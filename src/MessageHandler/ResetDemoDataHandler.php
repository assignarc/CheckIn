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
use App\Service\PinService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;


#[AsMessageHandler]
class ResetDemoDataHandler
{
    private EntityManagerInterface $em;
    private MessageBusInterface $messageBus;
    private ParameterBagInterface $params;
    private PinService $pinService;

    public function __construct(
        EntityManagerInterface $em,
        MessageBusInterface $messageBus,
        ParameterBagInterface $params,
        PinService $pinService
    ) {
        $this->em = $em;
        $this->messageBus = $messageBus;
        $this->params = $params;
        $this->pinService = $pinService;
    }

    public function __invoke(ResetDemoDataMessage $message)
    {
        // Allow unlimited memory and time for massive entity creation + Symfony Profiler overhead
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        // 1. Delete existing Demo Orgs using raw SQL to bypass SoftDeleteable and cascade delete EVERYTHING
        $this->em->getConnection()->executeStatement(
            "DELETE FROM organizations WHERE slug IN ('basic-demo', 'golden-demo')"
        );

        // 2. Recreate Basic Demo Org (Basic Plan, Open Access)
        $basicPlan = $this->em->getRepository(Plan::class)->findOneBy(['name' => 'basic']);
        $bOrg = new Organization();
        $bOrg->setName('Basic Demo Company');
        $bOrg->setSlug('basic-demo');
        $bOrg->setPlan($basicPlan);
        $bOrg->setAccessMode('open');
        // Will be overwritten by RotateKioskTokenMessage
        $bOrg->setCheckInLinkToken('temp_token_b');
        $this->em->persist($bOrg);

        $bManager = new Manager();
        $bManager->setEmail('basic@platform.com');
        $bManager->setFirstName('Basic');
        $bManager->setLastName('Manager');
        $bManager->setRole('ROLE_ORG_MANAGER');
        $bManager->setOrganization($bOrg);
        $bManager->setIsActive(true);
        // Will be overwritten by RotatePasswordMessage
        $bManager->setPasswordHash('temp_hash_b');
        $this->em->persist($bManager);

        $bCat = new Category();
        $bCat->setName('General Shift');
        $bCat->setIcon('work');
        $bCat->setColor('#10b981');
        $bCat->setOrganization($bOrg);
        $bCat->setIsActive(true);
        $bCat->setMaxSessionDurationMinutes(480);
        $this->em->persist($bCat);

        // 3. Recreate Golden Demo Org (Gold Plan, Secure Access)
        $goldPlan = $this->em->getRepository(Plan::class)->findOneBy(['name' => 'gold']);
        $gOrg = new Organization();
        $gOrg->setName('Golden Demo Company');
        $gOrg->setSlug('golden-demo');
        $gOrg->setPlan($goldPlan);
        $gOrg->setAccessMode('secure');
        // Will be overwritten by RotateKioskTokenMessage
        $gOrg->setCheckInLinkToken('temp_token_g');
        $gOrg->setOvertimeGraceMinutes(15);
        $gOrg->setOvertimeMultiplier('1.50');
        $this->em->persist($gOrg);

        $gManager = new Manager();
        $gManager->setEmail('golden@platform.com');
        $gManager->setFirstName('Golden');
        $gManager->setLastName('Manager');
        $gManager->setRole('ROLE_ORG_MANAGER');
        $gManager->setOrganization($gOrg);
        $gManager->setIsActive(true);
        // Will be overwritten by RotatePasswordMessage
        $gManager->setPasswordHash('temp_hash_g');
        $this->em->persist($gManager);

        $gCat = new Category();
        $gCat->setName('Design Shift');
        $gCat->setIcon('palette');
        $gCat->setColor('#8b5cf6');
        $gCat->setOrganization($gOrg);
        $gCat->setIsActive(true);
        $gCat->setMaxSessionDurationMinutes(480);
        $this->em->persist($gCat);

        // Flush newly created Organizations so they have database IDs for PinService to query
        $this->em->flush();

        // 4. Seed Users from DemoData.csv (50/50 split)
        $csvPath = $this->params->get('kernel.project_dir') . '/data/DemoData.csv';
        if (file_exists($csvPath)) {
            $handle = fopen($csvPath, 'r');
            fgetcsv($handle); // skip header
            
            $usersCreated = 0;
            $usedBasicPins = [];
            $usedGoldenPins = [];
            while (($row = fgetcsv($handle)) !== false) {
                if ($usersCreated >= 100) break;

                // Alternate between orgs
                $isGolden = ($usersCreated % 2 === 0);
                $targetOrg = $isGolden ? $gOrg : $bOrg;
                $targetCat = $isGolden ? $gCat : $bCat;

                $firstName = $row[1] ?? 'Demo';
                $lastName = $row[2] ?? 'User';
                $phone = $row[3] ?? null;
                $email = $row[4] ?? null;
                
                $pin = $this->pinService->generateUniquePin($targetOrg, null, $isGolden ? $usedGoldenPins : $usedBasicPins);
                if ($isGolden) {
                    $usedGoldenPins[] = $pin;
                } else {
                    $usedBasicPins[] = $pin;
                }

                $orgUser = new OrgUser();
                $orgUser->setOrganization($targetOrg);
                $orgUser->setFirstName($firstName);
                $orgUser->setLastName($lastName);
                $orgUser->setPhone($phone);
                $orgUser->setEmail($email);
                $orgUser->setPin($pin);
                $orgUser->setPinHash($this->pinService->hashPin($pin));
                $this->em->persist($orgUser);


                // Generate history for last 150 days (5 months)
                for ($day = 150; $day >= 1; $day--) {
                    if (random_int(1, 100) <= 15) {
                        $session = new WorkSession();
                        $session->setOrganization($targetOrg);
                        $session->setOrgUser($orgUser);
                        $session->setCategory($targetCat);
                        
                        $startHour = random_int(8, 10);
                        $startMin = random_int(0, 59);
                        $start = new \DateTime("-{$day} days");
                        $start->setTime($startHour, $startMin);
                        $session->setStartedAt($start);
                        
                        $durationHours = random_int(4, 8);
                        $end = clone $start;
                        $end->modify("+{$durationHours} hours");
                        $session->setEndedAt($end);
                        $session->setDurationSeconds($durationHours * 3600);
                        
                        $this->em->persist($session);
                    }
                }

                // 10% chance they are actively working today
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

                // Batch flush to avoid memory bloat
                if ($usersCreated % 20 === 0) {
                    $this->em->flush();
                }
            }
            fclose($handle);
        }

        // Final flush
        $this->em->flush();

        // 5. Dispatch rotation messages to secure the credentials
        if ($bOrg->getId() && $bManager->getId()) {
            $this->messageBus->dispatch(new RotatePasswordMessage($bOrg->getId(), $bManager->getId()));
            $this->messageBus->dispatch(new RotateKioskTokenMessage($bOrg->getId(), $bOrg->getName()));
        }

        if ($gOrg->getId() && $gManager->getId()) {
            $this->messageBus->dispatch(new RotatePasswordMessage($gOrg->getId(), $gManager->getId()));
            $this->messageBus->dispatch(new RotateKioskTokenMessage($gOrg->getId(), $gOrg->getName()));
        }
    }
}
