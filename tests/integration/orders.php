<?php
/**
 * The Orders index: the MYOB status sets (PHP and SQL held equal), the "MYOB status" condition
 * rule against real queries, the MYOB column over a live CP request, and the "Push to MYOB" bulk
 * action — in process and over HTTP, with its permission check.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-my/tests/integration/orders.php
 */

require __DIR__ . '/_support.php';

use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use justinholtweb\my\elements\actions\PushToMyob;
use justinholtweb\my\elements\conditions\MyobStatusConditionRule;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;
use justinholtweb\my\queue\jobs\PushOrder;
use justinholtweb\my\services\OrderStatus;

connectFixture();
$statuses = $plugin->getOrderStatus();
$variant = makeVariant();

// One completed order per status, built so each one tests a precedence rule.
$fixtures = [];

foreach (array_keys(OrderStatus::options()) as $status) {
    $fixtures[$status] = makeOrder($variant);
}

// A synced invoice does not save an order whose payment failed.
ledger($fixtures[OrderStatus::FAILED], SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_SYNCED, ['myobNumber' => 'INV-F']);
ledger($fixtures[OrderStatus::FAILED], SyncDocument::TYPE_PAYMENT, SyncDocument::STATUS_FAILED, ['lastError' => 'fixture']);
// In MYOB, at the wrong total.
ledger($fixtures[OrderStatus::MISMATCH], SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_SYNCED, ['myobNumber' => 'INV-M', 'lastError' => 'MYOB booked 22.00 but the order was 20.00.']);
// A pending payment does not make a synced invoice "pending".
ledger($fixtures[OrderStatus::SYNCED], SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_SYNCED, ['myobNumber' => 'INV-S']);
ledger($fixtures[OrderStatus::SYNCED], SyncDocument::TYPE_PAYMENT, SyncDocument::STATUS_PENDING);
ledger($fixtures[OrderStatus::PENDING], SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_PENDING);
ledger($fixtures[OrderStatus::SKIPPED], SyncDocument::TYPE_INVOICE, SyncDocument::STATUS_SKIPPED);

$ids = array_map(static fn(Order $o) => (int)$o->id, $fixtures);

// ---------------------------------------------------------------------------------------------
section('Status sets');

check('each fixture gets the status it was built for', function() use ($statuses, $ids) {
    $got = array_map(static fn(array $s) => $s['status'], $statuses->statuses(array_values($ids)));
    $want = array_flip($ids);

    foreach ($got as $id => $status) {
        if ($want[$id] !== $status) {
            return "order $id is $status, wanted {$want[$id]}";
        }
    }

    return count($got) === 6 ?: json_encode($got);
});

check('the invoice number comes with the status', function() use ($statuses, $ids) {
    $got = $statuses->statuses([$ids[OrderStatus::MISMATCH], $ids[OrderStatus::NONE]]);

    return $got[$ids[OrderStatus::MISMATCH]]['number'] === 'INV-M' && $got[$ids[OrderStatus::NONE]]['number'] === null ?: json_encode($got);
});

check('the SQL sets partition the fixtures exactly as the PHP does', function() use ($statuses, $ids) {
    foreach (array_keys(OrderStatus::options()) as $status) {
        $matched = Order::find()->id(array_values($ids))->status(null)->andWhere($statuses->condition($status))->ids();
        $matched = array_map('intval', $matched);

        if ($matched !== [$ids[$status]]) {
            return "$status matched " . json_encode($matched);
        }
    }

    return true;
});

check('an unknown status matches nothing, not everything', function() use ($statuses, $ids) {
    return Order::find()->id(array_values($ids))->status(null)->andWhere($statuses->condition('bogus'))->ids() === [] ?: 'matched';
});

// ---------------------------------------------------------------------------------------------
section('“MYOB status” condition rule');

