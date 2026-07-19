<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Category;
use App\Entity\OrgUser;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\Plan;
use App\Entity\WorkSession;
use App\Entity\PaymentHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Service\AppConfigService;

#[Route('/admin')]
class AdminController extends AbstractController
{
    private function logAdminAudit(EntityManagerInterface $em, string $action, array $details, Request $request, ?Organization $org = null): void
    {
        /** @var Manager $admin */
        $admin = $this->getUser();
        $log = new AuditLog();
        if ($admin instanceof Manager) {
            $log->setManager($admin);
            if ($org) {
                $log->setOrganization($org);
            } else {
                $log->setOrganization($admin->getOrganization());
            }
        } elseif ($org) {
            $log->setOrganization($org);
        }
        $log->setAction($action);
        $log->setDetailsJson($details);
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));

        $em->persist($log);
        $em->flush();
    }

    #[Route('/dashboard', name: 'app_admin_dashboard')]
    public function dashboard(EntityManagerInterface $em): Response
    {
        $orgCount = $em->getRepository(Organization::class)->count([]);
        $mgrCount = $em->getRepository(Manager::class)->count(['role' => 'ROLE_ORG_MANAGER']);
        $empCount = $em->getRepository(OrgUser::class)->count([]);
        $sessionCount = $em->getRepository(WorkSession::class)->count([]);

        // Plan distribution
        $basicPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'basic']);
        $silverPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'silver']);
        $goldPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'gold']);

        $basicCount = $basicPlan ? $em->getRepository(Organization::class)->count(['plan' => $basicPlan]) : 0;
        $silverCount = $silverPlan ? $em->getRepository(Organization::class)->count(['plan' => $silverPlan]) : 0;
        $goldCount = $goldPlan ? $em->getRepository(Organization::class)->count(['plan' => $goldPlan]) : 0;

        return $this->render('admin/dashboard.html.twig', [
            'orgCount' => $orgCount,
            'mgrCount' => $mgrCount,
            'empCount' => $empCount,
            'sessionCount' => $sessionCount,
            'basicCount' => $basicCount,
            'silverCount' => $silverCount,
            'goldCount' => $goldCount,
        ]);
    }

    #[Route('/billing', name: 'app_admin_billing')]
    public function billing(Request $request, EntityManagerInterface $em): Response
    {
        $q = $request->query->get('q', '');

        // 1. Calculate general revenue metrics
        $allPayments = $em->getRepository(PaymentHistory::class)->findBy([], ['paidAt' => 'DESC']);
        $totalRevenue = 0.00;
        foreach ($allPayments as $pay) {
            if ($pay->getStatus() === 'paid') {
                $totalRevenue += (float)$pay->getAmount();
            }
        }

        // Active Paid Subscriptions (silver/gold/platinum) count
        $organizations = $em->getRepository(Organization::class)->findAll();
        
        $activeSubCount = 0;
        $activePaidUsersCount = 0;
        foreach ($organizations as $o) {
            if ($o->isSubscriptionActive() && $o->getPlan()->getName() !== 'basic') {
                $activeSubCount++;
                // Add active employees in this tenant to total paid users
                $activePaidUsersCount += $em->getRepository(OrgUser::class)->count(['organization' => $o, 'isActive' => true]);
            }
        }

        // 2. Filter organizations based on query if present
        $filteredOrgs = [];
        if ($q !== '') {
            $qb = $em->createQueryBuilder();
            $qb->select('o')
                ->from(Organization::class, 'o')
                ->where('o.name LIKE :q OR o.slug LIKE :q')
                ->setParameter('q', '%' . $q . '%')
                ->orderBy('o.createdAt', 'DESC');
            $filteredOrgs = $qb->getQuery()->getResult();
        } else {
            $filteredOrgs = $organizations;
        }

        // 3. For each organization, fetch their latest payment to calculate next payment / renewal date
        $tenantOverview = [];
        foreach ($filteredOrgs as $o) {
            $latestPayment = $em->getRepository(PaymentHistory::class)->findOneBy(
                ['organization' => $o],
                ['paidAt' => 'DESC']
            );

            $tenantOverview[] = [
                'org' => $o,
                'latestPayment' => $latestPayment,
                'nextRenewalDate' => $latestPayment ? $latestPayment->getPeriodEnd() : null,
                'status' => $o->isSubscriptionActive() ? 'Active' : 'Suspended'
            ];
        }

        // 4. Retrieve subscription event logs from AuditLog
        $logQb = $em->createQueryBuilder();
        $logQb->select('l')
            ->from(AuditLog::class, 'l')
            ->where('l.action IN (:actions)')
            ->setParameter('actions', ['TENANT_SIGNUP', 'STRIPE_CHECKOUT_COMPLETED', 'STRIPE_PAYMENT_SUCCESS', 'STRIPE_PAYMENT_FAILED', 'PLAN_UPGRADE'])
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(50);
        $eventLogs = $logQb->getQuery()->getResult();

        return $this->render('admin/billing.html.twig', [
            'totalRevenue' => number_format($totalRevenue, 2),
            'activeSubCount' => $activeSubCount,
            'activePaidUsersCount' => $activePaidUsersCount,
            'tenantOverview' => $tenantOverview,
            'globalPayments' => array_slice($allPayments, 0, 50),
            'eventLogs' => $eventLogs,
            'q' => $q
        ]);
    }

    #[Route('/organizations', name: 'app_admin_organizations')]
    public function organizations(EntityManagerInterface $em): Response
    {
        $orgs = $em->getRepository(Organization::class)->findBy([], ['createdAt' => 'DESC']);
        $plans = $em->getRepository(Plan::class)->findAll();
        
        $orgDetailsList = [];
        foreach ($orgs as $org) {
            $empCount = $em->getRepository(OrgUser::class)->count(['organization' => $org]);
            $mgrCount = $em->getRepository(Manager::class)->count(['organization' => $org]);
            
            $orgDetailsList[] = [
                'org' => $org,
                'empCount' => $empCount,
                'mgrCount' => $mgrCount,
            ];
        }

        return $this->render('admin/organizations.html.twig', [
            'organizations' => $orgDetailsList,
            'plans' => $plans,
        ]);
    }

    #[Route('/organizations/{id}/edit-limits', name: 'app_admin_edit_limits', methods: ['POST'])]
    public function editLimits(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $org = $em->getRepository(Organization::class)->find($id);
        if (!$org) {
            throw $this->createNotFoundException('Organization not found');
        }

        $maxEmp = $request->request->get('max_orgUsers_override');
        $maxMgr = $request->request->get('max_managers_override');
        $maxFields = $request->request->get('max_fields_override');
        $maxCats = $request->request->get('max_categories_override');
        $sessionLimit = $request->request->get('session_limit_hours_override');
        $retention = $request->request->get('retention_days_override');
        $exports = $request->request->get('exports_enabled_override');
        $planId = $request->request->get('plan_id');

        // Update main plan relationship
        if ($planId) {
            $newPlan = $em->getRepository(Plan::class)->find($planId);
            if ($newPlan) {
                $org->setPlan($newPlan);
            }
        }

        // Apply overrides: empty fields represent NULL (falling back to plan defaults)
        $org->setMaxOrgUsersOverride($maxEmp !== '' && $maxEmp !== null ? (int)$maxEmp : null);
        $org->setMaxManagersOverride($maxMgr !== '' && $maxMgr !== null ? (int)$maxMgr : null);
        $org->setMaxFieldsOverride($maxFields !== '' && $maxFields !== null ? (int)$maxFields : null);
        $org->setMaxCategoriesOverride($maxCats !== '' && $maxCats !== null ? (int)$maxCats : null);
        $org->setSessionLimitHoursOverride($sessionLimit !== '' && $sessionLimit !== null ? (int)$sessionLimit : null);
        $org->setRetentionDaysOverride($retention !== '' && $retention !== null ? (int)$retention : null);
        
        if ($exports === 'yes') {
            $org->setExportsEnabledOverride(true);
        } elseif ($exports === 'no') {
            $org->setExportsEnabledOverride(false);
        } else {
            $org->setExportsEnabledOverride(null);
        }

        $org->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->logAdminAudit($em, 'LIMIT_OVERRIDE', [
            'org_id' => $org->getId(),
            'org_name' => $org->getName(),
            'plan' => $org->getPlan()->getName(),
            'max_orgUsers_override' => $org->getMaxOrgUsersOverride(),
            'max_managers_override' => $org->getMaxManagersOverride(),
            'max_fields_override' => $org->getMaxFieldsOverride(),
            'max_categories_override' => $org->getMaxCategoriesOverride(),
            'session_limit_hours_override' => $org->getSessionLimitHoursOverride(),
            'retention_days_override' => $org->getRetentionDaysOverride(),
            'exports_enabled_override' => $org->getExportsEnabledOverride()
        ], $request);

        $this->addFlash('success', "Limits and plan overrides updated successfully for '{$org->getName()}'.");
        return $this->redirectToRoute('app_admin_organizations');
    }

    #[Route('/plans', name: 'app_admin_plans', methods: ['GET', 'POST'])]
    public function plans(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $planId = $request->request->get('plan_id');
            $plan = $em->getRepository(Plan::class)->find($planId);
            
            if ($plan) {
                $plan->setMaxOrgUsers((int)$request->request->get('max_orgUsers'));
                $plan->setMaxManagers((int)$request->request->get('max_managers'));
                $plan->setMaxFields((int)$request->request->get('max_fields'));
                $plan->setMaxCategories((int)$request->request->get('max_categories'));
                $plan->setSessionLimitHours((int)$request->request->get('session_limit_hours'));
                $plan->setRetentionDays((int)$request->request->get('retention_days'));
                $plan->setExportsEnabled($request->request->get('exports_enabled') === 'yes');
                $plan->setPriceMonthly($request->request->get('price_monthly'));

                $em->flush();

                $this->logAdminAudit($em, 'PLAN_EDIT', [
                    'plan_id' => $plan->getId(),
                    'plan_name' => $plan->getName(),
                    'max_orgUsers' => $plan->getMaxOrgUsers(),
                    'max_managers' => $plan->getMaxManagers(),
                    'max_fields' => $plan->getMaxFields(),
                    'max_categories' => $plan->getMaxCategories(),
                    'session_limit_hours' => $plan->getSessionLimitHours(),
                    'retention_days' => $plan->getRetentionDays(),
                    'exports_enabled' => $plan->isExportsEnabled(),
                    'price_monthly' => $plan->getPriceMonthly()
                ], $request);

                $this->addFlash('success', "Plan '" . ucfirst($plan->getName()) . "' settings updated successfully.");
            }
            return $this->redirectToRoute('app_admin_plans');
        }

        $plans = $em->getRepository(Plan::class)->findAll();

        return $this->render('admin/plans.html.twig', [
            'plans' => $plans
        ]);
    }

    #[Route('/organizations/{id}/reset-manager-password', name: 'app_admin_reset_manager_password', methods: ['POST'])]
    public function resetManagerPassword(int $id, Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): Response
    {
        $org = $em->getRepository(Organization::class)->find($id);
        if (!$org) {
            throw $this->createNotFoundException('Organization not found');
        }

        $managerEmail = $request->request->get('manager_email');
        $newPassword = $request->request->get('new_password');

        if (!$managerEmail || !$newPassword) {
            $this->addFlash('danger', 'Manager email and new password are required.');
            return $this->redirectToRoute('app_admin_organizations');
        }

        $manager = $em->getRepository(Manager::class)->findOneBy([
            'email' => $managerEmail,
            'organization' => $org
        ]);

        if (!$manager) {
            $this->addFlash('danger', "Manager account '{$managerEmail}' not found in '{$org->getName()}'.");
            return $this->redirectToRoute('app_admin_organizations');
        }

        $manager->setPasswordHash($passwordHasher->hashPassword($manager, $newPassword));
        $manager->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->logAdminAudit($em, 'PASSWORD_RESET', [
            'org_id' => $org->getId(),
            'manager_email' => $managerEmail
        ], $request, $org);

        $this->addFlash('success', "Password successfully reset for manager '{$managerEmail}'.");
        return $this->redirectToRoute('app_admin_organizations');
    }



    #[Route('/managers', name: 'app_admin_managers')]
    public function managers(Request $request, EntityManagerInterface $em): Response
    {
        $search = $request->query->get('q', '');
        
        $qb = $em->createQueryBuilder()
            ->select('m', 'o')
            ->from(Manager::class, 'm')
            ->leftJoin('m.organization', 'o')
            ->orderBy('m.createdAt', 'DESC');
            
        if ($search) {
            $qb->andWhere('m.firstName LIKE :search OR m.lastName LIKE :search OR m.email LIKE :search OR m.phone LIKE :search OR o.name LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        
        $managers = $qb->getQuery()->getResult();
        
        return $this->render('admin/managers.html.twig', [
            'managers' => $managers,
            'search' => $search
        ]);
    }

    #[Route('/managers/{id}/edit', name: 'app_admin_manager_edit', methods: ['POST'])]
    public function managerEdit(Manager $manager, Request $request, EntityManagerInterface $em, \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $passwordHasher): Response
    {
        $phone = trim($request->request->get('phone', ''));
        if (!$phone) {
            $this->addFlash('danger', 'Phone number is required.');
            return $this->redirectToRoute('app_admin_managers');
        }

        $manager->setFirstName(trim($request->request->get('first_name', '')));
        $manager->setLastName(trim($request->request->get('last_name', '')));
        $manager->setEmail(trim($request->request->get('email', '')));
        $manager->setPhone($phone);
        $manager->setRole($request->request->get('role', 'ROLE_ORG_MANAGER'));
        $manager->setIsActive($request->request->get('is_active') === '1');

        $password = $request->request->get('password', '');
        $passwordChanged = false;
        if ($password && strlen($password) >= 8) {
            $manager->setPasswordHash($passwordHasher->hashPassword($manager, $password));
            $passwordChanged = true;
        }

        $manager->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $details = [
            'target_manager_id' => $manager->getId(),
            'email' => $manager->getEmail()
        ];
        if ($passwordChanged) {
            $details['password_changed'] = true;
        }

        $this->logAdminAudit($em, 'MANAGER_EDITED', $details, $request, $manager->getOrganization());

        $this->addFlash('success', 'Manager updated successfully.');
        return $this->redirectToRoute('app_admin_managers');
    }

    #[Route('/profile', name: 'app_admin_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): Response
    {
        /** @var \App\Entity\Manager|null $user */
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\Manager) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $firstName = $request->request->get('firstName');
            $lastName = $request->request->get('lastName');
            $email = $request->request->get('email');
            $password = $request->request->get('password');

            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setEmail($email);

            if (!empty($password)) {
                $user->setPasswordHash($passwordHasher->hashPassword($user, $password));
            }

            $em->flush();
            $this->addFlash('success', 'Profile updated successfully.');
            return $this->redirectToRoute('app_admin_profile');
        }

        return $this->render('admin/profile.html.twig', [
            'user' => $user
        ]);
    }

    #[Route('/demo-leads', name: 'app_admin_demo_leads')]
    public function demoLeads(EntityManagerInterface $em): Response
    {
        $leads = $em->getRepository(\App\Entity\DemoLead::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/demo_leads.html.twig', [
            'leads' => $leads,
        ]);
    }

    #[Route('/audit-logs', name: 'app_admin_audit_logs')]
    public function auditLogs(Request $request, EntityManagerInterface $em): Response
    {
        $orgId = $request->query->get('org');
        $action = $request->query->get('action');
        
        $qb = $em->createQueryBuilder()
            ->select('a')
            ->from(\App\Entity\AuditLog::class, 'a')
            ->orderBy('a.createdAt', 'DESC');
            
        // Prevent SQL empty returns from stale filter parameters:
        // Validate if the selected organization ID exists in the database before appending it to query constraints
        if ($orgId) {
            $orgExists = $em->getRepository(\App\Entity\Organization::class)->find($orgId);
            if ($orgExists) {
                $qb->andWhere('a.organization = :orgId')
                   ->setParameter('orgId', $orgId);
            }
        }
        if ($action) {
            $qb->andWhere('a.action LIKE :action')
               ->setParameter('action', '%' . $action . '%');
        }

        $logs = $qb->getQuery()->setMaxResults(1000)->getResult();

        if ($request->query->get('export') === 'csv') {
            $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($logs) {
                $handle = fopen('php://output', 'w+');
                fputcsv($handle, ['Timestamp', 'Organization', 'Action', 'Manager', 'Details', 'IP']);
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->getCreatedAt()->format('Y-m-d H:i:s'),
                        $log->getOrganization() ? $log->getOrganization()->getName() : 'System',
                        $log->getAction(),
                        $log->getManager() ? $log->getManager()->getEmail() : 'System',
                        json_encode($log->getDetailsJson()),
                        $log->getIpAddress()
                    ]);
                }
                fclose($handle);
            });

            $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="audit_logs.csv"');
            return $response;
        }

        $organizations = $em->getRepository(\App\Entity\Organization::class)->findAll();

        return $this->render('admin/audit_logs.html.twig', [
            'logs' => $logs,
            'organizations' => $organizations,
        ]);
    }

    #[Route('/settings', name: 'app_admin_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, AppConfigService $configService, EntityManagerInterface $em): Response
    {
        // 1. Process Form Submissions (POST requests)
        if ($request->isMethod('POST')) {
            $allowedCountries = $request->request->all('allowed_phone_countries');
            
            // Clean phone country input array
            $allowedCountries = array_filter($allowedCountries, function($value) {
                return !empty(trim($value));
            });

            // Default to US region if no boxes are checked
            if (empty($allowedCountries)) {
                $allowedCountries = ['us'];
            }
            
            $configService->set('allowed_phone_countries', array_values($allowedCountries));

            // Validate and set CSV row import limits
            $maxImportRecords = (int)$request->request->get('max_import_records', 100);
            if ($maxImportRecords < 1) $maxImportRecords = 100;
            $configService->set('max_import_records', $maxImportRecords);

            // Validate and set password/token rotation limits for demo organizations
            $demoRotationHours = (int)$request->request->get('demo_rotation_hours', 12);
            if ($demoRotationHours < 1) $demoRotationHours = 12;
            $configService->set('demo_rotation_hours', $demoRotationHours);

            // Toggle Captchas for console logins (Manager and Platform Admin)
            $captchaLoginEnabled = $request->request->get('captcha_login_enabled') === '1';
            $configService->set('captcha_login_enabled', $captchaLoginEnabled);

            // Toggle Captchas for requesting demo credentials
            $captchaDemoEnabled = $request->request->get('captcha_demo_enabled') === '1';
            $configService->set('captcha_demo_enabled', $captchaDemoEnabled);

            // Enable or disable new organization signups globally on the website
            $newSignupsEnabled = $request->request->get('new_signups_enabled') === '1';
            $configService->set('new_signups_enabled', $newSignupsEnabled);

            // Define database retention days before archiving old audit records
            $auditLogArchiveDays = (int)$request->request->get('audit_log_archive_days', 180);
            $configService->set('audit_log_archive_days', $auditLogArchiveDays);

            // Regulate request-triggered session sweeps using a seconds-based lock
            $sessionSweepThrottleSeconds = (int)$request->request->get('session_sweep_throttle_seconds', 60);
            $configService->set('session_sweep_throttle_seconds', $sessionSweepThrottleSeconds);

            // Control history depth generated during database refreshes
            $demoResetHistoryDays = (int)$request->request->get('demo_reset_history_days', 150);
            $configService->set('demo_reset_history_days', $demoResetHistoryDays);

            // Designate target directory path for zipped audit history files
            $auditLogArchiveDir = $request->request->get('audit_log_archive_dir', '/var/log/audit_archives');
            $configService->set('audit_log_archive_dir', $auditLogArchiveDir);

            // Audit log archive schedule
            $auditLogArchiveSchedule = $request->request->get('audit_log_archive_schedule', 'first_of_month');
            $configService->set('audit_log_archive_schedule', $auditLogArchiveSchedule);

            // Timezone settings configuration
            $displayTimezone = $request->request->get('display_timezone', 'America/Chicago');
            $configService->set('display_timezone', $displayTimezone);

            // Terms and Conditions & Privacy Policy
            $termsAndConditions = $request->request->get('terms_and_conditions', '');
            $privacyPolicy = $request->request->get('privacy_policy', '');
            $configService->set('terms_and_conditions', $termsAndConditions);
            $configService->set('privacy_policy', $privacyPolicy);

            $this->logAdminAudit($em, 'SETTINGS_UPDATE', [
                'allowed_phone_countries' => array_values($allowedCountries),
                'max_import_records' => $maxImportRecords,
                'demo_rotation_hours' => $demoRotationHours,
                'captcha_login_enabled' => $captchaLoginEnabled,
                'captcha_demo_enabled' => $captchaDemoEnabled,
                'new_signups_enabled' => $newSignupsEnabled,
                'audit_log_archive_days' => $auditLogArchiveDays,
                'audit_log_archive_schedule' => $auditLogArchiveSchedule,
                'session_sweep_throttle_seconds' => $sessionSweepThrottleSeconds,
                'demo_reset_history_days' => $demoResetHistoryDays,
                'audit_log_archive_dir' => $auditLogArchiveDir,
                'display_timezone' => $displayTimezone,
                'terms_and_conditions' => $termsAndConditions,
                'privacy_policy' => $privacyPolicy,
            ], $request);

            $this->addFlash('success', 'Global settings updated successfully.');
            return $this->redirectToRoute('app_admin_settings');
        }

        $allowedPhoneCountries = $configService->get('allowed_phone_countries', ['us']);
        $maxImportRecords = $configService->get('max_import_records', 100);
        $demoRotationHours = $configService->get('demo_rotation_hours', 12);
        $captchaLoginEnabled = $configService->get('captcha_login_enabled', false);
        $captchaDemoEnabled = $configService->get('captcha_demo_enabled', false);
        $newSignupsEnabled = $configService->get('new_signups_enabled', true);
        $auditLogArchiveDays = $configService->get('audit_log_archive_days', 180);
        $sessionSweepThrottleSeconds = $configService->get('session_sweep_throttle_seconds', 60);
        $demoResetHistoryDays = $configService->get('demo_reset_history_days', 150);
        $auditLogArchiveDir = $configService->get('audit_log_archive_dir', '/var/log/audit_archives');
        $displayTimezone = $configService->get('display_timezone', 'America/Chicago');

        $defaultTerms = "TERMS OF SERVICE\n\n1. Acceptance of Terms\nBy creating an account, registering an organization, or utilizing any portion of the CenterTime multi-tenant check-in and time-tracking service (the \"Service\"), you agree to be bound by these Terms of Service. If you do not agree, you must not access or use the Service.\n\n2. Description of Service\nCenterTime is a web-based multi-tenant employee time-tracking, attendance monitoring, and check-in kiosk platform. The Service is provided \"as is\" and \"as available\". We reserve the right to modify, suspend, or terminate the Service at any time without liability.\n\n3. Account Security and Responsibilities\n- You are responsible for maintaining the confidentiality of your manager credentials and all employee PINs/tokens.\n- You agree to ensure that all employee records, hours logged, and configurations represent accurate business operations.\n- You must comply with all local labor, employment, and wage laws. You represent that you have obtained all necessary employee consents for tracking their work hours and collecting their contact information.\n\n4. Limitation of Liability\nTO THE MAXIMUM EXTENT PERMITTED BY LAW, CENTERTIME AND ITS OPERATORS SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF PROFITS, REVENUE, DATA, OR USE, INCURRED BY YOU OR ANY THIRD PARTY. \nWe assume no responsibility or liability for:\n- Discrepancies in payroll calculation, timesheet exports, or labor compliance.\n- System downtime, data corruption, or loss of access to logged histories.\n- Fines, penalties, or damages resulting from your failure to comply with local labor regulations.\n\n5. Subscription Plans, Billing, and Quotas\n- Subscription fees for paid plans are billed in advance on a monthly recurring basis and are non-refundable.\n- Plan limits (active employee counts, manager seats, data retention thresholds, and features) are strictly enforced based on your selected subscription tier.\n- We reserve the right to suspend or terminate accounts for past-due balances or violations of these terms.";
        $defaultPrivacy = "PRIVACY POLICY\n\n1. Information We Collect\nWe collect metadata essential for providing secure time-tracking services, including:\n- Manager names, email addresses, and phone numbers.\n- Organization details (name, street address, slug, billing info).\n- Employee information (first/last names, phone numbers, unique check-in PIN hashes, and barcode data).\n- Time log history (check-in/out timestamps, selected categories, computed session durations).\n\n2. How We Use Information\nWe use your data solely to operate and improve the Service, including:\\n- Generating timesheets and organization reports.\n- Activating kiosk authorization and validating employee check-ins.\n- Sending transactional SMS notifications or emails.\n- Processing secure subscription payments via our integration partners.\n\n3. Data Protection and Hashing\n- Manager passwords and employee PINs are cryptographically hashed using secure, one-way hashing algorithms. We never store plain-text credentials.\n- Kiosk login states are secured using ephemeral, cryptographically random link tokens.\n\n4. Data Sharing and Third-Party Disclosures\nWe do not sell, rent, or lease customer data. We share information only with trusted service providers to the extent necessary to perform billing processes (e.g., Stripe) and run system hosting.\n\n5. Data Access and Portability\nManagers can export timesheets and user records in Excel or CSV formats at any time. We store log history in accordance with your plan's retention settings (up to 180 days by default), after which records are archived and deleted from our active database.";

        $termsAndConditions = $configService->get('terms_and_conditions', $defaultTerms);
        $privacyPolicy = $configService->get('privacy_policy', $defaultPrivacy);

        $auditLogArchiveSchedule = $configService->get('audit_log_archive_schedule', 'first_of_month');

        return $this->render('admin/settings.html.twig', [
            'allowedPhoneCountries' => $allowedPhoneCountries,
            'maxImportRecords' => $maxImportRecords,
            'demoRotationHours' => $demoRotationHours,
            'captchaLoginEnabled' => $captchaLoginEnabled,
            'captchaDemoEnabled' => $captchaDemoEnabled,
            'newSignupsEnabled' => $newSignupsEnabled,
            'auditLogArchiveDays' => $auditLogArchiveDays,
            'auditLogArchiveSchedule' => $auditLogArchiveSchedule,
            'sessionSweepThrottleSeconds' => $sessionSweepThrottleSeconds,
            'demoResetHistoryDays' => $demoResetHistoryDays,
            'auditLogArchiveDir' => $auditLogArchiveDir,
            'displayTimezone' => $displayTimezone,
            'termsAndConditions' => $termsAndConditions,
            'privacyPolicy' => $privacyPolicy,
        ]);
    }

    #[Route('/demo-reset', name: 'app_admin_demo_reset', methods: ['POST'])]
    public function resetDemoData(Request $request, EntityManagerInterface $em, \Symfony\Component\Messenger\MessageBusInterface $messageBus): Response
    {
        $messageBus->dispatch(new \App\Message\ResetDemoDataMessage());

        $this->logAdminAudit($em, 'DEMO_DATA_RESET_QUEUED', [], $request);

        $this->addFlash('success', 'Demo Data Reset has been queued and is running in the background. It will be ready shortly.');
        return $this->redirectToRoute('app_admin_settings');
    }

    #[Route('/scheduled-jobs', name: 'app_admin_scheduled_jobs')]
    public function scheduledJobs(AppConfigService $configService): Response
    {
        // Define known scheduled jobs configured on the server
        $jobs = [
            [
                'name' => 'Sweep Active Sessions',
                'command' => 'php bin/console app:sweep-sessions',
                'schedule' => '*/5 * * * * (Every 5 minutes)',
                'description' => 'Automatically ends work sessions that have exceeded their maximum duration based on their organization plan.',
                'status' => 'Active'
            ],
            [
                'name' => 'Rotate Demo Credentials',
                'command' => 'php bin/console app:rotate-demo-credentials',
                'schedule' => '0 0,12 * * * (Every 12 hours)',
                'description' => 'Rotates the manager passwords and kiosk URL tokens for the Demo organizations to prevent unauthorized prolonged access.',
                'status' => 'Active'
            ],
            [
                'name' => 'Audit Log Archival',
                'command' => 'php bin/console app:archive-audit-logs',
                'schedule' => '0 0 * * * (Daily)',
                'description' => 'Archives audit logs older than the configured number of days to keep the database size manageable.',
                'status' => 'Active'
            ]
        ];

        $cronToken = $this->getParameter('cron_secret_token');
        $demoRotationHours = $configService->get('demo_rotation_hours', 12);

        return $this->render('admin/scheduled_jobs.html.twig', [
            'jobs' => $jobs,
            'cronToken' => $cronToken,
            'demoRotationHours' => $demoRotationHours
        ]);
    }

    #[Route('/active-sessions', name: 'app_admin_active_sessions', methods: ['GET'])]
    public function activeSessions(EntityManagerInterface $em): Response
    {
        $activeSessions = $em->getRepository(WorkSession::class)->findBy(
            ['endedAt' => null],
            ['startedAt' => 'DESC']
        );

        return $this->render('admin/active_sessions.html.twig', [
            'activeSessions' => $activeSessions,
        ]);
    }

    #[Route('/active-sessions/force-end/{id}', name: 'app_admin_session_force_end', methods: ['POST'])]
    public function forceEndSession(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $session = $em->getRepository(WorkSession::class)->find($id);

        if ($session && !$session->getEndedAt()) {
            $now = new \DateTime();
            $session->setEndedAt($now);
            $session->setEndMethod('Admin Force End');
            
            $durationSeconds = $now->getTimestamp() - $session->getStartedAt()->getTimestamp();
            $session->setDurationSeconds($durationSeconds);

            $em->flush();

            $admin = $this->getUser();
            $this->logAdminAudit($em, 'ADMIN_FORCE_END_SESSION', [
                'session_id' => $session->getId(),
                'user' => $session->getOrgUser()->getFirstName() . ' ' . $session->getOrgUser()->getLastName(),
                'org' => $session->getOrganization()->getName(),
                'forced_by' => $admin->getFirstName() . ' ' . $admin->getLastName() . ' (' . $admin->getEmail() . ')'
            ], $request, $session->getOrganization());
            
            $this->addFlash('success', "Session for {$session->getOrgUser()->getFirstName()} {$session->getOrgUser()->getLastName()} has been forcefully ended.");
        }

        $referer = $request->headers->get('referer');
        return $referer ? $this->redirect($referer) : $this->redirectToRoute('app_admin_active_sessions');
    }

    #[Route('/emails', name: 'app_admin_emails', methods: ['GET'])]
    public function emailsList(): Response
    {
        $templates = [
            [
                'name' => 'welcome',
                'filename' => 'welcome.html.twig',
                'description' => 'Onboarding / Welcome email sent to organization managers immediately after signing up.',
                'variables' => ['{{ manager.firstName }}', '{{ org.name }}'],
            ],
            [
                'name' => 'renewal_success',
                'filename' => 'renewal_success.html.twig',
                'description' => 'Billing renewal receipt notice sent when a subscription payment is processed successfully.',
                'variables' => ['{{ manager.firstName }}', '{{ amount }}', '{{ renewalDate }}'],
            ],
            [
                'name' => 'expiry_warning',
                'filename' => 'expiry_warning.html.twig',
                'description' => 'Dunning notice sent to managers when a card charge fails, warning of suspension.',
                'variables' => ['{{ manager.firstName }}', '{{ org.name }}'],
            ],
            [
                'name' => 'upcoming_invoice',
                'filename' => 'upcoming_invoice.html.twig',
                'description' => 'Upcoming billing reminder sent to managers before a renewal charge is attempted.',
                'variables' => ['{{ manager.firstName }}', '{{ amount }}', '{{ dueDate }}'],
            ]
        ];

        return $this->render('admin/emails/index.html.twig', [
            'templates' => $templates,
        ]);
    }

    #[Route('/emails/edit/{templateName}', name: 'app_admin_email_edit', methods: ['GET', 'POST'])]
    public function editEmailTemplate(string $templateName, Request $request, EntityManagerInterface $em): Response
    {
        $allowed = ['welcome', 'renewal_success', 'expiry_warning', 'upcoming_invoice'];
        if (!in_array($templateName, $allowed)) {
            throw $this->createNotFoundException('Template not found.');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $filePath = $projectDir . '/templates/email/' . $templateName . '.html.twig';

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Template file not found.');
        }

        if ($request->isMethod('POST')) {
            $content = $request->request->get('content');
            file_put_contents($filePath, $content);
            $this->addFlash('success', 'Email template updated successfully.');
            
            $this->logAdminAudit($em, 'EMAIL_TEMPLATE_EDIT', ['template' => $templateName], $request);

            return $this->redirectToRoute('app_admin_emails');
        }

        $content = file_get_contents($filePath);

        return $this->render('admin/emails/edit.html.twig', [
            'templateName' => $templateName,
            'content' => $content,
        ]);
    }

    #[Route('/organizations/archive-toggle/{id}', name: 'app_admin_organization_archive_toggle', methods: ['POST'])]
    public function toggleArchive(int $id, EntityManagerInterface $em, Request $request): Response
    {
        $org = $em->getRepository(Organization::class)->find($id);
        if (!$org) {
            throw $this->createNotFoundException('Organization not found.');
        }

        $org->setIsArchived(!$org->isArchived());
        $em->flush();

        $this->logAdminAudit($em, 'ORGANIZATION_ARCHIVE_TOGGLE', [
            'org' => $org->getName(),
            'new_status' => $org->isArchived() ? 'Archived' : 'Active'
        ], $request, $org);

        $this->addFlash('success', "Organization {$org->getName()} status updated.");
        return $this->redirectToRoute('app_admin_organizations');
    }

    #[Route('/organizations/delete/{id}', name: 'app_admin_organization_delete', methods: ['POST'])]
    public function deleteOrganization(int $id, EntityManagerInterface $em, Request $request): Response
    {
        $org = $em->getRepository(Organization::class)->find($id);
        if (!$org) {
            throw $this->createNotFoundException('Organization not found.');
        }

        $managers = $em->getRepository(Manager::class)->findBy(['organization' => $org]);
        $users = $em->getRepository(OrgUser::class)->findBy(['organization' => $org]);
        $categories = $em->getRepository(Category::class)->findBy(['organization' => $org]);
        $sessions = $em->getRepository(\App\Entity\WorkSession::class)->findBy(['organization' => $org]);

        $auditLogs = $em->getRepository(AuditLog::class)->findBy(['organization' => $org]);
        $payments = $em->getRepository(\App\Entity\PaymentHistory::class)->findBy(['organization' => $org]);

        $data = [
            'organization' => [
                'id' => $org->getId(),
                'name' => $org->getName(),
                'slug' => $org->getSlug(),
                'plan' => $org->getPlan()->getName(),
                'isSubscriptionActive' => $org->isSubscriptionActive(),
                'isArchived' => $org->isArchived(),
            ],
            'managers' => array_map(fn($m) => [
                'email' => $m->getEmail(),
                'firstName' => $m->getFirstName(),
                'lastName' => $m->getLastName(),
                'roles' => $m->getRoles(),
            ], $managers),
            'users' => array_map(fn($u) => [
                'firstName' => $u->getFirstName(),
                'lastName' => $u->getLastName(),
                'pinHash' => $u->getPinHash(),
                'uniqueIdHash' => $u->getUniqueIdHash(),
                'isActive' => $u->isActive(),
            ], $users),
            'categories' => array_map(fn($c) => [
                'name' => $c->getName(),
                'icon' => $c->getIcon(),
                'isActive' => $c->isActive(),
            ], $categories),
            'sessions' => array_map(fn($s) => [
                'user' => $s->getOrgUser() ? ($s->getOrgUser()->getFirstName() . ' ' . $s->getOrgUser()->getLastName()) : 'Unknown',
                'startedAt' => $s->getStartedAt() ? $s->getStartedAt()->format(\DateTimeInterface::ATOM) : null,
                'endedAt' => $s->getEndedAt() ? $s->getEndedAt()->format(\DateTimeInterface::ATOM) : null,
                'durationSeconds' => $s->getDurationSeconds(),
            ], $sessions),
            'audit_logs' => array_map(fn($l) => [
                'action' => $l->getAction(),
                'details' => $l->getDetailsJson(),
                'createdAt' => $l->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $auditLogs),
            'payments' => array_map(fn($p) => [
                'stripeInvoiceId' => $p->getStripeInvoiceId(),
                'amount' => $p->getAmount(),
                'paidAt' => $p->getPaidAt() ? $p->getPaidAt()->format(\DateTimeInterface::ATOM) : null,
            ], $payments),
        ];

        $projectDir = $this->getParameter('kernel.project_dir');
        $archiveDir = $projectDir . '/var/archives';
        if (!file_exists($archiveDir)) {
            mkdir($archiveDir, 0777, true);
        }

        $zipPath = $archiveDir . '/' . $org->getSlug() . '_' . time() . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) === true) {
            $zip->addFromString('backup.json', json_encode($data, JSON_PRETTY_PRINT));
            $zip->close();
        }

        // 1. Delete dependent child records first and flush
        foreach ($sessions as $s) {
            $em->remove($s);
        }
        foreach ($auditLogs as $l) {
            $em->remove($l);
        }
        foreach ($payments as $p) {
            $em->remove($p);
        }
        $em->flush();

        // 2. Delete main entity associations and flush
        foreach ($users as $u) {
            $em->remove($u);
        }
        foreach ($categories as $c) {
            $em->remove($c);
        }
        foreach ($managers as $m) {
            $em->remove($m);
        }
        $em->flush();

        // 3. Delete the parent organization and flush
        $em->remove($org);
        $em->flush();

        $this->logAdminAudit($em, 'ORGANIZATION_DELETED', [
            'org' => $org->getName(),
            'slug' => $org->getSlug(),
            'backup_file' => basename($zipPath)
        ], $request);

        $this->addFlash('success', "Organization {$org->getName()} has been permanently deleted. Backup archive saved.");
        return $this->redirectToRoute('app_admin_organizations');
    }

    #[Route('/demo-leads/delete/{id}', name: 'app_admin_demo_lead_delete', methods: ['POST'])]
    public function deleteDemoLead(int $id, EntityManagerInterface $em, Request $request): Response
    {
        $lead = $em->getRepository(\App\Entity\DemoLead::class)->find($id);
        if ($lead) {
            $name = $lead->getName();
            $em->remove($lead);
            $em->flush();

            $this->logAdminAudit($em, 'DEMO_LEAD_DELETED', ['name' => $name, 'email' => $lead->getEmail()], $request);

            $this->addFlash('success', "Demo lead for {$name} deleted successfully.");
        }

        return $this->redirectToRoute('app_admin_demo_leads');
    }
}
