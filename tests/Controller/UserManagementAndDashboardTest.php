<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\OrgUser;
use App\Entity\Plan;
use App\Entity\WorkSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserManagementAndDashboardTest extends WebTestCase
{
    public function testCreateActivateDeactivateResetPinAndEditUser(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        // 1. Create a new user
        $client->request('POST', '/manager/users/add', [
            'first_name' => 'TestStudent',
            'last_name' => 'Automation',
            'email' => 'test.automation@acme.com',
            'phone' => '+15559990000',
            'pin' => 'B999'
        ]);

        $this->assertResponseRedirects('/manager/users');
        $client->followRedirect();

        $em->clear();
        $user = $em->getRepository(OrgUser::class)->findOneBy(['email' => 'test.automation@acme.com']);
        $this->assertNotNull($user);
        $this->assertEquals('B999', $user->getPin());
        $this->assertTrue($user->isActive());

        // 2. Edit user name
        $client->request('POST', '/manager/users/edit/' . $user->getId(), [
            'first_name' => 'TestStudentEdited',
            'last_name' => 'AutomationEdited',
            'email' => 'test.automation@acme.com',
            'phone' => '+15559990000',
            'pin' => 'B999'
        ]);
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $editedUser = $em->getRepository(OrgUser::class)->find($user->getId());
        $this->assertEquals('TestStudentEdited', $editedUser->getFirstName());

        // 3. Reset user PIN
        $oldPinHash = $editedUser->getPinHash();
        $client->request('POST', '/manager/users/reset-pin/' . $user->getId());
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $pinResetUser = $em->getRepository(OrgUser::class)->find($user->getId());
        $this->assertNotEquals($oldPinHash, $pinResetUser->getPinHash());

        // 4. Deactivate user (Toggle)
        $client->request('POST', '/manager/users/toggle/' . $user->getId());
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $inactiveUser = $em->getRepository(OrgUser::class)->find($user->getId());
        $this->assertFalse($inactiveUser->isActive());

        // 5. Reactivate user (Toggle back)
        $client->request('POST', '/manager/users/toggle/' . $user->getId());
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $reactivatedUser = $em->getRepository(OrgUser::class)->find($user->getId());
        $this->assertTrue($reactivatedUser->isActive());

        // Cleanup
        $em->remove($reactivatedUser);
        $em->flush();
    }

    public function testInstructorManagementAddEditToggle(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        // 1. Add new instructor / manager
        $client->request('POST', '/manager/users/add-manager', [
            'first_name' => 'InstructorJohn',
            'last_name' => 'InstructorDoe',
            'email' => 'instructor.john@acme.com',
            'phone' => '+15557778888',
            'password' => 'securepass123'
        ]);

        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $newInstructor = $em->getRepository(Manager::class)->findOneBy(['email' => 'instructor.john@acme.com']);
        $this->assertNotNull($newInstructor);

        // 2. Edit instructor
        $client->request('POST', '/manager/users/edit-manager/' . $newInstructor->getId(), [
            'first_name' => 'InstructorJohnEdited',
            'last_name' => 'InstructorDoe',
            'email' => 'instructor.john@acme.com',
            'phone' => '+15557778888'
        ]);

        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $editedInstructor = $em->getRepository(Manager::class)->find($newInstructor->getId());
        $this->assertEquals('InstructorJohnEdited', $editedInstructor->getFirstName());

        // Cleanup
        $em->remove($editedInstructor);
        $em->flush();
    }

    public function testCheckedInUsersPageAndForceCheckout(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $manager->getOrganization()]);

        // Create active work session
        $session = new WorkSession();
        $session->setOrgUser($orgUser);
        $session->setOrganization($manager->getOrganization());
        $session->setStartedAt(new \DateTime('-20 minutes'));
        $session->setStartMethod('secure');
        $em->persist($session);
        $em->flush();

        // Request checked-in users page
        $client->request('GET', '/manager/users/checked-in');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', $orgUser->getFirstName());

        // Force check-out session from reports force end endpoint
        $client->request('POST', '/manager/reports/force-end/' . $session->getId());
        $this->assertResponseRedirects();

        $em->clear();
        $endedSession = $em->getRepository(WorkSession::class)->find($session->getId());
        $this->assertNotNull($endedSession->getEndedAt());
        $this->assertEquals('Force End', $endedSession->getEndMethod());

        // Cleanup
        $em->remove($endedSession);
        $em->flush();
    }

    public function testAdminAndManagerDashboards(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // 1. Manager Dashboard
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        $client->request('GET', '/manager/dashboard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.dashboard-header');
        $this->assertSelectorExists('.metric-card');

        // 2. Admin Dashboard
        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $client->loginUser($admin);

        $client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.dashboard-header');
        $this->assertSelectorTextContains('body', 'Platform Overview');
    }
}
