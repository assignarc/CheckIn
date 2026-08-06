<?php

namespace App\Tests\Validator;

use App\Entity\Category;
use App\Entity\ContactMessage;
use App\Entity\Manager;
use App\Entity\OrgUser;
use App\Entity\Organization;
use App\Entity\Plan;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class EntityValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    public function testOrgUserValidationSuccess(): void
    {
        $org = new Organization();
        $orgUser = new OrgUser();
        $orgUser->setOrganization($org);
        $orgUser->setFirstName('John');
        $orgUser->setLastName('Doe');
        $orgUser->setEmail('john.doe@example.com');
        $orgUser->setPin('1234');
        $orgUser->setPinHash(password_hash('1234', PASSWORD_DEFAULT));

        $violations = $this->validator->validate($orgUser);
        $this->assertCount(0, $violations);
    }

    public function testOrgUserValidationInvalidEmail(): void
    {
        $org = new Organization();
        $orgUser = new OrgUser();
        $orgUser->setOrganization($org);
        $orgUser->setFirstName('John');
        $orgUser->setLastName('Doe');
        $orgUser->setEmail('not-an-email');
        $orgUser->setPinHash(password_hash('1234', PASSWORD_DEFAULT));

        $violations = $this->validator->validate($orgUser);
        $this->assertGreaterThan(0, count($violations));
    }

    public function testManagerValidationSuccess(): void
    {
        $manager = new Manager();
        $manager->setFirstName('Jane');
        $manager->setLastName('Smith');
        $manager->setEmail('jane.smith@example.com');
        $manager->setRole('ROLE_ORG_MANAGER');
        $manager->setPasswordHash('hashed_password');

        $violations = $this->validator->validate($manager);
        $this->assertCount(0, $violations);
    }

    public function testContactMessageValidationSuccess(): void
    {
        $msg = new ContactMessage();
        $msg->setName('Alice');
        $msg->setEmail('alice@example.com');
        $msg->setSubject('Inquiry');
        $msg->setMessage('Hello, I would like more information about your plans.');

        $violations = $this->validator->validate($msg);
        $this->assertCount(0, $violations);
    }
}
