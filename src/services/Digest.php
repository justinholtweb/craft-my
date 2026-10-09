<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use justinholtweb\my\db\Table;
use justinholtweb\my\events\DigestEvent;
use justinholtweb\my\helpers\Mailer;
use justinholtweb\my\models\Settings;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;
use justinholtweb\my\queue\jobs\SendDigest;
use Throwable;
use yii\db\IntegrityException;

/**
 * The scheduled MYOB sync summary: once a week (or a day), email the alert recipients what went to
 * MYOB, what did not, and what still needs a human.
 *
 * Copied from craft-yarn, the family reference for scheduled email reports (its CLAUDE.md,
 * "Scheduled digests"). Everything above the "What this plugin reports" line is the generic part —
 * schedule, the durable marker, the claim that makes a send happen once, the fallback trigger.
 * Below it is My's: what to count, how to key a problem, what to put in the email.
 *
 * Three ways in, one way through:
 *
 * - `php craft my/digest/send` from cron — the recommended route, every 15–60 minutes;
 * - the end of a web request, at most every five minutes, which queues {@see SendDigest};
 * - "Send a test summary now" on the settings screen, which never touches the marker.
 *
 * All of the scheduled ones end in {@see run()}, and `run()` is idempotent: the first caller to
 * move the marker onto the current period sends, everybody after it finds the period taken.
 * Nothing here hangs off `Gc::EVENT_RUN` — garbage collection runs on a dice roll, and a summary
 * that arrives on a dice roll is not a schedule.
 *
 * The recipients are the failure alerts' recipients. One list of people who look after the books.
 */
class Digest extends Component
{
    /** @see DigestEvent Cancel it, or change the recipients, subject or variables. */
    public const EVENT_BEFORE_SEND = 'beforeSend';

    public const TABLE = Table::DIGESTS;

    /** One row per digest the plugin sends. My has one. */
    public const HANDLE = 'summary';

    public const RESULT_SENT = 'sent';
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_NO_RECIPIENTS = 'noRecipients';
    public const RESULT_NOT_DUE = 'notDue';
    public const RESULT_ALREADY_SENT = 'alreadySent';
    public const RESULT_NOTHING_NEW = 'nothingNew';
    public const RESULT_CANCELLED = 'cancelled';
    public const RESULT_FAILED = 'failed';

    /** Most problems listed in one email. The rest are a count and a link. */
    public const MAX_ITEMS = 25;

    /** How often the web fallback looks at the schedule at all. */
    public const WEB_CHECK_SECONDS = 300;

    private const WEB_CHECK_KEY = 'my:digest:checked';
    private const QUEUED_KEY = 'my:digest:queued:';

    // ------------------------------------------------------------------------------- schedule

    /** Now, in the system time zone — the one the summary hour is set in. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(Craft::$app->getTimeZone()));
    }

    /**
     * The period a moment falls in: `2026-10-09` for a daily summary, `2026-W41` (ISO week) for a
     * weekly one. One send per key, ever.
     */
    public function periodKey(DateTimeInterface $now): string
    {
        $now = $this->inSystemTime($now);

        return $this->settings()->digestFrequency === Settings::DIGEST_DAILY
            ? $now->format('Y-m-d')
            : $now->format('o-\WW');
    }

