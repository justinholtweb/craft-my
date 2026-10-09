<?php

namespace justinholtweb\my\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\my\Plugin;

/**
 * "Push to MYOB" on the Orders index: queue a push for every selected, completed order.
 *
 * Always through the queue — a hundred orders inline is a request that times out halfway. Never
 * forced: an invoiced order is not invoiced again, and selecting one is harmless — it picks up any
 * payment or refund still missing and retries anything that failed. Re-sending an invoice on
 * purpose stays a one-order decision, on the order's own MYOB panel.
 *
 * Choosing orders by hand overrides the trigger statuses, as "Push now" on one order does.
 */
class PushToMyob extends ElementAction
{
    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('my', 'Push to MYOB');
    }

    /**
     * @inheritdoc
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        // The action is only offered to people who may push, but the request can be made by
        // anyone who can see the index.
        $user = Craft::$app->getUser();

        if (!$user->checkPermission('my-pushOrders')) {
            $this->setMessage(Craft::t('my', 'You are not allowed to push orders to MYOB.'));

            return false;
        }

        $plugin = Plugin::getInstance();

        if (!$plugin->getAuth()->getConnection()->isConnected()) {
            $this->setMessage(Craft::t('my', 'MYOB is not connected.'));

            return false;
        }

        $identity = $user->getIdentity();
        $elements = Craft::$app->getElements();
        $queued = 0;
        $skipped = 0;

        /** @var Order $order */
        foreach ((clone $query)->status(null)->all() as $order) {
            // A cart is not a sale, and an order this user may not open is not theirs to push —
            // the same rule the order panel's "Push now" applies.
            if (!$order instanceof Order || !$order->isCompleted || $identity === null || !$elements->canView($order, $identity)) {
                $skipped++;
                continue;
            }

            $plugin->getSync()->queue($order);
            $queued++;
        }

        $this->setMessage($skipped > 0
            ? Craft::t('my', '{queued} orders queued for MYOB; {skipped} skipped (not completed, or not yours to open).', ['queued' => $queued, 'skipped' => $skipped])
            : Craft::t('my', '{queued} orders queued for MYOB.', ['queued' => $queued]));

        return $queued > 0;
    }
}
