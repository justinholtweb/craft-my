<?php
/**
 * The MYOB sync summary (the family's scheduled-digest pattern, from craft-yarn): settings, the
 * schedule, the durable marker and its claim, what the email says, failure and cancel handling,
 * the web fallback, the console commands and the test-send endpoint.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-my/tests/integration/digest.php
 */

require __DIR__ . '/_support.php';

use craft\db\Query;
use justinholtweb\my\db\Table;
use justinholtweb\my\events\DigestEvent;
use justinholtweb\my\models\Settings;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\queue\jobs\SendDigest;
use justinholtweb\my\services\Digest;
use yii\base\Event;

$digest = $plugin->getDigest();
$tz = new DateTimeZone(Craft::$app->getTimeZone());
$now = new DateTimeImmutable('now', $tz);

connectFixture();
$db->createCommand()->delete(Table::DIGESTS)->execute();
$settings->setAttributes([
    'alertRecipients' => 'books@example.com, owner@example.com',
    'digestEnabled' => true,
    'digestFrequency' => Settings::DIGEST_WEEKLY,
    // Due from midnight today, so "now" is always on or after this week's due time.
    'digestWeekday' => (int)$now->format('N'),
    'digestHour' => 0,
    'digestSendWhenEmpty' => false,
    'digestWebTrigger' => true,
], false);

// ---------------------------------------------------------------------------------------------
section('Settings and schedule');

check('the schedule settings are validated, never required', function() {
    $model = new Settings();
    $model->setAttributes(['salesAccount' => '4-1000', 'digestFrequency' => 'hourly', 'digestWeekday' => 9, 'digestHour' => 24], false);
    $model->validate();
    $bad = array_keys($model->getErrors());
    sort($bad);

    $fresh = new Settings();
    $fresh->setAttributes(['salesAccount' => '4-1000'], false);

    return $bad === ['digestFrequency', 'digestHour', 'digestWeekday'] && $fresh->validate() ?: json_encode([$bad, $fresh->getErrors()]);
});

check('a weekly period is the ISO week (o, not Y: 2027-01-01 is 2026-W53)', function() use ($digest, $tz) {
    return $digest->periodKey(new DateTimeImmutable('2027-01-01 12:00', $tz)) === '2026-W53' ?: $digest->periodKey(new DateTimeImmutable('2027-01-01 12:00', $tz));
});

check('a daily period is the date', function() use ($digest, $settings, $tz) {
    $settings->digestFrequency = Settings::DIGEST_DAILY;
    $key = $digest->periodKey(new DateTimeImmutable('2026-10-09 23:30', $tz));
    $settings->digestFrequency = Settings::DIGEST_WEEKLY;

    return $key === '2026-10-09' ?: $key;
});

check('a weekly summary is due on the chosen weekday at the chosen hour', function() use ($digest, $settings, $tz) {
    $settings->digestWeekday = 1;
    $settings->digestHour = 8;
    $due = $digest->dueAt(new DateTimeImmutable('2026-10-09 15:00', $tz))->format('Y-m-d H:i');
    $settings->digestWeekday = (int)(new DateTimeImmutable('now', $tz))->format('N');
    $settings->digestHour = 0;

    return $due === '2026-10-05 08:00' ?: $due;
});

// ---------------------------------------------------------------------------------------------
section('Running');

$variant = makeVariant();
$invoiced = makeOrder($variant);
$broken = makeOrder($variant);
$offBy = makeOrder($variant);

$ok = ledger($invoiced, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_SYNCED, ['myobNumber' => 'INV-D-1', 'amount' => 120.0, 'currency' => 'AUD']);
$payment = ledger($invoiced, SyncDocument::TYPE_PAYMENT, SyncDocument::STATUS_SYNCED, ['amount' => 120.0]);
$failedDoc = ledger($broken, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_FAILED, ['lastError' => 'Invalid data — Account 4-9999 does not exist']);
$mismatchDoc = ledger($offBy, SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_SYNCED, ['myobNumber' => 'INV-D-3', 'lastError' => 'MYOB booked 22.00 but the order was 20.00.']);

// Five minutes ago, so a summary sent "now" is strictly after them.
foreach ([$ok, $payment, $failedDoc, $mismatchDoc] as $document) {
    age($document, 5);
}

