<?php

namespace justinholtweb\my\queue\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use justinholtweb\my\models\LogEntry;
use justinholtweb\my\Plugin;

/**
 * Push one order to MYOB.
 *
 * Checkout never waits on MYOB: the order-complete handler queues this and returns. A MYOB
 * outage, a rate limit or a slow company file then delays an invoice rather than a customer's
 * payment.
 */
class PushOrder extends BaseJob
{
    public int $orderId;

    /**
     * Push even if the order has already been invoiced.
     */
    public bool $force = false;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null || !Plugin::commerceIsReady()) {
            return;
        }

        $order = Order::find()->id($this->orderId)->status(null)->one();

        if (!$order instanceof Order) {
            // The order was deleted while the job waited. Nothing to do, and nothing wrong.
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('my', 'Resolving customer'));

        $result = $plugin->getSync()->pushOrder($order, $this->force);

        $this->setProgress($queue, 1);

        if ($result['ok']) {
            return;
        }

        $message = implode(' ', array_filter($result['messages'])) ?: Craft::t('my', 'The push failed.');

        $plugin->getLog()->write('push', [
            'level' => LogEntry::LEVEL_ERROR,
            'orderId' => $this->orderId,
            'summary' => Craft::t('my', 'Push failed for order {reference}', [
                'reference' => $order->reference ?: $order->getShortNumber(),
            ]),
            'message' => $message,
        ]);

        // Throwing is what gets the job retried. The ledger row survives either way, so a merchant
        // can see what happened even after the queue has given up.
        throw new \RuntimeException($message);
    }

    /**
     * @inheritdoc
     *
     * One push is a customer lookup, the invoice, and each payment and refund — and every one of
     * those requests may be retried three times inside `Api`, honouring a `Retry-After` of up to a
     * minute. Craft's default of 300 seconds can be outlived by a bad afternoon at MYOB, and a job
     * that outlives its TTR is handed to a second worker while the first is still running.
     */
    public function getTtr(): int
    {
        return 900;
    }

    /**
     * @inheritdoc
     *
     * MYOB's failures are mostly transient (rate limits, maintenance windows), so this is worth
     * being patient about; the number of attempts is a setting.
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < Plugin::getInstance()->getSettings()->maxAttempts;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('my', 'Pushing order to MYOB');
    }
}
