<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrgUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

class PinService
{
    private EntityManagerInterface $em;
    private PasswordHasherInterface $hasher;
    private string $projectDir;

    public function __construct(
        EntityManagerInterface $em,
        PasswordHasherFactoryInterface $hasherFactory,
        ParameterBagInterface $params
    ) {
        $this->em = $em;
        $this->hasher = $hasherFactory->getPasswordHasher(OrgUser::class);
        $this->projectDir = $params->get('kernel.project_dir');
    }

    /**
     * Get all 3000 possible valid PINs (A000-C999).
     */
    public function getAllPossiblePins(): array
    {
        $pins = [];
        $prefixes = ['A', 'B', 'C'];
        foreach ($prefixes as $prefix) {
            for ($i = 0; $i < 1000; $i++) {
                $pins[] = $prefix . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            }
        }
        return $pins;
    }

    private ?array $cachedHashes = null;

    /**
     * Hash a PIN (utilizing the cache for fast lookups).
     * If the PIN hash is already cached in var/cache/pin_hashes.php, it loads it.
     * Otherwise, it hashes on-demand and writes back to the cache.
     */
    public function hashPin(string $pin): string
    {
        $pin = strtoupper(trim($pin));
        
        $cacheFile = $this->projectDir . '/var/cache/pin_hashes.php';
        if ($this->cachedHashes === null) {
            if (file_exists($cacheFile)) {
                $this->cachedHashes = require $cacheFile;
            } else {
                $this->cachedHashes = [];
            }
        }

        if (isset($this->cachedHashes[$pin])) {
            return $this->cachedHashes[$pin];
        }

        // Hash it on-demand (e.g. on first use of this PIN)
        $hash = $this->hasher->hash($pin);
        $this->cachedHashes[$pin] = $hash;

        // Save updated cache back to file
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($cacheFile, '<?php return ' . var_export($this->cachedHashes, true) . ';');

        return $hash;
    }

    /**
     * Verify if a plain text PIN matches the hash stored in OrgUser.
     */
    public function verifyPin(OrgUser $orgUser, string $pin): bool
    {
        return $this->hasher->verify($orgUser->getPinHash(), strtoupper(trim($pin)));
    }

    /**
     * Fetch all taken PINs for the organization in a single query.
     */
    public function getTakenPins(Organization $org, ?int $excludeOrgUserId = null): array
    {
        if ($org->getId() === null) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('e.pin')
            ->from(OrgUser::class, 'e')
            ->where('e.organization = :org')
            ->setParameter('org', $org);

        if ($excludeOrgUserId !== null) {
            $qb->andWhere('e.id != :excludeId')
               ->setParameter('excludeId', $excludeOrgUserId);
        }

        $results = $qb->getQuery()->getScalarResult();
        return array_column($results, 'pin');
    }

    /**
     * Check if a PIN is taken in the organization.
     */
    public function isPinTaken(Organization $org, string $pin, ?int $excludeOrgUserId = null): bool
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(OrgUser::class, 'e')
            ->where('e.organization = :org')
            ->andWhere('e.pin = :pin')
            ->setParameter('org', $org)
            ->setParameter('pin', strtoupper(trim($pin)));

        if ($excludeOrgUserId !== null) {
            $qb->andWhere('e.id != :excludeId')
               ->setParameter('excludeId', $excludeOrgUserId);
        }

        return (int)$qb->getQuery()->getSingleScalarResult() > 0;
    }


    /**
     * Generate a unique PIN for an org.
     */
    public function generateUniquePin(Organization $org, ?int $excludeOrgUserId = null, array $excludePins = []): string
    {
        $takenPins = $this->getTakenPins($org, $excludeOrgUserId);
        $allPossible = $this->getAllPossiblePins();

        $available = array_diff($allPossible, $takenPins, $excludePins);

        if (empty($available)) {
            throw new \RuntimeException('No available PINs left for this organization.');
        }

        return $available[array_rand($available)];
    }

    /**
     * Reset the user's PIN to a new unique one and return the plain PIN.
     */
    public function resetPin(OrgUser $orgUser): string
    {
        $org = $orgUser->getOrganization();
        $newPin = $this->generateUniquePin($org, $orgUser->getId());

        $orgUser->setPin($newPin);
        $orgUser->setPinHash($this->hashPin($newPin));
        $orgUser->setUpdatedAt(new \DateTimeImmutable());

        return $newPin;
    }
}
