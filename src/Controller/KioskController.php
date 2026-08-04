<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\OrgUser;
use App\Entity\Organization;
use App\Entity\WorkSession;
use App\Service\AppConfigService;
use App\Service\LicenseService;
use App\Service\PinService;
use App\Service\SessionSweeperService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


class KioskController extends AbstractController
{
    private PinService $pinService;

    private AppConfigService $configService;

    public function __construct(PinService $pinService, AppConfigService $configService)
    {
        $this->pinService = $pinService;
        $this->configService = $configService;
    }

    #[Route('/org/{slug}/kiosk-redirect/{semiPermanentToken}', name: 'app_kiosk_redirect')]
    public function redirectKiosk(string $slug, string $semiPermanentToken, EntityManagerInterface $entityManager): Response
    {
        $org = $entityManager->getRepository(Organization::class)->findOneBy(['slug' => $slug]);
        if (!$org) {
            throw $this->createNotFoundException('Organization not found');
        }

        if ($org->getSemiPermanentKioskToken() !== $semiPermanentToken) {
            return $this->render('kiosk/error.html.twig', [
                'org_name' => $org->getName(),
                'error' => 'This semi-permanent kiosk link is invalid. Please contact your manager.'
            ]);
        }

        if ($org->isArchived()) {
            return $this->render('kiosk/error.html.twig', [
                'org_name' => $org->getName(),
                'error' => 'This organization has been archived. Check-in and checkout kiosks are currently disabled. Please contact support.'
            ]);
        }

        return $this->redirectToRoute('app_kiosk', [
            'slug' => $slug,
            'token' => $org->getCheckInLinkToken(),
        ]);
    }

    #[Route('/org/{slug}/kiosk/{token}', name: 'app_kiosk')]
    public function index(string $slug, string $token, EntityManagerInterface $entityManager, SessionSweeperService $sessionSweeper): Response
    {
        $org = $entityManager->getRepository(Organization::class)->findOneBy(['slug' => $slug]);
        if (!$org) {
            throw $this->createNotFoundException('Organization not found');
        }

        if ($org->isArchived()) {
            return $this->render('kiosk/error.html.twig', [
                'org_name' => $org->getName(),
                'error' => 'This organization has been archived. Check-in and checkout kiosks are currently disabled. Please contact support.'
            ]);
        }

        $sessionSweeper->sweepActiveSessions($org);

        // Validate rotatable check-in token
        if ($org->getCheckInLinkToken() !== $token) {
            return $this->render('kiosk/error.html.twig', [
                'org_name' => $org->getName(),
                'error' => 'This check-in link has expired or is invalid. Please contact your manager.'
            ]);
        }

        // Fetch active orgUsers
        $orgUsers = $entityManager->getRepository(OrgUser::class)->findBy([
            'organization' => $org,
            'isActive' => true
        ], ['firstName' => 'ASC', 'lastName' => 'ASC']);

        // Fetch active categories
        $categories = $entityManager->getRepository(Category::class)->findBy([
            'organization' => $org,
            'isActive' => true,
            'deletedAt' => null
        ]);

        return $this->render('kiosk/index.html.twig', [
            'org' => $org,
            'orgUsers' => $orgUsers,
            'categories' => $categories,
            'token' => $token,
        ]);
    }

    #[Route('/org/{slug}/kiosk/{token}/authenticate', name: 'app_kiosk_authenticate', methods: ['POST'])]
    public function authenticate(string $slug, string $token, Request $request, EntityManagerInterface $entityManager, SessionSweeperService $sessionSweeper): JsonResponse
    {
        $org = $entityManager->getRepository(Organization::class)->findOneBy(['slug' => $slug]);
        if (!$org || $org->getCheckInLinkToken() !== $token) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid kiosk configuration'], 400);
        }

        if ($org->isArchived()) {
            return new JsonResponse(['success' => false, 'message' => 'This organization has been archived. Kiosk access is disabled.'], 403);
        }

