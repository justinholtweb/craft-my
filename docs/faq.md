---
title: FAQ
slug: faq
order: 60
summary: Common questions about connecting Craft Commerce to MYOB AccountRight with My.
---

### What does My do?

It turns completed Craft Commerce orders into MYOB sale invoices, against a customer card it finds
by email or creates. It applies each Commerce payment to the invoice, and turns refunds into credit
notes. Every document is recorded in a ledger so nothing is sent twice, and every request is in a
log you can read.

### Which MYOB products does it work with?

MYOB AccountRight, through the MYOB Business API: company files in the cloud (AccountRight Live /
MYOB Business, connected over OAuth) and company files opened by an AccountRight local server on
your own network.

### What about MYOB Acumatica (MYOB Advanced) or MYOB Exo?

My doesn't connect to those. They're different products with different APIs. They're covered by
[Erpy for MYOB](/plugins/craft-erpy/docs/myob), a free add-on for the Erpy ERP gateway.

### Which Craft, Commerce and PHP versions are supported?

Craft CMS 5.3+, Craft Commerce 5.0+ and PHP 8.2+.

### How much does it cost?

$99 per Craft installation, then $79 a year for updates. There's one edition with everything in it.

### What's the licence?

My is licensed under the Craft License. Each licence covers one production install. See
`LICENSE.md`.

### Does checkout wait for MYOB?

No. Every automatic push is a queue job. If MYOB is down or slow, the invoice goes later; the
customer's order completes as normal.

### Can it send the same order twice?

Not by itself. The ledger has a unique key per order and document, and My claims the row before it
sends anything. If a push was interrupted after MYOB created the invoice, the next attempt asks
MYOB for that invoice before sending, and links the existing one. **Push again** does the same,
so it re-links rather than duplicates, even when MYOB assigns the invoice numbers. See
[Usage](usage#nothing-is-sent-twice).

The one way to get a second invoice is deliberate: **Unlink** a document, then push the order
again.

### Will it create duplicate customer cards?

It looks for an existing card by email address before creating one, and remembers the match. A
returning customer whose email is on their MYOB card gets that card.

### How do I make sure MYOB books what the customer paid?

My never sends a total. It sends lines and lets MYOB add them up, checks that sum against the order
before sending, and checks what MYOB booked afterwards. By default, a payload that doesn't add up to
the order total isn't sent. See [Reconciliation](usage#reconciliation).

### Will I know if pushes stop working?

Yes. Set recipients under **Alerts** and My emails them (and optionally Slack or Teams) when pushes
start failing, when MYOB books an invoice at a different total, or when MYOB refuses the
connection — once when it starts and once when it clears. A dead refresh token also puts a banner
across the control panel. A weekly sync summary and a **MYOB health** Dashboard widget show the
same picture. See [Alerts and the sync summary](alerts.md).

### Can I see what will be sent before it's sent?

Yes. **Preview** on the order's MYOB panel, or `php craft my/sync/order <reference> --dryRun`. Both
use the same code as the real push, so what they show is what MYOB receives. Both are read-only:
nothing is created or changed in MYOB. A customer with no card yet shows as the placeholder
`new-card-created-on-push`, which the real push replaces with the card it creates.

### Can I invoice orders from before I installed it?

Yes. `php craft my/sync/backfill` pushes every completed order that has no invoice in MYOB, oldest
first, 100 at a time by default.

### Do my products have to exist in MYOB?

Not with the default **Service** layout, which posts every line to a sales account. The **Item**
layout matches each SKU to a MYOB inventory item, for stock and cost of goods, and either falls back
to the sales account or refuses the push when a SKU isn't there.

### Does it handle GST?

Tax is taken per line from Commerce's own adjustments and sent with the MYOB tax code you map each
Commerce tax category to. Lines Commerce didn't tax get your zero-rated code, so MYOB doesn't add
GST that was never charged.

### Does it delete anything in MYOB?

No. Unlinking and disconnecting only change what Craft remembers. Uninstalling drops My's own
tables and leaves MYOB untouched.

### Can it connect to more than one company file?

One company file per Craft install. Set **Company file ID** per environment if staging and
production should use different files.

### Does it bring stock levels or products back from MYOB?

No. My sends sales to MYOB. It doesn't sync inventory, products or purchase orders back into
Commerce.

### Where are the MYOB tokens stored?

In My's own database table, not in project config. They're secrets, and MYOB replaces them every
twenty minutes.

### Is My made by MYOB?

No. My is an independent plugin built by Justin Holt, using MYOB's public API. It isn't affiliated
with, endorsed by or sponsored by MYOB.