check('switched off, it does nothing', function() use ($digest, $settings, &$mail) {
    $mail = [];
    $settings->digestEnabled = false;
    $result = $digest->run();
    $settings->digestEnabled = true;

    return $result === Digest::RESULT_DISABLED && $mail === [] ?: $result;
});

check('with no recipients it says so (and cron gets a non-zero exit)', function() use ($digest, $settings) {
    $settings->alertRecipients = '';
    $result = $digest->run();
    $settings->alertRecipients = 'books@example.com, owner@example.com';

    return $result === Digest::RESULT_NO_RECIPIENTS ?: $result;
});

check('before the hour, it is not due', function() use ($digest, $settings, $tz) {
    $settings->digestHour = 23;
    $result = $digest->run(new DateTimeImmutable('today 22:59', $tz));
    $settings->digestHour = 0;

    return $result === Digest::RESULT_NOT_DUE ?: $result;
});

check('when due, it sends one email per recipient', function() use ($digest, $now, &$mail) {
    $mail = [];
    $result = $digest->run($now);

    return $result === Digest::RESULT_SENT && count($mail) === 2
        && $mail[0]['to'] === ['books@example.com'] && $mail[1]['to'] === ['owner@example.com']
        ?: json_encode([$result, array_column($mail, 'to')]);
});

check('the subject counts the invoices and the new problems', function() use (&$mail) {
    $subject = $mail[0]['subject'] ?? '';

    return str_contains($subject, 'Weekly MYOB summary') && preg_match('/\d+ invoices?|one invoice/', $subject) === 1 && str_contains($subject, '2 new problems') ?: $subject;
});

check('the text body lists the activity and each new problem with its order and link', function() use (&$mail, $broken, $offBy) {
    $body = $mail[0]['body'] ?? '';
    $ref = static fn($o) => $o->reference ?: substr((string)$o->number, 0, 7);

    return str_contains($body, 'Invoices:') && str_contains($body, 'Payments:')
        && str_contains($body, 'Account 4-9999 does not exist')
        && str_contains($body, 'Order ' . $ref($broken)) && str_contains($body, 'Order ' . $ref($offBy))
        && str_contains($body, 'INV-D-3')
        && str_contains($body, 'my/documents/')
        && str_contains($body, 'Fixture Traders Pty Ltd')
        ?: $body;
});

check('the HTML body is escaped and carries the same content', function() use (&$mail) {
    $html = $mail[0]['html'] ?? '';

    return str_contains($html, 'MYOB sync summary for') && str_contains($html, 'Account 4-9999 does not exist') && str_contains($html, 'Booked at a different total') && !str_contains($html, '{{') ?: substr($html, 0, 400);
});

check('the marker records the period, the send and what was reported', function() use ($digest, $now) {
    $state = $digest->state();

    return $state['period'] === $digest->periodKey($now) && $state['lastSentAt'] !== null && $state['lastResult'] === Digest::RESULT_SENT
        && count($state['seen']) === 2 && $state['lastCount'] === 2
        ?: json_encode($state);
});

check('running again in the same period sends nothing', function() use ($digest, $now, &$mail) {
    $mail = [];
    $result = $digest->run($now->modify('+1 hour'));

    return $result === Digest::RESULT_ALREADY_SENT && $mail === [] ?: $result;
});

check('next period, with nothing pushed and nothing new, it records the period and stays quiet', function() use ($digest, $now, &$mail) {
    $mail = [];
    $next = $now->modify('+7 days');
    $result = $digest->run($next);

    return $result === Digest::RESULT_NOTHING_NEW && $mail === [] && $digest->state()['period'] === $digest->periodKey($next) ?: $result;
});

check('“send even when nothing happened” sends it anyway, saying nothing new went wrong', function() use ($digest, $settings, $now, &$mail) {
    $mail = [];
    $settings->digestSendWhenEmpty = true;
    $result = $digest->run($now->modify('+14 days'));
    $settings->digestSendWhenEmpty = false;

    return $result === Digest::RESULT_SENT && count($mail) === 2 && str_contains($mail[0]['body'], 'Nothing new went wrong') ?: json_encode([$result, $mail[0]['body'] ?? null]);
});

check('a new failure is news; the old ones are not repeated as new', function() use ($digest, $variant, $now, &$mail) {
    $mail = [];
    $another = makeOrder($variant);
    ledger($another, SyncDocument::TYPE_PAYMENT, SyncDocument::STATUS_FAILED, ['lastError' => 'Brand new failure']);
    $result = $digest->run($now->modify('+21 days'));
    $body = $mail[0]['body'] ?? '';

    return $result === Digest::RESULT_SENT && str_contains($mail[0]['subject'], 'one new problem')
        && str_contains($body, 'Brand new failure') && !str_contains($body, 'Account 4-9999')
        ?: json_encode([$result, $mail[0]['subject'] ?? null, $body]);
});

