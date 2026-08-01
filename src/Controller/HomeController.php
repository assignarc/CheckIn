<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\PaymentHistory;
use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\CaptchaService;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(EntityManagerInterface $entityManager, \App\Service\AppConfigService $configService): Response
    {
        $plans = $entityManager->getRepository(\App\Entity\Plan::class)->findAll();
        $basicPlan = $entityManager->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => 'basic']);
        return $this->render('home/index.html.twig', [
            'plans' => $plans,
            'basic_plan' => $basicPlan,
            'new_signups_enabled' => $configService->get('new_signups_enabled', true)
        ]);
    }

    #[Route('/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function signup(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, \App\Service\TokenGeneratorService $tokenGenerator, CaptchaService $captchaService, \App\Service\AppConfigService $configService, \App\Service\EmailService $emailService): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_redirect_by_role');
        }

        if (!$configService->get('new_signups_enabled', true)) {
            $this->addFlash('danger', 'New organization registration is currently disabled by the administrator.');
            return $this->redirectToRoute('app_home');
        }

        $plans = $entityManager->getRepository(\App\Entity\Plan::class)->findAll();

        if ($request->isMethod('POST')) {
            $orgName = $request->request->get('org_name');
            $orgSlug = $request->request->get('org_slug');
            $orgStreet = $request->request->get('org_street');
            $orgSuite = $request->request->get('org_suite');
            $orgCity = $request->request->get('org_city');
            $orgState = $request->request->get('org_state');
            $orgZip = $request->request->get('org_zip');

            // Format address: Street, Suite, City, State ZIP
            $addressParts = [];
            if (!empty($orgStreet)) { $addressParts[] = $orgStreet; }
            if (!empty($orgSuite)) { $addressParts[] = $orgSuite; }
            if (!empty($orgCity)) { $addressParts[] = $orgCity; }
            if (!empty($orgState) || !empty($orgZip)) {
                $addressParts[] = trim(($orgState ?? '') . ' ' . ($orgZip ?? ''));
            }
            $orgAddress = implode(', ', $addressParts);

            $orgPhone = $request->request->get('org_phone');
            $firstName = $request->request->get('first_name');
            $lastName = $request->request->get('last_name');
            $email = $request->request->get('email');
            $managerPhone = $request->request->get('manager_phone');
            $password = $request->request->get('password');
            $plan = $request->request->get('plan', 'basic');
            $captchaInput = $request->request->get('captcha_answer');
            
            $acceptPolicies = $request->request->get('accept_policies');
            if (!$acceptPolicies) {
                $this->addFlash('danger', 'You must accept the Terms of Service and Privacy Policy to register.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$captchaService->validate($request, 'signup', $captchaInput)) {
                $this->addFlash('danger', 'Incorrect math captcha answer. Please try again.');
                return $this->redirectToRoute('app_signup');
            }

            // Length and data-type validations
            if (!$orgName || strlen($orgName) < 2 || strlen($orgName) > 100) {
                $this->addFlash('danger', 'Organization name must be between 2 and 100 characters.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$orgSlug || !preg_match('/^[a-z0-9\-]{2,50}$/', $orgSlug)) {
                $this->addFlash('danger', 'Kiosk URL slug must be 2-50 lowercase alphanumeric characters or hyphens.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$orgStreet || strlen($orgStreet) < 2 || strlen($orgStreet) > 150) {
                $this->addFlash('danger', 'Street address must be between 2 and 150 characters.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$orgCity || strlen($orgCity) < 2 || strlen($orgCity) > 100) {
                $this->addFlash('danger', 'City must be between 2 and 100 characters.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$orgState || strlen($orgState) < 2 || strlen($orgState) > 50) {
                $this->addFlash('danger', 'State must be between 2 and 50 characters.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$orgZip || strlen($orgZip) < 3 || strlen($orgZip) > 10) {
                $this->addFlash('danger', 'ZIP code must be between 3 and 10 characters.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$orgPhone || strlen(preg_replace('/[^0-9\+]/', '', $orgPhone)) < 7) {
                $this->addFlash('danger', 'Please enter a valid organization phone number.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$firstName || strlen($firstName) < 2 || strlen($firstName) > 50) {
                $this->addFlash('danger', 'Manager first name must be between 2 and 50 characters.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$lastName || strlen($lastName) < 2 || strlen($lastName) > 50) {
                $this->addFlash('danger', 'Manager last name must be between 2 and 50 characters.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('danger', 'Please enter a valid manager email address.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$managerPhone || strlen(preg_replace('/[^0-9\+]/', '', $managerPhone)) < 7) {
                $this->addFlash('danger', 'Please enter a valid manager phone number.');
                return $this->redirectToRoute('app_signup');
            }

            if (!$password || strlen($password) < 8 || strlen($password) > 100) {
                $this->addFlash('danger', 'Password must be at least 8 characters long.');
                return $this->redirectToRoute('app_signup');
            }
            
            // Validate unique slug — use native SQL to catch conflicts with soft-deleted orgs
            // (Doctrine's SoftDeleteable filter skips deleted rows, but the DB unique index does not)
            $conn = $entityManager->getConnection();
            $slugTaken = (bool) $conn->fetchOne(
                'SELECT COUNT(*) FROM organizations WHERE slug = ?',
                [$orgSlug]
            );
            if ($slugTaken) {
                $this->addFlash('danger', 'This organization URL slug is already taken. Please choose a different one.');
                $captchaImage = $captchaService->generateImage($request, 'signup');
                return $this->render('home/signup.html.twig', [
                    'org_name' => $orgName,
                    'org_slug' => $orgSlug,
                    'org_street' => $orgStreet,
                    'org_suite' => $orgSuite,
                    'org_city' => $orgCity,
                    'org_state' => $orgState,
                    'org_zip' => $orgZip,
                    'org_phone' => $orgPhone,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'manager_phone' => $managerPhone,
                    'plan' => $plan,
                    'plans' => $plans,
                    'new_signups_enabled' => true,
                    'captcha_image' => $captchaImage,
                ]);
            }

            // Validate unique organization name — use native SQL to include soft-deleted rows
            $nameTaken = (bool) $conn->fetchOne(
                'SELECT COUNT(*) FROM organizations WHERE LOWER(name) = LOWER(?)',
                [$orgName]
            );
            if ($nameTaken) {
                $this->addFlash('danger', 'An organization with this name already exists. Please use a different name.');
                $captchaImage = $captchaService->generateImage($request, 'signup');
                return $this->render('home/signup.html.twig', [
                    'org_name' => $orgName,
                    'org_slug' => $orgSlug,
                    'org_street' => $orgStreet,
                    'org_suite' => $orgSuite,
                    'org_city' => $orgCity,
                    'org_state' => $orgState,
                    'org_zip' => $orgZip,
                    'org_phone' => $orgPhone,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'manager_phone' => $managerPhone,
                    'plan' => $plan,
                    'plans' => $plans,
                    'new_signups_enabled' => true,
                    'captcha_image' => $captchaImage,
                ]);
            }

            // Validate unique manager email
            $existingManager = $entityManager->getRepository(Manager::class)->findOneBy(['email' => $email]);
            if ($existingManager) {
                $this->addFlash('danger', 'A user with this email address already exists.');
                $captchaImage = $captchaService->generateImage($request, 'signup');
                return $this->render('home/signup.html.twig', [
                    'org_name' => $orgName,
                    'org_slug' => $orgSlug,
                    'org_street' => $orgStreet,
                    'org_suite' => $orgSuite,
                    'org_city' => $orgCity,
                    'org_state' => $orgState,
                    'org_zip' => $orgZip,
                    'org_phone' => $orgPhone,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'manager_phone' => $managerPhone,
                    'plan' => $plan,
                    'plans' => $plans,
                    'new_signups_enabled' => true,
                    'captcha_image' => $captchaImage,
                ]);
            }

            $manager = null;
            $entityManager->wrapInTransaction(function() use ($request, $entityManager, $orgName, $orgSlug, $orgAddress, $orgPhone, $tokenGenerator, $plan, $plans, $email, $firstName, $lastName, $managerPhone, $passwordHasher, $password, &$manager) {
                // Create Organization
                $org = new Organization();
                $org->setName($orgName);
                $org->setSlug($orgSlug);
                $org->setAddress($orgAddress);
                $org->setPhone($orgPhone);
                $org->setIsSubscriptionActive(true);
                $org->setCheckInLinkToken($tokenGenerator->generateHumanReadableToken());

                $planEntity = $entityManager->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => $plan]);
                if (!$planEntity) {
                    $planEntity = $entityManager->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => 'basic']);
                }
                $org->setPlan($planEntity);

                if (!$planEntity->hasSecureKiosk()) {
                    $org->setAccessMode('open');
                } else {
                    $org->setAccessMode('secure');
                }

                $entityManager->persist($org);

                // Create Manager
                $manager = new Manager();
                $manager->setOrganization($org);
                $manager->setEmail($email);
                $manager->setFirstName($firstName);
                $manager->setLastName($lastName);
                $manager->setPhone($managerPhone);
                $manager->setRole('ROLE_ORG_MANAGER');
                $manager->setIsActive(true);
                $manager->setPasswordHash($passwordHasher->hashPassword($manager, $password));
                $manager->setAcceptedPoliciesAt(new \DateTimeImmutable());

                $entityManager->persist($manager);

                // Create default category
                $category = new Category();
                $category->setOrganization($org);
                $category->setName('General');
                $category->setIcon('work');
                $category->setIsActive(true);
                $entityManager->persist($category);

                // If paid plan, record payment history record
                if ($plan !== 'basic') {
                    $payment = new PaymentHistory();
                    $payment->setOrganization($org);
                    $payment->setStripeInvoiceId('sub_' . bin2hex(random_bytes(8)));
                    $payment->setAmount(number_format($planEntity->getPriceMonthly(), 2, '.', ''));
                    $payment->setStatus('paid');
                    $payment->setPaidAt(new \DateTime());
                    $payment->setPeriodStart(new \DateTime());
                    $payment->setPeriodEnd(new \DateTime('+1 month'));
                    $entityManager->persist($payment);
                }

                // Log Tenant Signup Event
                $log = new AuditLog();
                $log->setOrganization($org);
                $log->setManager($manager);
                $log->setAction('TENANT_SIGNUP');
                $log->setDetailsJson([
                    'organization' => $org->getName(),
                    'slug' => $org->getSlug(),
                    'plan' => $planEntity->getName(),
                    'email' => $manager->getEmail()
                ]);
                $log->setIpAddress($request->getClientIp());
                $log->setUserAgent($request->headers->get('User-Agent'));
                $entityManager->persist($log);

                $entityManager->flush();
            });

            // Dispatch welcome email
            if ($manager) {
                try {
                    $emailService->sendWelcomeEmail($manager);
                } catch (\Exception $e) {
                    // Ignore mailer failure during redirect to prevent blocking account creation
                }
            }

            $this->addFlash('success', 'Your organization account has been successfully created! You can now log in.');
            return $this->redirectToRoute('app_login');
        }

        $captchaImage = $captchaService->generateImage($request, 'signup');

        return $this->render('home/signup.html.twig', [
            'plans' => $plans,
            'captcha_image' => $captchaImage,
            'new_signups_enabled' => true,
        ]); // GET render — captcha_image also generated at top of POST block for error re-renders
    }

    #[Route('/demo', name: 'app_demo')]
    public function demo(Request $request, CaptchaService $captchaService): Response
    {
        $captchaEnabled = $captchaService->isEnabled('demo');
        $captchaImage = $captchaEnabled ? $captchaService->generateImage($request, 'demo') : '';

        return $this->render('home/demo.html.twig', [
            'captcha_enabled' => $captchaEnabled,
            'captcha_image' => $captchaImage,
        ]);
    }

    #[Route('/request-demo', name: 'app_request_demo', methods: ['POST'])]
    public function requestDemo(Request $request, EntityManagerInterface $em, CaptchaService $captchaService): Response
    {
        $name = $request->request->get('name');
        $email = $request->request->get('email');
        $phone = $request->request->get('phone');
        $company = $request->request->get('company');
        $captcha = $request->request->get('captcha');

        if ($captchaService->isEnabled('demo')) {
            if (!$captchaService->validate($request, 'demo', $captcha)) {
                $this->addFlash('danger', 'Incorrect math captcha. Please try again.');
                return $this->redirectToRoute('app_demo');
            }
        }

        if ($name && $email) {
            $lead = new \App\Entity\DemoLead();
            $lead->setName($name);
            $lead->setEmail($email);
            $lead->setPhone($phone);
            $lead->setCompany($company);
            $em->persist($lead);
            $em->flush();
            
            // Unlock demo
            $request->getSession()->set('demo_unlocked', true);
            return $this->redirectToRoute('app_demo_access');
        }

        $this->addFlash('danger', 'Name and email are required to request a demo.');
        return $this->redirectToRoute('app_demo');
    }

    #[Route('/demo-access', name: 'app_demo_access')]
    public function demoAccess(Request $request, \App\Service\AppConfigService $configService): Response
    {
        if (!$request->getSession()->get('demo_unlocked')) {
            return $this->redirectToRoute('app_demo');
        }

        return $this->render('home/demo_access.html.twig', [
            'basic_password' => $configService->get('demo_basic_password'),
            'basic_token' => $configService->get('demo_basic_token'),
            'golden_password' => $configService->get('demo_golden_password'),
            'golden_token' => $configService->get('demo_golden_token'),
        ]);
    }

    #[Route('/plans/compare', name: 'app_plans_compare')]
    public function comparePlans(EntityManagerInterface $em, \App\Service\AppConfigService $configService): Response
    {
        $plans = $em->getRepository(\App\Entity\Plan::class)->findAll();
        return $this->render('home/compare.html.twig', [
            'plans' => $plans,
            'new_signups_enabled' => $configService->get('new_signups_enabled', true),
        ]);
    }
}
