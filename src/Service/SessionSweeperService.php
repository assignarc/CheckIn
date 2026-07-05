<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\WorkSession;
use Doctrine\ORM\EntityManagerInterface;

class SessionSweeperService
{
    private EntityManagerInterface $em;
    private SystemAuditLogger $auditLogger;

    public function __construct(EntityManagerInterface $em, SystemAuditLogger $auditLogger)
    {
        $this->em = $em;
        $this->auditLogger = $auditLogger;
    }

    public function sweepActiveSessions(Organization $org): void
    {
        $activeSessions = $this->em->getRepository(WorkSession::class)->findBy([
            'organization' => $org,
            'endedAt' => null
        ]);

        $now = new \DateTimeImmutable();
        $sweptCount = 0;

        foreach ($activeSessions as $session) {
            $startedAt = $session->getStartedAt();
            $category = $session->getCategory();

            $limitSeconds = $org->getSessionLimitHours() * 3600;

            if ($org->getPlan()->hasCustomSessionDurations() && $category && $category->getMaxSessionDurationMinutes() !== null) {
                $limitSeconds = $category->getMaxSessionDurationMinutes() * 60;
            }
            $elapsedSeconds = $now->getTimestamp() - $startedAt->getTimestamp();

            $shouldTerminate = false;
            if ($org->getPlan()->getName() === 'basic') {
                $shouldTerminate = true;
            } elseif ($category && $category->isTerminateAtMaxDuration()) {
                $shouldTerminate = true;
            }

            if ($elapsedSeconds >= $limitSeconds && $shouldTerminate) {
                $endedAt = \DateTime::createFromInterface($startedAt)->modify("+{$limitSeconds} seconds");
                $session->setEndedAt($endedAt);
                $session->setDurationSeconds($limitSeconds);
                $session->setEndMethod('Auto End');
                $session->setIsOvertime(false);
                $this->em->persist($session);
                
                $sweptCount++;
            }
        }
        
        $this->em->flush();

        if ($sweptCount > 0) {
            $this->auditLogger->log('SYSTEM_SESSION_SWEEP', [
                'organization_id' => $org->getId(),
                'organization_name' => $org->getName(),
                'swept_sessions_count' => $sweptCount
            ]);
        }
    }
}
