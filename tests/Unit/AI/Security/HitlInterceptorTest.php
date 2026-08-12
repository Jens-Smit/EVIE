<?php

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\HitlInterceptor;
use App\AI\Security\SecurityGuard;
use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit-Tests für HitlInterceptor
 * 
 * Testet:
 * - Tool-Genehmigungsprüfung (HITL)
 * - SecurityGuard-Integration
 * - Exception-Handling
 * - Status-Rückgabe
 */
final class HitlInterceptorTest extends TestCase
{
    private HitlInterceptor $interceptor;
    private EventDispatcherInterface $eventDispatcher;
    private SecurityGuard $securityGuard;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        
        $params = new ParameterBag([
            'evie.security.allowed_services' => [
                'App\AI\Skills\Tool\GenericApiExecutor',
                'App\AI\Skills\Tool\*',
            ],
            'evie.security.blocked_patterns' => [
                'localhost',
                '/etc/',
            ],
        ]);
        $this->securityGuard = new SecurityGuard($params);

        $this->interceptor = new HitlInterceptor(
            $this->eventDispatcher,
            $this->securityGuard
        );
    }

    // ========================================================================
    // Helper-Methoden
    // ========================================================================

    /**
     * Erstellt eine ToolDefinition für Tests.
     */
    private function createToolDefinition(
        string $name = 'test_tool',
        string $status = 'approved',
        array $schema = []
    ): ToolDefinition {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($name);
        $toolDefinition->setDescription('Test Tool Description');
        $toolDefinition->setStatus($status);
        $toolDefinition->setSchema($schema);
        $toolDefinition->setParameters([
            ['name' => 'input', 'type' => 'string', 'required' => true],
        ]);

        return $toolDefinition;
    }

    /**
     * Erstellt ein Mock-Tool mit getDefinition()-Methode.
     */
    private function createMockToolWithDefinition(ToolDefinition $toolDefinition): object
    {
        return new class($toolDefinition) {
            private ToolDefinition $definition;

            public function __construct(ToolDefinition $definition)
            {
                $this->definition = $definition;
            }

            public function getDefinition(): ToolDefinition
            {
                return $this->definition;
            }
        };
    }

    // ========================================================================
    // Tests für interceptToolExecution() mit genehmigten Tools
    // ========================================================================

    public function testInterceptToolExecutionWithApprovedTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'approved_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            'Test prompt',
            'user_123'
        );

        $this->assertEquals('approved', $result['status']);
        $this->assertEquals('approved_tool', $result['tool']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testInterceptToolExecutionWithApprovedToolAndValidSchema(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'website_scraper',
            'approved',
            [
                'service' => 'App\AI\Skills\Tool\GenericApiExecutor',
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string'],
                ],
            ]
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            'Scrape website',
            'user_123'
        );

        $this->assertEquals('approved', $result['status']);
        $this->assertEquals('website_scraper', $result['tool']);
    }

    // ========================================================================
    // Tests für interceptToolExecution() mit nicht genehmigten Tools
    // ========================================================================

    public function testInterceptToolExecutionWithPendingTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'pending_tool',
            'pending',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        // Erwarte, dass ein Event dispatched wird
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($toolDefinition) {
                return $event instanceof PendingToolApprovalEvent &&
                       $event->getToolDefinition() === $toolDefinition;
            }));

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            'Test prompt',
            'user_123'
        );

        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('Tool not approved', $result['reason']);
        $this->assertEquals('pending_tool', $result['tool']);
        $this->assertEquals('pending_approval', $result['action']);
    }

    public function testInterceptToolExecutionWithPendingApprovalTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'pending_approval_tool',
            'pending_approval',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PendingToolApprovalEvent::class));

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            'Test prompt',
            'user_123'
        );

        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('pending_approval', $result['action']);
    }

    // ========================================================================
    // Tests für interceptToolExecution() mit SecurityGuard-Blockierung
    // ========================================================================

    public function testInterceptToolExecutionWithBlockedService(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'blocked_service_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\DangerousExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            'Test prompt',
            'user_123'
        );

        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('Tool not allowed by SecurityGuard', $result['reason']);
        $this->assertEquals('blocked_service_tool', $result['tool']);
        $this->assertEquals('security_violation', $result['action']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testInterceptToolExecutionWithBlockedResource(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'blocked_resource_tool',
            'approved',
            [
                'service' => 'App\AI\Skills\Tool\GenericApiExecutor',
                'resource' => '/etc/passwd',
            ]
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            'Test prompt',
            'user_123'
        );

        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('Tool not allowed by SecurityGuard', $result['reason']);
        $this->assertEquals('security_violation', $result['action']);
    }

    public function testInterceptToolExecutionWithBlockedUrl(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'blocked_url_tool',
            'approved',
            [
                'service' => 'App\AI\Skills\Tool\GenericApiExecutor',
                'url' => 'http://localhost/api',
            ]
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            'Test prompt',
            'user_123'
        );

        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('Tool not allowed by SecurityGuard', $result['reason']);
    }

    // ========================================================================
    // Tests für isToolSafe()
    // ========================================================================

    public function testIsToolSafeWithApprovedAndAllowedTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'safe_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $this->assertTrue(
            $this->interceptor->isToolSafe($tool, 'Test prompt', 'user_123')
        );
    }

    public function testIsToolSafeWithPendingTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'pending_tool',
            'pending',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $this->assertFalse(
            $this->interceptor->isToolSafe($tool, 'Test prompt', 'user_123')
        );
    }

    public function testIsToolSafeWithBlockedService(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'blocked_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\DangerousExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $this->assertFalse(
            $this->interceptor->isToolSafe($tool, 'Test prompt', 'user_123')
        );
    }

    // ========================================================================
    // Tests für getToolStatus()
    // ========================================================================

    public function testGetToolStatusWithApprovedTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'approved_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $this->assertEquals(
            'approved',
            $this->interceptor->getToolStatus($tool, 'Test prompt', 'user_123')
        );
    }

    public function testGetToolStatusWithPendingTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'pending_tool',
            'pending',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $this->assertEquals(
            'blocked',
            $this->interceptor->getToolStatus($tool, 'Test prompt', 'user_123')
        );
    }

    public function testGetToolStatusWithBlockedService(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'blocked_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\DangerousExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $this->assertEquals(
            'blocked',
            $this->interceptor->getToolStatus($tool, 'Test prompt', 'user_123')
        );
    }

    // ========================================================================
    // Tests für getBlockReason()
    // ========================================================================

    public function testGetBlockReasonWithApprovedTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'approved_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $this->assertNull(
            $this->interceptor->getBlockReason($tool, 'Test prompt', 'user_123')
        );
    }

    public function testGetBlockReasonWithPendingTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'pending_tool',
            'pending',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $this->assertEquals(
            'Tool not approved',
            $this->interceptor->getBlockReason($tool, 'Test prompt', 'user_123')
        );
    }

    public function testGetBlockReasonWithBlockedService(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'blocked_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\DangerousExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $this->assertEquals(
            'Tool not allowed by SecurityGuard',
            $this->interceptor->getBlockReason($tool, 'Test prompt', 'user_123')
        );
    }

    // ========================================================================
    // Tests für ToolDefinition-Extraktion
    // ========================================================================

    public function testGetToolDefinitionFromToolDefinitionDirectly(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'direct_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        // ToolDefinition sollte direkt funktionieren
        $result = $this->interceptor->interceptToolExecution(
            $toolDefinition,
            'Test',
            'user_123'
        );

        $this->assertIsArray($result);
        $this->assertEquals('approved', $result['status']);
    }

    public function testGetToolDefinitionWithInvalidTool(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not have a valid ToolDefinition');

        // Erstelle ein Tool ohne gültige Definition
        $invalidTool = new \stdClass();

        $this->interceptor->interceptToolExecution(
            $invalidTool,
            'Test',
            'user_123'
        );
    }

    // ========================================================================
    // Tests für Tool mit toolDefinition-Property
    // ========================================================================

    public function testGetToolDefinitionFromObjectWithToolDefinitionProperty(): void
    {
        $toolDefinition = $this->createToolDefinition();
        
        // Erstelle ein Tool mit toolDefinition-Property
        $mockTool = new class($toolDefinition) {
            public ToolDefinition $toolDefinition;

            public function __construct(ToolDefinition $toolDefinition)
            {
                $this->toolDefinition = $toolDefinition;
            }
        };

        $result = $this->interceptor->interceptToolExecution(
            $mockTool,
            'Test',
            'user_123'
        );

        $this->assertIsArray($result);
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function testInterceptToolExecutionWithEmptyPrompt(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'test_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            '',
            'user_123'
        );

        $this->assertEquals('approved', $result['status']);
    }

    public function testInterceptToolExecutionWithEmptyUserIdentifier(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'test_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            'Test prompt',
            ''
        );

        $this->assertEquals('approved', $result['status']);
    }

    // ========================================================================
    // Tests für SecurityGuard-Integration
    // ========================================================================

    public function testSecurityGuardBlocksDangerousService(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'dangerous_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\DangerousExecutor']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            'Test prompt',
            'user_123'
        );

        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('security_violation', $result['action']);
        $this->assertStringContainsString('not allowed by SecurityGuard', $result['reason']);
    }

    public function testSecurityGuardAllowsWildcardService(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'wildcard_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\CustomTool']
        );

        $tool = $this->createMockToolWithDefinition($toolDefinition);

        $result = $this->interceptor->interceptToolExecution(
            $tool,
            'Test prompt',
            'user_123'
        );

        $this->assertEquals('approved', $result['status']);
    }
}
