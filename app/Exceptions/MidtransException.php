<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class MidtransException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 502,
        public readonly ?string $midtransOrderId = null,
        public readonly ?int $httpStatus = null,
        public readonly bool $isTimeout = false,
        public readonly bool $reconciliationRequired = false,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
