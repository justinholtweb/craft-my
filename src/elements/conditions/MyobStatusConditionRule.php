<?php

namespace justinholtweb\my\elements\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\my\Plugin;
use justinholtweb\my\services\OrderStatus;

/**
 * "MYOB status" on Commerce's Orders index filters (and anywhere else an order condition is built:
 * custom sources, discounts, shipping rules).
 *
 * The query side and the element side are both {@see OrderStatus}'s sets, so a custom source
 * "Failed in MYOB" and the MYOB column on the same rows can never disagree.
 *
 * Registered unconditionally — never behind a setting or a permission: Craft drops an
 * unregistered rule from a saved condition, and a custom source would silently widen to every
 * order.
 */
class MyobStatusConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('my', 'MYOB status');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    /**
     * Only the statuses My knows: a hand-edited or stale condition cannot smuggle anything else
     * into the query.
     *
     * @param string|string[] $values
     */
    public function setValues(array|string $values): void
    {
        parent::setValues(array_values(array_intersect((array)$values, array_keys(OrderStatus::options()))));
    }

    /**
     * @inheritdoc
     */
    protected function options(): array
    {
        $options = [];

        foreach (OrderStatus::options() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        $values = $this->getValues();

        if ($values === []) {
            return;
        }

        $statuses = Plugin::getInstance()->getOrderStatus();
        $condition = ['or'];

        foreach ($values as $status) {
            $condition[] = $statuses->condition($status);
        }

        $query->andWhere($this->operator === self::OPERATOR_NOT_IN ? ['not', $condition] : $condition);
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        if (!$element instanceof Order || !$element->id) {
            return $this->matchValue(OrderStatus::NONE);
        }

        $status = Plugin::getInstance()->getOrderStatus()->statuses([$element->id])[$element->id]['status'] ?? OrderStatus::NONE;

        return $this->matchValue($status);
    }
}
