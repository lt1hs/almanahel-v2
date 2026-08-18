<?php

namespace App\Exceptions;

use RuntimeException;

class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $context = []
    ) {
        parent::__construct($message);
    }
}
