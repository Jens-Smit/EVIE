<?php

namespace App\AI\Agent;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OrchestratorDialogService
{
    public function __construct(
        #[Autowire(service: 'ai.agent.orchestrator')]
        private AgentInterface $agent,
    ) {
    }

    public function ask(string $userMessage, string $userIdentifier): string
    {
        $messages = new MessageBag(
            Message::ofUser($userMessage),
        );

        $result = $this->agent->call($messages, ['user_identifier' => $userIdentifier]);

        return $result->getContent();
    }
}