<?php

namespace App\Command;

use App\Entity\Category;
use App\Entity\OrgUser;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\Plan;
use App\Entity\WorkSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed-data',
    description: 'Seeds the MySQL database with base plans, system administrator, organizations, managers, and orgUsers.',
)]
class SeedDataCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Starting Database Seeding...');

        // 1. Seed Plans if they do not exist
        $plans = [];
        $plansData = [
            'basic' => [
                'max_emp' => 10,
                'max_mgr' => 1,
                'max_fields' => 3,
                'max_cat' => 1,
                'session' => 2,
                'retention' => 45,
                'exports' => false,
                'price' => '0.00',
                'secure_kiosk' => false,
                'custom_duration' => false,
                'overtime' => false
            ],
            'silver' => [
                'max_emp' => 100,
                'max_mgr' => 1,
                'max_fields' => 10,
                'max_cat' => 5,
                'session' => 8,
                'retention' => 90,
                'exports' => true,
                'price' => '29.00',
                'secure_kiosk' => true,
                'custom_duration' => true,
                'overtime' => true
            ],
            'gold' => [
                'max_emp' => 1000,
                'max_mgr' => 9999,
                'max_fields' => 99,
                'max_cat' => 99,
                'session' => 24,
                'retention' => 365,
                'exports' => true,
                'price' => '99.00',
                'secure_kiosk' => true,
                'custom_duration' => true,
                'overtime' => true
            ]
        ];

        foreach ($plansData as $name => $data) {
            $plan = $this->entityManager->getRepository(Plan::class)->findOneBy(['name' => $name]);
            if (!$plan) {
                $plan = new Plan();
                $plan->setName($name);
                $plan->setMaxOrgUsers($data['max_emp']);
                $plan->setMaxManagers($data['max_mgr']);
                $plan->setMaxFields($data['max_fields']);
                $plan->setMaxCategories($data['max_cat']);
                $plan->setSessionLimitHours($data['session']);
                $plan->setRetentionDays($data['retention']);
                $plan->setExportsEnabled($data['exports']);
                $plan->setPriceMonthly($data['price']);
                $plan->setHasSecureKiosk($data['secure_kiosk']);
                $plan->setHasCustomSessionDurations($data['custom_duration']);
                $plan->setHasOvertimeRules($data['overtime']);
                
                $this->entityManager->persist($plan);
                $io->text("Seeding plan: {$name}");
            }
            $plans[$name] = $plan;
        }
        $this->entityManager->flush();

        // 2. Create System Administrator
        $existingAdmin = $this->entityManager->getRepository(Manager::class)->findOneBy(['email' => 'admin@platform.com']);
        if (!$existingAdmin) {
            $admin = new Manager();
            $admin->setEmail('admin@platform.com');
            $admin->setFirstName('System');
            $admin->setLastName('Administrator');
            $admin->setRole('ROLE_SYSTEM_ADMIN');
            $admin->setIsActive(true);
            
            $hashedPassword = $this->passwordHasher->hashPassword($admin, 'admin123');
            $admin->setPasswordHash($hashedPassword);

            $this->entityManager->persist($admin);
            $io->success('Created platform administrator: admin@platform.com (Password: admin123)');
        } else {
            $io->text('Platform administrator admin@platform.com already exists.');
        }

        // 3. Create Acme Corp Organization (Gold)
        $existingOrg = $this->entityManager->getRepository(Organization::class)->findOneBy(['slug' => 'acme-corp']);
        if (!$existingOrg) {
            $org = new Organization();
            $org->setName('Acme Corporation');
            $org->setSlug('acme-corp');
            $org->setPlan($plans['gold']);
            $org->setAccessMode('secure'); // Secure PIN mode
            $org->setCheckInLinkToken('acme-secret-token');
            $org->setOvertimeGraceMinutes(15);
            $org->setOvertimeMultiplier('1.50');
            
            $this->entityManager->persist($org);
            $io->success('Created Acme Corporation organization (Slug: acme-corp, Plan: Gold, Access Mode: Secure)');

            // 4. Create Org Manager
            $manager = new Manager();
            $manager->setEmail('manager@acme.com');
            $manager->setFirstName('Alice');
            $manager->setLastName('Manager');
            $manager->setRole('ROLE_ORG_MANAGER');
            $manager->setOrganization($org);
            $manager->setIsActive(true);

            $hashedPassword = $this->passwordHasher->hashPassword($manager, 'manager123');
            $manager->setPasswordHash($hashedPassword);

            $this->entityManager->persist($manager);
            $io->success('Created organization manager: manager@acme.com (Password: manager123)');

            // 5. Create Categories
            $categoriesData = [
                ['name' => 'Development', 'icon' => 'code'],
                ['name' => 'Design', 'icon' => 'palette'],
                ['name' => 'Meeting', 'icon' => 'groups'],
                ['name' => 'Support', 'icon' => 'support_agent'],
            ];

            $categories = [];
            foreach ($categoriesData as $catData) {
                $category = new Category();
                $category->setOrganization($org);
                $category->setName($catData['name']);
                $category->setIcon($catData['icon']);
                $category->setIsActive(true);
                $this->entityManager->persist($category);
                $categories[] = $category;
            }
            $io->success('Created 4 activity categories for Acme Corporation.');

            // 6. Create OrgUsers
            $orgUsersData = [
                ['first' => 'John', 'last' => 'Doe', 'email' => 'john.doe@acme.com', 'pin' => '1234', 'custom' => ['OrgUser ID' => 'EMP-1001', 'Department' => 'Engineering']],
                ['first' => 'Jane', 'last' => 'Smith', 'email' => 'jane.smith@acme.com', 'pin' => '5678', 'custom' => ['OrgUser ID' => 'EMP-1002', 'Department' => 'Design']],
                ['first' => 'Bob', 'last' => 'Johnson', 'email' => 'bob.johnson@acme.com', 'pin' => '4321', 'custom' => ['OrgUser ID' => 'EMP-1003', 'Department' => 'Customer Success']],
            ];

            foreach ($orgUsersData as $empData) {
                $orgUser = new OrgUser();
                $orgUser->setOrganization($org);
                $orgUser->setFirstName($empData['first']);
                $orgUser->setLastName($empData['last']);
                $orgUser->setEmail($empData['email']);
                $orgUser->setIsActive(true);
                $orgUser->setCustomFieldsJson($empData['custom']);
                
                $orgUser->setPhone($empData['phone'] ?? null);
                $orgUser->setPin($empData['pin']);
                $pinHash = password_hash($empData['pin'], PASSWORD_BCRYPT);
                $orgUser->setPinHash($pinHash);

                $this->entityManager->persist($orgUser);

                if ($empData['first'] === 'John') {
                    $session = new WorkSession();
                    $session->setOrgUser($orgUser);
                    $session->setOrganization($org);
                    $session->setCategory($categories[0]); // Development
                    $session->setStartedAt(new \DateTime('-1 day 09:00:00'));
                    $session->setEndedAt(new \DateTime('-1 day 17:00:00'));
                    $session->setDurationSeconds(8 * 3600);
                    $session->setStartMethod('secure');
                    $session->setEndMethod('secure');
                    $session->setIsOvertime(false);
                    $session->setIsOutOfWindow(false);
                    $this->entityManager->persist($session);
                }
            }
            $io->success('Created 3 orgUsers with sample PIN logins and check-in logs.');

        } else {
            $io->text('Acme Corporation sample data already seeded.');
        }

        // 7. Create a Basic Plan Demo Organization for comparison
        $existingBasicOrg = $this->entityManager->getRepository(Organization::class)->findOneBy(['slug' => 'basic-demo']);
        if (!$existingBasicOrg) {
            $org = new Organization();
            $org->setName('Basic Demo Corp');
            $org->setSlug('basic-demo');
            $org->setPlan($plans['basic']);
            $org->setAccessMode('open'); // Open mode
            $org->setCheckInLinkToken('basic-secret-token');
            $org->setOvertimeGraceMinutes(0);
            
            $this->entityManager->persist($org);

            $manager = new Manager();
            $manager->setEmail('basic@demo.com');
            $manager->setFirstName('Basic');
            $manager->setLastName('Manager');
            $manager->setRole('ROLE_ORG_MANAGER');
            $manager->setOrganization($org);
            $manager->setIsActive(true);
            $manager->setPasswordHash($this->passwordHasher->hashPassword($manager, 'demo123'));
            $this->entityManager->persist($manager);

            $category = new Category();
            $category->setOrganization($org);
            $category->setName('General Work');
            $category->setIcon('work');
            $category->setIsActive(true);
            $this->entityManager->persist($category);

            $orgUsers = [
                ['first' => 'Dave', 'last' => 'Basic', 'pin' => '0000', 'phone' => '000-000-0000'],
                ['first' => 'Sarah', 'last' => 'Open', 'pin' => '1111', 'phone' => '111-111-1111']
            ];
            foreach ($orgUsers as $emp) {
                $orgUser = new OrgUser();
                $orgUser->setOrganization($org);
                $orgUser->setFirstName($emp['first']);
                $orgUser->setLastName($emp['last']);
                $orgUser->setIsActive(true);
                $orgUser->setPhone($emp['phone']);
                $orgUser->setPin($emp['pin']);
                $orgUser->setPinHash(password_hash($emp['pin'], PASSWORD_BCRYPT));
                $this->entityManager->persist($orgUser);
            }

            $io->success('Created Basic Demo organization (Slug: basic-demo, Plan: Basic, Access Mode: Open, Manager: basic@demo.com / demo123)');
        }

        $this->entityManager->flush();
        $io->success('Seeding completed successfully!');

        return Command::SUCCESS;
    }
}
