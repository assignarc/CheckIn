<?php

namespace App\MessageHandler;

use App\Entity\Category;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\OrgUser;
use App\Entity\Plan;
use App\Entity\WorkSession;
use App\Message\ResetDemoDataMessage;
use App\Message\RotateKioskTokenMessage;
use App\Message\RotatePasswordMessage;
use App\Service\LicenseService;
use App\Service\PinService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;



#[AsMessageHandler]
class ResetDemoDataHandler
{
    /**
     * @var EntityManagerInterface Manage persistence state of entities
     */
    private EntityManagerInterface $em;

    /**
     * @var MessageBusInterface For dispatching password and token rotations asynchronously
     */
    private MessageBusInterface $messageBus;

    /**
     * @var ParameterBagInterface To read project root and config locations
     */
    private ParameterBagInterface $params;

    /**
     * @var PinService Handles generation and caching of conforming 4-char employee PINs
     */
    private PinService $pinService;

    /**
     * @var LicenseService Validates that employee creation fits plan-specific thresholds
     */
    private LicenseService $licenseService;

    /**
     * @var \App\Service\SystemAuditLogger Logs platform-wide audit log indicators
     */
    private \App\Service\SystemAuditLogger $auditLogger;

    /**
     * @var \App\Service\AppConfigService Handles platform configurations
     */
    private \App\Service\AppConfigService $configService;

    public function __construct(
        EntityManagerInterface $em,
        MessageBusInterface $messageBus,
        ParameterBagInterface $params,
        PinService $pinService,
        LicenseService $licenseService,
        \App\Service\SystemAuditLogger $auditLogger,
        \App\Service\AppConfigService $configService
    ) {
        $this->em = $em;
        $this->messageBus = $messageBus;
        $this->params = $params;
        $this->pinService = $pinService;
        $this->licenseService = $licenseService;
        $this->auditLogger = $auditLogger;
        $this->configService = $configService;
    }

