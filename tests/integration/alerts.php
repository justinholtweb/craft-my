<?php
/**
 * Failure alerts: settings, the latch, email, the webhook's SSRF guard, MYOB refusing the
 * connection, the Dashboard widget, the CP banner, the console commands and the test button.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-my/tests/integration/alerts.php
 */

require __DIR__ . '/_support.php';

use craft\db\Query;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use justinholtweb\my\db\Table;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\events\AlertEvent;
use justinholtweb\my\models\Settings;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;
use justinholtweb\my\services\Alerts;
use justinholtweb\my\widgets\HealthWidget;
use yii\base\Event;

$alerts = $plugin->getAlerts();

// ---------------------------------------------------------------------------------------------
section('Settings');

check('a fresh install validates with no recipients and no webhook (nothing is required)', function() use ($originalSettings) {
    $model = new Settings();
    $model->setAttributes(['salesAccount' => '4-1000'], false);

    return $model->validate() ?: json_encode($model->getErrors());
});

check('a bad address is refused, and named', function() {
    $model = new Settings();
    $model->setAttributes(['salesAccount' => '4-1000', 'alertRecipients' => 'books@example.com, not-an-address'], false);

    return !$model->validate() && str_contains(implode(' ', $model->getErrors('alertRecipients')), 'not-an-address') ?: json_encode($model->getErrors());
});

check('an unset $ENV reference is allowed and means nobody', function() {
    $model = new Settings();
    $model->setAttributes(['salesAccount' => '4-1000', 'alertRecipients' => '$MY_FIXTURE_UNSET_RECIPIENTS'], false);

    return $model->validate() && $model->recipientList() === [] ?: json_encode($model->getErrors());
});

check('a non-http webhook URL is refused at save', function() {
    $model = new Settings();
    $model->setAttributes(['salesAccount' => '4-1000', 'alertWebhookUrl' => 'file:///etc/passwd'], false);

    return !$model->validate() && $model->hasErrors('alertWebhookUrl') ?: json_encode($model->getErrors());
});

check('recipients split on commas, semicolons and new lines, de-duplicated', function() {
    $model = new Settings();
    $model->alertRecipients = "a@example.com, b@example.com;\nc@example.com a@example.com";

    return $model->recipientList() === ['a@example.com', 'b@example.com', 'c@example.com'] ?: json_encode($model->recipientList());
});

check('the alert settings are attributes, so they persist', function() use ($settings) {
    $attributes = $settings->attributes();

    foreach (['alertRecipients', 'alertWebhookUrl', 'alertOnFailures', 'alertOnMismatch', 'alertOnAuthFailure', 'alertCooldownMinutes', 'digestEnabled'] as $name) {
        if (!in_array($name, $attributes, true)) {
            return "$name is not an attribute";
        }
    }

    return true;
});

check('a cleared number field does not fatal the save', function() {
    $model = new Settings();
    $model->setAttributes(['alertWindowMinutes' => '', 'alertFailureThreshold' => '3']);

    return $model->alertWindowMinutes === 60 && $model->alertFailureThreshold === 3 ?: json_encode([$model->alertWindowMinutes, $model->alertFailureThreshold]);
});

// ---------------------------------------------------------------------------------------------
section('Nothing connected');

$variant = makeVariant();
$orderA = makeOrder($variant);
$orderB = makeOrder($variant);
$orderC = makeOrder($variant);

check('with no company file connected, nothing is checked or recorded', function() use ($alerts, $orderA) {
    disconnectFixture();
    resetAlerts();
    $doc = ledger($orderA, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_FAILED, ['lastError' => 'fixture']);
    $results = $alerts->check();
    Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['id' => $doc->id])->execute();

    return $results === [] && !(new Query())->from(Table::ALERTS)->exists() ?: json_encode($results);
});

connectFixture();
$settings->alertRecipients = 'books@example.com, owner@example.com';

// ---------------------------------------------------------------------------------------------
section('Redaction');

check('the API secret, company file password and both tokens are taken out by value', function() use ($alerts) {
    $out = $alerts->redact('secret fixture-api-secret-abcdef pw fixture-cf-password at fixture-access-token-1 rt fixture-refresh-token-1');

    return !str_contains($out, 'fixture-api-secret') && !str_contains($out, 'fixture-cf-password') && !str_contains($out, 'fixture-access-token') && !str_contains($out, 'fixture-refresh-token') ?: $out;
});

