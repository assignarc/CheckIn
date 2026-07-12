<?php

namespace App\Tests\Controller;

use App\Service\AppConfigService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests the global registration blocker policy.
 */
class SignupBlockerTest extends WebTestCase
{
    public function testSignupPageAccessibleWhenEnabled(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $configService = $container->get(AppConfigService::class);
        
        // Cache original setting value to restore
        $originalState = (bool)$configService->get('new_signups_enabled', true);

        try {
            // Enforce signup enabled
            $configService->set('new_signups_enabled', true);

            // Fetch registration page
            $client->request('GET', '/signup');
            $this->assertResponseIsSuccessful();
        } finally {
            $configService->set('new_signups_enabled', $originalState);
        }
    }

    public function testSignupPageBlockedAndRedirectedWhenDisabled(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $configService = $container->get(AppConfigService::class);

        // Cache original setting value to restore
        $originalState = (bool)$configService->get('new_signups_enabled', true);

        try {
            // Enforce signup disabled
            $configService->set('new_signups_enabled', false);

            // Fetch registration page -> should redirect
            $client->request('GET', '/signup');
            $this->assertResponseRedirects('/');

            // Follow redirect -> should show flash message
            $client->followRedirect();
            $this->assertSelectorTextContains('.alert-danger', 'New organization registration is currently disabled by the administrator.');
        } finally {
            $configService->set('new_signups_enabled', $originalState);
        }
    }
}
