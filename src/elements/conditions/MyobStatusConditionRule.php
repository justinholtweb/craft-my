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
     * Keeps every value that was chosen, known or not. Stripping unknown ones here would turn a
     * saved "is one of" whose statuses have all since been renamed or removed into an empty rule —
     * no filter, every order — and the next save of that source would lose them for good. Only
     * {@see knownValues()} ever reaches SQL, so a stale or hand-edited value still cannot.
     *
     * @param string|string[] $values
     */
    public function setValues(array|string $values): void
    {
        if ($values === '') {
            parent::setValues([]);

            return;
        }

        parent::setValues(array_values(array_map('strval', array_filter((array)$values, 'is_scalar'))));
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
        // Nothing chosen: no filter (Craft's convention for an empty multi-select).
        if ($this->getValues() === []) {
            return;
        }

        $known = $this->knownValues();
        $not = $this->operator === self::OPERATOR_NOT_IN;

        // Chosen, but none of them a status My still knows: "is one of" matches nothing and
        // "is not one of" excludes nothing. Never fall through to "no filter" for "is one of".
        if ($known === []) {
            if (!$not) {
                $query->andWhere('0=1');
            }

            return;
        }

        $statuses = Plugin::getInstance()->getOrderStatus();
        $condition = ['or'];

        foreach ($known as $status) {
            $condition[] = $statuses->condition($status);
        }

        $query->andWhere($not ? ['not', $condition] : $condition);
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        // Every stored value is compared, known or not. An order's own status is always a known
        // one, so a stale value never matches: the same answer modifyQuery() gives.
        if (!$element instanceof Order || !$element->id) {
            return $this->matchValue(OrderStatus::NONE);
        }

        $status = Plugin::getInstance()->getOrderStatus()->statuses([$element->id])[$element->id]['status'] ?? OrderStatus::NONE;

        return $this->matchValue($status);
    }

    /**
     * The chosen values My knows — the only ones that ever reach the query.
     *
     * @return string[]
     */
    private function knownValues(): array
    {
        return array_values(array_intersect($this->getValues(), array_map('strval', array_keys(OrderStatus::options()))));
    }
}
