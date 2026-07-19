<?php

namespace App\Event;

use App\Entity\Manager;
use Symfony\Contracts\EventDispatcher\Event;

class PasswordChangedEvent extends Event
{
    public const NAME = 'password.changed';

    public function __construct(
        private Manager $manager, // the manager who performed the change
        private Manager $targetManager, // the manager whose password was changed
        private \DateTimeImmutable $timestamp
    ) {
    }

    public function getManager(): Manager
    {
        return $this->manager;
    }

    public function getTargetManager(): Manager
    {
        return $this->targetManager;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }
}
