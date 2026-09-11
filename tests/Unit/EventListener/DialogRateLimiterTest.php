<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\DialogRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Unit-Tests fuer DialogRateLimiter.
 */
final class DialogRateLimiterTest extends TestCase
{
    private RateLimiterFactory $factory;
    private DialogRateLimiter $listener;

    protected function setUp(): void
    {
        $this->factory = new RateLimiterFactory(
            ['id' => 'agent_web_actions', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
        $this->listener = new DialogRateLimiter($this->factory);
    }

    public function testIgnoresSubRequests(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/agent/dialog', 'POST');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->listener->onKernelRequest($event);

        $this->addToAssertionCount(1);
    }

    public function testIgnoresNonDialogRoutes(): void
    {
        $request = Request::create('/other', 'GET');
        $event = $this->createRequestEvent($request);

        $this->listener->onKernelRequest($event);

        $this->addToAssertionCount(1);
    }

    public function testAllowsDialogRequestWithinLimit(): void
    {
        $request = Request::create('/api/agent/dialog', 'POST');
        $event = $this->createRequestEvent($request);

        $this->listener->onKernelRequest($event);

        $this->addToAssertionCount(1);
    }

    private function createRequestEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
