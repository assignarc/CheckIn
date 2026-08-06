<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity]
#[ORM\Table(name: "organizations")]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false)]
#[UniqueEntity(fields: ['slug'], message: 'An organization with this slug already exists.')]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 255)]
    #[Assert\NotBlank(message: 'Organization name is required.')]
    #[Assert\Length(min: 2, max: 255)]
    private string $name;

    #[ORM\Column(type: "string", length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Slug must contain lowercase alphanumeric characters and hyphens only.')]
    private string $slug;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(name: "plan_id", referencedColumnName: "id", nullable: false)]
    #[Assert\NotNull]
    private Plan $plan;

    #[ORM\Column(type: "string", length: 50)]
    #[Assert\Choice(choices: ['open', 'secure'], message: 'Access mode must be open or secure.')]
    private string $accessMode = 'secure'; // open, secure

    #[ORM\Column(type: "string", length: 255)]
    private string $checkInLinkToken;

    #[ORM\Column(type: "integer")]
    #[Assert\Range(min: 1, max: 168, notInRangeMessage: 'Token rotation interval must be between 1 and 168 hours.')]
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
    #[Assert\Range(min: 0, max: 1440, notInRangeMessage: 'Grace minutes must be between 0 and 1440.')]
    private int $overtimeGraceMinutes = 0;

    #[ORM\Column(type: "decimal", precision: 4, scale: 2, options: ["default" => "1.50"])]
    private string $overtimeMultiplier = '1.50';

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: "boolean")]
    private bool $isSubscriptionActive = true;

    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private bool $isArchived = false;

    #[ORM\Column(type: "boolean", options: ["default" => true])]
    private bool $maskLastName = true;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: "string", length: 100, nullable: true)]
    private ?string $timezone = null;

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

    #[ORM\Column(type: "boolean", options: ["default" => true])]
    private bool $kioskTokenAutoRotate = true;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $semiPermanentKioskToken = null;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(name: "pending_plan_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?Plan $pendingPlan = null;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?\DateTimeInterface $pendingPlanEffectiveAt = null;

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->checkInLinkToken = bin2hex(random_bytes(16));
        $this->semiPermanentKioskToken = bin2hex(random_bytes(16));
        $this->kioskTokenAutoRotate = true;
    }

    public function getSemiPermanentKioskToken(): ?string
    {
        if (empty($this->semiPermanentKioskToken)) {
            $this->semiPermanentKioskToken = bin2hex(random_bytes(16));
        }
        return $this->semiPermanentKioskToken;
    }

    public function setSemiPermanentKioskToken(?string $semiPermanentKioskToken): self
    {
        $this->semiPermanentKioskToken = $semiPermanentKioskToken;
        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): self
    {
        $this->stripeCustomerId = $stripeCustomerId;
        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): self
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        return $this;
    }

    public function getPendingPlan(): ?Plan
    {
        return $this->pendingPlan;
    }

    public function setPendingPlan(?Plan $pendingPlan): self
    {
        $this->pendingPlan = $pendingPlan;
        return $this;
    }

    public function getPendingPlanEffectiveAt(): ?\DateTimeInterface
    {
        return $this->pendingPlanEffectiveAt;
    }

    public function setPendingPlanEffectiveAt(?\DateTimeInterface $pendingPlanEffectiveAt): self
    {
        $this->pendingPlanEffectiveAt = $pendingPlanEffectiveAt;
        return $this;
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

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): self
    {
        $this->timezone = $timezone ? trim($timezone) : null;
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

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $isArchived): self
    {
        $this->isArchived = $isArchived;
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

    public function isBarcodeEnabled(): bool
    {
        return $this->plan->hasBarcodeFeatures();
    }

    public function isClickListEnabled(): bool
    {
        return $this->plan->hasClickList();
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

    public function clearOverrides(): self
    {
        $this->maxOrgUsersOverride = null;
        $this->maxManagersOverride = null;
        $this->maxFieldsOverride = null;
        $this->maxCategoriesOverride = null;
        $this->sessionLimitHoursOverride = null;
        $this->retentionDaysOverride = null;
        $this->exportsEnabledOverride = null;
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

    public function isKioskTokenAutoRotate(): bool
    {
        return $this->kioskTokenAutoRotate;
    }

    public function setKioskTokenAutoRotate(bool $kioskTokenAutoRotate): self
    {
        $this->kioskTokenAutoRotate = $kioskTokenAutoRotate;
        return $this;
    }
}
