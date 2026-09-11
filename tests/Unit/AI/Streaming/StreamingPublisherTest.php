<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Streaming;

use App\AI\Streaming\StreamingPublisher;
use App\Message\StreamToolResponseMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Unit-Tests für StreamingPublisher (Mercure-basierte Streaming-Updates).
 */
final class StreamingPublisherTest extends TestCase
{
    private HubInterface&MockObject $hub;
    private StreamingPublisher $publisher;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->publisher = new StreamingPublisher($this->hub, new NullLogger());
    }

    public function testPublishUpdateSendsToCorrectTopic(): void
    {
        $this->hub
            ->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                self::assertSame('/streaming/sessions/session123', $update->getTopics()[0]);
                $data = json_decode($update->getData(), true);
                self::assertSame('test_event', $data['event']);
                self::assertSame(['key' => 'value'], $data['data']);
                return 'id-123';
            });

        $this->publisher->publishUpdate('session123', 'test_event', ['key' => 'value']);
    }

    public function testPublishUpdateWithSuffix(): void
    {
        $this->hub
            ->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                self::assertSame('/streaming/sessions/session123/custom', $update->getTopics()[0]);
                return 'id';
            });

        $this->publisher->publishUpdate('session123', 'event', [], 'custom');
    }

    public function testPublishUpdateSwallowsException(): void
    {
        $this->hub
            ->method('publish')
            ->willThrowException(new \RuntimeException('Mercure error'));

        $this->publisher->publishUpdate('session', 'event', []);

        self::assertTrue(true);
    }

    public function testPublishStreamResponse(): void
    {
        $message = new StreamToolResponseMessage('sess1', 'ToolA', 'chunk-data', 'partial_result');

        $this->hub
            ->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                $data = json_decode($update->getData(), true);
                self::assertSame('partial_result', $data['event']);
                self::assertSame('sess1', $data['data']['session_id']);
                return 'id';
            });

        $this->publisher->publishStreamResponse($message);
    }

    public function testPublishSessionStart(): void
    {
        $this->hub
            ->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                $data = json_decode($update->getData(), true);
                self::assertSame('session_start', $data['event']);
                self::assertSame('WeatherTool', $data['data']['tool_name']);
                self::assertSame('running', $data['data']['status']);
                self::assertSame(0, $data['data']['progress']);
                return 'id';
            });

        $this->publisher->publishSessionStart('sess1', 'WeatherTool', ['city' => 'Berlin'], 'tenant1');
    }

    public function testPublishSessionEnd(): void
    {
        $this->hub
            ->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                $data = json_decode($update->getData(), true);
                self::assertSame('session_end', $data['event']);
                self::assertTrue($data['data']['success']);
                self::assertSame('completed', $data['data']['final_status']);
                self::assertSame(100, $data['data']['progress']);
                return 'id';
            });

        $this->publisher->publishSessionEnd('sess1', 'ToolA', true, 'completed');
    }

    public function testPublishSessionEndWithMetadata(): void
    {
        $this->hub
            ->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                $data = json_decode($update->getData(), true);
                self::assertSame(['extra' => 'info'], $data['data']['metadata']);
                return 'id';
            });

        $this->publisher->publishSessionEnd('sess1', 'ToolA', false, 'failed', ['extra' => 'info']);
    }

    public function testPublishProgress(): void
    {
        $this->hub
            ->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                $data = json_decode($update->getData(), true);
                self::assertSame('progress', $data['event']);
                self::assertSame(75.5, $data['data']['percentage']);
                self::assertSame('Processing', $data['data']['message']);
                self::assertSame(['extra' => 'data'], $data['data']['data']);
                return 'id';
            });

        $this->publisher->publishProgress('sess1', 75.5, 'Processing', ['extra' => 'data']);
    }

    public function testPublishError(): void
    {
        $this->hub
            ->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                $data = json_decode($update->getData(), true);
                self::assertSame('error', $data['event']);
                self::assertSame('Something went wrong', $data['data']['error']);
                self::assertSame(['code' => 500], $data['data']['details']);
                return 'id';
            });

        $this->publisher->publishError('sess1', 'Something went wrong', ['code' => 500]);
    }

    public function testGetEventTypeFromChunkTypeMapping(): void
    {
        $types = ['progress', 'partial_result', 'final_result', 'error', 'data', 'log', 'status', 'unknown_type'];

        $expected = ['progress', 'partial_result', 'final_result', 'error', 'data', 'log', 'status', 'data'];

        foreach ($types as $i => $chunkType) {
            $message = new StreamToolResponseMessage('sess', 'Tool', 'chunk', $chunkType);

            $this->hub
                ->expects(self::any())
                ->method('publish')
                ->willReturnCallback(function (Update $update) use ($expected, $i): string {
                    $data = json_decode($update->getData(), true);
                    self::assertSame($expected[$i], $data['event']);
                    return 'id';
                });

            $this->publisher->publishStreamResponse($message);
        }
    }
}
