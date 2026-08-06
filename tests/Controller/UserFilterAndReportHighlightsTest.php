<?php

namespace App\Tests\Controller;

use App\Entity\OrgUser;
use App\Entity\Manager;
use App\Entity\WorkSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserFilterAndReportHighlightsTest extends WebTestCase
{
    public function testUserDirectorySearchAndStatusFilter(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        // 1. Test search query parameter 'q'
        $client->request('GET', '/manager/users', ['q' => 'John']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'John');

        // 2. Test status filter = active
        $client->request('GET', '/manager/users', ['status' => 'active']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');

        // 3. Test status filter = inactive
        $client->request('GET', '/manager/users', ['status' => 'inactive']);
        $this->assertResponseIsSuccessful();
    }

    public function testReportPageHighlightsDataAttributes(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        $client->request('GET', '/manager/reports');
        $this->assertResponseIsSuccessful();

        // Assert highlight filter UI buttons exist
        $this->assertSelectorExists('.highlight-filter-btn[data-seconds="1800"]');
        $this->assertSelectorExists('.highlight-filter-btn[data-seconds="2700"]');
        $this->assertSelectorExists('.highlight-filter-btn[data-seconds="3600"]');
        $this->assertSelectorExists('#custom-highlight-mins');
        $this->assertSelectorExists('#highlight-custom-btn');
    }
}
