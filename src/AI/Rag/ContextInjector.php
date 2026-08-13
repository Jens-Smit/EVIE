<?php

namespace App\AI\Rag;

class ContextInjector
{
    private string $contextTemplate = "

## Relevanter Kontext:
{context}

";

    public function __construct(
        private Retriever $retriever
    ) {
    }

    public function inject(string $prompt, string $query, array $options = []): string
    {
        $result = $this->retriever->retrieve($query, $options);
        
        if (!$result->hasResults()) {
            return $prompt;
        }

        $context = $result->getContextAsString();
        
        if (str_contains($prompt, '{context}')) {
            return str_replace('{context}', $context, $prompt);
        }

        return $prompt . $this->contextTemplate . ['{context}' => $context];
    }

    public function injectForSystemPrompt(string $systemPrompt, string $query, array $options = []): string
    {
        $result = $this->retriever->retrieve($query, $options);
        
        if (!$result->hasResults()) {
            return $systemPrompt;
        }

        $context = $result->getContextAsString();
        return $systemPrompt . "

" . $this->contextTemplate . ['{context}' => $context];
    }

    public function setContextTemplate(string $template): void
    {
        $this->contextTemplate = $template;
    }
}
