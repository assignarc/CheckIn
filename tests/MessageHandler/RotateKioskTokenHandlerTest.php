<?php

namespace App\Tests\MessageHandler;

use App\Entity\Organization;
use App\Message\RotateKioskTokenMessage;
use App\MessageHandler\RotateKioskTokenHandler;
use App\Service\AppConfigService;
use App\Service\SystemAuditLogger;
use App\Service\TokenGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class RotateKioskTokenHandlerTest extends TestCase
{
    public function testRotateKioskToken(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $tokenGenerator = $this->createMock(TokenGeneratorService::class);
        $configService = $this->createMock(AppConfigService::class);
        $auditLogger = $this->createMock(SystemAuditLogger::class);

        $org = new Organization();
        $org->setKioskTokenAutoRotate(true);
        $org->setSlug('basic-demo');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->with(1)->willReturn($org);

        $em->method('getRepository')->with(Organization::class)->willReturn($repo);

        $tokenGenerator->method('generateHumanReadableToken')->willReturn('fresh-blue-fox-123');

        $configService->expects($this->once())
            ->method('set')
            ->with('demo_basic_token', 'fresh-blue-fox-123');

        $handler = new RotateKioskTokenHandler($em, $tokenGenerator, $configService, $auditLogger);
        $handler(new RotateKioskTokenMessage(1, 'Basic Demo'));

        $this->assertEquals('fresh-blue-fox-123', $org->getCheckInLinkToken());
    }
}
