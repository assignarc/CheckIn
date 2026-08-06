<?php

namespace App\Service\Kiosk;

use App\Entity\OrgUser;
use App\Entity\Organization;

class BadgeGeneratorService
{
    /**
     * Generates printable HTML data for barcode stickers.
     */
    public function generateBadgeData(OrgUser $orgUser, Organization $org): array
    {
        $pin = $orgUser->getPin() ?? 'N/A';
        $fullName = $orgUser->getFirstName() . ' ' . $orgUser->getLastName();
        $orgName = $org->getName();

        return [
            'pin' => $pin,
            'full_name' => $fullName,
            'org_name' => $orgName,
            'barcode_svg' => sprintf('<svg class="barcode-preview" data-pin="%s"></svg>', htmlspecialchars($pin)),
        ];
    }
}
