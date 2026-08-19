<?php

namespace App\Controller\Frontend;

use App\AI\LLM\LLMProviderFactory;
use App\Entity\LLMConfiguration;
use App\Entity\User;
use App\Repository\LLMConfigurationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller für LLM-Konfigurationen in den Settings
 */
#[Route('/settings/llm')]
#[IsGranted('ROLE_USER')]
class LLMConfigController extends AbstractController
{
    public function __construct(
        private LLMProviderFactory $providerFactory,
        private LLMConfigurationRepository $configRepo,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Zeigt die LLM-Konfigurationen an
     */
    #[Route('/', name: 'app_llm_settings')]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $configurations = $this->configRepo->findAllForUser($user);
        $providers = $this->providerFactory->getAvailableProviders();
        
        // Modelle pro Provider
        $modelsByProvider = [];
        foreach ($providers as $provider) {
            $modelsByProvider[$provider] = $this->providerFactory->getAvailableModels($provider);
        }

        // Default-Konfiguration
        $defaultConfig = $this->configRepo->findDefaultForUser($user);

        return $this->render('frontend/settings/llm/index.html.twig', [
            'configurations' => $configurations,
            'providers' => $providers,
            'modelsByProvider' => $modelsByProvider,
            'defaultConfig' => $defaultConfig,
            'currentProvider' => $defaultConfig ? $defaultConfig->getProvider() : $this->providerFactory->getDefaultConfiguration()['provider'],
            'currentModel' => $defaultConfig ? $defaultConfig->getModel() : $this->providerFactory->getDefaultConfiguration()['model'],
        ]);
    }

    /**
     * Speichert eine neue LLM-Konfiguration
     */
    #[Route('/save', name: 'app_llm_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $provider = $request->request->get('provider');
        $model = $request->request->get('model');
        $apiKey = $request->request->get('api_key');
        $isDefault = $request->request->get('is_default', false);
        $customProviderName = $request->request->get('custom_provider_name');
        $customApiUrl = $request->request->get('custom_api_url');

        // Validierung
        if (!$provider || !$model) {
            $this->addFlash('error', 'Bitte wählen Sie einen Anbieter und ein Modell aus.');
            return $this->redirectToRoute('app_llm_settings');
        }

        // Prüfe, ob der Provider verfügbar ist
        if (!$this->providerFactory->hasProvider($provider)) {
            $this->addFlash('error', 'Unbekannter Anbieter.');
            return $this->redirectToRoute('app_llm_settings');
        }

        // Prüfe, ob das Modell für den Provider verfügbar ist (außer für custom)
        if ($provider !== 'custom' && !$this->providerFactory->hasModel($provider, $model)) {
            $this->addFlash('error', 'Das ausgewählte Modell ist für diesen Anbieter nicht verfügbar.');
            return $this->redirectToRoute('app_llm_settings');
        }

        // Speichere die Konfiguration
        $config = $this->providerFactory->createConfiguration(
            $user,
            $provider,
            $model,
            $apiKey ?: null,
            $customProviderName ?: null,
            $customApiUrl ?: null,
            $isDefault
        );

        // Falls dies die Standard-Konfiguration ist, setze sie als Standard
        if ($isDefault) {
            $this->configRepo->setAsDefault($config);
        }

        $this->addFlash('success', 'LLM-Konfiguration erfolgreich gespeichert.');
        
        return $this->redirectToRoute('app_llm_settings');
    }

    /**
     * Löscht eine LLM-Konfiguration
     */
    #[Route('/delete/{id}', name: 'app_llm_delete', methods: ['POST'])]
    public function delete(LLMConfiguration $config): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Prüfe, ob der User die Konfiguration besitzt
        if ($config->getUser() !== $user) {
            $this->addFlash('error', 'Sie können nur Ihre eigenen Konfigurationen löschen.');
            return $this->redirectToRoute('app_llm_settings');
        }

        $this->providerFactory->deleteConfiguration($config);
        
        $this->addFlash('success', 'LLM-Konfiguration erfolgreich gelöscht.');
        
        return $this->redirectToRoute('app_llm_settings');
    }

    /**
     * Setzt eine Konfiguration als Standard
     */
    #[Route('/set-default/{id}', name: 'app_llm_set_default', methods: ['POST'])]
    public function setDefault(LLMConfiguration $config): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Prüfe, ob der User die Konfiguration besitzt
        if ($config->getUser() !== $user) {
            $this->addFlash('error', 'Sie können nur Ihre eigenen Konfigurationen als Standard setzen.');
            return $this->redirectToRoute('app_llm_settings');
        }

        $this->configRepo->setAsDefault($config);
        
        $this->addFlash('success', 'Standard-LLM-Konfiguration erfolgreich gesetzt.');
        
        return $this->redirectToRoute('app_llm_settings');
    }

    /**
     * Gibt die verfügbaren Modelle für einen Provider zurück (AJAX)
     */
    #[Route('/models/{provider}', name: 'app_llm_models', methods: ['GET'])]
    public function getModels(string $provider): Response
    {
        if (!$this->providerFactory->hasProvider($provider)) {
            return $this->json(['error' => 'Unbekannter Anbieter']);
        }

        $models = $this->providerFactory->getAvailableModels($provider);
        
        return $this->json(['models' => $models]);
    }
}
