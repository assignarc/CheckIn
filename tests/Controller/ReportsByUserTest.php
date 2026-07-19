<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\OrgUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ReportsByUserTest extends WebTestCase
{
    public function testReportsByUserLoadsAndRetainsViewState(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $manager->getOrganization()]);
        $this->assertNotNull($orgUser);

        // 1. Check page loads with default view (calendar)
        $client->request('GET', '/manager/reports/by-user', [
            'org_user_id' => $orgUser->getId(),
            'month' => '2026-05'
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#calendar-view-container');
        $this->assertSelectorNotExists('#calendar-view-container.d-none');

        // 2. Check loading with explicit view=list
        $client->request('GET', '/manager/reports/by-user', [
            'org_user_id' => $orgUser->getId(),
            'month' => '2026-05',
            'view' => 'list'
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#list-view-container');
        $this->assertSelectorNotExists('#list-view-container.d-none');
    }
}
