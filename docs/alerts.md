---
title: Alerts and the sync summary
slug: alerts
order: 35
summary: One email (and optionally a Slack or Teams message) when pushes fail, MYOB books a different total or refuses the connection — and one when it clears. Plus a weekly sync summary, a Dashboard widget and MYOB on the Orders index.
---

# Alerts and the sync summary

The documents screen knows everything that has gone wrong, but nobody opens an integration's admin
screen on a day it seems to be working. My tells you instead, when one of three things happens:

| Incident | Opens when | Clears when |
|---|---|---|
| **Orders failing to push** | `alertFailureThreshold` failures (default 1) inside `alertWindowMinutes` (default 60): an invoice, payment, credit note or credit refund left `failed` for a human | a whole window passes with no new failure |
| **Invoices booked at a different total** | an invoice pushed inside the window that MYOB booked at a different total from the order — usually a tax code mapped to the wrong rate | a whole window passes with no new one |
| **MYOB refused the connection** | MYOB refuses the refresh token, or answers 401 to a token My has just refreshed. In local mode, any 401: the company file login is wrong | the next authenticated request succeeds |

The last one matters most. A refresh token that MYOB stops honouring — the connection authorised
again somewhere else, revoked, or the MYOB password changed — silently stops every invoice. While
it is open, a banner also runs across the top of the control panel for everyone who can see MYOB
documents, with a **Reconnect** link for admins.

You get **one message when an incident starts and one when it clears**, never one per failure.
Each incident has a single latch row in the database: checking it a hundred times while it is
still open sends nothing new. If it reopens within `alertCooldownMinutes` (default 60) of its
recovery message, you hear about it when that quiet period ends, and only if it is still happening.

A recovery means nothing *new* has gone wrong for a whole window — not that everything is fixed.
It says how many documents still show as failed, or how many invoices still disagree with their
orders.

A MYOB outage is not a failure yet. A timeout, a `429` or a `5xx` leaves the document `pending`
and the queue retries it; it only becomes a failure — and an alert — once **Queue attempts** run
out, or MYOB rejects the document outright.

## Setting it up

**Settings → My → Alerts.**

| Setting | Default | |
|---|---|---|
| `alertRecipients` | empty | Addresses separated by commas, or an `$ENV` reference. Empty means no email. The sync summary goes to the same people |
| `alertWebhookUrl` | empty | A Slack or Teams incoming-webhook URL, or an `$ENV` reference. Keep it in an environment variable: the URL is the credential |
| `alertWebhookFormat` | `slack` | `slack`, `teams`, or `json` for your own receiver |
| `alertWebhookSecret` | empty | When set, each webhook carries `X-My-Timestamp` and `X-My-Signature: sha256=<hmac>` over `timestamp.body` |
| `alertOnFailures` | on | |
| `alertFailureThreshold` | 1 | |
| `alertWindowMinutes` | 60 | Used by both failures and mismatches |
| `alertOnMismatch` | on | |
| `alertOnAuthFailure` | on | |
| `alertCooldownMinutes` | 60 | |
| `allowPrivateAlertWebhookHosts` | off | Config file only — see below |

Mail goes through Craft's own mailer, so it uses whatever **Settings → Email** is set to. Press
**Send a test alert** (admins only) or run `php craft my/alerts/test` after saving to check that
both channels arrive.

Nothing here is required. A site with no recipients and no webhook still records incidents and
shows them on the Dashboard widget and in the banner. If you add a recipient later, any incident
that is still open is sent at the next check. An environment with no company file connected checks
nothing.

## When alerts are checked

- **At the end of every push** — queue, console, **Push to MYOB** on an order, or the bulk action:
  failures and mismatches. No cron needed.
- **The moment MYOB refuses the connection**: authentication.
- **`php craft my/alerts/check`** and **`php craft my/sync/retry`**: everything.

An incident can only be seen to *clear* when something checks. On a store that takes orders every
day that happens by itself; on a quiet one, put the check on cron:

```sh
*/15 * * * * cd /path/to/site && php craft my/alerts/check >> /dev/null 2>&1
```

## What an alert says

A plain-text email: the site, the incident, what was seen — the document type, the order
reference and MYOB's own error — and links straight to the right screen: the documents screen
filtered to failures or to invoices booked at a different total, or My's settings for a refused
connection. The Slack message, Teams card and JSON event carry the same.

What was seen is redacted before it leaves the site: the API secret, the company file password and
both OAuth tokens are removed by value, anything shaped like a credential (`Bearer …`,
`x-myobapi-cftoken: …`, `refresh_token=…`) by pattern, markup is stripped and the line is capped at
500 characters. Alerts quote MYOB's error and an order reference; never a customer's details.

## The webhook

Slack and Teams incoming webhooks work as they are. The `json` format posts:

```json
{
  "event": "my.alert.opened",
  "incident": "failures",
  "site": "My Store",
  "title": "Orders failing to push in MYOB",
  "detail": "1 push failures in the last 60 minutes; 1 documents show as failed. Latest: Invoice for order 1042: Invalid data — Account 4-9999 does not exist",
  "url": "https://example.com/admin/my/documents?status=failed",
  "documentsUrl": "https://example.com/admin/my/documents",
  "at": "2026-10-09T08:15:00+00:00"
}
```

