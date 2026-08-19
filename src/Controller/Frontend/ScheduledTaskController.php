<?php

namespace App\Controller\Frontend;

use App\AI\Tasks\ScheduledTaskManager;
use App\Entity\ScheduledTask;
use App\Entity\User;
use App\Repository\ScheduledTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller für geplante Aufgaben in den Settings
 */
#[Route('/settings/scheduled-tasks')]
#[IsGranted('ROLE_USER')]
class ScheduledTaskController extends AbstractController
{
    public function __construct(
        private ScheduledTaskManager $taskManager,
        private ScheduledTaskRepository $taskRepo,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Zeigt alle geplanten Aufgaben an
     */
    #[Route('/', name: 'app_scheduled_tasks')]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tasks = $this->taskRepo->findAllForUser($user);
        $pendingTasks = $this->taskRepo->findPendingForUser($user);
        $recurringTasks = $this->taskRepo->findRecurringForUser($user);

        return $this->render('frontend/settings/scheduled_tasks/index.html.twig', [
            'tasks' => $tasks,
            'pending_tasks' => $pendingTasks,
            'recurring_tasks' => $recurringTasks
        ]);
    }

    /**
     * Zeigt das Formular zum Erstellen einer neuen Aufgabe
     */
    #[Route('/create', name: 'app_scheduled_task_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $taskDescription = trim($request->request->get('task_description'));
            $taskType = $request->request->get('task_type');
            $scheduledAt = $request->request->get('scheduled_at');
            $isRecurring = $request->request->get('is_recurring', false);
            $recurrencePattern = $request->request->get('recurrence_pattern');
            $recurrenceInterval = $request->request->get('recurrence_interval', 1);

            // Validierung
            if (empty($taskDescription)) {
                $this->addFlash('error', 'Bitte geben Sie eine Aufgabenbeschreibung ein.');
                return $this->redirectToRoute('app_scheduled_task_create');
            }

            if (empty($taskType)) {
                $this->addFlash('error', 'Bitte wählen Sie einen Aufgabentyp aus.');
                return $this->redirectToRoute('app_scheduled_task_create');
            }

            if (empty($scheduledAt)) {
                $this->addFlash('error', 'Bitte geben Sie ein Datum und eine Uhrzeit an.');
                return $this->redirectToRoute('app_scheduled_task_create');
            }

            try {
                $scheduledAtDate = new \DateTimeImmutable($scheduledAt);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Ungültiges Datum/Uhrzeit-Format.');
                return $this->redirectToRoute('app_scheduled_task_create');
            }

            // Erstelle die Aufgabe
            $task = $this->taskManager->createTask(
                $user,
                $taskDescription,
                $taskType,
                [],
                $scheduledAtDate,
                $isRecurring,
                $recurrencePattern,
                $recurrenceInterval
            );

            $this->addFlash('success', 'Geplante Aufgabe erfolgreich erstellt.');
            
