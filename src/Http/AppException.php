<?php

namespace App\Http;

use RuntimeException;

final class AppException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 500)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
