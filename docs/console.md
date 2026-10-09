---
title: Console commands
slug: console
order: 40
summary: Pushing, backfilling and retrying orders, checking the connection, reading MYOB's accounts and tax codes, and managing the log from the command line.
---

Every command exits `0` on success. A command that couldn't reach MYOB, or whose push failed, exits
`69` (unavailable). One that couldn't find what you asked for exits `65` (data error). That makes
them safe to use in deploy hooks and cron.

## Pushing orders

### my/sync/order

Push one order: customer, invoice, payments and refunds, exactly as the queue would.

```sh
php craft my/sync/order 1042
php craft my/sync/order 1042 --dryRun
php craft my/sync/order 1042 --force
```

The argument is matched against the order **reference** first, then the order **ID**, then the full
order **number**.

| Option | Alias | What it does |
|---|---|---|
| `--dryRun` | `-d` | Print the endpoint and the JSON payload, and whether the total agrees with the order. Sends no invoice |
| `--force` | `-f` | Push even if the order is already invoiced. See **Push again** in [Usage](usage#the-order-panel) |

A dry run goes through the same builder as the real push, so it prints exactly what MYOB would
receive. It ends with a line like *Would book 110.00; the order is 110.00.*, with *these disagree*
added if they don't. A dry run is read-only: it creates and updates nothing in MYOB, customer cards
included. If the order's customer has no card yet, `Customer` shows the placeholder UID
`new-card-created-on-push`; the real push creates the card and puts its UID there.

### my/sync/backfill

Push every completed order that has no synced invoice, oldest first. Use it after connecting for
the first time, or after an outage the queue gave up on.

```sh
php craft my/sync/backfill
php craft my/sync/backfill --limit=500 --queue
```

| Option | Alias | Default | What it does |
|---|---|---|---|
| `--limit` | `-l` | 100 | How many orders to push in this run |
| `--queue` | `-q` | off | Queue a job per order instead of pushing here |
| `--force` | `-f` | off | Push even if already invoiced |

Backfill ignores **Push orders automatically** and the status filter: it's an explicit request to
send these orders. Each one still goes through the ledger, so running it twice doesn't double
anything. It prints a tick or a cross per order, with the reason for each failure.

### my/sync/retry

Push again every order with a **failed** document: an invoice MYOB refused, a payment that didn't
apply, a credit note that bounced.

```sh
php craft my/sync/retry
php craft my/sync/retry --limit=50 --queue
```

| Option | Alias | Default | What it does |
|---|---|---|---|
| `--limit` | `-l` | 100 | How many failed documents to look at |
| `--queue` | `-q` | off | Queue a job per order instead of pushing here |

Retry looks at **failed** rows only. A **pending** row is one the queue is still handling. If the
queue has given up on one, `my/sync/backfill` picks the order up when its invoice is the pending
document; for a pending payment or credit note, push the order with `my/sync/order`.

### my/sync/status

What the connection and the ledger look like.

```sh
php craft my/sync/status
```

```
Connection
  Mode:         cloud
  Company file: Clearwater Pty Ltd
  Connected:    yes

Documents
  synced     412
  pending    0
  failed     2
  skipped    0

  3 completed orders have never been invoiced.
```

## The connection

### my/connection/test

Ask MYOB who you are on the company file. Prints *Connected to … as …*, or the reason it couldn't,
and exits `69` on failure. Useful in a deploy hook, to confirm a release didn't lose the connection.

```sh
php craft my/connection/test
```

### my/connection/company-files

List the company files this connection can see, with their ID, country and name.

```sh
php craft my/connection/company-files
```

API keys issued after 12 March 2025 aren't allowed to list company files, so for those this prints
*MYOB returned no company files.* That's expected. Set **Company file ID** in the settings instead.

### my/connection/refresh

Force a new OAuth access token now, whatever the current one's expiry, and print when it's valid
until. Cloud mode only: a local server has no tokens, so in local mode nothing is refreshed. My
refreshes on its own before a token expires, and again after a `401` unless another worker has
already replaced the token, so you only need this when diagnosing a connection.

```sh
php craft my/connection/refresh
```

## Reference data

The settings screen asks for account codes and tax codes. These commands list them, so you don't
have to go and look in AccountRight.

```sh
php craft my/reference/accounts       # DisplayID, type and name of every account
php craft my/reference/tax-codes      # code, rate and description
php craft my/reference/item APR-01    # one inventory item, by its MYOB item number
php craft my/reference/refresh        # forget the cached accounts and tax codes and read them again
```

Accounts and tax codes are cached for 24 hours. Run `my/reference/refresh` after changing them in
MYOB. `my/reference/item` exits `65` if there's no item with that number, which is the quickest way
to check why an Item-layout line fell back to the sales account.

## Alerts and the sync summary

### my/alerts/check

Evaluate every incident — pushes failing, invoices booked at a different total, MYOB refusing the
connection — and send any alert or recovery that is owed. Every push already checks the first two,
and a refused connection is noticed the moment it happens; cron running this is what notices an
incident *clearing* on a quiet day. `my/sync/retry` runs the same check after its retries.

```sh
php craft my/alerts/check
```

Exits `0` whether or not anything is open: an open incident is news, not a failure of the command,
and cron would otherwise email you about the alert on top of the alert.

### my/alerts/test

Send a sample alert through every configured channel, using the saved settings. Exits `78`
(configuration) when there are no recipients and no webhook.

### my/digest/send

The sync summary, for cron. Run it as often as you like; it sends once per period, and only once
the configured day and hour have arrived.

```sh
0,15,30,45 * * * * php /path/to/craft my/digest/send
php craft my/digest/send --force    # now, whatever the schedule says
```

Exits `0` for every outcome that is not a fault (not due, already sent, nothing to report,
switched off), `78` when it is switched on but has no valid recipients, and `1` when the send
failed — the next run tries again.

### my/digest/status

The schedule: enabled, frequency, recipients, last period, last run, last send and next due.

## The log

```sh
php craft my/log/index 50          # the 50 most recent entries (default 20)
php craft my/log/prune             # delete entries older than Log retention
php craft my/log/prune --days=7    # or older than 7 days
php craft my/log/clear             # delete the whole log (asks first)
```

If **Log retention** is `0` (keep everything), `my/log/prune` deletes nothing unless you pass
`--days`. `my/log/clear` asks for confirmation unless you run it with `--interactive=0`.
