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
            if ($org->getPlan() && strtolower($org->getPlan()->getName()) === 'basic') {
                $basicOrg = $org;
            } elseif ($org->getPlan() && strtolower($org->getPlan()->getName()) === 'gold') {
                $goldOrg = $org;
            }
        }

        $basicPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'basic'])
                  ?? $em->getRepository(Plan::class)->findOneBy(['name' => 'Basic']);
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
            $em->persist($basicPlan);
        }
        $basicPlan->setHasBarcodeFeatures(false);

        $goldPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'gold'])
                 ?? $em->getRepository(Plan::class)->findOneBy(['name' => 'Gold']);
        if (!$goldPlan) {
            $goldPlan = new Plan();
            $goldPlan->setName('Gold');
            $goldPlan->setMaxOrgUsers(1000);
            $goldPlan->setMaxManagers(9999);
            $goldPlan->setMaxFields(99);
            $goldPlan->setMaxCategories(99);
            $goldPlan->setSessionLimitHours(24);
            $goldPlan->setRetentionDays(365);
            $goldPlan->setExportsEnabled(true);
            $goldPlan->setPriceMonthly('99.00');
            $em->persist($goldPlan);
        }

        $plans = $em->getRepository(Plan::class)->findAll();
        foreach ($plans as $p) {
            if (in_array(strtolower($p->getName()), ['gold', 'platinum'])) {
                $p->setHasBarcodeFeatures(true);
            } elseif (strtolower($p->getName()) === 'basic') {
                $p->setHasBarcodeFeatures(false);
            }
        }
        $em->flush();

        if (!$basicOrg) {
            $basicOrg = new Organization();
            $basicOrg->setName('Test Basic Org');
            $basicOrg->setSlug('test-basic-org');
            $em->persist($basicOrg);
        }
        $basicOrg->setCheckInLinkToken('basic-token');
        $basicOrg->setIsSubscriptionActive(true);
        $basicOrg->setIsArchived(false);
        $basicOrg->setPlan($basicPlan);
        $basicOrg->clearOverrides();

        if (!$goldOrg) {
            $goldOrg = new Organization();
            $goldOrg->setName('Test Gold Org');
            $goldOrg->setSlug('test-gold-org');
            $em->persist($goldOrg);
        }
        $goldOrg->setCheckInLinkToken('gold-token');
        $goldOrg->setIsSubscriptionActive(true);
        $goldOrg->setIsArchived(false);
        $goldOrg->setPlan($goldPlan);
        $goldOrg->clearOverrides();

        $em->flush();

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
