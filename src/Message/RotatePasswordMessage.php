<?php

namespace App\Message;

class RotatePasswordMessage
{
    public function __construct(
        private int $orgId,
        private int $managerId
    ) {
    }

    public function getOrgId(): int
    {
        return $this->orgId;
    }

    public function getManagerId(): int
    {
        return $this->managerId;
    }
}
