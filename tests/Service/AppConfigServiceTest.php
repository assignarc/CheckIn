<?php

namespace App\Tests\Service;

use App\Service\AppConfigService;
use App\Entity\AppConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for the global configuration AppConfigService.
 */
class AppConfigServiceTest extends KernelTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private ?AppConfigService $configService = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->configService = $container->get(AppConfigService::class);
        
        // Ensure clean slate before each test run
        $this->clearConfig('test_key_default');
        $this->clearConfig('test_key_set');
        $this->clearConfig('test_key_update');
    }

    public function testGetDefaultValue(): void
    {
        // Should return fallback default value if key doesn't exist in DB
        $val = $this->configService->get('test_key_default', 'fallback_default');
        $this->assertEquals('fallback_default', $val);
    }

    public function testSetAndGetConfig(): void
    {
        // Set new key
        $this->configService->set('test_key_set', 'custom_value');

        // Should return the saved value
        $val = $this->configService->get('test_key_set', 'fallback_default');
        $this->assertEquals('custom_value', $val);

        // Verify it was persisted to the database
        $configEntity = $this->entityManager->getRepository(AppConfig::class)->findOneBy(['settingKey' => 'test_key_set']);
        $this->assertNotNull($configEntity);
        $this->assertEquals('custom_value', $configEntity->getSettingValue());
    }

    public function testUpdateConfig(): void
    {
        $this->configService->set('test_key_update', 'initial_val');
        $this->configService->set('test_key_update', 'updated_val');

        $val = $this->configService->get('test_key_update');
        $this->assertEquals('updated_val', $val);
    }

    private function clearConfig(string $key): void
    {
        $config = $this->entityManager->getRepository(AppConfig::class)->findOneBy(['settingKey' => $key]);
        if ($config) {
            $this->entityManager->remove($config);
            $this->entityManager->flush();
        }
    }

    protected function tearDown(): void
    {
        $this->clearConfig('test_key_default');
        $this->clearConfig('test_key_set');
        $this->clearConfig('test_key_update');
        parent::tearDown();
        $this->entityManager = null;
        $this->configService = null;
    }
}
