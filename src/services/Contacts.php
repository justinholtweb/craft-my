<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\Address;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\my\db\Table;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\models\LogEntry;
use justinholtweb\my\models\Settings;
use justinholtweb\my\Plugin;

/**
 * Customer cards.
 *
 * An invoice needs a customer UID, and MYOB has no idea who anybody in Craft is. This finds the
 * card, and creates it if there isn't one — in that order, because a merchant who has been trading
 * for ten years already has cards for half the people who check out, and duplicating them is the
 * single most annoying thing an integration can do to a bookkeeper.
 *
 * Lookup order:
 *
 * 1. My's own `{{%my_contacts}}` map (email → UID).
 * 2. MYOB, by email address.
 * 3. Create.
 */
class Contacts extends Component
{
    /**
     * MYOB's column widths. Exceeding one is a 400, not a truncation.
     */
    private const MAX_COMPANY_NAME = 50;
    private const MAX_LAST_NAME = 30;
    private const MAX_FIRST_NAME = 20;
    private const MAX_DISPLAY_ID = 15;

    /**
     * What a read-only resolution (a preview, a dry run) puts where a card would be created.
     * Plainly not a MYOB UID, so it cannot be mistaken for one in the printed payload.
     */
    public const PLACEHOLDER_UID = 'new-card-created-on-push';

