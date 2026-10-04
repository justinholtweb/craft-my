<?php
/**
 * A stand-in for the MYOB Business API, good enough to exercise the real client.
 *
 * Run by `checks.php` with PHP's built-in server:
 *
 *     php -S 127.0.0.1:8899 tests/integration/mock-myob.php
 *
 * It is not a simulator of MYOB's business rules. It does three things that matter:
 *
 * 1. Answers the endpoints My actually calls, in MYOB's response shapes.
 * 2. Records every request — method, path, query, headers, body — so the tests can assert on the
 *    headers and the exact payload rather than on whatever the client believes it sent.
 * 3. Computes `TotalAmount` from the lines the way a company file does, so the "did MYOB book what
 *    the customer paid" check is answered by something other than the code under test.
 *
 * A control endpoint queues failures, which is how the retry and 401-refresh paths get exercised
 * without waiting for MYOB to have a bad day.
 */

const JOURNAL = '/tmp/my-mock-journal.jsonl';
const CONTROL = '/tmp/my-mock-control.json';

const CF_ID = '11111111-2222-3333-4444-555555555555';
const GST_UID = 'aaaaaaaa-0000-0000-0000-000000000001';
const FRE_UID = 'aaaaaaaa-0000-0000-0000-000000000002';
const SALES_UID = 'bbbbbbbb-0000-0000-0000-000000000001';
const FREIGHT_UID = 'bbbbbbbb-0000-0000-0000-000000000002';
const BANK_UID = 'bbbbbbbb-0000-0000-0000-000000000003';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$rawBody = file_get_contents('php://input') ?: '';
$body = $rawBody !== '' ? json_decode($rawBody, true) : null;

// Control plane — never journalled, never counted.
if ($path === '/__control') {
    file_put_contents(CONTROL, $rawBody !== '' ? $rawBody : '{}');
    respond(200, ['ok' => true]);
}

if ($path === '/__journal') {
    header('Content-Type: application/json');
    echo file_exists(JOURNAL) ? file_get_contents(JOURNAL) : '';
    exit;
}

if ($path === '/__reset') {
    @unlink(JOURNAL);
    @unlink(CONTROL);
    respond(200, ['ok' => true]);
}

journal($method, $path, $_GET, headers(), $body);

// Queued failures, one per request, so a test can say "fail twice then succeed".
$control = file_exists(CONTROL) ? (json_decode(file_get_contents(CONTROL), true) ?: []) : [];
$queue = $control['fail'] ?? [];
// `failPath` aims the queue at one endpoint, so the requests before it in a push go through.
$failPath = $control['failPath'] ?? null;

if ($queue !== [] && ($failPath === null || str_ends_with($path, $failPath))) {
    $status = (int)array_shift($queue);
    $control['fail'] = $queue;
    file_put_contents(CONTROL, json_encode($control));

    // Every injected failure carries `Retry-After: 0`, so the client's backoff is exercised
    // without the suite spending a minute asleep. MYOB really does send this on 429 and 503.
    header('Retry-After: 0');

    respond($status, ['Errors' => [[
        'Severity' => 'Error',
        'Message' => 'Injected failure',
        'AdditionalDetails' => "status $status",
        'ErrorCode' => 9001,
    ]]]);
}

$cf = '/accountright/' . CF_ID;

// The company file list.
if ($path === '/accountright/' || $path === '/accountright') {
    respond(200, [[
        'Id' => CF_ID,
        'Name' => 'Mock Traders Pty Ltd',
        'LibraryPath' => '',
        'ProductVersion' => '2024.5',
        'Country' => 'AU',
        'Uri' => 'http://127.0.0.1:8899' . $cf,
        'SerialNumber' => '123456789012',
    ]]);
}

// The company file itself — who are we, and can we open it.
if ($path === $cf || $path === $cf . '/') {
    respond(200, [
        'CompanyFile' => [
            'Id' => CF_ID,
            'Name' => 'Mock Traders Pty Ltd',
            'ProductVersion' => '2024.5',
            'Country' => 'AU',
        ],
        'UserAccess' => [
            'UserName' => 'Administrator',
            'IsReadOnly' => false,
        ],
    ]);
}

if ($path === $cf . '/GeneralLedger/Account') {
    respond(200, collection([
        ['UID' => SALES_UID, 'DisplayID' => '4-1000', 'Name' => 'Sales', 'Type' => 'Income'],
        ['UID' => FREIGHT_UID, 'DisplayID' => '4-2000', 'Name' => 'Freight collected', 'Type' => 'Income'],
        ['UID' => BANK_UID, 'DisplayID' => '1-1100', 'Name' => 'Business account', 'Type' => 'Bank'],
    ]));
}

if ($path === $cf . '/GeneralLedger/TaxCode') {
    respond(200, collection([
        ['UID' => GST_UID, 'Code' => 'GST', 'Description' => 'Goods & Services Tax', 'Rate' => 10.0],
        ['UID' => FRE_UID, 'Code' => 'FRE', 'Description' => 'GST Free', 'Rate' => 0.0],
    ]));
}

if ($path === $cf . '/Inventory/Item') {
    $filter = (string)($_GET['$filter'] ?? '');

    // Only SKU-KNOWN exists, so the item-fallback path has something to fall back from.
    if (str_contains($filter, 'SKU-KNOWN')) {
        respond(200, collection([[
            'UID' => 'cccccccc-0000-0000-0000-000000000001',
            'Number' => 'SKU-KNOWN',
            'Name' => 'A known item',
            'IsSold' => true,
        ]]));
    }

    respond(200, collection([]));
}

