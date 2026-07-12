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
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:seed-data',
    description: 'Seeds the MySQL database with base plans, system administrator, organizations, managers, and orgUsers from seed_data.json.',
)]
/**
 * CLI Command to initialize the database with system parameters and seed data.
 * 
 * Rationale:
 * - Parses config/seed_data.json as the unified source of truth.
 * - Seeds plan definitions, platform administrators, static organizations, categories, users, and sessions.
 * - Seeds default global configuration parameters (such as signups, throttling, and storage paths).
 */
class SeedDataCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private string $projectDir;

    public function __construct(
        EntityManagerInterface $entityManager, 
        UserPasswordHasherInterface $passwordHasher,
        KernelInterface $kernel
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        // Project directory parameter allows absolute file mapping during setup/CLI executions
        $this->projectDir = $kernel->getProjectDir();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Starting Database Seeding...');

        // 1. Locate and parse config/seed_data.json configuration file
        $configPath = $this->projectDir . '/config/seed_data.json';
        if (!file_exists($configPath)) {
            $io->error("Seed data configuration file not found at: {$configPath}");
            return Command::FAILURE;
        }

        $config = json_decode(file_get_contents($configPath), true);
        if (!$config) {
            $io->error("Failed to parse seed data JSON file.");
            return Command::FAILURE;
        }

        // 2. Seed Subscription Plans (Basic, Silver, Gold tiers)
        $plans = [];
        $plansData = $config['plans'] ?? [];

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

        // 3. Seed Platform Super Administrator Account
        $adminData = $config['admin'] ?? [];
        if ($adminData) {
            $existingAdmin = $this->entityManager->getRepository(Manager::class)->findOneBy(['email' => $adminData['email']]);
            if (!$existingAdmin) {
                $admin = new Manager();
                $admin->setEmail($adminData['email']);
                $admin->setFirstName($adminData['first_name']);
                $admin->setLastName($adminData['last_name']);
                $admin->setRole('ROLE_SYSTEM_ADMIN');
                $admin->setIsActive(true);
                
                // Hash credentials using standard Symfony password encoder
                $hashedPassword = $this->passwordHasher->hashPassword($admin, $adminData['password']);
                $admin->setPasswordHash($hashedPassword);

                $this->entityManager->persist($admin);
                $io->success("Created platform administrator: {$adminData['email']} (Password: {$adminData['password']})");
            } else {
                $io->text("Platform administrator {$adminData['email']} already exists.");
            }
        }

        // 4. Seed Static Organizations & Dependent Entities
        $orgsData = $config['static_organizations'] ?? [];
        foreach ($orgsData as $orgData) {
            $existingOrg = $this->entityManager->getRepository(Organization::class)->findOneBy(['slug' => $orgData['slug']]);
            if (!$existingOrg) {
                $org = new Organization();
                $org->setName($orgData['name']);
                $org->setSlug($orgData['slug']);
                $org->setPlan($plans[$orgData['plan']]);
                $org->setAccessMode($orgData['access_mode']);
                $org->setCheckInLinkToken($orgData['token']);
                $org->setOvertimeGraceMinutes($orgData['overtime_grace_minutes'] ?? 0);
                $org->setOvertimeMultiplier($orgData['overtime_multiplier'] ?? '1.00');
                
                $this->entityManager->persist($org);
                $io->success("Created organization: {$orgData['name']} (Slug: {$orgData['slug']})");

                // Seed organization managers
                foreach ($orgData['managers'] ?? [] as $mgrData) {
                    $manager = new Manager();
                    $manager->setEmail($mgrData['email']);
                    $manager->setFirstName($mgrData['first_name']);
                    $manager->setLastName($mgrData['last_name']);
                    $manager->setRole('ROLE_ORG_MANAGER');
                    $manager->setOrganization($org);
                    $manager->setIsActive(true);

                    $hashedPassword = $this->passwordHasher->hashPassword($manager, $mgrData['password']);
                    $manager->setPasswordHash($hashedPassword);
                    $this->entityManager->persist($manager);
                    $io->text("  Manager: {$mgrData['email']} (Password: {$mgrData['password']})");
                }

                // Seed shift categories
                $categories = [];
                foreach ($orgData['categories'] ?? [] as $catData) {
                    $category = new Category();
                    $category->setOrganization($org);
                    $category->setName($catData['name']);
                    $category->setIcon($catData['icon']);
                    $category->setIsActive(true);
                    $this->entityManager->persist($category);
                    $categories[] = $category;
                }

                // Seed employee records
                foreach ($orgData['org_users'] ?? [] as $empData) {
                    $orgUser = new OrgUser();
                    $orgUser->setOrganization($org);
                    $orgUser->setFirstName($empData['first']);
                    $orgUser->setLastName($empData['last']);
                    $orgUser->setEmail($empData['email'] ?? null);
                    $orgUser->setIsActive(true);
                    $orgUser->setPhone($empData['phone'] ?? null);
                    $orgUser->setPin($empData['pin']);
                    $orgUser->setPinHash(password_hash($empData['pin'], PASSWORD_BCRYPT));
                    if (isset($empData['custom'])) {
                        $orgUser->setCustomFieldsJson($empData['custom']);
                    }
                    $this->entityManager->persist($orgUser);

                    // Seed historical work sessions for the employee
                    foreach ($empData['sessions'] ?? [] as $sessData) {
                        $session = new WorkSession();
                        $session->setOrgUser($orgUser);
                        $session->setOrganization($org);
                        if (isset($categories[$sessData['category_index']])) {
                            $session->setCategory($categories[$sessData['category_index']]);
                        }
                        $session->setStartedAt(new \DateTime($sessData['started_at']));
                        $session->setEndedAt(new \DateTime($sessData['ended_at']));
                        $session->setDurationSeconds($sessData['duration_seconds']);
                        $session->setStartMethod($sessData['start_method']);
                        $session->setEndMethod($sessData['end_method']);
                        $session->setIsOvertime($sessData['is_overtime'] ?? false);
                        $session->setIsOutOfWindow($sessData['is_out_of_window'] ?? false);
                        $this->entityManager->persist($session);
                    }
                }
            } else {
                $io->text("Organization {$orgData['name']} already exists.");
            }
        }

        // 5. Seed Global Configurations into the app_config table
        $defaultConfigs = [
            'new_signups_enabled' => true,
            'allowed_phone_countries' => ['us'],
            'max_import_records' => 100,
            'demo_rotation_hours' => 12,
            'captcha_login_enabled' => false,
            'captcha_demo_enabled' => false,
            'audit_log_archive_days' => 180,
            'session_sweep_throttle_seconds' => 60,
            'demo_reset_history_days' => 150,
            'audit_log_archive_dir' => '/var/log/audit_archives',
        ];
        foreach ($defaultConfigs as $key => $val) {
            $existing = $this->entityManager->getRepository(\App\Entity\AppConfig::class)->findOneBy(['settingKey' => $key]);
            if (!$existing) {
                $config = new \App\Entity\AppConfig();
                $config->setSettingKey($key);
                $config->setSettingValue($val);
                $this->entityManager->persist($config);
                $io->text("Seeding default config: {$key} = " . json_encode($val));
            }
        }

        $this->entityManager->flush();
        $io->success('Seeding completed successfully!');

        return Command::SUCCESS;
    }
}
