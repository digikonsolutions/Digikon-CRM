<?php

namespace App\Services\Ai;

use RuntimeException;

class AiException extends RuntimeException
{
    public function __construct(string $message, public bool $temporary = false)
    {
        parent::__construct($message);
    }
}
