<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\OrgUser;
use App\Entity\Plan;
use App\Entity\WorkSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserManagementAndDashboardTest extends WebTestCase
{
    public function testCreateActivateDeactivateResetPinAndEditUser(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        // 1. Create a new user
        $client->request('POST', '/manager/users/add', [
            'first_name' => 'TestStudent',
            'last_name' => 'Automation',
            'email' => 'test.automation@acme.com',
            'phone' => '+15559990000',
            'pin' => 'B999'
        ]);

        $this->assertResponseRedirects('/manager/users');
        $client->followRedirect();

        $em->clear();
        $user = $em->getRepository(OrgUser::class)->findOneBy(['email' => 'test.automation@acme.com']);
        $this->assertNotNull($user);
        $this->assertEquals('B999', $user->getPin());
        $this->assertTrue($user->isActive());

        // 2. Edit user name
        $client->request('POST', '/manager/users/edit/' . $user->getId(), [
            'first_name' => 'TestStudentEdited',
            'last_name' => 'AutomationEdited',
            'email' => 'test.automation@acme.com',
            'phone' => '+15559990000',
            'pin' => 'B999'
        ]);
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $editedUser = $em->getRepository(OrgUser::class)->find($user->getId());
        $this->assertEquals('TestStudentEdited', $editedUser->getFirstName());

        // 3. Reset user PIN
        $oldPinHash = $editedUser->getPinHash();
        $client->request('POST', '/manager/users/reset-pin/' . $user->getId());
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $pinResetUser = $em->getRepository(OrgUser::class)->find($user->getId());
        $this->assertNotEquals($oldPinHash, $pinResetUser->getPinHash());

        // 4. Deactivate user (Toggle)
        $client->request('POST', '/manager/users/toggle/' . $user->getId());
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $inactiveUser = $em->getRepository(OrgUser::class)->find($user->getId());
        $this->assertFalse($inactiveUser->isActive());

        // 5. Reactivate user (Toggle back)
        $client->request('POST', '/manager/users/toggle/' . $user->getId());
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $reactivatedUser = $em->getRepository(OrgUser::class)->find($user->getId());
        $this->assertTrue($reactivatedUser->isActive());

        // Cleanup
        $em->remove($reactivatedUser);
        $em->flush();
    }

    public function testInstructorManagementAddEditToggle(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);
        $em->getConnection()->executeStatement("DELETE FROM managers WHERE email = 'instructor.john@acme.com'");

        // 1. Add new instructor / manager
        $client->request('POST', '/manager/users/add-manager', [
            'first_name' => 'InstructorJohn',
            'last_name' => 'InstructorDoe',
            'email' => 'instructor.john@acme.com',
            'phone' => '+15557778888',
            'password' => 'securepass123'
        ]);

        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $newInstructor = $em->getRepository(Manager::class)->findOneBy(['email' => 'instructor.john@acme.com']);
        $this->assertNotNull($newInstructor);

        // 2. Edit instructor
        $client->request('POST', '/manager/users/edit-manager/' . $newInstructor->getId(), [
            'first_name' => 'InstructorJohnEdited',
            'last_name' => 'InstructorDoe',
            'email' => 'instructor.john@acme.com',
            'phone' => '+15557778888'
        ]);

        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $editedInstructor = $em->getRepository(Manager::class)->find($newInstructor->getId());
        $this->assertEquals('InstructorJohnEdited', $editedInstructor->getFirstName());

        // Cleanup
        $em->remove($editedInstructor);
        $em->flush();
    }

    public function testCheckedInUsersPageAndForceCheckout(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);
        $em->getConnection()->executeStatement("DELETE FROM managers WHERE email = 'instructor.john@acme.com'");

        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $manager->getOrganization()]);

        // Create active work session
        $session = new WorkSession();
        $session->setOrgUser($orgUser);
        $session->setOrganization($manager->getOrganization());
        $session->setStartedAt(new \DateTime('-20 minutes'));
        $session->setStartMethod('secure');
        $em->persist($session);
        $em->flush();

        // Request checked-in users page
        $client->request('GET', '/manager/users/checked-in');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', $orgUser->getFirstName());

        // Force check-out session from reports force end endpoint
        $client->request('POST', '/manager/reports/force-end/' . $session->getId());
        $this->assertResponseRedirects();

        $em->clear();
        $endedSession = $em->getRepository(WorkSession::class)->find($session->getId());
        $this->assertNotNull($endedSession->getEndedAt());
        $this->assertEquals('Force End', $endedSession->getEndMethod());

        // Cleanup
        $em->remove($endedSession);
        $em->flush();
    }

    public function testAdminAndManagerDashboards(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // 1. Manager Dashboard
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);
        $em->getConnection()->executeStatement("DELETE FROM managers WHERE email = 'instructor.john@acme.com'");

        $client->request('GET', '/manager/dashboard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.dashboard-header');
        $this->assertSelectorExists('.metric-card');

        // 2. Admin Dashboard
        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $client->loginUser($admin);

        $client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.dashboard-header');
        $this->assertSelectorTextContains('body', 'Platform Overview');
    }

    public function testMergeUsersCapability(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);
        $org = $manager->getOrganization();
        $em->getConnection()->executeStatement("DELETE FROM org_users WHERE pin IN ('A881', 'B882')");

        $sourceUser = new OrgUser();
        $sourceUser->setOrganization($org);
        $sourceUser->setFirstName('MergeSource');
        $sourceUser->setLastName('Duplicate');
        $sourceUser->setEmail('source.dup@acme.com');
        $sourceUser->setPhone('+15550001111');
        $sourceUser->setPin('A881');
        $sourceUser->setPinHash(password_hash('A881', PASSWORD_BCRYPT));
        $sourceUser->setCustomFieldsJson(['Department' => 'TempLab']);
        $sourceUser->addNote('Initial source note 1', 'Admin', 'manager');
        $sourceUser->setIsActive(true);
        $em->persist($sourceUser);

        $targetUser = new OrgUser();
        $targetUser->setOrganization($org);
        $targetUser->setFirstName('MergeTarget');
        $targetUser->setLastName('Primary');
        $targetUser->setEmail('target.primary@acme.com');
        $targetUser->setPhone('+15550002222');
        $targetUser->setPin('B882');
        $targetUser->setPinHash(password_hash('B882', PASSWORD_BCRYPT));
        $targetUser->setCustomFieldsJson(['Department' => 'MainLab']);
        $targetUser->addNote('Initial target note 1', 'Admin', 'manager');
        $targetUser->setIsActive(true);
        $em->persist($targetUser);

        // Create 2 timesheet sessions for sourceUser and 1 for targetUser
        $s1 = new WorkSession();
        $s1->setOrganization($org);
        $s1->setOrgUser($sourceUser);
        $s1->setStartedAt(new \DateTime('-3 hours'));
        $s1->setEndedAt(new \DateTime('-2 hours'));
        $s1->setDurationSeconds(3600);
        $s1->setStartMethod('kiosk');
        $s1->setEndMethod('kiosk');
        $em->persist($s1);

        $s2 = new WorkSession();
        $s2->setOrganization($org);
        $s2->setOrgUser($sourceUser);
        $s2->setStartedAt(new \DateTime('-45 minutes'));
        $s2->setStartMethod('kiosk');
        $em->persist($s2);

        $t1 = new WorkSession();
        $t1->setOrganization($org);
        $t1->setOrgUser($targetUser);
        $t1->setStartedAt(new \DateTime('-5 hours'));
        $t1->setEndedAt(new \DateTime('-4 hours'));
        $t1->setDurationSeconds(3600);
        $t1->setStartMethod('kiosk');
        $t1->setEndMethod('kiosk');
        $em->persist($t1);

        $em->flush();

        $sourceId = $sourceUser->getId();
        $targetId = $targetUser->getId();

        // 0. Add a second note to targetUser via add-note route and verify older note is retained
        $client->request('POST', "/manager/users/add-note/{$targetId}", [
            'note' => 'Second target note from manager',
        ]);
        $this->assertResponseRedirects('/manager/users');

        // Verify Notes column on /manager/users renders collapsed <details>
        $usersCrawler = $client->request('GET', '/manager/users');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('details.user-notes-details');
        $this->assertNull($usersCrawler->filter('details.user-notes-details')->first()->attr('open'));

        // 1. Render Merge Users screen with preselected source & target
        $crawler = $client->request('GET', "/manager/users/merge?source_id={$sourceId}&target_id={$targetId}");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#source-card-name', 'MergeSource Duplicate');
        $this->assertSelectorTextContains('#target-card-name', 'MergeTarget Primary');
        $this->assertSelectorTextContains('#source-sessions-badge', '2 items');
        $this->assertSelectorTextContains('#target-sessions-badge', '1 item');
        $this->assertSelectorTextContains('#preview-timesheet-equation', '1 existing + 2 moved from MergeSource Duplicate = 3 total timesheet item(s)');
        // Verify profile notes disclosures start collapsed and contain existing notes
        $this->assertNull($crawler->filter('#source-notes-details')->attr('open'));
        $this->assertNull($crawler->filter('#target-notes-details')->attr('open'));
        $this->assertSelectorTextContains('#source-notes-summary', 'View Notes (1)');
        $this->assertSelectorTextContains('#target-notes-summary', 'View Notes (2)');

        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        // 2. Reject merge when only 1 of 2 warnings is accepted
        $client->request('POST', '/manager/users/merge', [
            '_token' => $csrfToken,
            'source_user_id' => $sourceId,
            'target_user_id' => $targetId,
            'confirm_warning_1' => '1',
        ]);
        $this->assertResponseRedirects("/manager/users/merge?source_id={$sourceId}&target_id={$targetId}");

        // 3. Execute merge with both warnings accepted
        $client->request('POST', '/manager/users/merge', [
            '_token' => $csrfToken,
            'source_user_id' => $sourceId,
            'target_user_id' => $targetId,
            'confirm_warning_1' => '1',
            'confirm_warning_2' => '1',
        ]);
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $reloadedSource = $em->getRepository(OrgUser::class)->find($sourceId);
        $reloadedTarget = $em->getRepository(OrgUser::class)->find($targetId);

        // Source user marked inactive, last name appended with " - MERGED" only once
        $this->assertFalse($reloadedSource->isActive());
        $this->assertTrue($reloadedSource->isMerged());
        $this->assertSame('MergeSource', $reloadedSource->getFirstName());
        $this->assertSame('Duplicate - MERGED', $reloadedSource->getLastName());
        // Calling markAsMerged() again must not append " - MERGED" a second time
        $reloadedSource->markAsMerged();
        $this->assertSame('Duplicate - MERGED', $reloadedSource->getLastName());

        // Target user profile fields unchanged
        $this->assertTrue($reloadedTarget->isActive());
        $this->assertSame('MergeTarget', $reloadedTarget->getFirstName());
        $this->assertSame('Primary', $reloadedTarget->getLastName());
        $this->assertSame('target.primary@acme.com', $reloadedTarget->getEmail());
        $this->assertSame(['Department' => 'MainLab'], $reloadedTarget->getCustomFieldsJson());

        // Older notes retained + System note appended to both users
        $sourceNotes = $reloadedSource->getNotes();
        $targetNotes = $reloadedTarget->getNotes();
        $this->assertCount(2, $sourceNotes);
        $this->assertSame('Initial source note 1', $sourceNotes[0]['text']);
        $this->assertSame('system', $sourceNotes[1]['type']);
        $this->assertStringContainsString('Merged FROM MergeSource Duplicate', $sourceNotes[1]['text']);

        $this->assertCount(3, $targetNotes);
        $this->assertSame('Initial target note 1', $targetNotes[0]['text']);
        $this->assertSame('Second target note from manager', $targetNotes[1]['text']);
        $this->assertSame('system', $targetNotes[2]['type']);
        $this->assertStringContainsString('INTO MergeTarget Primary', $targetNotes[2]['text']);

        // Timesheet records moved from source -> target
        $sourceSessionsAfter = $em->getRepository(WorkSession::class)->count(['orgUser' => $reloadedSource]);
        $targetSessionsAfter = $em->getRepository(WorkSession::class)->count(['orgUser' => $reloadedTarget]);
        $this->assertSame(0, $sourceSessionsAfter);
        $this->assertSame(3, $targetSessionsAfter);

        // Verify SYSTEM_USER_MERGE and USER_MERGE audit log events are recorded and visible in /manager/audit-logs
        $auditCrawler = $client->request('GET', '/manager/audit-logs');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'SYSTEM_USER_MERGE');
        $this->assertSelectorTextContains('table', 'USER_MERGE');
        $this->assertSelectorTextContains('table', 'Merged MergeSource Duplicate');

        // 4. Already merged user cannot be selected for future merges
        $client->request('POST', '/manager/users/merge', [
            '_token' => $csrfToken,
            'source_user_id' => $sourceId,
            'target_user_id' => $targetId,
            'confirm_warning_1' => '1',
            'confirm_warning_2' => '1',
        ]);
        $this->assertResponseRedirects('/manager/users/merge');

        // Cleanup test records
        $em->getConnection()->executeStatement("DELETE FROM work_sessions WHERE org_user_id IN ({$sourceId}, {$targetId})");
        $em->getConnection()->executeStatement("DELETE FROM org_users WHERE id IN ({$sourceId}, {$targetId})");
    }

    public function testUniqueIdCreateEditAndDuplicateNegativeValidation(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $userMatchingService = $container->get(\App\Service\UserMatchingService::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);
        $org = $manager->getOrganization();

        $em->getConnection()->executeStatement("DELETE FROM org_users WHERE pin IN ('A501', 'B502', 'C503')");
        $em->getConnection()->executeStatement("DELETE FROM org_users WHERE email IN ('uid.user1@acme.com', 'uid.user2@acme.com', 'uid.legacy@acme.com')");

        // 1. Positive: Add user with unique_id -> stores both plaintext uniqueId and encrypted uniqueIdHash
        $client->request('POST', '/manager/users/add', [
            'first_name' => 'UniqueOne',
            'last_name' => 'Tester',
            'email' => 'uid.user1@acme.com',
            'phone' => '+15554440001',
            'pin' => 'A501',
            'unique_id' => 'BADGE-5001',
        ]);
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $user1 = $em->getRepository(OrgUser::class)->findOneBy(['email' => 'uid.user1@acme.com']);
        $this->assertNotNull($user1);
        $this->assertSame('BADGE-5001', $user1->getUniqueId());
        $this->assertSame($userMatchingService->generateHash('BADGE-5001'), $user1->getUniqueIdHash());

        // 2. Positive: Edit user's unique_id -> updates both uniqueId and uniqueIdHash and appends a system note
        $client->request('POST', '/manager/users/edit/' . $user1->getId(), [
            'first_name' => 'UniqueOne',
            'last_name' => 'Tester',
            'email' => 'uid.user1@acme.com',
            'phone' => '+15554440001',
            'pin' => 'A501',
            'unique_id' => 'BADGE-5002',
        ]);
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $user1Updated = $em->getRepository(OrgUser::class)->find($user1->getId());
        $this->assertSame('BADGE-5002', $user1Updated->getUniqueId());
        $this->assertSame($userMatchingService->generateHash('BADGE-5002'), $user1Updated->getUniqueIdHash());
        $notes = $user1Updated->getNotes();
        $this->assertNotEmpty($notes);
        $this->assertStringContainsString('Unique ID updated from "BADGE-5001" to "BADGE-5002"', $notes[count($notes) - 1]['text']);

        // 3. Negative: Attempt to add a second user with a duplicate plaintext unique_id ('BADGE-5002')
        $client->request('POST', '/manager/users/add', [
            'first_name' => 'DuplicateAttempt',
            'last_name' => 'Tester',
            'email' => 'uid.user2@acme.com',
            'phone' => '+15554440002',
            'pin' => 'B502',
            'unique_id' => 'BADGE-5002',
        ]);
        $this->assertResponseRedirects('/manager/users');
        $em->clear();
        $dupUser = $em->getRepository(OrgUser::class)->findOneBy(['email' => 'uid.user2@acme.com']);
        $this->assertNull($dupUser, 'User with duplicate Unique ID in the same organization must not be created.');

        // 4. Negative: Create a legacy user with only encrypted uniqueIdHash (plaintext uniqueId is NULL)
        // and verify editing user1 to that legacy Unique ID is rejected due to hash collision
        $managedOrg = $em->getRepository(Organization::class)->find($org->getId());
        $legacyUser = new OrgUser();
        $legacyUser->setOrganization($managedOrg);
        $legacyUser->setFirstName('LegacyHash');
        $legacyUser->setLastName('Only');
        $legacyUser->setEmail('uid.legacy@acme.com');
        $legacyUser->setPin('C503');
        $legacyUser->setPinHash(password_hash('C503', PASSWORD_BCRYPT));
        $legacyUser->setUniqueId(null);
        $legacyUser->setUniqueIdHash($userMatchingService->generateHash('LEGACY-777'));
        $legacyUser->setIsActive(true);
        $em->persist($legacyUser);
        $em->flush();

        $client->request('POST', '/manager/users/edit/' . $user1Updated->getId(), [
            'first_name' => 'UniqueOne',
            'last_name' => 'Tester',
            'email' => 'uid.user1@acme.com',
            'phone' => '+15554440001',
            'pin' => 'A501',
            'unique_id' => 'LEGACY-777',
        ]);
        $this->assertResponseRedirects('/manager/users');

        $em->clear();
        $user1AfterRejectedEdit = $em->getRepository(OrgUser::class)->find($user1Updated->getId());
        $this->assertSame('BADGE-5002', $user1AfterRejectedEdit->getUniqueId(), 'Unique ID must remain unchanged when edit collides with an existing encrypted Unique ID hash.');

        // 5. Negative: Attempt to merge a user into themselves
        $crawler = $client->request('GET', '/manager/users/merge');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/manager/users/merge', [
            '_token' => $csrfToken,
            'source_user_id' => $user1AfterRejectedEdit->getId(),
            'target_user_id' => $user1AfterRejectedEdit->getId(),
            'confirm_warning_1' => '1',
            'confirm_warning_2' => '1',
        ]);
        $this->assertResponseRedirects('/manager/users/merge?source_id=' . $user1AfterRejectedEdit->getId());
        $em->clear();
        $user1StillActive = $em->getRepository(OrgUser::class)->find($user1Updated->getId());
        $this->assertTrue($user1StillActive->isActive(), 'Self-merge must be rejected and leave user active.');
        $this->assertFalse($user1StillActive->isMerged());

        // Cleanup
        $em->getConnection()->executeStatement("DELETE FROM org_users WHERE email IN ('uid.user1@acme.com', 'uid.user2@acme.com', 'uid.legacy@acme.com')");
    }
}
