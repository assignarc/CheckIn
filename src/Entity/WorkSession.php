<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: "work_sessions")]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false)]
class WorkSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrgUser::class)]
    #[ORM\JoinColumn(name: "org_user_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private OrgUser $orgUser;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: "category_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?Category $category = null;

    #[ORM\Column(type: "datetime")]
    private \DateTimeInterface $startedAt;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?\DateTimeInterface $endedAt = null;

    #[ORM\Column(type: "string", length: 50)]
    private string $startMethod = 'secure'; // open or secure

    #[ORM\Column(type: "string", length: 50, nullable: true)]
    private ?string $endMethod = null; // open, secure, auto_checkout

    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private bool $isOvertime = false;

    #[ORM\Column(type: "boolean")]
    private bool $isOutOfWindow = false;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $deviceInfo = null;

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->startedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrgUser(): OrgUser
    {
        return $this->orgUser;
    }

    public function setOrgUser(OrgUser $orgUser): self
    {
        $this->orgUser = $orgUser;
        return $this;
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

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getStartedAt(): \DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeInterface $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getEndedAt(): ?\DateTimeInterface
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeInterface $endedAt): self
    {
        $this->endedAt = $endedAt;
        return $this;
    }

    public function getStartMethod(): string
    {
        return $this->startMethod;
    }

    public function setStartMethod(string $startMethod): self
    {
        $this->startMethod = $startMethod;
        return $this;
    }

    public function getEndMethod(): ?string
    {
        return $this->endMethod;
    }

    public function setEndMethod(?string $endMethod): self
    {
        $this->endMethod = $endMethod;
        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(?int $durationSeconds): self
    {
        $this->durationSeconds = $durationSeconds;
        return $this;
    }

    public function isOvertime(): bool
    {
        return $this->isOvertime;
    }

    public function setIsOvertime(bool $isOvertime): self
    {
        $this->isOvertime = $isOvertime;
        return $this;
    }

    public function isOutOfWindow(): bool
    {
        return $this->isOutOfWindow;
    }

    public function setIsOutOfWindow(bool $isOutOfWindow): self
    {
        $this->isOutOfWindow = $isOutOfWindow;
        return $this;
    }

    public function getDeviceInfo(): ?string
    {
        return $this->deviceInfo;
    }

    public function setDeviceInfo(?string $deviceInfo): self
    {
        $this->deviceInfo = $deviceInfo;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
