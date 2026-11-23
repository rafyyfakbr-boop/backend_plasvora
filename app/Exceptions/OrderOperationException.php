<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class OrderOperationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
        public readonly ?array $data = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
