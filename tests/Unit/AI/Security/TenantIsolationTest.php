<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\HitlListener;
use App\AI\Security\SecurityGuard;
use App\Entity\ToolDefinition;
use App\Repository\AuditLogRepository;
use App\Repository\ToolDefinitionRepository;
use App\Security\UserContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use App\Entity\AuditLog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tenant-Isolation-Tests (P0-5).
 *
 * Beweist, dass ein User nur seine eigenen ToolDefinitions sieht und
 * freigeben kann — die DynamicToolbox und der HitlListener filtern
 * serverseitig nach user_identifier, sodass Tenant A niemals die Tools
 * von Tenant B erhält.
 */
final class TenantIsolationTest extends TestCase
{
    public function testHitlListenerFindsOnlyTenantScopedDefinition(): void
    {
        // Tenant A hat ein Tool "shared_api" (approved), Tenant B ebenfalls.
        $definitionA = (new ToolDefinition())
            ->setName('shared_api')
            ->setStatus('approved')
            ->setExecutorType('api')
            ->setUserIdentifier('tenant-a');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        // Der Listener muss findOneByNameForUser aufrufen, sobald ein User
        // eingeloggt ist — der Aufruf mit tenant-a liefert die Definition A.
        $repo->expects(self::once())
            ->method('findOneByNameForUser')
            ->with('shared_api', 'tenant-a')
            ->willReturn($definitionA);

        $listener = $this->buildListener($repo, 'tenant-a');
        $event = $this->buildEvent('shared_api', ['query' => 'test']);

        $listener($event);

        // Approved + sicher → nicht denied
        self::assertFalse($event->isDenied());
    }

    public function testHitlListenerDoesNotSeeOtherTenantsPendingTool(): void
    {
        // Tenant B hat ein pending Tool; Tenant A fragt ein Tool gleichen Namens ab.
        // Erwartung: Tenant A sieht NICHT die Definition von Tenant B.
        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->expects(self::once())
            ->method('findOneByNameForUser')
            ->with('secret_tool', 'tenant-a')
            ->willReturn(null); // Tenant A hat kein solches Tool

        $listener = $this->buildListener($repo, 'tenant-a');
        $event = $this->buildEvent('secret_tool', ['query' => 'x']);

        $listener($event);

        // Kein Tool für Tenant A → AskUser-Pfad ohne Definition → DENY
        self::assertTrue($event->isDenied());
        self::assertStringContainsString('nicht registriert', $event->getDenialReason() ?? '');
    }

    public function testHitlListenerCannotApproveOtherTenantsTool(): void
    {
        // Selbst wenn ein Tool "evil_tool" für Tenant B pending ist, darf
        // Tenant A es nicht freigeben/ablehnen — findOneByNameForUser mit
        // tenant-a liefert null.
        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneByNameForUser')
            ->with('evil_tool', 'tenant-a')
            ->willReturn(null);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        // Kein Approval-Event für Tenant A, da das Tool nicht für ihn existiert.
        $dispatcher->expects(self::never())->method('dispatch');

        $listener = $this->buildListener($repo, 'tenant-a', $dispatcher);
        $event = $this->buildEvent('evil_tool', []);

        $listener($event);
    }

    private function buildListener(ToolDefinitionRepository $repo, string $userIdentifier, ?EventDispatcherInterface $dispatcher = null): HitlListener
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $request->attributes->set('_evie_user_identifier', $userIdentifier);
        $requestStack->push($request);

        $userContext = new UserContext($requestStack);

        $auditRepo = $this->createMock(AuditLogRepository::class);
        $auditRepo->method('log')->willReturn(new AuditLog());
        $auditLogger = new \App\AI\Security\AuditLogger($auditRepo, $requestStack);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        return new HitlListener(
            new SecurityGuard(new NullLogger()),
            $repo,
            $dispatcher ?? $this->createMock(EventDispatcherInterface::class),
            $userContext,
            $auditLogger,
            $tokenStorage,
        );
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
