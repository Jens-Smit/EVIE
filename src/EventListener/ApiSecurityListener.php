<?php

namespace App\EventListener;

use App\AI\Security\AuditLogger;
use App\AI\Security\SecurityGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;


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
        private AuditLogger $auditLogger,
        private TokenStorageInterface $tokenStorage,
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
        // Bugfix: Request::getUser() liefert nur den HTTP-Basic-User als
        // String, nie ein UserInterface. Der authentifizierte User muss aus
        // dem TokenStorage bezogen werden, sonst wird jede geschuetzte API-
        // Route (auch fuer eingeloggte User) mit AccessDenied abgelehnt.
        $user = null;
        $token = $this->tokenStorage->getToken();
        if (null !== $token) {
            $tokenUser = $token->getUser();
            if ($tokenUser instanceof UserInterface) {
                $user = $tokenUser;
            }
        }

        // Prüfe ob User authentifiziert ist
        if (!$user instanceof UserInterface) {
            throw new AccessDeniedHttpException('Zugriff verweigert: Authentifizierung erforderlich');
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
