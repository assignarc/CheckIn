<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminPagesAndThemeTest extends WebTestCase
{
    public function testAdminOrganizationsPageRendersNamesAndThemeClasses(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $this->assertNotNull($admin);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/organizations');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'Acme Corp');
        $this->assertSelectorExists('td[style*="var(--text-primary)"]');
    }

    public function testAdminCommandsPageRendersCommandsAndThemeClasses(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/commands');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'app:seed-data');
        $this->assertSelectorExists('td[style*="var(--text-primary)"]');
    }

    public function testAdminPlansPageRendersPlansAndThemeClasses(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/plans');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'basic');
        $this->assertSelectorTextContains('table', 'gold');
        $this->assertSelectorExists('td[style*="var(--text-primary)"]');
    }
}
