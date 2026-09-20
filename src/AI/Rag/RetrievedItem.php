<?php

namespace App\AI\Rag;

use App\Entity\Embedding;

/**
 * RetrievedItem - Repräsentiert ein aus dem VectorStore abgerufenes Item.
 * Enthält Trust-Level Information für Prompt-Injection-Schutz (P2).
 */
class RetrievedItem
{
    public const TRUST_LEVEL_UNTRUSTED = 'untrusted';
    public const TRUST_LEVEL_TRUSTED = 'trusted';
    public const TRUST_LEVEL_SYSTEM = 'system';
    
    private ?string $trustLevel = null;

    public function __construct(
        public Embedding $embedding,
        public float $similarity,
        public string $contentType
    ) {
    }

    public function getContent(): string
    {
        return $this->embedding->getContent();
    }

    public function getMetadata(): array
    {
        return $this->embedding->getMetadata();
    }

    public function getSource(): ?string
    {
        return $this->embedding->getSource();
    }
    
    /**
     * Gibt das Trust-Level zurück (P2: Prompt-Injection Schutz).
     * Standardmäßig UNTRUSTED, kann aber aus Metadaten oder Embedding 
     * überschrieben werden.
     */
    public function getTrustLevel(): string
    {
        if (null !== $this->trustLevel) {
            return $this->trustLevel;
        }
        
        // Prüfe Metadaten des Embeddings
        $metadata = $this->getMetadata();
        if (isset($metadata['trust_level']) && in_array($metadata['trust_level'], [
            self::TRUST_LEVEL_UNTRUSTED,
            self::TRUST_LEVEL_TRUSTED,
            self::TRUST_LEVEL_SYSTEM
        ])) {
            return $metadata['trust_level'];
        }
        
        // Standard: UNTRUSTED für alle externen Inhalte
        return self::TRUST_LEVEL_UNTRUSTED;
    }
    
    /**
     * Setzt das Trust-Level manuell.
     */
    public function setTrustLevel(string $trustLevel): void
    {
        if (in_array($trustLevel, [
            self::TRUST_LEVEL_UNTRUSTED,
            self::TRUST_LEVEL_TRUSTED,
            self::TRUST_LEVEL_SYSTEM
        ])) {
            $this->trustLevel = $trustLevel;
        }
    }
    
    /**
     * Prüft, ob das Item als vertrauenswürdig markiert ist.
     */
    public function isTrusted(): bool
    {
        return $this->getTrustLevel() === self::TRUST_LEVEL_TRUSTED;
    }
    
    /**
     * Prüft, ob das Item als System-Content markiert ist.
     */
    public function isSystem(): bool
    {
        return $this->getTrustLevel() === self::TRUST_LEVEL_SYSTEM;
    }
}
