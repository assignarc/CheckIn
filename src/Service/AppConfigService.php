<?php

namespace App\Service;

use App\Entity\AppConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class AppConfigService
{
    private EntityManagerInterface $em;
    private CacheInterface $cache;

    public function __construct(EntityManagerInterface $em, CacheInterface $cache)
    {
        $this->em = $em;
        $this->cache = $cache;
    }

    public function get(string $key, $default = null)
    {
        // Cache the config for 1 hour to avoid DB hits on every request
        return $this->cache->get('app_config_' . $key, function (ItemInterface $item) use ($key, $default) {
            $item->expiresAfter(3600);

            $config = $this->em->getRepository(AppConfig::class)->findOneBy(['settingKey' => $key]);
            
            if ($config) {
                return $config->getSettingValue();
            }

            return $default;
        });
    }

    public function set(string $key, $value): void
    {
        $config = $this->em->getRepository(AppConfig::class)->findOneBy(['settingKey' => $key]);
        
        if (!$config) {
            $config = new AppConfig();
            $config->setSettingKey($key);
            $this->em->persist($config);
        }
        
        $config->setSettingValue($value);
        $this->em->flush();

        // Invalidate cache
        $this->cache->delete('app_config_' . $key);
    }
}
