<?php

namespace App\Tests\Service;

use App\Entity\Organization;
use App\Entity\OrgUser;
use App\Service\PinService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

class PinServiceTest extends TestCase
{
    private PinService $service;
    private EntityManagerInterface $em;
    private PasswordHasherInterface $hasher;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $hasherFactory = $this->createMock(PasswordHasherFactoryInterface::class);
        $this->hasher = $this->createMock(PasswordHasherInterface::class);
        
        $hasherFactory->method('getPasswordHasher')->willReturn($this->hasher);

        $params = $this->createMock(ParameterBagInterface::class);
        $params->method('get')->with('kernel.project_dir')->willReturn(sys_get_temp_dir() . '/pin_test_' . uniqid());

        $this->service = new PinService($this->em, $hasherFactory, $params);
    }

    public function testGetAllPossiblePins(): void
    {
        $pins = $this->service->getAllPossiblePins();
        $this->assertCount(3000, $pins);
        $this->assertContains('A000', $pins);
        $this->assertContains('C999', $pins);
    }

    public function testVerifyPin(): void
    {
        $orgUser = new OrgUser();
        $orgUser->setPinHash('hashed_1234');

        $this->hasher->expects($this->once())
            ->method('verify')
            ->with('hashed_1234', '1234')
            ->willReturn(true);

        $this->assertTrue($this->service->verifyPin($orgUser, '1234'));
    }

    public function testHashPinCachesResult(): void
    {
        $this->hasher->expects($this->once())
            ->method('hash')
            ->with('A123')
            ->willReturn('hashed_A123');

        $hash1 = $this->service->hashPin('A123');
        $hash2 = $this->service->hashPin('A123'); // Should use cache and not call hash again

        $this->assertSame('hashed_A123', $hash1);
        $this->assertSame('hashed_A123', $hash2);
    }
}
