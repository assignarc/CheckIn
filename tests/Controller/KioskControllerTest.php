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
}
