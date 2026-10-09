# My — Craft CMS 5 Plugin

## Project Overview

My connects Craft Commerce 5 to **MYOB**: completed orders become sale invoices, customers become
contact cards, payments are applied, refunds become credit notes. Distributed as
`justinholtweb/craft-my`. **One paid edition, $99** — no edition gates anywhere.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks

## Architecture

- Namespace: `justinholtweb\my`
- Package: `justinholtweb/craft-my`
- Handle: `my` — tables `my_*`, permissions `my-*`, translation category `my`
- Twig variable is **`craft.myob`**, not `craft.my`. `craft.my` reads like a possessive and would
  be mistaken for a Craft feature in every template it appeared in.

### The three invariants

1. **`services\Invoices::buildPayload()` is the only place an order becomes MYOB invoice JSON.**
   The queue job, the CP "Push now" button, `my/sync/order`, `--dryRun` and the CP preview panel
   all go through it, so a preview is byte-identical to what MYOB receives.
2. **`services\Sync::record()` is the only place a document row is written.** Queue, manual,
   console, retry — the "has this already gone?" decision is made once and cannot disagree with
   itself.
3. **`services\Api::request()` is the only place an HTTP request reaches MYOB.** Header assembly,
   token refresh, throttling, retry, error parsing and logging happen exactly once.

### Data model

- `{{%my_documents}}` — the sync ledger. **Unique on `(orderId, docType, sourceKey)`; that index
  *is* the idempotency guarantee.** `sourceKey` is the constant `order` for the invoice and the
  Commerce transaction hash for a payment or refund.
- `{{%my_connection}}` — one row: tokens, expiry, company file.
- `{{%my_contacts}}` — email → MYOB customer UID.
- `{{%my_log}}` — the connection log, with payloads, credentials redacted.
- `{{%my_alerts}}` — failure-alert latches, one row per incident, unique on `incident` (5.1.0).
- `{{%my_digests}}` — the sync summary's "last sent" marker, one row (`handle = summary`) (5.1.0).

**Claim before the call, never after.** A crash between the two leaves a `pending` row, which is
the signal to ask MYOB whether the document exists (`$filter=Number eq '…'`) rather than blindly
re-POSTing. Claiming afterwards makes a lost response indistinguishable from a failed request.

**Tokens live in the database, not project config.** They are secrets and they are rewritten every
twenty minutes; replaying that through YAML onto every environment is both noisy and a leak.

### Protocol notes (read, not guessed)

From MYOB's own developer documentation, plus their API support articles.

- OAuth 2: authorize `https://secure.myob.com/oauth2/account/authorize`, token
  `https://secure.myob.com/oauth2/v1/authorize`, scope `CompanyFile`. **`expires_in` is 1200 — and
  it arrives as a *string*.**
- Headers: `Authorization: Bearer …`, `x-myobapi-key`, `x-myobapi-version: v2`,
  `x-myobapi-cftoken` (base64 of the *company file* user's `username:password`, which is **not**
  the MYOB account login). A file with no user-level security wants **no token at all**, not an
  empty one. A local AccountRight server takes plain HTTP Basic and has no developer key.
- **`GET /accountright/` no longer lists company files for API keys issued after 12 March 2025** —
  the id arrives on the OAuth redirect instead. An empty list is a normal outcome, so the settings
  screen always offers a text field too and never depends on the list.
- `POST` answers 201 with an **empty body** and only a `Location` header unless you send
  `?returnBody=true`. Always send it: the UID would otherwise cost a second round trip.
- `RowVersion` must be **omitted on POST** and is **required on PUT**. A stale one is a 409.
- Rate limit: **8 requests/second** per API key by default. `Retry-After` is honoured for any
  status, not only 429.
- Errors: `{"Errors":[{"Severity","Message","AdditionalDetails","ErrorCode"}]}`. `Message` says
  "Invalid data"; `AdditionalDetails` says which field. Keep both.
- Service invoice lines are `{Type: 'Transaction', Description, Total, Account, TaxCode}`; item
  lines add `Item`, `ShipQuantity`, `UnitPrice`. Line `Type` ∈ `Transaction|Header|Subtotal`.
- `PaymentMethod` is a fixed enum (Cash, Cheque, EFTPOS, Money Order, Visa, MasterCard, American
  Express, Diners Club, Bank Card, Barter Card, Other). Anything else is a 400 — an unmapped
  gateway falls back to the default rather than sending its handle.
