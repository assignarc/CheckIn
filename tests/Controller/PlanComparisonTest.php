<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlanComparisonTest extends WebTestCase
{
    public function testPlansComparePageRedirectsToPricing(): void
    {
        $client = static::createClient();
        $client->request('GET', '/plans/compare');

        $this->assertResponseRedirects('/pricing', 301);
    }

    public function testSignupPageContainsPricingLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/signup');

        $this->assertResponseIsSuccessful();
        $this->assertAnySelectorTextContains('a', 'Compare all plans & core features in detail');
    }
}
