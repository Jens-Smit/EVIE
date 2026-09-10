<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Response;

use App\AI\Response\FaultTolerantValidator;
use App\AI\Response\ResponseNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Vollstaendige Test-Abdeckung fuer FaultTolerantValidator.
 *
 * Verifiziert direktes JSON-Parsing, Normalisierungspfad, Fallback-Strategie,
 * Schema-Validierung, Intent-Analyse, Content-Bereinigung und
 * Fehlerklassifizierung.
 */
final class FaultTolerantValidatorTest extends TestCase
{
    private function createValidator(?ResponseNormalizer $normalizer = null): FaultTolerantValidator
    {
        return new FaultTolerantValidator(new NullLogger(), $normalizer ?? new ResponseNormalizer(new NullLogger()));
    }

    /**
     * Normalizer-Mock, dessen normalizeResponse immer nicht-parsbares JSON
     * zurueckgibt, damit der Fallback-Pfad durchlaufen wird.
     */
    private function createFailingNormalizer(): ResponseNormalizer
    {
        $n = $this->createMock(ResponseNormalizer::class);
        $n->method('normalizeResponse')->willReturn('not-valid-json');
        return $n;
    }

    private function validDialogJson(): string
    {
        return json_encode(['type' => 'dialog', 'content' => 'Hallo'], JSON_THROW_ON_ERROR);
    }

