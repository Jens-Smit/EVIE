<?php
// src/AI/Skills/Tool/EmailTool.php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Tool für das Senden und Verwalten von E-Mails.
 * Ermöglicht dem AI-Agenten, E-Mails zu versenden, zu lesen und zu verwalten.
 */
class EmailTool
{
    public function __construct(
        private MailerInterface $mailer,
        private string $defaultFrom = 'noreply@evie.ai'
    ) {
    }

    /**
     * Sendet eine E-Mail
     */
    #[AsTool(
        name: 'send_email',
        description: 'Sendet eine E-Mail an einen oder mehrere Empfänger. Unterstützt HTML und Text-Inhalte.'
    )]
    public function sendEmail(
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
     * Sendet eine E-Mail mit Template
     */
    #[AsTool(
        name: 'send_templated_email',
        description: 'Sendet eine E-Mail mit einem Twig-Template.'
    )]
    public function sendTemplatedEmail(
        array $to,
        string $subject,
        string $templatePath,
        array $templateData = [],
        ?string $from = null
    ): array {
        try {
            // Hier würde man normalerweise das Template rendern
            // Für jetzt: Einfache Implementierung
            $body = $this->renderTemplate($templatePath, $templateData);

            $email = (new Email())
                ->from($from ?? $this->defaultFrom)
                ->to(...$to)
                ->subject($subject)
                ->html($body);

            $this->mailer->send($email);

            return [
                'status' => 'success',
                'message' => 'E-Mail mit Template erfolgreich gesendet',
                'to' => $to,
                'subject' => $subject,
                'template' => $templatePath,
                'sent_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Fehler beim Senden der Template-E-Mail: ' . $e->getMessage(),
                'to' => $to,
                'subject' => $subject,
                'template' => $templatePath,
            ];
        }
    }

    /**
     * Liest E-Mails von einem IMAP-Server (Platzhalter)
     * HINWEIS: Diese Funktion erfordert eine IMAP-Erweiterung und Konfiguration
     */
    #[AsTool(
        name: 'read_emails',
        description: 'Liest E-Mails von einem IMAP-Server. Erfordert IMAP-Konfiguration.'
    )]
    public function readEmails(
        string $mailbox = 'INBOX',
        int $limit = 10,
        bool $unreadOnly = true
    ): array {
        // Platzhalter-Implementierung
        // In einer echten Implementierung würde man hier eine IMAP-Verbindung herstellen
        return [
            'status' => 'warning',
            'message' => 'IMAP-Funktionalität nicht implementiert. Bitte IMAP-Konfiguration hinzufügen.',
            'mailbox' => $mailbox,
            'limit' => $limit,
            'unread_only' => $unreadOnly,
        ];
    }

    /**
     * Sucht nach E-Mails (Platzhalter)
     */
    #[AsTool(
        name: 'search_emails',
        description: 'Durchsucht E-Mails nach bestimmten Kriterien.'
    )]
    public function searchEmails(
        string $query,
        string $mailbox = 'INBOX',
        int $limit = 10
    ): array {
        // Platzhalter-Implementierung
        return [
            'status' => 'warning',
            'message' => 'E-Mail-Suche nicht implementiert.',
            'query' => $query,
            'mailbox' => $mailbox,
            'limit' => $limit,
        ];
    }

    /**
     * Rendert ein Template (Platzhalter)
     */
    private function renderTemplate(string $templatePath, array $data): string
    {
        // Einfache Template-Engine für Platzhalter
        $body = file_get_contents($templatePath) ?? '';
        
        foreach ($data as $key => $value) {
            $body = str_replace("{{ $key }}", $value, $body);
        }

        return $body;
    }
}
