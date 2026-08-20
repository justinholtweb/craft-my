# My — MYOB integration for Craft Commerce

## What it does

A completed Craft Commerce order becomes a sale invoice in MYOB, against a real customer card,
with the payment applied and a credit note raised if it is later refunded. Nothing is entered
twice, and every request either end makes is visible in a log.

## Decisions (2026-08-20)

| Decision | Choice |
|---|---|
| API surface | **MYOB Business API v2** — cloud (`api.myob.com/accountright/`, OAuth 2) **and** the on-premise AccountRight local server (`localhost:8080`, HTTP Basic) |
| Scope | Orders → invoices, customers → contacts, payments → customer payments, refunds → credit notes |
| Trigger | Configurable Commerce order statuses, pushed through a **queue job** — checkout never waits on MYOB |
| Editions | **Single paid edition, $99.** No edition gates to write, test or explain |

## The three invariants

1. **`services\Invoices::buildPayload()` is the only place an order becomes MYOB invoice JSON.**
   The queue job, the CP "Push now" button, the console command and the CP "Preview JSON" panel
   all go through it, so what a merchant previews is byte-identical to what MYOB receives.
2. **`services\Sync::record()` is the only place a document row is written.** Every path — queue,
   manual, console, retry — lands there, so the "already pushed" decision is made once and cannot
   disagree with itself.
3. **`services\Api::request()` is the only place an HTTP request reaches MYOB.** Header assembly,
   token refresh, throttling, retry, error parsing and logging happen once.

## Idempotency

MYOB has no notion of "the invoice for Craft order 1234", and a queue job can be retried at any
time — including after a request that actually succeeded but whose response was lost. So:

- `{{%my_documents}}` is unique on `(orderId, docType, sourceKey)`. `sourceKey` distinguishes the
  several payments or refunds an order can have; for the invoice it is the constant `order`.
- The row is claimed **before** the HTTP call, in state `pending`, and updated to `synced` or
  `failed` afterwards. A crash between the two leaves a `pending` row, and the recovery path asks
  MYOB whether the document exists (`$filter=Number eq '…'`) rather than blindly re-POSTing.
- Every invoice carries the Craft order reference in `Number` (configurable) *and* in
  `JournalMemo`, so a human can reconcile even if the plugin's own table is lost.

## Auth

Two modes, one interface:

- **Cloud.** OAuth 2 authorisation-code flow against `secure.myob.com`. Access tokens live
  **20 minutes**, so a refresh is part of normal operation, not an error path — it happens under a
  mutex so two concurrent queue workers cannot race and invalidate each other's refresh token.
  Requests carry `Authorization: Bearer …`, `x-myobapi-key`, `x-myobapi-version: v2` and
  `x-myobapi-cftoken` (base64 of the *company file* user's `username:password`, which is not the
  MYOB account login).
- **Local.** The AccountRight desktop server at `http://localhost:8080/accountright/`. No OAuth,
  no developer key: HTTP Basic with the company file credentials.

Tokens live in `{{%my_connection}}`, not project config — they are secrets, and they are rewritten
every twenty minutes, which is not something to replay through YAML onto every environment.

**Company file id:** API keys created after 12 March 2025 no longer get a company file list from
`GET /accountright/`; the id arrives on the OAuth redirect instead. The settings screen therefore
accepts a hand-typed id as well as offering the list, and never depends on the list working.

## Invoice shape

MYOB has two sale invoice layouts and the choice matters:

- **Service** (`/Sale/Invoice/Service`) — each line posts to an **account**. Nothing needs to exist
  in MYOB beforehand. **This is the default**, because the average Commerce catalogue is not
  mirrored in the company file.
- **Item** (`/Sale/Invoice/Item`) — each line references an **inventory item UID**. Correct
  inventory and cost of goods, but every SKU sold must already exist in MYOB. Opt-in, with a
  SKU → item resolution step and a configurable failure mode (skip the line, fall back to a
  service line, or fail the whole push).

## Totals must reconcile

Nothing is more damaging than an accounting integration that quietly books a different number than
the customer paid. `Invoices::buildPayload()` therefore:

- omits `Subtotal`, `TotalTax` and `TotalAmount` on POST — MYOB computes them, and sending
  disagreeing values is how invoices end up subtly wrong;
- but computes the same figures locally and compares them against the order, and refuses the push
  (or adds a configurable rounding line) when they differ by more than a cent.

## Data model

- `{{%my_connection}}` — one row: tokens, expiry, company file id and URI, auth mode.
- `{{%my_documents}}` — the sync ledger. Unique on `(orderId, docType, sourceKey)`.
- `{{%my_contacts}}` — email → MYOB customer UID, so a repeat customer is not re-created.
- `{{%my_log}}` — every request, with payloads.

## Out of scope for 5.0.0

Inventory/stock write-back into Commerce, purchase orders, and MYOB Acumatica (a different API
entirely).