- A credit note is an invoice with negative amounts. `Sale/CreditRefund` then pays it out of an
  account; `Sale/CreditSettlement` applies it to another invoice.
- OData `$filter` supports `any` over a collection, which is the only way to reach the email inside
  `Addresses`: `Addresses/any(x: x/Email eq '…')`. Single quotes are escaped by doubling.

### Totals

The prime directive is that MYOB books what the customer paid.

- `Subtotal`, `TotalTax`, `TotalAmount` are **never sent**.
- `Invoices::reconcile()` computes them anyway and compares. In tax-inclusive mode the arithmetic
  closes exactly against `Order::getTotalPrice()`; the derivation is in the docblock.
- `Invoices::verifyAgainstOrder()` re-checks against what MYOB actually booked, which is what
  catches a tax code mapped to the wrong rate.
- **`lineAmount()` is built from `LineItem::getTotal()`**, not from subtotal + discount. Commerce's
  adjuster list is open; reconstructing from known types silently drops third-party surcharges.
  Only shipping (which goes to `Freight`) and, in exclusive mode, tax are pulled back out.
- An adjustment naming a line item that is not on the order is deliberately **not** absorbed. It is
  a Commerce data problem, and reconciliation refusing the push is how the merchant finds out.

### Failure alerts (ported from Erpy via Zo, 2026-10-09)

`services\Alerts` is a copy of craft-erpy's reference (its CLAUDE.md, "Failure alerts"), by way of
craft-zo, with the connection dimension dropped. Three incidents: **failures** (ledger rows
`failed`, by `dateUpdated` inside `alertWindowMinutes`, threshold to open, a whole quiet window to
close), **mismatch** (`Sync::mismatchCondition()` — a `synced` row that still carries
`verifyAgainstOrder()`'s complaint in `lastError` — by `dateSynced` inside the window), **auth** (a
pushed signal). Hooks: `Sync::pushOrder()` wraps the real work (`pushOrderSerialised()`) and calls
`afterSync()` in a `finally`; `Api::send()` signals a 401 that reaches the final error branch (after
the forced refresh in cloud mode; at once in local mode) and calls `noteAuthSuccess()` on every
success; `Auth::refresh()` signals a 4xx from the token endpoint (`invalid_grant` etc. — never a
network failure or a 5xx). `check()` does nothing until `Connection::isConnected()`. Retryable
failures stay `pending` and never alert — only what the queue gave up on does. A CP banner
(`Cp::EVENT_REGISTER_ALERTS`) shows every open incident to `my-viewDocuments`. Do not change when
touching it: the conditional-UPDATE claim/release, redaction before anything leaves, the webhook
through `webhookTarget()` (`helpers\Ip` is the family copy — keep it identical), the HMAC header
(`X-My-Signature`), every path fail-open.

`Api::$clientConfig` and `Auth::$clientConfig` are merged into the Guzzle client options. Empty in
production; the alert/order/digest suites put a `MockHandler` stack there.

### Order status (Orders index column and condition rule)

`services\OrderStatus::statuses()` (PHP, one query per batch) and `::condition()` (SQL, for
`MyobStatusConditionRule::modifyQuery()`) define the same six sets in the same precedence — failed
(any document) > mismatch > synced > pending > skipped (all three by the invoice row) > none — and
`tests/integration/orders.php` holds them to partitioning the fixtures identically. Change one,
change both. The column prefetches the whole page from `$order->elementQueryResult` on the first
cell. The rule is registered unconditionally. `PushToMyob` queues **unforced** `PushOrder` jobs,
re-checks `my-pushOrders`, refuses when not connected, and skips carts and orders the user cannot
`canView()` — the same rule as the order panel's Push now.

### Sync summary (ported from Yarn, 2026-10-09)

`services\Digest` is a copy of craft-yarn's reference (its CLAUDE.md, "Scheduled digests"):
`helpers\Mailer`, `queue\jobs\SendDigest`, `console\controllers\DigestController`,
`controllers\DigestController`, `events\DigestEvent` and the marker table are the generic part.
My's part: recipients are **`recipientList()` — the alert recipients**, there is no separate list;
`collect()` keys every failed/mismatched document as `doc:<id>:<state>` (new = not in last `seen`);
`activity($since)` counts synced documents by type and invoice totals per currency since the last
send (or one period back); a period is "nothing new" only when there are no new problems **and**
no activity. The test button posts over Ajax (`data-my-action`), never a form — the settings screen
is one.

## Traps found while building this

