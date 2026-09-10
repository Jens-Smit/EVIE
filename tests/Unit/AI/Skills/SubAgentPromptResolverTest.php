<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\SubAgentPromptResolver;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Vollstaendige Test-Abdeckung fuer SubAgentPromptResolver.
 */
final class SubAgentPromptResolverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/evie-prompts-' . uniqid('', true);
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/*') ?: []);
        rmdir($this->tempDir);
    }

    private function writeFile(string $name, string $content): void
    {
        file_put_contents($this->tempDir . '/' . $name, $content);
    }

    public function testLoadPromptTemplatesFromDirectory(): void
    {
        $this->writeFile('orchestrator.json', json_encode(['prompt' => 'Du bist Orchestrator'], JSON_THROW_ON_ERROR));
        $this->writeFile('researcher.json', json_encode(['prompt' => 'Du bist Researcher'], JSON_THROW_ON_ERROR));

        $resolver = new SubAgentPromptResolver($this->tempDir);
        self::assertSame('Du bist Orchestrator', $resolver->getPromptForAgent('orchestrator'));
        self::assertSame('Du bist Researcher', $resolver->getPromptForAgent('researcher'));
    }

    public function testGetPromptForAgentReturnsNullForUnknown(): void
    {
        $resolver = new SubAgentPromptResolver($this->tempDir);
        self::assertNull($resolver->getPromptForAgent('nonexistent'));
    }

    public function testGetPromptForAgentReturnsNullWhenNoPromptField(): void
    {
        $this->writeFile('incomplete.json', json_encode(['other' => 'data'], JSON_THROW_ON_ERROR));
        $resolver = new SubAgentPromptResolver($this->tempDir);
        self::assertNull($resolver->getPromptForAgent('incomplete'));
    }

    public function testConstructorWithNonExistentDirectoryDoesNothing(): void
    {
        $resolver = new SubAgentPromptResolver('/nonexistent/path/xyz');
        self::assertNull($resolver->getPromptForAgent('anything'));
    }

    public function testLoadIgnoresNonJsonFiles(): void
    {
        $this->writeFile('readme.txt', 'ignore me');
        $this->writeFile('data.csv', 'a,b,c');
        $this->writeFile('valid.json', json_encode(['prompt' => 'valid'], JSON_THROW_ON_ERROR));

        $resolver = new SubAgentPromptResolver($this->tempDir);
        self::assertSame('valid', $resolver->getPromptForAgent('valid'));
    }

    public function testCreateToolPromptWithCustomTemplate(): void
    {
        $this->writeFile('tool.json', json_encode(['base' => 'Custom: {name} | {description} | {schema} | {executorType}'], JSON_THROW_ON_ERROR));

        $def = new ToolDefinition();
        $def->setName('search-tool');
        $def->setDescription('Searches the web');
        $def->setSchema(['type' => 'object']);
        $def->setExecutorType('http');

        $resolver = new SubAgentPromptResolver($this->tempDir);
        $prompt = $resolver->createToolPrompt($def);
        self::assertStringContainsString('Custom: search-tool', $prompt);
        self::assertStringContainsString('Searches the web', $prompt);
        self::assertStringContainsString('"type": "object"', $prompt);
        self::assertStringContainsString('http', $prompt);
    }

    public function testCreateToolPromptWithDefaultTemplate(): void
    {
        $def = new ToolDefinition();
        $def->setName('my-tool');
        $def->setDescription('A description');
        $def->setSchema(['field' => 'value']);
        $def->setExecutorType(null);

        $resolver = new SubAgentPromptResolver($this->tempDir);
        $prompt = $resolver->createToolPrompt($def);
        self::assertStringContainsString('my-tool', $prompt);
        self::assertStringContainsString('A description', $prompt);
        self::assertStringContainsString('"field": "value"', $prompt);
        self::assertStringContainsString('generic', $prompt);
        self::assertStringContainsString('Executor-Typ', $prompt);
    }

    public function testCreateToolPromptWithoutToolTemplateUsesFallback(): void
    {
        $def = new ToolDefinition();
        $def->setName('test');
        $def->setDescription('desc');

        $resolver = new SubAgentPromptResolver('/nonexistent');
        $prompt = $resolver->createToolPrompt($def);
        self::assertStringContainsString('test', $prompt);
        self::assertStringContainsString('desc', $prompt);
    }
}
