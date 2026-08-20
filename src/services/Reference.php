<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\Plugin;

/**
 * MYOB's reference data — accounts, tax codes and inventory items.
 *
 * Everything a merchant configures is a *code*: a sales account is `4-1000`, a tax code is `GST`.
 * Everything MYOB accepts on a write is a *UID*. This service is the translation, and it caches,
 * because a company file's chart of accounts changes about twice a year and looking it up on every
 * invoice would spend most of the rate limit on questions with the same answer.
 */
class Reference extends Component
{
    /**
     * How long a cached lookup table lives. Long, because "Refresh from MYOB" on the settings
     * screen is the way to pick up a change, and a stale account code fails loudly rather than
     * silently posting somewhere wrong.
     */
    public const CACHE_DURATION = 86400;

    private const CACHE_PREFIX = 'my:ref:';

    /**
     * Accounts keyed by `DisplayID`.
     *
     * @return array<string, array{UID: string, DisplayID: string, Name: string, Type: string}>
     * @throws MyobApiException
     */
    public function getAccounts(bool $refresh = false): array
    {
        return $this->cached('accounts', $refresh, function(): array {
            $rows = Plugin::getInstance()->getApi()->getAll('GeneralLedger/Account', [
                'action' => 'ref.accounts',
                '$orderby' => 'DisplayID',
            ]);

            $accounts = [];

            foreach ($rows as $row) {
                $displayId = (string)($row['DisplayID'] ?? '');

                if ($displayId === '') {
                    continue;
                }

                $accounts[$displayId] = [
                    'UID' => (string)($row['UID'] ?? ''),
                    'DisplayID' => $displayId,
                    'Name' => (string)($row['Name'] ?? ''),
                    'Type' => (string)($row['Type'] ?? ''),
                ];
            }

            return $accounts;
        });
    }

    /**
     * Tax codes keyed by `Code`.
     *
     * @return array<string, array{UID: string, Code: string, Description: string, Rate: float}>
     * @throws MyobApiException
     */
    public function getTaxCodes(bool $refresh = false): array
    {
        return $this->cached('taxcodes', $refresh, function(): array {
            $rows = Plugin::getInstance()->getApi()->getAll('GeneralLedger/TaxCode', [
                'action' => 'ref.taxcodes',
                '$orderby' => 'Code',
            ]);

            $codes = [];

            foreach ($rows as $row) {
                $code = (string)($row['Code'] ?? '');

                if ($code === '') {
                    continue;
                }

                $codes[$code] = [
                    'UID' => (string)($row['UID'] ?? ''),
                    'Code' => $code,
                    'Description' => (string)($row['Description'] ?? ''),
                    'Rate' => (float)($row['Rate'] ?? 0),
                ];
            }

            return $codes;
        });
    }

    /**
     * An account reference for a payload, from a `DisplayID`.
     *
     * @return array{UID: string}|null
     */
    public function accountRef(string $displayId): ?array
    {
        $displayId = trim($displayId);

        if ($displayId === '') {
            return null;
        }

        try {
            $accounts = $this->getAccounts();
        } catch (MyobApiException) {
            return null;
        }

        $uid = $accounts[$displayId]['UID'] ?? null;

        return $uid ? ['UID' => $uid] : null;
    }

    /**
     * A tax code reference for a payload, from a `Code`.
     *
     * @return array{UID: string}|null
     */
    public function taxCodeRef(string $code): ?array
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        try {
            $codes = $this->getTaxCodes();
        } catch (MyobApiException) {
            return null;
        }

        $uid = $codes[$code]['UID'] ?? null;

        return $uid ? ['UID' => $uid] : null;
    }

    /**
     * Find an inventory item by its MYOB item number, which is what a Commerce SKU maps onto.
     *
     * Not cached wholesale: a catalogue can run to tens of thousands of items, and the answer is
     * needed for a handful of SKUs per order. Each answer is cached individually, including the
     * misses — repeatedly asking MYOB about a SKU that will never be there is the expensive case.
     *
     * @return array{UID: string, Number: string, Name: string, IsSold: bool}|null
     */
    public function findItem(string $number): ?array
    {
        $number = trim($number);

        if ($number === '') {
            return null;
        }

        $cache = Craft::$app->getCache();
        $key = self::CACHE_PREFIX . 'item:' . md5($this->fileKey() . '|' . $number);
        $hit = $cache->get($key);

        if ($hit !== false) {
            return $hit === 'miss' ? null : $hit;
        }

        try {
            // Single quotes inside an OData string literal are escaped by doubling them.
            $filter = "Number eq '" . str_replace("'", "''", $number) . "'";
            $row = Plugin::getInstance()->getApi()->findOne('Inventory/Item', $filter, [
                'action' => 'ref.item',
            ]);
        } catch (MyobApiException) {
            // Do not cache a failure to ask; the item may well exist.
            return null;
        }

        if ($row === null) {
            $cache->set($key, 'miss', self::CACHE_DURATION);

            return null;
        }

        $item = [
            'UID' => (string)($row['UID'] ?? ''),
            'Number' => (string)($row['Number'] ?? $number),
            'Name' => (string)($row['Name'] ?? ''),
            'IsSold' => (bool)($row['IsSold'] ?? false),
        ];

        $cache->set($key, $item, self::CACHE_DURATION);

        return $item;
    }

    /**
     * Drop every cached lookup for the connected company file.
     *
     * Nothing is deleted: the generation counter in every cache key is bumped instead, which
     * orphans the whole set at once — including the per-item lookups, which are keyed individually
     * and so cannot be enumerated to be deleted.
     */
    public function flush(): void
    {
        $id = $this->companyFileId();

        Craft::$app->getCache()->set($this->generationKey($id), $this->generation($id) + 1, 0);
    }

    /**
     * A settings screen wants to know what went wrong, not an empty list.
     *
     * @return array{ok: bool, accounts: int, taxCodes: int, message: string}
     */
    public function refreshAll(): array
    {
        try {
            $accounts = $this->getAccounts(true);
            $taxCodes = $this->getTaxCodes(true);
        } catch (MyobApiException $e) {
            return [
                'ok' => false,
                'accounts' => 0,
                'taxCodes' => 0,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'accounts' => count($accounts),
            'taxCodes' => count($taxCodes),
            'message' => Craft::t('my', 'Loaded {accounts} accounts and {taxCodes} tax codes.', [
                'accounts' => count($accounts),
                'taxCodes' => count($taxCodes),
            ]),
        ];
    }

    private function cached(string $set, bool $refresh, callable $fetch): array
    {
        $cache = Craft::$app->getCache();
        $key = self::CACHE_PREFIX . $set . ':' . $this->fileKey();

        if (!$refresh) {
            $hit = $cache->get($key);

            if (is_array($hit)) {
                return $hit;
            }
        }

        $data = $fetch();
        $cache->set($key, $data, self::CACHE_DURATION);

        return $data;
    }

    /**
     * Cache keys carry the company file and a generation counter, so switching files or hitting
     * "Refresh" cannot serve one file's chart of accounts to another.
     */
    private function fileKey(): string
    {
        $id = $this->companyFileId();

        return $id . ':' . $this->generation($id);
    }

    private function companyFileId(): string
    {
        return Plugin::getInstance()->getAuth()->getConnection()->getEffectiveCompanyFileId() ?? 'none';
    }

    private function generationKey(string $id): string
    {
        return self::CACHE_PREFIX . 'gen:' . $id;
    }

    private function generation(string $id): int
    {
        return (int)Craft::$app->getCache()->get($this->generationKey($id));
    }
}