        $sessionSweeper->sweepActiveSessions($org);

        $orgUserId = $request->request->get('orgUser_id');
        $pin = $request->request->get('pin');

        if (!$orgUserId || !$pin) {
            return new JsonResponse(['success' => false, 'message' => 'Missing credentials'], 400);
        }

        $orgUser = $entityManager->getRepository(OrgUser::class)->find($orgUserId);
        if (!$orgUser || $orgUser->getOrganization()->getId() !== $org->getId() || !$orgUser->isActive()) {
            return new JsonResponse(['success' => false, 'message' => 'OrgUser not found'], 404);
        }

        if ($org->getAccessMode() === 'secure') {
            $session = $request->getSession();
            $lockoutKey = 'kiosk_attempts_' . $orgUser->getId();
            $attempts = $session->get($lockoutKey, 0);

            if ($attempts >= 3) {
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'Account blocked due to 3 failed PIN attempts. Please ask your manager to reset your PIN.'
                ], 403);
            }

            // Verify PIN
            if (!$this->pinService->verifyPin($orgUser, $pin)) {
                $attempts++;
                $session->set($lockoutKey, $attempts);
                $remaining = 3 - $attempts;
                
                return new JsonResponse([
                    'success' => false, 
                    'message' => "Incorrect PIN. {$remaining} attempts remaining.",
                    'remaining' => $remaining
                ]);
            }

            // Reset failed attempts on success
            $session->remove($lockoutKey);
        }

        // Check if there is an active work session
        $activeSession = $entityManager->getRepository(WorkSession::class)->findOneBy([
            'orgUser' => $orgUser,
            'endedAt' => null
        ]);

        if ($activeSession) {
            return new JsonResponse([
                'success' => true,
                'action' => 'checkout_prompt',
                'orgUser' => [
                    'id' => $orgUser->getId(),
                    'name' => $orgUser->getFirstName() . ' ' . ($org->isMaskLastName() ? $this->maskLastName($orgUser->getLastName()) : $orgUser->getLastName()),
                ],
                'session' => [
                    'id' => $activeSession->getId(),
                    'startedAt' => $activeSession->getStartedAt()->format('Y-m-d H:i:s'),
                    'category' => $activeSession->getCategory() ? $activeSession->getCategory()->getName() : 'General',
                ]
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'action' => 'checkin_prompt',
            'orgUser' => [
                'id' => $orgUser->getId(),
                'name' => $orgUser->getFirstName() . ' ' . ($org->isMaskLastName() ? $this->maskLastName($orgUser->getLastName()) : $orgUser->getLastName()),
            ]
        ]);
    }

    #[Route('/org/{slug}/kiosk/{token}/action', name: 'app_kiosk_action', methods: ['POST'])]
    public function kioskAction(string $slug, string $token, Request $request, EntityManagerInterface $entityManager, SessionSweeperService $sessionSweeper, AppConfigService $configService): JsonResponse
    {
        $org = $entityManager->getRepository(Organization::class)->findOneBy(['slug' => $slug]);
        if (!$org || $org->getCheckInLinkToken() !== $token) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid kiosk configuration'], 400);
        }

        if ($org->isArchived()) {
            return new JsonResponse(['success' => false, 'message' => 'This organization has been archived. Kiosk access is disabled.'], 403);
        }

        $sessionSweeper->sweepActiveSessions($org);

        $orgUserId = $request->request->get('orgUser_id');
        $categoryId = $request->request->get('category_id');
        $action = $request->request->get('action'); // checkin or checkout

        $orgUser = $entityManager->getRepository(OrgUser::class)->find($orgUserId);
        if (!$orgUser || $orgUser->getOrganization()->getId() !== $org->getId() || !$orgUser->isActive()) {
            return new JsonResponse(['success' => false, 'message' => 'OrgUser not found'], 404);
        }

        // Check active session
        $activeSession = $entityManager->getRepository(WorkSession::class)->findOneBy([
            'orgUser' => $orgUser,
            'endedAt' => null
        ]);

        if ($action === 'checkin') {
            if ($activeSession) {
                return new JsonResponse(['success' => false, 'message' => 'You already have an active work session. Please check out first.'], 400);
            }

            // Check if open mode is bypassed
            if ($org->getAccessMode() === 'secure' && !$request->request->has('verified')) {
                return new JsonResponse(['success' => false, 'message' => 'Security verification required.'], 403);
            }

            $category = null;
            if ($categoryId) {
                $category = $entityManager->getRepository(Category::class)->findOneBy([
                    'id' => $categoryId,
                    'organization' => $org,
                    'isActive' => true,
                    'deletedAt' => null
                ]);
                if (!$category) {
                    return new JsonResponse(['success' => false, 'message' => 'Invalid or inactive category selected.'], 400);
                }
            }

            $session = new WorkSession();
            $session->setOrgUser($orgUser);
            $session->setOrganization($org);
            $session->setCategory($category);
            $session->setStartMethod($org->getAccessMode());
            $session->setDeviceInfo($request->headers->get('User-Agent'));

            // Check check-in window against organization's resolved local timezone
            $orgTzStr = $this->configService->resolveOrganizationTimezone($org);
            $orgTz = new \DateTimeZone($orgTzStr);
            $nowLocal = new \DateTime('now', $orgTz);
            $session->setStartedAt(new \DateTime());

            $isOutOfWindow = false;
            $nowTime = new \DateTime($nowLocal->format('H:i:s'));
            
            if ($org->getCheckInWindowStart() && $org->getCheckInWindowEnd()) {
                $winStart = new \DateTime($org->getCheckInWindowStart()->format('H:i:s'));
                $winEnd = new \DateTime($org->getCheckInWindowEnd()->format('H:i:s'));
                
                if ($winStart <= $winEnd) {
                    if ($nowTime < $winStart || $nowTime > $winEnd) {
                        $isOutOfWindow = true;
                    }
                } else { // Overnight window support (e.g. 22:00 to 06:00)
                    if ($nowTime > $winEnd && $nowTime < $winStart) {
                        $isOutOfWindow = true;
                    }
                }
            }
            $session->setIsOutOfWindow($isOutOfWindow);

            $entityManager->persist($session);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Check-in successful!',
                'session' => [
                    'startedAt' => $session->getStartedAt()->format('H:i:s'),
                    'outOfWindow' => $isOutOfWindow
                ]
            ]);

        } elseif ($action === 'checkout') {
            if (!$activeSession) {
                return new JsonResponse(['success' => false, 'message' => 'No active work session found.'], 400);
            }

            $now = new \DateTime();
            $activeSession->setEndedAt($now);
            $activeSession->setEndMethod($org->getAccessMode());
            
            $durationSeconds = $now->getTimestamp() - $activeSession->getStartedAt()->getTimestamp();
            $activeSession->setDurationSeconds($durationSeconds);

            // Check if checkout time is out of window — compare in organization's local timezone
            $orgTzStr = $this->configService->resolveOrganizationTimezone($org);
            $orgTz = new \DateTimeZone($orgTzStr);
            $nowLocalTime = new \DateTime((new \DateTime('now', $orgTz))->format('H:i:s'));

            if ($org->getCheckInWindowStart() && $org->getCheckInWindowEnd()) {
                $winStart = new \DateTime($org->getCheckInWindowStart()->format('H:i:s'));
                $winEnd = new \DateTime($org->getCheckInWindowEnd()->format('H:i:s'));
                
                if ($winStart <= $winEnd) {
                    if ($nowLocalTime < $winStart || $nowLocalTime > $winEnd) {
                        $activeSession->setIsOutOfWindow(true);
                    }
                } else { // Overnight window support (e.g. 22:00 to 06:00)
                    if ($nowLocalTime > $winEnd && $nowLocalTime < $winStart) {
                        $activeSession->setIsOutOfWindow(true);
                    }
                }
            }

            $entityManager->flush();

            $hours = floor($durationSeconds / 3600);
            $mins = floor(($durationSeconds % 3600) / 60);

            return new JsonResponse([
                'success' => true,
                'message' => 'Checkout successful!',
                'session' => [
                    'startedAt' => $activeSession->getStartedAt()->format('H:i:s'),
                    'endedAt' => $activeSession->getEndedAt()->format('H:i:s'),
                    'duration' => "{$hours}h {$mins}m",
                    'isOutOfWindow' => $activeSession->isOutOfWindow()
                ]
            ]);
        }

        return new JsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }

    /**
     * Masks the middle characters of a last name for kiosk display privacy.
     * e.g. "Aguado" -> "A****o", "Lee" -> "L*e", "Li" -> "Li"
     */
    private function maskLastName(string $lastName): string
    {
        $len = mb_strlen($lastName);
        if ($len <= 2) {
            return $lastName;
        }
        $first = mb_substr($lastName, 0, 1);
        $last  = mb_substr($lastName, -1, 1);
        $stars = str_repeat('*', $len - 2);
        return $first . $stars . $last;
    }

    #[Route('/org/{slug}/kiosk/{token}/scan', name: 'app_kiosk_scan', methods: ['POST'])]
    public function scan(string $slug, string $token, Request $request, EntityManagerInterface $entityManager, SessionSweeperService $sessionSweeper, LicenseService $licenseService): JsonResponse
    {
        $org = $entityManager->getRepository(Organization::class)->findOneBy(['slug' => $slug]);
        if (!$org || $org->getCheckInLinkToken() !== $token) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid kiosk configuration'], 400);
        }

        if ($org->isArchived()) {
            return new JsonResponse(['success' => false, 'message' => 'This organization has been archived. Kiosk access is disabled.'], 403);
        }

        if (!$licenseService->isBarcodeEnabled($org)) {
            return new JsonResponse(['success' => false, 'message' => 'Barcode scanning is disabled on the Basic plan. Please upgrade to a higher tier.'], 403);
        }

        $sessionSweeper->sweepActiveSessions($org);

        $pin = $request->request->get('pin');
        if (!$pin) {
            return new JsonResponse(['success' => false, 'message' => 'Missing barcode scan value'], 400);
        }

        // Look up the active user by PIN within this organization
        $orgUser = $entityManager->getRepository(OrgUser::class)->findOneBy([
            'organization' => $org,
            'pin' => $pin,
            'isActive' => true
        ]);

        if (!$orgUser) {
            return new JsonResponse(['success' => false, 'message' => 'User not found or inactive'], 404);
        }

        // Reset lockout Key just in case they had failed manual PIN attempts
        $session = $request->getSession();
        $lockoutKey = 'kiosk_attempts_' . $orgUser->getId();
        $session->remove($lockoutKey);

        // Check if there is an active work session
        $activeSession = $entityManager->getRepository(WorkSession::class)->findOneBy([
            'orgUser' => $orgUser,
            'endedAt' => null
        ]);

        if ($activeSession) {
            return new JsonResponse([
                'success' => true,
                'action' => 'checkout_prompt',
                'orgUser' => [
                    'id' => $orgUser->getId(),
                    'name' => $orgUser->getFirstName() . ' ' . ($org->isMaskLastName() ? $this->maskLastName($orgUser->getLastName()) : $orgUser->getLastName()),
                ],
                'session' => [
                    'id' => $activeSession->getId(),
                    'startedAt' => $activeSession->getStartedAt()->format('Y-m-d H:i:s'),
                    'category' => $activeSession->getCategory() ? $activeSession->getCategory()->getName() : 'General',
                ]
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'action' => 'checkin_prompt',
            'orgUser' => [
                'id' => $orgUser->getId(),
                'name' => $orgUser->getFirstName() . ' ' . ($org->isMaskLastName() ? $this->maskLastName($orgUser->getLastName()) : $orgUser->getLastName()),
            ]
        ]);
    }
}
