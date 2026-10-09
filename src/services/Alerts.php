<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use justinholtweb\my\db\Table;
use justinholtweb\my\events\AlertEvent;
use justinholtweb\my\helpers\Ip;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;
use Throwable;

/**
 * Failure alerts: tell somebody when the MYOB connection is in trouble, once, and again when it is
 * not.
 *
 * Ported from Erpy, the reference for the connector family (see its CLAUDE.md, "Failure alerts"),
 * by way of Zo. The documents screen already knows everything that has gone wrong. What it cannot
 * do is make a bookkeeper look at it. Three incidents are worth interrupting somebody for:
 *
 * - **Failures** — at least `alertFailureThreshold` invoices, payments, credit notes or refunds
 *   were left `failed` inside the window. Every one of those is money that is not in the books.
 * - **Mismatch** — an invoice synced inside the window that MYOB booked at a different total from
 *   the order. The push succeeded; the books are wrong. Usually a tax code on the wrong rate.
 * - **Authentication** — MYOB refused the refresh token, or answered 401 after My had already
 *   refreshed (in local mode, a 401 at all). A lapsed token silently stops every invoice.
 *
 * Each incident is a latch in `my_alerts`, one row per incident type, unique in the database.
 * Opening it sends one alert; it stays open — and silent — however many checks see the same
 * trouble; clearing it sends one recovery. A send is claimed with a conditional update before it
 * goes out and released if it fails, so the queue and cron checking in the same minute cannot
 * both send, and a mail outage does not swallow the alert.
 *
 * Detection runs from three places: {@see afterSync()} at the end of every push (failures and
 * mismatches, no cron needed), {@see noteAuthFailure()}/{@see noteAuthSuccess()} from `Api` and
 * `Auth` (authentication, immediately), and {@see check()} from `my/alerts/check` and
 * `my/sync/retry` — the only paths that can notice an incident *clearing* when nothing is pushing.
 *
 * Bodies are redacted ({@see redact()}): an alert goes to a mailbox and a chat channel, both of
 * which outlive the credential they would otherwise quote.
 */
class Alerts extends Component
{
    public const INCIDENT_FAILURES = 'failures';
    public const INCIDENT_MISMATCH = 'mismatch';
    public const INCIDENT_AUTH = 'auth';

    public const STATE_OK = 'ok';
    public const STATE_OPEN = 'open';

    /** @event AlertEvent before an alert or a recovery is sent; set `isValid` false to swallow it. */
    public const EVENT_BEFORE_NOTIFY = 'beforeNotify';

    /** How often one process re-checks that an authentication incident is still clear. */
    private const AUTH_SUCCESS_TTL = 60;

    /**
     * The HTTP client for the webhook. Null means a curl-only client built per send; tests put a
     * Guzzle `MockHandler` client here. Never a client on Guzzle's default stack: it can hand a
     * request to PHP's stream wrapper, which ignores `CURLOPT_RESOLVE` — the address pin.
     */
    public ?ClientInterface $webhookClient = null;

    /** When this process last confirmed authentication is clear. */
    private ?int $authClearAt = null;

    /**
     * @return array<string,string> incident => label
     */
    public static function incidents(): array
    {
        return [
            self::INCIDENT_FAILURES => Craft::t('my', 'Orders failing to push'),
            self::INCIDENT_MISMATCH => Craft::t('my', 'Invoices booked at a different total'),
            self::INCIDENT_AUTH => Craft::t('my', 'MYOB refused the connection'),
        ];
    }

    public static function incidentLabel(string $incident): string
    {
        return self::incidents()[$incident] ?? $incident;
    }

    // ---------------------------------------------------------------------------------------
    // Detection
    // ---------------------------------------------------------------------------------------

    /**
     * Evaluate every incident and send whatever is owed.
     *
     * Nothing is checked until a company file is connected: an install that was never connected is
     * not an incident, and disconnecting is neither a new incident nor a recovery.
     *
     * @param string[]|null $only limit to these incidents
     * @return array<int,array{incident:string,state:string,transition:?string,notified:bool,detail:?string}>
     */
    public function check(?array $only = null): array
    {
        if (!Plugin::getInstance()->getAuth()->getConnection()->isConnected()) {
            return [];
        }

        $results = [];

        foreach (array_keys(self::incidents()) as $incident) {
            if ($only !== null && !in_array($incident, $only, true)) {
                continue;
            }

            $row = $this->row($incident);
            [$open, $detail] = $this->measure($incident, $row);
            $results[] = $this->transition($incident, $open, $detail);
        }

        return $results;
    }

