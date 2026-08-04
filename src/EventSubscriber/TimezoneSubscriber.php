<?php

namespace App\EventSubscriber;

use App\Service\AppConfigService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

class TimezoneSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AppConfigService $configService,
        private Environment $twig,
        private Security $security
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $org = null;
        $user = $this->security->getUser();
        if ($user && method_exists($user, 'getOrganization')) {
            $org = $user->getOrganization();
        }

        $timezone = $this->configService->resolveOrganizationTimezone($org);

        try {
            $this->twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone($timezone);
        } catch (\Exception $e) {
            // Ignore if timezone extension cannot be configured dynamically
        }
    }
}
