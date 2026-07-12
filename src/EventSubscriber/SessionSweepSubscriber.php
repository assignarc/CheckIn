<?php

namespace App\EventSubscriber;

use App\Service\SessionSweeperService;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

class SessionSweepSubscriber implements EventSubscriberInterface
{
    private EntityManagerInterface $em;
    private SessionSweeperService $sweeper;
    private string $cacheDir;
    private \App\Service\AppConfigService $configService;

    public function __construct(EntityManagerInterface $em, SessionSweeperService $sweeper, KernelInterface $kernel, \App\Service\AppConfigService $configService)
    {
        $this->em = $em;
        $this->sweeper = $sweeper;
        $this->cacheDir = $kernel->getProjectDir() . '/var';
        $this->configService = $configService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 0]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $lockFile = $this->cacheDir . '/session_sweep.lock';
        $now = time();

        $throttle = (int)$this->configService->get('session_sweep_throttle_seconds', 60);
        if (file_exists($lockFile) && ($now - filemtime($lockFile)) < $throttle) {
            return;
        }

        // Touch the lock file to update the time immediately and prevent concurrent execution
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0777, true);
        }
        @touch($lockFile);

        try {
            $orgs = $this->em->getRepository(Organization::class)->findAll();
            foreach ($orgs as $org) {
                $this->sweeper->sweepActiveSessions($org);
            }
        } catch (\Exception $e) {
            // Silently fail to not block requests if database is not ready
        }
    }
}