check('anything shaped like a credential is taken out by pattern', function() use ($alerts) {
    $out = $alerts->redact('Authorization: Bearer abcdefghijk1234 x-myobapi-cftoken: QWRtaW46c2VjcmV0 refresh_token=zzzzzzzz');

    return str_contains($out, 'Bearer ••••') && !str_contains($out, 'abcdefghijk1234') && !str_contains($out, 'QWRtaW46c2VjcmV0') && !str_contains($out, 'zzzzzzzz') ?: $out;
});

check('MYOB’s own error wording survives redaction', function() use ($alerts) {
    $out = $alerts->redact('Invalid data — Account 4-1000 does not exist');

    return $out === 'Invalid data — Account 4-1000 does not exist' ?: $out;
});

check('My’s own token-refusal explanations survive redaction whole', function() use ($alerts) {
    // They reach the auth alert verbatim, and the credential pattern eats the word after "token ".
    $explain = new ReflectionMethod(Plugin::getInstance()->getAuth(), 'explainTokenFailure');
    $bad = [];

    foreach (['invalid_grant', 'invalid_client', 'invalid_request'] as $error) {
        $text = $explain->invoke(Plugin::getInstance()->getAuth(), 400, json_encode(['error' => $error]), 'fallback');

        if ($alerts->redact($text) !== $text) {
            $bad[] = $alerts->redact($text);
        }
    }

    return $bad === [] ?: implode(' | ', $bad);
});

check('tags are stripped and the length is capped', function() use ($alerts) {
    $out = $alerts->redact('<b>x</b>' . str_repeat('y', 900));

    return !str_contains($out, '<b>') && mb_strlen($out) === 500 ?: mb_strlen($out) . ' ' . substr($out, 0, 20);
});

// ---------------------------------------------------------------------------------------------
section('Failures');

