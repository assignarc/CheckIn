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
     * Matching Precedence:
     * 1. Plaintext Unique ID (if $uniqueVal is provided and non-blank uniqueId exists in DB)
     * 2. Encrypted/Hashed Unique ID (matches hashed value of $uniqueVal against uniqueIdHash in DB)
     * 3. First Name + Last Name (Compound Name + Email, Compound Name + Phone, then First Name + Last Name)
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
        array $empMapByPhone,
        array $empMapByPlainId = [],
        array $empMapByName = []
    ): array {
        $firstName = strtolower(trim($firstName));
        $lastName = strtolower(trim($lastName));
        $email = strtolower(trim($email));
        $phone = strtolower(trim($phone));
        $uniqueVal = trim($uniqueVal);
        $normalizedUniqueVal = strtolower($uniqueVal);
        $nameKey = $firstName . '-' . $lastName;

        $matchedOrgUser = null;
        $matchType = 'new';
        $uniqueHash = '';

        // Generate the unique hash based on provided data hierarchy
        if ($uniqueVal !== '') {
            $uniqueHash = $this->generateHash($uniqueVal);
        } else {
            // Base name fallback
            $uniqueHash = $this->generateHash($nameKey);
        }

        // 1. Plaintext Unique ID (Highest precedence when non-blank in DB)
        if ($normalizedUniqueVal !== '' && isset($empMapByPlainId[$normalizedUniqueVal])) {
            $matchedOrgUser = $empMapByPlainId[$normalizedUniqueVal];
            $matchType = 'update';
        }

        // 2. Encrypted/Hashed Unique ID (Matches hashed value of ID in file against DB uniqueIdHash)
        if (!$matchedOrgUser && $uniqueVal !== '' && $uniqueHash !== '' && isset($empMapByHash[$uniqueHash])) {
            $matchedOrgUser = $empMapByHash[$uniqueHash];
            $matchType = 'update';
        }
        
        if (!$matchedOrgUser) {
            // 3. First Name + Last Name matching (Compound Email, Compound Phone, then Name fallback)
            
            // 3a. Compound Name + Email
            if ($email !== '' && isset($empMapByEmail[$email])) {
                $candidates = $empMapByEmail[$email];
                foreach ($candidates as $candidate) {
                    if (strtolower(trim($candidate->getFirstName())) === $firstName && 
                        strtolower(trim($candidate->getLastName())) === $lastName) {
                        $matchedOrgUser = $candidate;
                        $matchType = 'update';
                        break;
                    }
                }
            }

            // 3b. Compound Name + Phone
            if (!$matchedOrgUser && $phone !== '' && isset($empMapByPhone[$phone])) {
                $candidates = $empMapByPhone[$phone];
                foreach ($candidates as $candidate) {
                    if (strtolower(trim($candidate->getFirstName())) === $firstName && 
                        strtolower(trim($candidate->getLastName())) === $lastName) {
                        $matchedOrgUser = $candidate;
                        $matchType = 'update';
                        break;
                    }
                }
            }

            // 3c. First Name + Last Name direct map check
            if (!$matchedOrgUser && $firstName !== '' && $lastName !== '') {
                $altNameKey = $firstName . '|' . $lastName;
                $candidateEntry = $empMapByName[$nameKey] ?? ($empMapByName[$altNameKey] ?? null);
                if ($candidateEntry instanceof OrgUser) {
                    $matchedOrgUser = $candidateEntry;
                    $matchType = 'update';
                } elseif (is_array($candidateEntry) && isset($candidateEntry[0]) && $candidateEntry[0] instanceof OrgUser) {
                    $matchedOrgUser = $candidateEntry[0];
                    $matchType = 'update';
                }
            }

            // 3d. Base Name hash check (If unique column wasn't provided, the hash is the base name hash)
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
