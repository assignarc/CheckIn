<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\OrgUser;
use App\Entity\WorkSession;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ManualCheckoutAndAdminPasswordTest extends WebTestCase
{
    public function testManualCheckoutByManager(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        // Find or create active session
        $session = $em->getRepository(WorkSession::class)->findOneBy([
            'organization' => $manager->getOrganization(),
            'endedAt' => null
        ]);

        if (!$session) {
            $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $manager->getOrganization()]);
            $session = new WorkSession();
            $session->setOrganization($manager->getOrganization());
            $session->setOrgUser($orgUser);
            $session->setStartedAt(new \DateTime('-1 hour'));
            $em->persist($session);
            $em->flush();
        }

        $sessionId = $session->getId();
        $client->request('POST', '/manager/reports/force-end/' . $sessionId);
        $this->assertResponseRedirects();

        $em->clear();
        $updatedSession = $em->getRepository(WorkSession::class)->find($sessionId);
        $this->assertNotNull($updatedSession);
        $this->assertNotNull($updatedSession->getEndedAt());
    }

    public function testAdminResetManagerPassword(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        if (!$admin) {
            $admin = new Manager();
            $admin->setEmail('admin@centertime.us');
            $admin->setFirstName('System');
            $admin->setLastName('Admin');
            $admin->setPasswordHash('$2y$13$dummyhashedpassword');
            $em->persist($admin);
        }
        $admin->setRole('ROLE_SYSTEM_ADMIN');
        $em->flush();

        $client->loginUser($admin);

        $targetOrg = $em->getRepository(Organization::class)->findOneBy(['slug' => 'acme-corp']);
        $this->assertNotNull($targetOrg);
        $targetManager = $em->getRepository(Manager::class)->findOneBy(['organization' => $targetOrg]);
        $this->assertNotNull($targetManager);

        $client->request('POST', '/admin/organizations/' . $targetOrg->getId() . '/reset-manager-password', [
            'manager_email' => $targetManager->getEmail(),
            'new_password' => 'NewSecurePass123!'
        ]);

        $this->assertResponseRedirects('/admin/organizations');
    }
}
