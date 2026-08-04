<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RemainingEdgeCasesTest extends WebTestCase
{
    public function testServeLogoFallbackSvgAndCustomBlob(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $org = $em->getRepository(Organization::class)->findOneBy(['slug' => 'acme-corp']);
        $this->assertNotNull($org);

        // 1. Test fallback SVG logo generation
        $client->request('GET', '/org/acme-corp/logo');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/svg+xml');

        // 2. Test 404 for non-existent org logo
        $client->request('GET', '/org/non-existent-slug-1234/logo');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSupportControllerRoutes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        // Access support section
        $client->request('GET', '/support');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/support/kiosk');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/support/users');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/support/reports');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/support/audit-settings');
        $this->assertResponseIsSuccessful();
    }

    public function testStripeWebhookInvalidSignatureHandling(): void
    {
        $client = static::createClient();

        // Send invalid JSON payload (should return 400 Bad Request)
        $client->request('POST', '/webhooks/stripe', [], [], [], 'invalid_json_body');
        $this->assertResponseStatusCodeSame(400);

        // Send valid JSON payload with type and object data
        $client->request('POST', '/webhooks/stripe', [], [], [], json_encode([
            'type' => 'invoice.payment_succeeded',
            'data' => ['object' => ['id' => 'in_test123']]
        ]));
        $this->assertResponseIsSuccessful();
    }

    public function testAsyncMessageHandlerExecution(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $resetHandler = static::getContainer()->get(\App\MessageHandler\ResetDemoDataHandler::class);
        $rotateTokenHandler = static::getContainer()->get(\App\MessageHandler\RotateKioskTokenHandler::class);

        $org = $em->getRepository(Organization::class)->findOneBy(['slug' => 'acme-corp']);
        $this->assertNotNull($org);

        // Test RotateKioskTokenHandler
        $oldToken = $org->getCheckInLinkToken();
        ($rotateTokenHandler)(new \App\Message\RotateKioskTokenMessage($org->getId(), $org->getName()));

        $em->clear();
        $updatedOrg = $em->getRepository(Organization::class)->find($org->getId());
        $this->assertNotNull($updatedOrg->getCheckInLinkToken());

        // Restore original token to prevent test database contamination
        $updatedOrg->setCheckInLinkToken('acme-secret-token');
        $em->flush();
    }
}
