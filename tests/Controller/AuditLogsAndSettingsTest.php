<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Service\AppConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuditLogsAndSettingsTest extends WebTestCase
{
    public function testManagerSettingsAndAuditLogs(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // 1. Log in as manager
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        // 2. Load manager settings page
        $client->request('GET', '/manager/settings');
        $this->assertResponseIsSuccessful();

        // 3. Load manager audit logs page
        $client->request('GET', '/manager/audit-logs');
        $this->assertResponseIsSuccessful();
    }

    public function testAdminGlobalSettings(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var AppConfigService $configService */
        $configService = $container->get(AppConfigService::class);

        // 1. Log in as platform administrator
        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $this->assertNotNull($admin);
        $client->loginUser($admin);

        // 2. Load admin settings page
        $client->request('GET', '/admin/settings');
        $this->assertResponseIsSuccessful();

        // 3. Post a timezone setting change
        $client->request('POST', '/admin/settings', [
            'display_timezone' => 'America/New_York',
            'allowed_phone_countries' => ['us', 'ca'],
            'max_import_records' => 150,
            'demo_rotation_hours' => 24,
            'new_signups_enabled' => '1',
            'audit_log_archive_days' => 120,
            'session_sweep_throttle_seconds' => 120,
            'demo_reset_history_days' => 180,
            'audit_log_archive_dir' => '/var/log/test_archives',
            'audit_log_archive_schedule' => 'weekly_sunday',
        ]);

        $this->assertResponseRedirects('/admin/settings');
        $client->followRedirect();

        // 4. Verify that settings were updated in the database config service
        $this->assertEquals('America/New_York', $configService->get('display_timezone'));
        $this->assertEquals(150, $configService->get('max_import_records'));
        $this->assertEquals('weekly_sunday', $configService->get('audit_log_archive_schedule'));
    }
}
