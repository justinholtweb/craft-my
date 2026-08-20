# My

**MYOB for Craft Commerce.** A completed order becomes a sale invoice in MYOB, against a real
customer card, with the payment applied and a credit note raised if it is later refunded. Nothing
is entered twice, and every request either end makes is visible in a log.

Requires **Craft CMS 5.3+**, **Craft Commerce 5.0+** and **PHP 8.2+**. One edition, $99.

---

## What it does

- **Orders → invoices.** On completion, or on any order status you nominate. The push runs as a
  queue job, so a MYOB outage delays an invoice rather than a customer's payment.
- **Customers → contacts.** Found by email first, created only if there is no card. A company file
  that has been trading for a decade does not end up with two of everybody.
- **Payments → customer payments.** One MYOB payment per Commerce transaction, applied to the
  invoice, so it does not sit unpaid forever.
- **Refunds → credit notes.** Optionally paid back out of a nominated bank account.
- **Both MYOB transports.** The cloud API over OAuth 2, and an on-premise AccountRight local
  server over HTTP Basic.

## The part that actually matters

An accounting integration has exactly one unforgivable failure mode: booking a different number
than the customer paid. My is built around not doing that.

- **The payload never states a total.** `Subtotal`, `TotalTax` and `TotalAmount` are computed by
  MYOB from the lines. A payload whose stated total disagrees with its own lines is how invoices
  end up subtly and permanently wrong.
- **The same figures are computed locally and checked before the push.** If they disagree with the
  order by more than a cent, the default is to refuse — an invoice for the wrong amount is harder
  to find and much harder to undo than a failed push. You can ask for a rounding line instead.
- **And checked again afterwards, against what MYOB actually booked.** The payload can reconcile
  perfectly and the invoice still be wrong, if a tax code is mapped to a rate Commerce did not use.
  That is caught, logged and shown on the order.
- **Line amounts come from Commerce's own arithmetic**, not from a reconstruction of subtotal and
  discount. Commerce's adjuster list is open — a site can have a surcharge, a deposit, an eco-levy
  — and rebuilding lines from only the adjustment types this plugin knows about would silently
  invoice the customer for less than they paid.

## Nothing is entered twice

MYOB has no notion of "the invoice for Craft order 1234", and a queue job can be retried at any
time — including after a request that succeeded but whose response was lost.

- The sync ledger is unique on `(order, document type, source)`. One invoice per order; one payment
  per Commerce transaction, keyed on the transaction hash.
- The row is **claimed before the HTTP call**, not after. A crash in between leaves a `pending`
  row, and a `pending` row is the signal to go and *ask* MYOB whether the document exists before
  POSTing it again. Claiming afterwards would make a lost response indistinguishable from a failed
  request, which is how duplicate invoices are made.
- Every invoice carries the Craft order reference in `Number` and in `JournalMemo`, so a human can
  reconcile even if the ledger is lost.

## Connecting

**Cloud.** Register an app at [developer.myob.com](https://developer.myob.com), paste the API key
and secret into the settings screen, and register the redirect URL it prints — MYOB matches it
character for character. Then press *Connect to MYOB*.

The company file's own username and password are a **separate credential** from your MYOB account
login, and MYOB rejects both with the same 401. My says so when it happens, because everybody
confuses them at least once.

> API keys issued after 12 March 2025 are no longer allowed to list a company file. Those get the
> company file ID on the OAuth redirect instead, and the settings screen always offers a plain text
> field alongside the list, so nothing depends on the list working.

**Local.** Point *AccountRight server URL* at your desktop server (`http://localhost:8080/accountright/`)
and fill in the company file credentials. Craft has to be able to reach it — a server on the
bookkeeper's desktop is not reachable from a hosted site.

## Invoice layout

MYOB has two sale invoice layouts, and the choice matters:

| | Service | Item |
|---|---|---|
| Each line posts to | an account | an inventory item |
| Needs anything in MYOB first | no | every SKU you sell |
| Moves stock / costs goods | no | yes |

**Service is the default**, because the average Commerce catalogue is not mirrored in the company
file. In Item layout, a SKU with no matching item either falls back to an account line or refuses
the push — your choice. It is never dropped, because under-invoicing is not the lesser evil.

## Tax

Tax is attributed per line from Commerce's own adjustments, not recomputed. Posting **tax-inclusive**
guarantees the invoice total matches what the customer paid; posting exclusive lets MYOB compute
the tax from its codes, which can disagree with Commerce if a code is mapped to a different rate.
By default My follows whatever the order itself did.

Map Commerce tax categories to MYOB tax codes on the settings screen. A line that attracted no tax
gets the zero-rated code rather than the default one, so MYOB does not invent GST on something
Commerce treated as exempt.

## Console

```sh
php craft my/sync/order <reference>      # push one order (--dryRun prints the payload)
php craft my/sync/backfill --limit=100   # push every completed order that never reached MYOB
php craft my/sync/retry                  # try again on everything that failed
php craft my/sync/status                 # connection, ledger counts, what is outstanding

php craft my/connection/test             # ask MYOB who we are
php craft my/connection/company-files    # list the files this connection can see
php craft my/connection/refresh          # force a token refresh

php craft my/reference/accounts          # the chart of accounts, with the codes settings wants
php craft my/reference/tax-codes
php craft my/reference/item <number>
php craft my/reference/refresh

php craft my/log/prune --days=30
php craft my/log/clear
```

`my/sync/order --dryRun` builds the payload through the same code the real push uses, so what it
prints is what MYOB would receive — not a reassuring approximation. The CP order panel's *Preview*
button does the same thing.

## Twig

```twig
{% if craft.myob.isInvoiced(order) %}
    <p>Invoice {{ craft.myob.invoice(order).myobNumber }}</p>
{% endif %}

{% for document in craft.myob.documents(order) %}
    {{ document.getTypeLabel() }} — {{ document.getStatusLabel() }}
{% endfor %}
```

## Permissions

`View synced documents`, and nested under it `Push orders to MYOB` and `Unlink documents from
MYOB`; plus `View the connection log`. Unlinking forgets the link on the Craft side and never
deletes anything in MYOB — an invoice reconciled against a bank feed cannot be deleted anyway.

## What it does not do

Inventory and stock write-back into Commerce, purchase orders, and MYOB Acumatica (a different API
entirely).

## Testing

217 integration checks, most of them run against a mock MYOB API over real HTTP, so the client is
exercised where the interesting bugs live — header assembly, OData filters, paging, `returnBody`,
retry and backoff, error parsing. The mock totals invoices itself, so "did MYOB book what the
customer paid" is answered by something other than the code under test.

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-my/tests/integration/checks.php
```

## License

Proprietary. © Justin Holt.
