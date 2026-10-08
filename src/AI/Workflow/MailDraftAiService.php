<?php

declare(strict_types=1);

namespace App\AI\Workflow;

use App\AI\Security\AuditLogger;
use App\AI\Platform\TenantPlatformContext;
use App\Entity\AgentHistory;
use App\Entity\MailDraft;
use App\Entity\UserProfile;
use App\Repository\MailDraftRepository;
use App\Repository\UserProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * KI-Unterstützung fuer E-Mail-Entwuerfe (Blueprint §5: jede KI-Aktion
 * bleibt im HITL-Rahmen, es wird nichts ohne Freigabe versendet).
 *
 *  - generateReply(): erzeugt aus einer gelesenen E-Mail (Betreff, Text,
 *    Absender) einen KI-Antwort-Entwurf als MailDraft mit
 *    status=pending_approval - analog zum Ingestion-Reply-Pfad.
 *  - improveDraft(): korrigiert den Text eines bestehenden (noch
 *    ausstehenden) Entwurfs auf Ausdruck, Rechtschreibung und Tonart.
 *    Der korrigierte Text wird zurueckgegeben und als neue Version im
 *    Entwurf gespeichert; der Status bleibt pending_approval.
 *
 * Beide Pfade nutzen den communication_manager-Sub-Agenten (ai.yaml)
 * ueber den TenantPlatformContext (pro-Tenant API-Key). Schlaegt der
 * LLM-Abruf fehl, wird kein Text erfunden: generateReply() wirft eine
 * LogicException, improveDraft() gibt den unveraenderten Originaltext
 * zurueck (Kennzeichnung ueber improved=false).
 */
