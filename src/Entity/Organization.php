<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: "organizations")]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false)]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 255)]
    private string $name;

    #[ORM\Column(type: "string", length: 255, unique: true)]
    private string $slug;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(name: "plan_id", referencedColumnName: "id", nullable: false)]
    private Plan $plan;

    #[ORM\Column(type: "string", length: 50)]
    private string $accessMode = 'secure'; // open, secure

    #[ORM\Column(type: "string", length: 255)]
    private string $checkInLinkToken;

    #[ORM\Column(type: "integer")]
    private int $kioskTokenRotationHours = 24;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(type: "blob", nullable: true)]
    private $logoBlob = null;

    #[ORM\Column(type: "time", nullable: true)]
    private ?\DateTimeInterface $checkInWindowStart = null;

    #[ORM\Column(type: "time", nullable: true)]
    private ?\DateTimeInterface $checkInWindowEnd = null;

    #[ORM\Column(type: "integer")]
    private int $overtimeGraceMinutes = 0;

    #[ORM\Column(type: "decimal", precision: 4, scale: 2, options: ["default" => "1.50"])]
    private string $overtimeMultiplier = '1.50';

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: "boolean")]
    private bool $isSubscriptionActive = true;

    #[ORM\Column(type: "boolean", options: ["default" => true])]
    private bool $maskLastName = true;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: "string", length: 50, nullable: true)]
    private ?string $phone = null;

    // Overrides (nullable) - if null, falls back to linked Plan values
    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $maxOrgUsersOverride = null;

    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $maxManagersOverride = null;

    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $maxFieldsOverride = null;

    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $maxCategoriesOverride = null;

    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $sessionLimitHoursOverride = null;

    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $retentionDaysOverride = null;

    #[ORM\Column(type: "boolean", nullable: true)]
    private ?bool $exportsEnabledOverride = null;

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->checkInLinkToken = bin2hex(random_bytes(16));
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function setPlan(Plan $plan): self
    {
        $this->plan = $plan;
        return $this;
    }

    public function getAccessMode(): string
    {
        return $this->accessMode;
    }

    public function setAccessMode(string $accessMode): self
    {
        $this->accessMode = $accessMode;
        return $this;
    }

    public function getKioskTokenRotationHours(): int
    {
        return $this->kioskTokenRotationHours;
    }

    public function setKioskTokenRotationHours(int $kioskTokenRotationHours): self
    {
        $this->kioskTokenRotationHours = $kioskTokenRotationHours;
        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): self
    {
        $this->logoPath = $logoPath;
        return $this;
    }

    public function getLogoBlob()
    {
        return $this->logoBlob;
    }

    public function setLogoBlob($logoBlob): self
    {
        $this->logoBlob = $logoBlob;
        return $this;
    }

    public function getCheckInLinkToken(): string
    {
        return $this->checkInLinkToken;
    }

    public function setCheckInLinkToken(string $checkInLinkToken): self
    {
        $this->checkInLinkToken = $checkInLinkToken;
        return $this;
    }

    public function getCheckInWindowStart(): ?\DateTimeInterface
    {
        return $this->checkInWindowStart;
    }

    public function setCheckInWindowStart(?\DateTimeInterface $checkInWindowStart): self
    {
        $this->checkInWindowStart = $checkInWindowStart;
        return $this;
    }

    public function getCheckInWindowEnd(): ?\DateTimeInterface
    {
        return $this->checkInWindowEnd;
    }

    public function setCheckInWindowEnd(?\DateTimeInterface $checkInWindowEnd): self
    {
        $this->checkInWindowEnd = $checkInWindowEnd;
        return $this;
    }

    public function getOvertimeGraceMinutes(): int
    {
        return $this->overtimeGraceMinutes;
    }

    public function setOvertimeGraceMinutes(int $overtimeGraceMinutes): self
    {
        $this->overtimeGraceMinutes = $overtimeGraceMinutes;
        return $this;
    }

    public function getOvertimeMultiplier(): string
    {
        return $this->overtimeMultiplier;
    }

    public function setOvertimeMultiplier(string $overtimeMultiplier): self
    {
        $this->overtimeMultiplier = $overtimeMultiplier;
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

    public function isSubscriptionActive(): bool
    {
        return $this->isSubscriptionActive;
    }

    public function setIsSubscriptionActive(bool $isSubscriptionActive): self
    {
        $this->isSubscriptionActive = $isSubscriptionActive;
        return $this;
    }

    // Resolvers (Fallback Logic)
    public function getMaxOrgUsers(): int
    {
        return $this->maxOrgUsersOverride !== null ? $this->maxOrgUsersOverride : $this->plan->getMaxOrgUsers();
    }

    public function getMaxManagers(): int
    {
        return $this->maxManagersOverride !== null ? $this->maxManagersOverride : $this->plan->getMaxManagers();
    }

    public function getMaxFields(): int
    {
        return $this->maxFieldsOverride !== null ? $this->maxFieldsOverride : $this->plan->getMaxFields();
    }

    public function getMaxCategories(): int
    {
        return $this->maxCategoriesOverride !== null ? $this->maxCategoriesOverride : $this->plan->getMaxCategories();
    }

    public function getSessionLimitHours(): int
    {
        return $this->sessionLimitHoursOverride !== null ? $this->sessionLimitHoursOverride : $this->plan->getSessionLimitHours();
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDaysOverride !== null ? $this->retentionDaysOverride : $this->plan->getRetentionDays();
    }

    public function isExportsEnabled(): bool
    {
        return $this->exportsEnabledOverride !== null ? $this->exportsEnabledOverride : $this->plan->isExportsEnabled();
    }

    // Getters / Setters for Overrides
    public function getMaxOrgUsersOverride(): ?int
    {
        return $this->maxOrgUsersOverride;
    }

    public function setMaxOrgUsersOverride(?int $maxOrgUsersOverride): self
    {
        $this->maxOrgUsersOverride = $maxOrgUsersOverride;

        return $this;
    }

    public function getMaxManagersOverride(): ?int
    {
        return $this->maxManagersOverride;
    }

    public function setMaxManagersOverride(?int $maxManagersOverride): self
    {
        $this->maxManagersOverride = $maxManagersOverride;
        return $this;
    }

    public function getMaxFieldsOverride(): ?int
    {
        return $this->maxFieldsOverride;
    }

    public function setMaxFieldsOverride(?int $maxFieldsOverride): self
    {
        $this->maxFieldsOverride = $maxFieldsOverride;
        return $this;
    }

    public function getMaxCategoriesOverride(): ?int
    {
        return $this->maxCategoriesOverride;
    }

    public function setMaxCategoriesOverride(?int $maxCategoriesOverride): self
    {
        $this->maxCategoriesOverride = $maxCategoriesOverride;
        return $this;
    }

    public function getSessionLimitHoursOverride(): ?int
    {
        return $this->sessionLimitHoursOverride;
    }

    public function setSessionLimitHoursOverride(?int $sessionLimitHoursOverride): self
    {
        $this->sessionLimitHoursOverride = $sessionLimitHoursOverride;
        return $this;
    }

    public function getRetentionDaysOverride(): ?int
    {
        return $this->retentionDaysOverride;
    }

    public function setRetentionDaysOverride(?int $retentionDaysOverride): self
    {
        $this->retentionDaysOverride = $retentionDaysOverride;
        return $this;
    }

    public function getExportsEnabledOverride(): ?bool
    {
        return $this->exportsEnabledOverride;
    }

    public function setExportsEnabledOverride(?bool $exportsEnabledOverride): self
    {
        $this->exportsEnabledOverride = $exportsEnabledOverride;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isMaskLastName(): bool
    {
        return $this->maskLastName;
    }

    public function setMaskLastName(bool $maskLastName): self
    {
        $this->maskLastName = $maskLastName;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }
}
