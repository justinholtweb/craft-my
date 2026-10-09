<?php
/**
 * Shared bootstrap for alerts.php, orders.php and digest.php: Craft, the check runner, a captured
 * mailer, MYOB mocked with Guzzle's MockHandler, a fixture connection and order fixtures.
 *
 * Settings are only ever changed in memory here — nothing is written to project config — and every
 * fixture is removed in a shutdown function, pass or fail. The connection row the harness had
 * before the run is put back exactly as it was.
 *
 * Unlike checks.php there is no mock server: these suites test what My does *around* a request
 * (alerts, statuses, the summary), so a `MockHandler` stack on `Api::$clientConfig` and
 * `Auth::$clientConfig` is enough, and `Middleware::history` records what was sent.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\mail\Mailer;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use justinholtweb\my\db\Table;
use justinholtweb\my\models\Connection;
use justinholtweb\my\models\Settings;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function finish(): never
{
    global $passed, $failed;

    echo "\n$passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}

// craft-penny (a sibling in this shared harness) fatals every element save; detached in-process.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$originalSettings = $settings->toArray();
$suffix = bin2hex(random_bytes(3));
$cleanup = ['orders' => [], 'products' => [], 'users' => []];
$db = Craft::$app->getDb();

// What the harness had before the run, put back afterwards.
$connectionSnapshot = (new Query())->from(Table::CONNECTION)->all();
$logHighWater = (int)(new Query())->from(Table::LOG)->max('id');
$digestSnapshot = (new Query())->from(Table::DIGESTS)->all();

register_shutdown_function(function() use (&$cleanup, $plugin, $settings, $originalSettings, $connectionSnapshot, $logHighWater, $digestSnapshot) {
    $db = Craft::$app->getDb();
    $elements = Craft::$app->getElements();

    foreach ($cleanup['orders'] as $order) {
        try {
            $db->createCommand()->delete(Table::DOCUMENTS, ['orderId' => $order->id])->execute();
            $elements->deleteElement($order, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$order->id}: {$e->getMessage()}\n";
        }
    }

    foreach (array_merge($cleanup['products'], $cleanup['users']) as $element) {
        try {
            $elements->deleteElement($element, true);
        } catch (Throwable $e) {
            echo "  ! could not delete {$element->id}: {$e->getMessage()}\n";
        }
    }

    try {
        $db->createCommand()->delete(Table::ALERTS)->execute();
        $db->createCommand()->delete(Table::DIGESTS)->execute();

        foreach ($digestSnapshot as $row) {
            $db->createCommand()->insert(Table::DIGESTS, $row)->execute();
        }

        $db->createCommand()->delete(Table::LOG, ['>', 'id', $logHighWater])->execute();
        $db->createCommand()->delete(Table::CONTACTS, ['like', 'sourceKey', 'my-fixture-%', false])->execute();
        $db->createCommand()->delete(craft\db\Table::QUEUE, ['like', 'description', 'MYOB', false])->execute();
        $db->createCommand()->delete(Table::CONNECTION)->execute();

        foreach ($connectionSnapshot as $row) {
            $db->createCommand()->insert(Table::CONNECTION, $row)->execute();
        }
    } catch (Throwable $e) {
        echo "  ! could not restore My's tables: {$e->getMessage()}\n";
    }

    $plugin->getApi()->clientConfig = [];
    $plugin->getAuth()->clientConfig = [];
    $plugin->getAlerts()->webhookClient = null;
    $settings->setAttributes($originalSettings, false);
});

// Completing a fixture order would otherwise queue a real push per order, and the alert and
// summary checks start from nothing.
$settings->setAttributes([
    'syncEnabled' => false,
    'syncCustomers' => true,
    'customerMode' => Settings::CUSTOMER_PER_ORDER,
    'alertRecipients' => '',
    'alertWebhookUrl' => '',
    'alertWebhookSecret' => '',
    'alertOnFailures' => true,
    'alertOnMismatch' => true,
    'alertOnAuthFailure' => true,
    'alertFailureThreshold' => 1,
    'alertWindowMinutes' => 60,
    'alertCooldownMinutes' => 60,
    'digestEnabled' => false,
    'requestsPerSecond' => 8,
    'timeout' => 5,
    'loggingEnabled' => true,
], false);
$db->createCommand()->delete(Table::ALERTS)->execute();

// The real mailer on Symfony's null transport: the compose/send path runs in full, and the result
// does not depend on whether the harness's Mailpit is up. Everything sent is captured.
Craft::$app->getMailer()->setTransport(new Symfony\Component\Mailer\Transport\NullTransport());
$mail = [];
$mailFails = false;
Event::on(Mailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $e) use (&$mailFails) {
    if ($mailFails) {
        $e->isValid = false;
    }
});
Event::on(Mailer::class, BaseMailer::EVENT_AFTER_SEND, function(MailEvent $e) use (&$mail) {
    if ($e->isSuccessful) {
        $to = (array)$e->message->getTo();
        $symfony = $e->message->getSymfonyEmail();
        $mail[] = [
            'to' => array_map(static fn($k, $v) => is_string($k) ? $k : (string)$v, array_keys($to), $to),
            'subject' => (string)$e->message->getSubject(),
            // The decoded parts: the wire form is quoted-printable, which splits long lines.
            'body' => (string)$symfony->getTextBody(),
            'html' => (string)$symfony->getHtmlBody(),
        ];
    }
});

// ---------------------------------------------------------------------------------------------
// MYOB, mocked: the harness has no outbound network.

$journal = [];

/**
 * Queue MYOB's answers, for both the API and the token endpoint, in order.
 *
 * @param array<int, GuzzleResponse|Throwable> $responses
 */
