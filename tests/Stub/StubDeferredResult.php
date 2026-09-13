<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * StubDeferredResult - baut ein finales DeferredResult, dessen asText()
 * einen vorgegebenen Text liefert, ohne echte API-Aufrufe.
 *
 * DeferredResult ist final und kann nicht gemockt werden. Stattdessen
 * erzeugen wir ein echtes DeferredResult mit einem ResultConverterInterface-
 * Stub, der convert() ein TextResult mit dem gewuenschten Text zurueckgibt.
 * So laesst sich PlatformInterface::invoke()->asText() in Unit-Tests
 * deterministisch testen (analog QuotaDecoratorTest, der ein DeferredResult
 * mit Converter-Mock baut).
 */
final class StubDeferredResult
{
    private function __construct()
    {
    }

    /**
     * Erzeugt ein DeferredResult, dessen asText() $text zurueckgibt.
     */
    public static function withText(string $text): DeferredResult
    {
        $converter = new class($text) implements ResultConverterInterface {
            public function __construct(private readonly string $text)
            {
            }

            public function supports(Model $model): bool
            {
                return true;
            }

            public function convert(RawResultInterface $result, array $options = []): ResultInterface
            {
                return new TextResult($this->text);
            }

            public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
            {
                return null;
            }
        };

        $rawResult = new class implements RawResultInterface {
            public function getData(): array
            {
                return [];
            }

            public function getDataStream(): iterable
            {
                return [];
            }

            public function getObject(): object
            {
                return new \stdClass();
            }
        };

        return new DeferredResult($converter, $rawResult);
    }
}
