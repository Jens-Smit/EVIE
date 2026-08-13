<?php

namespace App\EventListener;

use App\AI\Security\AuditLogger;
use App\AI\Security\SecurityGuard;
use App\AI\Skills\Tool\DynamicTool;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: KernelEvents::VIEW, method: 'onKernelView', priority: 5)]
class ToolSecurityListener
{
    public function __construct(
        private SecurityGuard $securityGuard,
        private AuditLogger $auditLogger
    ) {
    }

    public function onKernelView(ViewEvent $event): void
    {
        $request = $event->getRequest();
        $controllerResult = $event->getControllerResult();

        // Prüfe ob es sich um eine Tool-Execution handelt
        if ($controllerResult instanceof DynamicTool) {
            $tool = $controllerResult;
            
            // Prüfe ob Tool sicher ist
            if (!$this->securityGuard->isToolSafe($tool)) {
                $user = $request->getUser();
                $this->auditLogger->logSecurityViolation(
                    'unsafe_tool_execution',
                    $user,
                    'Tool hat nicht erlaubten Executor-Type: ' . ($tool->getExecutorType() ?? 'null'),
                    ['tool_name' => $tool->getName()]
                );
                
                throw new BadRequestHttpException(
                    'Tool kann nicht ausgeführt werden: Sicherheitsrichtlinien verweigern die Ausführung.'
                );
            }
        }
    }
}
