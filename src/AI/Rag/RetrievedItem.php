<?php

namespace App\AI\Rag;

use App\Entity\Embedding;

/**
 * RetrievedItem - Reprsentiert ein aus dem VectorStore abgerufenes Item.
 * Enthlt Trust-Level Information fr Prompt-Injection-Schutz (P2).
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
     * Gibt das Trust-Level zurck (P2: Prompt-Injection Schutz).
     * Standardmig UNTRUSTED, kann aber aus Metadaten oder Embedding 
     * berschrieben werden.
     */
    public function getTrustLevel(): string
    {
        if (null !== $this->trustLevel) {
            return $this->trustLevel;
        }
        
        // Prfe Metadaten des Embeddings
        $metadata = $this->getMetadata();
        if (isset($metadata['trust_level']) && in_array($metadata['trust_level'], [
            self::TRUST_LEVEL_UNTRUSTED,
            self::TRUST_LEVEL_TRUSTED,
            self::TRUST_LEVEL_SYSTEM
        ])) {
            return $metadata['trust_level'];
        }
        
        // Standard: UNTRUSTED fr alle externen Inhalte
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
     * Prft, ob das Item als vertrauenswrdig markiert ist.
     */
    public function isTrusted(): bool
    {
        return $this->getTrustLevel() === self::TRUST_LEVEL_TRUSTED;
    }
    
    /**
     * Prft, ob das Item als System-Content markiert ist.
     */
    public function isSystem(): bool
    {
        return $this->getTrustLevel() === self::TRUST_LEVEL_SYSTEM;
    }
}
