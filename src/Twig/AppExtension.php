<?php

namespace App\Twig;

use App\Service\AppConfigService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    private AppConfigService $appConfigService;

    public function __construct(AppConfigService $appConfigService)
    {
        $this->appConfigService = $appConfigService;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_config', [$this, 'getAppConfig']),
        ];
    }

    public function getAppConfig(string $key, $default = null)
    {
        return $this->appConfigService->get($key, $default);
    }
}