check('it is registered on order conditions', function() {
    $condition = Craft::$app->getConditions()->createCondition(OrderCondition::class);
    $types = array_map(static fn($rule) => get_class($rule), $condition->getSelectableConditionRules());

    return in_array(MyobStatusConditionRule::class, $types, true) ?: json_encode($types);
});

check('“is one of” filters a real order query', function() use ($ids) {
    $rule = new MyobStatusConditionRule();
    $rule->setValues([OrderStatus::FAILED, OrderStatus::MISMATCH]);
    $query = Order::find()->id(array_values($ids))->status(null);
    $rule->modifyQuery($query);
    $got = array_map('intval', $query->ids());
    sort($got);
    $want = [$ids[OrderStatus::FAILED], $ids[OrderStatus::MISMATCH]];
    sort($want);

    return $got === $want ?: json_encode($got);
});

check('“is not one of” (the `ni` operator) is the complement', function() use ($ids) {
    $rule = new MyobStatusConditionRule();
    $rule->operator = 'ni';
    $rule->setValues([OrderStatus::SYNCED]);
    $query = Order::find()->id(array_values($ids))->status(null);
    $rule->modifyQuery($query);

    return count($query->ids()) === 5 && !in_array($ids[OrderStatus::SYNCED], array_map('intval', $query->ids()), true) ?: json_encode($query->ids());
});

check('it works through a whole OrderCondition, as a custom source uses it', function() use ($ids) {
    /** @var OrderCondition $condition */
    $condition = Craft::$app->getConditions()->createCondition(OrderCondition::class);
    $rule = new MyobStatusConditionRule();
    $rule->setValues([OrderStatus::NONE]);
    $condition->addConditionRule($rule);
    $query = Order::find()->id(array_values($ids))->status(null);
    $condition->modifyQuery($query);

    return array_map('intval', $query->ids()) === [$ids[OrderStatus::NONE]] ?: json_encode($query->ids());
});

check('matchElement agrees with the query', function() use ($fixtures) {
    $rule = new MyobStatusConditionRule();
    $rule->setValues([OrderStatus::PENDING]);

    return $rule->matchElement($fixtures[OrderStatus::PENDING]) && !$rule->matchElement($fixtures[OrderStatus::SYNCED]) ?: 'disagrees';
});

check('a stale or hand-edited value cannot reach the query', function() {
    $rule = new MyobStatusConditionRule();
    $rule->setValues(['failed', "x') OR 1=1 --", 'nonsense']);

    return $rule->getValues() === ['failed'] ?: json_encode($rule->getValues());
});

check('the rule validates (value only — the operator is uninitialised until set)', function() {
    $rule = new MyobStatusConditionRule();
    $rule->setValues(['synced']);

    return $rule->validate(['values']) ?: json_encode($rule->getErrors());
});

// ---------------------------------------------------------------------------------------------
section('The MYOB column');

check('“MYOB” is an available column on orders only', function() {
    $orders = Craft::$app->getElementSources()->getAvailableTableAttributes(Order::class);
    $entries = Craft::$app->getElementSources()->getAvailableTableAttributes(craft\elements\Entry::class);

    return isset($orders[Plugin::TABLE_ATTRIBUTE]) && !isset($entries[Plugin::TABLE_ATTRIBUTE]) ?: 'not registered as expected';
});

check('the cell shows status and number to someone who can see documents, and nothing to anyone else', function() use ($plugin, $fixtures) {
    $order = $fixtures[OrderStatus::MISMATCH];
    $plugin->getOrderStatus()->reset();
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $admin = $plugin->orderStatusHtml($order);
    Craft::$app->getUser()->setIdentity(null);
    $anonymous = $plugin->orderStatusHtml($order);

    return str_contains($admin, 'Booked at a different total') && str_contains($admin, 'status orange') && str_contains($admin, 'INV-M') && $anonymous === ''
        ?: json_encode([$admin, $anonymous]);
});

