<?php

declare(strict_types=1);

namespace App\Jev;

/**
 * Base error for Jev provider failures.
 */
class JevException extends \RuntimeException
{
    public ?int $statusCode;
    public bool $retriable;

    public function __construct(string $message, ?int $statusCode = null, bool $retriable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->retriable = $retriable;
    }
}
