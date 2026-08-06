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
        $this->assertSelectorExists('#captcha_answer');
        $this->assertSelectorExists('img[alt*="Math Captcha"]');
    }

    public function testLoginPageRendersMathCaptcha(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#captcha_answer');
        $this->assertSelectorExists('img[alt*="Math Captcha"]');
    }

    public function testLoginCaptchaValidationFailure(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        // Submit form with invalid captcha answer
        $form = $crawler->selectButton('Sign In')->form([
            '_username' => 'manager@acme.com',
            '_password' => 'password123',
            'captcha_answer' => '9999'
        ]);
        
        $client->submit($form);
        $this->assertResponseRedirects('http://localhost/login');
        
        $client->followRedirect();
        $this->assertSelectorExists('.alert-danger');
        $this->assertSelectorTextContains('.alert-danger', 'Incorrect math captcha answer');
    }
}
