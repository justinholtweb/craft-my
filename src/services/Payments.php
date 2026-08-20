<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\helpers\Dates;
use justinholtweb\my\helpers\Money;
use justinholtweb\my\models\Settings;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;

/**
 * Customer payments.
 *
 * An invoice with no payment against it sits in MYOB's "unpaid" list forever, and a merchant who
 * has to close a hundred of those by hand every week will turn the integration off. So every
 * successful Commerce payment becomes a `Sale/CustomerPayment` applied to the order's invoice.
 *
 * One MYOB payment per Commerce transaction, keyed on the transaction hash — an order paid in two
 * instalments gets two payments, and a retried queue job gets none extra.
 */
class Payments extends Component
{
    private const MAX_MEMO = 255;

    /**
     * The Commerce transactions that should become MYOB payments.
     *
     * Authorizations are excluded on purpose: the money has not moved, and booking it would
     * overstate the bank balance until the capture arrives.
     *
     * @return Transaction[]
     */
    public function getPayableTransactions(Order $order): array
    {
        $out = [];

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->status !== TransactionRecord::STATUS_SUCCESS) {
                continue;
            }

            if (!in_array($transaction->type, [TransactionRecord::TYPE_PURCHASE, TransactionRecord::TYPE_CAPTURE], true)) {
                continue;
            }

            if (Money::minor((float)$transaction->amount) <= 0) {
                continue;
            }

            $out[] = $transaction;
        }

        return $out;
    }

    /**
     * The refund transactions that should become credit notes.
     *
     * @return Transaction[]
     */
    public function getRefundTransactions(Order $order): array
    {
        $out = [];

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->status !== TransactionRecord::STATUS_SUCCESS) {
                continue;
            }

            if ($transaction->type !== TransactionRecord::TYPE_REFUND) {
                continue;
            }

            if (Money::minor((float)$transaction->amount) <= 0) {
                continue;
            }

            $out[] = $transaction;
        }

        return $out;
    }

    /**
     * The MYOB payload for one payment against one invoice.
     *
     * @throws MyobApiException
     */
    public function buildPayload(
        Order $order,
        Transaction $transaction,
        array $customerRef,
        string $invoiceUid,
        ?string $invoiceRowVersion = null,
    ): array {
        $settings = Plugin::getInstance()->getSettings();
        $amount = Money::round((float)$transaction->amount);

        $invoiceLine = [
            'UID' => $invoiceUid,
            'Type' => 'Invoice',
            'AmountApplied' => $amount,
        ];

        if ($invoiceRowVersion !== null && $invoiceRowVersion !== '') {
            $invoiceLine['RowVersion'] = $invoiceRowVersion;
        }

        $payload = [
            'DepositTo' => $settings->depositTo,
            'Customer' => $customerRef,
            'Date' => (string)Dates::formatDay($transaction->dateCreated ?? $order->dateOrdered),
            'AmountReceived' => $amount,
            'PaymentMethod' => $this->paymentMethod($transaction),
            'Invoices' => [$invoiceLine],
        ];

        if ($settings->depositTo === Settings::DEPOSIT_ACCOUNT) {
            $account = Plugin::getInstance()->getReference()->accountRef($settings->paymentAccount);

            if ($account === null) {
                throw new MyobApiException(Craft::t('my', 'MYOB has no account “{code}” to bank payments into. Check the settings screen.', [
                    'code' => $settings->paymentAccount,
                ]));
            }

            $payload['Account'] = $account;
        }

        $memo = $this->memo($order, $transaction);

        if ($memo !== '') {
            $payload['Memo'] = mb_substr($memo, 0, self::MAX_MEMO);
        }

        return $payload;
    }

    /**
     * Record a payment in MYOB.
     *
     * A payment carries the invoice's `RowVersion`, which MYOB uses to check the invoice has not
     * moved since it was read. It moves more often than you would think — anything that touches
     * the invoice in MYOB bumps it — so a conflict is answered by re-reading the invoice and
     * trying once more, rather than by failing a payment that is perfectly valid.
     *
     * @throws MyobApiException
     */
    public function push(Order $order, Transaction $transaction, array $customerRef, SyncDocument $invoice): mixed
    {
        $payload = $this->buildPayload($order, $transaction, $customerRef, (string)$invoice->myobUid, $invoice->rowVersion);
        $api = Plugin::getInstance()->getApi();

        try {
            return $api->post('Sale/CustomerPayment', $payload, [
                'action' => 'payment.create',
                'orderId' => $order->id,
            ]);
        } catch (MyobApiException $e) {
            if (!$e->isConflict()) {
                throw $e;
            }

            $fresh = $api->get('Sale/Invoice/' . $this->invoiceKind() . '/' . $invoice->myobUid, [
                'action' => 'invoice.read',
                'orderId' => $order->id,
            ]);

            $rowVersion = is_array($fresh) ? (string)($fresh['RowVersion'] ?? '') : '';

            if ($rowVersion === '' || $rowVersion === $invoice->rowVersion) {
                throw $e;
            }

            $invoice->rowVersion = $rowVersion;

            $payload = $this->buildPayload($order, $transaction, $customerRef, (string)$invoice->myobUid, $rowVersion);

            return $api->post('Sale/CustomerPayment', $payload, [
                'action' => 'payment.create',
                'orderId' => $order->id,
            ]);
        }
    }

    /**
     * MYOB only accepts payment methods from its own fixed list, so an unmapped gateway falls back
     * to the configured default rather than sending the gateway's handle and getting a 400.
     */
    public function paymentMethod(Transaction $transaction): string
    {
        $settings = Plugin::getInstance()->getSettings();

        $handle = null;

        try {
            $handle = $transaction->getGateway()?->handle;
        } catch (\Throwable) {
            // A gateway can be uninstalled while its transactions remain.
        }

        $mapped = $handle !== null ? trim((string)($settings->paymentMethodMap[$handle] ?? '')) : '';

        if ($mapped !== '' && in_array($mapped, Settings::PAYMENT_METHODS, true)) {
            return $mapped;
        }

        return in_array($settings->defaultPaymentMethod, Settings::PAYMENT_METHODS, true)
            ? $settings->defaultPaymentMethod
            : 'Other';
    }

    private function memo(Order $order, Transaction $transaction): string
    {
        $parts = [Craft::t('my', 'Payment for order {reference}', [
            'reference' => $order->reference ?: $order->getShortNumber(),
        ])];

        $gateway = null;

        try {
            $gateway = $transaction->getGateway()?->name;
        } catch (\Throwable) {
        }

        if ($gateway) {
            $parts[] = $gateway;
        }

        if (trim((string)$transaction->reference) !== '') {
            $parts[] = trim((string)$transaction->reference);
        }

        return implode(' — ', $parts);
    }

    private function invoiceKind(): string
    {
        return Plugin::getInstance()->getSettings()->invoiceLayout === Settings::LAYOUT_ITEM
            ? 'Item'
            : 'Service';
    }
}
