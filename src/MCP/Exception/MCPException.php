<?php
// src/MCP/Exception/MCPException.php
namespace App\MCP\Exception;

/**
 * Base exception for MCP-related errors.
 */
class MCPException extends \RuntimeException
{
    public const ERROR_CODE_TRANSPORT = 1000;
    public const ERROR_CODE_TIMEOUT = 1001;
    public const ERROR_CODE_SERVER = 1002;
    public const ERROR_CODE_PROTOCOL = 1003;
    public const ERROR_CODE_TOOL_NOT_FOUND = 1004;

    public static function transport(string $message, \Throwable $previous = null): self
    {
        return new self($message, self::ERROR_CODE_TRANSPORT, $previous);
    }

    public static function timeout(string $message, \Throwable $previous = null): self
    {
        return new self($message, self::ERROR_CODE_TIMEOUT, $previous);
    }

    public static function server(string $message, \Throwable $previous = null): self
    {
        return new self($message, self::ERROR_CODE_SERVER, $previous);
    }

    public static function protocol(string $message, \Throwable $previous = null): self
    {
        return new self($message, self::ERROR_CODE_PROTOCOL, $previous);
    }

    public static function toolNotFound(string $toolName): self
    {
        return new self(sprintf('Tool "%s" not found.', $toolName), self::ERROR_CODE_TOOL_NOT_FOUND);
    }
}
