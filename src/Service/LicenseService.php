<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\OrgUser;
use Doctrine\ORM\EntityManagerInterface;

class LicenseService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Checks if the next user created should be active or inactive based on plan limits.
     */
    public function isNextUserActiveAllowed(Organization $org, int $additionalActiveCount = 0): bool
    {
        if (!$org->isSubscriptionActive()) {
            return false;
        }
        $maxUsers = $org->getMaxOrgUsers();
        
        // Count active org users in organization
        $activeCount = $this->em->getRepository(OrgUser::class)->count([
            'organization' => $org,
            'isActive' => true
        ]);
        
        return ($activeCount + $additionalActiveCount) < $maxUsers;
    }

    /**
     * Checks if the next manager created is allowed based on plan limits.
     */
    public function isNextManagerAllowed(Organization $org): bool
    {
        if (!$org->isSubscriptionActive()) {
            return false;
        }
        $maxManagers = $org->getMaxManagers();
        $managerCount = $this->em->getRepository(Manager::class)->count([
            'organization' => $org,
            'isActive' => true
        ]);
        return $managerCount < $maxManagers;
    }

    /**
     * Checks if a new category is allowed based on plan limits.
     */
    public function isNextCategoryAllowed(Organization $org): bool
    {
        if (!$org->isSubscriptionActive()) {
            return false;
        }
        $maxCategories = $org->getMaxCategories();
        $categoryCount = $this->em->getRepository(Category::class)->count([
            'organization' => $org,
            'isActive' => true
        ]);
        return $categoryCount < $maxCategories;
    }

    /**
     * Checks if the custom fields count is within limits.
     */
    public function isCustomFieldsCountAllowed(Organization $org, int $count): bool
    {
        if (!$org->isSubscriptionActive()) {
            return false;
        }
        return $count <= $org->getMaxFields();
    }

    /**
     * Checks if max shift duration is within plan limits.
     */
    public function isSessionDurationAllowed(Organization $org, int $durationMinutes): bool
    {
        if (!$org->isSubscriptionActive()) {
            return false;
        }
        $limitHours = $org->getSessionLimitHours();
        return $durationMinutes <= ($limitHours * 60);
    }

    /**
     * Checks if exports are enabled.
     */
    public function isExportsEnabled(Organization $org): bool
    {
        if (!$org->isSubscriptionActive()) {
            return false;
        }
        return $org->isExportsEnabled();
    }

    /**
     * Checks if the requested retention days is within limits.
     */
    public function isRetentionAllowed(Organization $org, int $days): bool
    {
        if (!$org->isSubscriptionActive()) {
            return false;
        }
        return $days <= $org->getRetentionDays();
    }

    /**
     * Checks if barcode scanning/printing features are enabled.
     */
    public function isBarcodeEnabled(Organization $org): bool
    {
        if (!$org->isSubscriptionActive()) {
            return false;
        }
        return $org->isBarcodeEnabled();
    }

    /**
     * Checks if click list (open roster list) mode is enabled.
     */
    public function isClickListEnabled(Organization $org): bool
    {
        if (!$org->isSubscriptionActive()) {
            return false;
        }
        return $org->isClickListEnabled();
    }

    /**
     * Checks if secure PIN block mode is enabled.
     */
    public function isSecurePinBlockEnabled(Organization $org): bool
    {
        if (!$org->isSubscriptionActive()) {
            return false;
        }
        return $org->getPlan()->hasSecureKiosk();
    }
}
