<?php

namespace justinholtweb\my\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * My settings.
 *
 * Nothing here is ever marked `required`. Craft validates plugin settings wholesale, so a single
 * `required` credential means a fresh install cannot save *any* setting until MYOB is connected —
 * including the settings you need in order to connect it.
 */
class Settings extends Model
{
    public const MODE_CLOUD = 'cloud';
    public const MODE_LOCAL = 'local';

    public const LAYOUT_SERVICE = 'service';
    public const LAYOUT_ITEM = 'item';

    public const ITEM_FALLBACK_SERVICE = 'service';
    public const ITEM_FALLBACK_FAIL = 'fail';

    public const MISMATCH_FAIL = 'fail';
    public const MISMATCH_ROUND = 'round';
    public const MISMATCH_IGNORE = 'ignore';

    public const CUSTOMER_PER_ORDER = 'perCustomer';
    public const CUSTOMER_SINGLE_CARD = 'singleCard';

    public const DEPOSIT_UNDEPOSITED = 'UndepositedFunds';
    public const DEPOSIT_ACCOUNT = 'Account';

    public const REFUND_CREDIT_NOTE = 'creditNote';
    public const REFUND_CREDIT_NOTE_AND_REFUND = 'creditNoteAndRefund';

    /**
     * MYOB's own list of payment methods on a customer payment. Anything else is rejected by the
     * company file, so the mapping UI only ever offers these.
     */
    public const PAYMENT_METHODS = [
        'Cash',
        'Cheque',
        'EFTPOS',
        'Money Order',
        'Visa',
        'MasterCard',
        'American Express',
        'Diners Club',
        'Bank Card',
        'Barter Card',
        'Other',
    ];

    // Connection
    // -------------------------------------------------------------------------

    /**
     * `cloud` talks to api.myob.com over OAuth 2; `local` talks to an AccountRight desktop server
     * over HTTP Basic.
     */
    public string $mode = self::MODE_CLOUD;

    /**
     * The developer key and secret from developer.myob.com. Env-parseable.
     */
    public string $clientId = '';
    public string $clientSecret = '';

    /**
     * Base URL of the AccountRight local server, used only in `local` mode.
     */
    public string $localBaseUrl = 'http://localhost:8080/accountright/';

    /**
     * The *company file* user's credentials — not the MYOB account login. Sent as
     * `x-myobapi-cftoken` in cloud mode and as HTTP Basic in local mode. Env-parseable.
     *
     * A company file whose only user is "Administrator" with no password wants
     * `Administrator` here and nothing in the password.
     */
    public string $cfUsername = 'Administrator';
    public string $cfPassword = '';

    /**
     * Optional override for the company file id. Normally chosen on the settings screen and stored
     * on the connection row, but API keys issued after 12 March 2025 get no company file list from
     * MYOB, so it has to be possible to type one in — and to set it per environment.
     */
    public string $companyFileId = '';

    // Sync
    // -------------------------------------------------------------------------

    /**
     * Master switch. Off means nothing is ever pushed automatically; manual pushes still work.
     */
    public bool $syncEnabled = true;

    /**
     * Commerce order status handles that trigger an invoice. Empty means "any completed order".
     *
     * @var string[]
     */
    public array $invoiceStatusHandles = [];

    /**
     * Never invoice a live cart, whatever the status filter says.
     */
    public bool $requireCompletedOrder = true;

    /**
     * `service` posts each line to an account and needs nothing to exist in MYOB beforehand.
     * `item` references an inventory item UID and gives correct stock and cost of goods, but every
     * SKU sold has to already exist in the company file.
     */
    public string $invoiceLayout = self::LAYOUT_SERVICE;

    /**
     * What to do with a line whose SKU is not in MYOB, in `item` layout: post it to the sales
     * account instead, or refuse the whole push.
     */
    public string $itemFallback = self::ITEM_FALLBACK_SERVICE;

    /**
     * Where the MYOB invoice number comes from. `myob` leaves `Number` off the payload entirely
     * and lets the company file auto-increment.
     */
    public string $numberSource = 'reference';

    /**
     * Prefix stuck on the front of the generated number. MYOB caps `Number` at 13 characters, so
     * this is trimmed rather than allowed to silently truncate the order reference.
     */
    public string $numberPrefix = '';

