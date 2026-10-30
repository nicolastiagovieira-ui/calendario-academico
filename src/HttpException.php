<?php
declare(strict_types=1);
namespace Calendario;

final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) { parent::__construct($message); }
}

