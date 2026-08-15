<?php

declare(strict_types=1);

namespace App\AI\Rag;

use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\Message;

/**
 * RAG ContextInjector als nativer Symfony AI InputProcessor (Blueprint §4.H).
 *
 * Bei jedem User-Prompt holt er über den Retriever relevante Profil-/
 * Kontext-Informationen aus dem Vector Store und fügt sie als SystemMessage
 * in den MessageBag ein. Native Implementierung — kein Eigenbau-Decorator.
 */
#[AsInputProcessor]
final class ContextInjector implements InputProcessorInterface
{
    private string $contextTemplate = <<<'TXT'
## Relevanter Kontext aus der Wissensbasis:
{context}
TXT;

    public function __construct(
        private readonly Retriever $retriever,
    ) {
    }

    public function processInput(Input $input): void
    {
        $messageBag = $input->getMessageBag();

        // Query aus der letzten User-Nachricht ableiten.
        $messages = $messageBag->getMessages();
        $query = '';
        foreach (array_reverse($messages) as $message) {
            $text = $message->asText();
            if ('' !== $text) {
                $query = $text;
                break;
            }
        }

        if ('' === $query) {
            return;
        }

        $result = $this->retriever->retrieve($query);

        if (!$result->hasResults()) {
            return;
        }

        $context = $result->getContextAsString();
        if ('' === $context) {
            return;
        }

        $systemContent = str_replace('{context}', $context, $this->contextTemplate);
        $messageBag->add(new Message(Role::System, new Text($systemContent)));
    }

    /**
     * Legacy-Methode für direkte Prompt-Injektion (z. B. in Workflows, die
     * keinen nativen Agent-Loop nutzen).
     */
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

        return $prompt . "\n" . str_replace('{context}', $context, $this->contextTemplate);
    }

    public function setContextTemplate(string $template): void
    {
        $this->contextTemplate = $template;
    }
}
