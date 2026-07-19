<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ManagerBillingTest extends WebTestCase
{
    public function testManagerBillingPageLoads(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Log in as organization manager
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        // Load manager billing page
        $client->request('GET', '/manager/billing');
        $this->assertResponseIsSuccessful();

        // Assert elements
        $this->assertSelectorTextContains('h3', 'Subscription Overview');
        $this->assertSelectorTextContains('.glass-panel', 'Subscription Status');
        $this->assertSelectorTextContains('.glass-panel', 'Next Invoice Date');
        $this->assertSelectorTextContains('.glass-panel', 'Upcoming Bill');
    }
}
