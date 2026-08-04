<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Service\AppConfigService;
use App\Service\CaptchaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TermsAndPrivacyPolicyTest extends WebTestCase
{
    public function testRegistrationRequiresPolicyAgreement(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // Fetch captcha question and compute answer
        $session = $client->getContainer()->get('session.factory')->createSession();
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()));

        $crawler = $client->request('GET', '/signup');
        $this->assertResponseIsSuccessful();

        // Submit form without accept_policies
        $form = $crawler->selectButton('Create Account')->form();
        $form['org_name'] = 'Policy Agreement Org';
        $form['org_slug'] = 'policy-agreement-org';
        $form['org_street'] = '123 Policy St';
        $form['org_city'] = 'Policyville';
        $form['org_state'] = 'PO';
        $form['org_zip'] = '12345';
        $form['org_phone'] = '+15550198881';
        $form['first_name'] = 'Policy';
        $form['last_name'] = 'Manager';
        $form['email'] = 'policy.manager@example.com';
        $form['manager_phone'] = '+15550198882';
        $form['password'] = 'SuperSecurePass123';
        // Note: captcha_answer is checked based on session question
        $captchaService = $container->get(CaptchaService::class);
        // We set captcha answer directly or bypass it via setting session
        $session->set('_captcha_result_signup', '4');
        $session->save();
        $form['captcha_answer'] = '4';

        // Submit without accept_policies should redirect or show validation error
        // Let's call the POST request manually to bypass browser validation and check server-side redirect
        $client->request('POST', '/signup', [
            'org_name' => 'Policy Agreement Org',
            'org_slug' => 'policy-agreement-org',
            'org_street' => '123 Policy St',
            'org_city' => 'Policyville',
            'org_state' => 'PO',
            'org_zip' => '12345',
            'org_phone' => '+15550198881',
            'first_name' => 'Policy',
            'last_name' => 'Manager',
            'email' => 'policy.manager@example.com',
            'manager_phone' => '+15550198882',
            'password' => 'SuperSecurePass123',
            'captcha_answer' => '4',
            // 'accept_policies' is missing
        ]);

        $this->assertResponseRedirects('/signup');
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'You must accept the Terms of Service and Privacy Policy to register.');
    }

    public function testRegistrationSavesPolicyTimestamp(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // Fetch unique manager email
        $email = 'policy.ok.' . uniqid() . '@example.com';
        $slug = 'policy-ok-' . uniqid();

        // Set session captcha
        $session = $client->getContainer()->get('session.factory')->createSession();
        $session->set('_captcha_result_signup', '6');
        $session->save();

        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()));

        $client->request('POST', '/signup', [
            'org_name' => 'Policy Ok Org ' . uniqid(),
            'org_slug' => $slug,
            'org_street' => '123 Policy St',
            'org_city' => 'Policyville',
            'org_state' => 'PO',
            'org_zip' => '12345',
            'org_phone' => '+15550198885',
            'first_name' => 'PolicyOk',
            'last_name' => 'ManagerOk',
            'email' => $email,
            'manager_phone' => '+15550198886',
            'plan' => 'basic',
            'password' => 'SuperSecurePass123',
            'captcha_answer' => '6',
            'accept_policies' => '1' // Policy accepted!
        ]);

        $this->assertResponseRedirects('/manager/dashboard');
        
        // Let's verify that the manager's acceptedPoliciesAt is populated
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($manager);
        $this->assertNotNull($manager->getAcceptedPoliciesAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $manager->getAcceptedPoliciesAt());
    }

    public function testAdminCanSavePolicies(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $configService = $container->get(AppConfigService::class);
        $em = $container->get(EntityManagerInterface::class);

        // Log in as admin
        $admin = $em->getRepository(Manager::class)->findOneBy(['role' => 'ROLE_SYSTEM_ADMIN']);
        if (!$admin) {
            $this->markTestSkipped('No admin user found to test settings updates.');
        }

        $client->loginUser($admin);

        // Update settings with new policy texts
        $client->request('POST', '/admin/settings', [
            'allowed_phone_countries' => ['us'],
            'max_import_records' => 100,
            'demo_rotation_hours' => 12,
            'captcha_login_enabled' => 0,
            'captcha_demo_enabled' => 0,
            'new_signups_enabled' => 1,
            'audit_log_archive_days' => 180,
            'session_sweep_throttle_seconds' => 60,
            'demo_reset_history_days' => 150,
            'audit_log_archive_dir' => '/var/log/audit_archives',
            'terms_and_conditions' => 'Updated Custom Terms Text',
            'privacy_policy' => 'Updated Custom Privacy Text',
        ]);

        $this->assertResponseRedirects('/admin/settings');
        
        // Assert values updated in service/db
        $this->assertEquals('Updated Custom Terms Text', $configService->get('terms_and_conditions'));
        $this->assertEquals('Updated Custom Privacy Text', $configService->get('privacy_policy'));
    }
}
