<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\my\db\Table;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\models\LogEntry;
use justinholtweb\my\models\Settings;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;
use yii\db\IntegrityException;

/**
 * The sync ledger, and the orchestration on top of it.
 *
 * **`record()` is the only place a document row is written.** Queue jobs, the CP "Push now"
 * button, the console command and the retry path all land there, so the "has this already gone to
 * MYOB?" decision is made once and cannot disagree with itself.
 *
 * The ordering matters more than it looks. For each document the row is **claimed first**, in
 * `pending`, and only then is the HTTP call made. A crash in between leaves a `pending` row rather
 * than nothing — and a `pending` row is the signal to go and *ask* MYOB whether the document
 * exists before POSTing it again. Claiming afterwards would mean a lost response is
 * indistinguishable from a failed request, which is how duplicate invoices are made.
 */
class Sync extends Component
{
    /**
     * Mutex name prefix for the per-order push lock.
     */
    public const PUSH_LOCK_PREFIX = 'my:push:';

    /**
     * Seconds to wait for another push of the same order to finish before giving up.
     */
    private const PUSH_LOCK_TIMEOUT = 10;

    // Deciding
    // -------------------------------------------------------------------------

    /**
     * Whether an order is one the merchant asked to have invoiced.
     */
    public function shouldSync(Order $order): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->syncEnabled) {
            return false;
        }

        if ($settings->requireCompletedOrder && !$order->isCompleted) {
            return false;
        }

        $handles = array_filter(array_map('trim', $settings->invoiceStatusHandles));

        if ($handles === []) {
            // No filter configured: every completed order.
            return (bool)$order->isCompleted;
        }

        $handle = $order->getOrderStatus()?->handle;

        return $handle !== null && in_array($handle, $handles, true);
    }

    /**
     * Queue an order for pushing.
     *
     * Deliberately never synchronous. MYOB is a third party with a rate limit and a maintenance
     * window; a customer completing checkout must not be waiting on either.
     */
    public function queue(Order $order, bool $force = false): void
    {
        if (!$order->id) {
            return;
        }

        Craft::$app->getQueue()->push(new \justinholtweb\my\queue\jobs\PushOrder([
            'orderId' => $order->id,
            'force' => $force,
            'description' => Craft::t('my', 'Pushing order {reference} to MYOB', [
                'reference' => $order->reference ?: $order->getShortNumber(),
            ]),
        ]));
    }

    // Pushing
    // -------------------------------------------------------------------------

    /**
     * Push everything an order owes MYOB: the invoice, then its payments, then its refunds.
     *
     * Strictly in that order, and each step stops the ones after it — a payment cannot be applied
     * to an invoice that does not exist, and a credit note against a missing invoice is just a
     * mysterious negative balance.
     *
     * Serialised per order. Completing an order typically queues more than one job — the
     * completion itself, the purchase transaction, often a status change — and the ledger's unique
     * index only stops two *rows*: two workers that both find the same `pending` row would both
     * POST. The lock makes "read the ledger, then call MYOB" one step per order. A caller that
     * cannot get it is told so and does nothing; the queue's retry picks the order up again.
     *
     * @return array{ok: bool, invoice: SyncDocument|null, payments: SyncDocument[], refunds: SyncDocument[], messages: string[]}
     */
    public function pushOrder(Order $order, bool $force = false): array
    {
        try {
            return $this->pushOrderSerialised($order, $force);
        } finally {
            // Every push is a chance to notice trouble without cron. Fail-open: see afterSync().
            Plugin::getInstance()->getAlerts()->afterSync();
        }
    }

    /**
     * {@see pushOrder()} under the order's lock; `pushOrder()` wraps it so every exit evaluates
     * the alerts.
     *
     * @return array{ok: bool, invoice: SyncDocument|null, payments: SyncDocument[], refunds: SyncDocument[], messages: string[]}
     */
    private function pushOrderSerialised(Order $order, bool $force): array
    {
        $mutex = Craft::$app->getMutex();
        $lock = self::PUSH_LOCK_PREFIX . $order->id;

        if (!$mutex->acquire($lock, self::PUSH_LOCK_TIMEOUT)) {
            return [
                'ok' => false,
                'invoice' => null,
                'payments' => [],
                'refunds' => [],
                'messages' => [
                    Craft::t('my', 'Another push for this order is already running. Try again in a moment.'),
                ],
            ];
        }

        try {
            return $this->pushOrderLocked($order, $force);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * The body of `pushOrder()`, run with the order's lock held.
     *
     * @return array{ok: bool, invoice: SyncDocument|null, payments: SyncDocument[], refunds: SyncDocument[], messages: string[]}
     */
    private function pushOrderLocked(Order $order, bool $force): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $messages = [];

        $result = [
            'ok' => false,
            'invoice' => null,
            'payments' => [],
            'refunds' => [],
            'messages' => &$messages,
        ];

        try {
            $customerRef = Plugin::getInstance()->getContacts()->resolveForOrder($order);
        } catch (MyobApiException $e) {
            $messages[] = $e->getMessage();

            // A MYOB outage while looking up the customer is every bit as transient as one while
            // posting the invoice, so the row stays `pending` and the queue's own retry picks it
            // up. Marking it `failed` here would quietly need a human for something that fixes
            // itself in ten minutes.
            $this->fail($order, SyncDocument::TYPE_INVOICE, SyncDocument::SOURCE_ORDER, $e->getMessage(), $e->isRetryable());

            return $result;
        }

        $invoice = $this->pushInvoice($order, $customerRef, $force, $messages);
        $result['invoice'] = $invoice;

        if ($invoice === null || !$invoice->isSynced()) {
            return $result;
        }

        if ($settings->syncPayments) {
            $result['payments'] = $this->pushPayments($order, $customerRef, $invoice, $messages);
        }

        if ($settings->syncRefunds) {
            $result['refunds'] = $this->pushRefunds($order, $customerRef, $messages);
        }

        $result['ok'] = true;

        return $result;
    }

    /**
     * @param string[] $messages
     */
    public function pushInvoice(Order $order, array $customerRef, bool $force, array &$messages): ?SyncDocument
    {
        $existing = $this->getDocument($order->id, SyncDocument::TYPE_INVOICE, SyncDocument::SOURCE_ORDER);

        if ($existing !== null && $existing->isSynced() && !$force) {
            $messages[] = Craft::t('my', 'Already invoiced as {number}.', [
                'number' => $existing->myobNumber ?: $existing->myobUid,
            ]);

            return $existing;
        }

        $invoices = Plugin::getInstance()->getInvoices();
        $settings = Plugin::getInstance()->getSettings();

        try {
            $payload = $invoices->buildPayload($order, $customerRef);
            $check = $invoices->reconcile($order, $payload);

            if (!$check['ok']) {
                $payload = $this->handleMismatch($order, $payload, $check, $messages);
            }
        } catch (MyobApiException $e) {
            $messages[] = $e->getMessage();

            return $this->fail($order, SyncDocument::TYPE_INVOICE, SyncDocument::SOURCE_ORDER, $e->getMessage());
        }

        // Claim before the call, so a lost response leaves evidence.
        $document = $this->claim($order, SyncDocument::TYPE_INVOICE, SyncDocument::SOURCE_ORDER, [
            'amount' => (float)$order->getTotalPrice(),
            'currency' => $order->currency,
            'payload' => Json::encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);

        // A previous attempt may have succeeded and lost its answer on the way home.
        try {
            $recovered = $this->recoverInvoice($order, $document, $payload);
        } catch (MyobApiException $e) {
            // MYOB could not say whether the invoice is there. Leave the claim retryable and send
            // nothing until it can.
            $messages[] = $e->getMessage();

            return $this->fail($order, SyncDocument::TYPE_INVOICE, SyncDocument::SOURCE_ORDER, $e->getMessage(), true);
        }

        if ($recovered !== null) {
            $messages[] = Craft::t('my', 'Found the invoice already in MYOB as {number}; linked it rather than creating a second one.', [
                'number' => $recovered->myobNumber ?: $recovered->myobUid,
            ]);

            return $recovered;
        }

        try {
            $response = Plugin::getInstance()->getApi()->post($invoices->endpoint(), $payload, [
                'action' => 'invoice.create',
                'orderId' => $order->id,
            ]);
        } catch (MyobApiException $e) {
            $messages[] = $e->getMessage();

            return $this->fail($order, SyncDocument::TYPE_INVOICE, SyncDocument::SOURCE_ORDER, $e->getMessage(), $e->isRetryable());
        }

        $verified = $invoices->verifyAgainstOrder($order, $response);

        if (!$verified['ok']) {
            $messages[] = (string)$verified['message'];

            Plugin::getInstance()->getLog()->write('invoice.mismatch', [
                'level' => LogEntry::LEVEL_WARNING,
                'orderId' => $order->id,
                'summary' => Craft::t('my', 'MYOB booked a different total'),
                'message' => (string)$verified['message'],
            ]);
        }

        return $this->succeed($document, $response, $verified['ok'] ? null : (string)$verified['message']);
    }

    /**
     * @param string[] $messages
     * @return SyncDocument[]
     */
    public function pushPayments(Order $order, array $customerRef, SyncDocument $invoice, array &$messages): array
    {
        $out = [];
        $payments = Plugin::getInstance()->getPayments();

        foreach ($payments->getPayableTransactions($order) as $transaction) {
            $key = $this->transactionKey($transaction);
            $existing = $this->getDocument($order->id, SyncDocument::TYPE_PAYMENT, $key);

            if ($existing !== null && $existing->isSynced()) {
                $out[] = $existing;
                continue;
            }

            $document = $this->claim($order, SyncDocument::TYPE_PAYMENT, $key, [
                'amount' => (float)$transaction->amount,
                'currency' => $transaction->currency ?: $order->currency,
            ]);

            try {
                $response = $payments->push($order, $transaction, $customerRef, $invoice);
            } catch (MyobApiException $e) {
                $messages[] = $e->getMessage();
                $out[] = $this->fail($order, SyncDocument::TYPE_PAYMENT, $key, $e->getMessage(), $e->isRetryable());
                continue;
            }

            $out[] = $this->succeed($document, $response);
        }

        return $out;
    }

    /**
     * @param string[] $messages
     * @return SyncDocument[]
     */
    public function pushRefunds(Order $order, array $customerRef, array &$messages): array
    {
        $out = [];
        $refunds = Plugin::getInstance()->getRefunds();
        $payments = Plugin::getInstance()->getPayments();

        foreach ($payments->getRefundTransactions($order) as $transaction) {
            $key = $this->transactionKey($transaction);
            $creditNote = $this->getDocument($order->id, SyncDocument::TYPE_CREDIT_NOTE, $key);

            if ($creditNote === null || !$creditNote->isSynced()) {
                $document = $this->claim($order, SyncDocument::TYPE_CREDIT_NOTE, $key, [
                    'amount' => -(float)$transaction->amount,
                    'currency' => $transaction->currency ?: $order->currency,
                ]);

                try {
                    $response = $refunds->pushCreditNote($order, $transaction, $customerRef);
                } catch (MyobApiException $e) {
                    $messages[] = $e->getMessage();
                    $out[] = $this->fail($order, SyncDocument::TYPE_CREDIT_NOTE, $key, $e->getMessage(), $e->isRetryable());
                    continue;
                }

                $creditNote = $this->succeed($document, $response);
            }

            $out[] = $creditNote;

            if (!$refunds->shouldRefundToBank() || !$creditNote->isSynced()) {
                continue;
            }

            $existingRefund = $this->getDocument($order->id, SyncDocument::TYPE_REFUND, $key);

            if ($existingRefund !== null && $existingRefund->isSynced()) {
                $out[] = $existingRefund;
                continue;
            }

            $document = $this->claim($order, SyncDocument::TYPE_REFUND, $key, [
                'amount' => -(float)$transaction->amount,
                'currency' => $transaction->currency ?: $order->currency,
            ]);

            try {
                $response = $refunds->pushCreditRefund($order, $transaction, $customerRef, $creditNote);
            } catch (MyobApiException $e) {
                $messages[] = $e->getMessage();
                $out[] = $this->fail($order, SyncDocument::TYPE_REFUND, $key, $e->getMessage(), $e->isRetryable());
                continue;
            }

            $out[] = $this->succeed($document, $response);
        }

        return $out;
    }

    /**
     * Ask MYOB whether an invoice we may already have sent is there.
     *
     * Only worth doing when a previous attempt got far enough to claim a row — otherwise it is a
     * wasted request on every single push. Three ways to find it, most certain first:
     *
     * 1. The row already holds a UID — a forced "Push again" of an invoiced order. Read it back;
     *    a 404 means the merchant deleted it in MYOB and a new one is wanted.
     * 2. Our own `Number`, when we send one.
     * 3. `CustomerPurchaseOrderNumber`, which carries the order reference on every invoice — the
     *    only handle there is when MYOB assigns the number itself.
     *
     * @throws MyobApiException when MYOB gives no answer (timeout, 429, 5xx), so the caller sends nothing
     */
    private function recoverInvoice(Order $order, SyncDocument $document, array $payload): ?SyncDocument
    {
        if ($document->attempts <= 1) {
            return null;
        }

        $api = Plugin::getInstance()->getApi();
        $endpoint = Plugin::getInstance()->getInvoices()->endpoint();
        $existing = null;
        $label = null;

        if ($document->myobUid) {
            try {
                $read = $api->get($endpoint . '/' . rawurlencode($document->myobUid), [
                    'action' => 'invoice.recover',
                    'orderId' => $order->id,
                ]);
                $existing = is_array($read) && !empty($read['UID']) ? $read : null;
            } catch (MyobApiException $e) {
                // A 404 is an answer: it was deleted in MYOB. No answer at all is not, and posting
                // on the strength of it is exactly the duplicate this method exists to prevent.
                if ($e->isRetryable()) {
                    throw $e;
                }
                $existing = null;
            }
        }

        if ($existing === null) {
            $number = $payload['Number'] ?? null;
            $poNumber = $payload['CustomerPurchaseOrderNumber'] ?? null;

            // Single quotes inside an OData string literal are escaped by doubling them.
            $filter = match (true) {
                is_string($number) && $number !== '' => "Number eq '" . str_replace("'", "''", $number) . "'",
                is_string($poNumber) && $poNumber !== '' => "CustomerPurchaseOrderNumber eq '" . str_replace("'", "''", $poNumber) . "'",
                default => null,
            };

            if ($filter === null) {
                return null;
            }

            try {
                $existing = $api->findOne($endpoint, $filter, ['action' => 'invoice.recover', 'orderId' => $order->id]);
            } catch (MyobApiException $e) {
                if ($e->isRetryable()) {
                    throw $e;
                }

                return null;
            }
        }

        if ($existing === null || empty($existing['UID'])) {
            return null;
        }

        $label = (string)($existing['Number'] ?? $existing['UID']);

        Plugin::getInstance()->getLog()->write('invoice.recover', [
            'level' => LogEntry::LEVEL_WARNING,
            'orderId' => $order->id,
            'summary' => Craft::t('my', 'Recovered invoice {number}', ['number' => $label]),
            'message' => Craft::t('my', 'The invoice was already in MYOB. Linking it rather than creating a duplicate.'),
        ]);

        return $this->succeed($document, $existing);
    }

    /**
     * @param string[] $messages
     * @throws MyobApiException
     */
    private function handleMismatch(Order $order, array $payload, array $check, array &$messages): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $description = Craft::t('my', 'The invoice would book {computed} but the order is {expected}.', [
            'computed' => number_format($check['computed'], 2),
            'expected' => number_format($check['expected'], 2),
        ]);

        if ($settings->onTotalMismatch === Settings::MISMATCH_FAIL) {
            throw new MyobApiException($description . ' ' . Craft::t('my', 'Nothing was sent. Check the tax code mapping, or set “when totals disagree” to add a rounding line.'));
        }

        $messages[] = $description;

        Plugin::getInstance()->getLog()->write('invoice.reconcile', [
            'level' => LogEntry::LEVEL_WARNING,
            'orderId' => $order->id,
            'summary' => Craft::t('my', 'Totals disagreed by {cents}c', ['cents' => $check['diffMinor']]),
            'message' => $description,
        ]);

        if ($settings->onTotalMismatch === Settings::MISMATCH_ROUND) {
            return Plugin::getInstance()->getInvoices()->applyRounding($payload, $check['diffMinor']);
        }

        return $payload;
    }

    // The ledger
    // -------------------------------------------------------------------------

    /**
     * Claim a row for a document about to be sent.
     *
     * The unique index on `(orderId, docType, sourceKey)` is what makes this safe: two workers
     * racing on the same order produce one row, and the loser reads back the winner's.
     */
    public function claim(Order $order, string $docType, string $sourceKey, array $attributes = []): SyncDocument
    {
        $existing = $this->getDocument($order->id, $docType, $sourceKey);

        if ($existing !== null) {
            return $this->record($existing, [
                'status' => SyncDocument::STATUS_PENDING,
                'attempts' => $existing->attempts + 1,
                'lastError' => null,
            ] + $attributes);
        }

        $document = new SyncDocument([
            'orderId' => $order->id,
            'docType' => $docType,
            'sourceKey' => $sourceKey,
            'status' => SyncDocument::STATUS_PENDING,
            'attempts' => 1,
        ] + $attributes);

        try {
            return $this->record($document);
        } catch (IntegrityException) {
            // Another process claimed it between the read and the insert. Theirs wins.
            $winner = $this->getDocument($order->id, $docType, $sourceKey);

            if ($winner === null) {
                throw new IntegrityException('Could not claim a MYOB sync row for order ' . $order->id);
            }

            return $winner;
        }
    }

    /**
     * The single writer. Everything that touches the ledger comes through here.
     */
    public function record(SyncDocument $document, array $attributes = []): SyncDocument
    {
        foreach ($attributes as $name => $value) {
            if ($document->canSetProperty($name)) {
                $document->$name = $value;
            }
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());

        $columns = [
            'orderId' => $document->orderId,
            'docType' => $document->docType,
            'sourceKey' => $document->sourceKey,
            'status' => $document->status,
            'myobUid' => $document->myobUid,
            'myobNumber' => $document->myobNumber,
            'myobUri' => $document->myobUri,
            'rowVersion' => $document->rowVersion,
            'amount' => $document->amount,
            'currency' => $document->currency,
            'attempts' => $document->attempts,
            'lastError' => $document->lastError,
            'payload' => $document->payload,
            'response' => $document->response,
            'dateSynced' => Db::prepareDateForDb($document->dateSynced),
            'dateUpdated' => $now,
        ];

        if ($document->id) {
            $db->createCommand()->update(Table::DOCUMENTS, $columns, ['id' => $document->id])->execute();
        } else {
            $db->createCommand()->insert(Table::DOCUMENTS, $columns + [
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $document->id = (int)$db->getLastInsertID();
        }

        return $document;
    }

    /**
     * Mark a claimed row as done, taking the UID and number out of MYOB's reply.
     */
    private function succeed(SyncDocument $document, mixed $response, ?string $warning = null): SyncDocument
    {
        $data = is_array($response) ? $response : [];

        return $this->record($document, [
            'status' => SyncDocument::STATUS_SYNCED,
            'myobUid' => isset($data['UID']) ? (string)$data['UID'] : $document->myobUid,
            'myobNumber' => isset($data['Number']) ? (string)$data['Number'] : $document->myobNumber,
            'myobUri' => isset($data['URI']) ? (string)$data['URI'] : $document->myobUri,
            'rowVersion' => isset($data['RowVersion']) ? (string)$data['RowVersion'] : $document->rowVersion,
            'lastError' => $warning,
            'dateSynced' => new DateTime(),
            'response' => $response !== null ? Json::encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /**
     * Mark a document failed. `$retryable` keeps the row `pending` so the queue's own retry can
     * pick it up without a human deciding anything.
     */
    private function fail(Order|SyncDocument $subject, string $docType = '', string $sourceKey = '', string $error = '', bool $retryable = false): SyncDocument
    {
        $document = $subject instanceof SyncDocument
            ? $subject
            : ($this->getDocument($subject->id, $docType, $sourceKey) ?? new SyncDocument([
                'orderId' => $subject->id,
                'docType' => $docType,
                'sourceKey' => $sourceKey,
                'attempts' => 1,
            ]));

        return $this->record($document, [
            'status' => $retryable ? SyncDocument::STATUS_PENDING : SyncDocument::STATUS_FAILED,
            'lastError' => mb_substr($error, 0, 2000),
        ]);
    }

    // Reading
    // -------------------------------------------------------------------------

    public function getDocument(int $orderId, string $docType, string $sourceKey): ?SyncDocument
    {
        $row = (new Query())
            ->from([Table::DOCUMENTS])
            ->where(['orderId' => $orderId, 'docType' => $docType, 'sourceKey' => $sourceKey])
            ->one();

        return $row ? new SyncDocument($row) : null;
    }

    public function getDocumentById(int $id): ?SyncDocument
    {
        $row = (new Query())->from([Table::DOCUMENTS])->where(['id' => $id])->one();

        return $row ? new SyncDocument($row) : null;
    }

    /**
     * @return SyncDocument[]
     */
    public function getDocumentsForOrder(int $orderId): array
    {
        return array_map(
            static fn(array $row) => new SyncDocument($row),
            (new Query())
                ->from([Table::DOCUMENTS])
                ->where(['orderId' => $orderId])
                ->orderBy(['docType' => SORT_ASC, 'id' => SORT_ASC])
                ->all()
        );
    }

    public function getInvoiceForOrder(int $orderId): ?SyncDocument
    {
        return $this->getDocument($orderId, SyncDocument::TYPE_INVOICE, SyncDocument::SOURCE_ORDER);
    }

    /**
     * @return SyncDocument[]
     */
    public function getDocuments(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        return array_map(
            static fn(array $row) => new SyncDocument($row),
            $this->buildQuery($criteria)
                ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
                ->limit($limit)
                ->offset($offset)
                ->all()
        );
    }

    public function getTotal(array $criteria = []): int
    {
        return (int)$this->buildQuery($criteria)->count('[[id]]');
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        $rows = (new Query())
            ->select(['status', 'total' => 'COUNT(*)'])
            ->from([Table::DOCUMENTS])
            ->groupBy(['status'])
            ->all();

        $counts = [
            SyncDocument::STATUS_SYNCED => 0,
            SyncDocument::STATUS_PENDING => 0,
            SyncDocument::STATUS_FAILED => 0,
            SyncDocument::STATUS_SKIPPED => 0,
        ];

        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * Forget a document's MYOB link without touching MYOB.
     *
     * Deliberately does not delete anything in the company file: My has no business deleting a
     * merchant's accounting records, and an invoice that has been reconciled against a bank feed
     * cannot be deleted anyway.
     */
    public function unlink(SyncDocument $document): bool
    {
        return (bool)Craft::$app->getDb()->createCommand()
            ->delete(Table::DOCUMENTS, ['id' => $document->id])
            ->execute();
    }

    /**
     * Orders that ought to have been invoiced and have not been.
     *
     * @return int[]
     */
    public function findUnsyncedOrderIds(int $limit = 100, ?DateTime $since = null): array
    {
        $query = (new Query())
            ->select(['orders.id'])
            ->from(['orders' => '{{%commerce_orders}}'])
            ->innerJoin(['elements' => '{{%elements}}'], '[[elements.id]] = [[orders.id]]')
            ->leftJoin(
                ['docs' => Table::DOCUMENTS],
                "[[docs.orderId]] = [[orders.id]] AND [[docs.docType]] = 'invoice' AND [[docs.status]] = 'synced'"
            )
            ->where(['orders.isCompleted' => true])
            ->andWhere(['elements.dateDeleted' => null])
            ->andWhere(['docs.id' => null])
            ->orderBy(['orders.dateOrdered' => SORT_ASC])
            ->limit($limit);

        if ($since !== null) {
            $query->andWhere(['>=', 'orders.dateOrdered', Db::prepareDateForDb($since)]);
        }

        return array_map('intval', $query->column());
    }

    /**
     * The key a payment or refund is remembered under.
     *
     * The transaction hash is Commerce's own idempotency token and is what a gateway callback
     * carries, so it is the right identity. It is nullable on the model, though, so the id is the
     * fallback — never the amount, which repeats.
     */
    public function transactionKey(Transaction $transaction): string
    {
        $hash = trim((string)$transaction->hash);

        return $hash !== '' ? mb_substr($hash, 0, 64) : 'txn:' . $transaction->id;
    }

    /**
     * Synced documents MYOB booked at a different total from the order.
     *
     * `succeed()` keeps `verifyAgainstOrder()`'s complaint in `lastError` on an otherwise synced
     * row, and a clean push clears it — so "synced with an error" is exactly "in the books, at the
     * wrong amount". The alerts, the Orders index status and the summary all ask through this, so
     * they cannot disagree about what a mismatch is.
     *
     * @param string $alias the documents table's alias in the query, if it has one
     * @return array<int|string, mixed>
     */
    public static function mismatchCondition(string $alias = ''): array
    {
        $p = $alias !== '' ? $alias . '.' : '';

        return [
            'and',
            [$p . 'status' => SyncDocument::STATUS_SYNCED],
            ['not', [$p . 'lastError' => null]],
            ['not', [$p . 'lastError' => '']],
        ];
    }

    private function buildQuery(array $criteria): Query
    {
        $query = (new Query())->from([Table::DOCUMENTS]);

        foreach (['docType', 'status', 'orderId'] as $key) {
            if (empty($criteria[$key])) {
                continue;
            }

            // Not a stored status: the documents screen's "Booked at a different total" filter,
            // which alerts and the summary link to.
            if ($key === 'status' && $criteria[$key] === SyncDocument::STATUS_MISMATCH) {
                $query->andWhere(self::mismatchCondition());
                continue;
            }

            $query->andWhere([$key => $criteria[$key]]);
        }

        return $query;
    }
}
