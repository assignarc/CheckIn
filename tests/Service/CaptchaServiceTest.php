<?php

namespace App\Tests\Service;

use App\Service\AppConfigService;
use App\Service\CaptchaService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class CaptchaServiceTest extends TestCase
{
    private CaptchaService $service;
    private AppConfigService $configService;

    protected function setUp(): void
    {
        $this->configService = $this->createMock(AppConfigService::class);
        $this->service = new CaptchaService($this->configService);
    }

    public function testIsEnabledForSignupAlwaysTrue(): void
    {
        $this->assertTrue($this->service->isEnabled('signup'));
    }

    public function testIsEnabledReadsConfig(): void
    {
        $this->configService->method('get')
            ->with('captcha_login_enabled', false)
            ->willReturn(true);

        $this->assertTrue($this->service->isEnabled('login'));
    }

    public function testGenerateQuestionAndValidate(): void
    {
        $request = new Request();
        $session = $this->createMock(SessionInterface::class);
        
        $storedValue = null;
        $session->method('set')
            ->willReturnCallback(function ($key, $val) use (&$storedValue) {
                $storedValue = $val;
            });

        $session->method('get')
            ->willReturnCallback(function () use (&$storedValue) {
                return $storedValue;
            });

        $request->setSession($session);

        $question = $this->service->generateQuestion($request, 'login');
        $this->assertNotEmpty($question);
        $this->assertNotNull($storedValue);

        // Disabling captcha context so validate passes automatically
        $this->configService->method('get')->willReturn(false);
        $this->assertTrue($this->service->validate($request, 'login', '123'));
    }
}
