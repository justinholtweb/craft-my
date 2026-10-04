# Plugin Store promo images

Marketing images for the My listing on the Craft Plugin Store, rendered in the same theme as the
plugin's marketing page at
[justinholt.com/plugins/craft-my](https://justinholt.com/plugins/craft-my).

## Building

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output lands in `out/` as `my-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, then
downsampled so the type stays crisp). **Promos are JPEG, never PNG**: Chrome can only write PNG, so
`build.sh` converts with `sips` at quality 90 and deletes the intermediate.

If Chrome writes the screenshot and then never exits (it has been seen to hang on some machines),
kill it once the PNG is on disk; the conversion step is all that is left.

## Slides

| # | Slide | Shows | Strings come from |
|---|-------|-------|-------------------|
| 1 | Cover: name, tagline, app icon, price | — | `README.md` intro; one edition, $99 then $79 a year |
| 2 | The cloud, or the back office | the settings screen's Connection section | `src/templates/settings.twig` ("Connection", "Connected to {name}", "Last checked {date}", Reconnect / Test connection / Disconnect, "Where MYOB lives" and its option, "Redirect URL", the company-file note, "Company file username" / "password" and their instructions); the test result is `Auth::verify()`'s "Connected to {name} as {user}."; the URL is `Settings::getRedirectUri()` (`my/oauth/callback`) |
| 3 | See it before MYOB does | the order screen's MYOB panel with Preview open | `src/templates/_order-panel.twig` (legend "MYOB", "Push again", "Preview", "Totals agree.", the `POST {endpoint}` header); type and status labels from `SyncDocument`; the payload is `Invoices::buildPayload()` as `DocumentsController::actionPreview()` encodes it |
| 4 | MYOB books what the customer paid | `reconcile()` totalling the same payload, and the refusal | `Invoices::reconcile()` (`computed`, `expected`, `diffMinor`), "Totals agree." from the panel; the refusal is `Sync::handleMismatch()` verbatim |
| 5 | Nothing is entered twice | the documents index | `src/templates/documents/_index.twig` ("MYOB documents", "All types", "All statuses", the status counts, columns Type / Order / MYOB number / Amount / Status / Updated, "Not assigned", "{n} attempts") |
| 6 | Refunds become credit notes | the order panel after a partial refund, and the credit note's lines | `_order-panel.twig`; "Credit note" / "Credit refund" from `SyncDocument::getTypeLabel()`; the payload from `Refunds::buildPartialCreditNote()` ("Partial refund of order {reference}"); `CR` / `CR2-` numbering from `Invoices::invoiceNumber()` |
| 7 | Every request, on a screen | the log index, plus one entry's Response | `src/templates/log/_index.twig` and `_detail.twig` ("MYOB log", "All actions", "All levels", columns When / Level / Action / Request / Code / Time / Summary, "{ms} ms"); actions are the raw handles passed to `Api::request()` and `Auth::postToken()`; summaries from `Api::summarise()` ("OK ({code})", "{count} records"), `Api::send()` ("Retrying in {seconds}s (attempt {n})") and `Auth::postToken()` ("Token issued, valid {seconds}s"); `[redacted]` is `Log::prepare()`'s output |

There is no editions slide, because there is one edition.

The mocks have to agree with the plugin. Order 1042 is the same order throughout: an AU store,
tax-inclusive GST, settings at their defaults except `numberPrefix` = `WEB` and a sales account of
`4-1000`. Two jumpers at $89 (`MCJ-NAVY-M`), one wool wash at $14.95 (`WW-250`), a $10 cart-level
discount and $12 shipping, so the invoice is `WEB1042` for $194.95. A $89 partial refund three days
later raised credit note `WEBCR1042` and, with "Also record the money going back out", a credit
refund.

Things worth knowing before changing a mock:

- The payload refers to accounts and tax codes by **UID**, not by `4-1000` or `GST`; that is what
  `Reference::accountRef()` and `taxCodeRef()` return. The UIDs on the slides are made up.
- Amounts print as `178`, not `178.00`: the preview encodes without `JSON_PRESERVE_ZERO_FRACTION`
  (the request itself is sent with it).
- A product line puts `TaxCode` before `Account`; a discount or adjustment line the other way round.
  That is the order `buildLine()` and `buildAdjustmentLine()` build them in.
- A payment has no MYOB number in the ledger, because MYOB returns `ReceiptNumber`, not `Number`.
  The credit refund's `CD000412` follows the shape the test mock returns.
- The ledger's Order column prints the order's element ID; the slides assume it is 1042, the same as
  the reference, for readability.
- The API log records bodies, not headers, so there is no `Authorization` line to show. The
  redaction on slide 7 is the token response, which is where the secrets actually appear.
- Dates are en-AU short format (`3/10/26, 9:14 am`) because a MYOB merchant's control panel would
  be.

Change a label, a summary or the payload builder and change the slide.

## Palette

Accent `#4B1A60`, the deep aubergine of the icon tile. It is far too dark for anything small on the
dark background, so `#C9A6E0` (lavender) is the light accent for ticks, rules, inline code and
highlights, and the glows use a mid violet `#7A3E96`. Gradient headline text starts at `#A77BC8`.
Plus the family's `#ffd166` gold. Jersey 20 for display, Inter for body.

## The watermark is what is printed on the invoice

`assets/watermark.svg` is taken from the path in `../src/icon-mask.svg`, filled white, with no tile —
but **without the sheet of paper**. At watermark scale the sheet is a flat slab a thousand pixels
tall, and its edge and its punched-out rules read as hard grey boxes across every slide (the same
trap as a frame on an app icon). So only the three text lines, the total and its double rule are
kept, as solid pills, with the viewBox cropped to them. It is anchored bottom-right, runs off the
edge, and is switched off on the cover, where it would sit behind the icon like a shadow.

`assets/icon.svg` is a straight copy of `../src/icon.svg`. Re-copy it (`cp`) whenever the icon
changes.

`fonts.css` is generated by `build.sh` and gitignored; don't edit it.
