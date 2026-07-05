<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: "categories")]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false)]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private Organization $organization;

    #[ORM\Column(type: "string", length: 100)]
    private string $name;

    #[ORM\Column(type: "string", length: 50)]
    private string $icon = 'work'; // Default material icon name

    #[ORM\Column(type: "string", length: 50)]
    private string $color = '#6366f1';
    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private bool $requiresLocation = false;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: "boolean")]
    private bool $isActive = true;

    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $maxSessionDurationMinutes = null;

    #[ORM\Column(type: "boolean")]
    private bool $terminateAtMaxDuration = false;

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

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function setOrganization(Organization $organization): self
    {
        $this->organization = $organization;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    
    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): self
    {
        $this->icon = $icon;
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

    public function setRequiresLocation(bool $requiresLocation): self
    {
        $this->requiresLocation = $requiresLocation;
        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    public function getMaxSessionDurationMinutes(): ?int
    {
        return $this->maxSessionDurationMinutes;
    }

    public function setMaxSessionDurationMinutes(?int $maxSessionDurationMinutes): self
    {
        $this->maxSessionDurationMinutes = $maxSessionDurationMinutes;
        return $this;
    }

    // Helper for formatting minutes to HH:MM format
    public function getFormattedMaxDuration(): string
    {
        if ($this->maxSessionDurationMinutes === null) {
            return '';
        }
        $hours = floor($this->maxSessionDurationMinutes / 60);
        $mins = $this->maxSessionDurationMinutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }

    public function isTerminateAtMaxDuration(): bool
    {
        return $this->terminateAtMaxDuration;
    }

    public function setTerminateAtMaxDuration(bool $terminateAtMaxDuration): self
    {
        $this->terminateAtMaxDuration = $terminateAtMaxDuration;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
