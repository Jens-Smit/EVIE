<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\AI\Security\AuditLogger;
use App\AI\Security\SecurityGuard;
use App\AI\Skills\Tool\DynamicTool;
use App\Entity\AuditLog;
use App\EventListener\ToolSecurityListener;
use App\Repository\AuditLogRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit-Tests fuer ToolSecurityListener — deckt den Allow-Pfad (kein
 * DynamicTool bzw. sicheres Tool) und den Deny-Pfad (unsicherer Executor-Typ
 * mit Audit-Log und BadRequestHttpException) ab.
 */
final class ToolSecurityListenerTest extends TestCase
{
    private function buildEvent(mixed $controllerResult, ?Request $request = null): ViewEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request ??= new Request();

        return new ViewEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $controllerResult);
    }

    private function buildAuditLogger(): AuditLogger
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $repo->method('log')->willReturn(new AuditLog());
        $stack = new \Symfony\Component\HttpFoundation\RequestStack();
        $stack->push(new Request());

        return new AuditLogger($repo, $stack);
    }

    public function testIgnoresNonDynamicToolControllerResult(): void
    {
        $guard = $this->createMock(SecurityGuard::class);
        $guard->expects(self::never())->method('isToolSafe');
        $listener = new ToolSecurityListener($guard, $this->buildAuditLogger());

        $event = $this->buildEvent(new \stdClass());
        $listener->onKernelView($event);

        self::assertNull($event->getResponse());
    }

    public function testAllowsSafeDynamicTool(): void
    {
        $tool = new DynamicTool('safe_tool', 'desc', [], 'generic', [], ['allowed' => true]);
        $guard = $this->createMock(SecurityGuard::class);
        $guard->method('isToolSafe')->with($tool)->willReturn(true);
        $listener = new ToolSecurityListener($guard, $this->buildAuditLogger());

        $event = $this->buildEvent($tool);
        $listener->onKernelView($event);

        self::assertNull($event->getResponse());
    }

    public function testThrowsAndAuditsForUnsafeDynamicTool(): void
    {
        $tool = new DynamicTool('bad_tool', 'desc', [], 'shell', [], ['allowed' => false]);
        $guard = $this->createMock(SecurityGuard::class);
        $guard->method('isToolSafe')->with($tool)->willReturn(false);
        $repo = $this->createMock(AuditLogRepository::class);
        $repo->expects(self::once())->method('log')->willReturn(new AuditLog());
        $stack = new \Symfony\Component\HttpFoundation\RequestStack();
        $stack->push(new Request());
        $auditLogger = new AuditLogger($repo, $stack);
        $listener = new ToolSecurityListener($guard, $auditLogger);

        $event = $this->buildEvent($tool);

        $this->expectException(BadRequestHttpException::class);
        $listener->onKernelView($event);
    }
}