    /**
     * `ordered` uses the order's completion date; `today` uses the push date.
     */
    public string $invoiceDateSource = 'ordered';

    /**
     * Account codes (MYOB `DisplayID`, e.g. `4-1000`) that service lines post to.
     */
    public string $salesAccount = '';
    public string $freightAccount = '';
    public string $discountAccount = '';
    public string $roundingAccount = '';

    /**
     * Descriptions used for the synthetic lines.
     */
    public string $freightDescription = 'Shipping';
    public string $discountDescription = 'Discount';
    public string $roundingDescription = 'Rounding';

    /**
     * Whether line prices already include tax. Defaults to following the Commerce store's own
     * "prices include tax" setting, which is what `null` means.
     */
    public ?bool $taxInclusive = null;

    /**
     * MYOB tax codes (`Code`, e.g. `GST`, `FRE`, `N-T`).
     */
    public string $defaultTaxCode = 'GST';
    public string $freightTaxCode = 'GST';
    public string $zeroTaxCode = 'FRE';

    /**
     * Commerce tax category handle => MYOB tax code.
     *
     * @var array<string, string>
     */
    public array $taxCodeMap = [];

    /**
     * Object templates rendered against the order.
     */
    public string $journalMemoTemplate = 'Craft order {{ object.reference }}';
    public string $commentTemplate = '';

    /**
     * Optional MYOB `Category` and `Salesperson` UIDs stamped on every invoice.
     */
    public string $categoryUid = '';
    public string $salespersonUid = '';

    /**
     * MYOB's `InvoiceDeliveryStatus`. `AlreadyPrintedOrSent` keeps the merchant's MYOB to-do list
     * clean, which is usually what you want when Craft already emailed the customer.
     */
    public string $invoiceDeliveryStatus = 'AlreadyPrintedOrSent';

    /**
     * How far the locally computed invoice total may drift from the order total, in minor units,
     * before `onTotalMismatch` applies. One cent.
     */
    public int $tolerance = 1;

    /**
     * What to do when the invoice would not book the amount the customer actually paid.
     * `fail` is the default on purpose: a wrong number in the ledger is worse than a failed push.
     */
    public string $onTotalMismatch = self::MISMATCH_FAIL;

    // Customers
    // -------------------------------------------------------------------------

    public bool $syncCustomers = true;

    /**
     * `perCustomer` finds or creates a card per email address; `singleCard` posts everything
     * against one card, which suits high-volume retail.
     */
    public string $customerMode = self::CUSTOMER_PER_ORDER;

    /**
     * The card used in `singleCard` mode, and for guest orders with no usable email.
     */
    public string $defaultCustomerUid = '';
    public string $defaultCustomerName = '';

    /**
     * Object template for a new card's `DisplayID`. Blank lets MYOB assign one.
     */
    public string $customerDisplayIdTemplate = '';

    /**
     * Overwrite an existing card's address and phone from the order. Off by default — the
     * merchant's own bookkeeping usually wins over whatever a customer typed at checkout.
     */
    public bool $updateExistingContacts = false;

    /**
     * Handle of the custom field on Craft addresses that holds a phone number.
     *
     * Craft 5 moved addresses out of Commerce and dropped the phone attribute entirely, so there
     * is no way to guess this — a site that wants phone numbers on its MYOB cards has to say where
     * they live.
     */
    public string $phoneFieldHandle = '';

    // Payments
    // -------------------------------------------------------------------------

    public bool $syncPayments = true;

    /**
     * `UndepositedFunds` or `Account`.
     */
    public string $depositTo = self::DEPOSIT_UNDEPOSITED;

    /**
     * Account code money is banked to when `depositTo` is `Account`.
     */
    public string $paymentAccount = '';

    /**
     * Commerce gateway handle => MYOB payment method.
     *
     * @var array<string, string>
     */
    public array $paymentMethodMap = [];

    public string $defaultPaymentMethod = 'Other';

    // Refunds
    // -------------------------------------------------------------------------

    public bool $syncRefunds = true;

    /**
     * `creditNote` raises the credit and leaves it sitting on the customer's account.
     * `creditNoteAndRefund` also records the money going back out of `refundAccount`.
     */
    public string $refundMode = self::REFUND_CREDIT_NOTE;

