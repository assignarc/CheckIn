<?php

namespace App\Tests\Service;

use App\Entity\Organization;
use App\Entity\OrgUser;
use App\Service\UserMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class UserMatchingServiceTest extends TestCase
{
    private UserMatchingService $service;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $params = $this->createMock(ParameterBagInterface::class);
        $params->method('get')->with('import_hash_salt')->willReturn('testsalt');

        $this->service = new UserMatchingService($em, $params);
    }

    public function testFindMatchAndHashWithUniqueId(): void
    {
        $org = new Organization();
        $user1 = (new OrgUser())->setFirstName('John')->setLastName('Doe');
        
        $hash = $this->service->generateHash('EMP123');

        $empMapByHash = [$hash => $user1];
        
        $result = $this->service->findMatchAndHash(
            $org, 'EMP123', 'John', 'Doe', '', '', $empMapByHash, [], []
        );

        $this->assertSame($user1, $result['matchedUser']);
        $this->assertSame('update', $result['matchType']);
        $this->assertSame($hash, $result['uniqueHash']);
    }

    public function testFindMatchAndHashWithEmail(): void
    {
        $org = new Organization();
        $user1 = (new OrgUser())->setFirstName('Jane')->setLastName('Smith');
        
        $empMapByEmail = ['jane@example.com' => [$user1]];
        
        $result = $this->service->findMatchAndHash(
            $org, '', 'Jane', 'Smith', 'jane@example.com', '', [], $empMapByEmail, []
        );

        $this->assertSame($user1, $result['matchedUser']);
        $this->assertSame('update', $result['matchType']);
        // unique hash is base name
        $expectedHash = $this->service->generateHash('jane-smith');
        $this->assertSame($expectedHash, $result['uniqueHash']);
    }

    public function testFindMatchAndHashNoMatchReturnsNew(): void
    {
        $org = new Organization();
        
        $result = $this->service->findMatchAndHash(
            $org, '', 'Bob', 'New', '', '', [], [], []
        );

        $this->assertNull($result['matchedUser']);
        $this->assertSame('new', $result['matchType']);
    }
}
