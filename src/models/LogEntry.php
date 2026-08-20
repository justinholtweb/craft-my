<?php

namespace justinholtweb\my\models;

use craft\base\Model;
use DateTime;

/**
 * One line of the connection log.
 */
class LogEntry extends Model
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public ?int $id = null;
    public string $action = '';
    public string $level = self::LEVEL_INFO;
    public ?string $method = null;
    public ?string $endpoint = null;
    public ?int $statusCode = null;
    public ?int $durationMs = null;
    public ?int $orderId = null;
    public ?string $summary = null;
    public ?string $message = null;
    public ?string $request = null;
    public ?string $response = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function getLevelColor(): string
    {
        return match ($this->level) {
            self::LEVEL_ERROR => 'red',
            self::LEVEL_WARNING => 'orange',
            default => 'green',
        };
    }
}
