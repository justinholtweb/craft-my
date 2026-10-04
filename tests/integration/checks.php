<?php
/**
 * My integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-my/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: fixture products, orders, adjustments, transactions, ledger rows,
 * log rows and the plugin settings it overwrites are all restored in a `finally`, pass or fail.
 *
 * Most of the run happens against a **mock MYOB API** started by this script (`mock-myob.php`),
 * reached in `local` mode. That means the client is exercised over real HTTP — header assembly,
 * `returnBody`, OData filters, paging, retry, backoff, error parsing — rather than mocked out at
 * the seam where the interesting bugs live. The mock also totals invoices itself, so the "did MYOB
 * book what the customer paid" check is answered by something that is not the code under test.
 */

const MOCK_PORT = 8899;
const MOCK_BASE = 'http://127.0.0.1:' . MOCK_PORT . '/accountright/';
const MOCK_CF = '11111111-2222-3333-4444-555555555555';

/**
 * Start the mock MYOB server **before Craft boots**.
 *
 * This is not tidiness. `shell_exec()` forks, and a forked child inherits every open file
 * descriptor — including Craft's MySQL socket. A long-lived child holding that socket produces
 * "MySQL server has gone away" and stale-project-config errors minutes later, in code that has
 * nothing to do with either. Starting the server while there is no database connection to inherit
 * removes the problem rather than working around it.
 */
function startMock(): int
{
    $script = escapeshellarg(__DIR__ . '/mock-myob.php');
    $pid = (int)trim((string)shell_exec(sprintf(
        'php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
        MOCK_PORT,
        $script,
    )));

    // The built-in server takes a moment to bind.
    for ($i = 0; $i < 50; $i++) {
        usleep(100_000);

        if (@file_get_contents('http://127.0.0.1:' . MOCK_PORT . '/__reset') !== false) {
            return $pid;
        }
    }

    throw new RuntimeException('The mock MYOB server never came up on port ' . MOCK_PORT);
}

$mockPid = startMock();

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\Json;
use justinholtweb\my\db\Table;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\helpers\Dates;
use justinholtweb\my\helpers\Money;
use justinholtweb\my\models\Connection;
use justinholtweb\my\models\LogEntry;
use justinholtweb\my\models\Settings;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$originalSettings = $plugin->getSettings()->toArray();

// `craft-penny` (a sibling in this shared harness) types its
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler `ModelEvent` while Craft passes an `ElementEvent`,
// so saving *any* element fatals while it is enabled. Nothing to do with My; detached in-process
// here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

/**
 * Persist settings for the duration of the run.
 *
 * Project config writes are buffered until the request ends, and a bare console script has no
 * request end — so it pushes them itself.
 *
 * `saveModifiedConfigData()`, deliberately, and **not** `flush()`. `flush()` also writes the YAML
 * files, and writing them stamps a new `configVersion` into the `info` table that the already
 * loaded `Info` model knows nothing about — so the *second* `flush()` in the same process decides
 * another request has beaten it and throws `StaleResourceException`. A suite that toggles settings
 * thirty times cannot use it. Reaching only the `projectconfig` table is also exactly right here:
 * these settings are fixtures, and leaving the YAML alone means the harness reverts them.
 */
function applySettings(array $values): void
{
    global $plugin;

    try {
        Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
        Craft::$app->getProjectConfig()->saveModifiedConfigData();
    } catch (craft\errors\StaleResourceException) {
        // Craft guards a project config write by comparing the `configVersion` in the `info` table
        // against the one on the already-loaded `Info` model, and refuses if they differ — the
        // assumption being that another *request* moved it. In a long-running console script the
        // two can drift within one process, and once they have, every subsequent write throws.
        // Re-reading the stored version and putting it back on the loaded model is the whole fix.
        resyncConfigVersion();

        Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
        Craft::$app->getProjectConfig()->saveModifiedConfigData();
    }
}

/**
 * Bring the in-memory `configVersion` back into line with the database.
 */
function resyncConfigVersion(): void
{
    $info = Craft::$app->getInfo();
    $info->configVersion = (string)(new craft\db\Query())
        ->select(['configVersion'])
        ->from(['{{%info}}'])
        ->scalar();

    Craft::$app->saveInfo($info, ['configVersion']);
}

function makeProduct(string $sku, float $price): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "My fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . Json::encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 */
function makeOrder(array $lines, string $email = 'my-fixture@example.com', bool $complete = true): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail($email);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order: ' . Json::encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = Commerce::getInstance()->getLineItems()->createLineItem(
            $order,
            $line['variant']->id,
            [],
            $line['qty'],
        );
    }

    $order->setLineItems($lineItems);

    // Commerce refuses an address element it does not own, so the attributes go in as an array and
    // Commerce builds the owned element itself.
    $address = [
        'fullName' => 'Dana Fixture',
        'organization' => '',
        'addressLine1' => '19 Mock Street',
        'locality' => 'Melbourne',
        'administrativeArea' => 'VIC',
        'postalCode' => '3000',
        'countryCode' => 'AU',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order lines: ' . Json::encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

/**
 * Add an adjustment to a completed order.
 *
 * A completed order is locked at `RECALCULATION_MODE_NONE`, so adjustments written here survive —
 * which is the only way to build an order with a known tax and discount shape without running
 * Commerce's own tax engine.
 */
function addAdjustment(Order $order, string $type, float $amount, bool $included = false, ?int $lineItemId = null): void
{
    $adjustment = new OrderAdjustment();
    $adjustment->orderId = $order->id;
    $adjustment->lineItemId = $lineItemId;
    $adjustment->type = $type;
    $adjustment->name = ucfirst($type);
    $adjustment->description = ucfirst($type);
    $adjustment->amount = $amount;
    $adjustment->included = $included;
    $adjustment->setSourceSnapshot(['fixture' => true]);

    if (!Commerce::getInstance()->getOrderAdjustments()->saveOrderAdjustment($adjustment)) {
        throw new RuntimeException('Could not save adjustment: ' . Json::encode($adjustment->getErrors()));
    }
}

/**
 * Add a transaction to an order.
 *
 * Built by hand rather than through `Transactions::createTransaction()`, which reaches for
 * `$order->getGateway()->id` and fatals on a fixture order that was never taken to checkout.
 */
/**
 * Insert an adjustment that names a line item which is not on the order.
 *
 * Written straight to the table on purpose: `OrderAdjustments::saveOrderAdjustment()` rewrites
 * `lineItemId` from the *related model* rather than the property, so an orphan cannot be created
 * through the service at all. This is the shape the row takes after its line item has gone — and
 * it is the one case where the order total and the invoice cannot be made to agree, which is what
 * reconciliation is for.
 */
function addOrphanedAdjustment(Order $order, string $type, float $amount): void
{
    $now = craft\helpers\Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%commerce_orderadjustments}}', [
        'orderId' => $order->id,
        'lineItemId' => 999999,
        'type' => $type,
        'name' => 'Orphan',
        'description' => 'Orphan',
        'amount' => $amount,
        'included' => false,
        'isEstimated' => false,
        'sourceSnapshot' => '{}',
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => craft\helpers\StringHelper::UUID(),
    ])->execute();
}

function addTransaction(Order $order, string $type, float $amount, string $status = TransactionRecord::STATUS_SUCCESS): void
{
    $gateway = Commerce::getInstance()->getGateways()->getAllGateways()->first();

    $transaction = new craft\commerce\models\Transaction();
    $transaction->orderId = $order->id;
    $transaction->setOrder($order);
    $transaction->gatewayId = $gateway?->id;
    $transaction->type = $type;
    $transaction->status = $status;
    $transaction->amount = $amount;
    $transaction->paymentAmount = $amount;
    $transaction->currency = $order->currency;
    $transaction->paymentCurrency = $order->paymentCurrency ?: $order->currency;
    $transaction->paymentRate = 1.0;
    $transaction->hash = md5(uniqid('my-fixture', true));
    $transaction->reference = 'ref-' . substr(md5((string)microtime(true)), 0, 8);

    if (!Commerce::getInstance()->getTransactions()->saveTransaction($transaction, false)) {
        throw new RuntimeException('Could not save transaction: ' . Json::encode($transaction->getErrors()));
    }
}

function reload(Order $order): Order
{
    $fresh = Order::find()->id($order->id)->status(null)->one();

    if (!$fresh instanceof Order) {
        throw new RuntimeException('Could not reload order ' . $order->id);
    }

    return $fresh;
}

// The mock server
// ---------------------------------------------------------------------------

function mockControl(array $data): void
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => Json::encode($data),
        'timeout' => 5,
    ]]);

    @file_get_contents('http://127.0.0.1:' . MOCK_PORT . '/__control', false, $context);
}

function mockReset(): void
{
    @file_get_contents('http://127.0.0.1:' . MOCK_PORT . '/__reset');
}

/**
 * @return array<int, array{method: string, path: string, query: array, headers: array, body: array|null}>
 */
function mockJournal(): array
{
    $raw = @file_get_contents('http://127.0.0.1:' . MOCK_PORT . '/__journal') ?: '';
    $out = [];

    foreach (explode("\n", trim($raw)) as $line) {
        if (trim($line) === '') {
            continue;
        }

        $decoded = Json::decodeIfJson($line);

        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }

    return $out;
}

/**
 * The last request the mock saw whose path ends with `$needle`.
 */
function lastRequest(string $needle, ?string $method = null): ?array
{
    foreach (array_reverse(mockJournal()) as $entry) {
        if (!str_ends_with($entry['path'], $needle)) {
            continue;
        }

        if ($method !== null && $entry['method'] !== $method) {
            continue;
        }

        return $entry;
    }

    return null;
}

/**
 * Point the plugin at the mock, in local mode, with a connected company file.
 */
function connectToMock(): void
{
    applySettings([
        'mode' => Settings::MODE_LOCAL,
        'localBaseUrl' => MOCK_BASE,
        'companyFileId' => '',
        'cfUsername' => 'Administrator',
        'cfPassword' => 'secret',
        'salesAccount' => '4-1000',
        'freightAccount' => '4-2000',
        'discountAccount' => '4-1000',
        'roundingAccount' => '4-1000',
        'paymentAccount' => '1-1100',
        'refundAccount' => '1-1100',
        'defaultTaxCode' => 'GST',
        'freightTaxCode' => 'GST',
        'zeroTaxCode' => 'FRE',
        'invoiceLayout' => Settings::LAYOUT_SERVICE,
        'numberSource' => 'reference',
        'numberPrefix' => '',
        'onTotalMismatch' => Settings::MISMATCH_FAIL,
        'syncCustomers' => true,
        'customerMode' => Settings::CUSTOMER_PER_ORDER,
        'syncPayments' => true,
        'syncRefunds' => true,
        'depositTo' => Settings::DEPOSIT_UNDEPOSITED,
        'loggingEnabled' => true,
        'logPayloads' => true,
        'requestsPerSecond' => 8,
        'timeout' => 10,
    ]);

    $auth = Plugin::getInstance()->getAuth();
    $connection = $auth->getConnection();
    $connection->mode = Settings::MODE_LOCAL;
    $connection->companyFileId = MOCK_CF;
    $connection->companyFileUri = rtrim(MOCK_BASE, '/') . '/' . MOCK_CF;
    $connection->companyFileName = 'Mock Traders Pty Ltd';
    $connection->dateConnected = new DateTime();
    $auth->saveConnection($connection);

    Plugin::getInstance()->getReference()->flush();
}

// ---------------------------------------------------------------------------

