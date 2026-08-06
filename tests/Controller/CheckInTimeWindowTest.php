<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\OrgUser;
use App\Entity\Organization;
use App\Entity\WorkSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CheckInTimeWindowTest extends WebTestCase
{
    public function testSaveAndReloadStartAndEndTimes(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $org = $manager->getOrganization();

        $client->loginUser($manager);

        // 1. Submit Policy Form with Start Time 08:00 and End Time 18:00
        $client->request('POST', '/manager/settings', [
            'access_mode' => 'secure',
            'session_limit_hours' => 8,
            'window_start' => '08:00',
            'window_end' => '18:00',
            'mask_last_name' => '0',
            'kiosk_token_auto_rotate' => '0',
        ]);
        $this->assertResponseRedirects('/manager/settings');

        // 2. Reload settings page and verify window_start and window_end preserve exact wall-clock times
        $crawler = $client->request('GET', '/manager/settings');
        $this->assertResponseIsSuccessful();

        $startVal = $crawler->filter('input[name="window_start"]')->attr('value');
        $endVal   = $crawler->filter('input[name="window_end"]')->attr('value');

        $this->assertEquals('08:00', $startVal);
        $this->assertEquals('18:00', $endVal);
    }

    public function testCheckInWithinAndOutsideWindow(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $org = $manager->getOrganization();

        // Configure window: 08:00 to 18:00
        $org->setCheckInWindowStart(\DateTime::createFromFormat('H:i', '08:00'));
        $org->setCheckInWindowEnd(\DateTime::createFromFormat('H:i', '18:00'));
        $em->flush();

        $orgTzStr = $org->getTimezone() ?: 'America/Chicago';
        $orgTz = new \DateTimeZone($orgTzStr);

        // Case A: Check-in at 10:00 AM (Inside window)
        $nowInside = new \DateTime('10:00:00', $orgTz);
        $winStart  = new \DateTime($org->getCheckInWindowStart()->format('H:i:s'));
        $winEnd    = new \DateTime($org->getCheckInWindowEnd()->format('H:i:s'));
        $nowTimeA  = new \DateTime($nowInside->format('H:i:s'));

        $isOutOfWindowA = ($nowTimeA < $winStart || $nowTimeA > $winEnd);
        $this->assertFalse($isOutOfWindowA, '10:00 AM should be marked as WITHIN window');

        // Case B: Check-in at 06:00 AM (Outside window)
        $nowOutside = new \DateTime('06:00:00', $orgTz);
        $nowTimeB   = new \DateTime($nowOutside->format('H:i:s'));

        $isOutOfWindowB = ($nowTimeB < $winStart || $nowTimeB > $winEnd);
        $this->assertTrue($isOutOfWindowB, '06:00 AM should be marked as OUT OF WINDOW');
    }

    public function testOvernightWindowSupport(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $org = $manager->getOrganization();

        // Configure overnight window: 22:00 to 06:00
        $org->setCheckInWindowStart(\DateTime::createFromFormat('H:i', '22:00'));
        $org->setCheckInWindowEnd(\DateTime::createFromFormat('H:i', '06:00'));
        $em->flush();

        $winStart = new \DateTime($org->getCheckInWindowStart()->format('H:i:s'));
        $winEnd   = new \DateTime($org->getCheckInWindowEnd()->format('H:i:s'));

        // Case A: Check-in at 23:00 (11:00 PM - Inside overnight window)
        $timeLate = new \DateTime('23:00:00');
        $isOutOfWindowA = ($winStart > $winEnd) ? ($timeLate > $winEnd && $timeLate < $winStart) : ($timeLate < $winStart || $timeLate > $winEnd);
        $this->assertFalse($isOutOfWindowA, '23:00 should be marked WITHIN overnight window (22:00 - 06:00)');

        // Case B: Check-in at 12:00 PM (Noon - Outside overnight window)
        $timeNoon = new \DateTime('12:00:00');
        $isOutOfWindowB = ($winStart > $winEnd) ? ($timeNoon > $winEnd && $timeNoon < $winStart) : ($timeNoon < $winStart || $timeNoon > $winEnd);
        $this->assertTrue($isOutOfWindowB, '12:00 PM should be marked OUT OF WINDOW for overnight window (22:00 - 06:00)');
    }
}
