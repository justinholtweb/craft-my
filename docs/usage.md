---
title: Usage
slug: usage
order: 30
summary: When orders go to MYOB, what an invoice contains, payments and credit notes, reconciliation, the ledger and recovery, the log, permissions and the Twig variable.
---

## When orders push

Four things queue an order for MYOB:

| Event | Pushed when |
|---|---|
| The order completes | It passes the checks below |
| The order's status changes | The new status is one you ticked under **Invoice orders in these statuses** |
| A Commerce transaction succeeds | It's a purchase, capture or refund (not an authorisation) and the order passes the checks below |
| A refund is recorded | The order's invoice is already in MYOB, whatever status the order is now in |

An order passes the checks when **Push orders automatically** is on, the order is completed, and
either no statuses are ticked or its current status is one of them. Ticking `shipped`, for
example, means orders are invoiced when they ship rather than when they're paid for.

Every one of these **queues** a job. Nothing waits on MYOB during checkout, so a MYOB outage or its
rate limit delays an invoice, never a customer's payment.

A job that fails is retried by the queue up to **Queue attempts** times. Inside each attempt, a
request that hits something temporary (no response, a `429`, a `5xx`) is retried up to three more
times with backoff, honouring MYOB's `Retry-After` header when it sends one. A refusal such as a
`400` won't change on retry; it stays on the document as **Failed** until someone fixes the cause.

## What a push does

A push sends everything the order owes MYOB, in order, and each step stops the ones after it:

