<?php
// src/MCP/Transport/StdioTransport.php
namespace App\MCP\Transport;

use App\MCP\Exception\TransportException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * STDIO transport for MCP servers (e.g., local npm servers).
 */
final class StdioTransport implements TransportInterface
{
    private ?Process $process = null;

    public function __construct(
        private string $command,
        private array $arguments = [],
        private int $timeout = 60,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function send(array $request): array
    {
        if (!$this->process || !$this->process->isRunning()) {
            $this->startProcess();
        }

        try {
            $this->process->write(json_encode($request, JSON_THROW_ON_ERROR) . "\n");
            $response = $this->process->readLine();
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException | ProcessFailedException $e) {
            throw TransportException::connectionFailed(
                sprintf('STDIO communication failed: %s', $e->getMessage()),
                $e
            );
        }
    }

    /**
     * Starts the MCP server process.
     *
     * @throws TransportException If the process fails to start.
     */
    private function startProcess(): void
    {
        $this->process = new Process(array_merge([$this->command], $this->arguments));
        $this->process->setTimeout($this->timeout);
        $this->process->start();

        if (!$this->process->isRunning()) {
            throw TransportException::connectionFailed(
                sprintf('Failed to start MCP server: %s', $this->process->getErrorOutput())
            );
        }
    }

    /**
     * Stops the MCP server process.
     */
    public function stop(): void
    {
        if ($this->process && $this->process->isRunning()) {
            $this->process->stop();
        }
    }
}
