<?php

namespace App\Entity;

use App\Repository\AppConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppConfigRepository::class)]
class AppConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $settingKey = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private mixed $settingValue = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSettingKey(): ?string
    {
        return $this->settingKey;
    }

    public function setSettingKey(string $settingKey): static
    {
        $this->settingKey = $settingKey;

        return $this;
    }

    public function getSettingValue(): mixed
    {
        return $this->settingValue;
    }

    public function setSettingValue(mixed $settingValue): static
    {
        $this->settingValue = $settingValue;

        return $this;
    }
}
