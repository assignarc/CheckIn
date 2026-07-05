<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "plans")]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 100)]
    private string $name; // basic, silver, gold

    #[ORM\Column(type: "integer")]
    private int $maxOrgUsers;

    #[ORM\Column(type: "integer")]
    private int $maxManagers;

    #[ORM\Column(type: "integer")]
    private int $maxFields;

    #[ORM\Column(type: "integer")]
    private int $maxCategories;

    #[ORM\Column(type: "integer")]
    private int $sessionLimitHours;

    #[ORM\Column(type: "integer")]
    private int $retentionDays;

    #[ORM\Column(type: "boolean")]
    private bool $exportsEnabled;

    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private bool $hasSecureKiosk = false;

    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private bool $hasCustomSessionDurations = false;

    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private bool $hasOvertimeRules = false;

    #[ORM\Column(type: "decimal", precision: 10, scale: 2)]
    private string $priceMonthly;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getMaxOrgUsers(): int
    {
        return $this->maxOrgUsers;
    }

    public function setMaxOrgUsers(int $maxOrgUsers): self
    {
        $this->maxOrgUsers = $maxOrgUsers;

        return $this;
    }

    public function getMaxManagers(): int
    {
        return $this->maxManagers;
    }

    public function setMaxManagers(int $maxManagers): self
    {
        $this->maxManagers = $maxManagers;
        return $this;
    }

    public function getMaxFields(): int
    {
        return $this->maxFields;
    }

    public function setMaxFields(int $maxFields): self
    {
        $this->maxFields = $maxFields;
        return $this;
    }

    public function getMaxCategories(): int
    {
        return $this->maxCategories;
    }

    public function setMaxCategories(int $maxCategories): self
    {
        $this->maxCategories = $maxCategories;
        return $this;
    }

    public function getSessionLimitHours(): int
    {
        return $this->sessionLimitHours;
    }

    public function setSessionLimitHours(int $sessionLimitHours): self
    {
        $this->sessionLimitHours = $sessionLimitHours;
        return $this;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function setRetentionDays(int $retentionDays): self
    {
        $this->retentionDays = $retentionDays;
        return $this;
    }

    public function isExportsEnabled(): bool
    {
        return $this->exportsEnabled;
    }

    public function setExportsEnabled(bool $exportsEnabled): self
    {
        $this->exportsEnabled = $exportsEnabled;
        return $this;
    }

    public function getPriceMonthly(): string
    {
        return $this->priceMonthly;
    }

    public function setPriceMonthly(string $priceMonthly): self
    {
        $this->priceMonthly = $priceMonthly;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function hasSecureKiosk(): bool
    {
        return $this->hasSecureKiosk;
    }

    public function setHasSecureKiosk(bool $hasSecureKiosk): self
    {
        $this->hasSecureKiosk = $hasSecureKiosk;
        return $this;
    }

    public function hasCustomSessionDurations(): bool
    {
        return $this->hasCustomSessionDurations;
    }

    public function setHasCustomSessionDurations(bool $hasCustomSessionDurations): self
    {
        $this->hasCustomSessionDurations = $hasCustomSessionDurations;
        return $this;
    }

    public function hasOvertimeRules(): bool
    {
        return $this->hasOvertimeRules;
    }

    public function setHasOvertimeRules(bool $hasOvertimeRules): self
    {
        $this->hasOvertimeRules = $hasOvertimeRules;
        return $this;
    }
}
