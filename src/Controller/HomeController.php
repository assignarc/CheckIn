<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\PaymentHistory;
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
        if ($this->getUser()) {
            return $this->redirectToRoute('app_redirect_by_role');
        }
        $plans = $entityManager->getRepository(\App\Entity\Plan::class)->findAll();
        $basicPlan = $entityManager->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => 'basic']);
        return $this->render('home/index.html.twig', [
            'plans' => $plans,
            'basic_plan' => $basicPlan,
            'new_signups_enabled' => $configService->get('new_signups_enabled', true)
        ]);
    }

    #[Route('/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function signup(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, \App\Service\TokenGeneratorService $tokenGenerator, CaptchaService $captchaService, \App\Service\AppConfigService $configService): Response
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
            
            if (!$captchaService->validate($request, 'signup', $captchaInput)) {
                $this->addFlash('danger', 'Incorrect math captcha answer. Please try again.');
                return $this->redirectToRoute('app_signup');
            }
            
            // Validate unique slug
            $existingOrg = $entityManager->getRepository(Organization::class)->findOneBy(['slug' => $orgSlug]);
            if ($existingOrg) {
                $this->addFlash('danger', 'This organization URL slug is already taken.');
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
                ]);
            }

            // Validate unique manager email
            $existingManager = $entityManager->getRepository(Manager::class)->findOneBy(['email' => $email]);
            if ($existingManager) {
                $this->addFlash('danger', 'A user with this email address already exists.');
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
                ]);
            }

            $entityManager->wrapInTransaction(function() use ($entityManager, $orgName, $orgSlug, $orgAddress, $orgPhone, $tokenGenerator, $plan, $plans, $email, $firstName, $lastName, $managerPhone, $passwordHasher, $password) {
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

                $entityManager->flush();
            });

            $this->addFlash('success', 'Your organization account has been successfully created! You can now log in.');
            return $this->redirectToRoute('app_login');
        }

        $captchaQuestion = $captchaService->generateQuestion($request, 'signup');

        return $this->render('home/signup.html.twig', [
            'plans' => $plans,
            'captcha_question' => $captchaQuestion,
        ]);
    }

    #[Route('/demo', name: 'app_demo')]
    public function demo(Request $request, CaptchaService $captchaService): Response
    {
        $captchaEnabled = $captchaService->isEnabled('demo');
        $captchaQuestion = $captchaEnabled ? $captchaService->generateQuestion($request, 'demo') : '';

        return $this->render('home/demo.html.twig', [
            'captcha_enabled' => $captchaEnabled,
            'captcha_question' => $captchaQuestion,
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
}
