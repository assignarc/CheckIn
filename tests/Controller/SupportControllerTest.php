<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SupportControllerTest extends WebTestCase
{
    public function testSupportPagesArePubliclyAccessible(): void
    {
        $client = static::createClient();

        $urls = [
            '/support',
            '/support/kiosk',
            '/support/users',
            '/support/reports',
            '/support/audit-settings',
        ];

        foreach ($urls as $url) {
            $client->request('GET', $url);
            $this->assertResponseIsSuccessful(sprintf('Failed to load support page: %s', $url));
        }
    }
}
