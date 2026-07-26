<?php
// src/MCP/Exception/TransportException.php
namespace App\MCP\Exception;

class TransportException extends MCPException
{
    public static function connectionFailed(string $message, \Throwable $previous = null): self
    {
        return new self($message, self::ERROR_CODE_TRANSPORT, $previous);
    }
}
