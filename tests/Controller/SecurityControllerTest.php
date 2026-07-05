<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageIsAccessible(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Welcome Back');
    }

    public function testLoginWithInvalidCredentials(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        // Submit form with wrong credentials
        $form = $crawler->selectButton('Sign In')->form([
            '_username' => 'nonexistent@company.com',
            '_password' => 'wrongpassword',
        ]);
        
        $client->submit($form);
        $this->assertResponseRedirects('http://localhost/login');
        
        $client->followRedirect();
        $this->assertSelectorExists('.alert-danger');
    }
}
