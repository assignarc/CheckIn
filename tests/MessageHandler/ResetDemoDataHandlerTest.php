<?php

namespace App\Tests\MessageHandler;

use App\Message\ResetDemoDataMessage;
use App\MessageHandler\ResetDemoDataHandler;
use App\Entity\Organization;
use App\Entity\OrgUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ResetDemoDataHandlerTest extends KernelTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private ?ResetDemoDataHandler $handler = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->handler = $container->get(ResetDemoDataHandler::class);
    }

    public function testHandlerExecutesSuccessfullyAndPopulatesDemoTenants(): void
    {
        // Execute the handler
        $message = new ResetDemoDataMessage();
        ($this->handler)($message);

        // Check if Golden Demo Company exists
        $gOrg = $this->entityManager->getRepository(Organization::class)->findOneBy(['slug' => 'golden-demo']);
        $this->assertNotNull($gOrg);
        $this->assertEquals('Golden Demo Company', $gOrg->getName());

        // Check that at least some users are allocated to Golden Demo Company
        $users = $this->entityManager->getRepository(OrgUser::class)->findBy(['organization' => $gOrg]);
        $this->assertNotEmpty($users);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager = null;
        $this->handler = null;
    }
}
