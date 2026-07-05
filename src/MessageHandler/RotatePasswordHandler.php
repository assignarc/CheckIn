<?php

namespace App\MessageHandler;

use App\Entity\Manager;
use App\Message\RotatePasswordMessage;
use App\Service\AppConfigService;
use App\Service\SystemAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
class RotatePasswordHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private AppConfigService $configService,
        private SystemAuditLogger $auditLogger
    ) {
    }

    public function __invoke(RotatePasswordMessage $message)
    {
        $manager = $this->em->getRepository(Manager::class)->find($message->getManagerId());
        
        if (!$manager) {
            return;
        }

        // Generate password
        $words = ['apple', 'tiger', 'river', 'stone', 'cloud', 'eagle', 'ocean', 'breeze', 'spark', 'flare', 'lion', 'bear'];
        $newPassword = sprintf('demo-%s-%s', $words[array_rand($words)], random_int(10, 99));

        $hashedPassword = $this->passwordHasher->hashPassword($manager, $newPassword);
        $manager->setPasswordHash($hashedPassword);
        
        // Save to config if it's a known demo org (for the public access page)
        $orgSlug = $manager->getOrganization()->getSlug();
        if ($orgSlug === 'basic-demo') {
            $this->configService->set('demo_basic_password', $newPassword);
        } elseif ($orgSlug === 'golden-demo') {
            $this->configService->set('demo_golden_password', $newPassword);
        }

        $this->em->flush();

        $this->auditLogger->log('ROTATE_PASSWORD', [
            'org_id' => $message->getOrgId(),
            'manager_id' => $message->getManagerId(),
            'status' => 'success'
        ]);
    }
}
