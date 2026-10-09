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

    /** Failure-alert latches: one row per incident type. */
    public const ALERTS = '{{%my_alerts}}';

    /** The scheduled sync summary's "last sent" marker. */
    public const DIGESTS = '{{%my_digests}}';
}
