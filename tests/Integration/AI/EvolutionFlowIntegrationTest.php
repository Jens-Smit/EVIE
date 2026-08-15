<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI;

use App\AI\Security\AuditLogger;
use App\AI\Security\HitlListener;
use App\AI\Security\SecurityGuard;
use App\AI\Skills\DynamicToolbox;
use App\Entity\AuditLog;
use App\Entity\ToolDefinition;
use App\Repository\AuditLogRepository;
use App\Repository\ToolDefinitionRepository;
use App\Security\UserContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Integration-Test für den Selbst-Evolution-Flow (Blueprint §5, §7.2).
 *
 * Verifiziert den end-to-end Workflow auf Objektebene (ohne HTTP/Auth-Layer):
 *  1. Eine approved ToolDefinition wird von der DynamicToolbox als native
 *     Tool-Instanz geliefert (§4.B).
 *  2. Der HitlListener lässt approved + sichere Tools zu (§4.D).
 *  3. Eine pending ToolDefinition blockiert die Ausführung via deny() und
 *     löst ein PendingToolApprovalEvent aus (§5 Schritt 5-6).
 *  4. Nach Freigabe (status → approved) wird das Tool vom HitlListener
 *     durchgereicht (§5 Schritt 7-8).
 */
final class EvolutionFlowIntegrationTest extends TestCase
{
    public function testApprovedDefinitionIsExposedByDynamicToolbox(): void
    {
        $definition = (new ToolDefinition())
            ->setName('csv_analyzer')
            ->setDescription('Analysiert CSV-Dateien')
            ->setSchema(['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']])
            ->setStatus('approved')
            ->setExecutorType('filesystem');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findAllApproved')->willReturn([$definition]);

        $inner = $this->createMock(ToolboxInterface::class);
        $inner->method('getTools')->willReturn([]);

        $toolbox = new DynamicToolbox($inner, $repo, $this->createUserContext());
        $tools = $toolbox->getTools();