check('below the threshold, nothing opens and nothing is sent', function() use ($alerts, $orderA, $settings, &$mail) {
    resetAlerts();
    $settings->alertFailureThreshold = 2;
    ledger($orderA, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_FAILED, ['lastError' => 'Invalid data — Account 4-9999 does not exist']);
    $alerts->check();
    $settings->alertFailureThreshold = 1;

    return (latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && $mail === [] ?: json_encode([latch(Alerts::INCIDENT_FAILURES), count($mail)]);
});

check('reaching it opens the incident and sends one email to every recipient', function() use ($alerts, &$mail) {
    $results = $alerts->check();
    $row = latch(Alerts::INCIDENT_FAILURES);

    return $row['state'] === 'open' && $row['notifiedAt'] !== null && count($mail) === 1
        && $mail[0]['to'] === ['books@example.com', 'owner@example.com']
        ?: json_encode([$row, $mail, $results]);
});

check('the email names the order and the error, and links the documents screen filtered to failures', function() use (&$mail, $orderA) {
    $body = $mail[0]['body'] ?? '';
    $reference = $orderA->reference ?: substr((string)$orderA->number, 0, 7);

    return str_contains($mail[0]['subject'], 'Orders failing to push in MYOB')
        && str_contains($body, 'Account 4-9999 does not exist')
        && str_contains($body, $reference)
        && str_contains($body, 'my/documents?status=failed')
        ?: $mail[0]['subject'] . "\n" . $body;
});

check('it stays quiet while open, however often it is checked', function() use ($alerts, $orderB, &$mail) {
    ledger($orderB, SyncDocument::TYPE_PAYMENT, SyncDocument::STATUS_FAILED, ['lastError' => 'second']);
    $alerts->check();
    $alerts->check();

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('the detail follows what the latest check saw', function() {
    return str_contains((string)latch(Alerts::INCIDENT_FAILURES)['detail'], '2 push failures') ?: latch(Alerts::INCIDENT_FAILURES)['detail'];
});

check('a whole quiet window recovers it, with one recovery that says what is still failed', function() use ($alerts, &$mail) {
    // Everything failed more than an hour ago.
    Craft::$app->getDb()->createCommand()->update(Table::DOCUMENTS, ['dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime('-2 hours'))], ['status' => SyncDocument::STATUS_FAILED])->execute();
    $mail = [];
    $results = $alerts->check([Alerts::INCIDENT_FAILURES]);
    $row = latch(Alerts::INCIDENT_FAILURES);

    return $row['state'] === 'ok' && $row['recoveryNotifiedAt'] !== null && count($mail) === 1
        && str_contains($mail[0]['subject'], 'Recovered:')
        && preg_match('/\d+ documents still show as failed/', $mail[0]['body']) === 1
        ?: json_encode([$results, $mail]);
});

check('a reopening inside the quiet period is held, then sent once it ends', function() use ($alerts, $orderC, &$mail) {
    $mail = [];
    $doc = ledger($orderC, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_FAILED, ['lastError' => 'again']);
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $held = latch(Alerts::INCIDENT_FAILURES)['state'] === 'open' && $mail === [];

    Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => craft\helpers\Db::prepareDateForDb(new DateTime('-1 minute'))], ['incident' => Alerts::INCIDENT_FAILURES])->execute();
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return $held && count($mail) === 1 && !str_contains($mail[0]['subject'], 'Recovered') ?: json_encode([$held, $mail]);
});

check('a failed send is released and retried on the next check, not lost', function() use ($alerts, $orderC, &$mail, &$mailFails) {
    resetAlerts();
    $mailFails = true;
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $afterFail = latch(Alerts::INCIDENT_FAILURES);
    $mailFails = false;
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $afterRetry = latch(Alerts::INCIDENT_FAILURES);

    return $afterFail['state'] === 'open' && $afterFail['notifiedAt'] === null && $afterRetry['notifiedAt'] !== null && count($mail) === 1
        ?: json_encode([$afterFail, $afterRetry, count($mail)]);
});

check('switched off, failures alert nobody', function() use ($alerts, $settings, &$mail) {
    resetAlerts();
    $settings->alertOnFailures = false;
    $alerts->check();
    $settings->alertOnFailures = true;

    return (latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && $mail === [] ?: json_encode($mail);
});

check('a push that fails evaluates alerts by itself — no cron', function() use ($plugin, $orderA, &$mail) {
    resetAlerts();
    Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['orderId' => $orderA->id])->execute();
    // The customer lookup is the first request a push makes; MYOB refusing it fails the push.
    mockMyob([myobError(400, 'Invalid data', 'Filter is not valid')]);
    $result = $plugin->getSync()->pushOrder(reloadOrder($orderA));
    $invoice = $plugin->getSync()->getInvoiceForOrder($orderA->id);

    return !$result['ok'] && $invoice?->status === SyncDocument::STATUS_FAILED
        && latch(Alerts::INCIDENT_FAILURES)['state'] === 'open' && count($mail) === 1
        ?: json_encode([$result['messages'], $invoice?->status, latch(Alerts::INCIDENT_FAILURES), count($mail)]);
});

check('a transient failure stays pending and does not alert', function() use ($plugin, $orderB, &$mail) {
    resetAlerts();
    Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['status' => SyncDocument::STATUS_FAILED])->execute();
    // Four 503s with Retry-After: 0 — the client's own retries, then the queue's to come.
    $busy = fn() => myobJson(503, [], ['Retry-After' => '0']);
    mockMyob([$busy(), $busy(), $busy(), $busy()]);
    $plugin->getSync()->pushOrder(reloadOrder($orderB));
    $invoice = $plugin->getSync()->getInvoiceForOrder($orderB->id);

    return $invoice?->status === SyncDocument::STATUS_PENDING && (latch(Alerts::INCIDENT_FAILURES)['state'] ?? 'ok') === 'ok' && $mail === []
        ?: json_encode([$invoice?->status, latch(Alerts::INCIDENT_FAILURES), count($mail)]);
});

Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['orderId' => [$orderA->id, $orderB->id, $orderC->id]])->execute();

// ---------------------------------------------------------------------------------------------
section('Mismatch');

check('an invoice MYOB booked at a different total opens it', function() use ($alerts, $orderA, &$mail) {
    resetAlerts();
    ledger($orderA, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_SYNCED, [
        'myobNumber' => 'INV-FIX-1',
        'lastError' => 'MYOB booked 22.00 but the order was 20.00. This is usually a tax code mapped to the wrong rate.',
    ]);
    $alerts->check();
    $row = latch(Alerts::INCIDENT_MISMATCH);

    return $row['state'] === 'open' && count($mail) === 1
        && str_contains($mail[0]['body'], 'INV-FIX-1')
        && str_contains($mail[0]['body'], 'status=mismatch')
        ?: json_encode([$row, $mail]);
});

check('a clean synced invoice does not', function() use ($alerts, $orderB) {
    ledger($orderB, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_SYNCED, ['myobNumber' => 'INV-FIX-2']);

    return $alerts->standingCount(Alerts::INCIDENT_MISMATCH) === 1 ?: (string)$alerts->standingCount(Alerts::INCIDENT_MISMATCH);
});

check('a quiet window recovers it, and the recovery counts what still disagrees', function() use ($alerts, $orderA, $plugin, &$mail) {
    $mail = [];
    age($plugin->getSync()->getInvoiceForOrder($orderA->id), 120);
    $alerts->check([Alerts::INCIDENT_MISMATCH]);

    return latch(Alerts::INCIDENT_MISMATCH)['state'] === 'ok' && count($mail) === 1
        && str_contains($mail[0]['body'], '1 invoices still disagree')
        ?: json_encode($mail);
});

check('switched off, a mismatch alerts nobody', function() use ($alerts, $orderC, $settings, &$mail) {
    resetAlerts();
    $settings->alertOnMismatch = false;
    ledger($orderC, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_SYNCED, ['lastError' => 'MYOB booked 1.00']);
    $alerts->check();
    $settings->alertOnMismatch = true;

    return (latch(Alerts::INCIDENT_MISMATCH)['state'] ?? null) === 'ok' && $mail === [] ?: json_encode($mail);
});

Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['orderId' => [$orderA->id, $orderB->id, $orderC->id]])->execute();