- **`yii\base\Controller::run()` is public, so a private `run()` on a console controller is a
  compile-time fatal** the moment the class autoloads — which takes the *whole console* down, not
  just that command. Same family as `Model::load()` and `Model::rules()`. `craft help` in the
  shared harness is currently broken by `craft-bird` doing exactly this with `table()`.
- **Yii skips inline validators when the attribute is empty**, and empty is exactly what
  `validateSalesAccount` exists to reject. Both inline rules need `'skipOnEmpty' => false`.
- **Redacting `Authorization: Bearer …` with `[^\s]+` redacts the word `Bearer`**, leaving the
  token in the log. The scheme has to be matched explicitly and kept.
- **`{% for … if … %}` was removed in Twig 3** — use `|filter(x => …)`.
- **`OrderAdjustments::saveOrderAdjustment()` ignores the `lineItemId` property** and takes it from
  the *related model* (`$orderAdjustment->getLineItem()->id ?? null`). Setting the property alone
  silently saves the adjustment as order-level.
- **`Transactions::createTransaction()` reaches for `$order->getGateway()->id`** and fatals on an
  order that was never taken to checkout. Build the `Transaction` by hand in fixtures.
- **`Craft::$app->getUrlManager()->cpRules` is empty in a console request** — the CP URL rules
  event only fires while resolving a CP URL. Fire it directly to test.
- **`ProjectConfig::flush()` can only be called once per process.** Writing the YAML stamps a new
  `configVersion` into `info` that the loaded `Info` model does not know about, so the second call
  decides another request beat it and throws `StaleResourceException`. Use
  `saveModifiedConfigData()` for repeated writes; re-read and re-stamp `configVersion` if it drifts.
- **A `shell_exec()` after Craft has booted forks a child that inherits the MySQL socket.** A
  long-lived child holding it produces "MySQL server has gone away" and stale-project-config errors
  minutes later, in code with nothing to do with either. Start long-running helpers *before* the
  Craft bootstrap.
- **`_includes/statuses` does not exist in Craft 5** (inherited note from the sibling plugins).
- **Commerce's Orders index 500s for a non-admin with no `editSite:<uid>` permission** —
  `OrderElementTrait::defineActions()` calls `Cp::requestedSite()->getStore()`, and the requested
  site is null when the user may edit none. HTTP tests of the index need that permission.
- **A captured email's wire form is quoted-printable.** Read
  `$message->getSymfonyEmail()->getTextBody()` in tests, never `toString()`.

See `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps, and
`[[project_craft_shipper]]` / `[[project_craft_freshh]]` for the sibling Commerce integrations
whose conventions this follows.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-my/tests/integration/checks.php   # 231 checks
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-my/tests/integration/alerts.php  # 60: latch, mail, SSRF, webhook, auth signals, banner, widget, console, test action over HTTP
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-my/tests/integration/orders.php  # 27: status sets vs SQL, condition rule (incl. stale values), column + action in process and over HTTP
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-my/tests/integration/digest.php  # 34: schedule, marker claim/release, email, fallback, console, test action over HTTP
ddev exec bash -c 'find /var/www/craft-my/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Most of the run happens against **`tests/integration/mock-myob.php`**, a stand-in MYOB API started
by the suite and reached in `local` mode over real HTTP. It journals every request — method, path,
query, headers, body — so the tests assert on what was actually sent rather than on what the
client believed it sent, and it totals invoices the way a company file does, so the "did MYOB book
what the customer paid" check is answered by something other than the code under test. A control
endpoint queues failures, which is how the retry, backoff and recovery paths are exercised.

The suite is idempotent and self-cleaning: fixtures, ledger rows, log rows and settings are all
restored in a `finally`.

`alerts.php`, `orders.php` and `digest.php` share `tests/integration/_support.php`: settings in
memory only, MYOB mocked with Guzzle's `MockHandler` on the `clientConfig` seams, the harness's
connection row and digest marker snapshotted and put back, fixtures removed in a shutdown function.

**Harness note:** `craft-penny` registers an `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler typed
`ModelEvent` while Craft passes an `ElementEvent`, so **every element save fatals** while it is
enabled. `checks.php` detaches that handler in-process (never persisted). That is a bug in Penny.

## Coding conventions

- `Craft::t('my', '…')` for user-facing strings; `src/translations/en/my.php` lists them, and a
  check fails if one is missing
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- The settings template's field names carry **no** `settings[…]` prefix; Craft namespaces the
  template's output itself
- Anything that runs during checkout must fail **open** — pushing is always queued, never inline