            return $this->redirectToRoute('app_scheduled_tasks');
        }

        // Zeige das Formular
        return $this->render('frontend/settings/scheduled_tasks/create.html.twig', [
            'taskTypes' => [
                'check_email' => 'E-Mails prüfen',
                'create_briefing' => 'Briefing erstellen',
                'custom' => 'Benutzerdefiniert'
            ],
            'recurrencePatterns' => [
                'daily' => 'Täglich',
                'weekly' => 'Wöchentlich',
                'monthly' => 'Monatlich',
                'hourly' => 'Stündlich'
            ]
        ]);
    }

    /**
     * Zeigt die Details einer Aufgabe
     */
    #[Route('/{id}', name: 'app_scheduled_task_show', methods: ['GET'])]
    public function show(ScheduledTask $task): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Prüfe, ob der User die Aufgabe besitzt
        if ($task->getUser() !== $user) {
            $this->addFlash('error', 'Sie können nur Ihre eigenen Aufgaben anzeigen.');
            return $this->redirectToRoute('app_scheduled_tasks');
        }

        return $this->render('frontend/settings/scheduled_tasks/show.html.twig', [
            'task' => $task
        ]);
    }

    /**
     * Bearbeitet eine Aufgabe
     */
    #[Route('/{id}/edit', name: 'app_scheduled_task_edit', methods: ['GET', 'POST'])]
    public function edit(ScheduledTask $task, Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Prüfe, ob der User die Aufgabe besitzt
        if ($task->getUser() !== $user) {
            $this->addFlash('error', 'Sie können nur Ihre eigenen Aufgaben bearbeiten.');
            return $this->redirectToRoute('app_scheduled_tasks');
        }

        if ($request->isMethod('POST')) {
            $taskDescription = trim($request->request->get('task_description'));
            $taskType = $request->request->get('task_type');
            $scheduledAt = $request->request->get('scheduled_at');
            $isRecurring = $request->request->get('is_recurring', false);
            $recurrencePattern = $request->request->get('recurrence_pattern');
            $recurrenceInterval = $request->request->get('recurrence_interval', 1);

            // Validierung
            if (empty($taskDescription)) {
                $this->addFlash('error', 'Bitte geben Sie eine Aufgabenbeschreibung ein.');
                return $this->redirectToRoute('app_scheduled_task_edit', ['id' => $task->getId()]);
            }

            if (empty($taskType)) {
                $this->addFlash('error', 'Bitte wählen Sie einen Aufgabentyp aus.');
                return $this->redirectToRoute('app_scheduled_task_edit', ['id' => $task->getId()]);
            }

            if (empty($scheduledAt)) {
                $this->addFlash('error', 'Bitte geben Sie ein Datum und eine Uhrzeit an.');
                return $this->redirectToRoute('app_scheduled_task_edit', ['id' => $task->getId()]);
            }

            try {
                $scheduledAtDate = new \DateTimeImmutable($scheduledAt);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Ungültiges Datum/Uhrzeit-Format.');
                return $this->redirectToRoute('app_scheduled_task_edit', ['id' => $task->getId()]);
            }

            // Aktualisiere die Aufgabe
            $task->setTaskDescription($taskDescription);
            $task->setTaskType($taskType);
            $task->setScheduledAt($scheduledAtDate);
            $task->setIsRecurring($isRecurring);
            $task->setRecurrencePattern($recurrencePattern);
            $task->setRecurrenceInterval($recurrenceInterval);

            $this->entityManager->flush();

            $this->addFlash('success', 'Aufgabe erfolgreich aktualisiert.');
            
            return $this->redirectToRoute('app_scheduled_tasks');
        }

        // Zeige das Formular
        return $this->render('frontend/settings/scheduled_tasks/edit.html.twig', [
            'task' => $task,
            'taskTypes' => [
                'check_email' => 'E-Mails prüfen',
                'create_briefing' => 'Briefing erstellen',
                'custom' => 'Benutzerdefiniert'
            ],
            'recurrencePatterns' => [
                'daily' => 'Täglich',
                'weekly' => 'Wöchentlich',
                'monthly' => 'Monatlich',
                'hourly' => 'Stündlich'
            ]
        ]);
    }

    /**
     * Löscht eine Aufgabe
     */
    #[Route('/{id}/delete', name: 'app_scheduled_task_delete', methods: ['POST'])]
    public function delete(ScheduledTask $task): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Prüfe, ob der User die Aufgabe besitzt
        if ($task->getUser() !== $user) {
            $this->addFlash('error', 'Sie können nur Ihre eigenen Aufgaben löschen.');
            return $this->redirectToRoute('app_scheduled_tasks');
        }

        $this->taskManager->deleteTask($task);
        
        $this->addFlash('success', 'Aufgabe erfolgreich gelöscht.');
        
        return $this->redirectToRoute('app_scheduled_tasks');
    }

    /**
     * Aktiviert/Deaktiviert eine Aufgabe
     */
    #[Route('/{id}/toggle', name: 'app_scheduled_task_toggle', methods: ['POST'])]
    public function toggle(ScheduledTask $task): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Prüfe, ob der User die Aufgabe besitzt
        if ($task->getUser() !== $user) {
            $this->addFlash('error', 'Sie können nur Ihre eigenen Aufgaben aktivieren/deaktivieren.');
            return $this->redirectToRoute('app_scheduled_tasks');
        }

        if ($task->isActive()) {
            $this->taskManager->cancelTask($task);
            $this->addFlash('success', 'Aufgabe erfolgreich deaktiviert.');
        } else {
            $this->taskManager->activateTask($task);
            $this->addFlash('success', 'Aufgabe erfolgreich aktiviert.');
        }
        
        return $this->redirectToRoute('app_scheduled_tasks');
    }

    /**
     * Führt eine Aufgabe sofort aus
     */
    #[Route('/{id}/execute', name: 'app_scheduled_task_execute', methods: ['POST'])]
    public function execute(ScheduledTask $task): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Prüfe, ob der User die Aufgabe besitzt
        if ($task->getUser() !== $user) {
            $this->addFlash('error', 'Sie können nur Ihre eigenen Aufgaben ausführen.');
            return $this->redirectToRoute('app_scheduled_tasks');
        }

        // Führe die Aufgabe aus
        $this->taskManager->executeTask($task);
        
        $this->addFlash('success', 'Aufgabe wird ausgeführt.');
        
        return $this->redirectToRoute('app_scheduled_tasks');
    }
}