function mockMyob(array $responses): MockHandler
{
    global $journal, $plugin;

    $journal = [];
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($journal));

    $plugin->getApi()->clientConfig = ['handler' => $stack];
    $plugin->getAuth()->clientConfig = ['handler' => $stack];

    return $mock;
}

function myobJson(int $status, array $body = [], array $headers = []): GuzzleResponse
{
    return new GuzzleResponse($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
}

function myobError(int $status, string $message, string $details = ''): GuzzleResponse
{
    return myobJson($status, ['Errors' => [['Severity' => 'Error', 'Message' => $message, 'AdditionalDetails' => $details, 'ErrorCode' => 0]]]);
}

function tokenResponse(string $accessToken): GuzzleResponse
{
    // MYOB sends `expires_in` as a string.
    return myobJson(200, ['access_token' => $accessToken, 'refresh_token' => 'refresh-' . $accessToken, 'expires_in' => '1200', 'token_type' => 'bearer']);
}

/**
 * @return string[] `METHOD host/path` for every request My made
 */
function sentRequests(): array
{
    global $journal;

    return array_map(static fn(array $e) => $e['request']->getMethod() . ' ' . $e['request']->getUri()->getHost() . $e['request']->getUri()->getPath(), $journal);
}

/**
 * A connected company file: cloud mode with tokens, or a local AccountRight server.
 */
function connectFixture(string $mode = Settings::MODE_CLOUD, bool $expired = false): Connection
{
    global $plugin;

    $plugin->getSettings()->setAttributes([
        'mode' => $mode,
        'clientId' => 'fixture-api-key',
        'clientSecret' => 'fixture-api-secret-abcdef',
        'cfUsername' => 'Administrator',
        'cfPassword' => 'fixture-cf-password',
        'companyFileId' => '',
        'localBaseUrl' => 'http://accountright.invalid:8080/accountright/',
    ], false);

    Craft::$app->getDb()->createCommand()->delete(Table::CONNECTION)->execute();

    $connection = new Connection([
        'mode' => $mode,
        'accessToken' => $mode === Settings::MODE_CLOUD ? 'fixture-access-token-1' : null,
        'refreshToken' => $mode === Settings::MODE_CLOUD ? 'fixture-refresh-token-1' : null,
        'expiryDate' => $mode === Settings::MODE_CLOUD ? new DateTime($expired ? '-1 hour' : '+1 hour') : null,
        'companyFileId' => '11111111-2222-3333-4444-555555555555',
        'companyFileName' => 'Fixture Traders Pty Ltd',
        'dateConnected' => new DateTime(),
    ]);

    $plugin->getAuth()->saveConnection($connection);

    return $plugin->getAuth()->getConnection();
}

function disconnectFixture(): void
{
    global $plugin;

    // Deletes the row and forgets the cached one.
    $plugin->getAuth()->disconnect();
}

/**
 * The alert latch row for an incident.
 *
 * @return array<string, mixed>
 */
function latch(string $incident): array
{
    return (array)((new Query())->from(Table::ALERTS)->where(['incident' => $incident])->one() ?: []);
}

function resetAlerts(): void
{
    global $mail;

    Craft::$app->getDb()->createCommand()->delete(Table::ALERTS)->execute();
    $mail = [];
}

// ---------------------------------------------------------------------------------------------
// Commerce fixtures.

function makeVariant(float $price = 20.0): Variant
{
    global $cleanup, $suffix;

    $product = new Product();
    $product->typeId = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0]->id;
    $product->title = "My fixture $suffix";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = 'MY-FIX-' . $suffix . '-' . bin2hex(random_bytes(2));
    $variant->basePrice = $price;
    $variant->isDefault = true;
    $product->setVariants([$variant]);

    Craft::$app->getElements()->saveElement($product, true, true, false)
        or throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    $cleanup['products'][] = $product;

    return $product->getVariants()[0];
}