    /**
     * The MYOB customer for an order, as a payload reference.
     *
     * `$readOnly` is for the preview panel and `--dryRun`: nothing is created or updated in MYOB
     * and nothing is remembered locally. A card that would have to be created is answered with
     * `PLACEHOLDER_UID` instead.
     *
     * @return array{UID: string}
     * @throws MyobApiException
     */
    public function resolveForOrder(Order $order, bool $readOnly = false): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->customerMode === Settings::CUSTOMER_SINGLE_CARD || !$settings->syncCustomers) {
            return $this->defaultCustomer($readOnly);
        }

        $key = $this->sourceKey($order);

        if ($key === null) {
            // No email and no user: a genuinely anonymous order. The default card is the only
            // honest answer — inventing a card called "Guest 4821" per order is how company files
            // end up with 40,000 dead contacts.
            return $this->defaultCustomer($readOnly);
        }

        $known = $this->getMapping($key);

        if ($known !== null) {
            if ($settings->updateExistingContacts && !$readOnly) {
                $this->updateContact($known, $order);
            }

            return ['UID' => $known];
        }

        $existing = $this->findByEmail($this->email($order));

        if ($existing !== null) {
            if (!$readOnly) {
                $this->remember($key, $existing, $order);
            }

            return ['UID' => (string)$existing['UID']];
        }

        if ($readOnly) {
            return ['UID' => self::PLACEHOLDER_UID];
        }

        $created = $this->createContact($order);
        $this->remember($key, $created, $order);

        return ['UID' => (string)$created['UID']];
    }

    /**
     * The card everything falls back to.
     *
     * @return array{UID: string}
     * @throws MyobApiException
     */
    public function defaultCustomer(bool $readOnly = false): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $uid = trim($settings->defaultCustomerUid);

        if ($uid !== '') {
            return ['UID' => $uid];
        }

        $name = trim($settings->defaultCustomerName);

        if ($name === '') {
            throw new MyobApiException(Craft::t('my', 'No default MYOB customer is set. Choose one on the settings screen, or turn customer syncing on.'));
        }

        $key = 'default:' . mb_strtolower($name);
        $known = $this->getMapping($key);

        if ($known !== null) {
            return ['UID' => $known];
        }

        $filter = "CompanyName eq '" . $this->escape($name) . "'";
        $row = Plugin::getInstance()->getApi()->findOne('Contact/Customer', $filter, ['action' => 'contact.find']);

        if ($row === null && $readOnly) {
            return ['UID' => self::PLACEHOLDER_UID];
        }

        if ($row === null) {
            $row = Plugin::getInstance()->getApi()->post('Contact/Customer', [
                'IsIndividual' => false,
                'CompanyName' => mb_substr($name, 0, self::MAX_COMPANY_NAME),
                'IsActive' => true,
            ], ['action' => 'contact.create']);
        }

        $uid = (string)($row['UID'] ?? '');

        if ($uid === '') {
            throw new MyobApiException(Craft::t('my', 'MYOB did not return a UID for the default customer card.'));
        }

        if (!$readOnly) {
            $this->rememberUid($key, $uid, (string)($row['DisplayID'] ?? ''), $name, null);
        }

        return ['UID' => $uid];
    }

    /**
     * @return array|null
     * @throws MyobApiException
     */
    public function findByEmail(?string $email): ?array
    {
        $email = trim((string)$email);

        if ($email === '') {
            return null;
        }

        // MYOB's OData supports `any` over a collection, which is the only way to reach the email
        // buried in the Addresses array.
        $filter = "Addresses/any(x: x/Email eq '" . $this->escape($email) . "')";

        return Plugin::getInstance()->getApi()->findOne('Contact/Customer', $filter, [
            'action' => 'contact.find',
        ]);
    }

    /**
     * @throws MyobApiException
     */
    public function createContact(Order $order): array
    {
        $payload = $this->buildPayload($order);

        $row = Plugin::getInstance()->getApi()->post('Contact/Customer', $payload, [
            'action' => 'contact.create',
            'orderId' => $order->id,
        ]);

        if (!is_array($row) || empty($row['UID'])) {
            throw new MyobApiException(Craft::t('my', 'MYOB accepted the customer card but returned no UID.'));
        }

        return $row;
    }

    /**
     * Push the order's address onto an existing card.
     *
     * Best-effort: a customer whose card cannot be updated should still get an invoice, so this
     * logs and moves on rather than failing the push.
     */
    public function updateContact(string $uid, Order $order): void
    {
        try {
            $current = Plugin::getInstance()->getApi()->get('Contact/Customer/' . $uid, [
                'action' => 'contact.read',
                'orderId' => $order->id,
            ]);

            if (!is_array($current) || empty($current['RowVersion'])) {
                return;
            }

            $payload = $this->buildPayload($order) + [
                'UID' => $uid,
                // A PUT without the current RowVersion is a 409. It is the whole point of the
                // field, and the whole reason the record has to be read first.
                'RowVersion' => (string)$current['RowVersion'],
            ];

            // Never flip an existing card between company and individual — that is the merchant's
            // decision, and changing it rewrites how the card is addressed everywhere.
            unset($payload['IsIndividual']);

            Plugin::getInstance()->getApi()->put('Contact/Customer/' . $uid, $payload, [
                'action' => 'contact.update',
                'orderId' => $order->id,
            ]);
        } catch (MyobApiException $e) {
            Plugin::getInstance()->getLog()->write('contact.update', [
                'level' => LogEntry::LEVEL_WARNING,
                'orderId' => $order->id,
                'summary' => Craft::t('my', 'Could not update the customer card'),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The MYOB payload for an order's customer.
     */
    public function buildPayload(Order $order): array
    {
        $address = $order->getBillingAddress() ?? $order->getShippingAddress();
        $email = $this->email($order);

        $organization = trim((string)($address?->organization ?? ''));
        $isIndividual = $organization === '';

        $payload = [
            'IsIndividual' => $isIndividual,
            'IsActive' => true,
        ];

        if ($isIndividual) {
            [$first, $last] = $this->splitName($order, $address);
            $payload['FirstName'] = mb_substr($first, 0, self::MAX_FIRST_NAME);
            // MYOB requires a surname on an individual and will not accept an empty one.
            $payload['LastName'] = mb_substr($last !== '' ? $last : ($email ?: Craft::t('my', 'Customer')), 0, self::MAX_LAST_NAME);
        } else {
            $payload['CompanyName'] = mb_substr($organization, 0, self::MAX_COMPANY_NAME);
        }

        $displayId = $this->renderDisplayId($order);

        if ($displayId !== '') {
            $payload['DisplayID'] = mb_substr($displayId, 0, self::MAX_DISPLAY_ID);
        }

        $addresses = [];

        if ($address !== null || $email !== null) {
            $addresses[] = array_filter([
                'Location' => 1,
                'Street' => $this->street($address),
                'City' => $address?->locality,
                'State' => $address?->administrativeArea,
                'PostCode' => $address?->postalCode,
                'Country' => $address?->countryCode,
                'Email' => $email,
                'Phone1' => $this->phone($address),
                'ContactName' => $address?->fullName,
            ], static fn($value) => $value !== null && $value !== '');
        }

        $shipping = $order->getShippingAddress();

        if ($shipping !== null && $address !== null && $shipping->id !== $address->id) {
            $addresses[] = array_filter([
                'Location' => 2,
                'Street' => $this->street($shipping),
                'City' => $shipping->locality,
                'State' => $shipping->administrativeArea,
                'PostCode' => $shipping->postalCode,
                'Country' => $shipping->countryCode,
                'Phone1' => $this->phone($shipping),
                'ContactName' => $shipping->fullName,
            ], static fn($value) => $value !== null && $value !== '');
        }

        if ($addresses !== []) {
            $payload['Addresses'] = $addresses;
        }

        return $payload;
    }

    // The local map
    // -------------------------------------------------------------------------

    public function getMapping(string $sourceKey): ?string
    {
        $uid = (new Query())
            ->select(['myobUid'])
            ->from([Table::CONTACTS])
            ->where(['sourceKey' => $sourceKey])
            ->scalar();

        return $uid !== false && $uid !== null && $uid !== '' ? (string)$uid : null;
    }

    /**
     * Forget a mapping — used when MYOB 404s a UID we thought we knew, which is what a deleted
     * card looks like from here.
     */
    public function forget(string $sourceKey): void
    {
        Craft::$app->getDb()->createCommand()->delete(Table::CONTACTS, ['sourceKey' => $sourceKey])->execute();
    }

    public function forgetAll(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::CONTACTS)->execute();
    }

    public function count(): int
    {
        return (int)(new Query())->from([Table::CONTACTS])->count('[[id]]');
    }

    /**
     * The key an order's customer is remembered under.
     */
    public function sourceKey(Order $order): ?string
    {
        $email = $this->email($order);

        if ($email !== null) {
            return mb_strtolower($email);
        }

        $customerId = $order->getCustomerId();

        return $customerId ? 'user:' . $customerId : null;
    }

    private function remember(string $key, array $row, Order $order): void
    {
        $this->rememberUid(
            $key,
            (string)($row['UID'] ?? ''),
            (string)($row['DisplayID'] ?? ''),
            (string)($row['CompanyName'] ?? trim(($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? ''))),
            $order->getCustomerId(),
        );
    }

    private function rememberUid(string $key, string $uid, string $displayId, string $name, ?int $customerId): void
    {
        if ($uid === '') {
            return;
        }

        Db::upsert(Table::CONTACTS, [
            'sourceKey' => mb_substr($key, 0, 255),
            'myobUid' => $uid,
        ], [
            'customerId' => $customerId,
            'displayId' => mb_substr($displayId, 0, 32),
            'name' => mb_substr(trim($name), 0, 255),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ] + [
            // `Db::upsert` only writes its *update* columns on a conflict, so anything the insert
            // needs and the update does not has to be named on the insert side as well.
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ]);
    }

    // Bits of an order
    // -------------------------------------------------------------------------

    private function email(Order $order): ?string
    {
        $email = trim((string)$order->getEmail());

        return $email !== '' ? $email : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(Order $order, ?Address $address): array
    {
        $first = trim((string)($address?->firstName ?? ''));
        $last = trim((string)($address?->lastName ?? ''));

        if ($first !== '' || $last !== '') {
            return [$first, $last];
        }

        $full = trim((string)($address?->fullName ?? ''));

        if ($full === '') {
            $full = trim((string)($order->getCustomer()?->fullName ?? ''));
        }

        if ($full === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $full) ?: [];

        if (count($parts) === 1) {
            return ['', $parts[0]];
        }

        $last = (string)array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    private function street(?Address $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $lines = array_filter([
            $address->addressLine1,
            $address->addressLine2,
            $address->addressLine3,
        ], static fn($line) => trim((string)$line) !== '');

        return $lines !== [] ? mb_substr(implode("\n", $lines), 0, 255) : null;
    }

    /**
     * Craft 5 addresses have no phone attribute, so it can only come from a custom field the
     * merchant names in settings.
     */
    private function phone(?Address $address): ?string
    {
        $handle = trim(Plugin::getInstance()->getSettings()->phoneFieldHandle);

        if ($address === null || $handle === '') {
            return null;
        }

        try {
            $value = $address->getFieldValue($handle);
        } catch (\Throwable) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value !== '' ? mb_substr($value, 0, 21) : null;
    }

    private function renderDisplayId(Order $order): string
    {
        $template = trim(Plugin::getInstance()->getSettings()->customerDisplayIdTemplate);

        if ($template === '') {
            return '';
        }

        try {
            return trim(Craft::$app->getView()->renderObjectTemplate($template, $order, ['element' => $order]));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * A single quote inside an OData string literal is escaped by doubling it. Without this an
     * address like `o'brien@example.com` produces a filter MYOB cannot parse.
     */
    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
