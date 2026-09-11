<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\AI\Security\AuditLogger;
use App\AI\Security\SecurityGuard;
use App\Entity\AuditLog;
use App\Entity\User;
use App\EventListener\ApiSecurityListener;
use App\Repository\AuditLogRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Unit-Tests fuer ApiSecurityListener — deckt die geschuetzten API-Routen
 * (/api/tools, /api/admin, /api/hitl), den Allow-Pfad mit authentifiziertem
 * User inkl. Audit-Log und den Deny-Pfad ohne Authentifizierung ab.
 */
final class ApiSecurityListenerTest extends TestCase
{
    private function buildEvent(string $path, ?callable $controller = null, ?Request $request = null): ControllerEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request ??= Request::create($path);
        $controller ??= static fn () => null;

        return new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function buildAuditLogger(): AuditLogger
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $repo->method('log')->willReturn(new AuditLog());
        $stack = new \Symfony\Component\HttpFoundation\RequestStack();
        $stack->push(new Request());

        return new AuditLogger($repo, $stack);
    }

    private function buildListener(SecurityGuard $guard, ?TokenStorageInterface $tokenStorage = null, ?AuditLogger $auditLogger = null): ApiSecurityListener
    {
        $tokenStorage ??= $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $auditLogger ??= $this->buildAuditLogger();

        return new ApiSecurityListener($guard, $auditLogger, $tokenStorage);
    }

    public function testIgnoresNonProtectedRoute(): void
    {
        $guard = $this->createMock(SecurityGuard::class);
        $listener = $this->buildListener($guard);

        $event = $this->buildEvent('/public/page');
        $listener->onKernelController($event);

        $this->addToAssertionCount(1);
    }

    public function testDeniesProtectedRouteWithoutAuthentication(): void
    {
        $guard = $this->createMock(SecurityGuard::class);
        $listener = $this->buildListener($guard);

        $event = $this->buildEvent('/api/tools/execute');

        $this->expectException(AccessDeniedHttpException::class);
        $listener->onKernelController($event);
    }

    public function testAllowsAndAuditsProtectedRouteWithAuthenticatedUser(): void
    {
        $guard = $this->createMock(SecurityGuard::class);
        $repo = $this->createMock(AuditLogRepository::class);
        $repo->expects(self::once())->method('log')->willReturn(new AuditLog());
        $stack = new \Symfony\Component\HttpFoundation\RequestStack();
        $stack->push(new Request());
        $auditLogger = new AuditLogger($repo, $stack);

        $user = (new User())->setEmail('u@test.de');
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $listener = new ApiSecurityListener($guard, $auditLogger, $tokenStorage);

        $request = Request::create('/api/admin/stats');
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new ControllerEvent($kernel, static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST);

        $listener->onKernelController($event);

        $this->addToAssertionCount(1);
    }

    public function testDeniesHitlRouteWithoutAuthentication(): void
    {
        $guard = $this->createMock(SecurityGuard::class);
        $listener = $this->buildListener($guard);

        $event = $this->buildEvent('/api/hitl/approve');

        $this->expectException(AccessDeniedHttpException::class);
        $listener->onKernelController($event);
    }
}
