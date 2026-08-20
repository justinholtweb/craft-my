<?php

namespace justinholtweb\my\errors;

use Throwable;
use yii\base\Exception;

/**
 * A MYOB request that came back wrong.
 *
 * MYOB answers a rejected write with a body like:
 *
 *     {"Errors":[{"Severity":"Error","Message":"…","AdditionalDetails":"…","ErrorCode":1234}]}
 *
 * `AdditionalDetails` is nearly always the useful half — `Message` says "Invalid data", the detail
 * says which field — so both are kept and both are shown.
 */
class MyobApiException extends Exception
{
    public ?int $statusCode = null;
    public ?string $body = null;
    public ?string $method = null;
    public ?string $endpoint = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $errors = [];

    public function __construct(
        string $message,
        ?int $statusCode = null,
        array $errors = [],
        ?string $body = null,
        ?string $method = null,
        ?string $endpoint = null,
        ?Throwable $previous = null,
    ) {
        $this->statusCode = $statusCode;
        $this->errors = $errors;
        $this->body = $body;
        $this->method = $method;
        $this->endpoint = $endpoint;

        parent::__construct($message, 0, $previous);
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'MYOB API error';
    }

    /**
     * Whether retrying could plausibly work. A 400 will be a 400 forever; a 429 or a 503 will not.
     */
    public function isRetryable(): bool
    {
        if ($this->statusCode === null) {
            // Connection reset, DNS failure, timeout — no response at all.
            return true;
        }

        return $this->statusCode === 429 || $this->statusCode >= 500;
    }

    /**
     * A 409 means the record moved under us since it was read. Re-read and retry the PUT; never
     * turn it into a POST, which is how duplicates get made.
     */
    public function isConflict(): bool
    {
        return $this->statusCode === 409;
    }

    /**
     * Every message MYOB gave, flattened for display.
     *
     * @return string[]
     */
    public function getDetails(): array
    {
        $out = [];

        foreach ($this->errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $message = trim((string)($error['Message'] ?? ''));
            $detail = trim((string)($error['AdditionalDetails'] ?? ''));

            $line = $detail !== '' && $detail !== $message
                ? ($message !== '' ? "$message — $detail" : $detail)
                : $message;

            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }
}