check('a failed send gives the period back, and the next run sends', function() use ($digest, $variant, $now, &$mail, &$mailFails) {
    $mail = [];
    $period = $now->modify('+28 days');
    ledger(makeOrder($variant), SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_FAILED, ['lastError' => 'news for week 4']);
    $before = $digest->state()['period'];
    $mailFails = true;
    $failed = $digest->run($period);
    $mailFails = false;
    $released = $digest->state()['period'] === $before;
    $retried = $digest->run($period);

    return $failed === Digest::RESULT_FAILED && $released && $retried === Digest::RESULT_SENT && count($mail) === 2
        ?: json_encode([$failed, $released, $retried, count($mail)]);
});

check('a handler can cancel it, and the period is given back', function() use ($digest, $variant, $now, &$mail) {
    $mail = [];
    $period = $now->modify('+35 days');
    ledger(makeOrder($variant), SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_FAILED, ['lastError' => 'news for week 5']);
    $before = $digest->state()['period'];
    $handler = static function(DigestEvent $e) {
        $e->isValid = false;
    };
    Event::on(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);
    $result = $digest->run($period);
    Event::off(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);

    return $result === Digest::RESULT_CANCELLED && $digest->state()['period'] === $before && $mail === [] ?: $result;
});

check('a handler can change the recipients and subject', function() use ($digest, $now, &$mail) {
    $mail = [];
    $handler = static function(DigestEvent $e) {
        $e->recipients = ['bookkeeper@example.com'];
        $e->subject = 'Rewritten';
    };
    Event::on(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);
    $result = $digest->run($now->modify('+35 days'));
    Event::off(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);

    return $result === Digest::RESULT_SENT && count($mail) === 1 && $mail[0]['to'] === ['bookkeeper@example.com'] && $mail[0]['subject'] === 'Rewritten' ?: json_encode($mail);
});

check('a period another process already claimed is not sent twice', function() use ($digest, $now, &$mail) {
    $mail = [];
    $period = $now->modify('+42 days');
    // The other process won the conditional UPDATE a moment ago.
    Craft::$app->getDb()->createCommand()->update(Table::DIGESTS, ['period' => $digest->periodKey($period)], ['handle' => Digest::HANDLE])->execute();
    $result = $digest->run($period);

    return $result === Digest::RESULT_ALREADY_SENT && $mail === [] ?: $result;
});

check('--force sends whatever the schedule says', function() use ($digest, $now, &$mail) {
    $mail = [];
    $result = $digest->run($now->modify('+42 days'), true);

    return $result === Digest::RESULT_SENT && count($mail) === 2 ?: $result;
});

check('a test is marked as one and never touches the marker', function() use ($digest, &$mail) {
    $mail = [];
    $before = (new Query())->from(Table::DIGESTS)->where(['handle' => Digest::HANDLE])->one();
    $sent = $digest->sendTest(['tester@example.com']);
    $after = (new Query())->from(Table::DIGESTS)->where(['handle' => Digest::HANDLE])->one();

    return $sent === 1 && str_starts_with($mail[0]['subject'], '[Test]') && str_contains($mail[0]['body'], 'This is a test')
        && $before['period'] === $after['period'] && $before['lastSentAt'] === $after['lastSentAt'] && $before['seen'] === $after['seen']
        ?: json_encode([$sent, $mail[0]['subject'] ?? null, $before, $after]);
});

check('a daily summary looks back one day the first time', function() use ($digest, $settings, $tz) {
    $settings->digestFrequency = Settings::DIGEST_DAILY;
    $since = $digest->since(['lastSentAt' => null], new DateTimeImmutable('2026-10-09 09:00', $tz))->format('Y-m-d H:i');
    $settings->digestFrequency = Settings::DIGEST_WEEKLY;

    return $since === '2026-10-08 09:00' ?: $since;
});

// ---------------------------------------------------------------------------------------------
section('Web fallback');

