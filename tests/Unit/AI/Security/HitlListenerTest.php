<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\AuditLogger;
use App\AI\Security\HitlListener;
use App\AI\Security\PolicyDecision;
use App\AI\Security\SecurityGuard;
use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use App\Repository\AuditLogRepository;
use App\Repository\ToolDefinitionRepository;
use App\Security\UserContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit-Tests fuer den nativen HitlListener (Blueprint §4.D).
 *
 * Verifiziert das Verhalten auf das native ToolCallRequested-Event:
 *  - Allow: Listener greift nicht ein (Event nicht denied).
 *  - Deny: $event->deny() wird aufgerufen (SSRF, Policy-Verstoss).
 *  - AskUser: ToolDefinition auf "pending", PendingToolApprovalEvent versandt,
 *    $event->deny() blockiert die Ausfuehrung.
 */
final class HitlListenerTest extends TestCase
{
    private SecurityGuard $guard;
    private ToolDefinitionRepository $repo;
    private EventDispatcherInterface $dispatcher;
    private HitlListener $listener;

    protected function setUp(): void
    {
        $this->guard = new SecurityGuard(new NullLogger());
        $this->repo = $this->createMock(ToolDefinitionRepository::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);

        $requestStack = new RequestStack();
        $request = new Request();
        $requestStack->push($request);
        $userContext = new UserContext($requestStack);
        $userContext->setUserIdentifier('test-tenant');

        $auditRepo = $this->createMock(AuditLogRepository::class);
        $auditRepo->method('log')->willReturn(new \App\Entity\AuditLog());
        $auditLogger = new AuditLogger($auditRepo, $requestStack);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $this->listener = new HitlListener(
            $this->guard,
            $this->repo,
            $this->dispatcher,
            $userContext,
            $auditLogger,
            $tokenStorage,
        );
    }

    public function testAllowDoesNotDeny(): void
    {
        $event = $this->buildEvent('weather', ['city' => 'Berlin']);
        $this->repo->method('findOneByNameForUser')->willReturn(null);

        ($this->listener)($event);

        self::assertFalse($event->isDenied());
    }

    public function testDeniesOnSsrfPrivateIp(): void
    {
        $event = $this->buildEvent('http_call', ['url' => 'http://127.0.0.1/admin']);
        $this->repo->method('findOneByNameForUser')->willReturn(null);

        ($this->listener)($event);

        self::assertTrue($event->isDenied());
        self::assertStringContainsString('blockiert', $event->getDenialReason() ?? '');
    }

    public function testDeniesOnBlockedPath(): void
    {
        $event = $this->buildEvent('file_read', ['path' => '/etc/passwd']);
        $this->repo->method('findOneByNameForUser')->willReturn(null);

        ($this->listener)($event);

        self::assertTrue($event->isDenied());
    }

    public function testPendingDefinitionTriggersApprovalAndDenies(): void
    {
        $definition = (new ToolDefinition())
            ->setName('new_tool')
            ->setStatus('pending')
            ->setExecutorType('generic');

        $this->repo->method('findOneByNameForUser')->willReturn($definition);
        $this->dispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (PendingToolApprovalEvent $event): PendingToolApprovalEvent {
                self::assertSame('pending', $event->getToolDefinition()->getStatus());

                return $event;
            });

        $event = $this->buildEvent('new_tool', ['input' => 'data']);

        ($this->listener)($event);

        self::assertTrue($event->isDenied());
        self::assertStringContainsString('wartet auf Freigabe', $event->getDenialReason() ?? '');
    }

    public function testApprovedDefinitionWithHighSecurityLevelRequestsApproval(): void
    {
        $definition = (new ToolDefinition())
            ->setName('sensitive_tool')
            ->setStatus('approved')
            ->setExecutorType('api')
            ->setSecurityLevel('high');

        $this->repo->method('findOneByNameForUser')->willReturn($definition);
        $this->dispatcher->expects(self::once())->method('dispatch');

        $event = $this->buildEvent('sensitive_tool', ['query' => 'x']);

        ($this->listener)($event);

        self::assertTrue($event->isDenied());
        self::assertSame('pending', $definition->getStatus());
    }

    public function testApprovedDefinitionPassesThrough(): void
    {
        $definition = (new ToolDefinition())
            ->setName('safe_tool')
            ->setStatus('approved')
            ->setExecutorType('generic');

        $this->repo->method('findOneByNameForUser')->willReturn($definition);
        $this->dispatcher->expects(self::never())->method('dispatch');

        $event = $this->buildEvent('safe_tool', ['input' => 'ok']);

        ($this->listener)($event);

        self::assertFalse($event->isDenied());
    }

    public function testDeniesUnknownExecutorType(): void
    {
        $definition = (new ToolDefinition())
            ->setName('bad_tool')
            ->setStatus('approved')
            ->setExecutorType('shell');

        $this->repo->method('findOneByNameForUser')->willReturn($definition);

        $event = $this->buildEvent('bad_tool', []);

        ($this->listener)($event);

        self::assertTrue($event->isDenied());
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
}
