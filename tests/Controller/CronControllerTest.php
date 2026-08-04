<?php

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CronControllerTest extends WebTestCase
{
    public function testCronRunLogging(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // 1. Request without valid token (should log auth failure and return 401)
        $client->request('GET', '/cron/run');
        $this->assertResponseStatusCodeSame(401);

        $logFailed = $em->getRepository(AuditLog::class)->findOneBy(
            ['action' => 'SYSTEM_CRON_AUTH_FAILED'],
            ['createdAt' => 'DESC']
        );
        $this->assertNotNull($logFailed);

        // 2. Request with valid token (should log success execution and return 200)
        $expectedToken = $container->getParameter('cron_secret_token');
        $client->request('GET', '/cron/run', ['token' => $expectedToken]);
        $this->assertResponseIsSuccessful();

        $logSuccess = $em->getRepository(AuditLog::class)->findOneBy(
            ['action' => 'SYSTEM_CRON_EXECUTION'],
            ['createdAt' => 'DESC']
        );
        $this->assertNotNull($logSuccess);
        $this->assertArrayHasKey('sessions_swept_count', $logSuccess->getDetailsJson());
    }

    public function testSchemaUpdateEndpointAuthorization(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // 1. Request without valid token (401 Unauthorized)
        $client->request('GET', '/cron/schema-update');
        $this->assertResponseStatusCodeSame(401);

        // 2. Request with valid secret token (200 OK)
        $expectedToken = $container->getParameter('cron_secret_token');
        $client->request('GET', '/cron/schema-update', ['token' => $expectedToken]);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('success', (string)$client->getResponse()->getContent());
    }
}
