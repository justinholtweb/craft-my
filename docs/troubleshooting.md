---
title: Troubleshooting
slug: troubleshooting
order: 50
summary: What each error means and what to do about it, from connecting to MYOB to an invoice that booked the wrong total.
---

Start with **MYOB → Log**. Every request My makes is there with MYOB's reply, and MYOB's
`AdditionalDetails` usually names the exact field it objected to. The error on a failed document,
on **MYOB → Documents** or in the order's MYOB panel, is the same message.

## Connecting

### "MYOB rejected the credentials. Either the connection needs re-authorising, or the company file username and password are wrong"

MYOB answers a bad OAuth token and a bad company file login with the same `401`, so My names both.

- Check **Company file username** and **Company file password**. They're the login for the
  **company file** (often `Administrator`), not the email and password you sign in to MYOB with.
- If the file has no user-level security, clear both fields rather than leaving the username as
  `Administrator`.
- If the company file login is right, press **Reconnect** to re-authorise.

### "MYOB rejected the token request. Check that the redirect URI registered on developer.myob.com matches this site exactly."

MYOB's `invalid_request`. The redirect URL registered on your app doesn't match the one My sent,
character for character. Copy **Redirect URL** from the settings screen and paste it into the app
on developer.myob.com. Watch for `http` against `https`, `www.`, a trailing slash, and
`index.php?p=` when `omitScriptNameInUrls` is off.

### "MYOB rejected the API key and secret."

MYOB's `invalid_client`. The **API key** or **API secret** is wrong, or an environment variable
they reference isn't set in this environment.

### "MYOB rejected the refresh token. This happens when the connection is authorised again elsewhere, revoked, or the MYOB password changes"

MYOB's `invalid_grant`. MYOB issues a new refresh token on every refresh and the old one stops
working. The usual causes:

- The same API key was connected from another environment, e.g. staging after production. Each
  connection invalidates the last. Use a separate app per environment, or reconnect.
- The connection was revoked, or the MYOB account password changed.

Press **Reconnect**.

### "The MYOB authorisation did not come back the way it left. Try connecting again."

The `state` My stored in your session before sending you to MYOB didn't match the one that came
back. Usually the session was lost: you started on one hostname and the redirect URL points at
another, or you signed out in between. Start **Connect to MYOB** from the same hostname as the
redirect URL.

### "MYOB refused the authorisation: …"

You, or MYOB, declined on MYOB's approval screen. The text after the colon is MYOB's reason.

### "Enter your MYOB API key and secret first."

**Connect to MYOB** was pressed with no API key or secret saved. Save them first.

### "MYOB returned no company files. Newer API keys are not allowed to list them"

Expected for API keys issued after 12 March 2025. MYOB sends the company file ID on the OAuth
redirect for those, and My uses it as you come back from connecting. If it didn't, paste the ID into
**Company file ID** and press **Test connection**.

### "No company file has been chosen yet." / "No MYOB company file is connected."

Connected to MYOB, but no file is selected. Choose one under **Company file**, or set
**Company file ID**.

### "MYOB has no company file with that ID on this account."

A `404` on the company file. The ID is wrong, or belongs to a different MYOB account than the one
you authorised. If **Company file ID** is set from an environment variable, check its value in this
environment.

### "MYOB is not connected. Reconnect on the settings screen."

There's no refresh token: the connection was never completed, or it was disconnected. Press
**Connect to MYOB**.

### "MYOB returned a non-JSON response (…). Check that the API URL is right."

Something other than the MYOB API answered: a proxy, a login page, or the wrong port. In local
mode, check **AccountRight server URL** and that the AccountRight API service is running. Open the
URL from the Craft server itself (`curl http://localhost:8080/accountright/`) to see what answers.

### "Enter the URL of your AccountRight server, e.g. http://localhost:8080/accountright/"

Shown on the settings screen in local mode. **AccountRight server URL** must be a full `http://`
or `https://` URL. Other schemes (`file://`, `ftp://`…) are refused, because every request carries
the company file credentials.

### "That company file address is not a MYOB address, so it was not saved."

