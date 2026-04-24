<?php

namespace App\Mcp;

use RuntimeException;

class McpException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $mcpCode = -32000,
        int $httpCode = 422,
    ) {
        parent::__construct($message, $httpCode);
    }

    public function getMcpCode(): int
    {
        return $this->mcpCode;
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self($message, -32001, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self($message, -32003, 403);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self($message, -32002, 404);
    }

    public static function invalidParams(string $message): self
    {
        return new self($message, -32602, 422);
    }
}