check('a whole page is looked up by the first cell, in one batch', function() use ($plugin, $ids) {
    $plugin->getOrderStatus()->reset();
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $orders = Order::find()->id(array_values($ids))->status(null)->all();
    // Only the first row's cell is rendered; the memo must already hold every row on the page.
    $plugin->orderStatusHtml($orders[0]);
    $memo = (new ReflectionProperty($plugin->getOrderStatus(), '_memo'))->getValue($plugin->getOrderStatus());
    Craft::$app->getUser()->setIdentity(null);
    $missing = array_diff(array_values($ids), array_keys($memo));

    return $missing === [] ?: 'not prefetched: ' . json_encode(array_values($missing));
});

check('over HTTP, the Orders index renders the column for each row', function() use ($ids) {
    $response = client('admin', 'claudepassword')('element-indexes/get-elements', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false, 'tableColumns' => [Plugin::TABLE_ATTRIBUTE]],
        'criteria' => ['id' => array_values($ids), 'status' => null, 'isCompleted' => null],
    ], 'POST', true, true);
    $html = json_decode((string)$response->getBody(), true)['html'] ?? '';

    return $response->getStatusCode() === 200 && str_contains($html, 'Booked at a different total') && str_contains($html, 'Failed')
        && str_contains($html, 'Not pushed') && str_contains($html, 'Skipped') && str_contains($html, 'INV-S')
        ?: $response->getStatusCode() . ': ' . substr(strip_tags((string)$response->getBody()), 0, 300);
});

// ---------------------------------------------------------------------------------------------
section('“Push to MYOB” element action');

/**
 * @return PushOrder[] the jobs queued since `$after`
 */
function queuedSince(int $after): array
{
    $blobs = (new Query())->select(['job'])->from(craft\db\Table::QUEUE)->where(['>', 'id', $after])->column();
    $jobs = array_map(static fn($blob) => unserialize(is_resource($blob) ? stream_get_contents($blob) : $blob), $blobs);

    return array_values(array_filter($jobs, static fn($job) => $job instanceof PushOrder));
}

check('it queues every selected completed order, unforced, and skips carts', function() use ($fixtures, $variant) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $cart = makeOrder($variant, false);
    $before = (int)(new Query())->from(craft\db\Table::QUEUE)->max('id');
    $action = new PushToMyob();
    $ok = $action->performAction(Order::find()->id([$fixtures[OrderStatus::NONE]->id, $fixtures[OrderStatus::FAILED]->id, $cart->id])->status(null));
    $jobs = queuedSince($before);
    $orderIds = array_map(static fn(PushOrder $job) => $job->orderId, $jobs);
    sort($orderIds);
    $want = [(int)$fixtures[OrderStatus::NONE]->id, (int)$fixtures[OrderStatus::FAILED]->id];
    sort($want);
    $forced = array_filter($jobs, static fn(PushOrder $job) => $job->force);
    Craft::$app->getUser()->setIdentity(null);

    return $ok && $orderIds === $want && $forced === [] && str_contains((string)$action->getMessage(), '1 skipped')
        ?: json_encode(['ok' => $ok, 'orders' => $orderIds, 'message' => $action->getMessage()]);
});

check('it refuses someone without “Push orders to MYOB”, even if they reach it', function() use ($fixtures) {
    Craft::$app->getUser()->setIdentity(null);
    $action = new PushToMyob();

    return $action->performAction(Order::find()->id($fixtures[OrderStatus::NONE]->id)->status(null)) === false
        && str_contains((string)$action->getMessage(), 'not allowed') ?: (string)$action->getMessage();
});

check('it refuses when MYOB is not connected', function() use ($fixtures) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    disconnectFixture();
    $action = new PushToMyob();
    $ok = $action->performAction(Order::find()->id($fixtures[OrderStatus::NONE]->id)->status(null));
    connectFixture();
    Craft::$app->getUser()->setIdentity(null);

    return $ok === false && str_contains((string)$action->getMessage(), 'not connected') ?: (string)$action->getMessage();
});