// ---------------------------------------------------------------------------------------------
section('MYOB refusing the connection');

check('a 401 that the refresh fixes is not an incident', function() use ($plugin, &$mail) {
    resetAlerts();
    connectFixture();
    mockMyob([myobError(401, 'Unauthorized'), tokenResponse('fresh-1'), myobJson(200, ['CompanyFile' => ['Name' => 'Fixture'], 'UserAccess' => []])]);
    $result = $plugin->getAuth()->verify();

    return $result['ok'] && (latch(Alerts::INCIDENT_AUTH)['state'] ?? 'ok') === 'ok' && $mail === [] ?: json_encode([$result, latch(Alerts::INCIDENT_AUTH)]);
});

check('a 401 that a fresh token does not fix opens it and alerts at once', function() use ($plugin, &$mail) {
    resetAlerts();
    connectFixture();
    mockMyob([myobError(401, 'Unauthorized'), tokenResponse('fresh-2'), myobError(401, 'Unauthorized')]);
    $plugin->getAuth()->verify();
    $row = latch(Alerts::INCIDENT_AUTH);

    return $row['state'] === 'open' && count($mail) === 1
        && str_contains($mail[0]['subject'], 'MYOB refused the connection')
        && str_contains($mail[0]['body'], 'settings/plugins/my')
        && !str_contains($mail[0]['body'], 'fresh-2')
        ?: json_encode([$row, $mail]);
});