1. **The customer card.** Found or created. See
   [Customers](configuration#customers).
2. **The invoice.** Skipped if the order is already invoiced.
3. **Payments.** One MYOB customer payment per successful Commerce payment, applied to the invoice.
4. **Refunds.** One credit note per successful Commerce refund and, if you chose to, a credit
   refund paying it out.

A payment can't be applied to an invoice that doesn't exist, so a failed invoice stops the push
there. Running the push again later picks up where it stopped.

## The invoice

My posts a sale invoice to `Sale/Invoice/Service` or `Sale/Invoice/Item`, depending on the
[layout](configuration#service-or-item). It contains:

| Field | From |
|---|---|
| `Customer` | The resolved customer card |
| `Number` | The order reference (or the source you chose), prefixed and capped at 13 characters |
| `Date` | The order date, or the push date |
| `IsTaxInclusive` | See [Tax](configuration#tax) |
| `Lines` | One per line item, plus one per cart-level adjustment |
| `Freight`, `FreightTaxCode` | The order's shipping, plus any tax not attributed to a line item |
| `ShipToAddress` | The shipping address |
| `CustomerPurchaseOrderNumber` | The order reference, always, so the invoice can be traced back to Craft even without My's own records |
| `JournalMemo` | Your journal memo template, by default `Craft order 1042` |
| `Comment` | Your invoice comment template, if any |
| `InvoiceDeliveryStatus` | Your delivery status setting |
| `ForeignCurrency` | The order's currency code, when it differs from the store's |

Each line's amount comes from Commerce's own line total, with only shipping (which goes in
`Freight`) and, when posting tax-exclusive, tax taken back out. Lines aren't rebuilt from the
subtotal and the discount: Commerce's adjusters are open-ended, and rebuilding from known types
would drop a third-party surcharge or levy and invoice the customer for less than they paid.

Lines worth zero are left out. Cart-level adjustments become lines of their own: a discount is
described as **Discount** and posts to the discount account; anything else uses its own name and
posts to the sales account.

`Subtotal`, `TotalTax` and `TotalAmount` are **never sent**. MYOB computes them from the lines. A
payload stating a total that disagreed with its own lines is how invoices end up quietly wrong.

### Preview

The **Preview** button on the order's MYOB panel shows the exact JSON and endpoint My would post,
and whether its total agrees with the order. It's built by the same code that does the real push,
so what it shows is what MYOB would receive. `php craft my/sync/order <reference> --dryRun` prints
the same thing.

A preview is read-only. It creates and updates nothing in MYOB, customer cards included. If the
order's customer has no card yet, `Customer` shows the placeholder UID `new-card-created-on-push`;
the real push creates the card and sends its UID instead. Everything else is byte-for-byte what
would be sent.

## Reconciliation

My checks the invoice total twice.

**Before sending**, it adds up the payload the way MYOB will and compares that with
`Order::getTotalPrice()`. Posting tax-inclusive, the two match exactly by construction. If they
differ by more than the **Rounding tolerance** (1 cent by default), **When totals disagree**
decides:

| Setting | Result |
|---|---|
| **Refuse to push the order** (default) | Nothing is sent. The invoice is marked failed with *The invoice would book 108.90 but the order is 110.00. Nothing was sent.* |
| **Add a rounding line** | A line for the difference goes to the rounding account with the zero-rated tax code, and a warning is logged |
| **Push anyway and log a warning** | The invoice is sent as built, and a warning is logged |

Refusing is the default on purpose. An invoice for the wrong amount is harder to find, and much
harder to undo, than a push that failed and told you why. A refusal means the order's numbers don't
add up the way My expects. The usual cause is an adjustment in Commerce that points at a line item
no longer on the order. My doesn't absorb that into a line, because it's a data problem in
Commerce, and hiding it would make the invoice look right while leaving the cause in place.

**After sending**, My compares the `TotalAmount` MYOB actually booked with what the customer paid.
This catches the case where the payload was perfect and MYOB still booked a different figure,
which almost always means a tax code mapped to a different rate than Commerce charged. The invoice
is in MYOB by then, so My marks it synced, but keeps the message on the document, shows it in the
order's MYOB panel, and logs an `invoice.mismatch` warning: *MYOB booked 121.00 but the order was
110.00. This is usually a tax code mapped to the wrong rate.* Fix the mapping, then correct that
invoice in MYOB.

## Payments

Every successful Commerce **purchase** or **capture** becomes a MYOB customer payment applied to
the order's invoice. An **authorisation** is skipped: the money hasn't moved yet, and booking it
would overstate the bank balance. When the capture arrives, that's pushed.

- One MYOB payment per Commerce transaction, so an order paid in two parts gets two payments.
- The deposit account and payment method come from the [Payments](configuration#payments)
  settings.
- The memo reads *Payment for order 1042 — Stripe — ch_3Nx…*: the order, the gateway, and the
  gateway's reference.
- A payment carries the invoice's `RowVersion`. If someone touched the invoice in MYOB since it was
  created, MYOB answers `409`. My re-reads the invoice and tries once more with the current
  version, rather than failing a payment that's perfectly valid.

## Refunds and credit notes

MYOB has no refund on a sale. A refund is a **credit note** (an invoice with negative amounts)
and, optionally, a **credit refund**, which records the money leaving a bank account.

- **A full refund** (the whole order total) mirrors the invoice line for line, every amount
  reversed.
- **A partial refund** becomes a single line, *Partial refund of order 1042*, for the refunded
  amount, posted to the sales account with the order's tax treatment. Commerce records a refunded
  amount, not which items came back, and My doesn't pretend to know.
- With **Also record the money going back out**, the credit note is then paid out of the refund
  account as a credit refund.
- Credit notes are numbered with `CR` after your prefix: `CR1042` for the order's first refund,
  `CR2-1042` for the second, `CR3-1042` for the third. The count follows the order of the refund
  transactions, so a retry rebuilds the same number, and the result is capped at 13 characters
  like any invoice number.

Refunds are only pushed for orders whose invoice is already in MYOB. A credit note against an
invoice that doesn't exist would just be an unexplained negative balance.

## The ledger

Every document My sends gets a row in the sync ledger: **MYOB → Documents**. Filter by type
(invoice, payment, credit note, credit refund) and status. Each row links to the order and to a
detail page showing **What was sent** and **What MYOB said**.

| Status | Means |
|---|---|
| **Synced** | MYOB has it. The row holds MYOB's UID and number |
| **Pending** | My claimed the row and is sending it, or a temporary failure is waiting for the queue to retry |
| **Failed** | MYOB refused it, or My refused to send it. The error is on the row. It waits for a human |

### Nothing is sent twice

The ledger has a unique key on **order, document type and source**. The source is `order` for the
invoice and the Commerce transaction hash for a payment or refund. So an order has one invoice,
each transaction has one payment, and two queue workers racing on the same order end up writing
one row.

My **claims the row before** sending anything, and fills it in afterwards. That ordering is what
makes recovery possible.

### Recovery after an interrupted push

If a request reaches MYOB but the response never gets back (a timeout, a crashed worker), the row
is left **pending**. The next attempt doesn't send the invoice again blind. It first asks MYOB
whether the invoice is already there, most certain check first:

1. **By UID**, if the ledger row already holds one. If MYOB answers `404` (someone deleted the
   invoice in MYOB), My moves on to the next checks.
2. **By `Number`**, when My sends its own invoice numbers.
3. **By `CustomerPurchaseOrderNumber`**, which carries the order reference on every invoice. This
   is how an invoice is found when MYOB assigns the number itself.

If one exists, My links it and logs *Recovered invoice 1042* instead of creating a second. Only if
none finds it is the invoice sent.

Each order is also pushed by one worker at a time. Completing an order usually queues several jobs
(the completion, the payment, a status change). A job that finds another push of the same order
already running gives way with *Another push for this order is already running*, and the queue
retries it.

## The order panel

Commerce's order edit screen gets a **MYOB** panel listing each document for the order, its MYOB
number and status, and any error. Users with the **Push orders to MYOB** permission also see:

- **Push to MYOB.** Pushes now, while you wait, rather than through the queue, and tells you what
  happened.
- **Push again.** Shown once the order is invoiced. It runs the whole push again, through the
  same [recovery](#recovery-after-an-interrupted-push) checks: it finds the existing invoice in
  MYOB and re-links it rather than creating another, whatever your invoice numbering, then sends
  any payments or credit notes that are missing. If the invoice was deleted in MYOB, a new one is
  sent.
- **Preview.** Shows the payload. See [Preview](#preview).

## Unlinking

On a document's detail page, **Unlink from MYOB** (with the **Unlink documents from MYOB**
permission) deletes the ledger row. It deletes nothing in MYOB. An invoice that's been reconciled
against a bank feed can't be deleted there anyway.

After unlinking, My treats the document as never sent, and the next push creates it again. Unlink
when you've deleted or voided the document in MYOB yourself and want My to send a fresh one, not
as a way to tidy the list.

## The log

**MYOB → Log** lists every request My makes: the action (`invoice.create`, `payment.create`,
`contact.find`, `oauth.refresh`…), method, endpoint, status code, duration, the order it was for,
and a summary. Filter by action and level. Each entry shows the request and response bodies.

- **Credentials are redacted before anything is written.** `access_token`, `refresh_token`,
  `client_secret`, `password`, `Authorization` and `x-myobapi-cftoken` are replaced with
  `[redacted]`, in JSON bodies, form bodies and header lines. For an `Authorization` header the
  scheme is kept and only the credential is replaced, so `Bearer [redacted]` still tells you which
  kind it was.
- Bodies over 64 KB are truncated, after redaction.
- Turn bodies off with **Log payloads**, or the whole log with **Log requests**.
- Entries older than **Log retention** days are pruned on Craft's garbage collection, or with
  `php craft my/log/prune`.
- **Clear the log** empties it, for users with the **Clear the connection log** permission.

MYOB's errors come as `Message` plus `AdditionalDetails`. The message is usually *Invalid data*;
the detail says which field. My keeps both, everywhere it shows an error.

## Permissions

| Permission | Allows |
|---|---|
| **View synced documents** | The Documents screen and the MYOB panel on orders |
| ↳ **Push orders to MYOB** | Push, Push again and Preview on the order panel |
| ↳ **Unlink documents from MYOB** | Unlink on a document's detail page |
| **View the connection log** | The Log screen |
| ↳ **Clear the connection log** | The **Clear the log** button on the Log screen, which is hidden without it. Reading the log and erasing it are separate powers |

Settings, connecting and disconnecting need an admin.

The MYOB permissions say what a user may do with MYOB, not which orders they may see. Pushing,
previewing and opening a document's detail page also need permission to view that order in
Commerce, because each one shows the customer's details.

## Twig

`craft.myob` is read-only. It's for showing an invoice number on a customer's order page, not for
pushing anything: a template render is no place to write to an accounting system.

```twig
{% if craft.myob.isInvoiced(order) %}
    <p>Invoice {{ craft.myob.invoice(order).myobNumber }}</p>
{% endif %}

{% for document in craft.myob.documents(order) %}
    {{ document.getTypeLabel() }}: {{ document.getStatusLabel() }}
{% endfor %}
```

| Method | Returns |
|---|---|
| `craft.myob.invoice(order)` | The order's invoice document, or `null`. Takes an order or an order ID |
| `craft.myob.documents(order)` | Every document for the order: invoice, payments, credit notes, credit refunds |
| `craft.myob.isInvoiced(order)` | `true` once the invoice is synced |
| `craft.myob.isConnected()` | Whether a company file is connected |
| `craft.myob.companyFile()` | The connected company file's name, or `null` |

A document has `myobNumber`, `myobUid`, `amount`, `currency`, `status`, `dateSynced` and
`lastError`, plus `getTypeLabel()`, `getStatusLabel()` and `isSynced()`.
