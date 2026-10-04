---
title: Configuration
slug: configuration
order: 20
summary: Connecting a cloud or local AccountRight company file, choosing the file, and every setting for invoices, tax, customers, payments, refunds and the log.
---

Everything lives on **MYOB → Settings** (Craft's plugin settings screen for My). Nothing on it is
marked required, so you can save a half-finished screen. The one exception is the sales account,
described below.

## Connecting

The first setting, **Where MYOB lives**, decides how My reaches the company file.

| Mode | Use it for | How My authenticates |
|---|---|---|
| **MYOB cloud** (AccountRight Live / MYOB Business) | A company file hosted by MYOB | OAuth 2 with your app's API key and secret, plus the company file login |
| **AccountRight local server** | A company file opened by the AccountRight API service on a machine you run | HTTP Basic with the company file login. No API key, no OAuth |

### Cloud: register an app

1. Sign in at [developer.myob.com](https://developer.myob.com) and register an app. MYOB gives you
   an **API key** and an **API secret**.
2. On My's settings screen, copy the **Redirect URL**. It is Craft's action URL for
   `my/oauth/callback`, so it looks like `https://example.com/actions/my/oauth/callback`, or
   `https://example.com/index.php?p=actions/my/oauth/callback` if you don't omit the script name.
3. Register that URL against the app on developer.myob.com. **MYOB matches it character for
   character**: scheme, host, path and trailing slash. A mismatch doesn't fail at the redirect.
   It fails at the token step, with `invalid_request`.
4. Paste the API key and secret into **API key** and **API secret**. Both accept environment
   variables, e.g. `$MYOB_API_KEY`. Save.
5. Press **Connect to MYOB**. You'll sign in to MYOB, approve the app, and be sent back to the
   settings screen.

Connecting needs an admin account, and the browser that comes back from MYOB has to be the one
that left: My checks a one-time `state` value against your session.

### Local: point at the server

Set **AccountRight server URL** to where the AccountRight API service listens. The default is
`http://localhost:8080/accountright/`. It must be an `http://` or `https://` URL, and a company
file chosen from the list must live on that same host and port.

Craft has to be able to reach that address. A server on the bookkeeper's desktop is not reachable
from a hosted website, and `localhost` means the Craft server itself. Local mode suits a Craft site
on the same network as the AccountRight machine.

There's no **Connect** button in local mode. Fill in the company file login, choose the file, and
press **Test connection**.

### The company file login

**Company file username** and **Company file password** are the login for the company file
itself: the user you'd sign in as when opening the file in AccountRight. They are **not** your MYOB
account email and password. MYOB rejects either one being wrong with the same `401`, so it's worth
checking both.

- The username defaults to `Administrator`.
- Leave the password blank if the file user has none.
- If the file has no user-level security at all, clear both fields. My then sends no company file
  token, which is what MYOB expects. An empty token is not the same thing.

Both fields accept environment variables. In cloud mode they're sent as the `x-myobapi-cftoken`
header; in local mode they're the HTTP Basic credentials.

### Choosing the company file

Under **Company file**:

- **List company files** asks MYOB which files this connection can see. Press **Use this file**
  next to the right one. My saves it, then verifies the credentials actually open it. In cloud
  mode the file's address must be `https://` on `myob.com`; anything else is refused, because
  every request sends your tokens there.
- **Company file ID** takes the file's ID directly. Anything typed here wins over the file chosen
  from the list.

**An empty list is normal for newer API keys.** MYOB stopped letting API keys issued after
12 March 2025 list company files. For those keys, MYOB sends the company file ID back on the OAuth
redirect, and My selects and verifies it for you as you return from **Connect to MYOB**. If that
didn't happen, paste the ID into **Company file ID**.

**Company file ID** is also how you pin the file per environment. Set it to an environment variable
(`$MYOB_COMPANY_FILE_ID`) and a database copied from production to staging can't quietly start
invoicing the production file, or the other way round.

### Test connection

**Test connection** reads the company file root and reports the file name and the user it
connected as, e.g. *Connected to Clearwater Pty Ltd as Administrator.* It's the only check that
proves the **company file** login is right, as opposed to the OAuth one.
`php craft my/connection/test` does the same from the command line.

### Where the connection is stored

Tokens and the chosen company file live in the `my_connection` table, **not** in project config.
They are secrets, and MYOB access tokens last twenty minutes, so the row is rewritten several
times an hour. My refreshes the token itself, two minutes before it expires, under a lock so two
queue workers can't refresh at once and invalidate each other.

This means each environment connects separately. The settings (accounts, tax codes, mappings)
travel in project config as usual; the connection does not.

**Disconnect** deletes the connection row and forgets the cached accounts and tax codes. Nothing in
MYOB changes.

### Connecting where admin changes are off

With `allowAdminChanges` off, the settings screen isn't available. You can still connect:

1. Put the API key, secret, company file login and **Company file ID** in environment variables,
   referenced from the settings saved in your development environment.
2. Signed in as an admin, visit the connect action directly:
   `https://example.com/actions/my/oauth/connect`.
3. After approving in MYOB you'll be redirected to the settings URL, which won't load. The
   connection is saved anyway.
4. Confirm with `php craft my/connection/test`.

## Invoices

| Setting | Default | What it does |
|---|---|---|
| **Push orders automatically** | On | Off stops every automatic push. The order panel's push button and the console still work |
| **Invoice orders in these statuses** | none ticked | Nothing ticked: every order is invoiced as soon as it completes. Tick statuses to invoice only when an order reaches one of them, e.g. `shipped` |
| **Invoice layout** | Service | Service or Item. See [Service or Item](#service-or-item) |
| **When a SKU is not in MYOB** | Post to the sales account | Item layout only. Post the line to the sales account instead, or refuse to push the order |
| **Invoice number** | Order reference | Order reference, short order number, full order number, order ID, or let MYOB assign it |
| **Number prefix** | blank | Up to 8 characters, put in front of the number |
| **Invoice date** | Date the order was placed | Or the date it was pushed |
| **Journal memo** | `Craft order {{ object.reference }}` | An object template rendered against the order. `element` works as well as `object`. Capped at 255 characters |
| **Invoice comment** | blank | Shown on the invoice. An object template; blank sends none |
| **Delivery status** | Already printed or sent | What MYOB thinks still has to happen to the invoice. If Craft already emailed the customer, *already sent* keeps MYOB's to-do list honest |

### Invoice numbers

MYOB caps invoice numbers at **13 characters**. If prefix + number is longer, My trims the
**front** of the order number and keeps the end, so consecutive orders still get different
numbers. Credit notes get `CR` after the prefix, plus a count from the second refund on: `WEB-` +
`1042` gives `WEB-1042` for the invoice, `WEB-CR1042` for its first credit note and `WEB-CR2-1042`
for its second. The same 13-character cap applies.

**Let MYOB assign it** works, but it isn't the default. If a push is interrupted after MYOB created
the invoice but before My heard back, My looks for it by the invoice number it sent. With no number
of its own, it falls back to the order reference in the invoice's customer PO number, which is
less specific. See [Recovery](usage#recovery-after-an-interrupted-push).

### Service or Item

MYOB has two sale invoice layouts.

| | Service | Item |
|---|---|---|
| Each line posts to | an account (the **Sales account**) | an inventory item, matched by SKU |
| Needs anything in MYOB first | No | Every SKU you sell, as an item with that number |
| Moves stock and costs goods | No | Yes |
| Quantity | Folded into the description: `2 × Linen apron (APR-01)` | Sent as `ShipQuantity`, with the unit price derived from the line total |

Service is the default, because most Commerce catalogues aren't mirrored in the company file.

In Item layout, My looks each SKU up as a MYOB item number. A line whose SKU has no item either
becomes an account line on the same invoice (the default) or stops the push with *No MYOB inventory
item matches the SKU "…"*. It is never dropped, because that would invoice the customer for less
than they paid. Check a SKU with `php craft my/reference/item <sku>`.

### Accounts

Account fields take MYOB account codes (`DisplayID`) such as `4-1000`. List them with
`php craft my/reference/accounts`.

| Setting | Used for |
|---|---|
| **Sales account** | Every product line on a service invoice, item lines with no matching item, cart-level adjustments that aren't discounts, and partial-refund credit notes. **Required** while automatic pushing is on and the layout is Service: the screen won't save without it |
| **Discount account** | Cart-level discounts. Blank uses the sales account |
| **Rounding account** | The rounding line, when **When totals disagree** is set to add one. Blank uses the sales account |
| **Freight account** | Not currently applied. Shipping is sent in MYOB's own `Freight` field, and MYOB posts it to the freight account linked in the company file (AccountRight's linked accounts for sales freight) |

My caches the chart of accounts and the tax codes for 24 hours, because they rarely change and
looking them up on every invoice would spend the rate limit. After changing accounts in MYOB, press
**Refresh accounts and tax codes from MYOB**, or run `php craft my/reference/refresh`.

## Tax

| Setting | Default | What it does |
|---|---|---|
| **Prices include tax** | Follow the order | See below |
| **Default tax code** | `GST` | Lines that were taxed and whose tax category has no mapping |
| **Freight tax code** | `GST` | Sent as `FreightTaxCode` with the freight amount |
| **Zero-rated tax code** | `FRE` | Lines Commerce charged no tax on, when their tax category has no mapping |
| **Tax category mapping** | blank | One MYOB tax code per Commerce tax category. Blank falls back to the default (or zero-rated) code |
| **Rounding tolerance** | 1 | How far, in cents, the invoice may drift from the order total before **When totals disagree** applies |
| **When totals disagree** | Refuse to push the order | Refuse, add a rounding line, or push anyway and log a warning |

Tax codes are MYOB `Code` values. List them, with their rates, using
`php craft my/reference/tax-codes`. A code that doesn't exist in the company file stops the push
with *MYOB has no tax code "…"*.

**Prices include tax.** *Follow the order* posts the invoice tax-inclusive if Commerce included any
tax in the order's prices, tax-exclusive if Commerce added tax on top, and tax-inclusive if there
was no tax at all. You can force either.

Tax-inclusive is the safer choice: the invoice total is then exactly what the customer paid,
whatever rate MYOB has on the code. Posting tax-exclusive lets MYOB compute the tax from its own
codes, which can disagree with Commerce if a code is mapped to the wrong rate. My checks for that
after the push. See [Reconciliation](usage#reconciliation).

A discount line carries the default tax code if the order was taxed and the zero-rated code if it
wasn't, so the discount reduces tax the same way the goods attracted it.

## Customers

| Setting | Default | What it does |
|---|---|---|
| **Create customer cards** | On | Off posts every order against the default card |
| **How to identify customers** | A card per email address | Or one card for everything, which suits high-volume retail |
| **Default customer UID** | blank | The card used in single-card mode, when card creation is off, and for guest orders with no email address |
| **Default customer name** | blank | If no UID is set, My finds a company card with this name, or creates one |
| **Card ID template** | blank | An object template for a new card's MYOB card ID (15 characters). Blank lets MYOB assign one |
| **Update existing cards** | Off | Overwrite an existing card's addresses from each new order. Off by default, because the bookkeeper's version usually beats what a customer typed at checkout |
| **Phone field handle** | blank | Craft 5 addresses have no phone attribute. Name the custom field on your addresses that holds one |

How My finds a card, in order:

1. Its own map of email address → MYOB card, built up as it goes.
2. MYOB, searching every customer card's addresses for the order's email.
3. If neither finds one, it creates a card and remembers it.

So a company file that has been trading for years doesn't end up with a second card for every
returning customer, as long as their email is on their existing card.

A new card is a **company** card when the billing address has an organisation, and an
**individual** card otherwise. Updating an existing card never switches it between the two. If an
update fails, it's logged as a warning and the invoice still goes.

If an order needs the default card and neither **Default customer UID** nor **Default customer
name** is set, the push stops with *No default MYOB customer is set.*

## Payments

| Setting | Default | What it does |
|---|---|---|
| **Record payments** | On | Apply each successful Commerce payment to the MYOB invoice |
| **Deposit to** | Undeposited funds | Or straight into a bank account |
| **Bank account** | blank | The account code money goes into when depositing to an account |
| **Default payment method** | Other | The MYOB payment method for any gateway without a mapping |
| **Gateway mapping** | blank | One MYOB payment method per Commerce gateway |

MYOB accepts only its own payment methods: Cash, Cheque, EFTPOS, Money Order, Visa, MasterCard,
American Express, Diners Club, Bank Card, Barter Card and Other. Anything else is rejected by the
company file, so the mapping only offers those, and an unmapped gateway gets the default rather
than its own name.

## Refunds

| Setting | Default | What it does |
|---|---|---|
| **Raise credit notes** | On | A Commerce refund becomes a MYOB credit note |
| **What to do with the credit** | Leave it on the customer's account | Or also record the money going back out, as a MYOB credit refund |
| **Refund account** | blank | Where refunded money leaves from. Blank uses the payments **Bank account** |

## Advanced

| Setting | Default | What it does |
|---|---|---|
| **Requests per second** | 6 | 1–8. MYOB allows 8 per second per API key. My spaces requests out within a process and retries a `429` |
| **Timeout** | 30 | Seconds to wait on a MYOB request, 1–300 |
| **Queue attempts** | 5 | How many times the queue tries a push before it waits for a human, 1–20 |
| **Log requests** | On | Write the connection log |
| **Log payloads** | On | Keep request and response bodies in the log. Credentials and tokens are redacted first |
| **Log retention** | 30 | Days of log to keep. 0 keeps everything. Pruned on Craft's garbage collection |

## Config file

Every setting can be set in `config/my.php`, which overrides the settings screen, and can vary
per environment:

```php
<?php

use craft\helpers\App;

return [
    '*' => [
        'mode' => 'cloud',
        'clientId' => '$MYOB_API_KEY',
        'clientSecret' => '$MYOB_API_SECRET',
        'cfUsername' => '$MYOB_CF_USERNAME',
        'cfPassword' => '$MYOB_CF_PASSWORD',
        'companyFileId' => '$MYOB_COMPANY_FILE_ID',
        'salesAccount' => '4-1000',
        'taxCodeMap' => ['general' => 'GST', 'exempt' => 'FRE'],
        'paymentMethodMap' => ['stripe' => 'Visa'],
    ],
    'dev' => [
        'syncEnabled' => App::env('MYOB_SYNC') === 'on',
    ],
];
```

`clientId`, `clientSecret`, `cfUsername`, `cfPassword` and `companyFileId` resolve `$ENV_VAR`
strings themselves. For any other setting, read the variable with `App::env()`.

A few settings are only available here:

| Setting | Default | What it does |
|---|---|---|
| `requireCompletedOrder` | `true` | Never invoice an order that isn't completed, whatever the status filter says |
| `categoryUid` | blank | A MYOB category UID stamped on every invoice |
| `salespersonUid` | blank | A MYOB salesperson (employee card) UID stamped on every invoice |
| `discountDescription` | `Discount` | The description on cart-level discount lines |
| `roundingDescription` | `Rounding` | The description on the rounding line |
