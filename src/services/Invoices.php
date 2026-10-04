<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\elements\Address;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\helpers\Dates;
use justinholtweb\my\helpers\Money;
use justinholtweb\my\models\Settings;
use justinholtweb\my\Plugin;

/**
 * Turning a Commerce order into a MYOB sale invoice.
 *
 * **`buildPayload()` is the only place an order becomes MYOB JSON.** The queue job, the CP
 * "Push now" button, the console command and the CP preview panel all call it, so what a merchant
 * previews is byte-identical to what MYOB receives — which is the difference between a preview and
 * a reassuring guess.
 *
 * ## Why the totals are built the way they are
 *
 * The one unforgivable failure mode for an accounting integration is booking a different number
 * than the customer paid. Two things follow from that:
 *
 * - `Subtotal`, `TotalTax` and `TotalAmount` are **never sent**. MYOB computes them from the lines,
 *   and a payload whose stated total disagrees with its own lines is how invoices end up subtly and
 *   permanently wrong.
 * - The same figures are computed here anyway and checked against the order before the push, and
 *   checked again against what MYOB actually booked afterwards. A mismatch is a real error, not a
 *   rounding curiosity.
 *
 * Tax is attributed per line from Commerce's own adjustments rather than recomputed. In
 * tax-inclusive mode the arithmetic closes exactly against `Order::getTotalPrice()`; the derivation
 * is in `reconcile()`.
 */
class Invoices extends Component
{
    /**
     * MYOB's field widths. Going over is a 400, not a truncation.
     */
    private const MAX_NUMBER = 13;
    private const MAX_DESCRIPTION = 1000;
    private const MAX_JOURNAL_MEMO = 255;
    private const MAX_COMMENT = 2000;
    private const MAX_SHIP_TO = 255;
    private const MAX_PO_NUMBER = 255;

    /**
     * MYOB's two sale invoice layouts, and where each one posts.
     */
    public function endpoint(?string $layout = null): string
    {
        $layout ??= Plugin::getInstance()->getSettings()->invoiceLayout;

        return $layout === Settings::LAYOUT_ITEM
            ? 'Sale/Invoice/Item'
            : 'Sale/Invoice/Service';
    }

    // Building
    // -------------------------------------------------------------------------

