<?php

namespace justinholtweb\my\migrations;

use craft\db\Migration;
use justinholtweb\my\db\Table;

/**
 * Failure alerts and the scheduled sync summary (5.1.0): one latch row per incident type, and the
 * summary's "last sent" marker.
 *
 * Both table definitions live here as static methods so `Install` and this migration cannot drift
 * apart.
 */
class m261009_000000_alerts_and_digest extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::ALERTS)) {
            self::createAlertsTable($this);
        }

        if (!$this->db->tableExists(Table::DIGESTS)) {
            self::createDigestsTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::DIGESTS);
        $this->dropTableIfExists(Table::ALERTS);

        return true;
    }

    /**
     * The alert latch. Unique on `incident`, so there is exactly one row to compare and stamp — the
     * database, not a remembered check, is what makes "one email per incident" true when a queue
     * worker and cron both run a check in the same minute.
     *
     * Erpy keys the same table on `(connectionId, incident)`. My has one company file per
     * environment, so the column would only ever hold one value.
     */
    public static function createAlertsTable(Migration $migration): void
    {
        $migration->createTable(Table::ALERTS, [
            'id' => $migration->primaryKey(),
            'incident' => $migration->string(32)->notNull(),
            // `ok` or `open`.
            'state' => $migration->string(8)->notNull()->defaultValue('ok'),
            // Redacted, short: what the last check saw. Never a payload or a credential.
            'detail' => $migration->text(),
            // Pushed signals (MYOB refusing the connection) — the other incidents are measured.
            'signalledAt' => $migration->dateTime()->null(),
            'signalClearedAt' => $migration->dateTime()->null(),
            'openedAt' => $migration->dateTime()->null(),
            'notifiedAt' => $migration->dateTime()->null(),
            'recoveredAt' => $migration->dateTime()->null(),
            'recoveryNotifiedAt' => $migration->dateTime()->null(),
            // Set when a recovery goes out: a reopening before this is held, not sent.
            'quietUntil' => $migration->dateTime()->null(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::ALERTS, ['incident'], true);
    }

    /**
     * The summary's marker: which period was last claimed, which problems the last summary listed,
     * and how the last run went. State that has to survive a cache clear, or every clear would
     * resend the week's summary.
     */
    public static function createDigestsTable(Migration $migration): void
    {
        $migration->createTable(Table::DIGESTS, [
            'id' => $migration->primaryKey(),
            'handle' => $migration->string(64)->notNull(),
            // The period last claimed: `2026-10-09` or `2026-W41`. Null until the first run.
            'period' => $migration->string(32),
            // JSON list of the problem keys reported last time, so the next one can say what is new.
            'seen' => $migration->mediumText(),
            'lastRunAt' => $migration->dateTime(),
            'lastSentAt' => $migration->dateTime(),
            'lastResult' => $migration->string(32),
            'lastCount' => $migration->integer()->notNull()->defaultValue(0),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::DIGESTS, ['handle'], true);
    }
}
