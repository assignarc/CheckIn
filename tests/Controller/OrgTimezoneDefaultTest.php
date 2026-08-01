<?php

namespace App\Tests\Controller;

use App\Entity\Organization;

use App\Service\AppConfigService;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OrgTimezoneDefaultTest extends WebTestCase
{
    public function testOrgTimezoneDefaultsToAdminTimezoneUntilManagerChangesIt(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $configService = $container->get(AppConfigService::class);

        // 1. Set global admin display timezone to 'America/Phoenix'
        $configService->set('display_timezone', 'America/Phoenix');

        $org = new Organization();
        $org->setName('Timezone Test Org');
        $org->setSlug('tz-test-org-' . uniqid());

        // 2. Verify new org timezone is null (un-customized)
        $this->assertNull($org->getTimezone());

        // Effective timezone falls back to admin configured display_timezone
        $effectiveTzInitial = $org->getTimezone() ?: $configService->get('display_timezone', 'America/Chicago');
        $this->assertEquals('America/Phoenix', $effectiveTzInitial);

        // 3. Admin changes display_timezone to 'Europe/London'
        $configService->set('display_timezone', 'Europe/London');
        $effectiveTzAfterAdminChange = $org->getTimezone() ?: $configService->get('display_timezone', 'America/Chicago');
        $this->assertEquals('Europe/London', $effectiveTzAfterAdminChange);

        // 4. Manager sets custom timezone to 'America/New_York'
        $org->setTimezone('America/New_York');
        $this->assertEquals('America/New_York', $org->getTimezone());

        $effectiveTzAfterManagerChange = $org->getTimezone() ?: $configService->get('display_timezone', 'America/Chicago');
        $this->assertEquals('America/New_York', $effectiveTzAfterManagerChange);

        // 5. Manager clears custom timezone back to Admin Default
        $org->setTimezone(null);
        $this->assertNull($org->getTimezone());
        $effectiveTzReverted = $org->getTimezone() ?: $configService->get('display_timezone', 'America/Chicago');
        $this->assertEquals('Europe/London', $effectiveTzReverted);
    }
}
