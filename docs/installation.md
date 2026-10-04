---
title: Installation
slug: installation
order: 10
summary: Requirements, install, the one edition, and what has to be true before the first order goes to MYOB.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later, installed and enabled
- PHP 8.2 or later
- A MYOB company file, either:
  - **in the cloud** (AccountRight Live / MYOB Business), plus an app registered at
    [developer.myob.com](https://developer.myob.com) for the API key and secret, or
  - **on an AccountRight local server** that your Craft site can reach over the network
- A running Craft queue. Every automatic push is a queue job.

There's no build step, and nothing to install beyond Craft and Commerce.

## Install

```sh
composer require justinholtweb/craft-my
php craft plugin/install my
```

Or find **My** in the Craft Plugin Store and install it from there.

Installing creates four tables and nothing else. No fields, entry types or Matrix blocks.

| Table | Holds |
|---|---|
| `my_documents` | The sync ledger: one row per invoice, payment, credit note or credit refund sent to MYOB |
| `my_connection` | The connection: OAuth tokens, their expiry, and the chosen company file |
| `my_contacts` | Which MYOB customer card each email address maps to |
| `my_log` | The connection log, with request and response bodies and credentials redacted |

The plugin adds a **MYOB** item to the control panel nav, with **Documents**, **Log** and (for
admins, where admin changes are allowed) **Settings** under it.

My needs Commerce. If Commerce is missing or disabled, the settings screen says so and the plugin
does nothing else: it listens for no order events and adds no order panel.

## Editions

There is one edition.

| Edition | Price | Includes |
|---|---|---|
| My | $99, then $79 a year for updates | Everything: cloud and local AccountRight, invoices, customer cards, payments, credit notes, reconciliation, the ledger, the log, console commands and the Twig variable |

There are no feature gates, so there's nothing to upgrade to later.

## The queue

Orders never go to MYOB during checkout. Completing an order, changing its status or recording a
payment queues a **Pushing order … to MYOB** job, and the job does the work. If your queue doesn't
run, nothing is pushed.

Craft runs the queue on control panel requests by default (`runQueueAutomatically`). A busy store
should run a dedicated worker instead:

```sh
php craft queue/listen
```

A push that fails is retried by the queue up to **Queue attempts** times (5 by default). See
[Usage](usage#when-orders-push).

## First run

1. Choose **Where MYOB lives**, then connect. See [Configuration](configuration#connecting).
2. Pick the company file and press **Test connection**.
3. Set at least a **Sales account**. A service invoice can't be built without one, and the settings
   screen won't save while it's empty and automatic pushing is on.
4. Check the tax codes match the ones in your company file:
   `php craft my/reference/tax-codes` lists them.
5. Open an existing order in Commerce, find the **MYOB** panel and press **Preview**. You'll see
   the JSON My would send and whether its total agrees with the order.
6. When the preview looks right, press **Push to MYOB**, or let the next order go on its own.

To send orders placed before you installed My, see
[`my/sync/backfill`](console#my-sync-backfill).

## Uninstalling

```sh
php craft plugin/uninstall my
```

This drops the four tables: the ledger, the connection, the contact map and the log. Nothing in
MYOB is touched. Invoices, cards and payments stay exactly as they are.
