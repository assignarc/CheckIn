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

    public function testFindMatchAndHashByPlaintextUniqueIdFirst(): void
    {
        $org = new Organization();
        $plainMatchedUser = (new OrgUser())->setFirstName('Alice')->setLastName('Plain')->setUniqueId('BADGE-100');
        $hashMatchedUser  = (new OrgUser())->setFirstName('Alice')->setLastName('HashOnly');

        $hash = $this->service->generateHash('BADGE-100');

        // Both maps provided: plaintext map MUST take priority #1 over encrypted hash map
        $empMapByPlainId = ['badge-100' => $plainMatchedUser];
        $empMapByHash    = [$hash => $hashMatchedUser];

        $result = $this->service->findMatchAndHash(
            $org,
            'BADGE-100',
            'DifferentFirst',
            'DifferentLast',
            '',
            '',
            $empMapByHash,
            [],
            [],
            $empMapByPlainId,
            []
        );

        $this->assertSame($plainMatchedUser, $result['matchedUser']);
        $this->assertSame('update', $result['matchType']);
        $this->assertSame($hash, $result['uniqueHash']);
    }

    public function testFindMatchAndHashWithEncryptedUniqueIdWhenPlaintextBlankInDb(): void
    {
        $org = new Organization();
        $legacyUser = (new OrgUser())->setFirstName('John')->setLastName('Doe');

        $hash = $this->service->generateHash('EMP123');
        $empMapByHash = [$hash => $legacyUser];

        // Plaintext map is empty (legacy DB row has null unique_id), so Priority #2 (encrypted hash) matches
        $result = $this->service->findMatchAndHash(
            $org, 'EMP123', 'John', 'Doe', '', '', $empMapByHash, [], [], [], []
        );

        $this->assertSame($legacyUser, $result['matchedUser']);
        $this->assertSame('update', $result['matchType']);
        $this->assertSame($hash, $result['uniqueHash']);
    }

    public function testFindMatchAndHashFallbackToFirstNameLastName(): void
    {
        $org = new Organization();
        $userByName = (new OrgUser())->setFirstName('Sarah')->setLastName('Connor');

        $empMapByName = ['sarah-connor' => $userByName];

        // Unique ID 'NEW-ID-999' is not in plain or hash maps, so Priority #3 matches by First + Last Name
        $result = $this->service->findMatchAndHash(
            $org,
            'NEW-ID-999',
            'Sarah',
            'Connor',
            '',
            '',
            [],
            [],
            [],
            [],
            $empMapByName
        );

        $this->assertSame($userByName, $result['matchedUser']);
        $this->assertSame('update', $result['matchType']);
        // Still computes the hash of the incoming unique ID so the caller can backfill both plain + encrypted ID
        $this->assertSame($this->service->generateHash('NEW-ID-999'), $result['uniqueHash']);
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

    public function testNegativeEmailMatchesButNameDiffersReturnsNew(): void
    {
        $org = new Organization();
        $existingUser = (new OrgUser())->setFirstName('Jane')->setLastName('Smith');
        $empMapByEmail = ['shared@example.com' => [$existingUser]];

        // Same email, but different first/last name -> must NOT match existing user
        $result = $this->service->findMatchAndHash(
            $org,
            '',
            'DifferentFirst',
            'DifferentLast',
            'shared@example.com',
            '',
            [],
            $empMapByEmail,
            [],
            [],
            ['jane|smith' => [$existingUser]]
        );

        $this->assertNull($result['matchedUser']);
        $this->assertSame('new', $result['matchType']);
    }

    public function testNegativePhoneMatchesButNameDiffersReturnsNew(): void
    {
        $org = new Organization();
        $existingUser = (new OrgUser())->setFirstName('Jane')->setLastName('Smith');
        $empMapByPhone = ['+15551234567' => [$existingUser]];

        // Same phone, but different first/last name -> must NOT match existing user
        $result = $this->service->findMatchAndHash(
            $org,
            '',
            'OtherPerson',
            'Smith',
            '',
            '555-123-4567',
            [],
            [],
            $empMapByPhone,
            [],
            ['jane|smith' => [$existingUser]]
        );

        $this->assertNull($result['matchedUser']);
        $this->assertSame('new', $result['matchType']);
    }

    public function testNegativeUnmatchedUniqueIdAndUnmatchedNameReturnsNew(): void
    {
        $org = new Organization();
        $existingUser = (new OrgUser())->setFirstName('Existing')->setLastName('User')->setUniqueId('UID-111');
        $existingHash = $this->service->generateHash('UID-111');

        $result = $this->service->findMatchAndHash(
            $org,
            'UID-999-UNKNOWN',
            'Completely',
            'Different',
            '',
            '',
            [$existingHash => $existingUser],
            [],
            [],
            ['uid-111' => $existingUser],
            ['existing|user' => [$existingUser]]
        );

        $this->assertNull($result['matchedUser']);
        $this->assertSame('new', $result['matchType']);
        $this->assertSame($this->service->generateHash('UID-999-UNKNOWN'), $result['uniqueHash']);
    }
}