    public string $refundAccount = '';

    // Transport
    // -------------------------------------------------------------------------

    /**
     * MYOB's default quota is 8 requests a second per API key. Staying under it is cheaper than
     * handling the 429s it produces.
     */
    public int $requestsPerSecond = 6;

    /**
     * Seconds to wait on a MYOB request.
     */
    public int $timeout = 30;

    /**
     * Queue job attempts before a push is left `failed` for a human.
     */
    public int $maxAttempts = 5;

    // Logging
    // -------------------------------------------------------------------------

    public bool $loggingEnabled = true;
    public bool $logPayloads = true;

    /**
     * Days of log history to keep. 0 keeps everything.
     */
    public int $logRetentionDays = 30;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['requestsPerSecond'], 'integer', 'min' => 1, 'max' => 8],
            [['timeout'], 'integer', 'min' => 1, 'max' => 300],
            [['maxAttempts'], 'integer', 'min' => 1, 'max' => 20],
            [['tolerance'], 'integer', 'min' => 0, 'max' => 10000],
            [['logRetentionDays'], 'integer', 'min' => 0],
            [['mode'], 'in', 'range' => [self::MODE_CLOUD, self::MODE_LOCAL]],
            [['invoiceLayout'], 'in', 'range' => [self::LAYOUT_SERVICE, self::LAYOUT_ITEM]],
            [
                ['itemFallback'],
                'in',
                'range' => [self::ITEM_FALLBACK_SERVICE, self::ITEM_FALLBACK_FAIL],
            ],
            [
                ['onTotalMismatch'],
                'in',
                'range' => [self::MISMATCH_FAIL, self::MISMATCH_ROUND, self::MISMATCH_IGNORE],
            ],
            [['customerMode'], 'in', 'range' => [self::CUSTOMER_PER_ORDER, self::CUSTOMER_SINGLE_CARD]],
            [['depositTo'], 'in', 'range' => [self::DEPOSIT_UNDEPOSITED, self::DEPOSIT_ACCOUNT]],
            [
                ['refundMode'],
                'in',
                'range' => [self::REFUND_CREDIT_NOTE, self::REFUND_CREDIT_NOTE_AND_REFUND],
            ],
            [['numberSource'], 'in', 'range' => ['reference', 'number', 'shortNumber', 'id', 'myob']],
            [['invoiceDateSource'], 'in', 'range' => ['ordered', 'today']],
            [['defaultPaymentMethod'], 'in', 'range' => self::PAYMENT_METHODS],
            // `skipOnEmpty => false` on both: Yii skips an inline validator when the attribute is
            // empty, and empty is exactly the case these two exist to reject.
            [['localBaseUrl'], 'validateLocalBaseUrl', 'skipOnEmpty' => false],
            [['numberPrefix'], 'string', 'max' => 8],
            // Not `required`, but a service invoice with no account to post to cannot be built —
            // so say so on the settings screen rather than at 2am in a queue job.
            [['salesAccount'], 'validateSalesAccount', 'skipOnEmpty' => false],
            [
                [
                    'clientId', 'clientSecret', 'cfUsername', 'cfPassword', 'companyFileId',
                    'salesAccount', 'freightAccount', 'discountAccount', 'roundingAccount',
                    'paymentAccount', 'refundAccount', 'defaultTaxCode', 'freightTaxCode',
                    'zeroTaxCode', 'categoryUid', 'salespersonUid', 'defaultCustomerUid',
                    'defaultCustomerName', 'customerDisplayIdTemplate', 'journalMemoTemplate',
                    'commentTemplate', 'freightDescription', 'discountDescription',
                    'roundingDescription', 'invoiceDeliveryStatus', 'phoneFieldHandle',
                ],
                'string',
            ],
            [['invoiceStatusHandles', 'taxCodeMap', 'paymentMethodMap', 'taxInclusive'], 'safe'],
        ];
    }

    public function validateLocalBaseUrl(string $attribute): void
    {
        if ($this->mode !== self::MODE_LOCAL) {
            return;
        }

        // `FILTER_VALIDATE_URL` alone accepts `file://`, `ftp://` and `gopher://`. Every request
        // carries the company file credentials, so only an HTTP server may receive them.
        $scheme = strtolower((string)parse_url(trim($this->localBaseUrl), PHP_URL_SCHEME));

        if (filter_var(trim($this->localBaseUrl), FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            $this->addError($attribute, Craft::t('my', 'Enter the URL of your AccountRight server, e.g. http://localhost:8080/accountright/'));
        }
    }

    public function validateSalesAccount(string $attribute): void
    {
        if (!$this->syncEnabled || $this->invoiceLayout !== self::LAYOUT_SERVICE) {
            return;
        }

        if (trim($this->salesAccount) === '') {
            $this->addError($attribute, Craft::t('my', 'Service invoices post every line to an account, so this one is needed before anything can be pushed.'));
        }
    }

    /**
     * @inheritdoc
     *
     * Craft's CP posts an empty string for a cleared number field, and PHP throws a `TypeError`
     * rather than coercing it into a typed `int` property — so an author who clears "Timeout"
     * fatals the save. Cast against each property's declared type on the way in.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (is_array($values)) {
            foreach ($values as $name => $value) {
                if (!is_string($value) || !property_exists($this, $name)) {
                    continue;
                }

                $values[$name] = $this->coerce($name, $value);
            }
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * The declared type of a property, or null if it is untyped or unreadable.
     */
    private function coerce(string $name, string $value): mixed
    {
        try {
            $type = (new ReflectionProperty($this, $name))->getType();
        } catch (\ReflectionException) {
            return $value;
        }

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $nullable = $type->allowsNull();

        return match ($type->getName()) {
            'int' => $value === '' ? ($nullable ? null : $this->$name) : (int)$value,
            'float' => $value === '' ? ($nullable ? null : $this->$name) : (float)$value,
            'bool' => $value === '' && $nullable ? null : (bool)$value,
            'array' => $value === '' ? [] : $value,
            default => $value,
        };
    }

    // Parsed accessors
    // -------------------------------------------------------------------------

    public function getParsedClientId(): string
    {
        return trim((string)App::parseEnv($this->clientId));
    }

    public function getParsedClientSecret(): string
    {
        return trim((string)App::parseEnv($this->clientSecret));
    }

    public function getParsedCfUsername(): string
    {
        return (string)App::parseEnv($this->cfUsername);
    }

    public function getParsedCfPassword(): string
    {
        return (string)App::parseEnv($this->cfPassword);
    }

    public function getParsedCompanyFileId(): string
    {
        return trim((string)App::parseEnv($this->companyFileId));
    }

    public function isLocal(): bool
    {
        return $this->mode === self::MODE_LOCAL;
    }

    /**
     * The base every company file URI hangs off.
     */
    public function getApiBaseUrl(): string
    {
        if ($this->isLocal()) {
            return rtrim(trim($this->localBaseUrl), '/') . '/';
        }

        return 'https://api.myob.com/accountright/';
    }

    /**
     * Base64 `username:password` for the company file user, sent as `x-myobapi-cftoken`.
     *
     * A blank username *and* password means the file has no user-level security, in which case
     * MYOB wants no token at all rather than an empty one.
     */
    public function getCfToken(): ?string
    {
        $username = $this->getParsedCfUsername();
        $password = $this->getParsedCfPassword();

        if ($username === '' && $password === '') {
            return null;
        }

        return base64_encode($username . ':' . $password);
    }

    /**
     * Whether there is enough here to attempt a connection at all.
     */
    public function isConfigured(): bool
    {
        if ($this->isLocal()) {
            return trim($this->localBaseUrl) !== '';
        }

        return $this->getParsedClientId() !== '' && $this->getParsedClientSecret() !== '';
    }

    /**
     * The redirect URI to register on developer.myob.com. MYOB matches it exactly.
     */
    public function getRedirectUri(): string
    {
        return UrlHelper::actionUrl('my/oauth/callback');
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'clientId' => Craft::t('my', 'API key'),
            'clientSecret' => Craft::t('my', 'API secret'),
            'cfUsername' => Craft::t('my', 'Company file username'),
            'cfPassword' => Craft::t('my', 'Company file password'),
            'salesAccount' => Craft::t('my', 'Sales account'),
            'localBaseUrl' => Craft::t('my', 'AccountRight server URL'),
            'tolerance' => Craft::t('my', 'Rounding tolerance'),
        ];
    }
}
