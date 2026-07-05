<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;

class SystemAuditLogger
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function log(string $action, array $details = []): void
    {
        $log = new AuditLog();
        
        // Retrieve System Organization (id = 0)
        $systemOrg = $this->em->getRepository(Organization::class)->find(0);
        if ($systemOrg) {
            $log->setOrganization($systemOrg);
        }

        $log->setAction($action);
        $log->setDetailsJson($details);
        $log->setIpAddress('127.0.0.1');
        $log->setUserAgent('SYSTEM_CRON');

        $this->em->persist($log);
        $this->em->flush();
    }
}
