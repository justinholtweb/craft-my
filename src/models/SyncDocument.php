<?php

namespace justinholtweb\my\models;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;
use DateTime;

/**
 * One row of the sync ledger: a thing in Craft that became a thing in MYOB.
 */
class SyncDocument extends Model
{
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_CREDIT_NOTE = 'creditnote';
    public const TYPE_REFUND = 'refund';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Not a stored status. A `synced` row that still carries an error is one MYOB booked at a
     * different total from the order; this names that state for filters and the Orders index.
     */
    public const STATUS_MISMATCH = 'mismatch';

    /**
     * The `sourceKey` used for the one invoice an order gets. Payments and refunds key on their
     * Commerce transaction hash instead.
     */
    public const SOURCE_ORDER = 'order';

    public ?int $id = null;
    public int $orderId = 0;
    public string $docType = self::TYPE_INVOICE;
    public string $sourceKey = self::SOURCE_ORDER;
    public string $status = self::STATUS_PENDING;
    public ?string $myobUid = null;
    public ?string $myobNumber = null;
    public ?string $myobUri = null;
    public ?string $rowVersion = null;
    public ?float $amount = null;
    public ?string $currency = null;
    public int $attempts = 0;
    public ?string $lastError = null;
    public ?string $payload = null;
    public ?string $response = null;
    public ?DateTime $dateSynced = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateSynced']);
    }

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED && $this->myobUid !== null;
    }

    /**
     * In MYOB, but at a different total from the order. See {@see \justinholtweb\my\services\Sync::mismatchCondition()}.
     */
    public function isMismatched(): bool
    {
        return $this->status === self::STATUS_SYNCED && $this->lastError !== null && $this->lastError !== '';
    }

    public function getTypeLabel(): string
    {
        return match ($this->docType) {
            self::TYPE_INVOICE => Craft::t('my', 'Invoice'),
            self::TYPE_PAYMENT => Craft::t('my', 'Payment'),
            self::TYPE_CREDIT_NOTE => Craft::t('my', 'Credit note'),
            self::TYPE_REFUND => Craft::t('my', 'Credit refund'),
            default => $this->docType,
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => Craft::t('my', 'Pending'),
            self::STATUS_SYNCED => Craft::t('my', 'Synced'),
            self::STATUS_FAILED => Craft::t('my', 'Failed'),
            self::STATUS_SKIPPED => Craft::t('my', 'Skipped'),
            default => $this->status,
        };
    }

    /**
     * Craft's status-dot colours, so the index reads at a glance.
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_SYNCED => 'green',
            self::STATUS_FAILED => 'red',
            self::STATUS_PENDING => 'orange',
            default => 'grey',
        };
    }

    public function getCpUrl(): string
    {
        return UrlHelper::cpUrl('my/documents/' . $this->id);
    }

    public function getOrderCpUrl(): string
    {
        return UrlHelper::cpUrl('commerce/orders/' . $this->orderId);
    }
}
