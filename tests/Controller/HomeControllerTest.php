<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HomeControllerTest extends WebTestCase
{
    public function testHomePageLoadsSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Next-Gen Time Tracking');
        $this->assertAnySelectorTextContains('a.nav-link', 'Features');
        $this->assertAnySelectorTextContains('a.nav-link', 'Pricing');
        $this->assertAnySelectorTextContains('a.nav-link', 'FAQ');
    }

    public function testFeaturesPageLoadsSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/features');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Engineered for Precision & Control');
        $this->assertAnySelectorTextContains('h2', 'Traceability & Audit');
        $this->assertAnySelectorTextContains('h2', 'Streamlined Spreadsheet Onboarding');
    }

    public function testPricingPageLoadsSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/pricing');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Simple, Transparent Subscription Tiers');
        $this->assertAnySelectorTextContains('th', 'Features & Specifications');
    }

    public function testFaqPageLoadsSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/faq');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Frequently Asked Questions');
        $this->assertAnySelectorTextContains('h3', 'How fast can we set up CenterTime for our organization?');
    }

    public function testAboutPageLoadsSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/about');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Modern Time Tracking Built for');
        $this->assertAnySelectorTextContains('h3', 'Enterprise Architecture');
    }

    public function testContactPageLoadsAndValidates(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contact');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', "We'd Love to");

        // Submit form with invalid email to verify server-side validation
        $form = $crawler->selectButton('Send Message')->form([
            'name' => 'Test User',
            'email' => 'invalid-email-address',
            'subject' => 'Testing validation',
            'message' => 'This is a test message for validation checks.',
        ]);

        $client->submit($form);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-danger', 'Please enter a valid email address.');
    }
}
