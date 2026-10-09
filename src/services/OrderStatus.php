<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use justinholtweb\my\db\Table;
use justinholtweb\my\models\SyncDocument;

/**
 * Where an order stands with MYOB, as one word — for the Orders index column and the "MYOB
 * status" condition rule.
 *
 * Six statuses, in precedence order. {@see statuses()} works them out in PHP for a page of orders;
 * {@see condition()} builds exactly the same sets in SQL for the condition rule; the test suite
 * holds the two to partitioning the same orders identically. Change one, change both.
 *
 * - `failed` — any of its documents is `failed`: the invoice, a payment, a credit note or a
 *   refund. Something is waiting for a human.
 * - `mismatch` — its invoice is in MYOB, at a different total from the order.
 * - `synced` — its invoice is in MYOB and agrees with the order.
 * - `pending` — its invoice is claimed and not yet confirmed (queued, retrying, or interrupted).
 * - `skipped` — deliberately not sent.
 * - `none` — never pushed.
 */
class OrderStatus extends Component
{
    public const FAILED = 'failed';
    public const MISMATCH = 'mismatch';
    public const SYNCED = 'synced';
    public const PENDING = 'pending';
    public const SKIPPED = 'skipped';
    public const NONE = 'none';

    /**
     * order id => [status, MYOB invoice number], for the life of the request.
     *
     * @var array<int, array{status: string, number: string|null}>
     */
    private array $_memo = [];

    /**
     * @return array<string, string> status => label
     */
    public static function options(): array
    {
        return [
            self::SYNCED => Craft::t('my', 'Synced'),
            self::MISMATCH => Craft::t('my', 'Booked at a different total'),
            self::FAILED => Craft::t('my', 'Failed'),
            self::PENDING => Craft::t('my', 'Pending'),
            self::SKIPPED => Craft::t('my', 'Skipped'),
            self::NONE => Craft::t('my', 'Not pushed'),
        ];
    }

    /**
     * Craft's status-dot colour for each.
     */
    public static function color(string $status): string
    {
        return match ($status) {
            self::SYNCED => 'green',
            self::MISMATCH => 'orange',
            self::FAILED => 'red',
            self::PENDING => 'yellow',
            default => 'disabled',
        };
    }

    /**
     * Statuses and invoice numbers for any number of orders, in one query.
     *
     * @param int[] $orderIds
     * @return array<int, array{status: string, number: string|null}>
     */
    public function statuses(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));

        if ($orderIds === []) {
            return [];
        }

        $failed = [];
        $invoices = [];

        $rows = (new Query())
            ->select(['orderId', 'docType', 'status', 'lastError', 'myobNumber'])
            ->from([Table::DOCUMENTS])
            ->where(['orderId' => $orderIds])
            ->all();

        foreach ($rows as $row) {
            $id = (int)$row['orderId'];

            if ($row['status'] === SyncDocument::STATUS_FAILED) {
                $failed[$id] = true;
            }

            if ($row['docType'] === SyncDocument::TYPE_INVOICE) {
                $invoices[$id] = new SyncDocument([
                    'status' => (string)$row['status'],
                    'lastError' => $row['lastError'],
                    'myobNumber' => $row['myobNumber'],
                ]);
            }
        }

        $out = [];

        foreach ($orderIds as $id) {
            $invoice = $invoices[$id] ?? null;

            $status = match (true) {
                isset($failed[$id]) => self::FAILED,
                $invoice === null => self::NONE,
                $invoice->isMismatched() => self::MISMATCH,
                $invoice->status === SyncDocument::STATUS_SYNCED => self::SYNCED,
                $invoice->status === SyncDocument::STATUS_PENDING => self::PENDING,
                $invoice->status === SyncDocument::STATUS_SKIPPED => self::SKIPPED,
                default => self::NONE,
            };

            $out[$id] = [
                'status' => $status,
                'number' => $invoice?->myobNumber ?: null,
            ];
        }

        return $out;
    }

    /**
     * One order's status and number, from the per-request memo that {@see prefetch()} fills for a
     * whole index page at once.
     *
     * @return array{status: string, number: string|null}
     */
    public function forOrder(int $orderId): array
    {
        if (!isset($this->_memo[$orderId])) {
            $this->prefetch([$orderId]);
        }

        return $this->_memo[$orderId] ?? ['status' => self::NONE, 'number' => null];
    }

    /**
     * @param int[] $orderIds
     */
    public function prefetch(array $orderIds): void
    {
        $missing = array_diff(array_map('intval', $orderIds), array_keys($this->_memo));

        if ($missing !== []) {
            $this->_memo = $this->statuses($missing) + $this->_memo;
        }
    }

    /**
     * Forget the memo — after a push changes what it would say.
     */
    public function reset(): void
    {
        $this->_memo = [];
    }

    /**
     * A WHERE condition on an order id column that is true for orders in exactly this status.
     *
     * Each status excludes every one above it in precedence, so the six sets partition the orders:
     * an order is in one of them and only one. An invoice row is unique per order, so its states
     * cannot overlap either.
     *
     * @return array<int|string, mixed>
     */
    public function condition(string $status, string $idColumn = 'elements.id'): array
    {
        $notFailed = ['not in', $idColumn, $this->failedSubquery()];
        $mismatch = ['in', $idColumn, $this->invoiceSubquery()->andWhere(Sync::mismatchCondition())];

        return match ($status) {
            self::FAILED => ['in', $idColumn, $this->failedSubquery()],
            self::MISMATCH => ['and', $mismatch, $notFailed],
            self::SYNCED => [
                'and',
                ['in', $idColumn, $this->invoiceSubquery(SyncDocument::STATUS_SYNCED)],
                ['not', $mismatch],
                $notFailed,
            ],
            self::PENDING => ['and', ['in', $idColumn, $this->invoiceSubquery(SyncDocument::STATUS_PENDING)], $notFailed],
            self::SKIPPED => ['and', ['in', $idColumn, $this->invoiceSubquery(SyncDocument::STATUS_SKIPPED)], $notFailed],
            self::NONE => ['and', ['not in', $idColumn, $this->invoiceSubquery()], $notFailed],
            // An unknown status matches nothing, rather than everything.
            default => ['in', $idColumn, (new Query())->select(['orderId'])->from([Table::DOCUMENTS])->where('0=1')],
        };
    }

    /**
     * Order ids with an invoice row, optionally in one stored status.
     */
    private function invoiceSubquery(?string $status = null): Query
    {
        $query = (new Query())
            ->select(['orderId'])
            ->from([Table::DOCUMENTS])
            ->where(['docType' => SyncDocument::TYPE_INVOICE]);

        if ($status !== null) {
            $query->andWhere(['status' => $status]);
        }

        return $query;
    }

    /**
     * Order ids with any document left failed.
     */
    private function failedSubquery(): Query
    {
        return (new Query())
            ->select(['orderId'])
            ->from([Table::DOCUMENTS])
            ->where(['status' => SyncDocument::STATUS_FAILED]);
    }
}