    /**
     * The end of every push. Fail-open: an alert that cannot be worked out must never be the
     * reason a push reports a failure.
     */
    public function afterSync(): void
    {
        try {
            $this->check([self::INCIDENT_FAILURES, self::INCIDENT_MISMATCH]);
        } catch (Throwable $e) {
            Craft::warning('My could not evaluate alerts after a push: ' . $e->getMessage(), 'my');
        }
    }

    /**
     * MYOB refused the credentials: a final 401, or a refresh token it would not honour.
     *
     * Recorded as a signal rather than measured, because nothing else remembers it — the log can
     * be switched off, and a refused token never makes a ledger row.
     */
    public function noteAuthFailure(string $reason): void
    {
        try {
            $this->ensureRow(self::INCIDENT_AUTH);

            Craft::$app->getDb()->createCommand()->update(Table::ALERTS, [
                'signalledAt' => $this->now(),
                'signalClearedAt' => null,
                'detail' => $this->redact($reason),
                'dateUpdated' => $this->now(),
            ], ['incident' => self::INCIDENT_AUTH])->execute();

            $this->authClearAt = null;

            $this->check([self::INCIDENT_AUTH]);
        } catch (Throwable $e) {
            Craft::warning('My could not record an authentication failure: ' . $e->getMessage(), 'my');
        }
    }

    /**
     * An authenticated request succeeded. Called by `Api` on every success, so it costs at most
     * one conditional UPDATE per minute per process, and usually nothing.
     */
    public function noteAuthSuccess(): void
    {
        if ($this->authClearAt !== null && time() - $this->authClearAt < self::AUTH_SUCCESS_TTL) {
            return;
        }

        $this->authClearAt = time();

        try {
            $cleared = Craft::$app->getDb()->createCommand()->update(Table::ALERTS, [
                'signalClearedAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], [
                'and',
                ['incident' => self::INCIDENT_AUTH, 'signalClearedAt' => null],
                ['not', ['signalledAt' => null]],
            ])->execute();

            if ($cleared > 0) {
                $this->check([self::INCIDENT_AUTH]);
            }
        } catch (Throwable $e) {
            Craft::warning('My could not clear an authentication alert: ' . $e->getMessage(), 'my');
        }
    }

    /**
     * Whether an incident is happening right now, and a redacted line saying what was seen.
     *
     * @param array<string,mixed> $row
     * @return array{0:bool,1:?string}
     */
    private function measure(string $incident, array $row): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $wasOpen = ($row['state'] ?? self::STATE_OK) === self::STATE_OPEN;
        $window = max(5, $settings->alertWindowMinutes);
        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-$window minutes"));

        switch ($incident) {
            case self::INCIDENT_FAILURES:
                if (!$settings->alertOnFailures) {
                    return [false, null];
                }

                $recent = (new Query())
                    ->from(['d' => Table::DOCUMENTS])
                    ->where(['d.status' => SyncDocument::STATUS_FAILED])
                    ->andWhere(['>=', 'd.dateUpdated', $cutoff]);
                $count = (int)(clone $recent)->count();

                // Hysteresis: it takes the threshold to open, and a whole quiet window to close.
                // Closing the moment the count dipped below the threshold would page somebody
                // twice an hour for a company file that is refusing every fourth order.
                $open = $wasOpen ? $count > 0 : $count >= max(1, $settings->alertFailureThreshold);

                if (!$open) {
                    return [false, null];
                }

                $latest = $this->latest($recent, 'd.dateUpdated');

                return [true, $this->redact(Craft::t('my', '{count} push failures in the last {window} minutes; {failed} documents show as failed. Latest: {type} for order {order}: {error}', [
                    'count' => $count,
                    'window' => $window,
                    'failed' => $this->standingCount(self::INCIDENT_FAILURES),
                    'type' => $latest['type'],
                    'order' => $latest['order'],
                    'error' => $latest['error'] !== '' ? $latest['error'] : '—',
                ]))];

            case self::INCIDENT_MISMATCH:
                if (!$settings->alertOnMismatch) {
                    return [false, null];
                }

                $recent = (new Query())
                    ->from(['d' => Table::DOCUMENTS])
                    ->where(Sync::mismatchCondition('d'))
                    ->andWhere(['>=', 'd.dateSynced', $cutoff]);
                $count = (int)(clone $recent)->count();

                if ($count === 0) {
                    return [false, null];
                }

                $latest = $this->latest($recent, 'd.dateSynced');

                return [true, $this->redact(Craft::t('my', '{count} invoices pushed in the last {window} minutes were booked at a different total; {total} in all. Latest: {number} for order {order}: {error}', [
                    'count' => $count,
                    'window' => $window,
                    'total' => $this->standingCount(self::INCIDENT_MISMATCH),
                    'number' => $latest['number'],
                    'order' => $latest['order'],
                    'error' => $latest['error'],
                ]))];

            case self::INCIDENT_AUTH:
                $open = $settings->alertOnAuthFailure
                    && !empty($row['signalledAt'])
                    && empty($row['signalClearedAt']);

                return [$open, $open ? ($row['detail'] ?? null) : null];
        }

