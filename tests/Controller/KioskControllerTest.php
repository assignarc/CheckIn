<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class KioskControllerTest extends WebTestCase
{
    public function testKioskRequiresValidToken(): void
    {
        $client = static::createClient();
        
        // Invalid token test
        $client->request('GET', '/org/acme-corp/kiosk/wrong-token');
        $this->assertResponseIsSuccessful(); // Returns error view status 200
        $this->assertSelectorTextContains('h2', 'Access Link Expired');
    }

    public function testKioskLoadsWithValidToken(): void
    {
        $client = static::createClient();
        
        // Seeded Acme Corp Kiosk link
        $client->request('GET', '/org/acme-corp/kiosk/acme-secret-token');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Acme Corporation');
        $this->assertSelectorTextContains('h3', 'Identify Yourself');
    }

    public function testKioskDropdownStructureAndMultipleRosterSearch(): void
    {
        $client = static::createClient();
        
        $crawler = $client->request('GET', '/org/acme-corp/kiosk/acme-secret-token');
        $this->assertResponseIsSuccessful();

        // Verify search input container structure
        $this->assertCount(1, $crawler->filter('#orgUser-search'));
        
        // Verify autocomplete dropdown is placed inside the position-relative container
        $dropdownInRelativeContainer = $crawler->filter('.position-relative #orgUser-dropdown');
        $this->assertCount(1, $dropdownInRelativeContainer);

        // Verify JavaScript orgUsers roster array is rendered
        $responseContent = $client->getResponse()->getContent();
        $this->assertStringContainsString('const orgUsers = [', $responseContent);
        $this->assertStringContainsString('id:', $responseContent);
        $this->assertStringContainsString('name:', $responseContent);
    }
}
