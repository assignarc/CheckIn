<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ManagerAccountAndOrgProfileEditTest extends WebTestCase
{
    public function testManagerCanEditAccountFieldsWithValidPayload(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        // Edit account (name, email, phone)
        $client->request('POST', '/manager/settings', [
            '_section' => 'my_account',
            'first_name' => 'AcmeManagerUpdated',
            'last_name' => 'ManagerLastName',
            'email' => 'manager@acme.com',
            'phone' => '+15551234567'
        ]);

        $this->assertResponseRedirects('/manager/settings');
        $client->followRedirect();
        $this->assertSelectorExists('.alert-success');

        $em->clear();
        $updatedManager = $em->getRepository(Manager::class)->find($manager->getId());
        $this->assertEquals('AcmeManagerUpdated', $updatedManager->getFirstName());
        $this->assertEquals('+15551234567', $updatedManager->getPhone());
    }

    public function testManagerAccountEditValidationFailures(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        // 1. Invalid first name (1 char < min 2)
        $client->request('POST', '/manager/settings', [
            '_section' => 'my_account',
            'first_name' => 'A',
            'last_name' => 'ValidLast',
            'email' => 'valid@acme.com'
        ]);
        $this->assertResponseRedirects('/manager/settings');
        $client->followRedirect();
        $this->assertSelectorExists('.alert-danger');

        // 2. Invalid email format
        $client->request('POST', '/manager/settings', [
            '_section' => 'my_account',
            'first_name' => 'ValidFirst',
            'last_name' => 'ValidLast',
            'email' => 'notanemail'
        ]);
        $this->assertResponseRedirects('/manager/settings');
        $client->followRedirect();
        $this->assertSelectorExists('.alert-danger');
    }

    public function testOrgProfileEditAddressAndPhone(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        $client->request('POST', '/manager/settings', [
            '_section' => 'org_profile',
            'org_name' => 'Acme Corporation',
            'org_phone' => '+18005550199',
            'org_address' => '100 Innovation Way, Suite 400, Austin, TX 78701',
            'org_timezone' => 'America/Chicago'
        ]);

        $this->assertResponseRedirects('/manager/settings');
        $client->followRedirect();
        $this->assertSelectorExists('.alert-success');

        $em->clear();
        $updatedOrg = $em->getRepository(Organization::class)->find($manager->getOrganization()->getId());
        $this->assertEquals('+18005550199', $updatedOrg->getPhone());
        $this->assertEquals('100 Innovation Way, Suite 400, Austin, TX 78701', $updatedOrg->getAddress());
        $this->assertEquals('America/Chicago', $updatedOrg->getTimezone());
    }
}