check('a second refusal does not send a second alert', function() use ($plugin, &$mail) {
    mockMyob([myobError(401, 'Unauthorized'), tokenResponse('fresh-3'), myobError(401, 'Unauthorized')]);
    $plugin->getAuth()->verify();

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('the CP banner shows it to people who can see documents', function() use ($plugin) {
    Craft::$app->getUser()->setIdentity(craft\elements\User::find()->admin()->one());
    $event = new craft\events\RegisterCpAlertsEvent();
    Event::trigger(craft\helpers\Cp::class, craft\helpers\Cp::EVENT_REGISTER_ALERTS, $event);
    $mine = array_values(array_filter($event->alerts, static fn($a) => str_contains((string)$a, 'MYOB refused the connection')));

    return count($mine) === 1 && str_contains($mine[0], 'settings/plugins/my') ?: json_encode($event->alerts);
});

check('the next authenticated success recovers it, with one recovery', function() use ($plugin, &$mail) {
    $mail = [];
    mockMyob([myobJson(200, ['CompanyFile' => ['Name' => 'Fixture'], 'UserAccess' => []])]);
    $plugin->getAuth()->verify();

    return latch(Alerts::INCIDENT_AUTH)['state'] === 'ok' && count($mail) === 1 && str_contains($mail[0]['subject'], 'Recovered:') ?: json_encode([latch(Alerts::INCIDENT_AUTH), $mail]);
});

check('a refused refresh token opens it too', function() use ($plugin, &$mail) {
    resetAlerts();
    connectFixture(Settings::MODE_CLOUD, true);
    mockMyob([myobJson(400, ['error' => 'invalid_grant'])]);

    try {
        $plugin->getApi()->get('', ['action' => 'verify']);
    } catch (MyobApiException) {
    }

    $row = latch(Alerts::INCIDENT_AUTH);

    return $row['state'] === 'open' && str_contains((string)$row['detail'], 'refresh token') && count($mail) === 1 ?: json_encode([$row, count($mail)]);
});

check('a network failure on refresh is not an authentication failure', function() use ($plugin) {
    resetAlerts();
    connectFixture(Settings::MODE_CLOUD, true);
    mockMyob([new ConnectException('Could not resolve host', new Request('POST', 'https://secure.myob.com/oauth2/v1/authorize'))]);

    try {
        $plugin->getApi()->get('', ['action' => 'verify']);
    } catch (MyobApiException) {
    }

    return (latch(Alerts::INCIDENT_AUTH)['state'] ?? 'ok') === 'ok' ?: json_encode(latch(Alerts::INCIDENT_AUTH));
});

check('a 500 is not an authentication failure', function() use ($plugin) {
    resetAlerts();
    connectFixture();
    $broken = fn() => myobJson(500, [], ['Retry-After' => '0']);
    mockMyob([$broken(), $broken(), $broken(), $broken()]);

    try {
        $plugin->getApi()->get('', ['action' => 'verify']);
    } catch (MyobApiException) {
    }

    return (latch(Alerts::INCIDENT_AUTH)['state'] ?? 'ok') === 'ok' ?: json_encode(latch(Alerts::INCIDENT_AUTH));
});

check('in local mode, a 401 at all is the company file login being refused', function() use ($plugin, &$mail) {
    resetAlerts();
    connectFixture(Settings::MODE_LOCAL);
    mockMyob([myobError(401, 'Unauthorized')]);
    $plugin->getAuth()->verify();
    $refreshed = array_filter(sentRequests(), static fn($r) => str_contains($r, 'oauth2'));

    return latch(Alerts::INCIDENT_AUTH)['state'] === 'open' && $refreshed === [] && count($mail) === 1 ?: json_encode([latch(Alerts::INCIDENT_AUTH), sentRequests()]);
});

check('switched off, a refusal records the signal but alerts nobody', function() use ($plugin, $settings, &$mail) {
    resetAlerts();
    connectFixture();
    $settings->alertOnAuthFailure = false;
    mockMyob([myobError(401, 'Unauthorized'), tokenResponse('fresh-4'), myobError(401, 'Unauthorized')]);
    $plugin->getAuth()->verify();
    $settings->alertOnAuthFailure = true;
    $row = latch(Alerts::INCIDENT_AUTH);

    return $row['state'] === 'ok' && $row['signalledAt'] !== null && $mail === [] ?: json_encode([$row, $mail]);
});

connectFixture();

// ---------------------------------------------------------------------------------------------
section('Webhook');

$hookHistory = [];
$hookMock = new MockHandler();
$hookStack = HandlerStack::create($hookMock);
$hookStack->push(Middleware::history($hookHistory));
$alerts->webhookClient = new Client(['handler' => $hookStack]);

check('a private, loopback or metadata address is refused', function() use ($alerts) {
    foreach (['http://127.0.0.1/hook', 'http://169.254.169.254/latest', 'http://10.0.0.5/x', 'http://[::1]/x', 'http://[::ffff:127.0.0.1]/x'] as $url) {
        if (!is_string($alerts->webhookTarget($url))) {
            return "$url was allowed";
        }
    }

    return true;
});

check('a URL with credentials, or another scheme, is refused', function() use ($alerts) {
    return is_string($alerts->webhookTarget('https://user:pass@1.1.1.1/x')) && is_string($alerts->webhookTarget('gopher://1.1.1.1/x')) ?: 'allowed';
});

check('a public address is allowed, and pinned', function() use ($alerts) {
    $target = $alerts->webhookTarget('https://1.1.1.1/hooks/x');

    return is_array($target) && $target['addresses'] === ['1.1.1.1'] && $target['port'] === 443 ?: json_encode($target);
});

check('a refused URL is never requested', function() use ($alerts, &$hookHistory) {
    $hookHistory = [];
    $result = $alerts->postWebhook('http://169.254.169.254/latest/meta-data', ['text' => 'x']);

    return is_string($result) && $hookHistory === [] ?: json_encode($result);
});

check('the send pins the address, refuses redirects and does not throw on a 4xx', function() use ($alerts, $hookMock, &$hookHistory) {
    $hookHistory = [];
    $hookMock->append(new GuzzleResponse(403));
    $result = $alerts->postWebhook('https://1.1.1.1/hooks/x', ['text' => 'x']);
    $options = $hookHistory[0]['options'] ?? [];

    return $result === 'HTTP 403'
        && ($options['allow_redirects'] ?? null) === false
        && ($options['curl'][CURLOPT_RESOLVE][0] ?? null) === '1.1.1.1:443:1.1.1.1'
        ?: json_encode([$result, $options['allow_redirects'] ?? null, $options['curl'] ?? null]);
});

check('allowPrivateAlertWebhookHosts lets a LAN host through, unpinned', function() use ($alerts, $settings) {
    $settings->allowPrivateAlertWebhookHosts = true;
    $target = $alerts->webhookTarget('http://10.0.0.5:8065/hooks/x');
    $settings->allowPrivateAlertWebhookHosts = false;

    return is_array($target) && $target['addresses'] === [] && $target['port'] === 8065 ?: json_encode($target);
});

check('an incident posts a Slack message, signed, to the pinned address', function() use ($alerts, $orderA, $settings, $hookMock, &$hookHistory) {
    resetAlerts();
    $hookHistory = [];
    $settings->alertWebhookUrl = 'https://1.1.1.1/hooks/slack';
    $settings->alertWebhookSecret = 'fixture-hook-secret';
    $hookMock->append(new GuzzleResponse(200));
    ledger($orderA, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_FAILED, ['lastError' => 'webhook fixture']);
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    $request = $hookHistory[0]['request'] ?? null;
    $body = (string)$request?->getBody();
    $expected = 'sha256=' . hash_hmac('sha256', $request?->getHeaderLine('X-My-Timestamp') . '.' . $body, 'fixture-hook-secret');
    $decoded = json_decode($body, true);

    return $request !== null
        && $request->getHeaderLine('X-My-Signature') === $expected
        && str_contains((string)($decoded['text'] ?? ''), 'Orders failing to push')
        && isset($decoded['blocks'])
        ?: json_encode([$body, $request?->getHeaders()]);
});

check('a webhook that fails with no email configured is retried, not lost', function() use ($alerts, $settings, $hookMock) {
    resetAlerts();
    $settings->alertRecipients = '';
    $hookMock->append(new GuzzleResponse(500));
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $afterFail = latch(Alerts::INCIDENT_FAILURES)['notifiedAt'];
    $hookMock->append(new GuzzleResponse(200));
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $settings->alertRecipients = 'books@example.com, owner@example.com';

    return $afterFail === null && latch(Alerts::INCIDENT_FAILURES)['notifiedAt'] !== null ?: json_encode(latch(Alerts::INCIDENT_FAILURES));
});

check('Teams gets an Adaptive Card, JSON gets a flat my.alert event', function() use ($alerts) {
    $message = $alerts->compose(Alerts::INCIDENT_AUTH, false, 'x');
    $teams = $alerts->payload('teams', $message);
    $json = $alerts->payload('json', $message);

    return ($teams['attachments'][0]['content']['type'] ?? null) === 'AdaptiveCard' && ($json['event'] ?? null) === 'my.alert.opened' && ($json['incident'] ?? null) === 'auth'
        ?: json_encode([$teams, $json]);
});

check('a handler on EVENT_BEFORE_NOTIFY can reword or swallow an alert', function() use ($alerts, &$mail) {
    $mail = [];
    $handler = function(AlertEvent $e) {
        if ($e->recovered) {
            $e->isValid = false;

            return;
        }

        $e->subject = 'Reworded';
    };
    Event::on(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, $handler);
    $reworded = $alerts->notify(Alerts::INCIDENT_FAILURES, false, 'x');
    $swallowed = $alerts->notify(Alerts::INCIDENT_FAILURES, true, 'x');
    Event::off(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, $handler);

    return $reworded && $swallowed && count($mail) === 1 && $mail[0]['subject'] === 'Reworded' ?: json_encode($mail);
});

$settings->alertWebhookUrl = '';
$settings->alertWebhookSecret = '';
Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['orderId' => [$orderA->id, $orderB->id, $orderC->id]])->execute();

// ---------------------------------------------------------------------------------------------
section('Dashboard widget');

check('it shows the connection, the last seven days, the last push and any open incident', function() use ($orderA, $orderB, $orderC) {
    resetAlerts();
    Craft::$app->getUser()->setIdentity(craft\elements\User::find()->admin()->one());
    ledger($orderA, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_SYNCED, ['myobNumber' => 'INV-W-1']);
    ledger($orderB, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_FAILED, ['lastError' => 'widget fixture']);
    ledger($orderC, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_PENDING);
    Plugin::getInstance()->getAlerts()->check();

    $overview = Plugin::getInstance()->getAlerts()->overview();
    $html = (new HealthWidget())->getBodyHtml();

    return $overview['connected'] && $overview['stats']['synced'] >= 1 && $overview['stats']['failed'] >= 1 && $overview['stats']['pending'] >= 1
        && $overview['lastSynced'] !== null
        && str_contains((string)$html, 'Fixture Traders Pty Ltd')
        && str_contains((string)$html, 'Orders failing to push')
        && str_contains((string)$html, 'my/documents?status=failed')
        ?: json_encode([$overview, $html]);
});

check('it is registered with the Dashboard', function() {
    return in_array(HealthWidget::class, Craft::$app->getDashboard()->getAllWidgetTypes(), true) ?: 'not registered';
});

check('it renders nothing for someone who cannot see documents', function() {
    Craft::$app->getUser()->setIdentity(null);
    $html = (new HealthWidget())->getBodyHtml();
    Craft::$app->getUser()->setIdentity(craft\elements\User::find()->admin()->one());

    return $html === null ?: 'rendered';
});

// ---------------------------------------------------------------------------------------------
section('Console');

check('my/alerts/check runs and exits 0', function() {
    // The alert latch lives in the database, so a fresh process sees this one's incidents.
    exec('php craft my/alerts/check 2>&1', $out, $code);

    return $code === 0 && str_contains(implode("\n", $out), 'incident') ?: $code . ' ' . implode("\n", $out);
});

check('my/alerts/test refuses with nothing configured (saved settings)', function() {
    exec('php craft my/alerts/test 2>&1', $out, $code);

    return $code === 78 ?: $code . ' ' . implode("\n", $out);
});

check('my/sync/retry runs the alert check after its retries', function() {
    $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/console/controllers/SyncController.php');

    return substr_count($source, '$this->checkAlerts();') === 2 ?: 'not called on both paths';
});

// ---------------------------------------------------------------------------------------------
section('Send a test alert (HTTP)');

[$viewer, $viewerPassword] = makeUser('viewer', ['accessplugin-my', 'accesscp', 'my-viewdocuments', 'my-pushorders', 'my-viewlog']);

check('anonymous is refused', function() {
    $status = client(null, null)('my/alerts/test')->getStatusCode();

    return in_array($status, [302, 400, 401, 403], true) ?: "HTTP $status";
});

check('a non-admin with every My permission is refused', function() use ($viewer, $viewerPassword) {
    $status = client($viewer->username, $viewerPassword)('my/alerts/test')->getStatusCode();

    return $status === 403 ?: "HTTP $status";
});

check('an admin without a CSRF token is refused', function() {
    $status = client('admin', 'claudepassword')('my/alerts/test', [], 'POST', false)->getStatusCode();

    return $status === 400 ?: "HTTP $status";
});

check('an admin GET is refused', function() {
    $status = client('admin', 'claudepassword')('my/alerts/test', [], 'GET')->getStatusCode();

    return in_array($status, [400, 405], true) ?: "HTTP $status";
});

check('an admin POST answers JSON from the saved settings (and takes no URL from the request)', function() {
    $response = client('admin', 'claudepassword')('my/alerts/test', ['alertWebhookUrl' => 'http://169.254.169.254/']);
    $data = json_decode((string)$response->getBody(), true);

    return is_array($data) && str_contains((string)($data['message'] ?? ''), 'Save some recipients') ?: $response->getStatusCode() . ' ' . $response->getBody();
});

check('the settings screen offers the test button, bound by the screen’s own script', function() {
    $template = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings.twig');

    return str_contains($template, 'data-my-action="my/alerts/test"') && str_contains($template, "querySelectorAll('[data-my-action]')") ?: 'missing';
});

finish();