    /** When the summary for the period containing `$now` becomes due. */
    public function dueAt(DateTimeInterface $now): DateTimeImmutable
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now);

        if ($settings->digestFrequency === Settings::DIGEST_WEEKLY) {
            $now = $now->setISODate((int)$now->format('o'), (int)$now->format('W'), $settings->digestWeekday);
        }

        return $now->setTime($settings->digestHour, 0);
    }

    /** Whether a scheduled run at `$now` would send (or at least try to). */
    public function isDue(?DateTimeInterface $now = null): bool
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now ?? $this->now());

        return $settings->digestEnabled
            && $settings->recipientList() !== []
            && $now >= $this->dueAt($now)
            && $this->state()['period'] !== $this->periodKey($now);
    }

    /**
     * When the next scheduled summary will go — for the settings screen. One that is due now and
     * has not gone yet answers with now.
     */
    public function nextDueAt(?DateTimeInterface $now = null): ?DateTimeImmutable
    {
        $settings = $this->settings();

        if (!$settings->digestEnabled) {
            return null;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if ($this->state()['period'] !== $this->periodKey($now)) {
            $due = $this->dueAt($now);

            return $due > $now ? $due : $now;
        }

        $next = $now->modify($settings->digestFrequency === Settings::DIGEST_DAILY ? '+1 day' : '+1 week');

        return $this->dueAt($next);
    }

    // --------------------------------------------------------------------------- the triggers

    /**
     * The scheduled send. Idempotent: call it as often as you like, it sends once per period.
     *
     * @param bool $force Ignore the schedule and the "already sent" marker — `--force` on the
     *                    console command. Still records the send, so the next scheduled one
     *                    reports what is new since *this*.
     * @return string One of the `RESULT_*` constants.
     */
    public function run(?DateTimeInterface $now = null, bool $force = false): string
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now ?? $this->now());

        if (!$force && !$settings->digestEnabled) {
            return self::RESULT_DISABLED;
        }

        $recipients = $settings->recipientList();

        if ($recipients === []) {
            return self::RESULT_NO_RECIPIENTS;
        }

        if (!$force && $now < $this->dueAt($now)) {
            return self::RESULT_NOT_DUE;
        }

        $period = $this->periodKey($now);
        $state = $this->state();

        if (!$force && ($state['period'] === $period || !$this->claim($period, $state['period']))) {
            return self::RESULT_ALREADY_SENT;
        }

        try {
            $current = $this->collect();
            $since = $this->since($state, $now);
            $activity = $this->activity($since);
        } catch (Throwable $e) {
            $this->release($period, $state['period']);
            Craft::error('Could not assemble the MYOB sync summary: ' . $e->getMessage(), 'my');

            return $this->record(null, null, self::RESULT_FAILED);
        }

        $new = array_diff_key($current, array_flip($state['seen']));

        // A summary is news when something went to MYOB or something new went wrong. A quiet week
        // with the same two old failures records the period and stays quiet.
        if ($new === [] && $activity['total'] === 0 && !$settings->digestSendWhenEmpty) {
            return $this->record($period, $current, self::RESULT_NOTHING_NEW);
        }

        $delivered = $this->deliver($recipients, $current, $new, $since, $activity, false);

        if ($delivered === null) {
            $this->release($period, $state['period']);

            return $this->record(null, null, self::RESULT_CANCELLED);
        }

        if ($delivered === 0) {
            // Give the period back, so the next run tries again rather than the week going by
            // without a summary because the mail server was down for five minutes on Monday.
            $this->release($period, $state['period']);

            return $this->record(null, null, self::RESULT_FAILED);
        }

        return $this->record($period, $current, self::RESULT_SENT, count($new));
    }

    /**
     * "Send a test summary now". Sends what the next summary would say, marked as a test, and
     * leaves the marker alone — a test must never stop the real one going out.
     *
     * @param string[] $recipients
     * @return int How many recipients it was delivered to.
     */
    public function sendTest(array $recipients): int
    {
        $state = $this->state();
        $current = $this->collect();
        $new = array_diff_key($current, array_flip($state['seen']));
        $since = $this->since($state, $this->now());

        return (int)$this->deliver($recipients, $current, $new, $since, $this->activity($since), true);
    }

    /**
     * The fallback for sites with no cron job: called at the end of web requests, looks at the
     * schedule at most every {@see WEB_CHECK_SECONDS}, and queues {@see SendDigest} when due.
     *
     * Cheap on purpose — one cache read on almost every request, one row read every five
     * minutes. The job does the work; `run()` decides, so a duplicate job is a no-op.
     */
    public function queueIfDue(?DateTimeInterface $now = null): bool
    {
        $settings = $this->settings();

        if (!$settings->digestEnabled || !$settings->digestWebTrigger) {
            return false;
        }

        $cache = Craft::$app->getCache();

        // `add()` only writes when the key is absent, so of several requests landing together
        // one goes on to look at the schedule.
        if (!$cache->add(self::WEB_CHECK_KEY, 1, self::WEB_CHECK_SECONDS)) {
            return false;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if (!$this->isDue($now)) {
            return false;
        }

        if (!$cache->add(self::QUEUED_KEY . $this->periodKey($now), 1, 3600)) {
            return false;
        }

        Craft::$app->getQueue()->push(new SendDigest());

        return true;
    }

    // ------------------------------------------------------------------------ durable marker

    /**
     * The marker: which period was last claimed, which problems the last summary listed, and how
     * it went.
     *
     * @return array{period: string|null, seen: string[], lastRunAt: \DateTime|null, lastSentAt: \DateTime|null, lastResult: string|null, lastCount: int}
     */
    public function state(): array
    {
        $row = $this->row();

        if ($row === null) {
            try {
                Db::insert(self::TABLE, ['handle' => self::HANDLE, 'lastCount' => 0]);
            } catch (IntegrityException) {
                // Somebody else made it between our read and our write. Theirs will do.
            }

            $row = $this->row() ?? [];
        }

        $seen = json_decode((string)($row['seen'] ?? ''), true);

        return [
            'period' => isset($row['period']) && $row['period'] !== '' ? (string)$row['period'] : null,
            'seen' => is_array($seen) ? array_values(array_filter($seen, 'is_string')) : [],
            'lastRunAt' => DateTimeHelper::toDateTime($row['lastRunAt'] ?? null) ?: null,
            'lastSentAt' => DateTimeHelper::toDateTime($row['lastSentAt'] ?? null) ?: null,
            'lastResult' => $row['lastResult'] ?? null,
            'lastCount' => (int)($row['lastCount'] ?? 0),
        ];
    }

    /**
     * Moves the marker onto `$period`, but only if it still says `$previous`. Of two runs racing
     * for the same period, the database lets exactly one of them through.
     */
    private function claim(string $period, ?string $previous): bool
    {
        return Db::update(
            self::TABLE,
            ['period' => $period],
            ['handle' => self::HANDLE, 'period' => $previous],
        ) === 1;
    }

    /** Hands a claimed period back after a failure, so the next run tries again. */
    private function release(string $period, ?string $previous): void
    {
        Db::update(self::TABLE, ['period' => $previous], ['handle' => self::HANDLE, 'period' => $period]);
    }

    /**
     * Writes down how a run went. `$period` and `$current` are null when nothing should change
     * but the result — a failure leaves the period and the "seen" list as they were.
     *
     * @param array<string, mixed>|null $current
     */
    private function record(?string $period, ?array $current, string $result, ?int $count = null): string
    {
        $now = new DateTimeImmutable();
        $columns = [
            'lastRunAt' => Db::prepareDateForDb($now),
            'lastResult' => $result,
        ];

        if ($period !== null) {
            $columns['period'] = $period;
        }

        if ($current !== null) {
            $columns['seen'] = json_encode(array_keys($current));
        }

        if ($result === self::RESULT_SENT) {
            $columns['lastSentAt'] = Db::prepareDateForDb($now);
            $columns['lastCount'] = (int)$count;
        }

        Db::update(self::TABLE, $columns, ['handle' => self::HANDLE]);

        if ($result === self::RESULT_FAILED) {
            Craft::warning('The MYOB sync summary was not sent; the next run will try again.', 'my');
        } else {
            Craft::info("MYOB sync summary: $result.", 'my');
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function row(): ?array
    {
        return (new Query())->from([self::TABLE])->where(['handle' => self::HANDLE])->one() ?: null;
    }

    // ---------------------------------------------------------------------------- the email

    /**
     * Renders and sends. Null when an event handler cancelled it.
     *
     * @param string[] $recipients
     * @param array<string, array<string, mixed>> $current
     * @param array<string, array<string, mixed>> $new
     * @param array<string, mixed> $activity
     */
    private function deliver(array $recipients, array $current, array $new, DateTimeImmutable $since, array $activity, bool $test): ?int
    {
        $variables = $this->variables($current, $new, $since, $activity, $test);
        $subject = $this->subject(count($new), $activity, $test);

        $event = new DigestEvent([
            'recipients' => $recipients,
            'subject' => $subject,
            'variables' => $variables,
            'isTest' => $test,
        ]);
        $this->trigger(self::EVENT_BEFORE_SEND, $event);

        if (!$event->isValid) {
            return null;
        }

        return Mailer::send($event->recipients, $event->subject, 'my/_emails/digest', $event->variables);
    }

    // ===================================================================== What this plugin reports
    //
    // Everything below is My's: the problems the summary lists (and remembers, so it can say what
    // is new), the activity it counts for the period, and the email itself.

    /**
     * Every document that needs a human right now — failed, or booked at a different total —
     * keyed by its row and its state, so a document that fails, recovers and fails again is news
     * twice, and one that has sat failed for three weeks is not news three times.
     *
     * @return array<string, array<string, mixed>>
     */
    public function collect(): array
    {
        $rows = (new Query())
            ->select(['d.id', 'd.orderId', 'd.docType', 'd.status', 'd.myobNumber', 'd.amount', 'd.currency', 'd.lastError', 'd.dateUpdated', 'o.reference', 'o.number'])
            ->from(['d' => Table::DOCUMENTS])
            ->leftJoin(['o' => '{{%commerce_orders}}'], '[[o.id]] = [[d.orderId]]')
            ->where(['or', ['d.status' => SyncDocument::STATUS_FAILED], Sync::mismatchCondition('d')])
            ->orderBy(['d.dateUpdated' => SORT_DESC, 'd.id' => SORT_DESC])
            ->all();

        $keyed = [];

        foreach ($rows as $row) {
            $document = new SyncDocument([
                'id' => (int)$row['id'],
                'orderId' => (int)$row['orderId'],
                'docType' => (string)$row['docType'],
                'status' => (string)$row['status'],
                'lastError' => $row['lastError'],
            ]);
            $state = $document->isMismatched() ? SyncDocument::STATUS_MISMATCH : SyncDocument::STATUS_FAILED;

            $keyed[$this->key($document, $state)] = [
                'state' => $state,
                'type' => $document->getTypeLabel(),
                'order' => (string)(($row['reference'] ?? '') ?: substr((string)($row['number'] ?? $row['orderId']), 0, 7)),
                'number' => (string)($row['myobNumber'] ?? ''),
                'amount' => $row['amount'] !== null ? (float)$row['amount'] : null,
                'currency' => (string)($row['currency'] ?? ''),
                'error' => Plugin::getInstance()->getAlerts()->redact((string)($row['lastError'] ?? '')),
                'url' => $document->getCpUrl(),
                'orderUrl' => $document->getOrderCpUrl(),
            ];
        }

        return $keyed;
    }

    /**
     * Ids, never text: a reworded MYOB error on the same failed invoice is not news.
     */
    public function key(SyncDocument $document, string $state): string
    {
        return 'doc:' . $document->id . ':' . $state;
    }

    /**
     * Where the period being summarised starts: the last summary that went out, or one period
     * back for the first.
     *
     * @param array{lastSentAt: \DateTime|null} $state
     */
    public function since(array $state, DateTimeInterface $now): DateTimeImmutable
    {
        if ($state['lastSentAt'] instanceof \DateTime) {
            return DateTimeImmutable::createFromMutable($state['lastSentAt']);
        }

        return $this->inSystemTime($now)->modify($this->settings()->digestFrequency === Settings::DIGEST_DAILY ? '-1 day' : '-7 days');
    }

    /**
     * What went to MYOB since `$since`, by document type, with invoice totals per currency.
     *
     * @return array{total: int, invoices: int, payments: int, creditNotes: int, refunds: int, invoiced: array<string, float>, failed: int}
     */
    public function activity(DateTimeInterface $since): array
    {
        $cutoff = Db::prepareDateForDb($since);

        $synced = (new Query())
            ->select(['docType', 'currency', 'count' => 'COUNT(*)', 'amount' => 'SUM([[amount]])'])
            ->from([Table::DOCUMENTS])
            ->where(['status' => SyncDocument::STATUS_SYNCED])
            ->andWhere(['>=', 'dateSynced', $cutoff])
            ->groupBy(['docType', 'currency'])
            ->all();

        $out = [
            'total' => 0,
            'invoices' => 0,
            'payments' => 0,
            'creditNotes' => 0,
            'refunds' => 0,
            'invoiced' => [],
            'failed' => (int)(new Query())
                ->from([Table::DOCUMENTS])
                ->where(['status' => SyncDocument::STATUS_FAILED])
                ->andWhere(['>=', 'dateUpdated', $cutoff])
                ->count(),
        ];

        $keys = [
            SyncDocument::TYPE_INVOICE => 'invoices',
            SyncDocument::TYPE_PAYMENT => 'payments',
            SyncDocument::TYPE_CREDIT_NOTE => 'creditNotes',
            SyncDocument::TYPE_REFUND => 'refunds',
        ];

        foreach ($synced as $row) {
            $key = $keys[$row['docType']] ?? null;

            if ($key === null) {
                continue;
            }

            $count = (int)$row['count'];
            $out[$key] += $count;
            $out['total'] += $count;

            if ($key === 'invoices') {
                $currency = (string)($row['currency'] ?: '');
                $out['invoiced'][$currency] = ($out['invoiced'][$currency] ?? 0.0) + (float)$row['amount'];
            }
        }

        $out['total'] += $out['failed'];

        return $out;
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function subject(int $newCount, array $activity, bool $test): string
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();
        $period = $this->settings()->digestFrequency === Settings::DIGEST_DAILY
            ? Craft::t('my', 'Daily')
            : Craft::t('my', 'Weekly');

        $subject = Craft::t('my', '{period} MYOB summary for {site}: {invoices, plural, =1{one invoice} other{# invoices}}', [
            'period' => $period,
            'site' => $site,
            'invoices' => $activity['invoices'],
        ]);

        if ($newCount > 0) {
            $subject .= ', ' . Craft::t('my', '{count, plural, =1{one new problem} other{# new problems}}', ['count' => $newCount]);
        }

        return ($test ? Craft::t('my', '[Test]') . ' ' : '') . $subject;
    }

    /**
     * @param array<string, array<string, mixed>> $current
     * @param array<string, array<string, mixed>> $new
     * @param array<string, mixed> $activity
     * @return array<string, mixed>
     */
    private function variables(array $current, array $new, DateTimeImmutable $since, array $activity, bool $test): array
    {
        $plugin = Plugin::getInstance();
        $connection = $plugin->getAuth()->getConnection();
        $failed = array_filter($current, static fn(array $item) => $item['state'] === SyncDocument::STATUS_FAILED);

        $invoiced = [];

        foreach ($activity['invoiced'] as $currency => $amount) {
            $invoiced[] = $currency !== ''
                ? Craft::$app->getFormatter()->asCurrency($amount, $currency)
                : Craft::$app->getFormatter()->asDecimal($amount, 2);
        }

        return [
            'siteName' => Craft::$app->getSites()->getPrimarySite()->getName(),
            'isTest' => $test,
            'isDaily' => $this->settings()->digestFrequency === Settings::DIGEST_DAILY,
            'since' => $since,
            'activity' => $activity,
            'invoiced' => $invoiced,
            'items' => array_values(array_slice($new, 0, self::MAX_ITEMS)),
            'newCount' => count($new),
            'more' => max(0, count($new) - self::MAX_ITEMS),
            'failedCount' => count($failed),
            'mismatchCount' => count($current) - count($failed),
            'connected' => $connection->isConnected(),
            'companyFile' => $connection->getLabel(),
            'incidents' => array_map(static fn(array $row) => (string)$row['label'], $plugin->getAlerts()->openIncidents()),
            'documentsUrl' => UrlHelper::cpUrl('my/documents'),
            'failedUrl' => UrlHelper::cpUrl('my/documents', ['status' => SyncDocument::STATUS_FAILED]),
            'mismatchUrl' => UrlHelper::cpUrl('my/documents', ['status' => SyncDocument::STATUS_MISMATCH]),
            'settingsUrl' => UrlHelper::cpUrl('settings/plugins/my'),
        ];
    }

    // ---------------------------------------------------------------------------------- misc

    private function inSystemTime(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