    /**
     * Resets both basic and golden demo organizations to a fresh state.
     * 
     * Rationale:
     * - Preserves organization table IDs in-place using targeted raw SQL drops on child rows.
     * - Seeds 100 demo employees from seed_data.json split 50/50 between the two organizations.
     * - Generates historical work logs for each employee based on the configured history days.
     */
    public function __invoke(ResetDemoDataMessage $message)
    {
        // Allow unlimited memory and time for massive entity creation + Symfony Profiler overhead
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        // 1. Audit Start: Let administrators know the asynchronous workflow has initiated
        $this->auditLogger->log('SYSTEM_DEMO_DATA_RESET_START', [
            'status' => 'started'
        ]);

        // Load configurations from config/seed_data.json
        $configPath = $this->params->get('kernel.project_dir') . '/config/seed_data.json';
        $config = json_decode(file_get_contents($configPath), true);
        $demoConfigs = $config['demo_organizations'] ?? [];
        $basicConf = $demoConfigs['basic'] ?? [];
        $goldenConf = $demoConfigs['golden'] ?? [];

        // 2. Fetch existing Demo Organizations to see if we can reuse them (preserving IDs)
        $bOrg = $this->em->getRepository(Organization::class)->findOneBy(['slug' => $basicConf['slug'] ?? 'basic-demo']);
        $gOrg = $this->em->getRepository(Organization::class)->findOneBy(['slug' => $goldenConf['slug'] ?? 'golden-demo']);

        // Collect existing IDs to wipe only their child/dependent records
        $orgIds = [];
        if ($bOrg) {
            $orgIds[] = $bOrg->getId();
        }
        if ($gOrg) {
            $orgIds[] = $gOrg->getId();
        }

        // Purge child/dependent records using raw SQL queries.
        // This is much faster than loading entities and preserves Organization primary key IDs in-place.
        if (!empty($orgIds)) {
            $idsStr = implode(',', $orgIds);
            // Delete dependent records to start fresh under the same Org IDs
            $this->em->getConnection()->executeStatement("DELETE FROM work_sessions WHERE organization_id IN ({$idsStr})");
            $this->em->getConnection()->executeStatement("DELETE FROM audit_logs WHERE organization_id IN ({$idsStr})");
            $this->em->getConnection()->executeStatement("DELETE FROM org_users WHERE organization_id IN ({$idsStr})");
            $this->em->getConnection()->executeStatement("DELETE FROM categories WHERE organization_id IN ({$idsStr})");
            $this->em->getConnection()->executeStatement("DELETE FROM managers WHERE organization_id IN ({$idsStr})");
        }

        // 3. Setup/Update Basic Demo Org (Basic Plan, Open Access)
        $basicPlan = $this->em->getRepository(Plan::class)->findOneBy(['name' => $basicConf['plan'] ?? 'basic']);
        if (!$bOrg) {
            $bOrg = new Organization();
        }
        $bOrg->setName($basicConf['name'] ?? 'Basic Demo Company');
        $bOrg->setSlug($basicConf['slug'] ?? 'basic-demo');
        $bOrg->setPlan($basicPlan);
        $bOrg->clearOverrides();
        $bOrg->setTimezone(null);
        $bOrg->setAccessMode($basicConf['access_mode'] ?? 'open');
        $bOrg->setCheckInLinkToken($basicConf['token'] ?? 'temp_token_b');
        $bOrg->setLogoPath(null); 
        $bOrg->setLogoBlob(null); // Reset logo on refresh
        $bOrg->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist($bOrg);

        $bMgrConf = $basicConf['manager'] ?? [];
        $bManager = new Manager();
        $bManager->setEmail($bMgrConf['email'] ?? 'basic@platform.com');
        $bManager->setFirstName($bMgrConf['first_name'] ?? 'Basic');
        $bManager->setLastName($bMgrConf['last_name'] ?? 'Manager');
        $bManager->setRole('ROLE_ORG_MANAGER');
        $bManager->setOrganization($bOrg);
        $bManager->setIsActive(true);
        $bManager->setPasswordHash($bMgrConf['password'] ?? 'temp_hash_b');
        $this->em->persist($bManager);

        $bCatConf = $basicConf['category'] ?? [];
        $bCat = new Category();
        $bCat->setName($bCatConf['name'] ?? 'General Shift');
        $bCat->setIcon($bCatConf['icon'] ?? 'work');
        $bCat->setColor($bCatConf['color'] ?? '#10b981');
        $bCat->setOrganization($bOrg);
        $bCat->setIsActive(true);
        $bCat->setMaxSessionDurationMinutes($bCatConf['max_duration_minutes'] ?? 480);
        $this->em->persist($bCat);

        // 4. Setup/Update Golden Demo Org (Gold Plan, Secure Access)
        $goldPlan = $this->em->getRepository(Plan::class)->findOneBy(['name' => $goldenConf['plan'] ?? 'gold']);
        if (!$gOrg) {
            $gOrg = new Organization();
        }
        $gOrg->setName($goldenConf['name'] ?? 'Golden Demo Company');
        $gOrg->setSlug($goldenConf['slug'] ?? 'golden-demo');
        $gOrg->setPlan($goldPlan);
        $gOrg->clearOverrides();
        $gOrg->setTimezone(null);
        $gOrg->setAccessMode($goldenConf['access_mode'] ?? 'secure');
        $gOrg->setCheckInLinkToken($goldenConf['token'] ?? 'temp_token_g');
        $gOrg->setLogoPath(null);
        $gOrg->setLogoBlob(null); // Reset logo on refresh
        $gOrg->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist($gOrg);

        $gMgrConf = $goldenConf['manager'] ?? [];
        $gManager = new Manager();
        $gManager->setEmail($gMgrConf['email'] ?? 'golden@platform.com');
        $gManager->setFirstName($gMgrConf['first_name'] ?? 'Golden');
        $gManager->setLastName($gMgrConf['last_name'] ?? 'Manager');
        $gManager->setRole('ROLE_ORG_MANAGER');
        $gManager->setOrganization($gOrg);
        $gManager->setIsActive(true);
        $gManager->setPasswordHash($gMgrConf['password'] ?? 'temp_hash_g');
        $this->em->persist($gManager);

        $gCatConf = $goldenConf['category'] ?? [];
        $gCat = new Category();
        $gCat->setName($gCatConf['name'] ?? 'Creative Shift');
        $gCat->setIcon($gCatConf['icon'] ?? 'palette');
        $gCat->setColor($gCatConf['color'] ?? '#8b5cf6');
        $gCat->setOrganization($gOrg);
        $gCat->setIsActive(true);
        $gCat->setMaxSessionDurationMinutes($gCatConf['max_duration_minutes'] ?? 480);
        $this->em->persist($gCat);

        // Flush newly created/updated Organizations so they have database IDs for PinService to query
        $this->em->flush();

        // 5. Seed Users from config (50/50 split)
        $historyDays = (int)$this->configService->get('demo_reset_history_days', 150);
        $demoUsersData = $config['demo_users'] ?? [];
        
        $usersCreated = 0;
        $usedBasicPins = [];
        $usedGoldenPins = [];
        $activeBasicCount = 0;
        $activeGoldenCount = 0;

        foreach ($demoUsersData as $row) {
            if ($usersCreated >= 100) break;

            // Alternate between orgs
            $isGolden = ($usersCreated % 2 === 0);
            $targetOrg = $isGolden ? $gOrg : $bOrg;
            $targetCat = $isGolden ? $gCat : $bCat;

            $firstName = $row['first'] ?? 'Demo';
            $lastName = $row['last'] ?? 'User';
            $phone = $row['phone'] ?? null;
            $email = $row['email'] ?? null;
            
            // Allocate conforming unique PINs using PinService
            $pin = $this->pinService->generateUniquePin($targetOrg, null, $isGolden ? $usedGoldenPins : $usedBasicPins);
            if ($isGolden) {
                $usedGoldenPins[] = $pin;
            } else {
                $usedBasicPins[] = $pin;
            }

            // Enforce license caps (Active status limits)
            $isActive = $this->licenseService->isNextUserActiveAllowed($targetOrg, $isGolden ? $activeGoldenCount : $activeBasicCount);
            if ($isActive) {
                if ($isGolden) {
                    $activeGoldenCount++;
                } else {
                    $activeBasicCount++;
                }
            }

            $orgUser = new OrgUser();
            $orgUser->setOrganization($targetOrg);
            $orgUser->setFirstName($firstName);
            $orgUser->setLastName($lastName);
            $orgUser->setPhone($phone);
            $orgUser->setEmail($email);
            $orgUser->setPin($pin);
            $orgUser->setPinHash($this->pinService->hashPin($pin));
            $orgUser->setIsActive($isActive);
            $this->em->persist($orgUser);

            // Generate history for configured number of days
            for ($day = $historyDays; $day >= 1; $day--) {
                // 15% check-in rate per user per day to create a realistic sparse grid
                if (random_int(1, 100) <= 15) {
                    $session = new WorkSession();
                    $session->setOrganization($targetOrg);
                    $session->setOrgUser($orgUser);
                    $session->setCategory($targetCat);
                    
                    // Clock-in starts between 8 AM and 10 AM
                    $startHour = random_int(8, 10);
                    $startMin = random_int(0, 59);
                    $start = new \DateTime("-{$day} days");
                    $start->setTime($startHour, $startMin);
                    $session->setStartedAt($start);
                    
                    // Shift duration ranges from 4 to 8 hours
                    $durationHours = random_int(4, 8);
                    $end = clone $start;
                    $end->modify("+{$durationHours} hours");
                    $session->setEndedAt($end);
                    $session->setDurationSeconds($durationHours * 3600);
                    
                    $this->em->persist($session);
                }
            }

            // 10% chance they are actively working today (open sessions for dashboard demo)
            if (random_int(1, 10) === 1) {
                $session = new WorkSession();
                $session->setOrganization($targetOrg);
                $session->setOrgUser($orgUser);
                $session->setCategory($targetCat);
                
                $start = new \DateTime('-' . random_int(1, 4) . ' hours');
                $session->setStartedAt($start);
                $this->em->persist($session);
            }
            
            $usersCreated++;

            // Batch flush to avoid PHP memory exhaustion with 15,000+ session entities
            if ($usersCreated % 20 === 0) {
                $this->em->flush();
            }
        }

        // Final flush for remaining entities
        $this->em->flush();

        // 6. Dispatch password and token rotations asynchronously via Messenger to simulate rotation policies
        if ($bOrg->getId() && $bManager->getId()) {
            $this->messageBus->dispatch(new RotatePasswordMessage($bOrg->getId(), $bManager->getId()));
            $this->messageBus->dispatch(new RotateKioskTokenMessage($bOrg->getId(), $bOrg->getName()));
        }

        if ($gOrg->getId() && $gManager->getId()) {
            $this->messageBus->dispatch(new RotatePasswordMessage($gOrg->getId(), $gManager->getId()));
            $this->messageBus->dispatch(new RotateKioskTokenMessage($gOrg->getId(), $gOrg->getName()));
        }

        // 7. Audit Complete: Record system metrics for platform administrators
        $this->auditLogger->log('SYSTEM_DEMO_DATA_RESET_COMPLETE', [
            'status' => 'completed',
            'basic_demo_org_id' => $bOrg->getId(),
            'golden_demo_org_id' => $gOrg->getId(),
            'users_seeded_count' => $usersCreated,
        ]);
    }
}
