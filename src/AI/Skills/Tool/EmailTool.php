<?php
// src/AI/Skills/Tool/EmailTool.php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Tool für das Senden von E-Mails.
 * Erwartet Parameter: to, subject, body, from, cc, bcc, is_html
 */
#[AsTool(
    name: 'send_email',
    description: 'Sendet eine E-Mail an einen oder mehrere Empfänger. Parameter: to (Array), subject (String), body (String), from (String, optional), cc (String, optional), bcc (String, optional), is_html (Boolean, optional)'
)]
class EmailTool
{
    public function __construct(
        private MailerInterface $mailer,
        private string $defaultFrom = 'noreply@evie.ai'
    ) {
    }

    /**
     * Hauptmethode - E-Mail senden
     * Erwartet ein Array mit Parametern: to, subject, body, from, cc, bcc, is_html
     */
    public function __invoke(array $parameters = []): array
    {
        try {
            $to = $parameters['to'] ?? [];
            $subject = $parameters['subject'] ?? '';
            $body = $parameters['body'] ?? '';
            $from = $parameters['from'] ?? $this->defaultFrom;
            $cc = $parameters['cc'] ?? null;
            $bcc = $parameters['bcc'] ?? null;
            $isHtml = $parameters['is_html'] ?? false;

            if (empty($to) || empty($subject) || empty($body)) {
                return [
                    'status' => 'error',
                    'message' => 'Fehlende erforderliche Parameter: to, subject, body',
                    'required' => ['to', 'subject', 'body'],
                    'optional' => ['from', 'cc', 'bcc', 'is_html'],
                ];
            }

            $email = (new Email())
                ->from($from)
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
                'to' => $parameters['to'] ?? [],
                'subject' => $parameters['subject'] ?? '',
            ];
        }
    }
}
