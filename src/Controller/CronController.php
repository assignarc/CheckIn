<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\Manager;
use App\Entity\AuditLog;
use App\Service\SessionSweeperService;
use App\Service\AppConfigService;
use App\Service\AuditArchiveService;
use App\Message\RotateKioskTokenMessage;
use App\Message\RotatePasswordMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class CronController extends AbstractController
{
    #[Route('/cron/run', name: 'app_cron_run', methods: ['GET'])]
    public function runCron(
        Request $request,
        EntityManagerInterface $em,
        SessionSweeperService $sessionSweeper,
        AppConfigService $configService,
        MessageBusInterface $messageBus,
        AuditArchiveService $auditArchiveService
    ): Response {
        $token = $request->query->get('token');
        $expectedToken = $this->getParameter('cron_secret_token');

        if (!$expectedToken || $token !== $expectedToken) {
            $log = new AuditLog();
            $log->setAction('SYSTEM_CRON_AUTH_FAILED');
            $log->setDetailsJson(['ip' => $request->getClientIp()]);
            $log->setIpAddress($request->getClientIp());
            $log->setUserAgent($request->headers->get('User-Agent'));
            $em->persist($log);
            $em->flush();

            return new JsonResponse(['error' => 'Invalid or missing token'], Response::HTTP_UNAUTHORIZED);
        }

        // Allow unlimited execution time for these background jobs
        set_time_limit(0);

        $results = [];

        // 1. Sweep Active Sessions
        $orgs = $em->getRepository(Organization::class)->findAll();
        $sweptCount = 0;
        foreach ($orgs as $org) {
            $sweptCount += $sessionSweeper->sweepActiveSessions($org);
        }
        $results['sessions_swept_count'] = $sweptCount;

        // 2. Rotate Demo Credentials
        $lastRotation = (int)$configService->get('demo_last_rotated', 0);
        $demoRotationHours = (int)$configService->get('demo_rotation_hours', 12);
        
        $now = time();
        $rotationIntervalSeconds = $demoRotationHours * 60 * 60;

        if (($now - $lastRotation) >= $rotationIntervalSeconds) {
            $demoOrgs = $em->getRepository(Organization::class)->findBy([
                'slug' => ['basic-demo', 'golden-demo']
            ]);

            foreach ($demoOrgs as $org) {
                $messageBus->dispatch(new RotateKioskTokenMessage($org->getId(), $org->getName()));
                
                $managers = $em->getRepository(Manager::class)->findBy(['organization' => $org]);
                foreach ($managers as $manager) {
                    $messageBus->dispatch(new RotatePasswordMessage($org->getId(), $manager->getId()));
                }
            }
            
            $configService->set('demo_last_rotated', $now);
            $results['demo_rotation'] = 'executed';
        } else {
            $results['demo_rotation'] = 'skipped (not enough time passed)';
        }

        // 3. Archive Old Audit Logs according to the configured schedule
        $schedule = $configService->get('audit_log_archive_schedule', 'first_of_month');
        $shouldRunArchive = false;
        $nowDt = new \DateTime();

        switch ($schedule) {
            case 'daily':
                $shouldRunArchive = true;
                break;
            case 'weekly_sunday':
                $shouldRunArchive = ((int)$nowDt->format('w') === 0);
                break;
            case 'weekly_monday':
                $shouldRunArchive = ((int)$nowDt->format('w') === 1);
                break;
            case 'first_of_month':
                $shouldRunArchive = ($nowDt->format('j') === '1');
                break;
            case 'last_of_month':
                $tomorrow = (clone $nowDt)->modify('+1 day');
                $shouldRunArchive = ($nowDt->format('n') !== $tomorrow->format('n'));
                break;
        }

        if ($shouldRunArchive) {
            $archiveResult = $auditArchiveService->archiveLogs();
            $results['audit_archival'] = $archiveResult;
        } else {
            $results['audit_archival'] = 'skipped (does not match configured schedule: ' . $schedule . ')';
        }

        // 4. Check & Archive Expired Dunning Subscriptions
        $archivedCount = 0;
        $orgsToArchive = $em->getRepository(Organization::class)->findBy([
            'isSubscriptionActive' => false,
            'isArchived' => false,
        ]);
        foreach ($orgsToArchive as $org) {
            $latestPayment = $em->getRepository(\App\Entity\PaymentHistory::class)->findOneBy(
                ['organization' => $org],
                ['paidAt' => 'DESC']
            );

            if ($latestPayment && $latestPayment->getPeriodEnd() < new \DateTime()) {
                $org->setIsArchived(true);
                $archivedCount++;

                $archiveLog = new AuditLog();
                $archiveLog->setOrganization($org);
                $archiveLog->setAction('ORGANIZATION_AUTO_ARCHIVED_PAYMENT_FAILED');
                $archiveLog->setDetailsJson([
                    'period_end' => $latestPayment->getPeriodEnd()->format('Y-m-d H:i:s'),
                ]);
                $archiveLog->setIpAddress($request->getClientIp());
                $archiveLog->setUserAgent('Cron Sweeper');
                $em->persist($archiveLog);
            }
        }
        $results['orgs_auto_archived'] = $archivedCount;

        $log = new AuditLog();
        $log->setAction('SYSTEM_CRON_EXECUTION');
        $log->setDetailsJson($results);
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $em->persist($log);
        $em->flush();

        // 5. Clean up noisy empty cron logs older than configured hours to avoid db clutter
        $cronRetentionHours = (int)$configService->get('cron_empty_log_retention_hours', 2);
        $cronCutoffDate = new \DateTime("-{$cronRetentionHours} hours");
        $oldCronLogs = $em->getRepository(AuditLog::class)->createQueryBuilder('l')
            ->where('l.action = :action')
            ->andWhere('l.createdAt < :cutoff')
            ->setParameter('action', 'SYSTEM_CRON_EXECUTION')
            ->setParameter('cutoff', $cronCutoffDate)
            ->getQuery()
            ->getResult();

        foreach ($oldCronLogs as $oldLog) {
            $details = $oldLog->getDetailsJson();
            $swept = $details['sessions_swept_count'] ?? 0;
            $rotated = $details['demo_rotation'] ?? 'skipped';
            $archived = $details['audit_archival'] ?? 'skipped';
            $autoArchived = $details['orgs_auto_archived'] ?? 0;

            $rotatedStr = is_array($rotated) ? json_encode($rotated) : (string)$rotated;
            $archivedStr = is_array($archived) ? json_encode($archived) : (string)$archived;

            $didNothing = ($swept === 0 || $swept === '0')
                && str_contains($rotatedStr, 'skipped')
                && str_contains($archivedStr, 'skipped')
                && ($autoArchived === 0 || $autoArchived === '0');

            if ($didNothing) {
                $em->remove($oldLog);
            }
        }
        $em->flush();

        return new JsonResponse(['status' => 'success', 'results' => $results]);
    }

    #[Route('/cron/schema-update', name: 'app_cron_schema_update', methods: ['GET'])]
    public function updateSchema(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $token = $request->query->get('token');
        $expectedToken = $this->getParameter('cron_secret_token');
        if (!$expectedToken || $token !== $expectedToken) {
            return new JsonResponse(['error' => 'Invalid or missing token'], Response::HTTP_UNAUTHORIZED);
        }

        $sqls = [];

        try {
            $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
            $metadata = $em->getMetadataFactory()->getAllMetadata();
            $doctrineSqls = $schemaTool->getUpdateSchemaSql($metadata);

            if (!empty($doctrineSqls)) {
                $schemaTool->updateSchema($metadata);
                $sqls = array_merge($sqls, $doctrineSqls);
            }
        } catch (\Throwable $e) {
            // Silently continue to raw SQL migrations
        }

        // Direct SQL safety check to guarantee stripe_price_id column exists on plans table
        try {
            $em->getConnection()->executeStatement("ALTER TABLE plans ADD COLUMN IF NOT EXISTS stripe_price_id VARCHAR(100) NULL");
            $sqls[] = "ALTER TABLE plans ADD COLUMN IF NOT EXISTS stripe_price_id VARCHAR(100) NULL";
        } catch (\Throwable $e) {
            try {
                $em->getConnection()->executeStatement("ALTER TABLE plans ADD stripe_price_id VARCHAR(100) NULL");
                $sqls[] = "ALTER TABLE plans ADD stripe_price_id VARCHAR(100) NULL";
            } catch (\Throwable $ex) {
                // Column already exists
            }
        }

        // Direct SQL safety checks to guarantee unique_id and notes columns exist on org_users table
        foreach ([
            "ALTER TABLE org_users ADD unique_id VARCHAR(255) DEFAULT NULL",
            "ALTER TABLE org_users ADD notes JSON DEFAULT NULL",
        ] as $alterSql) {
            try {
                $em->getConnection()->executeStatement($alterSql);
                $sqls[] = $alterSql;
            } catch (\Throwable $ex) {
                // Column already exists
            }
        }

        return new JsonResponse([
            'status' => 'success',
            'queries_executed' => $sqls
        ]);
    }

    #[Route('/cron/clear-cache', name: 'app_cron_clear_cache', methods: ['GET'])]
    public function clearCache(Request $request, \Symfony\Component\HttpKernel\KernelInterface $kernel): JsonResponse
    {
        $token = $request->query->get('token');
        $expectedToken = $this->getParameter('cron_secret_token');
        if (!$expectedToken || $token !== $expectedToken) {
            return new JsonResponse(['error' => 'Invalid or missing token'], Response::HTTP_UNAUTHORIZED);
        }

        $cacheDir = $kernel->getCacheDir();
        if (is_dir($cacheDir)) {
            $filesystem = new \Symfony\Component\Filesystem\Filesystem();
            $filesystem->remove($cacheDir);
        }

        return new JsonResponse([
            'status' => 'success',
            'message' => 'Symfony cache cleared successfully'
        ]);
    }
}
