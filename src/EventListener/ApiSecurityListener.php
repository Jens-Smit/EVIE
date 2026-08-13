<?php

namespace AppEventListener;

use AppAISecurityAuditLogger;
use AppAISecuritySecurityGuard;
use SymfonyComponentHttpFoundationJsonResponse;
use SymfonyComponentHttpKernelEventControllerEvent;
use SymfonyComponentHttpKernelKernelEvents;
use SymfonyComponentEventDispatcherAttributeAsEventListener;
use SymfonyComponentSecurityCoreUserUserInterface;

#[AsEventListener(event: KernelEvents::CONTROLLER, method: 'onKernelController', priority: 10)]
class ApiSecurityListener
{
    private array $protectedRoutes = [
        '/api/tools',
        '/api/admin',
        '/api/hitl',
    ];

    public function __construct(
        private SecurityGuard $securityGuard,
        private AuditLogger $auditLogger
    ) {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $path = $request->getPathInfo();

        // Prüfe ob es eine geschützte Route ist
        foreach ($this->protectedRoutes as $protectedRoute) {
            if (str_starts_with($path, $protectedRoute)) {
                $this->checkApiAccess($request, $path);
                break;
            }
        }
    }

    private function checkApiAccess($request, string $path): void
    {
        $user = $request->getUser();
        
        // Prüfe ob User authentifiziert ist
        if (!$user instanceof UserInterface) {
            throw new SymfonyComponentHttpKernelExceptionAccessDeniedHttpException('Zugriff verweigert: Authentifizierung erforderlich');
        }

        // Logge API-Aufruf
        $this->auditLogger->logApiCall(
            $path,
            $user,
            true,
            $request->query->all() + $request->request->all()
        );
    }
}