try {
    echo '  · mock MYOB server up on port ' . MOCK_PORT . " (pid $mockPid)\n";

    mockReset();
    connectToMock();

    // -----------------------------------------------------------------------
    section('Money');

    check('rounding is to cents', fn() => Money::round(1.005) === 1.01 ?: 'got ' . Money::round(1.005));
    check('minor units are integers', fn() => Money::minor(19.99) === 1999 ?: 'got ' . var_export(Money::minor(19.99), true));
    check('minor units round rather than truncate', fn() => Money::minor(0.1 + 0.2) === 30);
    check('a negative amount keeps its sign in minor units', fn() => Money::minor(-4.55) === -455);
    check('unit prices keep six decimal places', fn() => Money::unit(10 / 3) === 3.333333 ?: 'got ' . Money::unit(10 / 3));

    check('summing avoids float drift', function() {
        // 0.1 + 0.2 + 0.3 is 0.6000000000000001 in floats, and 0.6 in a ledger.
        return Money::sum([0.1, 0.2, 0.3]) === 0.6 ?: 'got ' . var_export(Money::sum([0.1, 0.2, 0.3]), true);
    });

    check('a hundred one-cent lines add up to a dollar', function() {
        return Money::sum(array_fill(0, 100, 0.01)) === 1.0 ?: 'got ' . var_export(Money::sum(array_fill(0, 100, 0.01)), true);
    });

    check('equality within tolerance', fn() => Money::equals(10.00, 10.01, 1) && !Money::equals(10.00, 10.02, 1));
    check('equality with no tolerance is exact', fn() => !Money::equals(10.00, 10.01, 0));
    check('the difference is signed', fn() => Money::diffMinor(10.00, 9.95) === 5 && Money::diffMinor(9.95, 10.00) === -5);

    // -----------------------------------------------------------------------
    section('Dates');

    check('MYOB dates have no offset', function() {
        $formatted = Dates::format(new DateTime('2026-03-01 14:30:00', new DateTimeZone('UTC')), new DateTimeZone('UTC'));

        return $formatted === '2026-03-01T14:30:00' ?: 'got ' . var_export($formatted, true);
    });

    check('a transaction date is midnight local', function() {
        $formatted = Dates::formatDay(new DateTime('2026-03-01 14:30:00', new DateTimeZone('UTC')), new DateTimeZone('UTC'));

        return $formatted === '2026-03-01T00:00:00' ?: 'got ' . var_export($formatted, true);
    });

    check('a UTC instant is dated by the local day, not the UTC one', function() {
        // 9am on the 1st in Sydney is 10pm on the 28th in UTC. Formatting the UTC day would book
        // the invoice to the wrong month.
        $utc = new DateTime('2026-02-28 22:00:00', new DateTimeZone('UTC'));
        $formatted = Dates::formatDay($utc, new DateTimeZone('Australia/Sydney'));

        return $formatted === '2026-03-01T00:00:00' ?: 'got ' . var_export($formatted, true);
    });

    check('null formats to null', fn() => Dates::format(null) === null);
    check('a MYOB date parses back', fn() => Dates::parse('2026-03-01T00:00:00')?->format('Y-m-d') === '2026-03-01');
    check('nonsense parses to null', fn() => Dates::parse('not a date') === null);
    check('an empty string parses to null', fn() => Dates::parse('') === null);

    // -----------------------------------------------------------------------
    section('Settings');

    check('an empty string does not fatal a typed int', function() {
        // Craft's CP posts '' for a cleared number field, and PHP throws a TypeError rather than
        // coercing. An author clearing "Timeout" must not fatal the save.
        $settings = new Settings();
        $settings->setAttributes(['timeout' => ''], false);

        return $settings->timeout === 30 ?: 'got ' . var_export($settings->timeout, true);
    });

    check('a numeric string becomes an int', function() {
        $settings = new Settings();
        $settings->setAttributes(['timeout' => '45'], false);

        return $settings->timeout === 45 ?: 'got ' . var_export($settings->timeout, true);
    });

    check('an empty string on a nullable bool becomes null', function() {
        $settings = new Settings();
        $settings->setAttributes(['taxInclusive' => ''], false);

        return $settings->taxInclusive === null;
    });

    check('“0” on a nullable bool is false, not null', function() {
        $settings = new Settings();
        $settings->setAttributes(['taxInclusive' => '0'], false);

        return $settings->taxInclusive === false ?: 'got ' . var_export($settings->taxInclusive, true);
    });

    check('nothing is marked required', function() {
        $settings = new Settings();

        foreach ($settings->rules() as $rule) {
            if (($rule[1] ?? null) === 'required') {
                return 'a rule marks ' . Json::encode($rule[0]) . ' required';
            }
        }

        return true;
    });

    check('a blank settings model still validates', function() {
        // The whole point of not using `required`: a fresh install has to be able to save the
        // screen before MYOB exists.
        $settings = new Settings();
        $settings->syncEnabled = false;

        return $settings->validate() ?: Json::encode($settings->getErrors());
    });

    check('a service layout with no sales account is rejected', function() {
        $settings = new Settings();
        $settings->syncEnabled = true;
        $settings->invoiceLayout = Settings::LAYOUT_SERVICE;
        $settings->salesAccount = '';

        return !$settings->validate() && $settings->hasErrors('salesAccount');
    });

    check('an item layout does not demand a sales account', function() {
        $settings = new Settings();
        $settings->syncEnabled = true;
        $settings->invoiceLayout = Settings::LAYOUT_ITEM;
        $settings->salesAccount = '';

        return !$settings->hasErrors('salesAccount') && $settings->validate();
    });

    check('a bad local URL is only rejected in local mode', function() {
        $cloud = new Settings();
        $cloud->mode = Settings::MODE_CLOUD;
        $cloud->localBaseUrl = 'nonsense';
        $cloud->salesAccount = '4-1000';

        $local = new Settings();
        $local->mode = Settings::MODE_LOCAL;
        $local->localBaseUrl = 'nonsense';
        $local->salesAccount = '4-1000';

        return $cloud->validate() && !$local->validate() && $local->hasErrors('localBaseUrl');
    });

    check('a local URL that is not HTTP is rejected', function() {
        // FILTER_VALIDATE_URL alone accepts these, and every request carries the company file
        // credentials to whatever this points at.
        foreach (['file:///etc/passwd', 'gopher://127.0.0.1:6379/_x', 'ftp://example.com/'] as $url) {
            $settings = new Settings();
            $settings->mode = Settings::MODE_LOCAL;
            $settings->localBaseUrl = $url;
            $settings->salesAccount = '4-1000';

            if ($settings->validate() || !$settings->hasErrors('localBaseUrl')) {
                return "accepted $url";
            }
        }

        $ok = new Settings();
        $ok->mode = Settings::MODE_LOCAL;
        $ok->localBaseUrl = 'https://accountright.example.com:8443/accountright/';
        $ok->salesAccount = '4-1000';

        return $ok->validate() ?: Json::encode($ok->getErrors());
    });

    check('the cf token is base64 of username:password', function() {
        $settings = new Settings();
        $settings->cfUsername = 'Administrator';
        $settings->cfPassword = 'hunter2';

        return $settings->getCfToken() === base64_encode('Administrator:hunter2');
    });

    check('a file with no security gets no token at all', function() {
        // An empty token is not the same as no token, and MYOB treats them differently.
        $settings = new Settings();
        $settings->cfUsername = '';
        $settings->cfPassword = '';

        return $settings->getCfToken() === null;
    });

    check('a username with no password still gets a token', function() {
        $settings = new Settings();
        $settings->cfUsername = 'Administrator';
        $settings->cfPassword = '';

        return $settings->getCfToken() === base64_encode('Administrator:');
    });

    check('the cloud base URL is MYOB’s', function() {
        $settings = new Settings();
        $settings->mode = Settings::MODE_CLOUD;

        return $settings->getApiBaseUrl() === 'https://api.myob.com/accountright/';
    });

    check('the local base URL always ends in a slash', function() {
        $settings = new Settings();
        $settings->mode = Settings::MODE_LOCAL;
        $settings->localBaseUrl = 'http://localhost:8080/accountright';

        return $settings->getApiBaseUrl() === 'http://localhost:8080/accountright/';
    });

    check('cloud mode needs a key and a secret before it is configured', function() {
        $settings = new Settings();
        $settings->mode = Settings::MODE_CLOUD;
        $settings->clientId = 'abc';

        if ($settings->isConfigured()) {
            return 'a key alone was accepted';
        }

        $settings->clientSecret = 'def';

        return $settings->isConfigured();
    });

    check('the redirect URI points at the OAuth callback', function() {
        $uri = (new Settings())->getRedirectUri();

        return str_contains($uri, 'my/oauth/callback') ?: "got $uri";
    });

    check('MYOB’s payment methods are the only ones offered', function() {
        // Anything outside this list is a 400 from the company file.
        return in_array('EFTPOS', Settings::PAYMENT_METHODS, true)
            && in_array('American Express', Settings::PAYMENT_METHODS, true)
            && !in_array('PayPal', Settings::PAYMENT_METHODS, true);
    });

    // -----------------------------------------------------------------------
    section('Connection');

    check('a connection with no company file is not connected', function() {
        $connection = new Connection(['mode' => Settings::MODE_LOCAL]);

        return !$connection->isConnected();
    });

    check('local mode needs no refresh token', function() {
        $connection = new Connection([
            'mode' => Settings::MODE_LOCAL,
            'companyFileId' => MOCK_CF,
        ]);

        return $connection->isConnected() && !$connection->isExpired();
    });

    check('cloud mode without a refresh token is not connected', function() {
        $connection = new Connection([
            'mode' => Settings::MODE_CLOUD,
            'companyFileId' => MOCK_CF,
        ]);

        return !$connection->isConnected();
    });

    check('a token is treated as expired inside the refresh margin', function() {
        // MYOB issues twenty-minute tokens; a job starting at nineteen minutes fifty should
        // refresh rather than find out the hard way.
        $connection = new Connection([
            'mode' => Settings::MODE_CLOUD,
            'companyFileId' => MOCK_CF,
            'accessToken' => 'x',
            'refreshToken' => 'y',
            'expiryDate' => (new DateTime())->modify('+30 seconds'),
        ]);

        return $connection->isExpired();
    });

    check('a fresh token is not expired', function() {
        $connection = new Connection([
            'mode' => Settings::MODE_CLOUD,
            'companyFileId' => MOCK_CF,
            'accessToken' => 'x',
            'refreshToken' => 'y',
            'expiryDate' => (new DateTime())->modify('+15 minutes'),
        ]);

        return !$connection->isExpired();
    });

    check('the company file base URI ends in a slash', function() {
        $uri = Plugin::getInstance()->getAuth()->getConnection()->getCompanyFileBaseUri();

        return $uri === rtrim(MOCK_BASE, '/') . '/' . MOCK_CF . '/' ?: 'got ' . var_export($uri, true);
    });

    check('a company file ID in settings overrides the stored one', function() {
        // A database restored from another environment must not be able to invoice the wrong file.
        applySettings(['companyFileId' => 'override-file-id']);
        $override = Plugin::getInstance()->getAuth()->getConnection()->getEffectiveCompanyFileId();
        applySettings(['companyFileId' => '']);

        return $override === 'override-file-id' ?: 'got ' . var_export($override, true);
    });

    check('a company file URI off the configured server is refused in local mode', function() {
        $auth = Plugin::getInstance()->getAuth();
        $good = rtrim(MOCK_BASE, '/') . '/' . MOCK_CF;

        $refused = [
            'http://evil.example/accountright/' . MOCK_CF,
            'http://127.0.0.1:9999/accountright/' . MOCK_CF,
            'http://user:pass@127.0.0.1:' . MOCK_PORT . '/accountright/' . MOCK_CF,
            'file:///etc/passwd',
            'not a url',
        ];

        foreach ($refused as $uri) {
            if ($auth->isAcceptableCompanyFileUri($uri)) {
                return "accepted $uri";
            }
        }

        return $auth->isAcceptableCompanyFileUri($good) ?: "refused $good";
    });

    check('a company file URI must be HTTPS on myob.com in cloud mode', function() {
        $auth = Plugin::getInstance()->getAuth();

        applySettings(['mode' => Settings::MODE_CLOUD]);

        try {
            $accepted = [
                'https://api.myob.com/accountright/' . MOCK_CF,
                'https://ar1.api.myob.com/accountright/' . MOCK_CF,
            ];
            $refused = [
                'http://api.myob.com/accountright/' . MOCK_CF,
                'https://api.myob.com.evil.example/accountright/' . MOCK_CF,
                'https://evilmyob.com/accountright/' . MOCK_CF,
                rtrim(MOCK_BASE, '/') . '/' . MOCK_CF,
            ];

            foreach ($accepted as $uri) {
                if (!$auth->isAcceptableCompanyFileUri($uri)) {
                    return "refused $uri";
                }
            }

            foreach ($refused as $uri) {
                if ($auth->isAcceptableCompanyFileUri($uri)) {
                    return "accepted $uri";
                }
            }

            return true;
        } finally {
            applySettings(['mode' => Settings::MODE_LOCAL]);
        }
    });

    check('selecting a company file with a foreign URI saves nothing', function() {
        // The URI arrives in POST data from the settings screen, and every later request carries
        // the bearer token and company file credentials to it.
        $auth = Plugin::getInstance()->getAuth();
        $before = $auth->getConnection()->companyFileUri;

        try {
            $auth->selectCompanyFile(MOCK_CF, 'http://evil.example/accountright/' . MOCK_CF, 'Evil');

            return 'no exception';
        } catch (MyobApiException) {
            // expected
        }

        $after = (new craft\db\Query())->select(['companyFileUri'])->from([Table::CONNECTION])->scalar();

        return $after === $before && $auth->getConnection()->companyFileUri === $before
            ?: 'stored ' . var_export($after, true);
    });

    // -----------------------------------------------------------------------
    section('Errors');

    check('a 400 is not retryable', fn() => !(new MyobApiException('x', 400))->isRetryable());
    check('a 429 is retryable', fn() => (new MyobApiException('x', 429))->isRetryable());
    check('a 503 is retryable', fn() => (new MyobApiException('x', 503))->isRetryable());
    check('no response at all is retryable', fn() => (new MyobApiException('x', null))->isRetryable());
    check('a 409 is a conflict', fn() => (new MyobApiException('x', 409))->isConflict());

    check('the useful half of a MYOB error survives', function() {
        // `Message` says "Invalid data"; `AdditionalDetails` says which field. Both are kept.
        $e = new MyobApiException('x', 400, [[
            'Severity' => 'Error',
            'Message' => 'Invalid data',
            'AdditionalDetails' => 'Account is required',
        ]]);

        return $e->getDetails() === ['Invalid data — Account is required'] ?: Json::encode($e->getDetails());
    });

    check('a duplicated detail is not printed twice', function() {
        $e = new MyobApiException('x', 400, [[
            'Message' => 'Same thing',
            'AdditionalDetails' => 'Same thing',
        ]]);

        return $e->getDetails() === ['Same thing'] ?: Json::encode($e->getDetails());
    });

    check('a malformed error array does not explode', function() {
        $e = new MyobApiException('x', 400, ['not an array', ['Message' => 'ok']]);

        return $e->getDetails() === ['ok'] ?: Json::encode($e->getDetails());
    });

    // -----------------------------------------------------------------------
    section('Log');

    check('a bearer token never reaches a log row', function() {
        $log = Plugin::getInstance()->getLog();
        $log->write('test.redaction', [
            'summary' => 'redaction fixture ' . $GLOBALS['suffix'],
            'request' => 'Authorization: Bearer sk-super-secret-token',
        ]);

        $entry = $log->getEntries(['action' => 'test.redaction'], 1)[0] ?? null;
        $full = $entry ? $log->getEntryById($entry->id) : null;

        if ($full === null) {
            return 'nothing was written';
        }

        return !str_contains((string)$full->request, 'sk-super-secret-token')
            && str_contains((string)$full->request, '[redacted]')
            ?: 'got ' . var_export($full->request, true);
    });

    check('a refresh token in a JSON body is redacted', function() {
        $log = Plugin::getInstance()->getLog();
        $log->write('test.redaction', [
            'summary' => 'json redaction ' . $GLOBALS['suffix'],
            'response' => '{"access_token":"abc123","refresh_token":"def456","expires_in":"1200"}',
        ]);

        $entry = $log->getEntries(['action' => 'test.redaction'], 1)[0] ?? null;
        $full = $entry ? $log->getEntryById($entry->id) : null;

        if ($full === null) {
            return 'nothing was written';
        }

        return !str_contains((string)$full->response, 'abc123')
            && !str_contains((string)$full->response, 'def456')
            && str_contains((string)$full->response, '1200')
            ?: 'got ' . var_export($full->response, true);
    });

    check('the company file token is redacted', function() {
        $log = Plugin::getInstance()->getLog();
        $log->write('test.redaction', [
            'summary' => 'cftoken redaction ' . $GLOBALS['suffix'],
            'request' => 'x-myobapi-cftoken: QWRtaW5pc3RyYXRvcjpodW50ZXIy',
        ]);

        $entry = $log->getEntries(['action' => 'test.redaction'], 1)[0] ?? null;
        $full = $entry ? $log->getEntryById($entry->id) : null;

        return $full !== null && !str_contains((string)$full->request, 'QWRtaW5pc3RyYXRvcg');
    });

    check('an oversized payload is truncated', function() {
        $log = Plugin::getInstance()->getLog();
        $log->write('test.truncation', [
            'summary' => 'truncation fixture ' . $GLOBALS['suffix'],
            'request' => str_repeat('x', 100000),
        ]);

        $entry = $log->getEntries(['action' => 'test.truncation'], 1)[0] ?? null;
        $full = $entry ? $log->getEntryById($entry->id) : null;

        return $full !== null && str_contains((string)$full->request, '[truncated]');
    });

    check('a summary longer than the column is cut, not rejected', function() {
        $log = Plugin::getInstance()->getLog();
        $log->write('test.longsummary', ['summary' => str_repeat('y', 900)]);

        $entry = $log->getEntries(['action' => 'test.longsummary'], 1)[0] ?? null;

        return $entry !== null && mb_strlen((string)$entry->summary) === 255;
    });

    check('logging off writes nothing', function() {
        applySettings(['loggingEnabled' => false]);

        $log = Plugin::getInstance()->getLog();
        $before = $log->getTotal(['action' => 'test.disabled']);
        $log->write('test.disabled', ['summary' => 'should not exist']);
        $after = $log->getTotal(['action' => 'test.disabled']);

        applySettings(['loggingEnabled' => true]);

        return $before === $after;
    });

    check('payload logging off keeps the summary and drops the body', function() {
        applySettings(['logPayloads' => false]);

        $log = Plugin::getInstance()->getLog();
        $log->write('test.nopayload', ['summary' => 'kept', 'request' => 'dropped']);
        $entry = $log->getEntries(['action' => 'test.nopayload'], 1)[0] ?? null;
        $full = $entry ? $log->getEntryById($entry->id) : null;

        applySettings(['logPayloads' => true]);

        return $full !== null && $full->summary === 'kept' && $full->request === null;
    });

    check('the actions list is distinct', function() {
        $actions = Plugin::getInstance()->getLog()->getActions();

        return count($actions) === count(array_unique($actions));
    });

    check('pruning with no retention deletes nothing', fn() => Plugin::getInstance()->getLog()->prune(0) === 0);

    // -----------------------------------------------------------------------
    section('API transport');

    check('a GET reaches the mock and decodes', function() {
        $data = Plugin::getInstance()->getApi()->get('', ['action' => 'test.verify']);

        return ($data['CompanyFile']['Name'] ?? null) === 'Mock Traders Pty Ltd' ?: Json::encode($data);
    });

    check('local mode sends HTTP Basic, not a bearer token', function() {
        $request = lastRequest('/' . MOCK_CF . '/', 'GET');

        if ($request === null) {
            return 'the mock saw no request';
        }

        $auth = $request['headers']['authorization'] ?? '';

        return $auth === 'Basic ' . base64_encode('Administrator:secret') ?: "got " . var_export($auth, true);
    });

    check('every request carries the API version header', function() {
        $request = lastRequest('/' . MOCK_CF . '/', 'GET');

        return ($request['headers']['x-myobapi-version'] ?? null) === 'v2';
    });

    check('local mode sends no developer key', function() {
        // The desktop server has no notion of one, and sending an empty header is not the same as
        // sending none.
        $request = lastRequest('/' . MOCK_CF . '/', 'GET');

        return !array_key_exists('x-myobapi-key', $request['headers'] ?? []);
    });

    check('responses are asked for compressed', function() {
        $request = lastRequest('/' . MOCK_CF . '/', 'GET');

        return str_contains((string)($request['headers']['accept-encoding'] ?? ''), 'gzip');
    });

    check('a collection reports its count', function() {
        $page = Plugin::getInstance()->getApi()->getPage('GeneralLedger/TaxCode', ['action' => 'test.page']);

        return $page['Count'] === 2 ?: Json::encode($page);
    });

    check('paging parameters are sent as OData', function() {
        Plugin::getInstance()->getApi()->getPage('GeneralLedger/Account', ['action' => 'test.page'], 25, 50);
        $request = lastRequest('/GeneralLedger/Account', 'GET');

        return ($request['query']['$top'] ?? null) === '25'
            && ($request['query']['$skip'] ?? null) === '50'
            ?: Json::encode($request['query'] ?? []);
    });

    check('a filter reaches MYOB verbatim', function() {
        Plugin::getInstance()->getApi()->findOne('Contact/Customer', "Addresses/any(x: x/Email eq 'known@example.com')", ['action' => 'test.filter']);
        $request = lastRequest('/Contact/Customer', 'GET');

        return ($request['query']['$filter'] ?? null) === "Addresses/any(x: x/Email eq 'known@example.com')"
            ?: var_export($request['query']['$filter'] ?? null, true);
    });

    check('a POST asks for the body back', function() {
        // Without `returnBody`, MYOB answers 201 with an empty body and only a Location header, and
        // the UID costs a second round trip during which anything could happen.
        Plugin::getInstance()->getApi()->post('Contact/Customer', ['IsIndividual' => true, 'LastName' => 'Probe'], ['action' => 'test.post']);
        $request = lastRequest('/Contact/Customer', 'POST');

        return ($request['query']['returnBody'] ?? null) === 'true';
    });

    check('a POST body arrives as JSON', function() {
        $request = lastRequest('/Contact/Customer', 'POST');

        return ($request['body']['LastName'] ?? null) === 'Probe';
    });

    check('a 404 becomes a MyobApiException carrying the status', function() {
        try {
            Plugin::getInstance()->getApi()->get('Nowhere/At/All', ['action' => 'test.404']);
        } catch (MyobApiException $e) {
            return $e->statusCode === 404 ?: 'got ' . var_export($e->statusCode, true);
        }

        return 'no exception was thrown';
    });

    check('MYOB’s own error message is what surfaces', function() {
        mockControl(['fail' => [400]]);

        try {
            Plugin::getInstance()->getApi()->get('', ['action' => 'test.400']);
        } catch (MyobApiException $e) {
            mockControl(['fail' => []]);

            return str_contains($e->getMessage(), 'Injected failure')
                && str_contains($e->getMessage(), 'status 400')
                ?: 'got ' . $e->getMessage();
        }

        mockControl(['fail' => []]);

        return 'no exception was thrown';
    });

    check('a 429 is retried and then succeeds', function() {
        mockReset();
        mockControl(['fail' => [429, 429]]);

        $data = Plugin::getInstance()->getApi()->get('', ['action' => 'test.429']);
        mockControl(['fail' => []]);

        $attempts = count(array_filter(mockJournal(), static fn($e) => str_ends_with($e['path'], '/' . MOCK_CF . '/')));

        return ($data['CompanyFile']['Name'] ?? null) === 'Mock Traders Pty Ltd' && $attempts === 3
            ?: "succeeded after $attempts attempts";
    });

    check('a persistent 500 gives up rather than looping', function() {
        mockControl(['fail' => [500, 500, 500, 500, 500, 500]]);

        try {
            Plugin::getInstance()->getApi()->get('', ['action' => 'test.500']);
        } catch (MyobApiException $e) {
            mockControl(['fail' => []]);

            return $e->statusCode === 500;
        }

        mockControl(['fail' => []]);

        return 'no exception was thrown';
    });

    check('a failed request is logged as an error', function() {
        $entries = Plugin::getInstance()->getLog()->getEntries(['action' => 'test.500', 'level' => LogEntry::LEVEL_ERROR], 1);

        return $entries !== [] ?: 'nothing was logged';
    });

    check('the log records the endpoint without the company file GUID', function() {
        // A log full of 36-character GUIDs is unreadable, and the GUID is the same on every line.
        $entries = Plugin::getInstance()->getLog()->getEntries(['action' => 'test.page'], 5);
        $endpoints = array_map(static fn($e) => (string)$e->endpoint, $entries);

        foreach ($endpoints as $endpoint) {
            if (str_contains($endpoint, MOCK_CF)) {
                return "got $endpoint";
            }
        }

        return $endpoints !== [];
    });

    check('requests are throttled to the configured rate', function() {
        applySettings(['requestsPerSecond' => 2]);

        $api = Plugin::getInstance()->getApi();
        $started = microtime(true);

        for ($i = 0; $i < 3; $i++) {
            $api->get('GeneralLedger/TaxCode', ['action' => 'test.throttle']);
        }

        $elapsed = microtime(true) - $started;
        applySettings(['requestsPerSecond' => 8]);

        // Three requests at two per second cannot finish inside a second.
        return $elapsed >= 0.95 ?: sprintf('three requests took %.2fs', $elapsed);
    });

    check('an unconnected company file refuses to send anything', function() {
        $auth = Plugin::getInstance()->getAuth();
        $saved = $auth->getConnection()->companyFileId;

        $connection = $auth->getConnection();
        $connection->companyFileId = null;
        $connection->companyFileUri = null;
        $auth->saveConnection($connection);

        try {
            Plugin::getInstance()->getApi()->get('', ['action' => 'test.disconnected']);

            return 'a request went out with no company file';
        } catch (MyobApiException $e) {
            return str_contains($e->getMessage(), 'company file');
        } finally {
            $connection = $auth->getConnection();
            $connection->companyFileId = $saved;
            $connection->companyFileUri = rtrim(MOCK_BASE, '/') . '/' . MOCK_CF;
            $auth->saveConnection($connection);
        }
    });

    // -----------------------------------------------------------------------
    section('Connection verification');

    check('verify names the company file and the user', function() {
        $result = Plugin::getInstance()->getAuth()->verify();

        return $result['ok']
            && str_contains($result['message'], 'Mock Traders')
            && str_contains($result['message'], 'Administrator')
            ?: Json::encode($result);
    });

    check('verify stores what it learned', function() {
        $connection = Plugin::getInstance()->getAuth()->getConnection();

        return $connection->country === 'AU'
            && $connection->productVersion === '2024.5'
            && $connection->dateLastVerified !== null;
    });

    check('a 401 is explained as two possible credentials', function() {
        // MYOB answers 401 whichever credential is wrong, and merchants confuse the company file
        // login with their MYOB account constantly.
        mockControl(['fail' => [401]]);
        $result = Plugin::getInstance()->getAuth()->verify();
        mockControl(['fail' => []]);

        return !$result['ok'] && str_contains($result['message'], 'company file username')
            ?: Json::encode($result);
    });

    check('the company file list parses', function() {
        $files = Plugin::getInstance()->getAuth()->getCompanyFiles();

        return count($files) === 1
            && $files[0]['Id'] === MOCK_CF
            && $files[0]['Country'] === 'AU'
            ?: Json::encode($files);
    });

    check('a failure to list company files is not an exception', function() {
        // Newer API keys are not allowed to list them at all, so an empty list is a normal outcome.
        mockControl(['fail' => [403]]);
        $files = Plugin::getInstance()->getAuth()->getCompanyFiles();
        mockControl(['fail' => []]);

        return $files === [];
    });

    // -----------------------------------------------------------------------
    section('Reference data');

    check('accounts are keyed by their code', function() {
        $accounts = Plugin::getInstance()->getReference()->getAccounts(true);

        return isset($accounts['4-1000']) && $accounts['4-1000']['Name'] === 'Sales' ?: Json::encode(array_keys($accounts));
    });

    check('tax codes are keyed by their code', function() {
        $codes = Plugin::getInstance()->getReference()->getTaxCodes(true);

        return isset($codes['GST']) && $codes['GST']['Rate'] === 10.0 ?: Json::encode($codes);
    });

    check('an account resolves to a UID reference', function() {
        $ref = Plugin::getInstance()->getReference()->accountRef('4-1000');

        return ($ref['UID'] ?? null) === 'bbbbbbbb-0000-0000-0000-000000000001' ?: Json::encode($ref);
    });

    check('an unknown account resolves to null', fn() => Plugin::getInstance()->getReference()->accountRef('9-9999') === null);
    check('a blank account code resolves to null', fn() => Plugin::getInstance()->getReference()->accountRef('  ') === null);
    check('a tax code resolves to a UID reference', fn() => (Plugin::getInstance()->getReference()->taxCodeRef('GST')['UID'] ?? null) === 'aaaaaaaa-0000-0000-0000-000000000001');
    check('an unknown tax code resolves to null', fn() => Plugin::getInstance()->getReference()->taxCodeRef('NOPE') === null);

    check('the second lookup does not go back to MYOB', function() {
        mockReset();
        $reference = Plugin::getInstance()->getReference();
        $reference->getAccounts();
        $reference->getAccounts();
        $reference->accountRef('4-1000');

        $calls = count(array_filter(mockJournal(), static fn($e) => str_ends_with($e['path'], '/GeneralLedger/Account')));

        return $calls === 0 ?: "went to MYOB $calls times";
    });

    check('flushing makes the next lookup go back to MYOB', function() {
        mockReset();
        $reference = Plugin::getInstance()->getReference();
        $reference->flush();
        $reference->getAccounts();

        $calls = count(array_filter(mockJournal(), static fn($e) => str_ends_with($e['path'], '/GeneralLedger/Account')));

        return $calls === 1 ?: "went to MYOB $calls times";
    });

    check('a known SKU finds its inventory item', function() {
        $item = Plugin::getInstance()->getReference()->findItem('SKU-KNOWN');

        return ($item['UID'] ?? null) === 'cccccccc-0000-0000-0000-000000000001' ?: Json::encode($item);
    });

    check('an unknown SKU finds nothing', fn() => Plugin::getInstance()->getReference()->findItem('SKU-MISSING-' . $suffix) === null);

    check('a missing SKU is remembered as missing', function() {
        // Asking MYOB repeatedly about a SKU that will never be there is the expensive case.
        $sku = 'SKU-ALSO-MISSING-' . $GLOBALS['suffix'];
        Plugin::getInstance()->getReference()->findItem($sku);

        mockReset();
        Plugin::getInstance()->getReference()->findItem($sku);

        $calls = count(array_filter(mockJournal(), static fn($e) => str_ends_with($e['path'], '/Inventory/Item')));

        return $calls === 0 ?: "went to MYOB $calls times";
    });

    check('a quote in a SKU is escaped for OData', function() {
        Plugin::getInstance()->getReference()->flush();
        Plugin::getInstance()->getReference()->findItem("O'BRIEN-1");
        $request = lastRequest('/Inventory/Item', 'GET');

        return str_contains((string)($request['query']['$filter'] ?? ''), "O''BRIEN-1")
            ?: var_export($request['query']['$filter'] ?? null, true);
    });

    check('refreshing reports what it loaded', function() {
        $result = Plugin::getInstance()->getReference()->refreshAll();

        return $result['ok'] && $result['accounts'] === 3 && $result['taxCodes'] === 2 ?: Json::encode($result);
    });

    // -----------------------------------------------------------------------
    section('Fixtures');

    $known = makeProduct('SKU-KNOWN', 110.00);
    $unknown = makeProduct('SKU-MISSING-' . $suffix, 55.00);

    $knownVariant = $known->getVariants()->first();
    $unknownVariant = $unknown->getVariants()->first();

    /**
     * A GST-inclusive order: two lines, a line-level discount, a cart-level discount and shipping.
     *
     *   items         220.00
     *   line discount -11.00
     *   cart discount -22.00
     *   shipping       11.00
     *   ------------------------
     *   total         198.00
     */
    $inclusive = makeOrder([
        ['variant' => $knownVariant, 'qty' => 1],
        ['variant' => $unknownVariant, 'qty' => 2],
    ]);

    $inclusiveLines = reload($inclusive)->getLineItems();
    addAdjustment($inclusive, 'discount', -11.00, false, $inclusiveLines[0]->id);
    addAdjustment($inclusive, 'tax', 9.00, true, $inclusiveLines[0]->id);
    addAdjustment($inclusive, 'tax', 10.00, true, $inclusiveLines[1]->id);
    addAdjustment($inclusive, 'discount', -22.00, false);
    addAdjustment($inclusive, 'shipping', 11.00, false);
    addAdjustment($inclusive, 'tax', 1.00, true);
    $inclusive = reload($inclusive);

    check('the inclusive fixture totals 198.00', fn() => Money::equals($inclusive->getTotalPrice(), 198.00, 0) ?: 'got ' . $inclusive->getTotalPrice());

    /**
     * A GST-exclusive order: tax is added on top.
     *
     *   items         150.00
     *   shipping       10.00
     *   tax            16.00
     *   ------------------------
     *   total         176.00
     */
    $exclusive = makeOrder([
        ['variant' => $knownVariant, 'qty' => 1],
    ], 'known@example.com');

    $exclusiveLines = reload($exclusive)->getLineItems();
    addAdjustment($exclusive, 'tax', 11.00, false, $exclusiveLines[0]->id);
    addAdjustment($exclusive, 'shipping', 10.00, false);
    addAdjustment($exclusive, 'tax', 1.00, false);
    $exclusive = reload($exclusive);

    check('the exclusive fixture totals 132.00', fn() => Money::equals($exclusive->getTotalPrice(), 132.00, 0) ?: 'got ' . $exclusive->getTotalPrice());

    // No tax at all.
    $untaxed = makeOrder([['variant' => $unknownVariant, 'qty' => 1]]);
    $untaxed = reload($untaxed);

    check('the untaxed fixture totals 55.00', fn() => Money::equals($untaxed->getTotalPrice(), 55.00, 0) ?: 'got ' . $untaxed->getTotalPrice());

    // -----------------------------------------------------------------------
    section('Customers');

    check('a company name makes the card a company', function() use ($inclusive) {
        $order = clone $inclusive;
        $order->setBillingAddress([
            'fullName' => 'Dana Fixture',
            'organization' => 'Fixture Pty Ltd',
            'addressLine1' => '19 Mock Street',
            'locality' => 'Melbourne',
            'countryCode' => 'AU',
        ]);

        $payload = Plugin::getInstance()->getContacts()->buildPayload($order);

        return $payload['IsIndividual'] === false
            && $payload['CompanyName'] === 'Fixture Pty Ltd'
            && !isset($payload['LastName'])
            ?: Json::encode($payload);
    });

    check('no company name makes the card an individual', function() use ($inclusive) {
        $payload = Plugin::getInstance()->getContacts()->buildPayload($inclusive);

        return $payload['IsIndividual'] === true
            && $payload['LastName'] === 'Fixture'
            && $payload['FirstName'] === 'Dana'
            ?: Json::encode($payload);
    });

    check('an individual always gets a surname', function() use ($knownVariant) {
        // MYOB refuses an individual with an empty LastName, and Craft addresses frequently have
        // only a full name — or nothing at all.
        $order = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'nameless-' . $GLOBALS['suffix'] . '@example.com');
        $order->setBillingAddress(['addressLine1' => 'Somewhere', 'countryCode' => 'AU']);

        $payload = Plugin::getInstance()->getContacts()->buildPayload($order);

        return ($payload['LastName'] ?? '') !== '' ?: Json::encode($payload);
    });

    check('a one-word name becomes the surname', function() use ($knownVariant) {
        $order = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'oneword-' . $GLOBALS['suffix'] . '@example.com');
        $order->setBillingAddress(['fullName' => 'Prince', 'countryCode' => 'AU']);

        $payload = Plugin::getInstance()->getContacts()->buildPayload($order);

        return $payload['LastName'] === 'Prince' && $payload['FirstName'] === '' ?: Json::encode($payload);
    });

    check('the email goes on the card’s first address', function() use ($inclusive) {
        $payload = Plugin::getInstance()->getContacts()->buildPayload($inclusive);

        return ($payload['Addresses'][0]['Email'] ?? null) === 'my-fixture@example.com' ?: Json::encode($payload['Addresses'] ?? []);
    });

    check('address lines are joined, not lost', function() use ($knownVariant) {
        $order = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'lines-' . $GLOBALS['suffix'] . '@example.com');
        $order->setBillingAddress([
            'fullName' => 'Dana Fixture',
            'addressLine1' => 'Level 4',
            'addressLine2' => '19 Mock Street',
            'countryCode' => 'AU',
        ]);

        $street = Plugin::getInstance()->getContacts()->buildPayload($order)['Addresses'][0]['Street'] ?? '';

        return $street === "Level 4\n19 Mock Street" ?: var_export($street, true);
    });

    check('a long company name is cut to MYOB’s width', function() use ($knownVariant) {
        // 50 characters, and MYOB answers 400 rather than truncating.
        $order = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'long-' . $GLOBALS['suffix'] . '@example.com');
        $order->setBillingAddress(['organization' => str_repeat('A', 80), 'countryCode' => 'AU']);

        return mb_strlen(Plugin::getInstance()->getContacts()->buildPayload($order)['CompanyName']) === 50;
    });

    check('an existing MYOB card is found rather than duplicated', function() use ($exclusive) {
        Plugin::getInstance()->getContacts()->forgetAll();
        $ref = Plugin::getInstance()->getContacts()->resolveForOrder($exclusive);

        return ($ref['UID'] ?? null) === 'dddddddd-0000-0000-0000-00000000beef' ?: Json::encode($ref);
    });

    check('a found card is remembered so the next order does not search', function() use ($exclusive) {
        mockReset();
        Plugin::getInstance()->getContacts()->resolveForOrder($exclusive);

        $searches = count(array_filter(mockJournal(), static fn($e) => str_ends_with($e['path'], '/Contact/Customer') && $e['method'] === 'GET'));

        return $searches === 0 ?: "searched MYOB $searches times";
    });

    check('an unknown customer gets a card created', function() use ($inclusive) {
        Plugin::getInstance()->getContacts()->forgetAll();
        $ref = Plugin::getInstance()->getContacts()->resolveForOrder($inclusive);
        $request = lastRequest('/Contact/Customer', 'POST');

        return str_starts_with((string)($ref['UID'] ?? ''), 'dddddddd-')
            && ($request['body']['LastName'] ?? null) === 'Fixture'
            ?: Json::encode([$ref, $request['body'] ?? null]);
    });

    check('the mapping is keyed on the lower-cased email', function() use ($inclusive) {
        return Plugin::getInstance()->getContacts()->sourceKey($inclusive) === 'my-fixture@example.com';
    });

    check('single-card mode never creates a card per customer', function() use ($inclusive) {
        applySettings([
            'customerMode' => Settings::CUSTOMER_SINGLE_CARD,
            'defaultCustomerUid' => 'dddddddd-0000-0000-0000-0000000000ff',
        ]);

        $ref = Plugin::getInstance()->getContacts()->resolveForOrder($inclusive);

        applySettings([
            'customerMode' => Settings::CUSTOMER_PER_ORDER,
            'defaultCustomerUid' => '',
        ]);

        return $ref['UID'] === 'dddddddd-0000-0000-0000-0000000000ff';
    });

    check('with no default card and no email, the push is refused rather than guessed', function() use ($knownVariant) {
        applySettings(['customerMode' => Settings::CUSTOMER_SINGLE_CARD, 'defaultCustomerUid' => '', 'defaultCustomerName' => '']);

        try {
            Plugin::getInstance()->getContacts()->defaultCustomer();

            return 'a customer was invented';
        } catch (MyobApiException $e) {
            return str_contains($e->getMessage(), 'default MYOB customer');
        } finally {
            applySettings(['customerMode' => Settings::CUSTOMER_PER_ORDER]);
        }
    });

    // -----------------------------------------------------------------------
    section('Invoice payloads');

    $customerRef = ['UID' => 'dddddddd-0000-0000-0000-00000000beef'];
    $invoices = $plugin->getInvoices();

    check('an inclusive order is detected as inclusive', fn() => $invoices->isTaxInclusive($inclusive) === true);
    check('an exclusive order is detected as exclusive', fn() => $invoices->isTaxInclusive($exclusive) === false);
    check('an untaxed order defaults to inclusive', fn() => $invoices->isTaxInclusive($untaxed) === true);

    check('the setting overrides what the order says', function() use ($invoices, $exclusive) {
        applySettings(['taxInclusive' => true]);
        $forced = $invoices->isTaxInclusive($exclusive);
        applySettings(['taxInclusive' => null]);

        return $forced === true;
    });

    check('a service invoice posts to Sale/Invoice/Service', fn() => $invoices->endpoint() === 'Sale/Invoice/Service');
    check('an item invoice posts to Sale/Invoice/Item', fn() => $invoices->endpoint(Settings::LAYOUT_ITEM) === 'Sale/Invoice/Item');

    check('the payload never states a total', function() use ($invoices, $inclusive, $customerRef) {
        // MYOB computes Subtotal, TotalTax and TotalAmount from the lines. Sending disagreeing
        // values is how invoices end up subtly and permanently wrong.
        $payload = $invoices->buildPayload($inclusive, $customerRef);

        foreach (['Subtotal', 'TotalTax', 'TotalAmount', 'BalanceDueAmount'] as $field) {
            if (array_key_exists($field, $payload)) {
                return "the payload sent $field";
            }
        }

        return true;
    });

    check('an inclusive payload reconciles exactly against the order', function() use ($invoices, $inclusive, $customerRef) {
        $payload = $invoices->buildPayload($inclusive, $customerRef);
        $check = $invoices->reconcile($inclusive, $payload);

        return $check['diffMinor'] === 0 ?: sprintf(
            'computed %.2f, expected %.2f',
            $check['computed'],
            $check['expected'],
        );
    });

    check('an exclusive payload reconciles exactly against the order', function() use ($invoices, $exclusive, $customerRef) {
        $payload = $invoices->buildPayload($exclusive, $customerRef);
        $check = $invoices->reconcile($exclusive, $payload);

        return $check['diffMinor'] === 0 ?: sprintf(
            'computed %.2f, expected %.2f',
            $check['computed'],
            $check['expected'],
        );
    });

    check('an untaxed payload reconciles exactly against the order', function() use ($invoices, $untaxed, $customerRef) {
        $check = $invoices->reconcile($untaxed, $invoices->buildPayload($untaxed, $customerRef));

        return $check['diffMinor'] === 0 ?: sprintf('computed %.2f, expected %.2f', $check['computed'], $check['expected']);
    });

    check('a line-level discount is folded into its own line', function() use ($invoices, $inclusive, $customerRef) {
        // 110 less an 11 discount. Nothing separate appears for it.
        $payload = $invoices->buildPayload($inclusive, $customerRef);

        return Money::equals((float)$payload['Lines'][0]['Total'], 99.00, 0) ?: Json::encode($payload['Lines'][0]);
    });

    check('a cart-level discount becomes its own line', function() use ($invoices, $inclusive, $customerRef) {
        // It belongs to no line item, so it cannot be folded into one.
        $payload = $invoices->buildPayload($inclusive, $customerRef);
        $discountLines = array_filter($payload['Lines'], static fn($line) => (float)$line['Total'] < 0);

        return count($discountLines) === 1
            && Money::equals((float)reset($discountLines)['Total'], -22.00, 0)
            ?: Json::encode($payload['Lines']);
    });

    check('shipping becomes Freight, not a line', function() use ($invoices, $inclusive, $customerRef) {
        $payload = $invoices->buildPayload($inclusive, $customerRef);

        return Money::equals((float)($payload['Freight'] ?? 0), 11.00, 0)
            && isset($payload['FreightTaxCode']['UID'])
            ?: Json::encode(['freight' => $payload['Freight'] ?? null]);
    });

    check('tax on shipping rides with the freight, inclusive', function() use ($invoices, $inclusive) {
        // The 1.00 of included tax has no line item, which is what tax on shipping looks like here.
        return Money::equals($invoices->freightAmount($inclusive, true), 11.00, 0)
            ?: 'got ' . $invoices->freightAmount($inclusive, true);
    });

    check('tax on shipping is stripped from the freight, exclusive', function() use ($invoices, $exclusive) {
        // 10.00 of shipping plus 1.00 of additive tax; the ex-tax figure is 10.00.
        return Money::equals($invoices->freightAmount($exclusive, false), 10.00, 0)
            ?: 'got ' . $invoices->freightAmount($exclusive, false);
    });

    check('every line names a tax code', function() use ($invoices, $inclusive, $customerRef) {
        foreach ($invoices->buildPayload($inclusive, $customerRef)['Lines'] as $line) {
            if (!isset($line['TaxCode']['UID'])) {
                return 'a line had no tax code: ' . Json::encode($line);
            }
        }

        return true;
    });

    check('an untaxed line gets the zero-rated code, not the default', function() use ($invoices, $untaxed) {
        // Otherwise MYOB invents GST on something Commerce treated as exempt.
        $lineItem = $untaxed->getLineItems()[0];

        return $invoices->taxCodeFor($lineItem) === 'FRE' ?: 'got ' . $invoices->taxCodeFor($lineItem);
    });

    check('a taxed line gets the default code', function() use ($invoices, $inclusive) {
        return $invoices->taxCodeFor($inclusive->getLineItems()[0]) === 'GST';
    });

    check('a mapped tax category wins over both', function() use ($invoices, $inclusive) {
        $handle = $inclusive->getLineItems()[0]->getTaxCategory()?->handle;

        if ($handle === null) {
            return 'the fixture line has no tax category';
        }

        applySettings(['taxCodeMap' => [$handle => 'FRE']]);
        $code = $invoices->taxCodeFor($inclusive->getLineItems()[0]);
        applySettings(['taxCodeMap' => []]);

        return $code === 'FRE' ?: "got $code";
    });

    check('a service line carries an account and no item', function() use ($invoices, $inclusive, $customerRef) {
        $line = $invoices->buildPayload($inclusive, $customerRef)['Lines'][0];

        return isset($line['Account']['UID']) && !isset($line['Item']) ?: Json::encode($line);
    });

    check('a service line is typed Transaction', function() use ($invoices, $inclusive, $customerRef) {
        return $invoices->buildPayload($inclusive, $customerRef)['Lines'][0]['Type'] === 'Transaction';
    });

    check('a quantity over one is visible on a service line', function() use ($invoices, $inclusive, $customerRef) {
        // A service line throws the quantity away, so it has to survive in the description.
        $descriptions = array_map(static fn($line) => $line['Description'], $invoices->buildPayload($inclusive, $customerRef)['Lines']);

        foreach ($descriptions as $description) {
            if (str_starts_with($description, '2 × ')) {
                return true;
            }
        }

        return Json::encode($descriptions);
    });

    check('an item invoice links a known SKU to its inventory item', function() use ($invoices, $inclusive, $customerRef) {
        applySettings(['invoiceLayout' => Settings::LAYOUT_ITEM]);
        $payload = $invoices->buildPayload($inclusive, $customerRef);
        applySettings(['invoiceLayout' => Settings::LAYOUT_SERVICE]);

        $itemLines = array_filter($payload['Lines'], static fn($line) => isset($line['Item']['UID']));

        return count($itemLines) === 1
            && reset($itemLines)['Item']['UID'] === 'cccccccc-0000-0000-0000-000000000001'
            ?: Json::encode($payload['Lines']);
    });

    check('an item line’s unit price is derived from its discounted total', function() use ($invoices, $inclusive, $customerRef) {
        // 99.00 for one unit, not the 110.00 list price — otherwise the line does not add up.
        applySettings(['invoiceLayout' => Settings::LAYOUT_ITEM]);
        $payload = $invoices->buildPayload($inclusive, $customerRef);
        applySettings(['invoiceLayout' => Settings::LAYOUT_SERVICE]);

        $itemLine = null;

        foreach ($payload['Lines'] as $line) {
            if (isset($line['Item']['UID'])) {
                $itemLine = $line;
            }
        }

        return $itemLine !== null
            && Money::equals((float)$itemLine['UnitPrice'], 99.00, 0)
            && (float)$itemLine['ShipQuantity'] === 1.0
            ?: Json::encode($itemLine);
    });

    check('an unmatched SKU falls back to an account line rather than being dropped', function() use ($invoices, $inclusive, $customerRef) {
        // Dropping it would invoice the customer for less than they paid.
        applySettings(['invoiceLayout' => Settings::LAYOUT_ITEM, 'itemFallback' => Settings::ITEM_FALLBACK_SERVICE]);
        $payload = $invoices->buildPayload($inclusive, $customerRef);
        $check = $invoices->reconcile($inclusive, $payload);
        applySettings(['invoiceLayout' => Settings::LAYOUT_SERVICE]);

        $accountLines = array_filter($payload['Lines'], static fn($line) => isset($line['Account']['UID']) && !isset($line['Item']));

        return count($accountLines) >= 1 && $check['diffMinor'] === 0 ?: Json::encode($payload['Lines']);
    });

    check('an unmatched SKU can refuse the whole push instead', function() use ($invoices, $inclusive, $customerRef) {
        applySettings(['invoiceLayout' => Settings::LAYOUT_ITEM, 'itemFallback' => Settings::ITEM_FALLBACK_FAIL]);

        try {
            $invoices->buildPayload($inclusive, $customerRef);

            return 'the push was allowed';
        } catch (MyobApiException $e) {
            return str_contains($e->getMessage(), 'inventory item');
        } finally {
            applySettings(['invoiceLayout' => Settings::LAYOUT_SERVICE, 'itemFallback' => Settings::ITEM_FALLBACK_SERVICE]);
        }
    });

    check('a missing account is a clear error, not a 400 from MYOB', function() use ($invoices, $inclusive, $customerRef) {
        applySettings(['salesAccount' => '9-9999']);

        try {
            $invoices->buildPayload($inclusive, $customerRef);

            return 'the payload was built with no account';
        } catch (MyobApiException $e) {
            return str_contains($e->getMessage(), '9-9999');
        } finally {
            applySettings(['salesAccount' => '4-1000']);
        }
    });

    check('a missing tax code is a clear error too', function() use ($invoices, $inclusive, $customerRef) {
        applySettings(['defaultTaxCode' => 'NOPE']);

        try {
            $invoices->buildPayload($inclusive, $customerRef);

            return 'the payload was built with no tax code';
        } catch (MyobApiException $e) {
            return str_contains($e->getMessage(), 'NOPE');
        } finally {
            applySettings(['defaultTaxCode' => 'GST']);
        }
    });

    check('the order reference is carried where a human can find it', function() use ($invoices, $inclusive, $customerRef) {
        $payload = $invoices->buildPayload($inclusive, $customerRef);

        return str_contains((string)($payload['JournalMemo'] ?? ''), (string)$inclusive->reference)
            && ($payload['CustomerPurchaseOrderNumber'] ?? null) === $inclusive->reference
            ?: Json::encode($payload);
    });

    check('the ship-to address is a formatted block', function() use ($invoices, $inclusive, $customerRef) {
        $shipTo = $invoices->buildPayload($inclusive, $customerRef)['ShipToAddress'] ?? '';

        return str_contains($shipTo, '19 Mock Street') && str_contains($shipTo, 'Melbourne') ?: var_export($shipTo, true);
    });

    check('the invoice number is capped at MYOB’s 13 characters', function() use ($invoices, $inclusive) {
        applySettings(['numberPrefix' => 'INVOICE-']);
        $number = $invoices->invoiceNumber($inclusive);
        applySettings(['numberPrefix' => '']);

        return mb_strlen((string)$number) <= 13 ?: "got $number (" . mb_strlen((string)$number) . ')';
    });

    check('truncation keeps the end of the reference, not the start', function() use ($invoices, $inclusive) {
        // The digits that vary are at the end; chopping the tail would collide every order in a
        // batch.
        applySettings(['numberPrefix' => 'ABCDEFGH']);
        $number = $invoices->invoiceNumber($inclusive);
        applySettings(['numberPrefix' => '']);

        return str_ends_with((string)$inclusive->reference, substr((string)$number, 8)) ?: "got $number";
    });

    check('a prefix longer than the field is itself cut', function() use ($invoices, $inclusive) {
        applySettings(['numberPrefix' => 'ABCDEFGHIJKLMNOP']);
        $number = $invoices->invoiceNumber($inclusive);
        applySettings(['numberPrefix' => '']);

        return mb_strlen((string)$number) === 13 ?: "got $number";
    });

    check('a credit note cannot reuse the invoice number', function() use ($invoices, $inclusive) {
        return $invoices->invoiceNumber($inclusive, true) !== $invoices->invoiceNumber($inclusive, false);
    });

    check('letting MYOB assign the number sends no Number at all', function() use ($invoices, $inclusive, $customerRef) {
        applySettings(['numberSource' => 'myob']);
        $payload = $invoices->buildPayload($inclusive, $customerRef);
        applySettings(['numberSource' => 'reference']);

        return !array_key_exists('Number', $payload);
    });

    check('a zero-value line is left out', function() use ($invoices, $customerRef, $knownVariant) {
        // MYOB is happy to store it forever, and it is noise in the ledger.
        $order = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'zero-' . $GLOBALS['suffix'] . '@example.com');
        $lines = reload($order)->getLineItems();
        addAdjustment($order, 'discount', -110.00, false, $lines[0]->id);
        $order = reload($order);

        try {
            $invoices->buildPayload($order, $customerRef);

            return 'a zero order produced a payload';
        } catch (MyobApiException $e) {
            return str_contains($e->getMessage(), 'nothing to invoice');
        }
    });

    // -----------------------------------------------------------------------
    section('Reconciliation');

    check('a payload that would book the wrong amount is caught', function() use ($invoices, $inclusive, $customerRef) {
        $payload = $invoices->buildPayload($inclusive, $customerRef);
        $payload['Lines'][0]['Total'] = 1.00;
        $check = $invoices->reconcile($inclusive, $payload);

        return !$check['ok'] && $check['diffMinor'] === 9800 ?: Json::encode($check);
    });

    check('a one-cent drift is inside the default tolerance', function() use ($invoices, $inclusive, $customerRef) {
        $payload = $invoices->buildPayload($inclusive, $customerRef);
        $payload['Lines'][0]['Total'] = 99.01;

        return $invoices->reconcile($inclusive, $payload)['ok'];
    });

    check('a two-cent drift is not', function() use ($invoices, $inclusive, $customerRef) {
        $payload = $invoices->buildPayload($inclusive, $customerRef);
        $payload['Lines'][0]['Total'] = 99.02;

        return !$invoices->reconcile($inclusive, $payload)['ok'];
    });

    check('a rounding line closes the gap exactly', function() use ($invoices, $inclusive, $customerRef) {
        $payload = $invoices->buildPayload($inclusive, $customerRef);
        $payload['Lines'][0]['Total'] = 98.00;
        $check = $invoices->reconcile($inclusive, $payload);

        $rounded = $invoices->applyRounding($payload, $check['diffMinor']);

        return $invoices->reconcile($inclusive, $rounded)['diffMinor'] === 0
            ?: Json::encode($invoices->reconcile($inclusive, $rounded));
    });

    check('what MYOB actually booked is checked, not just what was sent', function() use ($invoices, $inclusive) {
        // The payload can reconcile perfectly and the invoice still be wrong, if a tax code is
        // mapped to a different rate than Commerce used.
        $verified = $invoices->verifyAgainstOrder($inclusive, ['TotalAmount' => 150.00]);

        return !$verified['ok'] && str_contains((string)$verified['message'], 'tax code') ?: Json::encode($verified);
    });

    check('a matching MYOB total passes', function() use ($invoices, $inclusive) {
        return $invoices->verifyAgainstOrder($inclusive, ['TotalAmount' => 198.00])['ok'];
    });

    check('a response with no total is not treated as a mismatch', function() use ($invoices, $inclusive) {
        return $invoices->verifyAgainstOrder($inclusive, null)['ok'];
    });

    // -----------------------------------------------------------------------
    section('Payments');

    $paid = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'paid-' . $suffix . '@example.com');
    addTransaction($paid, TransactionRecord::TYPE_PURCHASE, 110.00);
    addTransaction($paid, TransactionRecord::TYPE_AUTHORIZE, 110.00);
    addTransaction($paid, TransactionRecord::TYPE_PURCHASE, 25.00, TransactionRecord::STATUS_FAILED);
    $paid = reload($paid);

    $payments = $plugin->getPayments();

    check('only successful purchases and captures count as payments', function() use ($payments, $paid) {
        // An authorization has not moved any money; booking it would overstate the bank balance.
        $payable = $payments->getPayableTransactions($paid);

        return count($payable) === 1 && Money::equals((float)$payable[0]->amount, 110.00, 0)
            ?: count($payable) . ' transactions were payable';
    });

    check('a payment applies to the invoice, not to thin air', function() use ($payments, $paid, $customerRef) {
        $transaction = $payments->getPayableTransactions($paid)[0];
        $payload = $payments->buildPayload($paid, $transaction, $customerRef, 'invoice-uid', '-42');

        return ($payload['Invoices'][0]['UID'] ?? null) === 'invoice-uid'
            && ($payload['Invoices'][0]['Type'] ?? null) === 'Invoice'
            && Money::equals((float)$payload['Invoices'][0]['AmountApplied'], 110.00, 0)
            ?: Json::encode($payload);
    });

    check('the invoice’s RowVersion rides along when known', function() use ($payments, $paid, $customerRef) {
        $transaction = $payments->getPayableTransactions($paid)[0];
        $payload = $payments->buildPayload($paid, $transaction, $customerRef, 'invoice-uid', '-42');

        return ($payload['Invoices'][0]['RowVersion'] ?? null) === '-42';
    });

    check('undeposited funds needs no bank account', function() use ($payments, $paid, $customerRef) {
        $transaction = $payments->getPayableTransactions($paid)[0];
        $payload = $payments->buildPayload($paid, $transaction, $customerRef, 'invoice-uid');

        return $payload['DepositTo'] === 'UndepositedFunds' && !isset($payload['Account']);
    });

    check('depositing to an account names one', function() use ($payments, $paid, $customerRef) {
        applySettings(['depositTo' => Settings::DEPOSIT_ACCOUNT]);
        $transaction = $payments->getPayableTransactions($paid)[0];
        $payload = $payments->buildPayload($paid, $transaction, $customerRef, 'invoice-uid');
        applySettings(['depositTo' => Settings::DEPOSIT_UNDEPOSITED]);

        return ($payload['Account']['UID'] ?? null) === 'bbbbbbbb-0000-0000-0000-000000000003' ?: Json::encode($payload);
    });

    check('a missing bank account is a clear error', function() use ($payments, $paid, $customerRef) {
        applySettings(['depositTo' => Settings::DEPOSIT_ACCOUNT, 'paymentAccount' => '9-9999']);

        try {
            $transaction = $payments->getPayableTransactions($paid)[0];
            $payments->buildPayload($paid, $transaction, $customerRef, 'invoice-uid');

            return 'a payment was built with no account';
        } catch (MyobApiException $e) {
            return str_contains($e->getMessage(), '9-9999');
        } finally {
            applySettings(['depositTo' => Settings::DEPOSIT_UNDEPOSITED, 'paymentAccount' => '1-1100']);
        }
    });

    check('an unmapped gateway falls back to the default method', function() use ($payments, $paid) {
        // MYOB only accepts payment methods from its own list, so sending a gateway handle is a 400.
        $transaction = $payments->getPayableTransactions($paid)[0];
        $method = $payments->paymentMethod($transaction);

        return in_array($method, Settings::PAYMENT_METHODS, true) ?: "got $method";
    });

    check('a nonsense default falls back to “Other” rather than a 400', function() use ($payments, $paid) {
        applySettings(['defaultPaymentMethod' => 'Bitcoin']);
        $transaction = $payments->getPayableTransactions($paid)[0];
        $method = $payments->paymentMethod($transaction);
        applySettings(['defaultPaymentMethod' => 'Other']);

        return $method === 'Other' ?: "got $method";
    });

    check('the payment memo names the order', function() use ($payments, $paid, $customerRef) {
        $transaction = $payments->getPayableTransactions($paid)[0];
        $payload = $payments->buildPayload($paid, $transaction, $customerRef, 'invoice-uid');

        return str_contains((string)$payload['Memo'], (string)$paid->reference) ?: Json::encode($payload);
    });

    // -----------------------------------------------------------------------
    section('Refunds');

    $refunded = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'refunded-' . $suffix . '@example.com');
    addTransaction($refunded, TransactionRecord::TYPE_PURCHASE, 110.00);
    addTransaction($refunded, TransactionRecord::TYPE_REFUND, 110.00);
    $refunded = reload($refunded);

    $partial = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'partial-' . $suffix . '@example.com');
    addTransaction($partial, TransactionRecord::TYPE_PURCHASE, 110.00);
    addTransaction($partial, TransactionRecord::TYPE_REFUND, 30.00);
    $partial = reload($partial);

    $refunds = $plugin->getRefunds();

    check('refund transactions are picked out', function() use ($payments, $refunded) {
        $found = $payments->getRefundTransactions($refunded);

        return count($found) === 1 && Money::equals((float)$found[0]->amount, 110.00, 0);
    });

    check('a full refund mirrors the invoice, reversed', function() use ($refunds, $payments, $refunded, $customerRef) {
        $transaction = $payments->getRefundTransactions($refunded)[0];
        $payload = $refunds->buildCreditNote($refunded, $transaction, $customerRef);

        $total = 0.0;

        foreach ($payload['Lines'] as $line) {
            $total += (float)$line['Total'];
        }

        return Money::equals($total, -110.00, 0) ?: "credit lines totalled $total";
    });

    check('a partial refund is one line for the amount refunded', function() use ($refunds, $payments, $partial, $customerRef) {
        // Commerce records an amount, not a basket, so pretending to know which items came back
        // would be worse than saying so plainly.
        $transaction = $payments->getRefundTransactions($partial)[0];
        $payload = $refunds->buildCreditNote($partial, $transaction, $customerRef);

        return count($payload['Lines']) === 1
            && Money::equals((float)$payload['Lines'][0]['Total'], -30.00, 0)
            && str_contains($payload['Lines'][0]['Description'], 'Partial refund')
            ?: Json::encode($payload['Lines']);
    });

    check('a credit note gets its own number', function() use ($refunds, $payments, $refunded, $customerRef, $invoices) {
        $transaction = $payments->getRefundTransactions($refunded)[0];
        $payload = $refunds->buildCreditNote($refunded, $transaction, $customerRef);

        return ($payload['Number'] ?? null) !== $invoices->invoiceNumber($refunded) ?: 'the credit note reused the invoice number';
    });

    check('every refund on an order gets its own credit note number', function() use ($refunds, $payments, $customerRef, $invoices, $knownVariant, $suffix) {
        // MYOB numbers must be unique; three partial refunds once all went out as the same `CR…`.
        $order = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'refunds3-' . $suffix . '@example.com');
        addTransaction($order, TransactionRecord::TYPE_PURCHASE, 110.00);
        addTransaction($order, TransactionRecord::TYPE_REFUND, 30.00);
        addTransaction($order, TransactionRecord::TYPE_REFUND, 20.00);
        addTransaction($order, TransactionRecord::TYPE_REFUND, 10.00);
        $order = reload($order);

        $numbers = [];

        foreach ($payments->getRefundTransactions($order) as $transaction) {
            $numbers[] = (string)($refunds->buildCreditNote($order, $transaction, $customerRef)['Number'] ?? '');
        }

        $first = $invoices->invoiceNumber($order, true);

        return count($numbers) === 3
            && count(array_unique($numbers)) === 3
            && in_array($first, $numbers, true)
            && max(array_map('mb_strlen', $numbers)) <= 13
            ?: Json::encode($numbers);
    });

    check('a credit refund names an account, a credit note and a customer', function() use ($refunds, $payments, $refunded, $customerRef) {
        $transaction = $payments->getRefundTransactions($refunded)[0];
        $payload = $refunds->buildCreditRefund($refunded, $transaction, $customerRef, 'creditnote-uid');

        return ($payload['Invoice']['UID'] ?? null) === 'creditnote-uid'
            && ($payload['Account']['UID'] ?? null) === 'bbbbbbbb-0000-0000-0000-000000000003'
            && Money::equals((float)$payload['Amount'], 110.00, 0)
            ?: Json::encode($payload);
    });

    check('leaving the credit on account is the default', fn() => !$refunds->shouldRefundToBank());

    check('the bank refund is opt-in', function() use ($refunds) {
        applySettings(['refundMode' => Settings::REFUND_CREDIT_NOTE_AND_REFUND]);
        $on = $refunds->shouldRefundToBank();
        applySettings(['refundMode' => Settings::REFUND_CREDIT_NOTE]);

        return $on;
    });

    // -----------------------------------------------------------------------
    section('The sync ledger');

    $sync = $plugin->getSync();

    check('a claim creates a pending row', function() use ($sync, $untaxed) {
        $document = $sync->claim($untaxed, SyncDocument::TYPE_INVOICE, SyncDocument::SOURCE_ORDER);

        return $document->id !== null
            && $document->status === SyncDocument::STATUS_PENDING
            && $document->attempts === 1
            ?: Json::encode($document->toArray());
    });

    check('claiming twice does not create a second row', function() use ($sync, $untaxed) {
        // The unique index on (orderId, docType, sourceKey) is the whole idempotency guarantee.
        $first = $sync->claim($untaxed, SyncDocument::TYPE_INVOICE, SyncDocument::SOURCE_ORDER);
        $second = $sync->claim($untaxed, SyncDocument::TYPE_INVOICE, SyncDocument::SOURCE_ORDER);

        return $first->id === $second->id && $second->attempts > $first->attempts - 1
            ?: "ids {$first->id} and {$second->id}";
    });

    check('a second row for the same document is rejected by the database', function() use ($untaxed) {
        // Belt and braces: even if the service logic were wrong, MySQL says no.
        try {
            Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTS, [
                'orderId' => $untaxed->id,
                'docType' => SyncDocument::TYPE_INVOICE,
                'sourceKey' => SyncDocument::SOURCE_ORDER,
                'status' => SyncDocument::STATUS_PENDING,
                'attempts' => 1,
                'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'uid' => craft\helpers\StringHelper::UUID(),
            ])->execute();

            return 'a duplicate row was accepted';
        } catch (yii\db\IntegrityException) {
            return true;
        }
    });

    check('an order can hold several payments without colliding', function() use ($sync, $untaxed) {
        // The key is the Commerce transaction hash, so two instalments are two documents.
        $a = $sync->claim($untaxed, SyncDocument::TYPE_PAYMENT, 'hash-a');
        $b = $sync->claim($untaxed, SyncDocument::TYPE_PAYMENT, 'hash-b');

        return $a->id !== $b->id;
    });

    check('a transaction key prefers the hash', function() use ($sync, $paid, $payments) {
        $transaction = $payments->getPayableTransactions($paid)[0];

        return $sync->transactionKey($transaction) === $transaction->hash;
    });

    check('a transaction with no hash still gets a stable key', function() use ($sync, $paid, $payments) {
        // Never the amount, which repeats.
        $transaction = clone $payments->getPayableTransactions($paid)[0];
        $transaction->hash = null;

        return $sync->transactionKey($transaction) === 'txn:' . $transaction->id;
    });

    check('the ledger counts by status', function() use ($sync) {
        $counts = $sync->getCounts();

        return array_key_exists(SyncDocument::STATUS_SYNCED, $counts)
            && array_key_exists(SyncDocument::STATUS_FAILED, $counts);
    });

    check('unlinking removes the row and nothing else', function() use ($sync, $untaxed) {
        $document = $sync->claim($untaxed, SyncDocument::TYPE_PAYMENT, 'hash-a');
        $sync->unlink($document);

        return $sync->getDocument($untaxed->id, SyncDocument::TYPE_PAYMENT, 'hash-a') === null;
    });

    check('a completed order with no invoice shows up as unsynced', function() use ($sync, $paid) {
        return in_array($paid->id, $sync->findUnsyncedOrderIds(500), true) ?: 'order ' . $paid->id . ' was not listed';
    });

    // -----------------------------------------------------------------------
    section('Deciding what to push');

    check('a cart is never invoiced', function() use ($sync, $knownVariant) {
        $cart = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'cart-' . $GLOBALS['suffix'] . '@example.com', false);

        return !$sync->shouldSync(reload($cart));
    });

    check('with no status filter, every completed order is invoiced', function() use ($sync, $paid) {
        applySettings(['invoiceStatusHandles' => []]);

        return $sync->shouldSync($paid);
    });

    check('a status filter excludes orders that are not in it', function() use ($sync, $paid) {
        applySettings(['invoiceStatusHandles' => ['a-status-that-does-not-exist']]);
        $should = $sync->shouldSync($paid);
        applySettings(['invoiceStatusHandles' => []]);

        return !$should;
    });

    check('the master switch stops everything', function() use ($sync, $paid) {
        applySettings(['syncEnabled' => false]);
        $should = $sync->shouldSync($paid);
        applySettings(['syncEnabled' => true]);

        return !$should;
    });

    // -----------------------------------------------------------------------
    section('Pushing, end to end');

    $journey = makeOrder([['variant' => $knownVariant, 'qty' => 2]], 'journey-' . $suffix . '@example.com');
    $journeyLines = reload($journey)->getLineItems();
    addAdjustment($journey, 'tax', 20.00, true, $journeyLines[0]->id);
    addTransaction($journey, TransactionRecord::TYPE_PURCHASE, 220.00);
    $journey = reload($journey);

    check('the journey fixture totals 220.00', fn() => Money::equals($journey->getTotalPrice(), 220.00, 0) ?: 'got ' . $journey->getTotalPrice());

    $pushResult = null;

    check('an order pushes: card, invoice, payment', function() use ($sync, $journey, &$pushResult) {
        mockReset();
        $pushResult = $sync->pushOrder($journey);

        return $pushResult['ok']
            && $pushResult['invoice']?->isSynced()
            && count($pushResult['payments']) === 1
            ?: Json::encode(['ok' => $pushResult['ok'], 'messages' => $pushResult['messages']]);
    });

    check('the invoice came back with a UID and a number', function() use (&$pushResult) {
        $invoice = $pushResult['invoice'];

        return $invoice?->myobUid !== null && $invoice?->myobNumber !== null ?: Json::encode($invoice?->toArray());
    });

    check('MYOB booked exactly what the customer paid', function() {
        // The mock totals the invoice itself, so this is answered by something other than the code
        // that built the payload.
        $request = null;

        foreach (array_reverse(mockJournal()) as $entry) {
            if ($entry['method'] === 'POST' && str_ends_with($entry['path'], '/Sale/Invoice/Service')) {
                $request = $entry;
                break;
            }
        }

        if ($request === null) {
            return 'no invoice was posted';
        }

        $total = 0.0;

        foreach ($request['body']['Lines'] as $line) {
            $total += (float)$line['Total'];
        }

        $total += (float)($request['body']['Freight'] ?? 0);

        return Money::equals($total, 220.00, 0) ?: "MYOB was sent $total";
    });

    check('the payment applies to the invoice that was just created', function() use (&$pushResult) {
        $request = lastRequest('/Sale/CustomerPayment', 'POST');

        return ($request['body']['Invoices'][0]['UID'] ?? null) === $pushResult['invoice']->myobUid
            ?: Json::encode($request['body'] ?? null);
    });

    check('the payment carries the invoice’s RowVersion from the create response', function() {
        $request = lastRequest('/Sale/CustomerPayment', 'POST');

        return ($request['body']['Invoices'][0]['RowVersion'] ?? null) !== null;
    });

    check('pushing again does not create a second invoice', function() use ($sync, $journey) {
        mockReset();
        $result = $sync->pushOrder($journey);

        $posts = count(array_filter(mockJournal(), static fn($e) => $e['method'] === 'POST' && str_ends_with($e['path'], '/Sale/Invoice/Service')));

        return $posts === 0 && str_contains(implode(' ', $result['messages']), 'Already invoiced')
            ?: "posted $posts invoices; " . Json::encode($result['messages']);
    });

    check('pushing again does not create a second payment', function() use ($sync, $journey) {
        mockReset();
        $sync->pushOrder($journey);

        $posts = count(array_filter(mockJournal(), static fn($e) => $e['method'] === 'POST' && str_ends_with($e['path'], '/Sale/CustomerPayment')));

        return $posts === 0 ?: "posted $posts payments";
    });

    check('a refund raised later becomes a credit note', function() use ($sync, $journey) {
        addTransaction($journey, TransactionRecord::TYPE_REFUND, 220.00);
        $fresh = reload($journey);

        mockReset();
        $result = $sync->pushOrder($fresh);

        return count($result['refunds']) >= 1
            && $result['refunds'][0]->docType === SyncDocument::TYPE_CREDIT_NOTE
            && $result['refunds'][0]->isSynced()
            ?: Json::encode(array_map(static fn($d) => $d->toArray(), $result['refunds']));
    });

    check('the credit note is negative', function() {
        $request = lastRequest('/Sale/Invoice/Service', 'POST');
        $total = 0.0;

        foreach ($request['body']['Lines'] ?? [] as $line) {
            $total += (float)$line['Total'];
        }

        return $total < 0 ?: "credit note totalled $total";
    });

    check('the same refund is not credited twice', function() use ($sync, $journey) {
        mockReset();
        $sync->pushOrder(reload($journey));

        $posts = count(array_filter(mockJournal(), static fn($e) => $e['method'] === 'POST' && str_ends_with($e['path'], '/Sale/Invoice/Service')));

        return $posts === 0 ?: "posted $posts credit notes";
    });

    check('a refused push leaves the reason on the ledger, not just in the log', function() use ($sync, $knownVariant) {
        $order = makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'refused-' . $GLOBALS['suffix'] . '@example.com');
        $order = reload($order);

        applySettings(['salesAccount' => '9-9999']);
        $result = $sync->pushOrder($order);
        applySettings(['salesAccount' => '4-1000']);

        $document = $sync->getInvoiceForOrder($order->id);

        return !$result['ok']
            && $document !== null
            && $document->status === SyncDocument::STATUS_FAILED
            && str_contains((string)$document->lastError, '9-9999')
            ?: Json::encode($document?->toArray());
    });

    check('a MYOB outage leaves the document retryable, not failed', function() use ($sync, $knownVariant) {
        // The queue's own retry should pick this up without a human deciding anything.
        $order = reload(makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'outage-' . $GLOBALS['suffix'] . '@example.com'));

        mockControl(['fail' => [503, 503, 503, 503, 503, 503, 503, 503]]);
        $sync->pushOrder($order);
        mockControl(['fail' => []]);

        $document = $sync->getInvoiceForOrder($order->id);

        return $document !== null && $document->status === SyncDocument::STATUS_PENDING
            ?: Json::encode($document?->toArray());
    });

    check('an interrupted push is recovered rather than duplicated', function() use ($sync, $knownVariant) {
        // A previous attempt reached MYOB and lost its answer on the way home. The claimed row is
        // the signal to go and ask MYOB whether the invoice is there before POSTing it again.
        $order = reload(makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'recover-' . $GLOBALS['suffix'] . '@example.com'));
        $number = Plugin::getInstance()->getInvoices()->invoiceNumber($order);

        // First attempt: MYOB "fails", so the row is claimed with attempts recorded.
        mockControl(['fail' => [500, 500, 500, 500, 500, 500, 500, 500]]);
        $sync->pushOrder($order);
        mockControl(['fail' => [], 'recover' => $number, 'recoverTotal' => 110.00]);

        mockReset();
        mockControl(['fail' => [], 'recover' => $number, 'recoverTotal' => 110.00]);
        $result = $sync->pushOrder($order);
        mockControl(['fail' => [], 'recover' => null]);

        $posts = count(array_filter(mockJournal(), static fn($e) => $e['method'] === 'POST' && str_ends_with($e['path'], '/Sale/Invoice/Service')));

        return $result['invoice']?->myobUid === 'eeeeeeee-0000-0000-0000-00000000cafe'
            && $posts === 0
            ?: "posted $posts invoices; uid " . var_export($result['invoice']?->myobUid, true);
    });

    check('a total mismatch refuses the push by default', function() use ($sync, $knownVariant) {
        $order = reload(makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'mismatch-' . $GLOBALS['suffix'] . '@example.com'));

        // Commerce counts this in the order total; nothing can attribute it to a line. This is
        // the data problem reconciliation exists to catch, and papering over it would book a
        // number nobody could explain.
        addOrphanedAdjustment($order, 'discount', -5.00);
        $order = reload($order);

        mockReset();
        $result = $sync->pushOrder($order);

        $posts = count(array_filter(mockJournal(), static fn($e) => $e['method'] === 'POST' && str_ends_with($e['path'], '/Sale/Invoice/Service')));

        return !$result['ok'] && $posts === 0 ?: "posted $posts invoices; " . Json::encode($result['messages']);
    });

    check('a push waits its turn while another holds the order', function() use ($sync, $knownVariant) {
        // Completing an order queues more than one job. Two workers that both find the same
        // `pending` row would both POST; the per-order lock is what stops the second.
        $order = reload(makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'locked-' . $GLOBALS['suffix'] . '@example.com'));
        $mutex = Craft::$app->getMutex();
        $lock = justinholtweb\my\services\Sync::PUSH_LOCK_PREFIX . $order->id;

        if (!$mutex->acquire($lock)) {
            return 'could not take the lock for the test';
        }

        mockReset();

        try {
            $blocked = $sync->pushOrder($order);
        } finally {
            $mutex->release($lock);
        }

        $requests = count(mockJournal());
        $row = $sync->getInvoiceForOrder($order->id);

        // And once the holder lets go, the same order goes through — the lock is not leaked.
        $after = $sync->pushOrder($order);

        return !$blocked['ok']
            && $requests === 0
            && $row === null
            && str_contains(implode(' ', $blocked['messages']), 'already running')
            && $after['ok']
            ?: "blocked ok=" . var_export($blocked['ok'], true) . ", $requests requests, after ok=" . var_export($after['ok'], true) . ' ' . Json::encode($after['messages']);
    });

    check('the order screens refuse a user who cannot view the order', function() use ($plugin, $journey) {
        // My's permissions say what a user may do with MYOB, not which orders they may read; the
        // push, preview and detail screens all show the customer's details.
        $controller = new justinholtweb\my\controllers\DocumentsController('documents', $plugin);
        $method = new ReflectionMethod($controller, 'canAccessOrder');
        $method->setAccessible(true);

        $nobody = new craft\elements\User(['username' => 'my-nobody-' . $GLOBALS['suffix']]);
        $admin = new craft\elements\User(['username' => 'my-admin-' . $GLOBALS['suffix'], 'admin' => true]);

        return !$method->invoke($controller, $journey, $nobody)
            && !$method->invoke($controller, $journey, null)
            && $method->invoke($controller, $journey, $admin)
            ?: 'authorisation did not follow Order::canView()';
    });

    check('sync/retry runs rather than fataling', function() use ($plugin) {
        // It once called `$this->run($orderIds)` — yii\base\Controller::run(string $route) — and
        // died with a TypeError the moment anything had failed.
        // Its progress lines are swallowed: a `✗ order` line in the suite's output reads as a failed check.
        $controller = new class('sync', $plugin) extends justinholtweb\my\console\controllers\SyncController {
            public function stdout($string)
            {
                return strlen($string);
            }

            public function stderr($string)
            {
                return strlen($string);
            }
        };
        $controller->limit = 1;
        $controller->queue = false;

        $code = $controller->actionRetry();

        return is_int($code) ?: 'returned ' . var_export($code, true);
    });

    check('a preview resolves a new customer without creating a card', function() use ($plugin, $knownVariant) {
        // Preview and --dryRun must not write anything to MYOB.
        $email = 'preview-new-' . $GLOBALS['suffix'] . '@example.com';
        $order = reload(makeOrder([['variant' => $knownVariant, 'qty' => 1]], $email));

        mockReset();
        $ref = $plugin->getContacts()->resolveForOrder($order, true);
        $payload = $plugin->getInvoices()->buildPayload($order, $ref);

        $writes = array_filter(mockJournal(), static fn($e) => $e['method'] !== 'GET');
        $remembered = (new craft\db\Query())->from([Table::CONTACTS])->where(['sourceKey' => $email])->exists();

        return $ref['UID'] === justinholtweb\my\services\Contacts::PLACEHOLDER_UID
            && ($payload['Customer']['UID'] ?? null) === $ref['UID']
            && $writes === []
            && !$remembered
            ?: count($writes) . ' writes; ' . Json::encode($ref) . ($remembered ? '; remembered' : '');
    });

    check('a forced re-push links the invoice MYOB already has', function() use ($sync, $knownVariant) {
        // "Push again" on an invoiced order reads the stored UID back rather than POSTing a twin.
        $order = reload(makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'forced-' . $GLOBALS['suffix'] . '@example.com'));
        $first = $sync->pushOrder($order);
        $uid = $first['invoice']?->myobUid;

        mockReset();
        $again = $sync->pushOrder($order, true);

        $posts = count(array_filter(mockJournal(), static fn($e) => $e['method'] === 'POST' && str_ends_with($e['path'], '/Sale/Invoice/Service')));

        return $first['ok'] && $uid !== null && $again['invoice']?->myobUid === $uid && $posts === 0
            ?: "posted $posts invoices; uid " . var_export($again['invoice']?->myobUid, true) . ' vs ' . var_export($uid, true);
    });

    check('a recovery lookup MYOB cannot answer sends nothing', function() use ($sync, $knownVariant) {
        // The claimed row says an invoice may already be in MYOB. If the lookup times out or 5xxs,
        // "not found" is not what MYOB said — POSTing anyway is the duplicate recovery prevents.
        $order = reload(makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'noanswer-' . $GLOBALS['suffix'] . '@example.com'));

        // Push once so the customer card is known, then rewind the invoice to a claim whose
        // response was lost: pending, attempted once, no UID.
        $sync->pushOrder($order);
        $document = $sync->getInvoiceForOrder($order->id);

        if ($document === null) {
            return 'the first push recorded no invoice';
        }

        $sync->record($document, ['status' => SyncDocument::STATUS_PENDING, 'myobUid' => null, 'myobNumber' => null, 'attempts' => 1]);

        mockReset();
        mockControl(['failPath' => '/Sale/Invoice/Service', 'fail' => array_fill(0, 16, 503)]);
        $result = $sync->pushOrder($order);
        mockControl(['failPath' => null, 'fail' => []]);

        $journal = mockJournal();
        $lookups = count(array_filter($journal, static fn($e) => $e['method'] === 'GET' && str_ends_with($e['path'], '/Sale/Invoice/Service')));
        $posts = count(array_filter($journal, static fn($e) => $e['method'] === 'POST' && str_ends_with($e['path'], '/Sale/Invoice/Service')));
        $document = $sync->getInvoiceForOrder($order->id);

        return !$result['ok'] && $lookups > 0 && $posts === 0 && $document?->status === SyncDocument::STATUS_PENDING
            ?: "$lookups lookups, $posts posts; status " . var_export($document?->status, true);
    });

    check('with MYOB numbering an interrupted push is recovered by the order reference', function() use ($sync, $knownVariant) {
        // No `Number` to filter on, so `CustomerPurchaseOrderNumber` is the handle.
        applySettings(['numberSource' => 'myob']);

        try {
            $order = reload(makeOrder([['variant' => $knownVariant, 'qty' => 1]], 'myobnum-' . $GLOBALS['suffix'] . '@example.com'));
            $reference = trim((string)$order->reference);

            if ($reference === '') {
                return 'the fixture order has no reference';
            }

            mockControl(['fail' => [500, 500, 500, 500, 500, 500, 500, 500]]);
            $sync->pushOrder($order);

            mockReset();
            mockControl(['fail' => [], 'recover' => $reference, 'recoverTotal' => 110.00]);
            $result = $sync->pushOrder($order);
            mockControl(['fail' => [], 'recover' => null]);

            $posts = count(array_filter(mockJournal(), static fn($e) => $e['method'] === 'POST' && str_ends_with($e['path'], '/Sale/Invoice/Service')));

            return $result['invoice']?->myobUid === 'eeeeeeee-0000-0000-0000-00000000cafe' && $posts === 0
                ?: "posted $posts invoices; " . Json::encode($result['messages']);
        } finally {
            applySettings(['numberSource' => 'reference']);
        }
    });

    check('a forced refresh after a 401 defers to a token another worker already replaced', function() {
        // A 401 must be able to force a refresh before expiry — but if the stored token is no
        // longer the one that was rejected, someone else refreshed, and refreshing again would
        // rotate the refresh token out from under them. Checked without the network: this path
        // must return before any token request.
        $auth = Plugin::getInstance()->getAuth();
        $saved = (new craft\db\Query())->from([Table::CONNECTION])->one();

        applySettings(['mode' => Settings::MODE_CLOUD]);

        try {
            $connection = $auth->getConnection();
            $connection->mode = Settings::MODE_CLOUD;
            $connection->accessToken = 'replaced-token';
            $connection->refreshToken = 'refresh-token';
            $connection->expiryDate = (new DateTime())->modify('+15 minutes');
            $auth->saveConnection($connection);

            $after = $auth->refresh(true, 'rejected-token');

            return $after->accessToken === 'replaced-token' ?: 'got ' . var_export($after->accessToken, true);
        } finally {
            Craft::$app->getDb()->createCommand()->update(Table::CONNECTION, [
                'mode' => $saved['mode'],
                'accessToken' => $saved['accessToken'],
                'refreshToken' => $saved['refreshToken'],
                'expiryDate' => $saved['expiryDate'],
            ], ['id' => $saved['id']])->execute();
            applySettings(['mode' => Settings::MODE_LOCAL]);
            (new ReflectionProperty($auth, '_connection'))->setValue($auth, null);
        }
    });

    // -----------------------------------------------------------------------
    section('Twig');

    $variable = new justinholtweb\my\twig\MyVariable();

    check('an invoiced order reports its invoice', fn() => $variable->isInvoiced($journey));
    check('an uninvoiced order reports none', fn() => !$variable->isInvoiced($paid));
    check('the invoice is reachable by ID as well as by element', fn() => $variable->invoice($journey->id)?->myobUid !== null);
    check('documents lists everything for an order', fn() => count($variable->documents($journey)) >= 2);
    check('a null order is answered, not fataled', fn() => $variable->invoice(null) === null && $variable->documents(null) === []);
    check('the connection is reported', fn() => $variable->isConnected() === true);
    check('the company file is named', fn() => $variable->companyFile() === 'Mock Traders Pty Ltd');

    // -----------------------------------------------------------------------
    section('Plugin wiring');

    check('every service is registered', function() use ($plugin) {
        foreach (['auth', 'api', 'reference', 'contacts', 'invoices', 'payments', 'refunds', 'sync', 'log'] as $component) {
            if (!$plugin->has($component)) {
                return "missing $component";
            }
        }

        return true;
    });

    check('the CP routes are registered', function() {
        // `UrlManager::$cpRules` is empty in a console request — the event that fills it only
        // fires while resolving a CP URL. Firing it directly is the honest way to ask.
        $event = new craft\events\RegisterUrlRulesEvent(['rules' => []]);
        yii\base\Event::trigger(craft\web\UrlManager::class, craft\web\UrlManager::EVENT_REGISTER_CP_URL_RULES, $event);

        foreach (['my', 'my/documents', 'my/log'] as $route) {
            if (!isset($event->rules[$route])) {
                return "missing $route";
            }
        }

        return true;
    });

    check('no interpolated variable runs into a curly quote', function() {
        // `"“$reference”"` reads the closing quote's UTF-8 bytes as part of the variable name, so
        // PHP interpolates an undefined `$reference”` and the message loses the value. Braces fix it.
        $bad = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (token_get_all((string)file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && $token[0] === T_VARIABLE && preg_match('/[\x80-\xff]/', $token[1])) {
                    $bad[] = basename($file->getPathname()) . ':' . $token[2] . ' ' . $token[1];
                }
            }
        }

        return $bad === [] ?: implode(', ', $bad);
    });

    check('the permissions are registered', function() {
        $permissions = Craft::$app->getUserPermissions()->getAllPermissions();
        $found = [];

        foreach ($permissions as $group) {
            foreach ($group['permissions'] ?? [] as $handle => $definition) {
                $found[] = $handle;

                foreach ($definition['nested'] ?? [] as $nestedHandle => $nested) {
                    $found[] = $nestedHandle;
                }
            }
        }

        foreach (['my-viewDocuments', 'my-pushOrders', 'my-unlinkDocuments', 'my-viewLog', 'my-clearLog'] as $permission) {
            if (!in_array($permission, $found, true)) {
                return "missing $permission";
            }
        }

        return true;
    });

    check('the settings screen renders', function() use ($plugin) {
        $reflection = new ReflectionMethod($plugin, 'settingsHtml');
        $reflection->setAccessible(true);
        $html = (string)$reflection->invoke($plugin);

        return str_contains($html, 'Company file username') && str_contains($html, 'Sales account')
            ?: 'rendered ' . mb_strlen($html) . ' characters';
    });

    check('the settings template posts unprefixed field names', function() {
        // Craft namespaces this template's output itself. A name written as `settings[foo]` would
        // post as `settings[settings][foo]` and save nothing at all, silently.
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings.twig');

        return !str_contains($source, "name: 'settings[") ?: 'a field name carries its own settings prefix';
    });

    check('no CP template nests a form', function() {
        // A nested form does not merely fail: the parser drops the inner tag and keeps its
        // children, so a second `action` input lands beside the real one. Twig comments are
        // stripped first — they are where the rule is written down.
        foreach (['settings.twig', '_order-panel.twig'] as $name) {
            $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/' . $name);
            $source = preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;

            if (str_contains($source, '<form')) {
                return "$name contains a form";
            }
        }

        return true;
    });

    check('the order panel renders inside Commerce’s own screen', function() use ($journey) {
        $html = Craft::$app->getView()->renderTemplate('my/_order-panel', [
            'order' => $journey,
            'documents' => Plugin::getInstance()->getSync()->getDocumentsForOrder($journey->id),
            'connected' => true,
            'canPush' => true,
        ], craft\web\View::TEMPLATE_MODE_CP);

        return str_contains($html, 'MYOB') && str_contains($html, 'Push') ?: 'rendered ' . mb_strlen($html) . ' characters';
    });

    check('every console controller loads', function() {
        // A private method whose name collides with one of Yii's public controller methods —
        // `run()`, `table()`, `render()` — is a *compile-time* fatal the moment the class is
        // autoloaded, which takes the whole console down rather than just that command. Nothing
        // else in this suite loads these classes, so nothing else would notice.
        $classes = [
            justinholtweb\my\console\controllers\SyncController::class,
            justinholtweb\my\console\controllers\ConnectionController::class,
            justinholtweb\my\console\controllers\ReferenceController::class,
            justinholtweb\my\console\controllers\LogController::class,
        ];

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                return "$class did not load";
            }

            $controller = new $class('test', Plugin::getInstance());

            if (!$controller instanceof craft\console\Controller) {
                return "$class is not a console controller";
            }
        }

        return true;
    });

    check('no CP controller action is missing its request guards', function() {
        // `requirePost()` and friends do not exist — the methods are `requirePostRequest()`,
        // `requireAcceptsJson()`, `requireCpRequest()`. The wrong name is an unknown-method fatal
        // that only surfaces when the action is actually posted to, so it survives every render.
        foreach (glob(dirname(__DIR__, 2) . '/src/controllers/*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);

            foreach (['requirePost(', 'requireJson(', 'requireCp(', 'requireLogin('] as $wrong) {
                if (str_contains($source, '$this->' . $wrong)) {
                    return basename($file) . " calls \$this->$wrong";
                }
            }
        }

        return true;
    });

    check('the plugin icon is valid SVG', function() {
        $svg = file_get_contents(dirname(__DIR__, 2) . '/src/icon.svg');

        return simplexml_load_string($svg) !== false;
    });

    check('the mask icon is valid SVG', function() {
        $svg = file_get_contents(dirname(__DIR__, 2) . '/src/icon-mask.svg');

        return simplexml_load_string($svg) !== false;
    });

    check('every user-facing string is in the translations file', function() {
        $translations = require dirname(__DIR__, 2) . '/src/translations/en/my.php';
        $missing = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/**/*.php') ?: [] as $file) {
            preg_match_all("/Craft::t\(\s*'my'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/s", (string)file_get_contents($file), $matches);

            foreach ($matches[1] as $string) {
                $string = str_replace(["\\'", '\\\\'], ["'", '\\'], $string);

                if (!array_key_exists($string, $translations)) {
                    $missing[] = $string;
                }
            }
        }

        return $missing === [] ?: count($missing) . ' missing, e.g. ' . var_export($missing[0], true);
    });

} catch (Throwable $e) {
    // Fixture setup lives between the sections and is not wrapped in `check()`. Without this the
    // `exit()` in the `finally` swallows the exception and the run just stops, mid-section, with
    // no explanation at all.
    $failed++;
    echo "\n  ✗ the run stopped: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo '    ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    // -----------------------------------------------------------------------
    // Cleanup. Everything this run created goes away, pass or fail.

    echo "\nCleaning up\n";

    foreach ($createdOrders as $order) {
        try {
            Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['orderId' => $order->id])->execute();
            Craft::$app->getElements()->deleteElement($order, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$order->id}: " . $e->getMessage() . "\n";
        }
    }

    foreach ($createdProducts as $product) {
        try {
            Craft::$app->getElements()->deleteElement($product, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$product->id}: " . $e->getMessage() . "\n";
        }
    }

    try {
        Craft::$app->getDb()->createCommand()->delete(Table::CONNECTION)->execute();
        Craft::$app->getDb()->createCommand()->delete(Table::CONTACTS)->execute();
        Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
        Plugin::getInstance()->getReference()->flush();
    } catch (Throwable $e) {
        echo '  ! could not clear My’s tables: ' . $e->getMessage() . "\n";
    }

    try {
        applySettings($originalSettings);
        echo "  · settings restored\n";
    } catch (Throwable $e) {
        echo '  ! could not restore settings: ' . $e->getMessage() . "\n";
    }

    if ($mockPid) {
        // `posix_kill` rather than another `shell_exec` — no fork, no inherited database socket.
        if (function_exists('posix_kill')) {
            @posix_kill($mockPid, 15);
        } else {
            @shell_exec('kill ' . (int)$mockPid . ' 2>/dev/null');
        }
        @unlink('/tmp/my-mock-journal.jsonl');
        @unlink('/tmp/my-mock-control.json');
        echo "  · mock MYOB server stopped\n";
    }

    echo "\n$passed passed, $failed failed\n";

    exit($failed > 0 ? 1 : 0);
}
