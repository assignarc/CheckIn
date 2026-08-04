<?php

namespace App\Tests\Service;

use App\Entity\Organization;
use App\Entity\WorkSession;
use App\Entity\Plan;
use App\Service\SessionSweeperService;
use App\Service\SystemAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class SessionSweeperServiceTest extends TestCase
{
    private SessionSweeperService $service;
    private EntityManagerInterface $em;
    private SystemAuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(SystemAuditLogger::class);

        $this->service = new SessionSweeperService($this->em, $this->auditLogger);
    }

    public function testSweepActiveSessions(): void
    {
        $plan = new Plan();
        $plan->setHasCustomSessionDurations(false);
        $plan->setSessionLimitHours(1);
        
        $org = new Organization();
        $org->setPlan($plan);
        $org->setName('Test Org');

        $session1 = new WorkSession();
        // Started 2 hours ago (should be swept)
        $session1->setStartedAt((new \DateTimeImmutable())->modify('-2 hours'));

        $session2 = new WorkSession();
        // Started 30 mins ago (should not be swept)
        $session2->setStartedAt((new \DateTimeImmutable())->modify('-30 minutes'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->willReturn([$session1, $session2]);

        $this->em->expects($this->once())
            ->method('getRepository')
            ->with(WorkSession::class)
            ->willReturn($repository);

        // We expect one persist call for session1
        $this->em->expects($this->once())
            ->method('persist')
            ->with($session1);

        $this->em->expects($this->once())
            ->method('flush');

        $this->auditLogger->expects($this->once())
            ->method('log')
            ->with('SYSTEM_SESSION_SWEEP', $this->anything());

        $count = $this->service->sweepActiveSessions($org);

        $this->assertSame(1, $count);
        $this->assertNotNull($session1->getEndedAt());
        $this->assertSame(3600, $session1->getDurationSeconds());
        $this->assertSame('Auto End', $session1->getEndMethod());
        $this->assertNull($session2->getEndedAt());
    }
}