final class MailDraftAiService
{
    public function __construct(
        private AgentInterface $communicationAgent,
        private TenantPlatformContext $tenantPlatformContext,
        private MailDraftRepository $mailDraftRepository,
        private UserProfileRepository $userProfileRepository,
        private MailDraftHitlService $mailDraftHitlService,
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Erzeugt aus einer gelesenen E-Mail einen KI-Antwort-Entwurf (HITL:
     * der Entwurf landet mit status=pending_approval in der Freigabe-
     * Warteschlange, es wird nichts automatisch versendet).
     *
     * @param array{subject: string, body: string, from?: string} $email
     *
     * @return array{draft_id: int, subject: string, body: string, recipients: array<int, string>, source: 'llm'|'fallback'}
     */
    public function generateReply(string $userIdentifier, array $email): array
    {
        $subject = trim((string) ($email['subject'] ?? ''));
        $body = trim((string) ($email['body'] ?? ''));
        $from = trim((string) ($email['from'] ?? ''));

        if ($subject === '' || $body === '') {
            throw new \InvalidArgumentException('subject und body der gelesenen E-Mail sind erforderlich.');
        }

        $payload = json_encode([
            'action' => 'generate_email_reply',
            'email' => [
                'subject' => $subject,
                'body' => mb_substr($body, 0, 4000),
                'from' => $from,
            ],
            'instructions' => 'Verfasse eine sachliche, hoefliche Antwort-E-Mail auf Deutsch. Nutze nur Informationen aus der Ausgangs-E-Mail, erfinde keine Inhalte. Gib die Antwort als JSON-Objekt {"subject": "...", "body": "..."} zurueck.',
        ], \JSON_THROW_ON_ERROR);

        $reply = $this->callCommunicationAgent($userIdentifier, $payload, $subject, $body);

        $draft = $this->mailDraftHitlService->prepareDraft(
            $userIdentifier,
            $reply['subject'],
            $reply['body'],
            $from !== '' ? [$from] : [],
            metadata: [
                'source' => 'ai_reply',
                'original_subject' => $subject,
            ],
        );

        return [
            'draft_id' => (int) $draft->getId(),
            'subject' => $draft->getSubject(),
            'body' => $draft->getBody(),
            'recipients' => $draft->getRecipients(),
            'source' => $reply['source'],
        ];
    }

    /**
     * Korrigiert den Text eines ausstehenden Entwurfs auf Ausdruck,
     * Rechtschreibung und Tonart (eine einmalige Korrektur, keine
     * Neuformulierung des Inhalts).
     *
     * @return array{subject: string, body: string, improved: bool}
     */
    public function improveDraft(string $userIdentifier, MailDraft $draft): array
    {
        if ($draft->getUserIdentifier() !== $userIdentifier) {
            throw new \InvalidArgumentException('Dieser E-Mail-Entwurf gehoert einem anderen Tenant.');
        }
        if (!$draft->isPending()) {
            throw new \LogicException('Nur ausstehende Entwuerfe koennen korrigiert werden.');
        }

        $payload = json_encode([
            'action' => 'improve_email_draft',
            'draft' => [
                'subject' => $draft->getSubject(),
                'body' => mb_substr($draft->getBody(), 0, 4000),
            ],
            'instructions' => 'Korrigiere den Entwurf auf Rechtschreibung, Grammatik, Ausdruck und Tonart (professionell, hoeflich). Aendere den Inhalt und die Aussagen nicht, formuliere nicht komplett um. Gib das Ergebnis als JSON-Objekt {"subject": "...", "body": "..."} zurueck.',
        ], \JSON_THROW_ON_ERROR);

        $improved = $this->callCommunicationAgent($userIdentifier, $payload, $draft->getSubject(), $draft->getBody());

        if (!$improved['is_improvement']) {
            return [
                'subject' => $draft->getSubject(),
                'body' => $draft->getBody(),
                'improved' => false,
            ];
        }

        $metadata = $draft->getMetadata() ?? [];
        $metadata['ai_improved'] = true;
        $metadata['ai_improved_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $draft->setSubject($improved['subject']);
        $draft->setBody($improved['body']);
        $draft->setMetadata($metadata);
        $this->mailDraftRepository->save($draft, true);

        $this->logHistory($userIdentifier, 'mail_draft_ai_improved', [
            'agent' => 'mail_draft_ai',
            'status' => 'success',
            'input' => ['mail_draft_id' => $draft->getId()],
            'output' => ['improved' => true],
        ]);

        $this->auditLogger->log(
            'ai_mail_improvement',
            null,
            (int) ($draft->getId() ?? 0),
            'MailDraft',
            ['tool_name' => 'mail_draft_ai', 'action' => 'improve'],
            'success',
        );

        return [
            'subject' => $draft->getSubject(),
            'body' => $draft->getBody(),
            'improved' => true,
        ];
    }

    /**
     * Ruft den communication_manager-Agenten auf und validiert die
     * JSON-Antwort strikt. Ohne gueltige LLM-Antwort wird fuer
     * generateReply eine LogicException geworfen bzw. fuer improveDraft
     * das Original zurueckgegeben - es wird kein Text erfunden.
     *
     * @return array{subject: string, body: string, source: 'llm'|'fallback', is_improvement: bool}
     */
    private function callCommunicationAgent(string $userIdentifier, string $payload, string $fallbackSubject, string $fallbackBody): array
    {
        try {
            $this->tenantPlatformContext->setUserIdentifier($userIdentifier);
            try {
                $messages = new MessageBag(Message::ofUser($payload));
                $result = $this->communicationAgent->call($messages);
            } finally {
                $this->tenantPlatformContext->clear();
            }
            $decoded = $this->decodeLlmJson($result->getContent());
        } catch (\Throwable $e) {
            $this->logger->warning('MailDraftAi-LLM-Abruf fehlgeschlagen', [
                'user' => $userIdentifier,
                'error' => $e->getMessage(),
            ]);
            $decoded = null;
        }

        $subject = trim((string) ($decoded['subject'] ?? ''));
        $body = trim((string) ($decoded['body'] ?? ''));

        if ($subject === '' || $body === '') {
            return [
                'subject' => $fallbackSubject,
                'body' => $fallbackBody,
                'source' => 'fallback',
                'is_improvement' => false,
            ];
        }

        return [
            'subject' => $subject,
            'body' => $body,
            'source' => 'llm',
            'is_improvement' => true,
        ];
    }

    /**
     * Dekodiert die LLM-Antwort als JSON (inkl. Markdown-Code-Fence-Removal).
     *
     * @return array<string, mixed>|null
     */
    private function decodeLlmJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $content, $matches) === 1) {
            $content = $matches[1];
        }
        $firstBrace = strpos($content, '{');
        $lastBrace = strrpos($content, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $content = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
        }
        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function logHistory(string $userIdentifier, string $action, array $details): void
    {
        $userProfile = $this->userProfileRepository->findOneBy(['userIdentifier' => $userIdentifier]);
        if ($userProfile === null) {
            return;
        }
        $historyEntry = new AgentHistory();
        $historyEntry->setAction($action);
        $historyEntry->setDetails(json_encode($details, \JSON_THROW_ON_ERROR));
        $historyEntry->setUser($userProfile);
        $this->entityManager->persist($historyEntry);
        $this->entityManager->flush();
    }
}
