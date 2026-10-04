<?php

namespace justinholtweb\my\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\elements\User;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\models\SyncDocument;
use justinholtweb\my\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The synced-documents screen, and the buttons that push orders.
 */
class DocumentsController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('my-viewDocuments');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $sync = Plugin::getInstance()->getSync();

        $criteria = array_filter([
            'docType' => $request->getQueryParam('type'),
            'status' => $request->getQueryParam('status'),
        ]);

        $page = max(1, (int)$request->getQueryParam('page', 1));
        $total = $sync->getTotal($criteria);

        return $this->renderTemplate('my/documents/_index', [
            'documents' => $sync->getDocuments($criteria, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'counts' => $sync->getCounts(),
            'criteria' => $criteria,
            'page' => $page,
            'total' => $total,
            'perPage' => self::PER_PAGE,
            'connected' => Plugin::getInstance()->getAuth()->getConnection()->isConnected(),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDetail(int $documentId): Response
    {
        $document = Plugin::getInstance()->getSync()->getDocumentById($documentId);

        if ($document === null) {
            throw new NotFoundHttpException('Document not found');
        }

        $order = Order::find()->id($document->orderId)->status(null)->one();

        // The stored payload is the customer's name, email and addresses. Seeing it is seeing the
        // order, so it takes the same permission Commerce asks for.
        if ($order instanceof Order) {
            $this->requireOrderAccess($order);
        }

        return $this->renderTemplate('my/documents/_detail', [
            'document' => $document,
            'order' => $order,
        ]);
    }

    /**
     * Push an order now, from the CP.
     *
     * Runs inline rather than queued, because somebody is standing there watching the button and
     * wants to be told what happened.
     *
     * @throws ForbiddenHttpException
     */
    public function actionPush(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('my-pushOrders');

        $request = Craft::$app->getRequest();
        $orderId = (int)$request->getBodyParam('orderId');
        $force = (bool)$request->getBodyParam('force', false);

        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            return $this->asFailure(Craft::t('my', 'No such order.'));
        }

        $this->requireOrderAccess($order);

        $result = Plugin::getInstance()->getSync()->pushOrder($order, $force);
        $messages = array_filter($result['messages']);

        if (!$result['ok']) {
            return $this->asFailure(
                $messages !== [] ? implode(' ', $messages) : Craft::t('my', 'The push failed. Check the log.')
            );
        }

        $invoice = $result['invoice'];

        return $this->asSuccess(
            Craft::t('my', 'Pushed to MYOB as {number}.', [
                'number' => $invoice?->myobNumber ?: ($invoice?->myobUid ?? '—'),
            ]),
            ['messages' => $messages],
        );
    }

    /**
     * Queue a push instead of running it now — for bulk work from the index.
     */
    public function actionQueue(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('my-pushOrders');

        $orderIds = Craft::$app->getRequest()->getBodyParam('orderIds', []);
        $orderIds = array_filter(array_map('intval', is_array($orderIds) ? $orderIds : [$orderIds]));

        $sync = Plugin::getInstance()->getSync();
        $queued = 0;

        foreach ($orderIds as $orderId) {
            $order = Order::find()->id($orderId)->status(null)->one();

            if ($order instanceof Order && $this->canAccessOrder($order, static::currentUser())) {
                $sync->queue($order, (bool)Craft::$app->getRequest()->getBodyParam('force', false));
                $queued++;
            }
        }

        return $this->asSuccess(Craft::t('my', '{count} orders queued.', ['count' => $queued]));
    }

    /**
     * What would be sent, exactly.
     *
     * Goes through the same `buildPayload()` the push does, so the preview is not a
     * reassuring approximation.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('my-pushOrders');

        $orderId = (int)Craft::$app->getRequest()->getBodyParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            return $this->asJson(['success' => false, 'message' => Craft::t('my', 'No such order.')]);
        }

        $this->requireOrderAccess($order);

        $plugin = Plugin::getInstance();

        try {
            // Read-only: a preview must not create (or update) a card in MYOB.
            $customerRef = $plugin->getContacts()->resolveForOrder($order, true);
            $payload = $plugin->getInvoices()->buildPayload($order, $customerRef);
            $check = $plugin->getInvoices()->reconcile($order, $payload);
        } catch (MyobApiException $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->asJson([
            'success' => true,
            'endpoint' => $plugin->getInvoices()->endpoint(),
            'payload' => Json::encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'reconciliation' => $check,
        ]);
    }

    /**
     * Forget a document's MYOB link. Nothing is deleted in MYOB.
     */
    public function actionUnlink(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('my-unlinkDocuments');

        $documentId = (int)Craft::$app->getRequest()->getBodyParam('documentId');
        $document = Plugin::getInstance()->getSync()->getDocumentById($documentId);

        if ($document === null) {
            return $this->asFailure(Craft::t('my', 'No such document.'));
        }

        Plugin::getInstance()->getSync()->unlink($document);

        return $this->asSuccess(Craft::t('my', 'Unlinked. The {type} is still in MYOB.', [
            'type' => mb_strtolower($document->getTypeLabel()),
        ]));
    }

    /**
     * Whether a user may see an order — Commerce's own rule, through the Elements service so that
     * any `EVENT_AUTHORIZE_VIEW` handler on the site is honoured too.
     *
     * The plugin's permissions say what a user may do *with MYOB*; they say nothing about which
     * orders the user may read, and the push, preview and detail screens all show the order's
     * customer details. Order ids arrive in POST data, so without this a user holding only
     * `my-pushOrders` could read any customer's details by guessing ids.
     */
    protected function canAccessOrder(Order $order, ?User $user): bool
    {
        return $user !== null && Craft::$app->getElements()->canView($order, $user);
    }

    /**
     * @throws ForbiddenHttpException
     */
    private function requireOrderAccess(Order $order): void
    {
        if (!$this->canAccessOrder($order, static::currentUser())) {
            throw new ForbiddenHttpException(Craft::t('app', 'User is not authorized to perform this action.'));
        }
    }
}