check('it is offered only to people who may push', function() {
    $offered = static function(?User $user): bool {
        Craft::$app->getUser()->setIdentity($user);
        $event = new craft\events\RegisterElementActionsEvent(['source' => '*', 'actions' => []]);
        yii\base\Event::trigger(Order::class, craft\base\Element::EVENT_REGISTER_ACTIONS, $event);

        return in_array(PushToMyob::class, array_map(static fn($a) => is_string($a) ? $a : (is_array($a) ? $a['type'] : get_class($a)), $event->actions), true);
    };

    $admin = $offered(User::find()->admin()->one());
    $nobody = $offered(null);
    Craft::$app->getUser()->setIdentity(null);

    return $admin && !$nobody ?: json_encode([$admin, $nobody]);
});

Craft::$app->getDb()->createCommand()->delete(craft\db\Table::QUEUE, ['like', 'description', 'MYOB', false])->execute();

// `editSite:` for the primary site: Commerce resolves the order index's store from the site the
// user may edit, and 500s without one.
$editSite = 'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid;
[$viewer, $viewerPassword] = makeUser('orders', [$editSite, 'accesscp', 'accessplugin-commerce', 'accessplugin-my', 'commerce-manageorders', 'commerce-editorders', 'my-viewdocuments']);
[$pusher, $pusherPassword] = makeUser('pusher', [$editSite, 'accesscp', 'accessplugin-commerce', 'accessplugin-my', 'commerce-manageorders', 'commerce-editorders', 'my-viewdocuments', 'my-pushorders']);

check('over HTTP, a user without “Push orders to MYOB” cannot run it', function() use ($viewer, $viewerPassword, $fixtures) {
    $before = (int)(new Query())->from(craft\db\Table::QUEUE)->max('id');
    $response = client($viewer->username, $viewerPassword)('element-indexes/perform-action', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false],
        'elementAction' => PushToMyob::class,
        'elementIds' => [$fixtures[OrderStatus::NONE]->id],
    ], 'POST', true, true);
    $data = json_decode((string)$response->getBody(), true);

    return $response->getStatusCode() >= 400 && empty($data['success']) && queuedSince($before) === []
        ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 300);
});

check('over HTTP, a user who may push queues the order', function() use ($pusher, $pusherPassword, $fixtures) {
    $before = (int)(new Query())->from(craft\db\Table::QUEUE)->max('id');
    $response = client($pusher->username, $pusherPassword)('element-indexes/perform-action', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false],
        'elementAction' => PushToMyob::class,
        'elementIds' => [$fixtures[OrderStatus::NONE]->id],
    ], 'POST', true, true);
    $jobs = queuedSince($before);
    Craft::$app->getDb()->createCommand()->delete(craft\db\Table::QUEUE, ['like', 'description', 'MYOB', false])->execute();

    return $response->getStatusCode() === 200 && count($jobs) === 1 && $jobs[0]->orderId === (int)$fixtures[OrderStatus::NONE]->id
        ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 300);
});

check('over HTTP, the column renders nothing for a user who cannot see MYOB documents', function() use ($ids, $editSite) {
    [$clerk, $clerkPassword] = makeUser('clerk', [$editSite, 'accesscp', 'accessplugin-commerce', 'commerce-manageorders']);
    $response = client($clerk->username, $clerkPassword)('element-indexes/get-elements', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false, 'tableColumns' => [Plugin::TABLE_ATTRIBUTE]],
        'criteria' => ['id' => array_values($ids), 'status' => null, 'isCompleted' => null],
    ], 'POST', true, true);
    $html = json_decode((string)$response->getBody(), true)['html'] ?? '';

    return $response->getStatusCode() === 200 && !str_contains($html, 'INV-M') && !str_contains($html, 'Booked at a different total')
        ?: $response->getStatusCode() . ': ' . substr(strip_tags((string)$response->getBody()), 0, 300);
});

finish();
