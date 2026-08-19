<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;

/**
 * Service für Benachrichtigungen
 * 
 * Sendet Benachrichtigungen an User über verschiedene Kanäle.
 */
class NotificationService
{
    public function __construct(
        private ?NotifierInterface $notifier = null,
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Sendet eine Benachrichtigung an einen User
     * 
     * @param string $userIdentifier Der User-Identifier
     * @param string $message Die Nachricht
     * @param string $subject Das Thema der Nachricht
     * @param array $options Zusätzliche Optionen
     */
    public function sendToUser(
        string $userIdentifier,
        string $message,
        string $subject = 'Benachrichtigung',
        array $options = []
    ): void {
        // Falls kein Notifier verfügbar ist, nur loggen
        if (!$this->notifier) {
            $this->logger?->info('Benachrichtigung (ohne Notifier): ' . $subject, [
                'user' => $userIdentifier,
                'message' => substr($message, 0, 200)
            ]);
            return;
        }

        try {
            // Erstelle eine Benachrichtigung
            $notification = new Notification($message, ['chat']);
            $notification->setSubject($subject);
            
            // Füge User-spezifische Optionen hinzu
            $notification->setUserIdentifier($userIdentifier);
            
            // Sende die Benachrichtigung
            $this->notifier->send($notification);

            $this->logger?->debug('Benachrichtigung gesendet', [
                'user' => $userIdentifier,
                'subject' => $subject
            ]);

        } catch (\Exception $e) {
            $this->logger?->error('Fehler beim Senden der Benachrichtigung: ' . $e->getMessage(), [
                'user' => $userIdentifier,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Sendet eine E-Mail-Benachrichtigung
     * 
     * @param string $email Die E-Mail-Adresse
     * @param string $subject Das Thema
     * @param string $body Der Inhalt
     */
    public function sendEmail(
        string $email,
        string $subject,
        string $body
    ): void {
        // In einer echten Implementierung würde hier eine E-Mail gesendet werden
        // Für jetzt nur loggen
        $this->logger?->info('E-Mail-Benachrichtigung: ' . $subject, [
            'to' => $email,
            'body_length' => strlen($body)
        ]);
    }

    /**
     * Sendet eine System-Benachrichtigung (für interne Zwecke)
     * 
     * @param string $message Die Nachricht
     * @param string $level Das Log-Level (info, warning, error, etc.)
     */
    public function sendSystemNotification(
        string $message,
        string $level = 'info'
    ): void {
        $this->logger?->log($level, 'System-Benachrichtigung: ' . $message);
    }

    /**
     * Prüft, ob Benachrichtigungen aktiviert sind
     * 
     * @return bool True, falls Benachrichtigungen aktiviert sind
     */
    public function isEnabled(): bool
    {
        return $this->notifier !== null;
    }

    /**
     * Gibt den Notifier zurück
     * 
     * @return NotifierInterface|null Der Notifier oder null
     */
    public function getNotifier(): ?NotifierInterface
    {
        return $this->notifier;
    }
}
