<?php

namespace App\Exceptions;

use RuntimeException;

class MetaApiException extends RuntimeException
{
    public function __construct(string $message, public bool $temporary = false, public ?int $metaCode = null)
    {
        parent::__construct($message);
    }
}
