<?php
// src/AI/Skills/Tool/EmailTool.php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Tool für das Senden von E-Mails.
 * Ermöglicht dem AI-Agenten, E-Mails zu versenden.
 */
class EmailTool
{
    public function __construct(
        private MailerInterface $mailer,
        private string $defaultFrom = 'noreply@evie.ai'
    ) {
    }

    /**
     * Sendet eine E-Mail - HAUPTMETHODE als Tool
     */
    #[AsTool(
        name: 'send_email',
        description: 'Sendet eine E-Mail an einen oder mehrere Empfänger. Unterstützt HTML und Text-Inhalte.'
    )]
    public function __invoke(
        array $to,
        string $subject,
        string $body,
        ?string $from = null,
        ?string $cc = null,
        ?string $bcc = null,
        bool $isHtml = false
    ): array {
        try {
            $email = (new Email())
                ->from($from ?? $this->defaultFrom)
                ->to(...$to)
                ->subject($subject);

            if ($isHtml) {
                $email->html($body);
            } else {
                $email->text($body);
            }

            if ($cc) {
                $email->cc($cc);
            }
            if ($bcc) {
                $email->bcc($bcc);
            }

            $this->mailer->send($email);

            return [
                'status' => 'success',
                'message' => 'E-Mail erfolgreich gesendet',
                'to' => $to,
                'subject' => $subject,
                'sent_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Fehler beim Senden der E-Mail: ' . $e->getMessage(),
                'to' => $to,
                'subject' => $subject,
            ];
        }
    }

    /**
     * Hilfsmethode: Sendet eine E-Mail mit Template (wird intern aufrufen)
     */
    public function sendTemplatedEmail(
        array $to,
        string $subject,
        string $templatePath,
        array $templateData = [],
        ?string $from = null
    ): array {
        try {
            $body = $this->renderTemplate($templatePath, $templateData);
            return $this->__invoke($to, $subject, $body, $from, null, null, true);
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Fehler beim Senden der Template-E-Mail: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Hilfsmethode: Liest E-Mails (Platzhalter)
     */
    public function readEmails(
        string $mailbox = 'INBOX',
        int $limit = 10,
        bool $unreadOnly = true
    ): array {
        return [
            'status' => 'warning',
            'message' => 'IMAP-Funktionalität nicht implementiert.',
            'mailbox' => $mailbox,
            'limit' => $limit,
            'unread_only' => $unreadOnly,
        ];
    }

    /**
     * Hilfsmethode: Sucht nach E-Mails (Platzhalter)
     */
    public function searchEmails(
        string $query,
        string $mailbox = 'INBOX',
        int $limit = 10
    ): array {
        return [
            'status' => 'warning',
            'message' => 'E-Mail-Suche nicht implementiert.',
            'query' => $query,
        ];
    }

    /**
     * Rendert ein Template (Platzhalter)
     */
    private function renderTemplate(string $templatePath, array $data): string
    {
        $body = file_get_contents($templatePath) ?? '';
        foreach ($data as $key => $value) {
            $body = str_replace("{{ $key }}", $value, $body);
        }
        return $body;
    }
}
