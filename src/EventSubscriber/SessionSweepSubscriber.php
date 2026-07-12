<?php

namespace App\EventSubscriber;

use App\Service\SessionSweeperService;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Request listener that runs active session sweeps automatically.
 * 
 * Rationale:
 * - Sweeping ensures users don't remain clocked in past allowed limits.
 * - Triggering sweeps on incoming requests avoids requiring a cron runner for basic demo usage.
 * - Employs a file lock to throttle executions to once per `session_sweep_throttle_seconds` to minimize DB load.
 */
class SessionSweepSubscriber implements EventSubscriberInterface
{
    private EntityManagerInterface $em;
    private SessionSweeperService $sweeper;
    private string $cacheDir;
    private \App\Service\AppConfigService $configService;

    public function __construct(
        EntityManagerInterface $em,
        SessionSweeperService $sweeper,
        KernelInterface $kernel,
        \App\Service\AppConfigService $configService
    ) {
        $this->em = $em;
        $this->sweeper = $sweeper;
        $this->cacheDir = $kernel->getProjectDir() . '/var';
        $this->configService = $configService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Subscribe to the initial Kernel Request event
            KernelEvents::REQUEST => [['onKernelRequest', 0]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Skip sub-requests (e.g. controller forwards, twig renders)
        if (!$event->isMainRequest()) {
            return;
        }

        $lockFile = $this->cacheDir . '/session_sweep.lock';
        $now = time();

        // 1. Throttling Lock: Only run the sweeps if the lock file is older than configured seconds
        $throttle = (int)$this->configService->get('session_sweep_throttle_seconds', 60);
        if (file_exists($lockFile) && ($now - filemtime($lockFile)) < $throttle) {
            return;
        }

        // 2. Touch/Create lock file immediately to prevent concurrent requests from double-triggering
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0777, true);
        }
        @touch($lockFile);

        try {
            // 3. Scan all registered organizations and terminate expired clock-ins
            $orgs = $this->em->getRepository(Organization::class)->findAll();
            foreach ($orgs as $org) {
                $this->sweeper->sweepActiveSessions($org);
            }
        } catch (\Exception $e) {
            // Silently fail to avoid blocking client requests if database is not fully loaded/migrated
        }
    }
}
