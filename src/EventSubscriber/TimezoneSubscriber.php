<?php

namespace App\EventSubscriber;

use App\Service\AppConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

class TimezoneSubscriber implements EventSubscriberInterface
{
    private $configService;
    private $twig;

    public function __construct(AppConfigService $configService, Environment $twig)
    {
        $this->configService = $configService;
        $this->twig = $twig;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $timezone = $this->configService->get('display_timezone', 'America/Chicago');
        try {
            $this->twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone($timezone);
        } catch (\Exception $e) {
            // Ignore if timezone extension cannot be configured dynamically
        }
    }
}