    /**
     * The MYOB invoice payload for an order.
     *
     * @param bool $negate Build a credit note — the same invoice with every amount reversed.
     * @return array
     * @throws MyobApiException
     */
    public function buildPayload(Order $order, array $customerRef, bool $negate = false, ?array $only = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $inclusive = $this->isTaxInclusive($order);
        $sign = $negate ? -1 : 1;

        $lines = [];

        foreach ($order->getLineItems() as $lineItem) {
            if ($only !== null && !in_array($lineItem->id, $only, true)) {
                continue;
            }

            $amount = $this->lineAmount($lineItem, $inclusive);

            // A zero line is noise in the ledger and MYOB is happy to store it forever.
            if (Money::minor($amount) === 0) {
                continue;
            }

            $lines[] = $this->buildLine($lineItem, $amount * $sign, $inclusive);
        }

        // Cart-level adjustments belong to no line, so they cannot be folded into one.
        if ($only === null) {
            foreach ($this->orderLevelAdjustments($order) as $adjustment) {
                $description = $adjustment['isDiscount']
                    ? ($settings->discountDescription ?: Craft::t('my', 'Discount'))
                    : ($adjustment['name'] ?: Craft::t('my', 'Adjustment'));

                $lines[] = $this->buildAdjustmentLine(
                    $description,
                    $adjustment['amount'] * $sign,
                    ($adjustment['isDiscount'] ? $settings->discountAccount : '') ?: $settings->salesAccount,
                    $this->discountTaxCode($order),
                );
            }
        }

        if ($lines === []) {
            throw new MyobApiException(Craft::t('my', 'Order {reference} has nothing to invoice.', [
                'reference' => $order->reference ?: $order->id,
            ]));
        }

        $freight = $only === null ? $this->freightAmount($order, $inclusive) : 0.0;

        $payload = [
            'Customer' => $customerRef,
            'Date' => $this->invoiceDate($order),
            'IsTaxInclusive' => $inclusive,
            'Lines' => $lines,
        ];

        $number = $this->invoiceNumber($order, $negate);

        if ($number !== null) {
            $payload['Number'] = $number;
        }

        if (Money::minor($freight) !== 0) {
            $payload['Freight'] = Money::round($freight * $sign);
            $freightTaxCode = $this->taxCodeRef($settings->freightTaxCode);

            if ($freightTaxCode !== null) {
                $payload['FreightTaxCode'] = $freightTaxCode;
            }
        }

        $shipTo = $this->formatAddress($order->getShippingAddress());

        if ($shipTo !== null) {
            $payload['ShipToAddress'] = mb_substr($shipTo, 0, self::MAX_SHIP_TO);
        }

        $reference = trim((string)$order->reference);

        if ($reference !== '') {
            // Belt and braces against losing the sync ledger: a human can always reconcile a MYOB
            // invoice back to a Craft order from this field, whatever `Number` ended up as.
            $payload['CustomerPurchaseOrderNumber'] = mb_substr($reference, 0, self::MAX_PO_NUMBER);
        }

        $memo = $this->render($settings->journalMemoTemplate, $order);

        if ($memo !== '') {
            $payload['JournalMemo'] = mb_substr($memo, 0, self::MAX_JOURNAL_MEMO);
        }

        $comment = $this->render($settings->commentTemplate, $order);

        if ($comment !== '') {
            $payload['Comment'] = mb_substr($comment, 0, self::MAX_COMMENT);
        }

        if (trim($settings->categoryUid) !== '') {
            $payload['Category'] = ['UID' => trim($settings->categoryUid)];
        }

        if (trim($settings->salespersonUid) !== '') {
            $payload['Salesperson'] = ['UID' => trim($settings->salespersonUid)];
        }

        if (trim($settings->invoiceDeliveryStatus) !== '') {
            $payload['InvoiceDeliveryStatus'] = trim($settings->invoiceDeliveryStatus);
        }

        // A foreign-currency order has to name its currency, or MYOB books it at face value in the
        // company file's own currency — which looks right and is not.
        $currency = strtoupper(trim((string)$order->currency));
        $storeCurrency = $this->storeCurrency($order);

        if ($currency !== '' && $storeCurrency !== null && $currency !== $storeCurrency) {
            $payload['ForeignCurrency'] = ['Code' => $currency];
        }

        return $payload;
    }

    /**
     * One invoice line.
     *
     * A **service** line posts a `Total` to an account and needs nothing to exist in MYOB. An
     * **item** line references an inventory item UID, which gives correct stock and cost of goods
     * but requires the SKU to already be in the company file.
     *
     * @throws MyobApiException
     */
    private function buildLine(LineItem $lineItem, float $amount, bool $inclusive): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $description = $this->lineDescription($lineItem);
        $taxCode = $this->taxCodeRef($this->taxCodeFor($lineItem));

        $line = [
            'Type' => 'Transaction',
            'Description' => $description,
            'Total' => Money::round($amount),
        ];

        if ($taxCode !== null) {
            $line['TaxCode'] = $taxCode;
        }

        if ($settings->invoiceLayout === Settings::LAYOUT_ITEM) {
            $item = $this->resolveItem($lineItem);

            if ($item !== null) {
                $qty = (float)$lineItem->qty;

                $line['Item'] = ['UID' => $item['UID']];
                $line['ShipQuantity'] = $qty;
                // Derive the unit price from the line total rather than from `salePrice`, so the
                // line still adds up after its own discounts. MYOB stores six decimal places.
                $line['UnitPrice'] = $qty > 0 ? Money::unit($amount / $qty) : Money::round($amount);

                return $line;
            }

            if ($settings->itemFallback === Settings::ITEM_FALLBACK_FAIL) {
                throw new MyobApiException(Craft::t('my', 'No MYOB inventory item matches the SKU “{sku}”.', [
                    'sku' => $lineItem->getSku(),
                ]));
            }

            // Fall through to an account line. An Item invoice accepts account lines alongside
            // item ones, so the customer is still invoiced the right amount — the line just does
            // not move stock. Dropping it instead would under-invoice, which is never the lesser
            // evil.
        }