        self::assertCount(1, $tools);
        self::assertInstanceOf(Tool::class, $tools[0]);
        self::assertSame('csv_analyzer', $tools[0]->getName());
        self::assertSame('App\\AI\\Skills\\Executor\\GenericFileExecutor', $tools[0]->getReference()->getClass());
    }

    public function testPendingDefinitionTriggersHitlBlockadeAndApprovalEvent(): void
    {
        $definition = (new ToolDefinition())
            ->setName('new_tool')
            ->setStatus('pending')
            ->setExecutorType('generic');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneBy')->willReturn($definition);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch');

        $listener = new HitlListener(new SecurityGuard(new NullLogger()), $repo, $dispatcher, $this->createUserContext(), $this->createAuditLogger(), $this->createTokenStorage());
        $event = $this->buildEvent('new_tool', ['input' => 'data']);

        $listener($event);

        self::assertTrue($event->isDenied());
        self::assertSame('pending', $definition->getStatus());
    }

    public function testAfterApprovalHitlListenerAllowsExecution(): void
    {
        $definition = (new ToolDefinition())
            ->setName('approved_tool')
            ->setStatus('approved')
            ->setExecutorType('generic');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneBy')->willReturn($definition);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $listener = new HitlListener(new SecurityGuard(new NullLogger()), $repo, $dispatcher, $this->createUserContext(), $this->createAuditLogger(), $this->createTokenStorage());
        $event = $this->buildEvent('approved_tool', ['input' => 'ok']);

        $listener($event);

        self::assertFalse($event->isDenied());
    }

    public function testFullEvolutionFlowFromPendingToApproved(): void
    {
        $definition = (new ToolDefinition())
            ->setName('excel_parser')
            ->setStatus('pending')
            ->setExecutorType('filesystem');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneBy')->willReturn($definition);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $guard = new SecurityGuard(new NullLogger());
        $listener = new HitlListener($guard, $repo, $dispatcher, $this->createUserContext(), $this->createAuditLogger(), $this->createTokenStorage());

        // Schritt 5: pending → blockiert, Event versandt.
        $event = $this->buildEvent('excel_parser', ['path' => '/tmp/data.xlsx']);
        $listener($event);
        self::assertTrue($event->isDenied());

        // Schritt 7: User-Freigabe simuliert (status → approved).
        $definition->setStatus('approved');

        // Schritt 8: erneuter Aufruf → erlaubt.
        $event2 = $this->buildEvent('excel_parser', ['path' => '/tmp/data.xlsx']);
        $listener($event2);
        self::assertFalse($event2->isDenied());
    }

    public function testSsrfInApprovedToolStillBlockedByGuard(): void
    {
        $definition = (new ToolDefinition())
            ->setName('http_call')
            ->setStatus('approved')
            ->setExecutorType('http');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneBy')->willReturn($definition);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $listener = new HitlListener(new SecurityGuard(new NullLogger()), $repo, $dispatcher, $this->createUserContext(), $this->createAuditLogger(), $this->createTokenStorage());

        $event = $this->buildEvent('http_call', ['url' => 'http://127.0.0.1/admin']);
        $listener($event);

        self::assertTrue($event->isDenied());
        self::assertStringContainsString('blockiert', $event->getDenialReason() ?? '');
    }

    public function testRevokeApprovalReblocksTool(): void
    {
        $definition = (new ToolDefinition())
            ->setName('revoke_tool')
            ->setStatus('approved')
            ->setExecutorType('generic');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneBy')->willReturn($definition);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $listener = new HitlListener(new SecurityGuard(new NullLogger()), $repo, $dispatcher, $this->createUserContext(), $this->createAuditLogger(), $this->createTokenStorage());

        // Zuerst erlaubt (approved)
        $event1 = $this->buildEvent('revoke_tool', []);
        $listener($event1);
        self::assertFalse($event1->isDenied());

        // Revoke simulieren (status → pending)
        $definition->setStatus('pending');
        $dispatcher2 = $this->createMock(EventDispatcherInterface::class);
        $dispatcher2->expects(self::once())->method('dispatch');
        $listener2 = new HitlListener(new SecurityGuard(new NullLogger()), $repo, $dispatcher2, $this->createUserContext(), $this->createAuditLogger(), $this->createTokenStorage());

        $event2 = $this->buildEvent('revoke_tool', []);
        $listener2($event2);
        self::assertTrue($event2->isDenied());
    }

    public function testInvalidExecutorTypeDenied(): void
    {
        $definition = (new ToolDefinition())
            ->setName('shell_tool')
            ->setStatus('approved')
            ->setExecutorType('shell');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneBy')->willReturn($definition);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $listener = new HitlListener(new SecurityGuard(new NullLogger()), $repo, $dispatcher, $this->createUserContext(), $this->createAuditLogger(), $this->createTokenStorage());
        $event = $this->buildEvent('shell_tool', []);

        $listener($event);
        self::assertTrue($event->isDenied());
        self::assertStringContainsString('blockiert', $event->getDenialReason() ?? '');
    }

    public function testSsrfInArgumentsDenied(): void
    {
        $definition = (new ToolDefinition())
            ->setName('api_tool')
            ->setStatus('approved')
            ->setExecutorType('http');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneBy')->willReturn($definition);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $listener = new HitlListener(new SecurityGuard(new NullLogger()), $repo, $dispatcher, $this->createUserContext(), $this->createAuditLogger(), $this->createTokenStorage());
        $event = $this->buildEvent('api_tool', ['url' => 'http://169.254.169.254/meta-data']);

        $listener($event);
        self::assertTrue($event->isDenied());
    }

    public function testBlockedPathInArgumentsDenied(): void
    {
        $definition = (new ToolDefinition())
            ->setName('file_tool')
            ->setStatus('approved')
            ->setExecutorType('filesystem');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneBy')->willReturn($definition);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $listener = new HitlListener(new SecurityGuard(new NullLogger()), $repo, $dispatcher, $this->createUserContext(), $this->createAuditLogger(), $this->createTokenStorage());
        $event = $this->buildEvent('file_tool', ['path' => '/etc/passwd']);

        $listener($event);
        self::assertTrue($event->isDenied());
    }

    public function testAskUserForHighSecurityTool(): void
    {
        $definition = (new ToolDefinition())
            ->setName('sensitive_tool')
            ->setStatus('approved')
            ->setExecutorType('api')
            ->setSecurityLevel('high');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneBy')->willReturn($definition);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch');

        $listener = new HitlListener(new SecurityGuard(new NullLogger()), $repo, $dispatcher, $this->createUserContext(), $this->createAuditLogger(), $this->createTokenStorage());
        $event = $this->buildEvent('sensitive_tool', []);

        $listener($event);
        self::assertTrue($event->isDenied());
        self::assertSame('pending', $definition->getStatus());
    }

    public function testToolVersionPersistedInDefinition(): void
    {
        $definition = (new ToolDefinition())
            ->setName('versioned_tool')
            ->setStatus('approved')
            ->setExecutorType('generic')
            ->setVersion('2.0');

        self::assertSame('2.0', $definition->getVersion());
    }

    public function testInvalidSchemaStillLoadsInToolbox(): void
    {
        // Die Toolbox laedt approved ToolDefinitions ungeachtet des Schemas.
        // P0-2: die Schema-Validierung erfolgt zur Generierungszeit im
        // ToolDefinitionGenerator::validateSchema(); ein Tool mit invalidem
        // Schema wird dort abgelehnt und erreicht nie den approved-Status.
        // Dieser Test verifiziert nur die Toolbox-Layer-Resilienz.
        $definition = (new ToolDefinition())
            ->setName('no_schema_tool')
            ->setStatus('approved')
            ->setExecutorType('generic')
            ->setSchema([]);

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findAllApproved')->willReturn([$definition]);

        $inner = $this->createMock(ToolboxInterface::class);
        $inner->method('getTools')->willReturn([]);

        $toolbox = new DynamicToolbox($inner, $repo, $this->createUserContext());
        $tools = $toolbox->getTools();

        self::assertCount(1, $tools);
        self::assertSame('no_schema_tool', $tools[0]->getName());
    }

    private function buildEvent(string $name, array $arguments): ToolCallRequested
    {
        $toolCall = new ToolCall('call-'.uniqid('', true), $name, $arguments);
        $definition = new Tool(
            new ExecutionReference('App\\AI\\Skills\\Tool\\'.$name),
            $name,
            'Test tool',
            ['type' => 'object', 'properties' => []],
        );

        return new ToolCallRequested($toolCall, $definition);
    }

    private function createUserContext(): UserContext
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new UserContext($requestStack, $this->createTokenStorage());
    }

    private function createAuditLogger(): AuditLogger
    {
        $auditRepo = $this->createMock(AuditLogRepository::class);
        $auditRepo->method('log')->willReturn(new AuditLog());
        $requestStack = new RequestStack();

        return new AuditLogger($auditRepo, $requestStack);
    }

    private function createTokenStorage(): TokenStorageInterface
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        return $tokenStorage;
    }
}