`event` is `my.alert.recovered` when it clears; `incident` is `failures`, `mismatch` or `auth`.

The URL is checked every time it is used, not only when it is saved. It must be `http` or `https`
with no username or password in it, every address the host resolves to must be public — not
private, loopback, link-local (the cloud metadata service) or carrier-grade NAT — and the request
is pinned to those addresses so DNS cannot be switched between the check and the send. Redirects
are never followed. For a self-hosted Mattermost on your own network, set
`allowPrivateAlertWebhookHosts` in `config/my.php`. The scheme and redirect rules still apply.

If every channel fails, the alert is not marked sent, and the next check tries again.

## The sync summary

**Settings → My → Sync summary.** Once a week (or once a day), the alert recipients get an email
saying what went to MYOB since the last one — invoices (with the total invoiced, per currency),
payments, credit notes and credit refunds — how many documents are still failed or booked at a
different total, any open alert, and each **new** problem since the last summary with its order,
MYOB's error and a link. A problem that was already in last week's summary is counted, not listed
again.

| Setting | Default | |
|---|---|---|
| `digestEnabled` | off | |
| `digestFrequency` | `weekly` | or `daily` |
| `digestWeekday` | 1 | ISO day of the week, 1 = Monday |
| `digestHour` | 8 | 0–23 in the system time zone. Sent *at or after* this hour, so a missed hour still sends later that day |
| `digestSendWhenEmpty` | off | Off: a period with nothing pushed and nothing new wrong sends nothing |
| `digestWebTrigger` | on | Also check from web requests, for sites without cron |

Put it on cron. It sends once per period however often it runs:

```sh
0,15,30,45 * * * * cd /path/to/site && php craft my/digest/send >> /dev/null 2>&1
```

Without cron, the end of a web request looks at the schedule at most every five minutes and, when
the summary is due, queues it. It never hangs off Craft's garbage collection, which runs on a dice
roll.

Each send claims its period in the database first, so cron and a web request racing in the same
minute send one summary between them. If the mail server refuses it, the period is given back and
the next run tries again. `php craft my/digest/send --force` sends now whatever the schedule says;
`php craft my/digest/status` prints the last run, the last send and when the next one is due.

**Send a test summary now** on the settings screen sends what the next summary would say, marked
as a test, to the alert recipients (or to you, if there are none). It never counts as the period's
summary.

## The Dashboard widget

**Dashboard → New widget → MYOB health** shows whether this environment is connected and to which
company file, how many documents were synced, are pending and failed in the last seven days, how
many invoices were booked at a different total (each linking to the documents screen filtered to
them), when the last push went through, and any open incident — hover it to see what was seen. It
reads the same latch rows the alerts come from, so the widget and your inbox cannot disagree. Only
people with *View synced documents* can add it.

## The Orders index

Commerce's Orders index gets three things:

- **A MYOB column.** A status dot, a word and the MYOB invoice number. Add it with the index's
  column picker. It is looked up for the whole page at once, not once per row.
- **A "MYOB status" filter**, usable in the index's filter bar and in custom sources (*Failed in
  MYOB*, *Not pushed*). The same rule is available anywhere Craft builds an order condition.
- **A Push to MYOB bulk action**, for people with *Push orders to MYOB*. It queues a push for each
  selected completed order (carts, and orders the user may not open, are skipped). It is never a
  forced re-send: an invoiced order is not invoiced again — it only picks up any payment or credit
  note still missing and retries anything that failed.

| Status | Means |
|---|---|
| **Failed** | Any of the order's documents — the invoice, a payment, a credit note — is failed |
| **Booked at a different total** | The invoice is in MYOB, at a different total from the order |
| **Synced** | The invoice is in MYOB and agrees with the order |
| **Pending** | The invoice is queued, retrying, or was interrupted mid-push |
| **Skipped** | Deliberately not sent |
| **Not pushed** | Nothing has gone to MYOB for this order |

They are in that order of precedence, so every order is in exactly one.

## Changing or suppressing an alert or a summary

```php
use justinholtweb\my\events\AlertEvent;
use justinholtweb\my\events\DigestEvent;
use justinholtweb\my\services\Alerts;
use justinholtweb\my\services\Digest;
use yii\base\Event;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, function(AlertEvent $e) {
    // $e->incident, $e->recovered, $e->detail
    $e->subject = '[Books] ' . $e->subject;

    // Swallow it. The latch still counts it as sent.
    if ($e->incident === Alerts::INCIDENT_MISMATCH && !$e->recovered) {
        $e->isValid = false;
    }
});

Event::on(Digest::class, Digest::EVENT_BEFORE_SEND, function(DigestEvent $e) {
    // $e->recipients, $e->subject, $e->variables, $e->isTest.
    // Cancelling a scheduled summary leaves the period unclaimed, so the next run asks again.
    $e->recipients[] = 'accountant@example.com';
});
```