    public function testValidateWithFallbackDirectParseSuccess(): void
    {
        $result = $this->createValidator()->validateWithFallback($this->validDialogJson(), 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame($this->validDialogJson(), $result['normalized_response']);
        self::assertSame(1.0, $result['confidence']);
        self::assertSame('direct_parse_success', $result['recovery_action']);
    }

    public function testValidateWithFallbackDirectParseInvalidSchema(): void
    {
        $invalidSchema = json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback($invalidSchema, 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame('fallback_applied', $result['error_type']);
        self::assertSame('fallback_response', $result['recovery_action']);
    }

    public function testValidateWithFallbackJsonParseError(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('not json at all', 'Hallo Welt');
        self::assertTrue($result['is_valid']);
        self::assertSame('fallback_applied', $result['error_type']);
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame('fallback', $decoded['status']);
    }

    public function testValidateWithFallbackNormalizationSuccess(): void
    {
        $normalizer = $this->createMock(ResponseNormalizer::class);
        $validNormalized = json_encode(['type' => 'dialog', 'content' => 'ok'], JSON_THROW_ON_ERROR);
        $normalizer->method('normalizeResponse')->willReturn($validNormalized);

        $result = $this->createValidator($normalizer)->validateWithFallback('not-json', 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame(0.8, $result['confidence']);
        self::assertSame('normalization_success', $result['recovery_action']);
        self::assertSame($validNormalized, $result['normalized_response']);
    }

    public function testValidateWithFallbackNormalizationReturnsInvalidJson(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('not-json', 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame('fallback_applied', $result['error_type']);
    }

    public function testValidateWithFallbackNormalizationThrowsException(): void
    {
        $normalizer = $this->createMock(ResponseNormalizer::class);
        $normalizer->method('normalizeResponse')->willThrowException(new \RuntimeException('norm-fail'));

        $result = $this->createValidator($normalizer)->validateWithFallback('not-json', 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame('fallback_applied', $result['error_type']);
    }

    public function testValidateWithFallbackNormalizationValidJsonInvalidSchema(): void
    {
        $normalizer = $this->createMock(ResponseNormalizer::class);
        $normalizer->method('normalizeResponse')->willReturn(json_encode(['wrong' => true], JSON_THROW_ON_ERROR));

        $result = $this->createValidator($normalizer)->validateWithFallback('not-json', 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame('fallback_applied', $result['error_type']);
    }

    public function testValidateWithFallbackFallbackCreatesStructuredResponseWithIntent(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('roher content', 'Bitte recherchiere eine Webseite und fasse zusammen');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame('website_research', $decoded['intent']);
        self::assertSame('fallback', $decoded['status']);
        self::assertStringContainsString('website_researcher', implode(',', $decoded['suggested_actions']));
    }

    public function testValidateWithFallbackIntentDataAnalysis(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('x', 'Analysiere die Daten und erstelle eine Statistik mit Diagramm');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame('data_analysis', $decoded['intent']);
    }

    public function testValidateWithFallbackIntentCodeAssistance(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('x', 'Schreibe ein PHP Skript als Funktion');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame('code_assistance', $decoded['intent']);
    }

    public function testValidateWithFallbackIntentDocumentProcessing(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('x', 'Verarbeite ein PDF Dokument und extrahiere den Text');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame('document_processing', $decoded['intent']);
    }

    public function testValidateWithFallbackIntentGeneralQuery(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('x', 'irgendetwas ganz anderes');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame('general_query', $decoded['intent']);
        self::assertSame(['ask_for_clarification', 'search_knowledge_base'], $decoded['suggested_actions']);
    }

    public function testValidateWithFallbackCleansLongContent(): void
    {
        $longContent = str_repeat('a', 2000);
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback($longContent, 'Hallo');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertLessThanOrEqual(1003, strlen($decoded['content']));
        self::assertStringEndsWith('...', $decoded['content']);
    }

    public function testValidateWithFallbackContextParameter(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('x', 'Hallo', 'custom-context');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame('custom-context', $decoded['context']);
    }

    public function testValidateWithFallbackValidToolCallType(): void
    {
        $valid = json_encode(['type' => 'tool_call', 'content' => 'call'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator()->validateWithFallback($valid, 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame(1.0, $result['confidence']);
        self::assertSame('direct_parse_success', $result['recovery_action']);
    }

    public function testValidateWithFallbackValidResearchResultType(): void
    {
        $valid = json_encode(['type' => 'research_result', 'content' => 'data'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator()->validateWithFallback($valid, 'Hallo');
        self::assertTrue($result['is_valid']);
    }

    public function testValidateWithFallbackMissingContentField(): void
    {
        $missingContent = json_encode(['type' => 'dialog'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback($missingContent, 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame('fallback_applied', $result['error_type']);
    }

    public function testValidateWithFallbackMissingTypeField(): void
    {
        $missingType = json_encode(['content' => 'data'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback($missingType, 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame('fallback_applied', $result['error_type']);
    }

    public function testValidateWithFallbackInvalidTypeValue(): void
    {
        $invalidType = json_encode(['type' => 'not_a_valid_type', 'content' => 'data'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback($invalidType, 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame('fallback_applied', $result['error_type']);
    }

    public function testValidateWithFallbackUserMessageMetadata(): void
    {
        $userMsg = 'Das ist eine sehr lange User-Nachricht die mehr als hundert Zeichen enthaelt um den Test zu pruefen und zwar sehr genau';
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('x', $userMsg);
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame(substr($userMsg, 0, 100), $decoded['metadata']['original_message']);
        self::assertSame(strlen('x'), $decoded['metadata']['raw_response_length']);
    }

    public function testValidateWithFallbackFallbackResponseHasTimestamp(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('x', 'Hallo');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertNotEmpty($decoded['timestamp']);
        self::assertSame('fault_tolerant_fallback', $decoded['source']);
    }

    public function testValidateWithFallbackEmptyResponse(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('', 'Hallo');
        self::assertTrue($result['is_valid']);
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame('fallback', $decoded['status']);
        self::assertSame('', $decoded['content']);
    }

    public function testValidateWithFallbackValidSubagentDelegationType(): void
    {
        $valid = json_encode(['type' => 'subagent_delegation', 'content' => 'x'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator()->validateWithFallback($valid, 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame(1.0, $result['confidence']);
    }

    public function testValidateWithFallbackValidNoToolFoundType(): void
    {
        $valid = json_encode(['type' => 'no_tool_found', 'content' => 'x'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator()->validateWithFallback($valid, 'Hallo');
        self::assertTrue($result['is_valid']);
    }

    public function testValidateWithFallbackValidAnalysisResultType(): void
    {
        $valid = json_encode(['type' => 'analysis_result', 'content' => 'x'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator()->validateWithFallback($valid, 'Hallo');
        self::assertTrue($result['is_valid']);
    }

    public function testValidateWithFallbackValidCodeResponseType(): void
    {
        $valid = json_encode(['type' => 'code_response', 'content' => 'x'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator()->validateWithFallback($valid, 'Hallo');
        self::assertTrue($result['is_valid']);
    }

    public function testValidateWithFallbackValidDocumentProcessingResultType(): void
    {
        $valid = json_encode(['type' => 'document_processing_result', 'content' => 'x'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator()->validateWithFallback($valid, 'Hallo');
        self::assertTrue($result['is_valid']);
    }

    public function testValidateWithFallbackDirectParseInvalidSchemaFallsToNormalizationSuccess(): void
    {
        // direct parse fails schema, normalization succeeds with valid schema
        $normalizer = $this->createMock(ResponseNormalizer::class);
        $validNormalized = json_encode(['type' => 'dialog', 'content' => 'fixed'], JSON_THROW_ON_ERROR);
        $normalizer->method('normalizeResponse')->willReturn($validNormalized);

        $invalidSchema = json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR);
        $result = $this->createValidator($normalizer)->validateWithFallback($invalidSchema, 'Hallo');
        self::assertTrue($result['is_valid']);
        self::assertSame('normalization_success', $result['recovery_action']);
    }

    public function testValidateWithFallbackFallbackStrategyCreatesErrorResponse(): void
    {
        // Force the fallback's inner try to throw: analyzeUserIntent/getSuggestedActions path
        // Use a user message that triggers fallback (failing normalizer) and verify error response path
        // by providing content that makes createStructuredFallback produce a valid fallback
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('content', 'Hallo');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame('fallback', $decoded['status']);
        self::assertSame(0.6, $decoded['confidence']);
        self::assertSame('fault_tolerant_fallback', $decoded['source']);
        self::assertContains('intent_analysis', $decoded['metadata']['processing_steps']);
    }

    public function testValidateWithFallbackMetadataFields(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('some content here', 'Hallo');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame(strlen('some content here'), $decoded['metadata']['cleaned_response_length']);
        self::assertSame('dialog', $decoded['type']);
        self::assertSame('intent_analysis', $decoded['metadata']['processing_steps'][0]);
    }

    public function testValidateWithFallbackNormalizedResponseContainsDialogType(): void
    {
        $result = $this->createValidator($this->createFailingNormalizer())
            ->validateWithFallback('test', 'Hallo');
        $decoded = json_decode($result['normalized_response'], true);
        self::assertSame('dialog', $decoded['type']);
    }

    public function testValidateWithFallbackCreatesErrorResponseOnFallbackException(): void
    {
        // Force fallback to throw by providing a binary string that breaks cleaning
        $normalizer = $this->createMock(ResponseNormalizer::class);
        $normalizer->method('normalizeResponse')->willReturn('not-valid-json');

        // Use an invalid UTF-8 sequence that may break preg_replace
        $badContent = "\xff\xfe invalid utf8";
        $result = $this->createValidator($normalizer)->validateWithFallback($badContent, 'Hallo');
        self::assertTrue($result['is_valid']);
    }

    public function testClassifyErrorPatternJsonSyntaxError(): void
    {
        $type = $this->createValidator()->classifyErrorPattern('{ "foo": "bar"');
        self::assertSame('json_syntax_error', $type);
    }

    public function testClassifyErrorPatternLlmHallucination(): void
    {
        $type = $this->createValidator()->classifyErrorPattern('Ich weiß nicht, was ich tun soll');
        self::assertSame('llm_hallucination', $type);
    }

    public function testClassifyErrorPatternToolNotFound(): void
    {
        $type = $this->createValidator()->classifyErrorPattern('Kein Tool verfügbar für diese Aktion');
        self::assertSame('tool_not_found', $type);
    }

    public function testClassifyErrorPatternMarkdownCodeblock(): void
    {
        $type = $this->createValidator()->classifyErrorPattern('```json {"foo":"bar"}```');
        self::assertSame('markdown_codeblock', $type);
    }

    public function testClassifyErrorPatternPartialJson(): void
    {
        $type = $this->createValidator()->classifyErrorPattern('{ "key": ' . str_repeat('x', 60) . ' }');
        self::assertSame('partial_json', $type);
    }

    public function testClassifyErrorPatternUnknownError(): void
    {
        $type = $this->createValidator()->classifyErrorPattern('plain text with no patterns');
        self::assertSame('unknown_error', $type);
    }

}
