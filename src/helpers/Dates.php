<?php

namespace justinholtweb\my\helpers;

use DateTime;
use DateTimeInterface;
use DateTimeZone;

/**
 * MYOB date formatting.
 *
 * The company file has no time zone of its own — it stores wall-clock dates, and a transaction is
 * dated by the day the merchant thinks it happened. Craft stores everything in UTC. Converting to
 * the *site's* time zone before formatting is what stops an order placed at 9am Sydney time on the
 * 1st being booked to the 31st.
 */
abstract class Dates
{
    public const FORMAT = 'Y-m-d\TH:i:s';

    public static function format(?DateTimeInterface $date, ?DateTimeZone $timeZone = null): ?string
    {
        if ($date === null) {
            return null;
        }

        $local = DateTime::createFromInterface($date);
        $local->setTimezone($timeZone ?? new DateTimeZone(date_default_timezone_get()));

        return $local->format(self::FORMAT);
    }

    /**
     * Midnight on the same local day — how MYOB dates a transaction.
     */
    public static function formatDay(?DateTimeInterface $date, ?DateTimeZone $timeZone = null): ?string
    {
        if ($date === null) {
            return null;
        }

        $local = DateTime::createFromInterface($date);
        $local->setTimezone($timeZone ?? new DateTimeZone(date_default_timezone_get()));
        $local->setTime(0, 0, 0);

        return $local->format(self::FORMAT);
    }

    /**
     * Parse a date out of a MYOB response. MYOB emits `2024-03-01T00:00:00` with no offset, meaning
     * company-file local time.
     */
    public static function parse(?string $value): ?DateTime
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
