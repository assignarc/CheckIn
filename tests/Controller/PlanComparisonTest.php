<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlanComparisonTest extends WebTestCase
{
    public function testPlansComparePageLoadsSuccessfully(): void
    {
        $client = static::createClient();
        $client->request('GET', '/plans/compare');

        $this->assertResponseIsSuccessful();
        // The header contains the CenterTime branding logo; the compare page heading is in a div
        $this->assertSelectorTextContains('title', 'Compare Plans');
        $this->assertSelectorTextContains('table', 'Maximum Active Users');
    }

    public function testSignupPageContainsComparisonLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/signup');

        $this->assertResponseIsSuccessful();
        
        // Assert the comparison link is present
        $link = $crawler->selectLink('Compare all plans & core features in detail');
        $this->assertCount(1, $link);
        $this->assertEquals('/plans/compare', $link->attr('href'));
        $this->assertEquals('_blank', $link->attr('target'));
    }
}