check('when due, it queues one SendDigest and then holds off', function() use ($digest, $now) {
    Craft::$app->getCache()->delete('my:digest:checked');
    $period = $now->modify('+49 days');
    Craft::$app->getCache()->delete('my:digest:queued:' . $digest->periodKey($period));
    $before = (int)(new Query())->from(craft\db\Table::QUEUE)->max('id');
    $first = $digest->queueIfDue($period);
    $second = $digest->queueIfDue($period);
    $jobs = array_filter(
        array_map(static fn($b) => unserialize(is_resource($b) ? stream_get_contents($b) : $b), (new Query())->select(['job'])->from(craft\db\Table::QUEUE)->where(['>', 'id', $before])->column()),
        static fn($job) => $job instanceof SendDigest,
    );
    Craft::$app->getDb()->createCommand()->delete(craft\db\Table::QUEUE, ['like', 'description', 'MYOB', false])->execute();
    Craft::$app->getCache()->delete('my:digest:checked');

    return $first && !$second && count($jobs) === 1 ?: json_encode([$first, $second, count($jobs)]);
});

check('switched off, the fallback queues nothing', function() use ($digest, $settings, $now) {
    Craft::$app->getCache()->delete('my:digest:checked');
    $settings->digestWebTrigger = false;
    $queued = $digest->queueIfDue($now->modify('+56 days'));
    $settings->digestWebTrigger = true;

    return $queued === false ?: 'queued';
});

check('the fallback is an after-request hook, never garbage collection', function() {
    $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
    $digestSource = (string)file_get_contents(dirname(__DIR__, 2) . '/src/services/Digest.php');

    return str_contains($source, 'WebApplication::EVENT_AFTER_REQUEST') && !preg_match('/Gc::EVENT_RUN[^;]*digest/i', $source) && !str_contains($digestSource, 'EVENT_RUN,')
        ?: 'wrong trigger';
});

// ---------------------------------------------------------------------------------------------
section('Console');

check('my/digest/send exits 0 on the saved settings (switched off)', function() {
    exec('php craft my/digest/send 2>&1', $out, $code);

    return $code === 0 && str_contains(implode("\n", $out), 'switched off') ?: $code . ' ' . implode("\n", $out);
});

check('my/digest/status prints the schedule', function() {
    exec('php craft my/digest/status 2>&1', $out, $code);

    return $code === 0 && str_contains(implode("\n", $out), 'Next due') ?: $code . ' ' . implode("\n", $out);
});

// ---------------------------------------------------------------------------------------------
section('Send a test summary (HTTP)');

[$viewer, $viewerPassword] = makeUser('digest', ['accessplugin-my', 'accesscp', 'my-viewdocuments', 'my-pushorders', 'my-viewlog']);

check('anonymous is refused', function() {
    $status = client(null, null)('my/digest/send-test')->getStatusCode();

    return in_array($status, [302, 400, 401, 403], true) ?: "HTTP $status";
});

check('a non-admin with every My permission is refused', function() use ($viewer, $viewerPassword) {
    $status = client($viewer->username, $viewerPassword)('my/digest/send-test')->getStatusCode();

    return $status === 403 ?: "HTTP $status";
});

check('an admin without a CSRF token is refused', function() {
    $status = client('admin', 'claudepassword')('my/digest/send-test', [], 'POST', false)->getStatusCode();

    return $status === 400 ?: "HTTP $status";
});

check('an admin GET is refused', function() {
    $status = client('admin', 'claudepassword')('my/digest/send-test', [], 'GET')->getStatusCode();

    return in_array($status, [400, 405], true) ?: "HTTP $status";
});

check('an admin POST answers JSON, and a second one inside 30 seconds is held off', function() use ($digest) {
    $markerBefore = $digest->state()['period'];
    $admin = client('admin', 'claudepassword');
    $first = $admin('my/digest/send-test');
    $second = $admin('my/digest/send-test');
    $firstData = json_decode((string)$first->getBody(), true);
    $secondData = json_decode((string)$second->getBody(), true);

    return is_array($firstData) && isset($firstData['message'])
        && str_contains((string)($secondData['message'] ?? ''), 'a moment ago')
        && $digest->state()['period'] === $markerBefore
        ?: $first->getStatusCode() . ' ' . $first->getBody() . ' / ' . $second->getStatusCode() . ' ' . $second->getBody();
});

check('the settings screen offers the test button, bound by the screen’s own script', function() {
    $template = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings.twig');

    return str_contains($template, 'data-my-action="my/digest/send-test"') ?: 'missing';
});

finish();
