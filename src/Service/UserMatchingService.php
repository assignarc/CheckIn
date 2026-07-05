<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrgUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class UserMatchingService
{
    private string $salt;

    public function __construct(
        private EntityManagerInterface $em,
        ParameterBagInterface $params
    ) {
        $this->salt = $params->get('import_hash_salt');
    }

    /**
     * Finds the best matching user and generates their unique hash.
     * 
     * @return array{matchedUser: ?OrgUser, uniqueHash: string, matchType: string}
     */
    public function findMatchAndHash(
        Organization $org,
        string $uniqueVal,
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        array $empMapByHash,
        array $empMapByEmail,
        array $empMapByPhone
    ): array {
        $firstName = strtolower(trim($firstName));
        $lastName = strtolower(trim($lastName));
        $email = strtolower(trim($email));
        $phone = strtolower(trim($phone));
        $uniqueVal = trim($uniqueVal);

        $matchedOrgUser = null;
        $matchType = 'new';
        $uniqueHash = '';

        // Generate the unique hash based on provided data hierarchy
        if ($uniqueVal !== '') {
            $uniqueHash = $this->generateHash($uniqueVal);
        } else {
            // Base name fallback
            $uniqueHash = $this->generateHash($firstName . '-' . $lastName);
        }

        // 1. Explicit Unique ID (Highest precedence, safest)
        if ($uniqueHash !== '' && isset($empMapByHash[$uniqueHash])) {
            $matchedOrgUser = $empMapByHash[$uniqueHash];
            $matchType = 'update';
        }
        
        if (!$matchedOrgUser) {
            // We do a compound check. Email or Phone alone are not sufficient.
            
            // 2. Compound Name + Email
            if ($email !== '' && isset($empMapByEmail[$email])) {
                $candidates = $empMapByEmail[$email];
                foreach ($candidates as $candidate) {
                    if (strtolower($candidate->getFirstName()) === $firstName && 
                        strtolower($candidate->getLastName()) === $lastName) {
                        $matchedOrgUser = $candidate;
                        $matchType = 'update';
                        break;
                    }
                }
            }

            // 3. Compound Name + Phone
            if (!$matchedOrgUser && $phone !== '' && isset($empMapByPhone[$phone])) {
                $candidates = $empMapByPhone[$phone];
                foreach ($candidates as $candidate) {
                    if (strtolower($candidate->getFirstName()) === $firstName && 
                        strtolower($candidate->getLastName()) === $lastName) {
                        $matchedOrgUser = $candidate;
                        $matchType = 'update';
                        break;
                    }
                }
            }

            // 4. Base Name check (If unique column wasn't provided, the hash is the base name hash)
            if (!$matchedOrgUser && $uniqueVal === '' && isset($empMapByHash[$uniqueHash])) {
                $matchedOrgUser = $empMapByHash[$uniqueHash];
                $matchType = 'update';
            }
        }

        return [
            'matchedUser' => $matchedOrgUser,
            'uniqueHash' => $uniqueHash,
            'matchType' => $matchType
        ];
    }

    public function generateHash(string $identifier): string
    {
        return md5($this->salt . strtolower(trim($identifier)));
    }
}
