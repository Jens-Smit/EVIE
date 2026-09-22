<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OutboundAllowlistEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Persistierter Host-Freigabe-Eintrag fuer ausgehende HTTP-Anfragen (SSRF).
 *
 * Ein Eintrag wird per Frontend (ROLE_ADMIN, CSRF, Audit-Log) angelegt und
 * von der OutboundRequestPolicy beruecksichtigt: Sobald mindestens ein
 * aktiver Eintrag existiert, werden nur noch freigegebene Hosts erlaubt
 * (explizite Freigabe, Blueprint §4.D/§5 Security by Default). Der
 * patternType entscheidet:
 *
 * - 'exact':    Host muss exakt matchen (z. B. "api.tavily.com").
 * - 'suffix':   Host oder Subdomain (z. B. "mistral.ai" erlaubt auch
 *               "api.mistral.ai").
 * - 'wildcard': fnmatch-Wildcard (z. B. "api.*.example.com").
 *
 * Wichtig: Eine Freigabe ist keine Aufhebung der SSRF-Blockliste. Geblockte
 * interne Zieladressen (Loopback, private Ranges, Link-Local, Metadaten-
 * Endpunkte) bleiben auch bei vorhandener Freigabe blockiert, da die
 * OutboundRequestPolicy die Blocklist vor der Allowlist prueft.
 */
#[ORM\Entity(repositoryClass: OutboundAllowlistEntryRepository::class)]
#[ORM\Table(name: 'ai_outbound_allowlist')]
#[ORM\UniqueConstraint(name: 'uniq_outbound_allowlist_host', columns: ['host_pattern'])]
#[ORM\Index(name: 'idx_outbound_allowlist_organization', columns: ['organization_id'])]
class OutboundAllowlistEntry
{
    public const TYPE_EXACT = 'exact';
    public const TYPE_SUFFIX = 'suffix';
    public const TYPE_WILDCARD = 'wildcard';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $hostPattern;

    #[ORM\Column(type: 'string', length: 16)]
    private string $patternType = self::TYPE_SUFFIX;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    // Tenant-Zuordnung analog McpServerDefinition: null = systemweit
    // (nur Super-Admins duerfen systemweite Eintraege anlegen).
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $organizationId = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getHostPattern(): string
    {
        return $this->hostPattern;
    }

    public function setHostPattern(string $hostPattern): self
    {
        $this->hostPattern = $hostPattern;

        return $this;
    }

    public function getPatternType(): string
    {
        return $this->patternType;
    }

    public function setPatternType(string $patternType): self
    {
        $this->patternType = $patternType;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getOrganizationId(): ?string
    {
        return $this->organizationId;
    }

    public function setOrganizationId(?string $organizationId): self
    {
        $this->organizationId = $organizationId;

        return $this;
    }
}
