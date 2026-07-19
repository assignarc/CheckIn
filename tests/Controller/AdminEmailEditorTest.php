<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminEmailEditorTest extends WebTestCase
{
    public function testEmailTemplatesEditorLoadsAndSaves(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // 1. Log in as platform administrator
        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $client->loginUser($admin);

        // 2. Load the templates index page
        $client->request('GET', '/admin/emails');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2.mb-1', 'Transactional Email Templates');

        // 3. Load the edit page for welcome template
        $client->request('GET', '/admin/emails/edit/welcome');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2.mb-1', 'Customize Email: Welcome');

        // 4. Save and restore content to verify writing works without breaking code
        $projectDir = $container->getParameter('kernel.project_dir');
        $filePath = $projectDir . '/templates/email/welcome.html.twig';
        $this->assertFileExists($filePath);
        $originalContent = file_get_contents($filePath);

        $testContent = $originalContent . "\n<!-- test editor save tag -->";

        // POST the modified content
        $client->request('POST', '/admin/emails/edit/welcome', [
            'content' => $testContent
        ]);
        $this->assertResponseRedirects('/admin/emails');

        // Verify content was saved
        $savedContent = file_get_contents($filePath);
        $this->assertStringContainsString('<!-- test editor save tag -->', $savedContent);

        // Restore original content
        file_put_contents($filePath, $originalContent);
    }
}
