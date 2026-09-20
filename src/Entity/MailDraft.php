<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MailDraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistenter E-Mail-Entwurf fuer den Human-in-the-Loop-Freigabe-Workflow
 * (Blueprint §5: keine kritische Aktion ohne HITL).
 *
 * Ein Agent (z.B. communication_manager oder Vertriebs-Sub-Agent) erstellt
 * ueber das MailDraftTool einen Entwurf mit status=pending_approval. Der
 * Nutzer genehmigt oder lehnt den Entwurf ueber den HitlMailController ab;
 * erst nach Freigabe wird die E-Mail ueber den Symfony Mailer versendet.
 */
#[ORM\Entity(repositoryClass: MailDraftRepository::class)]
#[ORM\Table(name: 'mail_drafts')]
#[ORM\Index(name: 'idx_mail_draft_status', columns: ['status'])]
class MailDraft
{
    public const STATUS_PENDING = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SENT = 'sent';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $userIdentifier;

    #[ORM\Column(type: Types::STRING)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** @var array<int, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $recipients = [];

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $sender = null;

    /** @var array<int, array{filename: string, content: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $attachments = [];

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isHtml = false;

    #[ORM\ManyToOne(targetEntity: UserProfile::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?UserProfile $userProfile = null;

    #[ORM\ManyToOne(targetEntity: SubAgent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SubAgent $subAgent = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(string $userIdentifier): static
    {
        $this->userIdentifier = $userIdentifier;
        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    /** @return array<int, string> */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /** @param array<int, string> $recipients */
    public function setRecipients(array $recipients): static
    {
        $this->recipients = array_values($recipients);
        return $this;
    }

    public function getSender(): ?string
    {
        return $this->sender;
    }

    public function setSender(?string $sender): static
    {
        $this->sender = $sender;
        return $this;
    }

    /** @return array<int, array{filename: string, content: string}> */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /** @param array<int, array{filename: string, content: string}> $attachments */
    public function setAttachments(array $attachments): static
    {
        $this->attachments = array_values($attachments);
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }

    public function isHtml(): bool
    {
        return $this->isHtml;
    }

    public function setIsHtml(bool $isHtml): static
    {
        $this->isHtml = $isHtml;
        return $this;
    }

    public function getUserProfile(): ?UserProfile
    {
        return $this->userProfile;
    }

    public function setUserProfile(?UserProfile $userProfile): static
    {
        $this->userProfile = $userProfile;
        return $this;
    }

    public function getSubAgent(): ?SubAgent
    {
        return $this->subAgent;
    }

    public function setSubAgent(?SubAgent $subAgent): static
    {
        $this->subAgent = $subAgent;
        return $this;
    }
}
