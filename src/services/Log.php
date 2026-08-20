<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\my\db\Table;
use justinholtweb\my\models\LogEntry;
use justinholtweb\my\Plugin;

/**
 * The connection log.
 *
 * An accounting integration that "just doesn't work" is otherwise unfalsifiable: the merchant sees
 * no invoice, MYOB has nothing to show, and neither end says why. Every request My sends lands
 * here with its payload and MYOB's reply.
 */
class Log extends Component
{
    /**
     * Payload bodies over this are truncated. Nobody reads past the first screen, and a bulk
     * backfill would otherwise write megabytes a minute.
     */
    public const MAX_PAYLOAD = 65535;

    /**
     * Secrets that must never reach a log row, whatever the payload shape.
     */
    private const REDACT_KEYS = [
        'access_token',
        'refresh_token',
        'client_secret',
        'password',
        'Authorization',
        'x-myobapi-cftoken',
    ];

    /**
     * @param array{
     *     level?: string,
     *     method?: string|null,
     *     endpoint?: string|null,
     *     statusCode?: int|null,
     *     durationMs?: int|null,
     *     orderId?: int|null,
     *     summary?: string|null,
     *     message?: string|null,
     *     request?: string|null,
     *     response?: string|null,
     * } $data
     */
    public function write(string $action, array $data = []): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->loggingEnabled) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
                'action' => $action,
                'level' => $data['level'] ?? LogEntry::LEVEL_INFO,
                'method' => $data['method'] ?? null,
                'endpoint' => isset($data['endpoint']) ? mb_substr((string)$data['endpoint'], 0, 255) : null,
                'statusCode' => $data['statusCode'] ?? null,
                'durationMs' => $data['durationMs'] ?? null,
                'orderId' => $data['orderId'] ?? null,
                'summary' => isset($data['summary']) ? mb_substr((string)$data['summary'], 0, 255) : null,
                'message' => $data['message'] ?? null,
                'request' => $settings->logPayloads ? $this->prepare($data['request'] ?? null) : null,
                'response' => $settings->logPayloads ? $this->prepare($data['response'] ?? null) : null,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable $e) {
            // The log is diagnostics, never the point. Failing to describe a request must not take
            // down the request it was describing.
            Craft::warning('My could not write a log entry: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @return LogEntry[]
     */
    public function getEntries(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        return array_map(
            static fn(array $row) => new LogEntry($row),
            $this->buildQuery($criteria)
                ->select([
                    'id', 'action', 'level', 'method', 'endpoint', 'statusCode', 'durationMs',
                    'orderId', 'summary', 'message', 'dateCreated', 'uid',
                ])
                ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
                ->limit($limit)
                ->offset($offset)
                ->all()
        );
    }

    public function getTotal(array $criteria = []): int
    {
        return (int)$this->buildQuery($criteria)->count('[[id]]');
    }

    public function getEntryById(int $id): ?LogEntry
    {
        $row = (new Query())->from([Table::LOG])->where(['id' => $id])->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * The distinct actions present, for the index filter.
     *
     * @return string[]
     */
    public function getActions(): array
    {
        return (new Query())
            ->select(['action'])
            ->distinct()
            ->from([Table::LOG])
            ->orderBy(['action' => SORT_ASC])
            ->column();
    }

    /**
     * Drop entries older than the configured retention. Returns the number deleted.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-$days days");

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG, [
            '<', 'dateCreated', Db::prepareDateForDb($cutoff),
        ])->execute();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    public function count(): int
    {
        return (int)(new Query())->from([Table::LOG])->count('[[id]]');
    }

    private function buildQuery(array $criteria): Query
    {
        $query = (new Query())->from([Table::LOG]);

        foreach (['action', 'level', 'orderId'] as $key) {
            if (!empty($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        return $query;
    }

    /**
     * Redact, then truncate. In that order — a secret hiding past the truncation point today is a
     * secret in the log tomorrow when the payload gets shorter.
     */
    private function prepare(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        foreach (self::REDACT_KEYS as $key) {
            $quoted = preg_quote($key, '/');

            // JSON: "access_token":"…"
            $payload = preg_replace('/("' . $quoted . '"\s*:\s*")[^"]*(")/i', '$1[redacted]$2', $payload) ?? $payload;
            // Header lines and form encoding: access_token=… / Authorization: Bearer …
            //
            // The scheme is kept and the credential replaced. Without the optional scheme group,
            // `Bearer` is the first non-space run after the colon and gets redacted *instead of*
            // the token — which reads as redacted and is not.
            $payload = preg_replace(
                '/(' . $quoted . '\s*[:=]\s*)(Bearer\s+|Basic\s+)?[^\s&;"]+/i',
                '$1$2[redacted]',
                $payload,
            ) ?? $payload;
        }

        if (strlen($payload) <= self::MAX_PAYLOAD) {
            return $payload;
        }

        return substr($payload, 0, self::MAX_PAYLOAD) . "\n…[truncated]";
    }
}
