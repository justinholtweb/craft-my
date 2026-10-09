<?php

namespace justinholtweb\my;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\Order;
use craft\commerce\events\OrderStatusEvent;
use craft\commerce\events\RefundTransactionEvent;
use craft\commerce\events\TransactionEvent;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\services\OrderHistories;
use craft\commerce\services\Payments as CommercePayments;
use craft\commerce\services\Transactions;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterCpAlertsEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\my\elements\actions\PushToMyob;
use justinholtweb\my\elements\conditions\MyobStatusConditionRule;
use justinholtweb\my\models\Settings;
use justinholtweb\my\services\Alerts;
use justinholtweb\my\services\Api;
use justinholtweb\my\services\Auth;
use justinholtweb\my\services\Contacts;
use justinholtweb\my\services\Digest;
use justinholtweb\my\services\Invoices;
use justinholtweb\my\services\Log;
use justinholtweb\my\services\OrderStatus;
use justinholtweb\my\services\Payments;
use justinholtweb\my\services\Reference;
use justinholtweb\my\services\Refunds;
use justinholtweb\my\services\Sync;
use justinholtweb\my\twig\MyVariable;
use justinholtweb\my\widgets\HealthWidget;
use yii\base\Event;

/**
 * My — MYOB integration for Craft Commerce.
 *
 * @property-read Auth $auth
 * @property-read Api $api
 * @property-read Reference $reference
 * @property-read Contacts $contacts
 * @property-read Invoices $invoices
 * @property-read Payments $payments
 * @property-read Refunds $refunds
 * @property-read Sync $sync
 * @property-read Log $log
 * @property-read Alerts $alerts
 * @property-read Digest $digest
 * @property-read OrderStatus $orderStatus
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'my';

    /** The Orders index column. */
    public const TABLE_ATTRIBUTE = 'myob';

    public string $schemaVersion = '5.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'auth' => ['class' => Auth::class],
                'api' => ['class' => Api::class],
                'reference' => ['class' => Reference::class],
                'contacts' => ['class' => Contacts::class],
                'invoices' => ['class' => Invoices::class],
                'payments' => ['class' => Payments::class],
                'refunds' => ['class' => Refunds::class],
                'sync' => ['class' => Sync::class],
                'log' => ['class' => Log::class],
                'alerts' => ['class' => Alerts::class],
                'digest' => ['class' => Digest::class],
                'orderStatus' => ['class' => OrderStatus::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();
        $this->_registerGarbageCollection();
        $this->_registerWidgets();
        $this->_registerCpAlerts();

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->_registerDigestFallback();
        }

        // The plugin can be installed while Commerce is disabled or mid-upgrade, and everything
        // below reaches for an order.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderEvents();
        $this->_registerOrderEditPanel();
        $this->_registerOrderIndex();
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function getAuth(): Auth
    {
        return $this->get('auth');
    }

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getReference(): Reference
    {
        return $this->get('reference');
    }

    public function getContacts(): Contacts
    {
        return $this->get('contacts');
    }

    public function getInvoices(): Invoices
    {
        return $this->get('invoices');
    }

    public function getPayments(): Payments
    {
        return $this->get('payments');
    }

    public function getRefunds(): Refunds
    {
        return $this->get('refunds');
    }

    public function getSync(): Sync
    {
        return $this->get('sync');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    public function getAlerts(): Alerts
    {
        return $this->get('alerts');
    }

    public function getDigest(): Digest
    {
        return $this->get('digest');
    }

    public function getOrderStatus(): OrderStatus
    {
        return $this->get('orderStatus');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        $commerceReady = self::commerceIsReady();

        return Craft::$app->getView()->renderTemplate('my/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'connection' => $this->getAuth()->getConnection(),
            'commerceReady' => $commerceReady,
            'orderStatuses' => $commerceReady ? $this->_orderStatusOptions() : [],
            'taxCategories' => $commerceReady ? $this->_taxCategories() : [],
            'gateways' => $commerceReady ? $this->_gateways() : [],
            'paymentMethods' => array_map(
                static fn(string $method) => ['label' => $method, 'value' => $method],
                Settings::PAYMENT_METHODS,
            ),
            'weekdayOptions' => $this->_weekdayOptions(),
            'hourOptions' => array_map(
                static fn(int $hour) => ['label' => sprintf('%02d:00', $hour), 'value' => $hour],
                range(0, 23),
            ),
            'timeZone' => Craft::$app->getTimeZone(),
            'digestState' => $this->_safely(fn() => $this->getDigest()->state(), []),
            'digestNext' => $this->_safely(fn() => $this->getDigest()->nextDueAt(), null),
        ]);
    }

    /**
     * @return array<int, array{label: string, value: int}>
     */
    private function _weekdayOptions(): array
    {
        $out = [];

        foreach (range(1, 7) as $day) {
            // 2024-01-01 was a Monday, so day N of that week is ISO weekday N.
            $out[] = [
                'label' => Craft::$app->getFormatter()->asDate("2024-01-0$day", 'php:l'),
                'value' => $day,
            ];
        }

        return $out;
    }

    /**
     * The settings screen must render even before the 5.1.0 migration has run — it is where an
     * admin goes to find out why things are broken.
     */
    private function _safely(callable $read, mixed $fallback): mixed
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            Craft::warning('My could not read the summary schedule: ' . $e->getMessage(), __METHOD__);

            return $fallback;
        }
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function _orderStatusOptions(): array
    {
        $out = [];

        try {
            // The primary store, not the "current" one: `getCurrentStore()` resolves through the
            // current site, which is not reliably set outside a front-end request.
            $storeId = \craft\commerce\Plugin::getInstance()->getStores()->getPrimaryStore()?->id;

            foreach (\craft\commerce\Plugin::getInstance()->getOrderStatuses()->getAllOrderStatuses($storeId) as $status) {
                $out[] = ['label' => $status->name, 'value' => $status->handle];
            }
        } catch (\Throwable $e) {
            Craft::warning('My could not read Commerce order statuses: ' . $e->getMessage(), __METHOD__);
        }

        return $out;
    }

    /**
     * @return array<int, array{handle: string, name: string}>
     */
    private function _taxCategories(): array
    {
        $out = [];

        try {
            foreach (\craft\commerce\Plugin::getInstance()->getTaxCategories()->getAllTaxCategories() as $category) {
                $out[] = ['handle' => $category->handle, 'name' => $category->name];
            }
        } catch (\Throwable $e) {
            Craft::warning('My could not read Commerce tax categories: ' . $e->getMessage(), __METHOD__);
        }

        return $out;
    }

    /**
     * @return array<int, array{handle: string, name: string}>
     */
    private function _gateways(): array
    {
        $out = [];

        try {
            foreach (\craft\commerce\Plugin::getInstance()->getGateways()->getAllGateways() as $gateway) {
                $out[] = ['handle' => (string)$gateway->handle, 'name' => (string)$gateway->name];
            }
        } catch (\Throwable $e) {
            Craft::warning('My could not read Commerce gateways: ' . $e->getMessage(), __METHOD__);
        }

        return $out;
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('my', 'MYOB');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('my-viewDocuments')) {
            $subNav['documents'] = [
                'label' => Craft::t('my', 'Documents'),
                'url' => 'my/documents',
            ];
        }

        if ($user->checkPermission('my-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('my', 'Log'),
                'url' => 'my/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('my', 'Settings'),
                'url' => 'settings/plugins/my',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('myob', MyVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('my', 'MYOB'),
                    'permissions' => [
                        'my-viewDocuments' => [
                            'label' => Craft::t('my', 'View synced documents'),
                            'nested' => [
                                'my-pushOrders' => [
                                    'label' => Craft::t('my', 'Push orders to MYOB'),
                                ],
                                'my-unlinkDocuments' => [
                                    'label' => Craft::t('my', 'Unlink documents from MYOB'),
                                ],
                            ],
                        ],
                        'my-viewLog' => [
                            'label' => Craft::t('my', 'View the connection log'),
                            'nested' => [
                                'my-clearLog' => [
                                    'label' => Craft::t('my', 'Clear the connection log'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['my'] = 'my/documents/index';
                $event->rules['my/documents'] = 'my/documents/index';
                $event->rules['my/documents/<documentId:\d+>'] = 'my/documents/detail';
                $event->rules['my/log'] = 'my/log/index';
                $event->rules['my/log/<entryId:\d+>'] = 'my/log/detail';
            }
        );
    }

    /**
     * Three ways an order becomes MYOB's problem.
     */
    private function _registerOrderEvents(): void
    {
        // 1. The order completes.
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            static function(Event $event) {
                $order = $event->sender;

                if (!$order instanceof Order) {
                    return;
                }

                $plugin = Plugin::getInstance();

                if ($plugin->getSync()->shouldSync($order)) {
                    $plugin->getSync()->queue($order);
                }
            }
        );

        // 2. It reaches a status the merchant nominated. Orders are frequently invoiced only once
        //    they ship, so this is the trigger most merchants actually configure.
        Event::on(
            OrderHistories::class,
            OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            static function(OrderStatusEvent $event) {
                $plugin = Plugin::getInstance();

                if ($plugin->getSync()->shouldSync($event->order)) {
                    $plugin->getSync()->queue($event->order);
                }
            }
        );

        // 3. Money moves after the fact — a later capture, or a refund. Both need pushing even
        //    though the order itself has not changed, and both are no-ops if the order was never
        //    invoiced in the first place.
        Event::on(
            Transactions::class,
            Transactions::EVENT_AFTER_SAVE_TRANSACTION,
            static function(TransactionEvent $event) {
                $transaction = $event->transaction;

                if ($transaction->status !== TransactionRecord::STATUS_SUCCESS) {
                    return;
                }

                if ($transaction->type === TransactionRecord::TYPE_AUTHORIZE) {
                    // The money has not moved yet.
                    return;
                }

                $plugin = Plugin::getInstance();
                $order = $transaction->getOrder();

                if ($order === null || !$plugin->getSync()->shouldSync($order)) {
                    return;
                }

                $plugin->getSync()->queue($order);
            }
        );

        Event::on(
            CommercePayments::class,
            CommercePayments::EVENT_AFTER_REFUND_TRANSACTION,
            static function(RefundTransactionEvent $event) {
                $plugin = Plugin::getInstance();
                $order = $event->refundTransaction->getOrder();

                if ($order === null || !$plugin->getSettings()->syncRefunds) {
                    return;
                }

                // A refund is pushed even if the order's current status no longer matches the
                // trigger — the invoice already exists, and leaving the credit unrecorded is the
                // worse outcome.
                if ($plugin->getSync()->getInvoiceForOrder($order->id)?->isSynced()) {
                    $plugin->getSync()->queue($order);
                }
            }
        );
    }

    /**
     * The MYOB panel on Commerce's own order edit screen.
     */
    private function _registerOrderEditPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('my-viewDocuments')) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('my/_order-panel', [
                'order' => $order,
                'documents' => $this->getSync()->getDocumentsForOrder($order->id),
                'connected' => $this->getAuth()->getConnection()->isConnected(),
                'canPush' => Craft::$app->getUser()->checkPermission('my-pushOrders'),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    private function _registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = HealthWidget::class;
            }
        );
    }

    /**
     * A banner across the CP while an alert is open, for the people who can do something about it.
     *
     * Above all for a dead refresh token: MYOB stops taking invoices without a sound, and the
     * banner is what turns "the books are a week behind" into "reconnect, now". One query on a
     * three-row table per CP page. Fail-open: a CP that cannot render because an alert could not be
     * read would be a worse outage than the one being reported.
     */
    private function _registerCpAlerts(): void
    {
        Event::on(Cp::class, Cp::EVENT_REGISTER_ALERTS, function(RegisterCpAlertsEvent $event) {
            try {
                $user = Craft::$app->getUser();

                if (!$user->checkPermission('my-viewDocuments') || $this->isInstalled === false) {
                    return;
                }

                foreach ($this->getAlerts()->openIncidents() as $incident) {
                    $link = $incident['incident'] === Alerts::INCIDENT_AUTH && $user->getIsAdmin()
                        ? Html::a(Craft::t('my', 'Reconnect'), UrlHelper::cpUrl('settings/plugins/my'), ['class' => 'go'])
                        : Html::a(Craft::t('my', 'Open MYOB documents'), UrlHelper::cpUrl('my/documents'), ['class' => 'go']);

                    $event->alerts[] = Html::encode(Craft::t('my', 'MYOB: {label}.', ['label' => $incident['label']])) . ' ' . $link;
                }
            } catch (\Throwable $e) {
                Craft::warning('My could not read its alerts: ' . $e->getMessage(), __METHOD__);
            }
        });
    }

    /**
     * The sync summary's fallback trigger, for sites with no cron job running `my/digest/send`.
     *
     * At the end of a web request rather than on `Gc::EVENT_RUN`: garbage collection runs on a
     * dice roll, which makes for a schedule nobody can predict. What runs here is a cache read; the
     * schedule is consulted at most every five minutes and the work happens in a queue job.
     */
    private function _registerDigestFallback(): void
    {
        $settings = $this->getSettings();

        if (!$settings->digestEnabled || !$settings->digestWebTrigger) {
            return;
        }

        Event::on(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST, function() {
            try {
                // Before the migration that adds the marker table has run, there is nowhere to
                // read the schedule from.
                if (!Craft::$app->getIsInstalled() || Craft::$app->getPlugins()->isPluginUpdatePending($this)) {
                    return;
                }

                $this->getDigest()->queueIfDue();
            } catch (\Throwable $e) {
                Craft::warning('My could not check the summary schedule: ' . $e->getMessage(), __METHOD__);
            }
        });
    }

    /**
     * The Orders index: a MYOB column, a "MYOB status" filter, and a bulk "Push to MYOB" action.
     *
     * Every hook is attached to the Order class, not to Element: the table-attribute events do not
     * say which element type is asking.
     */
    private function _registerOrderIndex(): void
    {
        // Registered unconditionally. A rule registered only for some users or settings is
        // dropped from saved conditions, and a custom source built on it silently widens to
        // every order.
        Event::on(
            OrderCondition::class,
            OrderCondition::EVENT_REGISTER_CONDITION_RULES,
            static function(RegisterConditionRulesEvent $event) {
                $event->conditionRules[] = MyobStatusConditionRule::class;
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            static function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes[self::TABLE_ATTRIBUTE] = ['label' => Craft::t('my', 'MYOB')];
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            static function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== self::TABLE_ATTRIBUTE) {
                    return;
                }

                /** @var Order $order */
                $order = $event->sender;
                $event->html = Plugin::getInstance()->orderStatusHtml($order);
                $event->handled = true;
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_REGISTER_ACTIONS,
            static function(RegisterElementActionsEvent $event) {
                // Actions are not saved anywhere, so offering this only to people who may use it
                // is safe; the action checks the permission again when it runs.
                if (Craft::$app->getUser()->checkPermission('my-pushOrders')) {
                    $event->actions[] = PushToMyob::class;
                }
            }
        );
    }

    /**
     * The Orders index cell: a status dot, a word, and the MYOB invoice number.
     *
     * Looked up for the whole page at once: Craft 5 puts the full query result on every element it
     * returns (`elementQueryResult`), so the first cell runs one query for every row and the rest
     * read the memo.
     */
    public function orderStatusHtml(Order $order): string
    {
        if (!$order->id || !Craft::$app->getUser()->checkPermission('my-viewDocuments')) {
            return '';
        }

        $statuses = $this->getOrderStatus();
        $page = [];

        foreach ($order->elementQueryResult ?? [] as $other) {
            if ($other instanceof Order && $other->id) {
                $page[] = $other->id;
            }
        }

        $statuses->prefetch(array_merge($page, [$order->id]));

        $state = $statuses->forOrder($order->id);
        $label = OrderStatus::options()[$state['status']] ?? $state['status'];

        if ($state['status'] === OrderStatus::NONE) {
            return Html::tag('span', Html::encode($label), ['class' => 'light']);
        }

        $html = Html::tag('span', '', ['class' => ['status', OrderStatus::color($state['status'])], 'aria-hidden' => 'true'])
            . Html::encode($label);

        if ($state['number'] !== null) {
            $html .= ' ' . Html::tag('code', Html::encode($state['number']), ['class' => 'light']);
        }

        return $html;
    }

    private function _registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function() {
                $this->getLog()->prune();
            }
        );
    }
}
