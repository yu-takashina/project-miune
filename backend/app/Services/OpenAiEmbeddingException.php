<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class OpenAiEmbeddingException extends RuntimeException
{
    public function __construct(
        string $message,
        public bool $permanent,
        public bool $authentication,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
