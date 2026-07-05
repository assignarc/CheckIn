<?php

namespace App\MessageHandler;

use App\Entity\Organization;
use App\Message\RotateKioskTokenMessage;
use App\Service\AppConfigService;
use App\Service\SystemAuditLogger;
use App\Service\TokenGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RotateKioskTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private TokenGeneratorService $tokenGenerator,
        private AppConfigService $configService,
        private SystemAuditLogger $auditLogger
    ) {
    }

    public function __invoke(RotateKioskTokenMessage $message)
    {
        $org = $this->em->getRepository(Organization::class)->find($message->getOrgId());
        
        if (!$org) {
            return;
        }

        $newToken = $this->tokenGenerator->generateHumanReadableToken();
        $org->setCheckInLinkToken($newToken);
        
        // Save to config if it's a known demo org (for the public access page)
        if ($org->getSlug() === 'basic-demo') {
            $this->configService->set('demo_basic_token', $newToken);
        } elseif ($org->getSlug() === 'golden-demo') {
            $this->configService->set('demo_golden_token', $newToken);
        }

        $this->em->flush();

        $this->auditLogger->log('ROTATE_KIOSK_TOKEN', [
            'org_id' => $message->getOrgId(),
            'org_name' => $message->getOrgName(),
            'status' => 'success'
        ]);
    }
}
