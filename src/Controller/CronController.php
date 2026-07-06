<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\Manager;
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
            return new JsonResponse(['error' => 'Invalid or missing token'], Response::HTTP_UNAUTHORIZED);
        }

        // Allow unlimited execution time for these background jobs
        set_time_limit(0);

        $results = [];

        // 1. Sweep Active Sessions
        $orgs = $em->getRepository(Organization::class)->findAll();
        $sweptCount = 0;
        foreach ($orgs as $org) {
            $sessionSweeper->sweepActiveSessions($org);
            $sweptCount++;
        }
        $results['sessions_swept_orgs'] = $sweptCount;

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

        // 3. Archive Old Audit Logs
        $archiveResult = $auditArchiveService->archiveLogs();
        $results['audit_archival'] = $archiveResult;

        return new JsonResponse(['status' => 'success', 'results' => $results]);
    }
}
