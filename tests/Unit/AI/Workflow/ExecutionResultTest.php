<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Workflow;

use App\AI\Workflow\ExecutionResult;
use PHPUnit\Framework\TestCase;

/**
 * Vollstaendige Test-Abdeckung fuer ExecutionResult.
 */
final class ExecutionResultTest extends TestCase
{
    public function testSuccessResultGetters(): void
    {
        $result = new ExecutionResult(true, ['data' => 'value'], null, 'orig-request');
        self::assertTrue($result->isSuccess());
        self::assertSame(['data' => 'value'], $result->getResult());
        self::assertNull($result->getError());
        self::assertSame('orig-request', $result->getOriginalRequest());
    }

    public function testFailedResultGetters(): void
    {
        $result = new ExecutionResult(false, null, 'error message', 'orig');
        self::assertFalse($result->isSuccess());
        self::assertNull($result->getResult());
        self::assertSame('error message', $result->getError());
        self::assertSame('orig', $result->getOriginalRequest());
    }

    public function testToArraySuccess(): void
    {
        $result = new ExecutionResult(true, ['data' => 'value'], null, 'orig');
        self::assertSame([
            'success' => true,
            'result' => ['data' => 'value'],
            'error' => null,
            'original_request' => 'orig',
        ], $result->toArray());
    }

    public function testToArrayFailure(): void
    {
        $result = new ExecutionResult(false, null, 'err', 'orig');
        self::assertSame([
            'success' => false,
            'result' => null,
            'error' => 'err',
            'original_request' => 'orig',
        ], $result->toArray());
    }

    public function testResultWithNullResult(): void
    {
        $result = new ExecutionResult(true, null, null, 'orig');
        self::assertTrue($result->isSuccess());
        self::assertNull($result->getResult());
    }

    public function testResultWithEmptyOriginalRequest(): void
    {
        $result = new ExecutionResult(true, [], null, '');
        self::assertSame('', $result->getOriginalRequest());
    }
}
