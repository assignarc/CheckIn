<?php

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\Plan;
use App\Service\LicenseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BarcodePlanLimiterTest extends WebTestCase
{
    public function testBarcodeLicenseLogicAndKioskRestriction(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $licenseService = $container->get(LicenseService::class);

        // Fetch organizations
        $orgs = $em->getRepository(Organization::class)->findAll();
        
        $basicOrg = null;
        $goldOrg = null;

        foreach ($orgs as $org) {
            if ($org->getPlan()->getName() === 'basic') {
                $basicOrg = $org;
            } elseif ($org->getPlan()->getName() === 'gold') {
                $goldOrg = $org;
            }
        }

        // If not found in the test DB, let's create a temporary mock organization/plan for test reliability
        if (!$basicOrg || !$goldOrg) {
            $basicPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'basic']);
            if (!$basicPlan) {
                $basicPlan = new Plan();
                $basicPlan->setName('basic');
                $basicPlan->setMaxOrgUsers(10);
                $basicPlan->setMaxManagers(1);
                $basicPlan->setMaxFields(3);
                $basicPlan->setMaxCategories(1);
                $basicPlan->setSessionLimitHours(2);
                $basicPlan->setRetentionDays(45);
                $basicPlan->setExportsEnabled(false);
                $basicPlan->setPriceMonthly('0.00');
                $basicPlan->setHasBarcodeFeatures(false);
                $em->persist($basicPlan);
            } else {
                $basicPlan->setHasBarcodeFeatures(false);
                $em->persist($basicPlan);
            }

            $goldPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'gold']);
            if (!$goldPlan) {
                $goldPlan = new Plan();
                $goldPlan->setName('gold');
                $goldPlan->setMaxOrgUsers(1000);
                $goldPlan->setMaxManagers(9999);
                $goldPlan->setMaxFields(99);
                $goldPlan->setMaxCategories(99);
                $goldPlan->setSessionLimitHours(24);
                $goldPlan->setRetentionDays(365);
                $goldPlan->setExportsEnabled(true);
                $goldPlan->setPriceMonthly('99.00');
                $goldPlan->setHasBarcodeFeatures(true);
                $em->persist($goldPlan);
            } else {
                $goldPlan->setHasBarcodeFeatures(true);
                $em->persist($goldPlan);
            }

            if (!$basicOrg) {
                $basicOrg = new Organization();
                $basicOrg->setName('Test Basic Org');
                $basicOrg->setSlug('test-basic-org');
                $basicOrg->setPlan($basicPlan);
                $basicOrg->setCheckInLinkToken('basic-token');
                $basicOrg->setIsSubscriptionActive(true);
                $em->persist($basicOrg);
            }
            if (!$goldOrg) {
                $goldOrg = new Organization();
                $goldOrg->setName('Test Gold Org');
                $goldOrg->setSlug('test-gold-org');
                $goldOrg->setPlan($goldPlan);
                $goldOrg->setCheckInLinkToken('gold-token');
                $goldOrg->setIsSubscriptionActive(true);
                $em->persist($goldOrg);
            }
            $em->flush();
        }

        $em->clear();
        $basicOrg = $em->getRepository(Organization::class)->findOneBy(['slug' => $basicOrg ? $basicOrg->getSlug() : 'test-basic-org']);
        $goldOrg = $em->getRepository(Organization::class)->findOneBy(['slug' => $goldOrg ? $goldOrg->getSlug() : 'test-gold-org']);

        // Test 1: LicenseService checks
        $this->assertFalse($licenseService->isBarcodeEnabled($basicOrg));
        $this->assertTrue($licenseService->isBarcodeEnabled($goldOrg));

        // Test 2: Basic Kiosk Scan endpoint returns 403
        $client->request('POST', sprintf('/org/%s/kiosk/%s/scan', $basicOrg->getSlug(), $basicOrg->getCheckInLinkToken()), [
            'pin' => '1234'
        ]);
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
        $responseContent = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($responseContent['success']);
        $this->assertStringContainsString('Barcode scanning is disabled', $responseContent['message']);

        // Test 3: Gold Kiosk Scan endpoint allows request (returns 404 because user PIN is not found, not 403 Forbidden)
        $client->request('POST', sprintf('/org/%s/kiosk/%s/scan', $goldOrg->getSlug(), $goldOrg->getCheckInLinkToken()), [
            'pin' => 'NONEXISTENT'
        ]);
        $this->assertEquals(404, $client->getResponse()->getStatusCode());
    }
}
