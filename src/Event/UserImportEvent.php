<?php

namespace App\Event;

use App\Entity\Organization;
use Symfony\Contracts\EventDispatcher\Event;

class UserImportEvent extends Event
{
    public const NAME = 'user.imported';

    public function __construct(
        private Organization $organization,
        private int $importedCount,
        private int $updatedCount,
        private int $enabledCount,
        private int $disabledCount,
        private \DateTimeImmutable $timestamp
    ) {
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }

    public function getUpdatedCount(): int
    {
        return $this->updatedCount;
    }

    public function getEnabledCount(): int
    {
        return $this->enabledCount;
    }

    public function getDisabledCount(): int
    {
        return $this->disabledCount;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }
}
