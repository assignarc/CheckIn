<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity]
#[ORM\Table(name: "org_users")]
#[ORM\UniqueConstraint(name: "uq_org_pin", columns: ["organization_id", "pin"])]
#[ORM\UniqueConstraint(name: "uq_org_unique_hash", columns: ["organization_id", "unique_id_hash"])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false)]
#[UniqueEntity(fields: ['organization', 'pin'], message: 'This PIN is already in use by another user in your organization.', ignoreNull: true)]
class OrgUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    #[Assert\NotNull(message: 'Organization is required.')]
    private Organization $organization;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    #[Assert\Email(message: 'Invalid email address format.', mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: 255)]
    private ?string $email = null;

    #[ORM\Column(type: "string", length: 100)]
    #[Assert\NotBlank(message: 'First name is required.')]
    #[Assert\Length(min: 1, max: 100, minMessage: 'First name cannot be empty.')]
    private string $firstName;

    #[ORM\Column(type: "string", length: 100)]
    #[Assert\NotBlank(message: 'Last name is required.')]
    #[Assert\Length(min: 1, max: 100, minMessage: 'Last name cannot be empty.')]
    private string $lastName;

    #[ORM\Column(type: "string", length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $phone = null;

    #[ORM\Column(type: "string", length: 255)]
    private string $pinHash; // hashed 4-character PIN

    #[ORM\Column(type: "string", length: 10, nullable: true)]
    #[Assert\Length(max: 10)]
    private ?string $pin = null;

    #[ORM\Column(type: "boolean", options: ["default" => true])]
    private bool $isActive = true;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $uniqueIdHash = null;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: "json")]
    private array $customFieldsJson = [];

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;
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

    public function getPinHash(): string
    {
        return $this->pinHash;
    }

    public function setPinHash(string $pinHash): self
    {
        $this->pinHash = $pinHash;
        return $this;
    }

    public function getPin(): ?string
    {
        return $this->pin;
    }

    public function setPin(?string $pin): self
    {
        $this->pin = $pin;
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

    public function getUniqueIdHash(): ?string
    {
        return $this->uniqueIdHash;
    }

    public function setUniqueIdHash(?string $uniqueIdHash): self
    {
        $this->uniqueIdHash = $uniqueIdHash;
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

    public function getCustomFieldsJson(): array
    {
        return $this->customFieldsJson;
    }

    public function setCustomFieldsJson(array $customFieldsJson): self
    {
        $this->customFieldsJson = $customFieldsJson;
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
}
