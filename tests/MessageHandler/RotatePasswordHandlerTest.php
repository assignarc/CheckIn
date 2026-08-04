<?php

namespace App\Tests\MessageHandler;

use App\Entity\Manager;
use App\Entity\Organization;
use App\Message\RotatePasswordMessage;
use App\MessageHandler\RotatePasswordHandler;
use App\Service\AppConfigService;
use App\Service\SystemAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RotatePasswordHandlerTest extends TestCase
{
    public function testRotatePassword(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $configService = $this->createMock(AppConfigService::class);
        $auditLogger = $this->createMock(SystemAuditLogger::class);

        $org = new Organization();
        $org->setSlug('basic-demo');

        $manager = new Manager();
        $manager->setOrganization($org);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->with(5)->willReturn($manager);

        $em->method('getRepository')->with(Manager::class)->willReturn($repo);

        $passwordHasher->method('hashPassword')->willReturn('hashed_password_123');

        $handler = new RotatePasswordHandler($em, $passwordHasher, $configService, $auditLogger);
        $handler(new RotatePasswordMessage(1, 5));

        $this->assertEquals('hashed_password_123', $manager->getPasswordHash());
    }
}
