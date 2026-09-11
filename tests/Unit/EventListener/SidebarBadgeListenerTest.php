<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\SidebarBadgeListener;
use App\Repository\AgentHistoryRepository;
use App\Repository\ToolDefinitionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;

/**
 * Unit-Tests fuer SidebarBadgeListener.
 */
final class SidebarBadgeListenerTest extends TestCase
{
    private ToolDefinitionRepository&MockObject $toolRepo;
    private AgentHistoryRepository&MockObject $historyRepo;
    private Environment&MockObject $twig;
    private SidebarBadgeListener $listener;

    protected function setUp(): void
    {
        $this->toolRepo = $this->createMock(ToolDefinitionRepository::class);
        $this->historyRepo = $this->createMock(AgentHistoryRepository::class);
        $this->twig = $this->createMock(Environment::class);
        $this->listener = new SidebarBadgeListener($this->toolRepo, $this->historyRepo, $this->twig);
    }

    public function testSetsGlobalsOnMainHtmlRequest(): void
    {
        $this->toolRepo->method('count')->willReturn(3);
        $this->twig->expects(self::exactly(2))->method('addGlobal');

        $request = Request::create('/dashboard');
        $event = $this->createEvent($request, true);

        $this->listener->onKernelController($event);
    }

    public function testIgnoresAjaxRequests(): void
    {
        $this->twig->expects(self::never())->method('addGlobal');

        $request = Request::create('/dashboard');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $event = $this->createEvent($request, true);

        $this->listener->onKernelController($event);
    }

    public function testIgnoresSubRequests(): void
    {
        $this->twig->expects(self::never())->method('addGlobal');

        $request = Request::create('/dashboard');
        $event = $this->createEvent($request, false);

        $this->listener->onKernelController($event);
    }

    public function testFallsBackToZeroOnException(): void
    {
        $this->toolRepo->method('count')->willThrowException(new \RuntimeException('db error'));
        $this->twig->expects(self::exactly(2))
            ->method('addGlobal')
            ->willReturnCallback(function (string $name, mixed $value): Environment {
                self::assertSame(0, $value);

                return $this->twig;
            });

        $request = Request::create('/dashboard');
        $event = $this->createEvent($request, true);

        $this->listener->onKernelController($event);
    }

    private function createEvent(Request $request, bool $main): ControllerEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $controller = static fn () => new \Symfony\Component\HttpFoundation\Response();

        return new ControllerEvent(
            $kernel,
            $controller,
            $request,
            $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
    }
}
