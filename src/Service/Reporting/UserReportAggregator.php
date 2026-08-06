<?php

namespace App\Service\Reporting;

use App\Entity\Organization;
use App\Entity\WorkSession;
use Doctrine\ORM\EntityManagerInterface;

class UserReportAggregator
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Aggregates work sessions by category and user metrics.
     */
    public function getCategoryBreakdown(Organization $org): array
    {
        $sessions = $this->em->getRepository(WorkSession::class)->findBy(['organization' => $org]);
        $breakdown = [];

        foreach ($sessions as $session) {
            $catName = $session->getCategory() ? $session->getCategory()->getName() : 'General';
            if (!isset($breakdown[$catName])) {
                $breakdown[$catName] = 0.0;
            }
            $breakdown[$catName] += round(($session->getDurationSeconds() ?? 0) / 3600, 1);
        }

        return $breakdown;
    }
}
