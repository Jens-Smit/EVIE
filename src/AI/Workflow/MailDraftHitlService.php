<?php

declare(strict_types=1);

namespace App\AI\Workflow;

use App\AI\Security\AuditLogger;
use App\Entity\AgentHistory;
use App\Entity\MailDraft;
use App\Entity\UserProfile;
use App\Repository\AgentHistoryRepository;
use App\Repository\MailDraftRepository;
use App\Repository\UserProfileRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * HITL-Workflow fuer E-Mail-Entwuerfe (Blueprint §5: keine kritische
 * Aktion ohne menschliche Freigabe).
 *
 * prepareDraft() legt einen persistenten MailDraft mit
 * status=pending_approval an und protokolliert den Schritt in der
 * AgentHistory (Traceability). approveDraft() setzt den Status auf
 * approved und versendet die E-Mail ueber den Symfony Mailer - im
 * Test-Env mit MAILER_DSN=null://null, in Produktion ueber den im
 * Onboarding erfassten SMTP-Transport (mailer.yaml).
 *
 * Jede Statusaenderung wird zusaetzlich ueber den AuditLogger in die
 * audit_logs geschrieben, sodass der Freigabeprozess im Frontend
 * nachvollziehbar ist.
 */
final class MailDraftHitlService
{
    public function __construct(
        private MailDraftRepository $mailDraftRepository,
        private UserProfileRepository $userProfileRepository,
        private AgentHistoryRepository $historyRepository,
        private MailerInterface $mailer,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
        private string $defaultFrom = 'noreply@evie.ai',
    ) {
    }

    /**
     * Legt einen E-Mail-Entwurf zur Freigabe an (kein Versand!).
     *
     * @param array<int, string>                              $recipients
     * @param array<int, array{filename: string, content: string}> $attachments
     * @param array<string, mixed>|null                       $metadata
     */
    public function prepareDraft(
        string $userIdentifier,
        string $subject,
        string $body,
        array $recipients,
        ?string $sender = null,
        array $attachments = [],
        ?array $metadata = null,
        bool $isHtml = false,
    ): MailDraft {
        if ($subject === '' || $body === '' || $recipients === []) {
            throw new \InvalidArgumentException('subject, body und recipients sind fuer einen E-Mail-Entwurf erforderlich.');
        }

        $userProfile = $this->userProfileRepository->findOneBy(['userIdentifier' => $userIdentifier]);
        if ($userProfile === null) {
            throw new \InvalidArgumentException(sprintf('UserProfile fuer user_identifier "%s" nicht gefunden.', $userIdentifier));
        }

        $draft = new MailDraft();
        $draft->setUserIdentifier($userIdentifier);
        $draft->setUserProfile($userProfile);
        $draft->setSubject($subject);
        $draft->setBody($body);
        $draft->setRecipients($recipients);
        $draft->setSender($sender);
        $draft->setAttachments($attachments);
        $draft->setMetadata($metadata);
        $draft->setIsHtml($isHtml);
        $draft->setStatus(MailDraft::STATUS_PENDING);
        $this->mailDraftRepository->save($draft, true);

        $this->logHistory(
            $userProfile,
            'mail_draft_prepared',
            [
                'agent' => 'mail_draft_hitl',
                'status' => MailDraft::STATUS_PENDING,
                'input' => ['subject' => $subject, 'recipients' => $recipients],
                'output' => ['mail_draft_id' => $draft->getId()],
            ],
        );

        return $draft;
    }

    /**
     * Genehmigt den Entwurf und versendet die E-Mail.
     *
     * Der Entwurf wird zunaechst auf approved gesetzt und danach ueber den
     * Mailer versendet; erst nach erfolgreichem Versand wird der Status
     * auf sent gesetzt. Ein Fehlschlag beim Versand laesst den Entwurf im
     * approved-Zustand (idempotentes erneutes Versenden moeglich).
     */
    public function approveDraft(MailDraft $draft): MailDraft
    {
        if (!$draft->isPending()) {
            throw new \LogicException('Nur ausstehende Entwuerfe koennen freigegeben werden.');
        }

        $draft->setStatus(MailDraft::STATUS_APPROVED);
        $draft->setApprovedAt(new \DateTimeImmutable());
        $this->mailDraftRepository->save($draft, true);

        $email = new Email();
        $email->from($draft->getSender() ?? $this->defaultFrom);
        $email->to(...$draft->getRecipients());
        $email->subject($draft->getSubject());
        $draft->isHtml() ? $email->html($draft->getBody()) : $email->text($draft->getBody());
        foreach ($draft->getAttachments() as $attachment) {
            $email->attach($attachment['content'], $attachment['filename']);
        }

        try {
            $this->mailer->send($email);
            $draft->setStatus(MailDraft::STATUS_SENT);
            $draft->setSentAt(new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $this->logger->error('E-Mail-Versand nach HITL-Freigabe fehlgeschlagen', [
                'mail_draft_id' => $draft->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $this->mailDraftRepository->save($draft, true);
        }

        $userProfile = $draft->getUserProfile();
        if ($userProfile !== null) {
            $this->logHistory(
                $userProfile,
                'mail_draft_approved',
                [
                    'agent' => 'mail_draft_hitl',
                    'status' => $draft->getStatus(),
                    'input' => ['mail_draft_id' => $draft->getId()],
                    'output' => ['sent_at' => $draft->getSentAt()?->format(\DATE_ATOM)],
                ],
            );
        }

        $this->auditLogger->log(
            'hitl_decision',
            null,
            (int) ($draft->getId() ?? 0),
            'MailDraft',
            ['tool_name' => 'mail_draft', 'decision' => 'approved'],
            'success',
        );

        return $draft;
    }

    /**
     * Lehnt den Entwurf ab (kein Versand, Begrundung obligatorisch).
     */
    public function rejectDraft(MailDraft $draft, string $reason): MailDraft
    {
        if (!$draft->isPending()) {
            throw new \LogicException('Nur ausstehende Entwuerfe koennen abgelehnt werden.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Eine Ablehnung erfordert eine Begruendung (reason).');
        }

        $draft->setStatus(MailDraft::STATUS_REJECTED);
        $draft->setRejectionReason(trim($reason));
        $this->mailDraftRepository->save($draft, true);

        $userProfile = $draft->getUserProfile();
        if ($userProfile !== null) {
            $this->logHistory(
                $userProfile,
                'mail_draft_rejected',
                [
                    'agent' => 'mail_draft_hitl',
                    'status' => MailDraft::STATUS_REJECTED,
                    'input' => ['mail_draft_id' => $draft->getId()],
                    'output' => ['reason' => trim($reason)],
                ],
            );
        }

        $this->auditLogger->log(
            'hitl_decision',
            null,
            (int) ($draft->getId() ?? 0),
            'MailDraft',
            ['tool_name' => 'mail_draft', 'decision' => 'rejected'],
            'success',
            trim($reason),
        );

        return $draft;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function logHistory(UserProfile $userProfile, string $action, array $details): void
    {
        $historyEntry = new AgentHistory();
        $historyEntry->setAction($action);
        $historyEntry->setDetails(json_encode($details, \JSON_THROW_ON_ERROR));
        $historyEntry->setUser($userProfile);
        $this->historyRepository->save($historyEntry, true);
    }
}
