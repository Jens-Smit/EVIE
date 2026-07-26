<?php

namespace App\Message;

class MailDraftApprovalRequested
{
    public function __construct(
        private string $subject,
        private array $recipients,
        private string $content,
        private array $attachments = [],
        private string $requestedBy
    ) {
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getRequestedBy(): string
    {
        return $this->requestedBy;
    }
}