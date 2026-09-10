<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Response;

use App\AI\Response\JsonResponseEnforcer;
use App\AI\Response\ResponseNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Vollstaendige Test-Abdeckung fuer JsonResponseEnforcer.
 *
 * Verifiziert createStructuredPrompt fuer alle Agent-Typen,
 * validateJsonResponse (gültig/ungültig/normalisiert) und
 * extractResponseType (inklusive Markdown-Codeblock-Extraktion).
 */
final class JsonResponseEnforcerTest extends TestCase
{
    private function createEnforcer(?ResponseNormalizer $normalizer = null): JsonResponseEnforcer
    {
        return new JsonResponseEnforcer(
            $this->createMock(PlatformInterface::class),
            new NullLogger(),
            $normalizer ?? new ResponseNormalizer(new NullLogger())
        );
    }

    private function contentToString(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (method_exists($part, 'getText')) {
                    $text .= $part->getText();
                }
            }
            return $text;
        }
        return '';
    }

    public function testCreateStructuredPromptDefaultAgent(): void
    {
        $messages = $this->createEnforcer()->createStructuredPrompt('Hallo');
        self::assertInstanceOf(MessageBag::class, $messages);
        $all = $messages->getMessages();
        self::assertCount(2, $all);
        $systemContent = $this->contentToString($all[0]->getContent());
        $userContent = $this->contentToString($all[1]->getContent());
        self::assertStringContainsString('Orchestrator-Agent', $systemContent);
        self::assertStringContainsString('User-Anfrage', $userContent);
        self::assertStringContainsString('Hallo', $userContent);
    }

    public function testCreateStructuredPromptWebsiteResearcher(): void
    {
        $messages = $this->createEnforcer()->createStructuredPrompt('Suche', 'website_researcher');
        $system = $this->contentToString($messages->getMessages()[0]->getContent());
        self::assertStringContainsString('Website-Research-Agent', $system);
        self::assertStringContainsString('research_result', $system);
    }

    public function testCreateStructuredPromptDataAnalyst(): void
    {
        $messages = $this->createEnforcer()->createStructuredPrompt('Analysiere', 'data_analyst');
        $system = $this->contentToString($messages->getMessages()[0]->getContent());
        self::assertStringContainsString('Data-Analyst-Agent', $system);
        self::assertStringContainsString('analysis_result', $system);
    }

    public function testCreateStructuredPromptCodeAssistant(): void
    {
        $messages = $this->createEnforcer()->createStructuredPrompt('Code', 'code_assistant');
        $system = $this->contentToString($messages->getMessages()[0]->getContent());
        self::assertStringContainsString('Code-Assistant-Agent', $system);
        self::assertStringContainsString('code_response', $system);
    }

    public function testCreateStructuredPromptDocumentProcessor(): void
    {
        $messages = $this->createEnforcer()->createStructuredPrompt('PDF', 'document_processor');
        $system = $this->contentToString($messages->getMessages()[0]->getContent());
        self::assertStringContainsString('Document-Processor-Agent', $system);
        self::assertStringContainsString('document_processing_result', $system);
    }

    public function testCreateStructuredPromptUnknownAgentFallsBackToOrchestrator(): void
    {
        $messages = $this->createEnforcer()->createStructuredPrompt('Hallo', 'unknown_type');
        $system = $this->contentToString($messages->getMessages()[0]->getContent());
        self::assertStringContainsString('Orchestrator-Agent', $system);
    }

    public function testCreateStructuredPromptNormalizesUserMessage(): void
    {
        $messages = $this->createEnforcer()->createStructuredPrompt('  whitespace  ', 'orchestrator');
        $user = $this->contentToString($messages->getMessages()[1]->getContent());
        self::assertStringNotContainsString('  whitespace  ', $user);
        self::assertStringContainsString('whitespace', $user);
        self::assertStringContainsString('JSON-Format', $user);
    }

    public function testValidateJsonResponseValidWith(): void
    {
        $valid = json_encode(['type' => 'dialog', 'content' => 'ok'], JSON_THROW_ON_ERROR);
        self::assertTrue($this->createEnforcer()->validateJsonResponse($valid));
    }

    public function testValidateJsonResponseValidWithoutContent(): void
    {
        $valid = json_encode(['type' => 'tool_call'], JSON_THROW_ON_ERROR);
        self::assertTrue($this->createEnforcer()->validateJsonResponse($valid));
    }

    public function testValidateJsonResponseInvalidJsonNormalizable(): void
    {
        $normalizer = $this->createMock(ResponseNormalizer::class);
        $normalizer->method('normalizeResponse')
            ->willReturn(json_encode(['type' => 'dialog', 'content' => 'fixed'], JSON_THROW_ON_ERROR));

        self::assertTrue($this->createEnforcer($normalizer)->validateJsonResponse('not-json'));
    }

    public function testValidateJsonResponseInvalidJsonNotNormalizable(): void
    {
        $normalizer = $this->createMock(ResponseNormalizer::class);
        $normalizer->method('normalizeResponse')->willReturn('still-not-json');

        self::assertFalse($this->createEnforcer($normalizer)->validateJsonResponse('not-json'));
    }

    public function testValidateJsonResponseInvalidJsonNormalizedWithoutType(): void
    {
        $normalizer = $this->createMock(ResponseNormalizer::class);
        $normalizer->method('normalizeResponse')
            ->willReturn(json_encode(['content' => 'no type'], JSON_THROW_ON_ERROR));

        self::assertFalse($this->createEnforcer($normalizer)->validateJsonResponse('not-json'));
    }

    public function testValidateJsonResponseValidJsonMissingType(): void
    {
        $missingType = json_encode(['content' => 'data'], JSON_THROW_ON_ERROR);
        self::assertFalse($this->createEnforcer()->validateJsonResponse($missingType));
    }

    public function testValidateJsonResponseEmptyStringNormalized(): void
    {
        // Empty string gets normalized by ResponseNormalizer to valid JSON with type
        $result = $this->createEnforcer()->validateJsonResponse('');
        self::assertTrue($result);
    }

    public function testExtractResponseTypeValidJson(): void
    {
        $valid = json_encode(['type' => 'tool_call', 'tool_name' => 'search'], JSON_THROW_ON_ERROR);
        self::assertSame('tool_call', $this->createEnforcer()->extractResponseType($valid));
    }

    public function testExtractResponseTypeMarkdownCodeblock(): void
    {
        $response = '```json' . PHP_EOL . '{"type": "dialog", "content": "hi"}' . PHP_EOL . '```';
        self::assertSame('dialog', $this->createEnforcer()->extractResponseType($response));
    }

    public function testExtractResponseTypeMarkdownWithoutJsonTag(): void
    {
        $response = '```' . PHP_EOL . '{"type": "analysis_result"}' . PHP_EOL . '```';
        self::assertSame('analysis_result', $this->createEnforcer()->extractResponseType($response));
    }

    public function testExtractResponseTypeInvalidJsonNoMarkdown(): void
    {
        self::assertNull($this->createEnforcer()->extractResponseType('plain text'));
    }

    public function testExtractResponseTypeMarkdownInvalidJson(): void
    {
        $response = '```json' . PHP_EOL . 'not valid json' . PHP_EOL . '```';
        self::assertNull($this->createEnforcer()->extractResponseType($response));
    }

    public function testExtractResponseTypeNullType(): void
    {
        $valid = json_encode(['content' => 'no type'], JSON_THROW_ON_ERROR);
        self::assertNull($this->createEnforcer()->extractResponseType($valid));
    }

    public function testGetOrchestratorPromptContainsAllSchemas(): void
    {
        $messages = $this->createEnforcer()->createStructuredPrompt('test', 'orchestrator');
        $system = $this->contentToString($messages->getMessages()[0]->getContent());
        self::assertStringContainsString('tool_call', $system);
        self::assertStringContainsString('subagent_delegation', $system);
        self::assertStringContainsString('no_tool_found', $system);
        self::assertStringContainsString('website_research_result', $system);
        self::assertStringContainsString('dialog', $system);
        self::assertStringContainsString('JSON-Format', $system);
    }
}
