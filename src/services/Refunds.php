<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\helpers\Dates;
use justinholtweb\my\helpers\Money;
use justinholtweb\my\models\Settings;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;

/**
 * Refunds.
 *
 * MYOB has no "refund" transaction on a sale. A refund is two things: a **credit note**, which is
 * an invoice with negative amounts, and then optionally a **credit refund**, which is the money
 * actually leaving the bank account. Leaving the credit on the customer's account is a legitimate
 * choice — they may be about to buy something else — so which of the two happens is a setting.
 *
 * A refund for the whole order total mirrors the original invoice line for line, which is what a
 * bookkeeper expects to see. A partial refund cannot be attributed to particular lines from a
 * Commerce refund transaction (Commerce records an amount, not a basket), so it becomes a single
 * credit line for that amount. Pretending to know which items came back would be worse than
 * saying so plainly on the credit note.
 */
class Refunds extends Component
{
    private const MAX_MEMO = 255;

    /**
     * The credit note payload for a refund transaction.
     *
     * @throws MyobApiException
     */
    public function buildCreditNote(Order $order, Transaction $transaction, array $customerRef): array
    {
        $amount = Money::round((float)$transaction->amount);
        $invoices = Plugin::getInstance()->getInvoices();

        if (Money::equals($amount, (float)$order->getTotalPrice(), 0)) {
            // A full refund: mirror the invoice, every amount reversed.
            $payload = $invoices->buildPayload($order, $customerRef, true);
            $payload['JournalMemo'] = mb_substr($this->memo($order, $transaction), 0, self::MAX_MEMO);

            return $payload;
        }

        return $this->buildPartialCreditNote($order, $transaction, $customerRef, $amount);
    }

    /**
     * A one-line credit note for part of an order.
     *
     * @throws MyobApiException
     */
    private function buildPartialCreditNote(Order $order, Transaction $transaction, array $customerRef, float $amount): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $invoices = Plugin::getInstance()->getInvoices();
        $inclusive = $invoices->isTaxInclusive($order);

        $account = Plugin::getInstance()->getReference()->accountRef(
            $settings->salesAccount
        );

        if ($account === null) {
            throw new MyobApiException(Craft::t('my', 'MYOB has no account “{code}”, which is needed to raise a credit note.', [
                'code' => $settings->salesAccount,
            ]));
        }

        // A partial refund is credited at the same tax treatment as the order it came from, or the
        // GST on the credit will not match the GST that was charged.
        $taxed = Money::minor(Money::sum([$order->getTotalTax(), $order->getTotalTaxIncluded()])) !== 0;
        $taxCodeRef = Plugin::getInstance()->getReference()->taxCodeRef(
            $taxed ? $settings->defaultTaxCode : ($settings->zeroTaxCode ?: $settings->defaultTaxCode)
        );

        $line = [
            'Type' => 'Transaction',
            'Description' => Craft::t('my', 'Partial refund of order {reference}', [
                'reference' => $order->reference ?: $order->getShortNumber(),
            ]),
            'Total' => Money::round(-$amount),
            'Account' => $account,
        ];

        if ($taxCodeRef !== null) {
            $line['TaxCode'] = $taxCodeRef;
        }

        $payload = [
            'Customer' => $customerRef,
            'Date' => (string)Dates::formatDay($transaction->dateCreated ?? new \DateTime()),
            'IsTaxInclusive' => $inclusive,
            'Lines' => [$line],
            'JournalMemo' => mb_substr($this->memo($order, $transaction), 0, self::MAX_MEMO),
        ];

        $number = $invoices->invoiceNumber($order, true);

        if ($number !== null) {
            $payload['Number'] = $number;
        }

        if (trim($settings->invoiceDeliveryStatus) !== '') {
            $payload['InvoiceDeliveryStatus'] = trim($settings->invoiceDeliveryStatus);
        }

        return $payload;
    }

    /**
     * Pay a credit note back out of a bank account.
     *
     * @throws MyobApiException
     */
    public function buildCreditRefund(Order $order, Transaction $transaction, array $customerRef, string $creditNoteUid): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $account = Plugin::getInstance()->getReference()->accountRef(
            $settings->refundAccount ?: $settings->paymentAccount
        );

        if ($account === null) {
            throw new MyobApiException(Craft::t('my', 'MYOB has no account “{code}” to refund from. Set a refund account on the settings screen.', [
                'code' => $settings->refundAccount ?: $settings->paymentAccount,
            ]));
        }

        return [
            'Account' => $account,
            'Invoice' => ['UID' => $creditNoteUid],
            'Customer' => $customerRef,
            'Date' => (string)Dates::formatDay($transaction->dateCreated ?? new \DateTime()),
            'Amount' => Money::round((float)$transaction->amount),
            'Memo' => mb_substr($this->memo($order, $transaction), 0, self::MAX_MEMO),
        ];
    }

    /**
     * @throws MyobApiException
     */
    public function pushCreditNote(Order $order, Transaction $transaction, array $customerRef): mixed
    {
        $payload = $this->buildCreditNote($order, $transaction, $customerRef);

        return Plugin::getInstance()->getApi()->post(
            Plugin::getInstance()->getInvoices()->endpoint(),
            $payload,
            ['action' => 'creditnote.create', 'orderId' => $order->id],
        );
    }

    /**
     * @throws MyobApiException
     */
    public function pushCreditRefund(Order $order, Transaction $transaction, array $customerRef, SyncDocument $creditNote): mixed
    {
        $payload = $this->buildCreditRefund($order, $transaction, $customerRef, (string)$creditNote->myobUid);

        return Plugin::getInstance()->getApi()->post('Sale/CreditRefund', $payload, [
            'action' => 'refund.create',
            'orderId' => $order->id,
        ]);
    }

    public function shouldRefundToBank(): bool
    {
        return Plugin::getInstance()->getSettings()->refundMode === Settings::REFUND_CREDIT_NOTE_AND_REFUND;
    }

    private function memo(Order $order, Transaction $transaction): string
    {
        $parts = [Craft::t('my', 'Refund of order {reference}', [
            'reference' => $order->reference ?: $order->getShortNumber(),
        ])];

        if (trim((string)$transaction->note) !== '') {
            $parts[] = trim((string)$transaction->note);
        } elseif (trim((string)$transaction->reference) !== '') {
            $parts[] = trim((string)$transaction->reference);
        }

        return implode(' — ', $parts);
    }
}
