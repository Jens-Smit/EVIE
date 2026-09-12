<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Response;

use App\AI\Response\ResponseNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer ResponseNormalizer.
 */
final class ResponseNormalizerTest extends TestCase
{
    private ResponseNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ResponseNormalizer(new NullLogger());
    }

    public function testNormalizeValidJsonReturnsJson(): void
    {
        $result = $this->normalizer->normalizeResponse('{"type":"dialog","content":"hi"}');

        $decoded = json_decode($result, true);
        self::assertSame('dialog', $decoded['type']);
        self::assertSame('hi', $decoded['content']);
    }

    public function testNormalizeExtractsJsonFromMarkdownCodeblock(): void
    {
        $raw = "Here is the response:\n```json\n{\"type\":\"dialog\",\"content\":\"hello\"}\n```\nDone.";

        $result = $this->normalizer->normalizeResponse($raw);
        $decoded = json_decode($result, true);

        self::assertSame('dialog', $decoded['type']);
        self::assertSame('hello', $decoded['content']);
    }

    public function testNormalizeExtractsJsonFromPlainCodeblock(): void
    {
        $raw = "```\n{\"type\":\"x\",\"content\":\"y\"}\n```";

        $result = $this->normalizer->normalizeResponse($raw);
        $decoded = json_decode($result, true);

        self::assertSame('x', $decoded['type']);
    }

    public function testNormalizeRepairsTrailingComma(): void
    {
        $raw = '{"type":"dialog","content":"hi",}';

        $result = $this->normalizer->normalizeResponse($raw);
        $decoded = json_decode($result, true);

        self::assertSame('dialog', $decoded['type']);
    }

    public function testNormalizeRepairsSingleQuotes(): void
    {
        $raw = "{'type':'dialog','content':'hi'}";

        $result = $this->normalizer->normalizeResponse($raw);
        $decoded = json_decode($result, true);

        self::assertSame('dialog', $decoded['type']);
        self::assertSame('hi', $decoded['content']);
    }

    public function testNormalizeCreatesFallbackForUnstructured(): void
    {
        $result = $this->normalizer->normalizeResponse('Some random unstructured text without json');

        $decoded = json_decode($result, true);
        self::assertSame('normalized', $decoded['status']);
        self::assertSame('fallback_normalization', $decoded['source']);
        self::assertSame('general', $decoded['intent']);
    }

    public function testFallbackDetectsWebsiteResearchIntent(): void
    {
        $result = $this->normalizer->normalizeResponse('Bitte recherchiere eine Webseite für mich');

        $decoded = json_decode($result, true);
        self::assertSame('website_research', $decoded['intent']);
    }

    public function testFallbackDetectsDataAnalysisIntent(): void
    {
        $result = $this->normalizer->normalizeResponse('Führe eine Datenanalyse der Statistik durch');

        $decoded = json_decode($result, true);
        self::assertSame('data_analysis', $decoded['intent']);
    }

    public function testFallbackDetectsCodeAssistanceIntent(): void
    {
        $result = $this->normalizer->normalizeResponse('Schreibe ein PHP Skript mit einer Funktion');

        $decoded = json_decode($result, true);
        self::assertSame('code_assistance', $decoded['intent']);
    }

    public function testFallbackDetectsDocumentProcessingIntent(): void
    {
        $result = $this->normalizer->normalizeResponse('Verarbeite ein PDF Dokument');

        $decoded = json_decode($result, true);
        self::assertSame('document_processing', $decoded['intent']);
    }

    public function testValidateResponseSchemaValid(): void
    {
        self::assertTrue($this->normalizer->validateResponseSchema('{"type":"dialog","content":"x"}'));
    }

    public function testValidateResponseSchemaMissingField(): void
    {
        self::assertFalse($this->normalizer->validateResponseSchema('{"type":"dialog"}'));
    }

    public function testValidateResponseSchemaInvalidJson(): void
    {
        self::assertFalse($this->normalizer->validateResponseSchema('not json'));
    }

    public function testExtractResponseData(): void
    {
        $json = '{"type":"dialog","content":"hello","intent":"general"}';

        self::assertSame('dialog', $this->normalizer->extractResponseData($json, 'type'));
        self::assertSame('hello', $this->normalizer->extractResponseData($json, 'content'));
        self::assertNull($this->normalizer->extractResponseData($json, 'missing'));
    }

    public function testCreateErrorResponse(): void
    {
        $result = $this->normalizer->createErrorResponse('Something went wrong', 'original text');

        $decoded = json_decode($result, true);
        self::assertSame('error', $decoded['type']);
        self::assertSame('Something went wrong', $decoded['error_message']);
        self::assertSame('failed', $decoded['status']);
        self::assertSame('original text', $decoded['original_content']);
    }

    public function testCreateErrorResponseWithoutOriginalContent(): void
    {
        $decoded = json_decode($this->normalizer->createErrorResponse('boom'), true);
        self::assertSame('', $decoded['original_content']);
    }
}
