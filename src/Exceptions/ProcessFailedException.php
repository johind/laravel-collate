<?php

declare(strict_types=1);

namespace Johind\Collate\Exceptions;

class ProcessFailedException extends CollateException
{
    public function __construct(
        string $message,
        public readonly int $exitCode,
        public readonly string $errorOutput,
    ) {
        parent::__construct($message, $exitCode);
    }
}
