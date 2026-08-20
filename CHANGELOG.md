# Release Notes for My

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
