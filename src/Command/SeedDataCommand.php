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
use Symfony\Component\Console\Input\InputOption;
use App\MessageHandler\ResetDemoDataHandler;
use App\Message\ResetDemoDataMessage;

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
    private ResetDemoDataHandler $resetDemoDataHandler;

    public function __construct(
        EntityManagerInterface $entityManager, 
        UserPasswordHasherInterface $passwordHasher,
        KernelInterface $kernel,
        ResetDemoDataHandler $resetDemoDataHandler
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        // Project directory parameter allows absolute file mapping during setup/CLI executions
        $this->projectDir = $kernel->getProjectDir();
        $this->resetDemoDataHandler = $resetDemoDataHandler;
    }

    protected function configure(): void
    {
        $this
            ->addOption('clean', null, InputOption::VALUE_NONE, 'Clear existing seed data before seeding')
        ;
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

        if ($input->getOption('clean')) {
            $io->section('Cleaning existing seed data...');
            
            // Delete configs
            $defaultConfigs = [
                'new_signups_enabled',
                'allowed_phone_countries',
                'max_import_records',
                'demo_rotation_hours',
                'captcha_login_enabled',
                'captcha_demo_enabled',
                'audit_log_archive_days',
                'session_sweep_throttle_seconds',
                'demo_reset_history_days',
                'audit_log_archive_dir',
            ];
            $configKeysStr = implode(',', array_map(function($k) { return "'" . addslashes($k) . "'"; }, $defaultConfigs));
            $this->entityManager->getConnection()->executeStatement("DELETE FROM app_config WHERE setting_key IN ({$configKeysStr})");

            // Delete all system administrator users
            $this->entityManager->getConnection()->executeStatement(
                "DELETE FROM managers WHERE role = 'ROLE_SYSTEM_ADMIN'"
            );

            // Find all slugs
            $slugs = [];
            foreach ($config['static_organizations'] ?? [] as $orgData) {
                if (!empty($orgData['slug'])) {
                    $slugs[] = $orgData['slug'];
                }
            }
            foreach ($config['demo_organizations'] ?? [] as $key => $demoData) {
                if (!empty($demoData['slug'])) {
                    $slugs[] = $demoData['slug'];
                }
            }

            if (!empty($slugs)) {
                $quotedSlugs = array_map(function($slug) {
                    return "'" . addslashes($slug) . "'";
                }, $slugs);
                $slugsStr = implode(',', $quotedSlugs);
                
                // Get the IDs of these organizations
                $orgIds = $this->entityManager->getConnection()->fetchAllAssociative(
                    "SELECT id FROM organizations WHERE slug IN ({$slugsStr})"
                );
                $ids = array_column($orgIds, 'id');
                
                if (!empty($ids)) {
                    $idsStr = implode(',', $ids);
                    $this->entityManager->getConnection()->executeStatement("DELETE FROM work_sessions WHERE organization_id IN ({$idsStr})");
                    $this->entityManager->getConnection()->executeStatement("DELETE FROM audit_logs WHERE organization_id IN ({$idsStr})");
                    $this->entityManager->getConnection()->executeStatement("DELETE FROM org_users WHERE organization_id IN ({$idsStr})");
                    $this->entityManager->getConnection()->executeStatement("DELETE FROM categories WHERE organization_id IN ({$idsStr})");
                    $this->entityManager->getConnection()->executeStatement("DELETE FROM managers WHERE organization_id IN ({$idsStr})");
                    $this->entityManager->getConnection()->executeStatement("DELETE FROM payment_history WHERE organization_id IN ({$idsStr})");
                    $this->entityManager->getConnection()->executeStatement("DELETE FROM organizations WHERE id IN ({$idsStr})");
                }
            }
            $io->success('Existing seed data cleared!');
        }

        // 2. Seed Subscription Plans (Basic, Silver, Gold tiers)
        $plans = [];
        $plansData = $config['plans'] ?? [];

        foreach ($plansData as $name => $data) {
            $plan = $this->entityManager->getRepository(Plan::class)->findOneBy(['name' => $name]);
            if (!$plan) {
                $plan = new Plan();
                $plan->setName($name);
                $io->text("Seeding plan: {$name}");
            }
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
            $plan->setHasBarcodeFeatures($data['barcode_features'] ?? false);
            $plan->setHasClickList($data['click_list'] ?? true);
            $plan->setStripePriceId($data['stripe_price_id'] ?? null);
            
            $this->entityManager->persist($plan);
            $plans[$name] = $plan;
        }
        $this->entityManager->flush();

        // 3. Seed Platform Super Administrator Account
        $adminData = $config['admin'] ?? [];
        $adminEmail = $adminData['email'] ?? 'admin@centertime.us';

        $adminUser = $this->entityManager->getRepository(Manager::class)->findOneBy(['email' => $adminEmail]);
        if (!$adminUser) {
            $adminUser = new Manager();
            $adminUser->setEmail($adminEmail);
            $adminUser->setFirstName($adminData['first_name'] ?? 'System');
            $adminUser->setLastName($adminData['last_name'] ?? 'Administrator');
            $adminUser->setIsActive(true);
            $this->entityManager->persist($adminUser);
            $io->success("Created Super Admin user: {$adminEmail}");
        }
        $rawPass = $_ENV['SEED_ADMIN_PASSWORD'] ?? ($_SERVER['SEED_ADMIN_PASSWORD'] ?? 'dummy123');
        $hashed = $this->passwordHasher->hashPassword($adminUser, $rawPass);
        $adminUser->setPasswordHash($hashed);
        $adminUser->setRole('ROLE_SYSTEM_ADMIN');

        // 4. Seed Static Organizations and initial records
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
                $org->clearOverrides();
                $org->setTimezone(null);
                
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

                    $mgrRawPass = $_ENV['SEED_MANAGER_PASSWORD'] ?? ($_SERVER['SEED_MANAGER_PASSWORD'] ?? 'dummy123');
                    $mgrHashed = $this->passwordHasher->hashPassword($manager, $mgrRawPass);
                    $manager->setPasswordHash($mgrHashed);

                    $this->entityManager->persist($manager);
                }

                // Seed categories
                $categories = [];
                foreach ($orgData['categories'] ?? [] as $catData) {
                    $category = new Category();
                    $category->setName($catData['name']);
                    $category->setIcon($catData['icon']);
                    $category->setOrganization($org);
                    $category->setIsActive(true);
                    $this->entityManager->persist($category);
                    $categories[] = $category;
                }

                // Seed org users and sessions
                foreach ($orgData['org_users'] ?? [] as $userData) {
                    $orgUser = new OrgUser();
                    $orgUser->setFirstName($userData['first']);
                    $orgUser->setLastName($userData['last']);
                    $orgUser->setEmail($userData['email']);
                    $orgUser->setPin($userData['pin']);
                    $orgUser->setPinHash(password_hash($userData['pin'], PASSWORD_BCRYPT));
                    $orgUser->setOrganization($org);
                    $orgUser->setCustomFieldsJson($userData['custom'] ?? []);
                    $orgUser->setIsActive(true);
                    $this->entityManager->persist($orgUser);

                    foreach ($userData['sessions'] ?? [] as $sessData) {
                        $session = new WorkSession();
                        $session->setOrganization($org);
                        $session->setOrgUser($orgUser);
                        if (isset($categories[$sessData['category_index']])) {
                            $session->setCategory($categories[$sessData['category_index']]);
                        }
                        $session->setStartedAt(new \DateTime($sessData['started_at']));
                        $session->setEndedAt(new \DateTime($sessData['ended_at']));
                        $session->setDurationSeconds($sessData['duration_seconds']);
                        $session->setStartMethod($sessData['start_method']);
                        $session->setEndMethod($sessData['end_method']);
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
            'captcha_login_enabled' => true,
            'captcha_demo_enabled' => false,
            'pdf_poster_download_enabled' => false,
            'audit_log_archive_days' => 180,
            'cron_empty_log_retention_hours' => 2,
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
            } else if ($key === 'captcha_login_enabled') {
                $existing->setSettingValue($val);
            }
        }

        $this->entityManager->flush();
        $io->success('Seeding completed successfully!');

        if ($input->getOption('clean')) {
            $io->section('Seeding demo organizations...');
            $this->resetDemoDataHandler->__invoke(new ResetDemoDataMessage());
            $io->success('Demo organizations seeded successfully!');
        }

        return Command::SUCCESS;
    }
}
