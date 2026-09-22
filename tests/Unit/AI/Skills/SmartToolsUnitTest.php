<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills;

use App\AI\Loader\OnboardingLoader;
use App\AI\Security\SecurityGuard;
use App\AI\Skills\Executor\ExecutorResolver;
use App\AI\Skills\Executor\GenericApiExecutor;
use App\AI\Skills\Executor\GenericExecutor;
use App\AI\Skills\Executor\GenericFileExecutor;
use App\AI\Skills\Tool\DataAnalyzerTool;
use App\AI\Skills\Tool\DynamicTool;
use App\AI\Skills\Tool\ExcelParserTool;
use App\AI\Skills\Tool\FileReadTool;
use App\AI\Skills\Tool\SubAgentRegisterTool;
use App\AI\Skills\Tool\WeatherTool;
use App\AI\Skills\ToolRegistrationException;
use App\AI\Skills\ToolRegistrationResult;
use App\Entity\SubAgentDefinition;
use App\Repository\SubAgentDefinitionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer die bislang ungetesteten Smart-Tools und Executoren
 * (Coverage-Luecke: 0-13 % je Klasse). Alle Tests sind deterministisch
 * und ohne externe Dienste lauffaehig.
 */
final class SmartToolsUnitTest extends TestCase
{
    public function testWeatherToolReturnsDefaultCity(): void
    {
        $result = (new WeatherTool())();

        self::assertSame('Sonnig', $result['weather']);
        self::assertSame(22, $result['temperature']);
        self::assertSame('Berlin', $result['city']);
    }

    public function testWeatherToolUsesGivenCity(): void
    {
        $result = (new WeatherTool())(['city' => 'Hamburg']);

        self::assertSame('Hamburg', $result['city']);
    }

    public function testExcelParserToolReturnsParsedConfirmation(): void
    {
        $result = (new ExcelParserTool())('/tmp/beispiel.xlsx');

        self::assertStringContainsString('/tmp/beispiel.xlsx', $result);
        self::assertStringContainsString('erfolgreich geparsed', $result);
    }

    public function testDataAnalyzerToolSummarizesData(): void
    {
        $result = (new DataAnalyzerTool())([
            ['name' => 'A', 'wert' => 1],
            ['name' => 'B', 'wert' => 2],
        ]);

        $decoded = json_decode($result, true);
        self::assertSame(2, $decoded['count']);
        self::assertSame(['name', 'wert'], $decoded['keys']);
    }

    public function testDataAnalyzerToolHandlesEmptyData(): void
    {
        $decoded = json_decode((new DataAnalyzerTool())([]), true);

        self::assertSame(0, $decoded['count']);
        self::assertSame([], $decoded['keys']);
    }

    public function testFileReadToolReadsFileFromSandbox(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'evie-file-read-');
        file_put_contents($path, 'dateiinhalt');

        $result = (new FileReadTool())(['path' => $path]);