        return [false, null];
    }

    /**
     * The newest row of a documents query, with the order's reference rather than its id — a
     * bookkeeper searches MYOB by the number on the invoice, not by Craft's element id.
     *
     * @return array{type:string,order:string,number:string,error:string}
     */
    private function latest(Query $query, string $orderBy): array
    {
        $row = (clone $query)
            ->select(['d.docType', 'd.orderId', 'd.myobNumber', 'd.lastError', 'o.reference', 'o.number'])
            ->leftJoin(['o' => '{{%commerce_orders}}'], '[[o.id]] = [[d.orderId]]')
            ->orderBy([$orderBy => SORT_DESC, 'd.id' => SORT_DESC])
            ->one() ?: [];

        $type = (string)($row['docType'] ?? '');
        $order = (string)(($row['reference'] ?? '') ?: (isset($row['number']) ? substr((string)$row['number'], 0, 7) : ($row['orderId'] ?? '')));

        return [
            'type' => (new SyncDocument(['docType' => $type]))->getTypeLabel(),
            'order' => $order,
            'number' => (string)(($row['myobNumber'] ?? '') ?: '—'),
            'error' => is_string($row['lastError'] ?? null) ? $row['lastError'] : '',
        ];
    }

    /**
     * What is still wrong regardless of when it happened — quoted in alerts and recoveries, so a
     * recovery ("nothing new for an hour") does not read as "everything is fixed".
     */
    public function standingCount(string $incident): int
    {
        return match ($incident) {
            self::INCIDENT_FAILURES => (int)(new Query())
                ->from(Table::DOCUMENTS)
                ->where(['status' => SyncDocument::STATUS_FAILED])
                ->count(),
            self::INCIDENT_MISMATCH => (int)(new Query())
                ->from(Table::DOCUMENTS)
                ->where(Sync::mismatchCondition())
                ->count(),
            default => 0,
        };
    }

    // ---------------------------------------------------------------------------------------
    // The latch
    // ---------------------------------------------------------------------------------------

    /**
     * Move the latch, then send whatever it says is owed.
     *
     * @return array{incident:string,state:string,transition:?string,notified:bool,detail:?string}
     */
    private function transition(string $incident, bool $open, ?string $detail): array
    {
        $db = Craft::$app->getDb();
        $row = $this->row($incident);
        $transition = null;
        $where = ['id' => $row['id']];

        if ($open && $row['state'] !== self::STATE_OPEN) {
            // Conditional on the old state, so two checks racing open it once.
            $won = $db->createCommand()->update(Table::ALERTS, [
                'state' => self::STATE_OPEN,
                'openedAt' => $this->now(),
                'notifiedAt' => null,
                'recoveryNotifiedAt' => null,
                'detail' => $detail,
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OK])->execute();
            $transition = $won ? 'opened' : null;
        } elseif ($open && $detail !== null && $detail !== $row['detail']) {
            $db->createCommand()->update(Table::ALERTS, ['detail' => $detail, 'dateUpdated' => $this->now()], $where)->execute();
        } elseif (!$open && $row['state'] === self::STATE_OPEN) {
            $won = $db->createCommand()->update(Table::ALERTS, [
                'state' => self::STATE_OK,
                'recoveredAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OPEN])->execute();
            $transition = $won ? 'recovered' : null;
        }

        $notified = $this->deliverOwed($incident);
        $row = $this->row($incident);

        return [
            'incident' => $incident,
            'state' => $row['state'],
            'transition' => $transition,
            'notified' => $notified,
            'detail' => $row['detail'],
        ];
    }

    /**
     * Send what the latch says is owed: an opening alert nobody has had yet, or the recovery for
     * one somebody has. Claimed by a conditional update first, released again if every channel
     * failed — so it is sent once, and a mail outage delays it rather than losing it.
     */
    private function deliverOwed(string $incident): bool
    {
        $db = Craft::$app->getDb();
        $row = $this->row($incident);
        $where = ['id' => $row['id']];

        if ($row['state'] === self::STATE_OPEN && $row['notifiedAt'] === null) {
            // A flapping connection gets one alert and one recovery per cooldown, not one each
            // per check. A reopening inside the cooldown is told about once the cooldown ends,
            // if it is still open by then.
            if ($row['quietUntil'] !== null && $row['quietUntil'] > $this->now()) {
                return false;
            }

            $claimed = $db->createCommand()->update(Table::ALERTS, ['notifiedAt' => $this->now()], $where + ['notifiedAt' => null, 'state' => self::STATE_OPEN])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($incident, false, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(Table::ALERTS, ['notifiedAt' => null], $where)->execute();

            return false;
        }

        // A recovery is only owed for an incident somebody was told about.
        if ($row['state'] === self::STATE_OK && $row['notifiedAt'] !== null && $row['recoveryNotifiedAt'] === null) {
            $cooldown = max(0, Plugin::getInstance()->getSettings()->alertCooldownMinutes);
            $claimed = $db->createCommand()->update(Table::ALERTS, [
                'recoveryNotifiedAt' => $this->now(),
                'quietUntil' => $cooldown > 0 ? Db::prepareDateForDb((new DateTime())->modify("+$cooldown minutes")) : null,
            ], $where + ['state' => self::STATE_OK, 'recoveryNotifiedAt' => null])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($incident, true, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(Table::ALERTS, [
                'recoveryNotifiedAt' => null,
                'quietUntil' => $row['quietUntil'],
            ], $where)->execute();
        }

        return false;
    }

    /**
     * The latch row, created on first sight.
     *
     * @return array<string,mixed>
     */
    private function row(string $incident): array
    {
        $this->ensureRow($incident);

        return (array)(new Query())
            ->from(Table::ALERTS)
            ->where(['incident' => $incident])
            ->one();
    }

    private function ensureRow(string $incident): void
    {
        if ((new Query())->from(Table::ALERTS)->where(['incident' => $incident])->exists()) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::ALERTS, [
                'incident' => $incident,
                'state' => self::STATE_OK,
                'dateCreated' => $this->now(),
                'dateUpdated' => $this->now(),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\yii\db\IntegrityException) {
            // Another process created it between the check and the insert. The unique index is
            // the point; there is nothing to do.
        }
    }

    /**
     * Every open incident, for the widget, the CP banner, the summary and the console.
     *
     * @return array<int,array<string,mixed>>
     */
    public function openIncidents(): array
    {
        $rows = (new Query())
            ->from(Table::ALERTS)
            ->where(['state' => self::STATE_OPEN])
            ->orderBy(['openedAt' => SORT_DESC])
            ->all();

        return array_map(static fn(array $row) => $row + ['label' => self::incidentLabel((string)$row['incident'])], $rows);
    }

    /**
     * What the Dashboard widget shows: the last seven days of the ledger, whether MYOB is
     * connected, when an invoice last went through, and anything open.
     *
     * @return array{connected:bool,label:string,stats:array<string,int>,mismatched:int,lastSynced:?string,incidents:array<int,array<string,mixed>>}
     */
    public function overview(): array
    {
        $plugin = Plugin::getInstance();
        $connection = $plugin->getAuth()->getConnection();
        $since = Db::prepareDateForDb((new DateTime())->modify('-7 days'));

        $stats = [
            SyncDocument::STATUS_SYNCED => 0,
            SyncDocument::STATUS_PENDING => 0,
            SyncDocument::STATUS_FAILED => 0,
        ];

        // By `dateUpdated`: a document that failed on Monday and went through on Tuesday counts
        // once, as what it is now.
        $rows = (new Query())
            ->select(['status', 'total' => 'COUNT(*)'])
            ->from(Table::DOCUMENTS)
            ->where(['>=', 'dateUpdated', $since])
            ->groupBy(['status'])
            ->all();

        foreach ($rows as $row) {
            if (isset($stats[$row['status']])) {
                $stats[$row['status']] = (int)$row['total'];
            }
        }

        $lastSynced = (new Query())
            ->from(Table::DOCUMENTS)
            ->where(['status' => SyncDocument::STATUS_SYNCED])
            ->max('dateSynced');

        return [
            'connected' => $connection->isConnected(),
            'label' => $connection->getLabel(),
            'stats' => $stats,
            'mismatched' => (int)(new Query())
                ->from(Table::DOCUMENTS)
                ->where(Sync::mismatchCondition())
                ->andWhere(['>=', 'dateSynced', $since])
                ->count(),
            // A bare UTC string; the zone is named in the template so it is not read as site-local.
            'lastSynced' => is_string($lastSynced) ? $lastSynced : null,
            'incidents' => $this->openIncidents(),
        ];
    }

    // ---------------------------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------------------------

    /**
     * Send one alert to every configured channel. True when at least one channel took it — a
     * second email because the webhook failed would be worse than a missing Slack message.
     */
    public function notify(string $incident, bool $recovered, string $detail): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $hasWebhook = $webhookUrl !== '' && !str_starts_with($webhookUrl, '$');

        if ($recipients === [] && !$hasWebhook) {
            return false;
        }

        $message = $this->compose($incident, $recovered, $detail);

        $event = new AlertEvent([
            'incident' => $incident,
            'recovered' => $recovered,
            'detail' => $message['detail'],
            'subject' => $message['subject'],
            'body' => $message['body'],
            'payload' => $this->payload($settings->alertWebhookFormat, $message),
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_NOTIFY)) {
            $this->trigger(self::EVENT_BEFORE_NOTIFY, $event);

            if (!$event->isValid) {
                return true;
            }
        }

        $sent = false;

        if ($recipients !== []) {
            try {
                $sent = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($event->subject)
                    ->setTextBody($event->body)
                    ->send();
            } catch (Throwable $e) {
                Craft::error('My could not email an alert: ' . $e->getMessage(), 'my');
            }
        }

        if ($hasWebhook) {
            $result = $this->postWebhook($webhookUrl, $event->payload);

            if ($result === true) {
                $sent = true;
            } else {
                Craft::error('My could not post an alert webhook: ' . $result, 'my');
            }
        }

        return $sent;
    }

    /**
     * Send a sample through every channel, for the settings screen's "Send a test alert".
     *
     * @return array{email:?bool,webhook:bool|string|null}
     */
    public function sendTest(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $message = $this->compose('test', false, Craft::t('my', 'This is a test. If you can read it, failure alerts will reach you here.'));
        $result = ['email' => null, 'webhook' => null];

        if ($recipients !== []) {
            try {
                $result['email'] = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($message['subject'])
                    ->setTextBody($message['body'])
                    ->send();
            } catch (Throwable $e) {
                Craft::error('My could not email a test alert: ' . $e->getMessage(), 'my');
                $result['email'] = false;
            }
        }

        if ($webhookUrl !== '' && !str_starts_with($webhookUrl, '$')) {
            $result['webhook'] = $this->postWebhook($webhookUrl, $this->payload($settings->alertWebhookFormat, $message));
        }

        return $result;
    }

    /**
     * Subject, plain-text body and links for one alert.
     *
     * Plain text on purpose: it may be read on a phone at an inconvenient hour, and it should say
     * what happened and where to go — nothing that needs a rendering engine. `UrlHelper::cpUrl()`
     * rather than a hand-assembled host, because this runs from the queue and the console, where
     * there is no request to read a host from.
     *
     * @return array{subject:string,body:string,title:string,detail:string,url:string,documentsUrl:string,incident:string,recovered:bool,site:string}
     */
    public function compose(string $incident, bool $recovered, string $detail): array
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();
        $label = $incident === 'test' ? Craft::t('my', 'Test alert') : self::incidentLabel($incident);
        $detail = $this->redact($detail);
        $window = max(5, Plugin::getInstance()->getSettings()->alertWindowMinutes);

        $documentsUrl = UrlHelper::cpUrl('my/documents');
        $url = match ($incident) {
            self::INCIDENT_AUTH => UrlHelper::cpUrl('settings/plugins/my'),
            self::INCIDENT_FAILURES => UrlHelper::cpUrl('my/documents', ['status' => SyncDocument::STATUS_FAILED]),
            self::INCIDENT_MISMATCH => UrlHelper::cpUrl('my/documents', ['status' => SyncDocument::STATUS_MISMATCH]),
            default => $documentsUrl,
        };

        $title = $recovered
            ? Craft::t('my', 'Recovered: {label} in MYOB', ['label' => $label])
            : Craft::t('my', '{label} in MYOB', ['label' => $label]);

        $lines = [
            $recovered
                ? Craft::t('my', 'My on {site}: this has cleared.', ['site' => $site])
                : Craft::t('my', 'My on {site} needs attention.', ['site' => $site]),
            '',
            Craft::t('my', 'Incident: {label}', ['label' => $label]),
        ];

        if (!$recovered && $detail !== '') {
            $lines[] = '';
            $lines[] = $detail;
        }

        if (!$recovered && $incident === self::INCIDENT_AUTH) {
            $lines[] = '';
            $lines[] = Craft::t('my', 'Nothing will reach MYOB until My is reconnected. Open My’s settings and press Reconnect, or check the company file username and password.');
        }

        // "Nothing new for an hour" is not "fixed". Say what is still waiting.
        if ($recovered && in_array($incident, [self::INCIDENT_FAILURES, self::INCIDENT_MISMATCH], true)) {
            $standing = $this->standingCount($incident);
            $lines[] = '';
            $lines[] = $incident === self::INCIDENT_FAILURES
                ? Craft::t('my', 'Nothing new has failed in the last {window} minutes. {count} documents still show as failed on the documents screen.', ['window' => $window, 'count' => $standing])
                : Craft::t('my', 'Nothing pushed in the last {window} minutes was booked at a different total. {count} invoices still disagree with their orders.', ['window' => $window, 'count' => $standing]);
        } elseif ($recovered) {
            $lines[] = '';
            $lines[] = Craft::t('my', 'No action is needed.');
        }

        $lines[] = '';
        $lines[] = Craft::t('my', 'Open: {url}', ['url' => $url]);

        if ($url !== $documentsUrl) {
            $lines[] = Craft::t('my', 'Documents: {url}', ['url' => $documentsUrl]);
        }

        $lines[] = '';
        $lines[] = Craft::t('my', 'You get one message when this starts and one when it clears. Change who gets them in My’s settings.');

        return [
            'subject' => '[' . $site . '] ' . $title,
            'body' => implode("\n", $lines) . "\n",
            'title' => $title,
            'detail' => $recovered ? '' : $detail,
            'url' => $url,
            'documentsUrl' => $documentsUrl,
            'incident' => $incident,
            'recovered' => $recovered,
            'site' => $site,
        ];
    }

    /**
     * The webhook body in the receiver's own shape.
     *
     * @param array{subject:string,body:string,title:string,detail:string,url:string,documentsUrl:string,incident:string,recovered:bool,site:string} $m
     * @return array<string,mixed>
     */
    public function payload(string $format, array $m): array
    {
        $text = $m['title'] . ($m['detail'] !== '' ? "\n" . $m['detail'] : '');

        return match ($format) {
            'teams' => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => array_values(array_filter([
                            ['type' => 'TextBlock', 'size' => 'Large', 'weight' => 'Bolder', 'color' => $m['recovered'] ? 'Good' : 'Attention', 'text' => $m['title'], 'wrap' => true],
                            $m['detail'] !== '' ? ['type' => 'TextBlock', 'text' => $m['detail'], 'wrap' => true] : null,
                            ['type' => 'FactSet', 'facts' => [['title' => 'Site', 'value' => $m['site']]]],
                        ])),
                        'actions' => [['type' => 'Action.OpenUrl', 'title' => Craft::t('my', 'Open in Craft'), 'url' => $m['url']]],
                    ],
                ]],
            ],
            'json' => [
                'event' => $m['recovered'] ? 'my.alert.recovered' : 'my.alert.opened',
                'incident' => $m['incident'],
                'site' => $m['site'],
                'title' => $m['title'],
                'detail' => $m['detail'],
                'url' => $m['url'],
                'documentsUrl' => $m['documentsUrl'],
                'at' => (new DateTime('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
            default => [
                'text' => $text,
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . $m['title'] . '*' . ($m['detail'] !== '' ? "\n" . $m['detail'] : '')]],
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $m['site'] . ' · <' . $m['url'] . '|' . Craft::t('my', 'Open in Craft') . '>']]],
                ],
            ],
        };
    }

    /**
     * Where an alert webhook may be sent, or why it may not. The family SSRF rules, as in Erpy:
     *
     * 1. `http` and `https` only, with no credentials in the URL.
     * 2. Every address the host resolves to must be public ({@see Ip::resolvePublic()}).
     * 3. The send pins the connection to those addresses with `CURLOPT_RESOLVE`, so a second
     *    lookup at connect time cannot rebind the host somewhere private.
     * 4. Redirects are never followed.
     *
     * `allowPrivateAlertWebhookHosts` (config file only) skips 2 and 3; 1 and 4 still hold.
     *
     * @return array{host:string,port:int,addresses:string[]}|string the pinned target, or the refusal
     */
    public function webhookTarget(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)(is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = (string)(is_array($parts) ? ($parts['host'] ?? '') : '');

        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return Craft::t('my', 'Only http:// and https:// webhook URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return Craft::t('my', 'Webhook URLs may not carry a username or password.');
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (Plugin::getInstance()->getSettings()->allowPrivateAlertWebhookHosts) {
            return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => []];
        }

        $addresses = Ip::resolvePublic($host);

        if ($addresses === []) {
            return Craft::t('my', 'That host doesn’t resolve, or resolves to a private, loopback or link-local address. Alert webhooks only go to public addresses.');
        }

        return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => $addresses];
    }

    /**
     * POST the payload. True on a 2xx, otherwise the reason — never an exception.
     *
     * @param array<string,mixed> $payload
     */
    public function postWebhook(string $url, array $payload): bool|string
    {
        $target = $this->webhookTarget($url);

        if (is_string($target)) {
            return $target;
        }

        $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'My alerts'];
        $secret = trim((string)App::parseEnv(Plugin::getInstance()->getSettings()->alertWebhookSecret));

        if ($secret !== '' && !str_starts_with($secret, '$')) {
            $timestamp = (string)time();
            $headers['X-My-Timestamp'] = $timestamp;
            $headers['X-My-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }

        $options = [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
        ];

        if ($target['addresses'] !== []) {
            // One entry per host:port, addresses comma-joined — one entry per address would leave
            // only the last one pinned.
            $options['curl'][CURLOPT_RESOLVE] = [sprintf(
                '%s:%d:%s',
                $target['host'],
                $target['port'],
                implode(',', array_map(static fn(string $ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $target['addresses'])),
            )];
        }

        try {
            $client = $this->webhookClient ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
            $response = $client->request('POST', $url, $options);
            $status = $response->getStatusCode();

            return $status >= 200 && $status < 300 ? true : 'HTTP ' . $status;
        } catch (Throwable $e) {
            // A Slack or Teams webhook URL is itself the credential; the reason ends up in logs.
            return str_replace($url, $target['host'], $e->getMessage());
        }
    }

    /**
     * Take anything secret out of a line that is about to leave the building.
     *
     * `Log` already redacts what it writes, but an alert quotes ledger errors that never passed
     * through it, and it goes somewhere — a mailbox, a chat channel — that outlives the
     * credential. So: the API secret, the company file password and both tokens by value,
     * anything shaped like a credential by pattern, tags stripped, and a length cap so a stack
     * trace cannot ride along.
     */
    public function redact(string $text): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $secrets = [];

        try {
            $connection = Plugin::getInstance()->getAuth()->getConnection();
            $secrets[] = $settings->getParsedClientSecret();
            $secrets[] = $settings->getParsedCfPassword();
            $secrets[] = (string)$settings->getCfToken();
            $secrets[] = (string)$connection->accessToken;
            $secrets[] = (string)$connection->refreshToken;
        } catch (Throwable) {
            // A connection row that cannot be read still leaves the patterns below.
        }

        foreach ($secrets as $secret) {
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, '••••', $text);
            }
        }

        $text = (string)preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9\-._~+\/=]{6,}/i', '$1 ••••', $text);
        $text = (string)preg_replace(
            '/(["\']?\b(?:password|passwd|pwd|secret|client_secret|api[_-]?key|apikey|access_token|refresh_token|token|cftoken|x-myobapi-cftoken|x-myobapi-key|signature|sig)\b["\']?\s*[:=]\s*["\']?)[^"\'&\s,;}]+/i',
            '$1••••',
            $text,
        );
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 499) . '…' : $text;
    }

    private function now(): string
    {
        return (string)Db::prepareDateForDb(new DateTime());
    }
}
