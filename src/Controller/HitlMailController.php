<?php

namespace App\Controller;

use App\AI\Workflow\MailDraftHitlService;
use App\Entity\MailDraft;
use App\Repository\MailDraftRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Psr\Log\LoggerInterface;

/**
 * Controller fuer den Human-in-the-Loop-Freigabe-Workflow von E-Mail-
 * Entwuerfen (Blueprint §5): Agenten legen Entwuerfe an (status
 * pending_approval), der Nutzer genehmigt oder lehnt hier ab. Erst nach
 * Freigabe versendet der MailDraftHitlService die E-Mail.
 *
 * Der Tenant-Zugriff folgt dem P0-5-Muster: userIdentifier wird
 * ausschliesslich aus dem authentifizierten User bezogen, nie aus dem
 * Request-Body (IDOR-Schutz).
 */
#[Route('/tools/mail-drafts')]
final class HitlMailController extends AbstractController
{
    public function __construct(
        private MailDraftRepository $mailDraftRepository,
        private MailDraftHitlService $hitlService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ausstehende E-Mail-Entwuerfe des authentifizierten Tenants.
     */
    #[Route('', name: 'app_mail_draft_list', methods: ['GET'])]
    public function listPending(): JsonResponse
    {
        $userIdentifier = $this->requireUserIdentifier();
        $drafts = $this->mailDraftRepository->findPendingByUserIdentifier($userIdentifier);

        return $this->json([
            'status' => 'success',
            'drafts' => array_map(static fn (MailDraft $draft): array => [
                'id' => $draft->getId(),
                'subject' => $draft->getSubject(),
                'body' => $draft->getBody(),
                'recipients' => $draft->getRecipients(),
                'sender' => $draft->getSender(),
                'attachments' => array_map(
                    static fn (array $attachment): string => (string) ($attachment['filename'] ?? ''),
                    $draft->getAttachments(),
                ),
                'status' => $draft->getStatus(),
                'created_at' => $draft->getCreatedAt()->format(DATE_ATOM),
            ], $drafts),
        ]);
    }

    /**
     * Genehmigt einen E-Mail-Entwurf und loest den Versand aus.
     */
    #[Route('/{id}/approve', name: 'app_mail_draft_approve', methods: ['POST'])]
    public function approve(MailDraft $draft): JsonResponse
    {
        try {
            $this->assertOwnership($draft);

            $draft = $this->hitlService->approveDraft($draft);

            return $this->json([
                'status' => 'success',
                'message' => 'E-Mail-Entwurf freigegeben und versendet',
                'draft' => [
                    'id' => $draft->getId(),
                    'status' => $draft->getStatus(),
                    'sent_at' => $draft->getSentAt()?->format(DATE_ATOM),
                ],
            ]);
        } catch (AccessDeniedException $e) {
            throw $e;
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Freigeben des E-Mail-Entwurfs: ' . $e->getMessage());
            return $this->json([
                'status' => 'error',
                'message' => 'Fehler beim Freigeben des E-Mail-Entwurfs',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Lehnt einen E-Mail-Entwurf ab (kein Versand).
     */
    #[Route('/{id}/reject', name: 'app_mail_draft_reject', methods: ['POST'])]
    public function reject(Request $request, MailDraft $draft): JsonResponse
    {
        try {
            $this->assertOwnership($draft);

            $reason = trim((string) $request->request->get('reason', ''));
            if ($reason === '' && str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')) {
                $reason = trim((string) ($request->toArray()['reason'] ?? ''));
            }

            $draft = $this->hitlService->rejectDraft($draft, $reason);

            return $this->json([
                'status' => 'success',
                'message' => 'E-Mail-Entwurf abgelehnt',
                'draft' => [
                    'id' => $draft->getId(),
                    'status' => $draft->getStatus(),
                    'rejection_reason' => $draft->getRejectionReason(),
                ],
            ]);
        } catch (AccessDeniedException $e) {
            throw $e;
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Ablehnen des E-Mail-Entwurfs: ' . $e->getMessage());
            return $this->json([
                'status' => 'error',
                'message' => 'Fehler beim Ablehnen des E-Mail-Entwurfs',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function assertOwnership(MailDraft $draft): void
    {
        $userIdentifier = $this->requireUserIdentifier();
        if ($draft->getUserIdentifier() !== $userIdentifier) {
            throw $this->createAccessDeniedException('Dieser E-Mail-Entwurf gehoert einem anderen Tenant.');
        }
    }

    private function requireUserIdentifier(): string
    {
        $authenticatedUser = $this->getUser();
        if ($authenticatedUser === null) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        return $authenticatedUser->getUserIdentifier();
    }
}
