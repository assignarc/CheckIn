<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "audit_logs")]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: true, onDelete: "CASCADE")]
    private ?Organization $organization = null;

    #[ORM\ManyToOne(targetEntity: Manager::class)]
    #[ORM\JoinColumn(name: "manager_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?Manager $manager = null;

    #[ORM\Column(type: "string", length: 100)]
    private string $action;

    #[ORM\Column(type: "json")]
    private array $detailsJson = [];

    #[ORM\Column(type: "string", length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): self
    {
        $this->organization = $organization;
        return $this;
    }

    public function getManager(): ?Manager
    {
        return $this->manager;
    }

    public function setManager(?Manager $manager): self
    {
        $this->manager = $manager;
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getDetailsJson(): array
    {
        return $this->detailsJson;
    }

    public function setDetailsJson(array $detailsJson): self
    {
        $this->detailsJson = $detailsJson;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