if ($path === $cf . '/Contact/Customer') {
    if ($method === 'POST') {
        respond(201, ($body ?? []) + [
            'UID' => 'dddddddd-0000-0000-0000-' . substr(md5($rawBody), 0, 12),
            'DisplayID' => '*None',
            'RowVersion' => '-1234567890',
        ]);
    }

    $filter = (string)($_GET['$filter'] ?? '');

    if (str_contains($filter, 'known@example.com')) {
        respond(200, collection([[
            'UID' => 'dddddddd-0000-0000-0000-00000000beef',
            'CompanyName' => '',
            'FirstName' => 'Known',
            'LastName' => 'Customer',
            'DisplayID' => 'CUST-1',
            'RowVersion' => '-1',
        ]]));
    }

    respond(200, collection([]));
}

if (preg_match('~^' . preg_quote($cf, '~') . '/Contact/Customer/([0-9a-f-]+)$~i', $path, $m)) {
    respond(200, [
        'UID' => $m[1],
        'IsIndividual' => true,
        'FirstName' => 'Known',
        'LastName' => 'Customer',
        'RowVersion' => '-1',
    ]);
}

if ($path === $cf . '/Sale/Invoice/Service' || $path === $cf . '/Sale/Invoice/Item') {
    if ($method === 'POST') {
        respond(201, invoiceResponse($body ?? []));
    }

    $filter = (string)($_GET['$filter'] ?? '');
    $control = file_exists(CONTROL) ? (json_decode(file_get_contents(CONTROL), true) ?: []) : [];

    // "Recovery" mode: pretend a previous push landed, so `Sync::recoverInvoice()` finds it.
    if (!empty($control['recover']) && str_contains($filter, (string)$control['recover'])) {
        respond(200, collection([[
            'UID' => 'eeeeeeee-0000-0000-0000-00000000cafe',
            'Number' => $control['recover'],
            'TotalAmount' => (float)($control['recoverTotal'] ?? 0),
            'RowVersion' => '-9',
        ]]));
    }

    respond(200, collection([]));
}

if (preg_match('~^' . preg_quote($cf, '~') . '/Sale/Invoice/(Service|Item)/([0-9a-f-]+)$~i', $path, $m)) {
    respond(200, [
        'UID' => $m[2],
        'Number' => 'INV-READ',
        'RowVersion' => '-777',
        'TotalAmount' => 0.0,
    ]);
}

if ($path === $cf . '/Sale/CustomerPayment' && $method === 'POST') {
    respond(201, ($body ?? []) + [
        'UID' => 'ffffffff-0000-0000-0000-' . substr(md5($rawBody), 0, 12),
        'ReceiptNumber' => 'CR000001',
        'RowVersion' => '-2',
    ]);
}

if ($path === $cf . '/Sale/CreditRefund' && $method === 'POST') {
    respond(201, ($body ?? []) + [
        'UID' => '99999999-0000-0000-0000-' . substr(md5($rawBody), 0, 12),
        'Number' => 'CD000001',
        'RowVersion' => '-3',
    ]);
}

respond(404, ['Errors' => [['Severity' => 'Error', 'Message' => 'Not found', 'AdditionalDetails' => $path]]]);

// ---------------------------------------------------------------------------

/**
 * Total an invoice the way a company file does — which is the point of computing it here rather
 * than trusting the number the client hoped for.
 */
function invoiceResponse(array $payload): array
{
    $inclusive = (bool)($payload['IsTaxInclusive'] ?? true);
    $net = 0.0;
    $tax = 0.0;

    foreach ($payload['Lines'] ?? [] as $line) {
        $total = (float)($line['Total'] ?? 0);
        $net += $total;

        if (($line['TaxCode']['UID'] ?? '') === GST_UID) {
            $tax += $inclusive ? $total - ($total / 1.1) : $total * 0.1;
        }
    }

    $freight = (float)($payload['Freight'] ?? 0);
    $net += $freight;

    if (($payload['FreightTaxCode']['UID'] ?? '') === GST_UID) {
        $tax += $inclusive ? $freight - ($freight / 1.1) : $freight * 0.1;
    }

    $total = $inclusive ? $net : $net + $tax;

    return $payload + [
        'UID' => 'eeeeeeee-0000-0000-0000-' . substr(md5(json_encode($payload)), 0, 12),
        'Number' => $payload['Number'] ?? 'AUTO-0001',
        'Subtotal' => round($inclusive ? $net - $tax : $net, 2),
        'TotalTax' => round($tax, 2),
        'TotalAmount' => round($total, 2),
        'BalanceDueAmount' => round($total, 2),
        'Status' => 'Open',
        'RowVersion' => '-' . abs(crc32(json_encode($payload))),
        'URI' => 'http://127.0.0.1:8899/accountright/' . CF_ID . '/Sale/Invoice',
    ];
}

function collection(array $items): array
{
    return ['Items' => $items, 'Count' => count($items), 'NextPageLink' => null];
}

function headers(): array
{
    $out = [];

    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $out[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
        }
    }

    return $out;
}

function journal(string $method, string $path, array $query, array $headers, ?array $body): void
{
    file_put_contents(JOURNAL, json_encode([
        'method' => $method,
        'path' => $path,
        'query' => $query,
        'headers' => $headers,
        'body' => $body,
    ]) . "\n", FILE_APPEND);
}

function respond(int $status, mixed $data): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
