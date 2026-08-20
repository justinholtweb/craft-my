<?php

namespace justinholtweb\my\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\my\db\Table;

/**
 * My install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::CONTACTS);
        $this->dropTableIfExists(Table::DOCUMENTS);
        $this->dropTableIfExists(Table::CONNECTION);

        return true;
    }

    private function createTables(): void
    {
        // One row, id 1. OAuth tokens are rewritten every twenty minutes and are secrets, so they
        // do not belong in project config — this is deliberately a plain table.
        $this->createTable(Table::CONNECTION, [
            'id' => $this->primaryKey(),
            'mode' => $this->string(16)->notNull()->defaultValue('cloud'),
            'accessToken' => $this->text(),
            'refreshToken' => $this->text(),
            'expiryDate' => $this->dateTime(),
            'scope' => $this->string(255),
            'companyFileId' => $this->string(64),
            'companyFileUri' => $this->string(255),
            'companyFileName' => $this->string(255),
            'country' => $this->string(8),
            'productVersion' => $this->string(32),
            'dateConnected' => $this->dateTime(),
            'dateLastVerified' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // The sync ledger. A row is claimed *before* the HTTP call so a retried queue job cannot
        // create a second invoice for the same order.
        $this->createTable(Table::DOCUMENTS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            // invoice | payment | creditnote
            'docType' => $this->string(16)->notNull(),
            // What in the order this document is *for*: 'order' for the invoice, the Commerce
            // transaction hash for a payment or refund. Part of the uniqueness guarantee.
            'sourceKey' => $this->string(64)->notNull(),
            // pending | synced | failed | skipped
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'myobUid' => $this->string(64),
            'myobNumber' => $this->string(32),
            'myobUri' => $this->string(255),
            'rowVersion' => $this->string(64),
            'amount' => $this->decimal(14, 4),
            'currency' => $this->string(8),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastError' => $this->text(),
            'payload' => $this->mediumText(),
            'response' => $this->mediumText(),
            'dateSynced' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CONTACTS, [
            'id' => $this->primaryKey(),
            // Lower-cased email, or 'user:<id>' when the order has a Craft user and no email.
            'sourceKey' => $this->string(255)->notNull(),
            'customerId' => $this->integer(),
            'myobUid' => $this->string(64)->notNull(),
            'displayId' => $this->string(32),
            'name' => $this->string(255),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'action' => $this->string(48)->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue('info'),
            'method' => $this->string(8),
            'endpoint' => $this->string(255),
            'statusCode' => $this->integer(),
            'durationMs' => $this->integer(),
            'orderId' => $this->integer(),
            'summary' => $this->string(255),
            'message' => $this->text(),
            'request' => $this->mediumText(),
            'response' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::DOCUMENTS, ['orderId'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['status'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['myobUid'], false);
        // The idempotency guarantee: one invoice per order, one document per payment.
        $this->createIndex(null, Table::DOCUMENTS, ['orderId', 'docType', 'sourceKey'], true);

        $this->createIndex(null, Table::CONTACTS, ['sourceKey'], true);
        $this->createIndex(null, Table::CONTACTS, ['customerId'], false);

        $this->createIndex(null, Table::LOG, ['action'], false);
        $this->createIndex(null, Table::LOG, ['level'], false);
        $this->createIndex(null, Table::LOG, ['orderId'], false);
        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::DOCUMENTS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::CONTACTS, ['customerId'], CraftTable::USERS, ['id'], 'SET NULL', null);
    }
}