        $account = Plugin::getInstance()->getReference()->accountRef($settings->salesAccount);

        if ($account === null) {
            throw new MyobApiException(Craft::t('my', 'MYOB has no account “{code}”. Check the sales account on the settings screen.', [
                'code' => $settings->salesAccount,
            ]));
        }

        $line['Account'] = $account;

        return $line;
    }

    /**
     * A synthetic line — freight, a cart-level discount, a rounding correction.
     *
     * @throws MyobApiException
     */
    private function buildAdjustmentLine(string $description, float $amount, string $accountCode, string $taxCode): array
    {
        $account = Plugin::getInstance()->getReference()->accountRef($accountCode);

        if ($account === null) {
            throw new MyobApiException(Craft::t('my', 'MYOB has no account “{code}”, which is needed for the “{description}” line.', [
                'code' => $accountCode,
                'description' => $description,
            ]));
        }

        $line = [
            'Type' => 'Transaction',
            'Description' => mb_substr($description, 0, self::MAX_DESCRIPTION),
            'Total' => Money::round($amount),
            'Account' => $account,
        ];

        $ref = Plugin::getInstance()->getReference()->taxCodeRef($taxCode);

        if ($ref !== null) {
            $line['TaxCode'] = $ref;
        }

        return $line;
    }

    // Money
    // -------------------------------------------------------------------------

    /**
     * What one line item is worth on the invoice.
     *
     * Built from `LineItem::getTotal()` — Commerce's own arithmetic — rather than reconstructed
     * from subtotal and discount. That matters because Commerce's adjuster list is open: a site
     * can have a third-party surcharge, a deposit, an eco-levy. Reconstructing the line from the
     * handful of adjustment types this plugin knows about would silently drop those, and invoice
     * the customer for less than they paid.
     *
     * Only two things are pulled back out. **Shipping**, because MYOB carries it in `Freight`
     * rather than on a line. And **tax**, when posting exclusive, because MYOB is going to add it
     * back from the tax code.
     */
    public function lineAmount(LineItem $lineItem, bool $inclusive): float
    {
        $shipping = 0.0;
        $additiveTax = 0.0;
        $includedTax = 0.0;

        foreach ($lineItem->getAdjustments() as $adjustment) {
            if ($adjustment->type === 'shipping' && !$adjustment->included) {
                $shipping += $adjustment->amount;
                continue;
            }

            if ($adjustment->type !== 'tax') {
                continue;
            }

            if ($adjustment->included) {
                $includedTax += $adjustment->amount;
            } else {
                $additiveTax += $adjustment->amount;
            }
        }

        // `getTotal()` is the subtotal plus every adjustment that is not already inside the price.
        $base = Money::sum([$lineItem->getTotal(), -$shipping]);

        return $inclusive
            ? $base
            : Money::sum([$base, -$additiveTax, -$includedTax]);
    }

    /**
     * The adjustments that belong to the cart rather than to any one line item, each of which
     * needs a line of its own — a cart-level discount, and anything a third-party adjuster added.
     *
     * Tax and shipping are excluded: they are carried by the lines' tax codes and by `Freight`.
     *
     * An adjustment naming a line item that is not on the order is **not** picked up here. That is
     * a Commerce data problem rather than a cart-level charge, and reconciliation refusing the
     * push is how the merchant finds out about it — quietly inventing a line to absorb it would
     * make the invoice add up and leave the cause invisible.
     *
     * @return array<int, array{name: string, amount: float, isDiscount: bool}>
     */
    public function orderLevelAdjustments(Order $order): array
    {
        $out = [];

        foreach (($order->getAdjustments() ?? []) as $adjustment) {
            if ($adjustment->lineItemId !== null) {
                continue;
            }

            if ($adjustment->included) {
                continue;
            }

            if (in_array($adjustment->type, ['tax', 'shipping'], true)) {
                continue;
            }

            if (Money::minor((float)$adjustment->amount) === 0) {
                continue;
            }

            $out[] = [
                'name' => trim((string)($adjustment->description ?: $adjustment->name)),
                'amount' => (float)$adjustment->amount,
                'isDiscount' => $adjustment->type === 'discount',
            ];
        }

        return $out;
    }

    /**
     * Shipping, with the tax that was never attributed to a line item — which is what tax on
     * shipping looks like from here.
     */
    public function freightAmount(Order $order, bool $inclusive): float
    {
        $shipping = $order->getTotalShippingCost();

        [$additive, $included] = $this->unattributedTax($order);

        return $inclusive
            ? Money::sum([$shipping, $additive])
            : Money::sum([$shipping, -$included]);
    }

    /**
     * Tax adjustments that belong to the order rather than to any one line item.
     *
     * @return array{0: float, 1: float} additive, included
     */
    private function unattributedTax(Order $order): array
    {
        $additive = 0.0;
        $included = 0.0;

        foreach (($order->getAdjustments() ?? []) as $adjustment) {
            if ($adjustment->type !== 'tax' || $adjustment->lineItemId !== null) {
                continue;
            }

            if ($adjustment->included) {
                $included += $adjustment->amount;
            } else {
                $additive += $adjustment->amount;
            }
        }

        return [$additive, $included];
    }

    /**
     * What this payload will book, and what it ought to book.
     *
     * In tax-inclusive mode the two are equal by construction:
     *
     *     Σ(lineBase + lineAdditiveTax) + (shipping + unattributedAdditiveTax) + orderDiscount
     *   = itemSubtotal + totalDiscount + totalShipping + totalAdditiveTax
     *   = Order::getTotalPrice()
     *
     * In tax-exclusive mode the lines carry no tax, so the expected total is the ex-tax figure
     * plus every tax adjustment Commerce made — and whether MYOB agrees depends on its own tax
     * codes, which is exactly what `verifyAgainstOrder()` checks after the push.
     *
     * @return array{expected: float, computed: float, diffMinor: int, ok: bool}
     */
    public function reconcile(Order $order, array $payload): array
    {
        $inclusive = (bool)($payload['IsTaxInclusive'] ?? true);
        $tolerance = Plugin::getInstance()->getSettings()->tolerance;

        $amounts = [(float)($payload['Freight'] ?? 0)];

        foreach ($payload['Lines'] ?? [] as $line) {
            $amounts[] = (float)($line['Total'] ?? 0);
        }

        $computed = Money::sum($amounts);

        if (!$inclusive) {
            $computed = Money::sum([
                $computed,
                $order->getTotalTax(),
                $order->getTotalTaxIncluded(),
            ]);
        }

        $expected = (float)$order->getTotalPrice();
        $diff = Money::diffMinor($expected, $computed);

        return [
            'expected' => $expected,
            'computed' => $computed,
            'diffMinor' => $diff,
            'ok' => abs($diff) <= $tolerance,
        ];
    }

    /**
     * Add a rounding line so the invoice books the order total exactly.
     *
     * Only reached when `onTotalMismatch` is `round`. It is not the default, because a rounding
     * line that is quietly absorbing three dollars is a bug wearing a disguise.
     *
     * @throws MyobApiException
     */
    public function applyRounding(array $payload, int $diffMinor): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $payload['Lines'][] = $this->buildAdjustmentLine(
            $settings->roundingDescription ?: Craft::t('my', 'Rounding'),
            $diffMinor / 100,
            $settings->roundingAccount ?: $settings->salesAccount,
            $settings->zeroTaxCode,
        );

        return $payload;
    }

    /**
     * What MYOB actually booked, against what the customer actually paid.
     *
     * This is the check that catches a tax code mapped to the wrong rate: the payload reconciled
     * perfectly, MYOB accepted it, and the invoice is still for the wrong amount.
     *
     * @return array{ok: bool, message: string|null, myobTotal: float|null}
     */
    public function verifyAgainstOrder(Order $order, mixed $response): array
    {
        if (!is_array($response) || !isset($response['TotalAmount'])) {
            return ['ok' => true, 'message' => null, 'myobTotal' => null];
        }

        $myobTotal = (float)$response['TotalAmount'];
        $expected = (float)$order->getTotalPrice();
        $tolerance = Plugin::getInstance()->getSettings()->tolerance;

        if (Money::equals($myobTotal, $expected, $tolerance)) {
            return ['ok' => true, 'message' => null, 'myobTotal' => $myobTotal];
        }

        return [
            'ok' => false,
            'myobTotal' => $myobTotal,
            'message' => Craft::t('my', 'MYOB booked {myob} but the order was {order}. This is usually a tax code mapped to the wrong rate.', [
                'myob' => number_format($myobTotal, 2),
                'order' => number_format($expected, 2),
            ]),
        ];
    }

    // Tax
    // -------------------------------------------------------------------------

    /**
     * Whether to post the invoice tax-inclusive.
     *
     * `null` in settings means "ask the order": if Commerce applied any included tax, prices
     * include tax. That is more reliable than reading a store setting, because it reflects what
     * actually happened to this order.
     */
    public function isTaxInclusive(Order $order): bool
    {
        $configured = Plugin::getInstance()->getSettings()->taxInclusive;

        if ($configured !== null) {
            return $configured;
        }

        if (Money::minor($order->getTotalTaxIncluded()) !== 0) {
            return true;
        }

        if (Money::minor($order->getTotalTax()) !== 0) {
            return false;
        }

        // No tax either way. Inclusive is the safer default: the invoice total then matches the
        // order total whatever tax code MYOB has on the account.
        return true;
    }

    /**
     * The MYOB tax code for a line item.
     */
    public function taxCodeFor(LineItem $lineItem): string
    {
        $settings = Plugin::getInstance()->getSettings();

        try {
            $handle = $lineItem->getTaxCategory()?->handle;
        } catch (\Throwable) {
            $handle = null;
        }

        if ($handle !== null && isset($settings->taxCodeMap[$handle]) && trim((string)$settings->taxCodeMap[$handle]) !== '') {
            return trim((string)$settings->taxCodeMap[$handle]);
        }

        // No mapping. A line that attracted no tax at all gets the zero-rated code rather than the
        // default one, so MYOB does not invent GST on something Commerce treated as exempt.
        $tax = Money::sum([$lineItem->getTax(), $lineItem->getTaxIncluded()]);

        return Money::minor($tax) === 0
            ? ($settings->zeroTaxCode ?: $settings->defaultTaxCode)
            : $settings->defaultTaxCode;
    }

    private function discountTaxCode(Order $order): string
    {
        $settings = Plugin::getInstance()->getSettings();

        // A discount has to carry the same tax treatment as what it discounts, or the tax on the
        // invoice is computed against the undiscounted amount.
        $taxed = Money::minor(Money::sum([$order->getTotalTax(), $order->getTotalTaxIncluded()])) !== 0;

        return $taxed ? $settings->defaultTaxCode : ($settings->zeroTaxCode ?: $settings->defaultTaxCode);
    }

    /**
     * @throws MyobApiException
     */
    private function taxCodeRef(string $code): ?array
    {
        $ref = Plugin::getInstance()->getReference()->taxCodeRef($code);

        if ($ref === null && trim($code) !== '') {
            throw new MyobApiException(Craft::t('my', 'MYOB has no tax code “{code}”.', ['code' => $code]));
        }

        return $ref;
    }

    // Odds and ends
    // -------------------------------------------------------------------------

    /**
     * @return array{UID: string}|null
     */
    private function resolveItem(LineItem $lineItem): ?array
    {
        $sku = trim($lineItem->getSku());

        if ($sku === '') {
            return null;
        }

        $item = Plugin::getInstance()->getReference()->findItem($sku);

        return ($item['UID'] ?? '') !== '' ? $item : null;
    }

    private function lineDescription(LineItem $lineItem): string
    {
        $description = trim($lineItem->getDescription());
        $sku = trim($lineItem->getSku());

        if ($sku !== '' && $description !== '' && !str_contains($description, $sku)) {
            $description = "$description ($sku)";
        }

        if ($description === '') {
            $description = $sku !== '' ? $sku : Craft::t('my', 'Item');
        }

        // MYOB shows the description on the invoice, so the quantity has to be visible somewhere
        // once a service line has thrown it away.
        if (Plugin::getInstance()->getSettings()->invoiceLayout === Settings::LAYOUT_SERVICE && $lineItem->qty > 1) {
            $description = $lineItem->qty . ' × ' . $description;
        }

        return mb_substr($description, 0, self::MAX_DESCRIPTION);
    }

    /**
     * The invoice number, or null to let MYOB auto-increment.
     */
    public function invoiceNumber(Order $order, bool $negate = false, int $sequence = 1): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->numberSource === 'myob') {
            return null;
        }

        $base = match ($settings->numberSource) {
            'number' => (string)$order->number,
            'shortNumber' => (string)$order->getShortNumber(),
            'id' => (string)$order->id,
            default => (string)($order->reference ?: $order->getShortNumber()),
        };

        $prefix = trim($settings->numberPrefix);

        if ($negate) {
            // A credit note cannot reuse the invoice's number, and MYOB does not auto-suffix. Nor
            // can two credit notes share one, so the second refund on an order is `CR2-…`, the
            // third `CR3-…`. The first stays plain `CR…`, which is what it has always been.
            $prefix .= 'CR' . ($sequence > 1 ? $sequence . '-' : '');
        }

        $number = $prefix . $base;

        if (mb_strlen($number) <= self::MAX_NUMBER) {
            return $number;
        }

        // MYOB caps Number at 13 characters. Trimming the *front* of the order reference keeps the
        // digits that vary — chopping the tail instead would collide every order in a batch.
        $keep = self::MAX_NUMBER - mb_strlen($prefix);

        if ($keep <= 0) {
            return mb_substr($prefix, 0, self::MAX_NUMBER);
        }

        return $prefix . mb_substr($base, -$keep);
    }

    private function invoiceDate(Order $order): string
    {
        $settings = Plugin::getInstance()->getSettings();

        $date = $settings->invoiceDateSource === 'today'
            ? new \DateTime()
            : ($order->dateOrdered ?? $order->dateCreated ?? new \DateTime());

        return (string)Dates::formatDay($date);
    }

    private function formatAddress(?Address $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $lines = array_filter([
            $address->fullName,
            $address->organization,
            $address->addressLine1,
            $address->addressLine2,
            $address->addressLine3,
            trim(implode(' ', array_filter([
                $address->locality,
                $address->administrativeArea,
                $address->postalCode,
            ]))),
            $address->countryCode,
        ], static fn($line) => trim((string)$line) !== '');

        return $lines !== [] ? implode("\n", $lines) : null;
    }

    private function render(string $template, Order $order): string
    {
        $template = trim($template);

        if ($template === '') {
            return '';
        }

        try {
            // `renderObjectTemplate()` calls its subject `object`; passing `element` as well means
            // `{{ element.reference }}` works too, which is what everybody types first.
            return trim(Craft::$app->getView()->renderObjectTemplate($template, $order, ['element' => $order]));
        } catch (\Throwable $e) {
            Craft::warning('My could not render an object template: ' . $e->getMessage(), __METHOD__);

            return '';
        }
    }

    private function storeCurrency(Order $order): ?string
    {
        try {
            return strtoupper($order->getStore()->getCurrency()->getCode());
        } catch (\Throwable) {
            return null;
        }
    }
}
