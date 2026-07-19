<?php

namespace App\Event;

use App\Entity\Organization;
use Symfony\Contracts\EventDispatcher\Event;

class OrganizationProfileUpdatedEvent extends Event
{
    public const NAME = 'organization.profile_updated';

    public function __construct(
        private Organization $organization,
        private array $changedFields, // e.g. ['name' => ['old' => '...', 'new' => '...']]
        private \DateTimeImmutable $timestamp
    ) {
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getChangedFields(): array
    {
        return $this->changedFields;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }
}
