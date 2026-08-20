<?php

namespace justinholtweb\my\twig;

use craft\commerce\elements\Order;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;
use yii\base\BaseObject;

/**
 * `craft.myob` — read-only access to what My knows about an order.
 *
 * Named `myob` rather than `my`, because `craft.my` reads like a possessive and would be mistaken
 * for a Craft feature in every template it appeared in.
 */
class MyVariable extends BaseObject
{
    /**
     * The MYOB invoice for an order, or null if it has not been pushed.
     */
    public function invoice(Order|int|null $order): ?SyncDocument
    {
        $id = $order instanceof Order ? $order->id : $order;

        if (!$id) {
            return null;
        }

        return Plugin::getInstance()->getSync()->getInvoiceForOrder($id);
    }

    /**
     * Everything MYOB holds for an order — invoice, payments, credit notes.
     *
     * @return SyncDocument[]
     */
    public function documents(Order|int|null $order): array
    {
        $id = $order instanceof Order ? $order->id : $order;

        if (!$id) {
            return [];
        }

        return Plugin::getInstance()->getSync()->getDocumentsForOrder($id);
    }

    /**
     * Whether an order has made it to MYOB.
     */
    public function isInvoiced(Order|int|null $order): bool
    {
        return (bool)$this->invoice($order)?->isSynced();
    }

    /**
     * Whether MYOB is connected at all.
     */
    public function isConnected(): bool
    {
        return Plugin::getInstance()->getAuth()->getConnection()->isConnected();
    }

    /**
     * The connected company file's name, for a status line in a dashboard template.
     */
    public function companyFile(): ?string
    {
        $label = Plugin::getInstance()->getAuth()->getConnection()->getLabel();

        return $label !== '' ? $label : null;
    }
}