function makeOrder(Variant $variant, bool $complete = true): Order
{
    global $cleanup;

    $commerce = Commerce::getInstance();
    $order = new Order();
    $order->storeId = $commerce->getStores()->getPrimaryStore()->id;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = $commerce->getCarts()->generateCartNumber();
    // A unique email per order, so no MYOB card is ever shared between fixtures.
    $order->setEmail('my-fixture-' . bin2hex(random_bytes(5)) . '@example.com');

    Craft::$app->getElements()->saveElement($order, false, true, false) or throw new RuntimeException('Could not save order');
    $cleanup['orders'][] = $order;

    $order->setLineItems([$commerce->getLineItems()->createLineItem($order, $variant->id, [], 1)]);
    $address = [
        'fullName' => 'Dana Fixture',
        'addressLine1' => '19 Mock Street',
        'locality' => 'Melbourne',
        'administrativeArea' => 'VIC',
        'postalCode' => '3000',
        'countryCode' => 'AU',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);
    Craft::$app->getElements()->saveElement($order, false, true, false) or throw new RuntimeException('Could not save order lines');

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

/**
 * A ledger row, written through the single writer like everything else.
 *
 * @param array<string, mixed> $attributes
 */
function ledger(Order $order, string $docType, string $status, array $attributes = []): SyncDocument
{
    global $plugin;

    $document = new SyncDocument([
        'orderId' => $order->id,
        'docType' => $docType,
        'sourceKey' => $docType === SyncDocument::TYPE_INVOICE ? SyncDocument::SOURCE_ORDER : 'txn-' . bin2hex(random_bytes(4)),
        'status' => $status,
        'attempts' => 1,
        'amount' => 20.0,
        'currency' => $order->currency ?: 'AUD',
    ] + $attributes);

    if ($status === SyncDocument::STATUS_SYNCED && !array_key_exists('dateSynced', $attributes)) {
        $document->dateSynced = new DateTime();
    }

    if ($status === SyncDocument::STATUS_SYNCED && !array_key_exists('myobUid', $attributes)) {
        $document->myobUid = 'uid-' . bin2hex(random_bytes(6));
    }

    return $plugin->getSync()->record($document);
}

/**
 * Move a ledger row back in time, as though it happened `$minutes` ago.
 */
function age(SyncDocument $document, int $minutes): void
{
    $then = craft\helpers\Db::prepareDateForDb((new DateTime())->modify("-$minutes minutes"));

    Craft::$app->getDb()->createCommand()->update(Table::DOCUMENTS, [
        'dateUpdated' => $then,
        'dateSynced' => $document->status === SyncDocument::STATUS_SYNCED ? $then : null,
    ], ['id' => $document->id])->execute();
}

function reloadOrder(Order $order): Order
{
    return Order::find()->id($order->id)->status(null)->one();
}

// ---------------------------------------------------------------------------------------------
// HTTP against the harness's own web server, as a real signed-in user.

/**
 * @param string[] $permissions
 * @return array{0: User, 1: string}
 */
function makeUser(string $handle, array $permissions): array
{
    global $cleanup, $suffix;

    $password = 'My-' . bin2hex(random_bytes(6));
    $user = new User(['username' => "my-$handle-$suffix", 'email' => "my-$handle-$suffix@example.com", 'newPassword' => $password]);
    Craft::$app->getElements()->saveElement($user, false) or throw new RuntimeException('Could not save user');
    Craft::$app->getUsers()->activateUser($user);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
    $cleanup['users'][] = $user;

    return [$user, $password];
}

/**
 * A signed-in client. Returns `fn(action, params, method, withCsrf, json)`.
 */
function client(?string $username, ?string $password): Closure
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 60]);
    $accept = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=admin/actions/users/session-info', ['headers' => $accept])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($username !== null) {
        $http->post('index.php?p=admin/actions/users/login', ['headers' => $accept, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
            or throw new RuntimeException("Could not sign in as $username");
    }

    return static function(string $action, array $params = [], string $method = 'POST', bool $withCsrf = true, bool $json = false) use ($http, $accept, $csrf) {
        $options = ['headers' => $accept];

        if ($method === 'POST' && $json) {
            $options['json'] = $params;
            $options['headers'] += $withCsrf ? ['X-CSRF-Token' => $csrf()] : [];
        } elseif ($method === 'POST') {
            $options['form_params'] = $params + ($withCsrf ? ['CRAFT_CSRF_TOKEN' => $csrf()] : []);
        }

        return $http->request($method, "index.php?p=admin/actions/$action", $options);
    };
}
