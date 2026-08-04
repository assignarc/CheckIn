<?php

namespace App\Tests\Service;

use App\Service\TokenGeneratorService;
use PHPUnit\Framework\TestCase;

class TokenGeneratorServiceTest extends TestCase
{
    public function testGenerateHumanReadableToken(): void
    {
        $service = new TokenGeneratorService();
        $token = $service->generateHumanReadableToken();

        $this->assertNotEmpty($token);
        // Should match pattern adj-color-noun-number
        $this->assertMatchesRegularExpression('/^[a-z]+-[a-z]+-[a-z]+-\d+$/', $token);
    }
}
