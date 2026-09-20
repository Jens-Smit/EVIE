<?php

// src/EventListener/PendingApprovalCountListener.php

namespace App\EventListener;

use App\AI\Decision\DecisionManager;
use App\Repository\ToolDefinitionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;

/**
 * Stellt die Anzahl offener Freigaben (Tools + Entscheidungen) als Twig-Global
 * `pending_approval_count` bereit, damit der Sidebar-Badge serverseitig
 * gerendert wird und nicht per HTMX-JSON-Swap nachgeladen werden muss.
 */
final class PendingApprovalCountListener
{
    public function __construct(
        private readonly ToolDefinitionRepository $toolDefinitionRepository,
        private readonly DecisionManager $decisionManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly Environment $twig,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER, method: 'onKernelController')]
    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null || !$token->isAuthenticated()) {
            $this->twig->addGlobal('pending_approval_count', 0);

            return;
        }

        $userIdentifier = $token->getUserIdentifier();
        if ($userIdentifier === '' || $userIdentifier === 'anon.') {
            $this->twig->addGlobal('pending_approval_count', 0);

            return;
        }

        $toolCount = count($this->toolDefinitionRepository->findBy([
            'status' => ['pending', 'pending_approval'],
        ]));

        $decisionCount = 0;
        try {
            $decisionCount = count($this->decisionManager->getPendingDecisions($userIdentifier));
        } catch (\Throwable) {
            // DecisionManager kann in frischen Instanzen ohne Decision-Daten
            // werfen; der Badge darf niemals die Seite brechen.
        }

        $this->twig->addGlobal('pending_approval_count', $toolCount + $decisionCount);
    }
}
