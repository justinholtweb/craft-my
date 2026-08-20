<?php

namespace justinholtweb\my\db;

/**
 * My's database tables.
 */
abstract class Table
{
    public const CONNECTION = '{{%my_connection}}';
    public const DOCUMENTS = '{{%my_documents}}';
    public const CONTACTS = '{{%my_contacts}}';
    public const LOG = '{{%my_log}}';
}
