<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Custom DBAL-Type fuer pgvector vector(1024)-Spalten (P2-3).
 *
 * Konvertiert PHP-Array [0.1, 0.2, ...] <-> pgvector-String '[0.1,0.2,...]'.
 * Auf SQLite (Tests) wird als JSON gespeichert, da SQLite keinen
 * pgvector-Typ unterstuetzt.
 */
final class VectorType extends Type
{
    public const NAME = 'vector';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // Auf PostgreSQL den echten vector-Typ nutzen, sonst JSON-Fallback
        if (method_exists($platform, 'getName') && $platform->getName() === 'postgresql') {
            $dimension = $column['dimension'] ?? 1024;
            return sprintf('vector(%d)', $dimension);
        }

        return $platform->getJsonTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (is_array($value)) {
            // pgvector erwartet [0.1,0.2,...] als Text-Literal
            return '[' . implode(',', array_map('floatval', $value)) . ']';
        }

        return (string) $value;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?array
    {
        if (null === $value) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        // pgvector gibt den Vektor als String '[0.1,0.2,...]' zurueck
        $value = trim($value, '[]');
        if ($value === '') {
            return [];
        }

        return array_map('floatval', explode(',', $value));
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
