<?php

namespace App\Tests\Service;

use App\Entity\Organization;
use App\Entity\Plan;
use App\Service\LicenseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LicenseServiceTest extends KernelTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private ?LicenseService $licenseService = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->licenseService = $container->get(LicenseService::class);
    }

    public function testIsNextUserActiveAllowedReturnsFalseForInactiveSubscription(): void
    {
        $org = new Organization();
        $org->setName('Test Inactive Org');
        $org->setSlug('test-inactive');
        $org->setIsSubscriptionActive(false);

        $allowed = $this->licenseService->isNextUserActiveAllowed($org);
        $this->assertFalse($allowed);
    }

    public function testLicenseServiceRespectsPlanLimits(): void
    {
        $org = $this->entityManager->getRepository(Organization::class)->findOneBy(['slug' => 'basic-demo']);
        if ($org) {
            // Basic plan supports 50 users. If basic-demo currently has less than 50 active, it should be allowed.
            $allowed = $this->licenseService->isNextUserActiveAllowed($org);
            $this->assertTrue($allowed);

            // Adding an offset exceeding the plan maximum of 50 users should block creation
            $blocked = $this->licenseService->isNextUserActiveAllowed($org, 60);
            $this->assertFalse($blocked);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager = null;
        $this->licenseService = null;
    }
}
