<?php

namespace justinholtweb\my\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;
use yii\console\ExitCode;

/**
 * Pushing orders to MYOB from the command line.
 *
 * The console is where a merchant recovers from a bad afternoon: a backfill after connecting for
 * the first time, a retry of everything that failed while MYOB was down, a dry run to see what
 * would be sent before sending it.
 */
class SyncController extends Controller
{
    /**
     * Build the payload and print it, without sending anything.
     */
    public bool $dryRun = false;

    /**
     * Push again even if the order is already invoiced.
     */
    public bool $force = false;

    /**
     * How many orders a backfill will do.
     */
    public int $limit = 100;

    /**
     * Push through the queue instead of doing the work here.
     */
    public bool $queue = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'order' => array_merge(parent::options($actionID), ['dryRun', 'force']),
            'backfill' => array_merge(parent::options($actionID), ['limit', 'force', 'queue']),
            'retry' => array_merge(parent::options($actionID), ['limit', 'queue']),
            default => parent::options($actionID),
        };
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        return ['d' => 'dryRun', 'f' => 'force', 'l' => 'limit', 'q' => 'queue'];
    }

    /**
     * Push one order.
     *
     * @param string $reference The order's reference, number or ID.
     */
    public function actionOrder(string $reference): int
    {
        $order = $this->findOrder($reference);

        if ($order === null) {
            $this->stderr("No order matches “{$reference}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();

        if ($this->dryRun) {
            try {
                // Read-only: a dry run must not create (or update) a card in MYOB.
                $customerRef = $plugin->getContacts()->resolveForOrder($order, true);
                $payload = $plugin->getInvoices()->buildPayload($order, $customerRef);
                $check = $plugin->getInvoices()->reconcile($order, $payload);
            } catch (MyobApiException $e) {
                $this->stderr($e->getMessage() . "\n", Console::FG_RED);

                return ExitCode::UNAVAILABLE;
            }

            $this->stdout('POST ' . $plugin->getInvoices()->endpoint() . "\n", Console::FG_CYAN);
            $this->stdout(Json::encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $this->stdout(sprintf(
                "\nWould book %s; the order is %s.%s\n",
                number_format($check['computed'], 2),
                number_format($check['expected'], 2),
                $check['ok'] ? '' : '  ← these disagree',
            ), $check['ok'] ? Console::FG_GREEN : Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $result = $plugin->getSync()->pushOrder($order, $this->force);

        foreach (array_filter($result['messages']) as $message) {
            $this->stdout("  $message\n");
        }

        if (!$result['ok']) {
            $this->stderr("Push failed.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout(sprintf(
            "Pushed order %s as %s.\n",
            $order->reference ?: $order->getShortNumber(),
            $result['invoice']?->myobNumber ?: '(number assigned by MYOB)',
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Push every completed order that has never reached MYOB.
     */
    public function actionBackfill(): int
    {
        $sync = Plugin::getInstance()->getSync();
        $orderIds = $sync->findUnsyncedOrderIds($this->limit);

        if ($orderIds === []) {
            $this->stdout("Nothing to backfill.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(count($orderIds) . " orders to push.\n");

        return $this->pushEach($orderIds);
    }

    /**
     * Try again on everything that failed.
     */
    public function actionRetry(): int
    {
        $sync = Plugin::getInstance()->getSync();

        $orderIds = [];

        foreach ($sync->getDocuments(['status' => SyncDocument::STATUS_FAILED], $this->limit) as $document) {
            $orderIds[$document->orderId] = $document->orderId;
        }

        if ($orderIds === []) {
            $this->stdout("Nothing has failed.\n", Console::FG_GREEN);
            $this->checkAlerts();

            return ExitCode::OK;
        }

        $this->stdout(count($orderIds) . " orders to retry.\n");

        $code = $this->pushEach(array_values($orderIds));
        $this->checkAlerts();

        return $code;
    }

    /**
     * Cron runs `retry` when nothing else is running, so it is where an incident is seen to clear.
     */
    private function checkAlerts(): void
    {
        foreach (Plugin::getInstance()->getAlerts()->check() as $result) {
            if ($result['transition'] !== null) {
                $this->stdout(sprintf("Alert %s: %s\n", $result['transition'], $result['incident']), Console::FG_YELLOW);
            }
        }
    }

    /**
     * What the ledger looks like.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $connection = $plugin->getAuth()->getConnection();

        $this->stdout("Connection\n", Console::FG_CYAN);
        $this->stdout('  Mode:         ' . $connection->mode . "\n");
        $this->stdout('  Company file: ' . ($connection->getLabel() ?: '(none)') . "\n");
        $this->stdout('  Connected:    ' . ($connection->isConnected() ? 'yes' : 'no') . "\n");

        $counts = $plugin->getSync()->getCounts();

        $this->stdout("\nDocuments\n", Console::FG_CYAN);

        foreach ($counts as $status => $count) {
            $this->stdout(sprintf("  %-10s %d\n", $status, $count));
        }

        $unsynced = count($plugin->getSync()->findUnsyncedOrderIds(1000));
        $this->stdout("\n  " . $unsynced . " completed orders have never been invoiced.\n");

        return ExitCode::OK;
    }

    /**
     * Push a list of orders, reporting as it goes.
     *
     * Named `pushEach`, not `run`: `yii\base\Controller::run()` is public, and a private method
     * of the same name is a **compile-time fatal** the moment the class is autoloaded — so the
     * whole console stops working, not just this command. Same family as `Model::load()` and
     * `Model::rules()`.
     *
     * @param int[] $orderIds
     */
    private function pushEach(array $orderIds): int
    {
        $sync = Plugin::getInstance()->getSync();
        $failed = 0;

        foreach ($orderIds as $orderId) {
            $order = Order::find()->id($orderId)->status(null)->one();

            if (!$order instanceof Order) {
                continue;
            }

            $label = $order->reference ?: $order->getShortNumber();

            if ($this->queue) {
                $sync->queue($order, $this->force);
                $this->stdout("  queued $label\n");
                continue;
            }

            $result = $sync->pushOrder($order, $this->force);

            if ($result['ok']) {
                $this->stdout("  ✓ $label\n", Console::FG_GREEN);
                continue;
            }

            $failed++;
            $this->stdout("  ✗ $label — " . implode(' ', array_filter($result['messages'])) . "\n", Console::FG_RED);
        }

        if ($failed > 0) {
            $this->stderr("\n$failed failed.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        return ExitCode::OK;
    }

    private function findOrder(string $reference): ?Order
    {
        $order = Order::find()->reference($reference)->status(null)->one();

        if ($order instanceof Order) {
            return $order;
        }

        if (ctype_digit($reference)) {
            $order = Order::find()->id((int)$reference)->status(null)->one();

            if ($order instanceof Order) {
                return $order;
            }
        }

        $order = Order::find()->number($reference)->status(null)->one();

        return $order instanceof Order ? $order : null;
    }
}
