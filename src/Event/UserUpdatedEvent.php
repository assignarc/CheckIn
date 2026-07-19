<?php

namespace App\Event;

use App\Entity\OrgUser;
use Symfony\Contracts\EventDispatcher\Event;

class UserUpdatedEvent extends Event
{
    public const NAME = 'user.updated';

    public function __construct(
        private OrgUser $orgUser,
        private string $changeType, // 'create', 'edit', 'toggle_active', etc.
        private array $changedFields, // e.g. ['email' => ['old' => '...', 'new' => '...']]
        private \DateTimeImmutable $timestamp
    ) {
    }

    public function getOrgUser(): OrgUser
    {
        return $this->orgUser;
    }

    public function getChangeType(): string
    {
        return $this->changeType;
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
