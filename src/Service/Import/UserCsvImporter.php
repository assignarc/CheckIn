<?php

namespace App\Service\Import;

use App\Entity\OrgUser;
use App\Entity\Organization;
use App\Service\PinService;
use App\Service\UserMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserCsvImporter
{
    private EntityManagerInterface $em;
    private PinService $pinService;
    private UserMatchingService $userMatchingService;
    private ValidatorInterface $validator;

    public function __construct(
        EntityManagerInterface $em,
        PinService $pinService,
        UserMatchingService $userMatchingService,
        ValidatorInterface $validator
    ) {
        $this->em = $em;
        $this->pinService = $pinService;
        $this->userMatchingService = $userMatchingService;
        $this->validator = $validator;
    }

    /**
     * Parses and processes CSV row data into OrgUser entities.
     * Returns summary of imported counts, skipped rows, and validation errors.
     */
    public function importCsvData(Organization $org, array $rows, array $fieldMapping): array
    {
        $importedCount = 0;
        $skippedCount = 0;
        $errors = [];

        $assignedPins = [];
        $existingUsers = $this->em->getRepository(OrgUser::class)->findBy(['organization' => $org, 'deletedAt' => null]);
        foreach ($existingUsers as $u) {
            if ($u->getPin()) {
                $assignedPins[] = $u->getPin();
            }
        }

        foreach ($rows as $index => $row) {
            $firstName = trim($row[$fieldMapping['first_name'] ?? 'first_name'] ?? '');
            $lastName = trim($row[$fieldMapping['last_name'] ?? 'last_name'] ?? '');
            $email = trim($row[$fieldMapping['email'] ?? 'email'] ?? '');
            $phone = trim($row[$fieldMapping['phone'] ?? 'phone'] ?? '');

            if (empty($firstName) || empty($lastName)) {
                $skippedCount++;
                $errors[] = sprintf('Row %d: Missing required first name or last name.', $index + 1);
                continue;
            }

            // User matching logic
            $orgUser = $this->userMatchingService->findOrCreateUser($org, $firstName, $lastName, $email, $phone);

            if (!$orgUser->getPin()) {
                $newPin = $this->pinService->generateUniquePin($assignedPins);
                $orgUser->setPin($newPin);
                $orgUser->setPinHash($this->pinService->hashPin($newPin));
                $assignedPins[] = $newPin;
            }

            $violations = $this->validator->validate($orgUser);
            if (count($violations) > 0) {
                $skippedCount++;
                $errorMsg = sprintf('Row %d (%s %s): %s', $index + 1, $firstName, $lastName, $violations[0]->getMessage());
                $errors[] = $errorMsg;
                continue;
            }

            $this->em->persist($orgUser);
            $importedCount++;
        }

        if ($importedCount > 0) {
            $this->em->flush();
        }

        return [
            'imported' => $importedCount,
            'skipped' => $skippedCount,
            'errors' => $errors,
        ];
    }
}
