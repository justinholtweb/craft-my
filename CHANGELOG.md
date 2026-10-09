# Release Notes for My

## Unreleased

### Added

- Failure alerts. One email — and optionally a Slack, Teams or signed JSON webhook — when pushes
  start failing, when MYOB books an invoice at a different total from the order, or when MYOB
  refuses the connection (a refused refresh token, or a 401 a fresh token did not fix); one more
  when it clears. Each incident is a latch, so a hundred failures are one message, and a flapping
  connection waits out a quiet period. Checked after every push, the moment MYOB refuses the
  connection, and by `my/alerts/check` and `my/sync/retry`. Webhooks go through the family SSRF
  guard and never follow redirects; alert bodies are redacted before they leave.
- A banner across the control panel while an alert is open — above all for a dead refresh token,
  which otherwise stops every invoice without a sound.
- A **MYOB health** Dashboard widget: connection, the last seven days of the ledger, invoices
  booked at a different total, the last successful push and any open alert.
- A weekly (or daily) **sync summary** to the alert recipients: what went to MYOB, what is still
  failed, and each new problem since the last one. Sent from `my/digest/send` on cron, or from the
  queue after a web request on sites without cron; once per period, claimed in the database.
  `my/digest/status`, and **Send a test summary now** on the settings screen.
- A **MYOB** column on Commerce's Orders index (status and invoice number), a **MYOB status**
  condition rule for filters and custom sources, and a **Push to MYOB** bulk action for people
  with *Push orders to MYOB*.
- The documents screen can be filtered to invoices booked at a different total.
- `my/alerts/check` and `my/alerts/test`.

### Changed

- MYOB's `invalid_request` refusal now reads "MYOB rejected the request for an access token…".
  The old "…rejected the token request…" reached the authentication alert as "the token ••••",
  because alert redaction masks whatever follows the word "token".

### Fixed

- A saved **MYOB status** filter or custom source whose chosen statuses had all since been renamed
  or removed no longer widens to every order. "Is one of" now matches nothing, "is not one of"
  excludes nothing, and the stale values are kept rather than stripped, so re-saving the source no
  longer loses them for good. Only statuses My knows ever reach the query.

## 5.0.0 — 2026-08-20

Initial release.

### Added

- Completed Commerce orders become MYOB sale invoices, on order completion or on any order status
  the merchant nominates, pushed through the queue so checkout never waits on MYOB.
- Both MYOB transports: the cloud API (`api.myob.com/accountright/`, OAuth 2) and an on-premise
  AccountRight local server (HTTP Basic).
- Customer cards: found by email before being created, so a company file that has been trading for
  a decade does not end up with two of everybody.
- Payments applied to the invoice, one MYOB payment per Commerce transaction.
- Refunds raised as credit notes, optionally paid back out of a nominated bank account.
- Service and Item invoice layouts, with SKU → inventory item resolution and a configurable
  fallback.
- Tax category → MYOB tax code mapping, per-line tax attribution taken from Commerce's own
  adjustments, and a total reconciliation that runs before the push *and* against what MYOB
  actually booked.
- A sync ledger with a unique key per document, so a retried queue job cannot create a second
  invoice — and a recovery path that asks MYOB whether an interrupted push actually landed.
- A connection log with request and response bodies, credentials redacted.
- The MYOB panel on Commerce's order edit screen, with "Push now" and a payload preview that goes
  through the same builder the real push does.
- Console commands: `my/sync/order`, `my/sync/backfill`, `my/sync/retry`, `my/sync/status`,
  `my/connection/test`, `my/connection/company-files`, `my/connection/refresh`,
  `my/reference/accounts`, `my/reference/tax-codes`, `my/reference/item`, `my/reference/refresh`,
  `my/log/prune`, `my/log/clear`.
- `craft.myob` in Twig, for showing an invoice number on a customer's order page.

### Fixed

- `my/sync/retry` called `$this->run()` — Yii's public `run($route, $params)` — instead of the private `pushEach()`, so it threw a `TypeError` the moment there was anything to retry.
- The document detail heading glued the MYOB number to the document ID. Twig's `??` binds tighter than `~`, so `document.myobNumber ?? '#' ~ document.id` parsed as `(document.myobNumber ?? '#') ~ document.id`.