        unlink($path);
        self::assertSame($path, $result['path']);
        self::assertSame('dateiinhalt', $result['content']);
    }

    public function testFileReadToolRejectsMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        (new FileReadTool())(['path' => '/existiert/nicht.txt']);
    }

    public function testFileReadToolDelegatesToSecurityGuard(): void
    {
        $guard = $this->createMock(SecurityGuard::class);
        $guard->method('isPathSafe')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ausserhalb');
        (new FileReadTool($guard))(['path' => '/etc/passwd']);
    }

    public function testSubAgentRegisterToolPersistsNewDefinition(): void
    {
        $captured = [];
        $repo = $this->createMockSubAgentRepo(null, $captured);

        $result = (new SubAgentRegisterTool($repo))([
            'name' => 'marketing',
            'description' => 'Marketing-Agent',
            'role' => 'marketing_manager',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('marketing', $result['sub_agent_name']);
        self::assertCount(1, $captured);
        $definition = $captured[0];
        self::assertInstanceOf(SubAgentDefinition::class, $definition);
        self::assertSame('marketing', $definition->getName());
        self::assertSame('Marketing-Agent', $definition->getDescription());
        self::assertTrue($definition->isActive());
        self::assertSame('mistral-large-latest', $definition->getConfiguration()['model']);
        self::assertSame('marketing_manager', $definition->getConfiguration()['role']);
    }

    public function testSubAgentRegisterToolReportsExistingAgent(): void
    {
        $existing = new SubAgentDefinition();
        $existing->setName('vertrieb');
        $repo = $this->createMock(SubAgentDefinitionRepository::class);
        $repo->method('findOneByName')->willReturn($existing);

        $result = (new SubAgentRegisterTool($repo))([
            'name' => 'vertrieb',
            'description' => 'Vertrieb',
        ]);

        self::assertSame('exists', $result['status']);
        self::assertSame('vertrieb', $result['sub_agent_name']);
    }

    public function testSubAgentRegisterToolRequiresNameAndDescription(): void
    {
        $repo = $this->createMock(SubAgentDefinitionRepository::class);

        $this->expectException(\RuntimeException::class);
        (new SubAgentRegisterTool($repo))(['name' => '']);
    }

    public function testOnboardingLoaderYieldsWelcomeDocuments(): void
    {
        $documents = iterator_to_array((new OnboardingLoader())->load(), false);

        self::assertCount(2, $documents);
        self::assertSame('Willkommen bei EVIE.', $documents[0]->getContent());
        self::assertSame('Der Benutzer kann sein Unternehmen anlegen.', $documents[1]->getContent());
    }

    public function testGenericExecutorReturnsFallbackResult(): void
    {
        $tool = new DynamicTool('fallback_tool');
        $result = (new GenericExecutor())->execute($tool, ['x' => 1]);

        self::assertSame('warning', $result['status']);
        self::assertSame('fallback_tool', $result['tool']);
        self::assertSame(['x' => 1], $result['parameters']);
        self::assertSame('generic', (new GenericExecutor())->getType());
    }

    public function testGenericApiExecutorRequiresUrl(): void
    {
        $tool = new DynamicTool('api_tool', null, [], 'api', []);

        $this->expectException(\RuntimeException::class);
        (new GenericApiExecutor())->execute($tool, []);
    }

    public function testGenericApiExecutorSimulatesCall(): void
    {
        $tool = new DynamicTool('api_tool', null, [], 'api', [
            'url' => 'https://api.example.com/v1',
            'method' => 'POST',
        ]);

        $result = (new GenericApiExecutor())->execute($tool, ['q' => 'test']);

        self::assertSame('success', $result['status']);
        self::assertSame('https://api.example.com/v1', $result['url']);
        self::assertSame('POST', $result['method']);
        self::assertSame(['q' => 'test'], $result['data']);
        self::assertSame('api', (new GenericApiExecutor())->getType());
    }

    public function testExecutorResolverResolvesFallbackForUnknownType(): void
    {
        $resolver = $this->buildResolver();

        self::assertFalse($resolver->supports('unbekannt'));

        $fallback = $resolver->resolve('unbekannt');
        self::assertInstanceOf(GenericExecutor::class, $fallback);
        self::assertSame('generic', $fallback->getType());
    }

    public function testExecutorResolverResolvesAllBuiltinTypes(): void
    {
        $resolver = $this->buildResolver();

        foreach (['api', 'database', 'filesystem', 'http'] as $type) {
            self::assertTrue($resolver->supports($type), "Executor {$type} muss unterstuetzt werden.");
            self::assertSame($type, $resolver->resolve($type)->getType());
        }
    }

    public function testExecutorResolverAddExecutorOverridesType(): void
    {
        $resolver = $this->buildResolver();
        $custom = new GenericExecutor();
        $resolver->addExecutor('custom', $custom);

        self::assertTrue($resolver->supports('custom'));
        self::assertSame($custom, $resolver->resolve('custom'));
    }

    private function buildResolver(): ExecutorResolver
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $httpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);

        return new ExecutorResolver(new NullLogger(), $connection, $httpClient, new SecurityGuard(new NullLogger()));
    }

    public function testGenericFileExecutorRequiresPath(): void
    {
        $tool = new DynamicTool('file_tool', null, [], 'filesystem', ['action' => 'read']);

        $this->expectException(\RuntimeException::class);
        (new GenericFileExecutor())->execute($tool, []);
    }

    public function testGenericFileExecutorReadsFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'evie-exec-');
        file_put_contents($path, 'inhalt-exec');

        $tool = new DynamicTool('file_tool', null, [], 'filesystem', ['action' => 'read']);
        $result = (new GenericFileExecutor())->execute($tool, ['path' => $path]);

        unlink($path);
        self::assertSame('inhalt-exec', $result);
        self::assertSame('filesystem', (new GenericFileExecutor())->getType());
    }

    public function testToolRegistrationResultSuccessAndFailure(): void
    {
        $tool = new DynamicTool('tool_x');
        $definition = new \App\Entity\ToolDefinition();

        $success = ToolRegistrationResult::success($tool, $definition);
        self::assertTrue($success->isSuccess());
        self::assertSame($tool, $success->getTool());
        self::assertSame($definition, $success->getDefinition());
        self::assertNull($success->getErrorMessage());
        self::assertNull($success->getErrorDetails());

        $failure = ToolRegistrationResult::failure('Schema ungueltig', ['zeile' => 3]);
        self::assertFalse($failure->isSuccess());
        self::assertNull($failure->getTool());
        self::assertSame('Schema ungueltig', $failure->getErrorMessage());
        self::assertSame(['zeile' => 3], $failure->getErrorDetails());
    }

    public function testToolRegistrationExceptionCarriesDefinitionAndContext(): void
    {
        $definition = new \App\Entity\ToolDefinition();
        $exception = new ToolRegistrationException('Fehler', $definition, ['ctx' => 1]);

        self::assertSame($definition, $exception->getDefinition());
        self::assertSame(['ctx' => 1], $exception->getContext());
    }

    private function createMockSubAgentRepo(?SubAgentDefinition $existing, array &$captured): SubAgentDefinitionRepository&MockObject
    {
        $repo = $this->createMock(SubAgentDefinitionRepository::class);
        $repo->method('findOneByName')->willReturnCallback(
            function (string $name) use ($existing) {
                return null !== $existing && $existing->getName() === $name ? $existing : null;
            }
        );
        $repo->method('save')->willReturnCallback(
            function (SubAgentDefinition $entity, bool $flush = false) use (&$captured) {
                $captured[] = $entity;

                return null;
            }
        );

        return $repo;
    }
}
