<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\PaymentHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminBillingDashboardTest extends WebTestCase
{
    public function testAdminBillingDashboardLoads(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Log in as platform administrator
        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $this->assertNotNull($admin);
        $client->loginUser($admin);

        // Load the admin billing page
        $client->request('GET', '/admin/billing');
        $this->assertResponseIsSuccessful();

        // Assert dashboard headers and metrics cards
        $this->assertSelectorTextContains('h2.mb-1', 'Billing & Revenue Dashboard');
        $this->assertSelectorTextContains('body', 'Total Revenue Collected');
        $this->assertSelectorTextContains('body', 'Active Paid Subscriptions');

        // Test search filter
        $client->request('GET', '/admin/billing', ['q' => 'Acme']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'Acme Corporation');
    }
}
