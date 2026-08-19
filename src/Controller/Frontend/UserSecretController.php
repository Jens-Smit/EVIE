<?php

namespace App\Controller\Frontend;

use App\AI\Secrets\SecretRequestService;
use App\AI\Secrets\UserSecretManager;
use App\Entity\ToolDefinition;
use App\Entity\User;
use App\Entity\UserSecret;
use App\Repository\ToolDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller für User-Secrets in den Settings
 */
#[Route('/settings/secrets')]
#[IsGranted('ROLE_USER')]
class UserSecretController extends AbstractController
{
    public function __construct(
        private UserSecretManager $secretManager,
        private SecretRequestService $requestService,
        private ToolDefinitionRepository $toolRepo,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Zeigt die User-Secrets an
     */
    #[Route('/', name: 'app_user_secrets')]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $secrets = $this->secretManager->getAllSecrets($user);
        
        // Tools, die Secrets benötigen
        $toolsWithSecrets = [];
        $allTools = $this->toolRepo->findAll();
        
        foreach ($allTools as $tool) {
            $requiredSecrets = $this->secretManager->getRequiredSecretsForTool($tool);
            if (!empty($requiredSecrets)) {
                $toolsWithSecrets[] = [
                    'tool' => $tool,
                    'required_secrets' => $requiredSecrets,
                    'has_all_secrets' => $this->secretManager->hasAllRequiredSecrets($user, $tool)
                ];
            }
        }

        // Fehlende Secrets für den User
        $missingSecrets = $this->requestService->getAllMissingSecretsForUser($user);

        return $this->render('frontend/settings/secrets/index.html.twig', [
            'secrets' => $secrets,
            'tools_with_secrets' => $toolsWithSecrets,
            'missing_secrets' => $missingSecrets
        ]);
    }

    /**
     * Speichert ein neues Secret
     */
    #[Route('/save', name: 'app_user_secret_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $key = trim($request->request->get('key'));
        $value = trim($request->request->get('value'));
        $description = trim($request->request->get('description'));
        $toolName = trim($request->request->get('tool_name'));

        // Validierung
        if (empty($key)) {
            $this->addFlash('error', 'Bitte geben Sie einen Schlüssel ein.');
            return $this->redirectToRoute('app_user_secrets');
        }

        if (empty($value)) {
            $this->addFlash('error', 'Bitte geben Sie einen Wert ein.');
            return $this->redirectToRoute('app_user_secrets');
        }

        // Speichere das Secret
        $this->secretManager->setSecret($user, $key, $value, $description, $toolName);
        
        $this->addFlash('success', 'Secret erfolgreich gespeichert.');
        
        return $this->redirectToRoute('app_user_secrets');
    }

    /**
     * Löscht ein Secret
     */
    #[Route('/delete/{id}', name: 'app_user_secret_delete', methods: ['POST'])]
    public function delete(UserSecret $secret): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Prüfe, ob der User das Secret besitzt
        if ($secret->getUser() !== $user) {
            $this->addFlash('error', 'Sie können nur Ihre eigenen Secrets löschen.');
            return $this->redirectToRoute('app_user_secrets');
        }

        $this->secretManager->deleteSecret($user, $secret->getKey());
        
        $this->addFlash('success', 'Secret erfolgreich gelöscht.');
        
        return $this->redirectToRoute('app_user_secrets');
    }

    /**
     * Deaktiviert ein Secret
     */
    #[Route('/deactivate/{id}', name: 'app_user_secret_deactivate', methods: ['POST'])]
    public function deactivate(UserSecret $secret): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Prüfe, ob der User das Secret besitzt
        if ($secret->getUser() !== $user) {
            $this->addFlash('error', 'Sie können nur Ihre eigenen Secrets deaktivieren.');
            return $this->redirectToRoute('app_user_secrets');
        }

        $this->secretManager->deactivateSecret($user, $secret->getKey());
        
        $this->addFlash('success', 'Secret erfolgreich deaktiviert.');
        
        return $this->redirectToRoute('app_user_secrets');
    }

    /**
     * Aktiviert ein Secret
     */
    #[Route('/activate/{id}', name: 'app_user_secret_activate', methods: ['POST'])]
    public function activate(UserSecret $secret): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Prüfe, ob der User das Secret besitzt
        if ($secret->getUser() !== $user) {
            $this->addFlash('error', 'Sie können nur Ihre eigenen Secrets aktivieren.');
            return $this->redirectToRoute('app_user_secrets');
        }

        $this->secretManager->activateSecret($user, $secret->getKey());
        
        $this->addFlash('success', 'Secret erfolgreich aktiviert.');
        
        return $this->redirectToRoute('app_user_secrets');
    }

    /**
     * Sucht nach Secrets
     */
    #[Route('/search', name: 'app_user_secret_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $query = trim($request->query->get('q'));
        
        if (empty($query)) {
            return $this->redirectToRoute('app_user_secrets');
        }

        $secrets = $this->secretManager->searchSecrets($user, $query);
        
        return $this->render('frontend/settings/secrets/index.html.twig', [
            'secrets' => $secrets,
            'tools_with_secrets' => [],
            'missing_secrets' => [],
            'search_query' => $query
        ]);
    }
}