My sends your tokens and company file login to the address stored with the company file, so it
only accepts a safe one: in cloud mode, `https://` on a `myob.com` host; in local mode, `http://`
or `https://` on the same host and port as **AccountRight server URL**. In local mode, this usually
means the server reports its files under a different hostname than the one you entered (e.g.
`localhost` against the machine's name). Make **AccountRight server URL** use the host the server
reports, and choose the file again.

### The local server can't be reached

A timeout or connection refused in local mode means the Craft server can't reach the AccountRight
machine. `localhost` is the Craft server, not the bookkeeper's computer. A hosted Craft site can't
reach a desktop server on an office network; use the cloud mode instead.

## Orders not going to MYOB

Run `php craft my/sync/status` first. Then check, in order:

- **Push orders automatically** is on.
- The order is completed, and, if you ticked statuses under **Invoice orders in these statuses**,
  it's in one of them. Nothing happens until it reaches that status.
- **The queue is running.** Every automatic push is a queue job. Look under **Utilities → Queue
  Manager** for **Pushing order … to MYOB** jobs.
- Commerce is installed and enabled.

To see what would be sent without waiting, press **Preview** on the order's MYOB panel, or run
`php craft my/sync/order <reference> --dryRun`.

### "Another push for this order is already running. Try again in a moment."

Pushes are serialised per order: completing an order often queues more than one job (the
completion, the payment, a status change), and only one may talk to MYOB about that order at a
time. The other gives way and the queue retries it. From the order panel, wait a moment and press
the button again.

## Invoice errors

### "The invoice would book … but the order is …. Nothing was sent."

The pre-send [reconciliation](usage#reconciliation) failed and **When totals disagree** is set to
refuse, so My sent nothing. The order's lines, shipping and adjustments don't add up to its total
the way My expects.

- Run `php craft my/sync/order <reference> --dryRun` and compare the lines with the order.
- The usual cause is an adjustment in Commerce that belongs to a line item no longer on the order.
  That's a Commerce data problem to fix on the order. My doesn't paper over it.
- A difference of a few cents from rounding: raise **Rounding tolerance**, or set **When totals
  disagree** to add a rounding line.

### "MYOB booked … but the order was …. This is usually a tax code mapped to the wrong rate."

The invoice went through and is in MYOB, but MYOB's total isn't what the customer paid. The payload
was right; MYOB applied a different tax rate than Commerce did. Run
`php craft my/reference/tax-codes`, compare the rates with your Commerce tax rates, and fix the
**Tax category mapping**. Then correct the existing invoice in MYOB. Posting tax-inclusive avoids
this class of problem altogether.

### "MYOB has no account "…". Check the sales account on the settings screen."

The account code isn't in the company file, or the cached chart of accounts is out of date. Check
it with `php craft my/reference/accounts`, then press **Refresh accounts and tax codes from MYOB**.
The same goes for *…which is needed for the "Discount" line*, *…to bank payments into*,
*…to refund from*, and *…which is needed to raise a credit note*: each names the account and the
setting it came from.

### "MYOB has no tax code "…"."

A tax code in your settings or mapping isn't in the company file. Codes are case-sensitive MYOB
`Code` values like `GST`, `FRE`, `N-T`. List them with `php craft my/reference/tax-codes`.

### "No MYOB inventory item matches the SKU "…"."

Item layout, with **When a SKU is not in MYOB** set to refuse. Create the item in MYOB with that
exact item number, or switch the fallback to post the line to the sales account. Check a SKU with
`php craft my/reference/item <sku>`.

### "No default MYOB customer is set."

The order needs the default card (single-card mode, card creation off, or a guest order with no
email) and neither **Default customer UID** nor **Default customer name** is set.

### "Order … has nothing to invoice."

Every line on the order is worth zero, and there are no cart-level adjustments. There's nothing to
put on an invoice.

### "Invalid data — …"

A `400` from MYOB. The part after the dash is MYOB's `AdditionalDetails` and names the field. Open
the document's detail page to see **What was sent** next to **What MYOB said**.

### "The company file user is not allowed to do that."

A `403`. The company file user doesn't have access to sales, cards or banking in AccountRight. Give
that user the access in AccountRight's user setup, or use a user that has it.

### "MYOB rate limit exceeded."

A `429` that kept coming back after My's own retries. Lower **Requests per second**, especially if
other integrations share the same API key, and let the queue retry.

## Payments and refunds

### A payment didn't appear in MYOB

- **Record payments** is on.
- The transaction is a successful purchase or capture. Authorisations aren't booked until they're
  captured.
- The invoice is synced. Payments wait for it.
- On a `409` (*The record changed in MYOB since it was read*), My re-reads the invoice and retries
  once. If it still fails, someone is editing that invoice in MYOB; push the order again once
  they've finished.

### A refund didn't become a credit note

- **Raise credit notes** is on.
- The order's invoice was in MYOB when the refund happened. Refunds on uninvoiced orders are
  skipped; push the order and the credit note goes with it.

## Customers

### Duplicate customer cards

My looks for an existing card by the email address on the card's addresses in MYOB. A card with no
email, or a different one, won't be found, and My creates a new card. Add the customer's email to
their existing card in MYOB. Already-created duplicates can be merged in AccountRight.

### Addresses on existing cards are being overwritten

**Update existing cards** is on. Turn it off to leave existing cards alone.

## Alerts and the sync summary

- **No alert arrived.** Press **Send a test alert** (or run `php craft my/alerts/test`). If the
  test arrives, check **MYOB health** on the Dashboard: an incident that is already open does not
  alert again until it clears. An environment with no company file connected checks nothing.
- **"That host doesn't resolve, or resolves to a private, loopback or link-local address."** The
  webhook URL points somewhere My refuses to post to. A Mattermost on your own network needs
  `allowPrivateAlertWebhookHosts` in `config/my.php`.
- **"Save some recipients or a webhook URL first."** The test uses the saved settings. Save, then
  test.
- **The "MYOB refused the connection" banner won't go away.** It clears on the next request MYOB
  accepts. Reconnect on the settings screen (or fix the company file username and password in
  local mode) and press **Test connection**.
- **No summary arrived.** Run `php craft my/digest/status`. A period with nothing pushed and
  nothing new wrong sends nothing unless **Send even when nothing happened** is on. Without cron,
  the summary goes out through the queue after a web request, so the queue has to be running.

## The log

- **The log is empty.** **Log requests** is off.
- **Entries have no request or response.** **Log payloads** is off.
- **The log is huge.** Lower **Log retention**, or run `php craft my/log/prune --days=7`.
