<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\ToolExecutionResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer ToolExecutionResult.
 */
final class ToolExecutionResultTest extends TestCase
{
    public function testSuccessResultGetters(): void
    {
        $result = new ToolExecutionResult('weather', true, null, ['temp' => 20]);

        self::assertSame('weather', $result->getToolName());
        self::assertTrue($result->isSuccess());
        self::assertNull($result->getErrorMessage());
        self::assertSame(['temp' => 20], $result->getResult());
    }

    public function testFailureResultGetters(): void
    {
        $result = new ToolExecutionResult('weather', false, 'API down', null);

        self::assertFalse($result->isSuccess());
        self::assertSame('API down', $result->getErrorMessage());
        self::assertNull($result->getResult());
    }

    public function testDefaults(): void
    {
        $result = new ToolExecutionResult('tool', true);

        self::assertNull($result->getErrorMessage());
        self::assertNull($result->getResult());
    }
}
