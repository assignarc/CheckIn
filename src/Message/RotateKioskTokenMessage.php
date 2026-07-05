<?php

namespace App\Message;

class RotateKioskTokenMessage
{
    public function __construct(
        private int $orgId,
        private string $orgName
    ) {
    }

    public function getOrgId(): int
    {
        return $this->orgId;
    }

    public function getOrgName(): string
    {
        return $this->orgName;
    }
}
