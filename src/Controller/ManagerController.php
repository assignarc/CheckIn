<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\OrgUser;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\PaymentHistory;
use App\Entity\WorkSession;
use App\Entity\AuditLog;
use App\Service\LicenseService;
use App\Service\PinService;
use App\Service\SessionSweeperService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/manager')]
class ManagerController extends AbstractController
{
    private PinService $pinService;
    private LicenseService $licenseService;
    private \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher;

    public function __construct(
        PinService $pinService,
        LicenseService $licenseService,
        \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
    ) {
        $this->pinService = $pinService;
        $this->licenseService = $licenseService;
        $this->eventDispatcher = $eventDispatcher;
    }


    private function getOrgLimits(Organization $org, EntityManagerInterface $em): array
    {
        $orgUserCount = $em->getRepository(OrgUser::class)->count(['organization' => $org, 'isActive' => true]);
        $managerCount = $em->getRepository(Manager::class)->count(['organization' => $org, 'isActive' => true]);
        $categoryCount = $em->getRepository(Category::class)->count(['organization' => $org, 'deletedAt' => null]);
        
        return [
            'plan' => $org->getPlan()->getName(),
            'limits' => [
                'max_orgUsers' => $org->getMaxOrgUsers(),
                'max_managers' => $org->getMaxManagers(),
                'max_fields' => $org->getMaxFields(),
                'max_categories' => $org->getMaxCategories(),
                'max_session_hours' => $org->getSessionLimitHours(),
                'retention_days' => $org->getRetentionDays(),
                'exports' => $org->isExportsEnabled(),
            ],
            'usage' => [
                'orgUsers' => $orgUserCount,
                'managers' => $managerCount,
                'categories' => $categoryCount,
            ]
        ];
    }

    private function logAudit(EntityManagerInterface $em, Organization $org, string $action, array $details, Request $request): void
    {
        $manager = $this->getUser();
        $log = new AuditLog();
        $log->setOrganization($org);
        if ($manager instanceof Manager) {
            $log->setManager($manager);
        }
        $log->setAction($action);
        $log->setDetailsJson($details);
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        
        $em->persist($log);
        $em->flush();
    }



    private function parseHrMinToMinutes(?string $str): ?int
    {
        if ($str === null) return null;
        $str = trim($str);
        if ($str === '') return null;
        if (!preg_match('/^([0-9]+):([0-5][0-9])$/', $str, $matches)) {
            return null;
        }
        return ((int)$matches[1] * 60) + (int)$matches[2];
    }

    // sweepActiveSessions moved to App\Service\SessionSweeperService

    #[Route('/dashboard', name: 'app_manager_dashboard')]
    public function dashboard(EntityManagerInterface $em, \App\Service\SessionSweeperService $sessionSweeper): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $sessionSweeper->sweepActiveSessions($org);

        $limitsData = $this->getOrgLimits($org, $em);

        // Fetch active check-ins count
        $activeSessionsCount = $em->getRepository(WorkSession::class)->count([
            'organization' => $org,
            'endedAt' => null
        ]);

        // Recent work sessions
        $recentSessions = $em->getRepository(WorkSession::class)->findBy(
            ['organization' => $org],
            ['startedAt' => 'DESC'],
            5
        );

        // Category distribution for metrics chart
        $sessions = $em->getRepository(WorkSession::class)->findBy(['organization' => $org]);
        $categoryBreakdown = [];
        foreach ($sessions as $session) {
            $catName = $session->getCategory() ? $session->getCategory()->getName() : 'General';
            if (!isset($categoryBreakdown[$catName])) {
                $categoryBreakdown[$catName] = 0;
            }
            $categoryBreakdown[$catName] += round(($session->getDurationSeconds() ?? 0) / 3600, 1);
        }

        return $this->render('manager/dashboard.html.twig', [
            'org' => $org,
            'limitsData' => $limitsData,
            'activeCount' => $activeSessionsCount,
            'recentSessions' => $recentSessions,
            'categoryBreakdown' => $categoryBreakdown,
        ]);
    }

    #[Route('/settings/delete-logo', name: 'app_manager_settings_delete_logo', methods: ['POST'])]
    public function deleteLogo(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $org->setLogoBlob(null);
        $org->setLogoPath(null);
        $org->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->logAudit($em, $org, 'ORG_LOGO_DELETE', [], $request);
        $this->addFlash('success', 'Organization logo removed successfully. Falling back to stock image.');

        return $this->redirectToRoute('app_manager_settings');
    }

    #[Route('/settings', name: 'app_manager_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, \App\Service\AppConfigService $configService): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        if ($request->isMethod('POST')) {
            $section = $request->request->get('_section', 'policies');

            // ── Org Profile ──────────────────────────────────────────────
            if ($section === 'org_profile') {
                $orgName = trim($request->request->get('org_name', ''));
                $newPhone = trim((string)$request->request->get('org_phone', '')) ?: null;
                $newAddress = trim((string)$request->request->get('org_address', '')) ?: null;
                $newTimezone = trim((string)$request->request->get('org_timezone', '')) ?: null;
                $logoFile = $request->files->get('org_logo');

                // Data Validations
                if (empty($orgName) || mb_strlen($orgName) > 100) {
                    $this->addFlash('danger', 'Organization name is required and must be 100 characters or fewer.');
                    return $this->redirectToRoute('app_manager_settings');
                }
                if ($newAddress && mb_strlen($newAddress) > 255) {
                    $this->addFlash('danger', 'Organization address must be 255 characters or fewer.');
                    return $this->redirectToRoute('app_manager_settings');
                }
                if ($newPhone && mb_strlen($newPhone) > 30) {
                    $this->addFlash('danger', 'Phone number must be 30 characters or fewer.');
                    return $this->redirectToRoute('app_manager_settings');
                }
                if ($newTimezone && !in_array($newTimezone, \DateTimeZone::listIdentifiers(), true)) {
                    $this->addFlash('danger', 'Invalid timezone specified.');
                    return $this->redirectToRoute('app_manager_settings');
                }

                $changedFields = [];
                if ($orgName && $org->getName() !== $orgName) {
                    $changedFields['name'] = ['old' => $org->getName(), 'new' => $orgName];
                    $org->setName($orgName);
                }
                if ($org->getPhone() !== $newPhone) {
                    $changedFields['phone'] = ['old' => $org->getPhone(), 'new' => $newPhone];
                    $org->setPhone($newPhone);
                }
                if ($org->getAddress() !== $newAddress) {
                    $changedFields['address'] = ['old' => $org->getAddress(), 'new' => $newAddress];
                    $org->setAddress($newAddress);
                }
                if ($org->getTimezone() !== $newTimezone) {
                    $changedFields['timezone'] = ['old' => $org->getTimezone(), 'new' => $newTimezone];
                    $org->setTimezone($newTimezone);
                }

                if ($logoFile) {
                    // Load image
                    $mime = $logoFile->getMimeType();
                    $src = null;
                    if ($mime === 'image/jpeg') $src = imagecreatefromjpeg($logoFile->getPathname());
                    elseif ($mime === 'image/png') $src = imagecreatefrompng($logoFile->getPathname());
                    elseif ($mime === 'image/webp') $src = imagecreatefromwebp($logoFile->getPathname());
                    elseif ($mime === 'image/gif') $src = imagecreatefromgif($logoFile->getPathname());

                    if ($src) {
                        $width = imagesx($src);
                        $height = imagesy($src);
                        
                        // Center-square crop sizing
                        $size = min($width, $height);
                        $x = (int)(($width - $size) / 2);
                        $y = (int)(($height - $size) / 2);
                        
                        $dst = imagecreatetruecolor(128, 128);
                        
                        // Handle transparency
                        imagealphablending($dst, false);
                        imagesavealpha($dst, true);
                        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
                        imagefilledrectangle($dst, 0, 0, 128, 128, $transparent);

                        // Center crop & resample to 128x128
                        imagecopyresampled($dst, $src, 0, 0, $x, $y, 128, 128, $size, $size);
                        
                        // Capture buffer as PNG binary string
                        ob_start();
                        imagepng($dst);
                        $logoBinary = ob_get_clean();

                        imagedestroy($src);
                        imagedestroy($dst);

                        $org->setLogoBlob($logoBinary);
                        $org->setLogoPath('db_stored');
                        $changedFields['logo'] = ['old' => 'existing_logo', 'new' => 'db_stored_new_crop'];
                    }
                }

                $org->setUpdatedAt(new \DateTimeImmutable());
                $em->flush();
                $this->logAudit($em, $org, 'ORG_PROFILE_UPDATE', [
                    'name' => $org->getName(),
                    'changed_fields' => $changedFields
                ], $request);

                $this->eventDispatcher->dispatch(new \App\Event\OrganizationProfileUpdatedEvent(
                    $org,
                    $changedFields,
                    new \DateTimeImmutable()
                ), \App\Event\OrganizationProfileUpdatedEvent::NAME);

                $this->addFlash('success', 'Organization profile updated successfully.');
                return $this->redirectToRoute('app_manager_settings');
            }

            // ── My Account ───────────────────────────────────────────────
            if ($section === 'my_account') {
                $firstName = trim($request->request->get('first_name', ''));
                $lastName  = trim($request->request->get('last_name', ''));
                $email     = trim($request->request->get('email', ''));
                $phone     = trim($request->request->get('phone', ''));
                $password  = $request->request->get('password', '');

                if ($firstName && (strlen($firstName) < 2 || strlen($firstName) > 50)) {
                    $this->addFlash('danger', 'First name must be between 2 and 50 characters.');
                    return $this->redirectToRoute('app_manager_settings');
                }

                if ($lastName && (strlen($lastName) < 2 || strlen($lastName) > 50)) {
                    $this->addFlash('danger', 'Last name must be between 2 and 50 characters.');
                    return $this->redirectToRoute('app_manager_settings');
                }

                if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->addFlash('danger', 'Please enter a valid email address.');
                    return $this->redirectToRoute('app_manager_settings');
                }

                if ($phone && strlen(preg_replace('/[^0-9\+]/', '', $phone)) < 7) {
                    $this->addFlash('danger', 'Please enter a valid phone number.');
                    return $this->redirectToRoute('app_manager_settings');
                }

                if ($password && strlen($password) < 8) {
                    $this->addFlash('danger', 'Password must be at least 8 characters long.');
                    return $this->redirectToRoute('app_manager_settings');
                }

                if ($firstName) $manager->setFirstName($firstName);
                if ($lastName)  $manager->setLastName($lastName);
                if ($email)     $manager->setEmail($email);
                $manager->setPhone($phone ?: null);

                $passwordChanged = false;
                if ($password && strlen($password) >= 8) {
                    $manager->setPasswordHash($passwordHasher->hashPassword($manager, $password));
                    $passwordChanged = true;
                }
                $manager->setUpdatedAt(new \DateTimeImmutable());
                $em->flush();
                $details = ['manager_id' => $manager->getId()];
                if ($passwordChanged) {
                    $details['password_changed'] = true;
                    $this->logAudit($em, $org, 'PASSWORD_CHANGE_SELF', ['email' => $manager->getEmail()], $request);
                    $this->eventDispatcher->dispatch(new \App\Event\PasswordChangedEvent(
                        $manager,
                        $manager,
                        new \DateTimeImmutable()
                    ), \App\Event\PasswordChangedEvent::NAME);
                } else {
                    $this->logAudit($em, $org, 'MANAGER_SELF_EDIT', $details, $request);
                }
                $this->addFlash('success', 'Your account information has been updated.');
                return $this->redirectToRoute('app_manager_settings');
            }

            // ── Policies (default) ───────────────────────────────────────
            $accessMode   = $request->request->get('access_mode', 'secure');
            $sessionLimit = (int)$request->request->get('session_limit_hours', 2);
            $windowStart  = $request->request->get('window_start');
            $windowEnd    = $request->request->get('window_end');

            $maxAllowedHours = $org->getSessionLimitHours();
            if (!$this->licenseService->isSessionDurationAllowed($org, $sessionLimit * 60)) {
                $sessionLimit = $maxAllowedHours;
                $this->addFlash('warning', "Session duration limit capped at {$maxAllowedHours} hours by your plan.");
            }

            $org->setAccessMode($accessMode);
            $org->setSessionLimitHoursOverride($sessionLimit);
            $utcTz = new \DateTimeZone('UTC');
            if ($windowStart) {
                $winStartDt = \DateTime::createFromFormat('!H:i', $windowStart, $utcTz);
                $org->setCheckInWindowStart($winStartDt ?: null);
            } else {
                $org->setCheckInWindowStart(null);
            }

            if ($windowEnd) {
                $winEndDt = \DateTime::createFromFormat('!H:i', $windowEnd, $utcTz);
                $org->setCheckInWindowEnd($winEndDt ?: null);
            } else {
                $org->setCheckInWindowEnd(null);
            }

            $maskLastName = $request->request->get('mask_last_name') === '1';
            $org->setMaskLastName($maskLastName);

            $kioskTokenAutoRotate = $request->request->get('kiosk_token_auto_rotate') === '1';
            $org->setKioskTokenAutoRotate($kioskTokenAutoRotate);

            $org->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();

            $this->logAudit($em, $org, 'POLICY_CHANGE', [
                'access_mode'             => $accessMode,
                'session_limit_hours'     => $sessionLimit,
                'window_start'            => $windowStart,
                'window_end'              => $windowEnd,
                'mask_last_name'          => $maskLastName,
                'kiosk_token_auto_rotate' => $kioskTokenAutoRotate,
            ], $request);

            $this->addFlash('success', 'Policies updated successfully.');
            return $this->redirectToRoute('app_manager_settings');
        }

        if (!$org->getSemiPermanentKioskToken()) {
            $org->setSemiPermanentKioskToken(bin2hex(random_bytes(16)));
            $em->flush();
        }

        $pdfPosterDownloadEnabled = (bool)$configService->get('pdf_poster_download_enabled', false);
        return $this->render('manager/settings.html.twig', [
            'org' => $org,
            'pdfPosterDownloadEnabled' => $pdfPosterDownloadEnabled,
        ]);
    }

    #[Route('/settings/rotate-token', name: 'app_manager_rotate_token', methods: ['POST'])]
    public function rotateToken(Request $request, EntityManagerInterface $em, \App\Service\TokenGeneratorService $tokenGenerator): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $oldToken = $org->getCheckInLinkToken();
        $newToken = $tokenGenerator->generateHumanReadableToken();
        $org->setCheckInLinkToken($newToken);
        $em->flush();

        $this->logAudit($em, $org, 'TOKEN_ROTATION', [
            'old_token' => $oldToken,
            'new_token' => $newToken
        ], $request);

        $this->addFlash('success', 'Check-in kiosk link has been rotated. The old link is now invalid.');
        return $this->redirectToRoute('app_manager_settings');
    }

    #[Route('/settings/rotate-semi-permanent-token', name: 'app_manager_rotate_semi_permanent_token', methods: ['POST'])]
    public function rotateSemiPermanentToken(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $oldToken = $org->getSemiPermanentKioskToken();
        $newToken = bin2hex(random_bytes(16));
        $org->setSemiPermanentKioskToken($newToken);
        $em->flush();

        $this->logAudit($em, $org, 'SEMI_PERMANENT_TOKEN_ROTATION', [
            'old_token' => $oldToken,
            'new_token' => $newToken,
        ], $request);

        $this->addFlash('success', 'Permanent kiosk QR code link has been regenerated. Old printed QR codes are now invalid.');
        return $this->redirectToRoute('app_manager_settings', ['_tab' => 'semi-perm']);
    }

    #[Route('/settings/kiosk-qr-data', name: 'app_manager_settings_kiosk_qr_data', methods: ['GET'])]
    public function kioskQrData(): \Symfony\Component\HttpFoundation\Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org     = $manager->getOrganization();

        $kioskUrl = $this->generateUrl(
            'app_kiosk_redirect',
            ['slug' => $org->getSlug(), 'semiPermanentToken' => $org->getSemiPermanentKioskToken()],
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
        );

        $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=360x360&ecc=H&color=6366f1&data=' . urlencode($kioskUrl);

        $imgData = null;
        try {
            $client = \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 5, 'verify_peer' => false]);
            $response = $client->request('GET', $qrApiUrl);
            if ($response->getStatusCode() === 200) {
                $imgData = $response->getContent();
            }
        } catch (\Throwable $e) {
            $imgData = @file_get_contents($qrApiUrl);
        }

        if ((!$imgData || strlen($imgData) < 10) && $this->getParameter('kernel.environment') !== 'test') {
            $fallbackUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=360x360&chl=' . urlencode($kioskUrl);
            try {
                $client = \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 5, 'verify_peer' => false]);
                $response = $client->request('GET', $fallbackUrl);
                if ($response->getStatusCode() === 200) {
                    $imgData = $response->getContent();
                }
            } catch (\Throwable $e) {
                $imgData = @file_get_contents($fallbackUrl);
            }
        }

        if (!$imgData && $this->getParameter('kernel.environment') === 'test') {
            $imgData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        }

        if ($imgData === false || $imgData === null || strlen($imgData) < 10) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Failed to fetch QR code'], 500);
        }

        $base64 = 'data:image/png;base64,' . base64_encode($imgData);
        return new \Symfony\Component\HttpFoundation\JsonResponse([
            'qrDataUrl'  => $base64,
            'kioskUrl'   => $kioskUrl,
            'orgName'    => $org->getName(),
            'orgSlug'    => $org->getSlug(),
        ]);
    }

    #[Route('/categories', name: 'app_manager_categories', methods: ['GET', 'POST'])]
    public function categories(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $limitsData = $this->getOrgLimits($org, $em);
        $categories = $em->getRepository(Category::class)->findBy(['organization' => $org, 'deletedAt' => null]);
        
        $qb = $em->createQueryBuilder();
        $qb->select('c')
           ->from(Category::class, 'c')
           ->where('c.organization = :org')
           ->andWhere('c.deletedAt IS NOT NULL')
           ->setParameter('org', $org);
        $deletedCategories = $qb->getQuery()->getResult();

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $icon = $request->request->get('icon', 'work');
            $color = $request->request->get('color', '#8b5cf6');
            $maxDurationInput = $request->request->get('max_session_duration');
            $terminateAtMax = $request->request->has('terminate_at_max_duration');

            $isActive = $this->licenseService->isNextCategoryAllowed($org);

            if ($name) {
                $category = new Category();
                $category->setOrganization($org);
                $category->setName($name);
                $category->setIcon($icon);
                $category->setIsActive($isActive);

                // Handle session duration overrides for non-basic plans
                if ($org->getPlan()->hasCustomSessionDurations()) {
                    if ($maxDurationInput !== null && $maxDurationInput !== '') {
                        $maxMinutes = $this->parseHrMinToMinutes($maxDurationInput);
                        $planMaxMinutes = $org->getSessionLimitHours() * 60;
                        if ($maxMinutes === null) {
                            $this->addFlash('danger', "Max Session Duration must be in HR:MIN format (e.g. 08:00 or 02:30).");
                            return $this->redirectToRoute('app_manager_categories');
                        }
                        if ($maxMinutes <= 0 || !$this->licenseService->isSessionDurationAllowed($org, $maxMinutes)) {
                            $this->addFlash('danger', "Max Session Duration for a category must be greater than 0 and cannot exceed the plan limit of {$org->getSessionLimitHours()} hours.");
                            return $this->redirectToRoute('app_manager_categories');
                        }
                        $category->setMaxSessionDurationMinutes($maxMinutes);
                    } else {
                        $category->setMaxSessionDurationMinutes(null);
                    }
                    $category->setTerminateAtMaxDuration($terminateAtMax);
                } else {
                    // Free plan: always auto-terminates at plan limit
                    $category->setMaxSessionDurationMinutes(null);
                    $category->setTerminateAtMaxDuration(true);
                }

                $em->persist($category);
                $em->flush();

                $this->logAudit($em, $org, 'CATEGORY_CREATE', ['name' => $name], $request);
                
                if ($isActive) {
                    $this->addFlash('success', "Category '{$name}' created successfully.");
                } else {
                    $this->addFlash('warning', "Category '{$name}' created as Inactive due to active category quota.");
                }
            }
            return $this->redirectToRoute('app_manager_categories');
        }

        return $this->render('manager/categories.html.twig', [
            'categories' => $categories,
            'deletedCategories' => $deletedCategories,
            'limitsData' => $limitsData,
            'org' => $org,
        ]);
    }

    #[Route('/categories/{id}/toggle-active', name: 'app_manager_category_toggle_active', methods: ['POST'])]
    public function toggleCategoryActive(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $category = $em->getRepository(Category::class)->find($id);
        if ($category && $category->getOrganization()->getId() === $org->getId()) {
            $newStatus = !$category->isActive();

            if ($newStatus && !$this->licenseService->isNextCategoryAllowed($org)) {
                $limitsData = $this->getOrgLimits($org, $em);
                $this->addFlash('danger', "Active category limit reached ({$limitsData['limits']['max_categories']}). Deactivate another category or upgrade your plan.");
                return $this->redirectToRoute('app_manager_categories');
            }

            $category->setIsActive($newStatus);
            $em->flush();

            $statusText = $newStatus ? 'activated' : 'inactivated';
            $this->logAudit($em, $org, 'CATEGORY_TOGGLE_ACTIVE', ['name' => $category->getName(), 'is_active' => $newStatus], $request);
            $this->addFlash('success', "Category \"{$category->getName()}\" has been {$statusText}.");
        }

        return $this->redirectToRoute('app_manager_categories');
    }

    #[Route('/categories/{id}/update', name: 'app_manager_category_update', methods: ['POST'])]
    public function updateCategory(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $category = $em->getRepository(Category::class)->find($id);
        if ($category && $category->getOrganization()->getId() === $org->getId()) {
            $name = $request->request->get('name');
            $icon = $request->request->get('icon', 'work');
            $color = $request->request->get('color', '#8b5cf6');
            $maxDurationInput = $request->request->get('max_session_duration');
            $terminateAtMax = $request->request->has('terminate_at_max_duration');

            if ($name) {
                $category->setName($name);
                $category->setIcon($icon);
                $category->setColor($color);

                // Handle session duration overrides for non-basic plans
                if ($org->getPlan()->hasCustomSessionDurations()) {
                    if ($maxDurationInput !== null && $maxDurationInput !== '') {
                        $maxMinutes = $this->parseHrMinToMinutes($maxDurationInput);
                        $planMaxMinutes = $org->getSessionLimitHours() * 60;
                        if ($maxMinutes === null) {
                            $this->addFlash('danger', "Max Session Duration must be in HR:MIN format (e.g. 08:00 or 02:30).");
                            return $this->redirectToRoute('app_manager_categories');
                        }
                        if ($maxMinutes <= 0 || !$this->licenseService->isSessionDurationAllowed($org, $maxMinutes)) {
                            $this->addFlash('danger', "Max Session Duration for a category must be greater than 0 and cannot exceed the plan limit of {$org->getSessionLimitHours()} hours.");
                            return $this->redirectToRoute('app_manager_categories');
                        }
                        $category->setMaxSessionDurationMinutes($maxMinutes);
                    } else {
                        $category->setMaxSessionDurationMinutes(null);
                    }
                    $category->setTerminateAtMaxDuration($terminateAtMax);
                } else {
                    $category->setMaxSessionDurationMinutes(null);
                    $category->setTerminateAtMaxDuration(true);
                }

                $em->flush();
                $this->logAudit($em, $org, 'CATEGORY_UPDATE', ['name' => $name], $request);
                $this->addFlash('success', "Category '{$name}' updated successfully.");
            }
        }

        return $this->redirectToRoute('app_manager_categories');
    }

    #[Route('/categories/{id}/delete', name: 'app_manager_category_delete', methods: ['POST'])]
    public function deleteCategory(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $category = $em->getRepository(Category::class)->find($id);
        if ($category && $category->getOrganization()->getId() === $org->getId()) {
            if ($category->getDeletedAt() === null) {
                $category->setDeletedAt(new \DateTimeImmutable());
                $category->setName($category->getName() . ' (Deleted)');
                $category->setIsActive(false);

                $em->flush();
                $this->logAudit($em, $org, 'CATEGORY_DELETE', ['name' => $category->getName()], $request);
                $this->addFlash('success', "Category has been deleted.");
            }
        }

        return $this->redirectToRoute('app_manager_categories');
    }

    #[Route('/users', name: 'app_manager_users')]
    public function users(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $limitsData = $this->getOrgLimits($org, $em);
        
        $searchQuery = $request->query->get('q', '');
        $statusFilter = $request->query->get('status', 'all'); // 'all', 'active', 'inactive'

        $qb = $em->createQueryBuilder()
            ->select('u')
            ->from(OrgUser::class, 'u')
            ->where('u.organization = :org')
            ->setParameter('org', $org);

        if ($searchQuery) {
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR u.email LIKE :q OR u.phone LIKE :q OR u.pin LIKE :q')
               ->setParameter('q', '%' . $searchQuery . '%');
        }

        if ($statusFilter === 'active') {
            $qb->andWhere('u.isActive = :status')->setParameter('status', true);
        } elseif ($statusFilter === 'inactive') {
            $qb->andWhere('u.isActive = :status')->setParameter('status', false);
        }

        $sortBy = $request->query->get('sort', 'lastName');
        $sortDir = $request->query->get('direction', 'ASC');

        $allowedSorts = [
            'lastName' => 'u.lastName',
            'firstName' => 'u.firstName',
            'email' => 'u.email',
            'phone' => 'u.phone',
            'pin' => 'u.pin',
            'isActive' => 'u.isActive',
            'createdAt' => 'u.createdAt'
        ];
        $sortField = $allowedSorts[$sortBy] ?? 'u.lastName';
        $sortDir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';

        if ($sortField === 'u.lastName') {
            $qb->orderBy('u.lastName', $sortDir)
               ->addOrderBy('u.firstName', $sortDir);
        } else {
            $qb->orderBy($sortField, $sortDir);
        }

        $orgUsers = $qb->getQuery()->getResult();

        $managers = $em->getRepository(Manager::class)->findBy(['organization' => $org]);

        return $this->render('manager/users.html.twig', [
            'orgUsers' => $orgUsers,
            'managers' => $managers,
            'limitsData' => $limitsData,
            'searchQuery' => $searchQuery,
            'statusFilter' => $statusFilter,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }

    #[Route('/users/toggle/{id}', name: 'app_manager_user_toggle', methods: ['POST'])]
    public function toggleUser(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $orgUser = $em->getRepository(OrgUser::class)->find($id);
        if ($orgUser && $orgUser->getOrganization()->getId() === $org->getId()) {
            $newStatus = !$orgUser->isActive();
            if ($newStatus && !$this->licenseService->isNextUserActiveAllowed($org)) {
                $this->addFlash('danger', "Cannot activate user. OrgUser limit reached ({$org->getMaxOrgUsers()}). Please deactivate another user first or upgrade your plan.");
                return $this->redirectToRoute('app_manager_users');
            }
            
            $orgUser->setIsActive($newStatus);
            $orgUser->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();

            $status = $orgUser->isActive() ? 'activated' : 'deactivated';
            $this->logAudit($em, $org, 'USER_TOGGLE', ['orgUser_id' => $orgUser->getId(), 'status' => $status], $request);

            $this->eventDispatcher->dispatch(new \App\Event\UserUpdatedEvent(
                $orgUser,
                'toggle_active',
                ['isActive' => ['old' => !$newStatus, 'new' => $newStatus]],
                new \DateTimeImmutable()
            ), \App\Event\UserUpdatedEvent::NAME);

            $this->addFlash('success', "OrgUser {$orgUser->getFirstName()} {$orgUser->getLastName()} has been {$status}.");
        }

        return $this->redirectToRoute('app_manager_users');
    }

    #[Route('/users/reset-pin/{id}', name: 'app_manager_user_reset_pin', methods: ['POST'])]
    public function resetUserPin(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $orgUser = $em->getRepository(OrgUser::class)->find($id);
        if ($orgUser && $orgUser->getOrganization()->getId() === $org->getId()) {
            // Generate a unique PIN for this org
            $newPin = $this->pinService->resetPin($orgUser);
            $em->flush();

            // Clear any PIN block session lockouts
            $session = $request->getSession();
            $session->remove('kiosk_attempts_' . $orgUser->getId());

            $this->logAudit($em, $org, 'PIN_RESET', ['orgUser_id' => $orgUser->getId()], $request);

            $this->addFlash('success', "PIN reset! New PIN for {$orgUser->getFirstName()} {$orgUser->getLastName()} is: <strong>{$newPin}</strong>");
        }

        return $this->redirectToRoute('app_manager_users');
    }

    #[Route('/users/add', name: 'app_manager_user_add', methods: ['POST'])]
    public function addOrgUser(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $isActive = $this->licenseService->isNextUserActiveAllowed($org);

        $first = $request->request->get('first_name');
        $last = $request->request->get('last_name');
        $email = $request->request->get('email');
        $phone = $request->request->get('phone');
        $pin = strtoupper(trim($request->request->get('pin', '')));

        if (!$first || !$last) {
            $this->addFlash('danger', 'First name and last name are required.');
            return $this->redirectToRoute('app_manager_users');
        }

        // Auto-generate PIN if left blank
        if (!$pin) {
            $pin = $this->pinService->generateUniquePin($org);
        } else {
            // Validate user-provided PIN format: exactly [ABC][0-9]{3}
            if (!preg_match('/^[ABC][0-9]{3}$/', $pin)) {
                $this->addFlash('danger', 'Invalid PIN format. PIN must be exactly 4 characters: A, B, or C followed by 3 digits (e.g. A123, B045, C999).');
                return $this->redirectToRoute('app_manager_users');
            }

            // Enforce PIN uniqueness within organization
            if ($this->pinService->isPinTaken($org, $pin)) {
                $this->addFlash("danger", "PIN '" . $pin . "' is already assigned to another user.");
                return $this->redirectToRoute('app_manager_users');
            }
        }

        $orgUser = new OrgUser();
        $orgUser->setOrganization($org);
        $orgUser->setFirstName($first);
        $orgUser->setLastName($last);
        if ($email) $orgUser->setEmail($email);
        if ($phone) $orgUser->setPhone($phone);
        $orgUser->setIsActive($isActive);
        $orgUser->setPinHash($this->pinService->hashPin($pin));
        $orgUser->setPin($pin);

        $em->persist($orgUser);
        $em->flush();

        $this->logAudit($em, $org, 'USER_CREATE', ['name' => "{$first} {$last}"], $request);
        
        $this->eventDispatcher->dispatch(new \App\Event\UserUpdatedEvent(
            $orgUser,
            'create',
            [],
            new \DateTimeImmutable()
        ), \App\Event\UserUpdatedEvent::NAME);

        if ($isActive) {
            $this->addFlash('success', "User {$first} {$last} added successfully with PIN: <strong>{$pin}</strong>");
        } else {
            $this->addFlash('warning', "User {$first} {$last} added as <strong>Inactive</strong> due to active user quota. PIN: <strong>{$pin}</strong>");
        }

        return $this->redirectToRoute('app_manager_users');
    }

    #[Route('/users/edit/{id}', name: 'app_manager_user_edit', methods: ['POST'])]
    public function editOrgUser(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $orgUser = $em->getRepository(OrgUser::class)->find($id);
        if (!$orgUser || $orgUser->getOrganization()->getId() !== $org->getId()) {
            $this->addFlash('danger', 'User not found.');
            return $this->redirectToRoute('app_manager_users');
        }

        $first = trim($request->request->get('first_name', ''));
        $last  = trim($request->request->get('last_name', ''));
        $email = trim($request->request->get('email', ''));
        $phone = trim($request->request->get('phone', ''));
        $pin   = strtoupper(trim($request->request->get('pin', '')));

        $changedFields = [];
        if ($first && $orgUser->getFirstName() !== $first) {
            $changedFields['firstName'] = ['old' => $orgUser->getFirstName(), 'new' => $first];
            $orgUser->setFirstName($first);
        }
        if ($last && $orgUser->getLastName() !== $last) {
            $changedFields['lastName'] = ['old' => $orgUser->getLastName(), 'new' => $last];
            $orgUser->setLastName($last);
        }
        if ($orgUser->getEmail() !== ($email ?: null)) {
            $changedFields['email'] = ['old' => $orgUser->getEmail(), 'new' => ($email ?: null)];
            $orgUser->setEmail($email ?: null);
        }
        if ($orgUser->getPhone() !== ($phone ?: null)) {
            $changedFields['phone'] = ['old' => $orgUser->getPhone(), 'new' => ($phone ?: null)];
            $orgUser->setPhone($phone ?: null);
        }

        if ($pin) {
            if (!preg_match('/^[ABC][0-9]{3}$/', $pin)) {
                $this->addFlash('danger', 'Invalid PIN format. Must be [A/B/C] + 3 digits.');
                return $this->redirectToRoute('app_manager_users');
            }
            if ($this->pinService->isPinTaken($org, $pin, $orgUser->getId())) {
                $this->addFlash("danger", "PIN '" . $pin . "' is already assigned to another user.");
                return $this->redirectToRoute('app_manager_users');
            }
            if ($orgUser->getPin() !== $pin) {
                $changedFields['pin'] = ['old' => $orgUser->getPin(), 'new' => $pin];
            }
            $orgUser->setPin($pin);
            $orgUser->setPinHash($this->pinService->hashPin($pin));
        }

        $orgUser->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->logAudit($em, $org, 'USER_EDIT', [
            'orgUser_id' => $orgUser->getId(),
            'changed_fields' => $changedFields
        ], $request);

        $this->eventDispatcher->dispatch(new \App\Event\UserUpdatedEvent(
            $orgUser,
            'edit',
            $changedFields,
            new \DateTimeImmutable()
        ), \App\Event\UserUpdatedEvent::NAME);

        $this->addFlash('success', "User {$orgUser->getFirstName()} {$orgUser->getLastName()} updated successfully.");
        return $this->redirectToRoute('app_manager_users');
    }

    #[Route('/users/edit-manager/{id}', name: 'app_manager_edit_manager', methods: ['POST'])]
    public function editManager(int $id, Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): Response
    {
        /** @var Manager $currentManager */
        $currentManager = $this->getUser();
        $org = $currentManager->getOrganization();

        $target = $em->getRepository(Manager::class)->find($id);
        if (!$target || $target->getOrganization()->getId() !== $org->getId()) {
            $this->addFlash('danger', 'Manager not found.');
            return $this->redirectToRoute('app_manager_users');
        }

        $first    = trim($request->request->get('first_name', ''));
        $last     = trim($request->request->get('last_name', ''));
        $email    = trim($request->request->get('email', ''));
        $phone    = trim($request->request->get('phone', ''));
        $password = $request->request->get('password', '');

        if (!$first || !$last || !$email || !$phone) {
            $this->addFlash('danger', 'First name, last name, email, and phone number are required.');
            return $this->redirectToRoute('app_manager_users');
        }

        $target->setFirstName($first);
        $target->setLastName($last);
        $target->setEmail($email);
        $target->setPhone($phone);

        $passwordChanged = false;
        if ($password && strlen($password) >= 8) {
            $target->setPasswordHash($passwordHasher->hashPassword($target, $password));
            $passwordChanged = true;
        }

        $target->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        if ($passwordChanged) {
            $this->logAudit($em, $org, 'PASSWORD_CHANGE_OTHER', [
                'manager_id' => $target->getId(),
                'target_email' => $target->getEmail(),
                'changed_by' => $currentManager->getEmail()
            ], $request);
            $this->eventDispatcher->dispatch(new \App\Event\PasswordChangedEvent(
                $currentManager,
                $target,
                new \DateTimeImmutable()
            ), \App\Event\PasswordChangedEvent::NAME);
        } else {
            $this->logAudit($em, $org, 'MANAGER_EDIT', ['manager_id' => $target->getId()], $request);
        }

        $this->addFlash('success', "Manager {$target->getFirstName()} {$target->getLastName()} updated successfully.");
        return $this->redirectToRoute('app_manager_users');
    }


    #[Route('/users/add-manager', name: 'app_manager_add_manager', methods: ['POST'])]
    public function addManager(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): Response
    {
        /** @var Manager $currentManager */
        $currentManager = $this->getUser();
        $org = $currentManager->getOrganization();

        $isActive = $this->licenseService->isNextManagerAllowed($org);

        $first = $request->request->get('first_name');
        $last = $request->request->get('last_name');
        $email = $request->request->get('email');
        $password = $request->request->get('password');
        $phone = $request->request->get('phone');

        if (!$first || !$last || !$email || !$password || !$phone) {
            $this->addFlash('danger', 'All manager fields, including phone number, are required.');
            return $this->redirectToRoute('app_manager_users');
        }

        // Check if email already exists
        $existingManager = $em->getRepository(Manager::class)->findOneBy(['email' => $email]);
        if ($existingManager) {
            $this->addFlash('danger', 'A manager with this email address already exists.');
            return $this->redirectToRoute('app_manager_users');
        }

        $newManager = new Manager();
        $newManager->setOrganization($org);
        $newManager->setFirstName($first);
        $newManager->setLastName($last);
        $newManager->setEmail($email);
        $newManager->setPhone($phone);
        $newManager->setRole('ROLE_ORG_MANAGER');
        $newManager->setIsActive($isActive);
        $newManager->setPasswordHash($passwordHasher->hashPassword($newManager, $password));

        $em->persist($newManager);
        $em->flush();

        $this->logAudit($em, $org, 'MANAGER_CREATE', ['email' => $email], $request);
        
        if ($isActive) {
            $this->addFlash('success', "Manager {$first} {$last} added successfully.");
        } else {
            $this->addFlash('warning', "Manager {$first} {$last} added as Inactive due to active manager quota.");
        }

        return $this->redirectToRoute('app_manager_users');
    }

    #[Route('/users/import', name: 'app_manager_users_import', methods: ['GET', 'POST'])]
    public function importWizard(Request $request, EntityManagerInterface $em, \App\Service\UserMatchingService $userMatchingService, \App\Service\AppConfigService $configService): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        if ($org->isArchived()) {
            $this->addFlash('warning', 'Your organization has been archived. CSV imports are disabled.');
            return $this->redirectToRoute('app_manager_billing');
        }

        $limitsData = $this->getOrgLimits($org, $em);
        $session = $request->getSession();

        // Allow unlimited execution time for large CSV imports
        set_time_limit(0);
        ini_set('memory_limit', '256M');

        if ($request->isMethod('POST')) {
            $step = (int)$request->request->get('step', 1);
            $action = $request->request->get('wizard_action', 'next');

            if ($action === 'cancel') {
                $session->remove('import_headers');
                $session->remove('import_rows');
                $session->remove('import_analysis_results');
                $session->remove('import_actions');
                $session->remove('import_missing_users');
                $session->remove('import_missing_users_action');
                $session->remove('import_enable_inactive');
                return $this->redirectToRoute('app_manager_users_import');
            }

            if ($step === 1) {
                // Handle file upload
                $file = $request->files->get('import_file');
                if (!$file) {
                    $this->addFlash('danger', 'Please upload a CSV file.');
                    return $this->redirectToRoute('app_manager_users_import');
                }

                $filePath = $file->getRealPath();
                $rows = [];
                if (($handle = fopen($filePath, "r")) !== FALSE) {
                    while (($data = fgetcsv($handle, 1000, ",", '"', '\\')) !== FALSE) {
                        $rows[] = $data;
                    }
                    fclose($handle);
                }

                if (count($rows) < 2) {
                    $this->addFlash('danger', 'The uploaded file has no records.');
                    return $this->redirectToRoute('app_manager_users_import');
                }
                $maxRecords = (int)$configService->get('max_import_records', 100);
                if (count($rows) > $maxRecords + 1) { // +1 for the header row
                    $this->addFlash('danger', "Import size exceeded. Maximum {$maxRecords} records allowed per import. Please split your file into smaller batches.");
                    return $this->redirectToRoute('app_manager_users_import');
                }

                $headers = array_map('trim', $rows[0]);
                $dataRows = array_slice($rows, 1);

                $session->set('import_headers', $headers);
                $session->set('import_rows', $dataRows);

                return $this->render('manager/import_wizard.html.twig', [
                    'step' => 2,
                    'headers' => $headers,
                    'previewRows' => array_slice($dataRows, 0, 5),
                ]);

            } elseif ($step === 2) {
                $uniqueColIndex = $request->request->get('unique_col', ''); 
                $mappings = $request->request->all('mappings');
                
                $headers = $session->get('import_headers');
                $dataRows = $session->get('import_rows');

                $mappedCustomFields = 0;
                foreach ($mappings as $field => $index) {
                    if ($index !== '' && !in_array($field, ['first_name', 'last_name', 'email', 'phone', 'pin'])) {
                        $mappedCustomFields++;
                    }
                }
                $maxFields = $org->getMaxFields();
                if (!$this->licenseService->isCustomFieldsCountAllowed($org, $mappedCustomFields)) {
                    $this->addFlash('danger', "Your plan limits custom fields to {$maxFields}. You mapped {$mappedCustomFields} custom fields. Please downgrade mapping or upgrade plan.");
                    return $this->render('manager/import_wizard.html.twig', [
                        'step' => 2,
                        'headers' => $headers,
                        'previewRows' => array_slice($dataRows, 0, 5),
                    ]);
                }

                $results = [];
                $dbOrgUsers = $em->getRepository(OrgUser::class)->findBy(['organization' => $org]);
                
                $empMapByHash = [];
                $empMapByEmail = [];
                $empMapByPhone = [];

                foreach ($dbOrgUsers as $dbEmp) {
                    if ($dbEmp->getUniqueIdHash()) {
                        $empMapByHash[$dbEmp->getUniqueIdHash()] = $dbEmp;
                    }
                    if ($dbEmp->getEmail()) {
                        $empMapByEmail[strtolower($dbEmp->getEmail())][] = $dbEmp;
                    }
                    if ($dbEmp->getPhone()) {
                        $empMapByPhone[strtolower($dbEmp->getPhone())][] = $dbEmp;
                    }
                }

                $matchedDbUserIds = [];

                foreach ($dataRows as $index => $row) {
                    $uniqueVal = $uniqueColIndex !== '' ? trim($row[$uniqueColIndex] ?? '') : '';

                    $emailIndex = $mappings['email'] ?? '';
                    $rowEmail = $emailIndex !== '' ? trim($row[$emailIndex] ?? '') : '';
                    
                    $firstNameIndex = $mappings['first_name'] ?? '';
                    $rowFirst = $firstNameIndex !== '' ? trim($row[$firstNameIndex] ?? '') : '';
                    
                    $lastNameIndex = $mappings['last_name'] ?? '';
                    $rowLast = $lastNameIndex !== '' ? trim($row[$lastNameIndex] ?? '') : '';

                    $phoneIndex = $mappings['phone'] ?? '';
                    $rowPhone = $phoneIndex !== '' ? trim($row[$phoneIndex] ?? '') : '';

                    $pinIndex = $mappings['pin'] ?? '';
                    $rowPin = $pinIndex !== '' ? trim($row[$pinIndex] ?? '') : '0000';

                    $customData = [];
                    foreach ($mappings as $field => $colIndex) {
                        if ($colIndex !== '' && !in_array($field, ['first_name', 'last_name', 'email', 'phone', 'pin'])) {
                            $customData[$field] = trim($row[$colIndex] ?? '');
                        }
                    }

                    $matchResult = $userMatchingService->findMatchAndHash(
                        $org,
                        $uniqueVal,
                        $rowFirst,
                        $rowLast,
                        $rowEmail,
                        $rowPhone,
                        $empMapByHash,
                        $empMapByEmail,
                        $empMapByPhone
                    );

                    $matchedOrgUser = $matchResult['matchedUser'];
                    $matchType = $matchResult['matchType'];
                    $uniqueHash = $matchResult['uniqueHash'];

                    if ($matchedOrgUser) {
                        $matchedDbUserIds[] = $matchedOrgUser->getId();
                    }

                    $results[] = [
                        'row_index' => $index,
                        'first_name' => $rowFirst,
                        'last_name' => $rowLast,
                        'email' => $rowEmail,
                        'phone' => $rowPhone,
                        'pin' => $rowPin,
                        'custom' => $customData,
                        'match_type' => $matchType,
                        'unique_hash' => $uniqueHash,
                        'matched_orgUser_id' => $matchedOrgUser ? $matchedOrgUser->getId() : null,
                        'matched_orgUser_name' => $matchedOrgUser ? ($matchedOrgUser->getFirstName().' '.$matchedOrgUser->getLastName()) : null,
                        'matched_orgUser_is_active' => $matchedOrgUser ? $matchedOrgUser->isActive() : null,
                    ];
                }

                $session->set('import_analysis_results', $results);

                // Compute missing users
                $missingUsers = [];
                foreach ($dbOrgUsers as $dbEmp) {
                    if (!in_array($dbEmp->getId(), $matchedDbUserIds)) {
                        $missingUsers[] = [
                            'id' => $dbEmp->getId(),
                            'name' => $dbEmp->getFirstName() . ' ' . $dbEmp->getLastName(),
                            'email' => $dbEmp->getEmail(),
                            'is_active' => $dbEmp->isActive(),
                        ];
                    }
                }
                $session->set('import_missing_users', $missingUsers);

                return $this->render('manager/import_wizard.html.twig', [
                    'step' => 3,
                    'results' => $results,
                ]);

            } elseif ($step === 3) {
                // Process Step 3
                $actions = $request->request->all('actions');
                $enableInactive = $request->request->get('enable_inactive', false) !== false;

                $session->set('import_actions', $actions);
                $session->set('import_enable_inactive', $enableInactive);

                $missingUsers = $session->get('import_missing_users', []);

                return $this->render('manager/import_wizard.html.twig', [
                    'step' => 4,
                    'missingUsers' => $missingUsers,
                ]);

            } elseif ($step === 4) {
                // Process Step 4
                $missingAction = $request->request->get('missing_action', 'keep');
                $session->set('import_missing_users_action', $missingAction);

                $results = $session->get('import_analysis_results', []);
                $actions = $session->get('import_actions', []);
                $missingUsers = $session->get('import_missing_users', []);
                $enableInactive = $session->get('import_enable_inactive', false);
                
                $dbOrgUsers = $em->getRepository(OrgUser::class)->findBy(['organization' => $org]);
                
                $disabledByMissing = [];
                if ($missingAction === 'disable') {
                    foreach ($missingUsers as $mu) {
                        $disabledByMissing[] = $mu['id'];
                    }
                }

                $simulatedDbState = [];
                foreach ($dbOrgUsers as $dbEmp) {
                    $simulatedDbState[$dbEmp->getId()] = $dbEmp->isActive();
                }

                foreach ($disabledByMissing as $id) {
                    $simulatedDbState[$id] = false;
                }

                $newActiveCount = 0;
                foreach ($results as $item) {
                    $idx = $item['row_index'];
                    $act = $actions[$idx] ?? 'skip';

                    if ($act === 'skip') continue;

                    if ($act === 'add' || ($act === 'update' && !$item['matched_orgUser_id'])) {
                        $newActiveCount++;
                    } else if ($act === 'update' && $item['matched_orgUser_id']) {
                        if ($enableInactive && !$item['matched_orgUser_is_active']) {
                            $simulatedDbState[$item['matched_orgUser_id']] = true;
                        }
                    }
                }

                $finalExistingActiveCount = 0;
                foreach ($simulatedDbState as $id => $isActive) {
                    if ($isActive) $finalExistingActiveCount++;
                }

                $projectedActiveTotal = $finalExistingActiveCount + $newActiveCount;
                $maxLimit = $limitsData['limits']['max_orgUsers'];
                $isOverLimit = $projectedActiveTotal > $maxLimit;

                return $this->render('manager/import_wizard.html.twig', [
                    'step' => 5,
                    'projectedActiveTotal' => $projectedActiveTotal,
                    'maxLimit' => $maxLimit,
                    'isOverLimit' => $isOverLimit,
                    'newActiveCount' => $newActiveCount,
                    'finalExistingActiveCount' => $finalExistingActiveCount,
                    'limitsData' => $limitsData,
                ]);

            } elseif ($step === 5) {
                // Final execution
                $overQuotaAction = $request->request->get('over_quota_action');
                if ($request->request->get('wizard_action') === 'abort') {
                    // Similar to cancel
                    $session->remove('import_headers');
                    $session->remove('import_rows');
                    $session->remove('import_analysis_results');
                    $session->remove('import_actions');
                    $session->remove('import_missing_users');
                    $session->remove('import_missing_users_action');
                    $session->remove('import_enable_inactive');
                    return $this->redirectToRoute('app_manager_users_import');
                }

                $results = $session->get('import_analysis_results', []);
                $actions = $session->get('import_actions', []);
                $missingUsersAction = $session->get('import_missing_users_action');
                $enableInactive = $session->get('import_enable_inactive', false);
                $missingUsers = $session->get('import_missing_users', []);
                $maxLimit = $limitsData['limits']['max_orgUsers'];

                $disabledMissingCount = 0;
                $importedCount = 0;
                $updatedCount = 0;
                $pinSkippedCount = 0;
                $newActiveCount = 0;
                $newInactiveCount = 0;
                $existingEnabledCount = 0;

                $em->wrapInTransaction(function() use (
                    $em, $org, $missingUsersAction, $missingUsers,
                    &$disabledMissingCount, &$importedCount, &$updatedCount, &$pinSkippedCount,
                    &$newActiveCount, &$newInactiveCount, &$existingEnabledCount,
                    $results, $actions, $enableInactive, $maxLimit, $overQuotaAction
                ) {
                    if ($missingUsersAction === 'disable') {
                        foreach ($missingUsers as $mu) {
                            $orgUser = $em->getRepository(OrgUser::class)->find($mu['id']);
                            if ($orgUser && $orgUser->getOrganization()->getId() === $org->getId()) {
                                if ($orgUser->isActive()) {
                                    $orgUser->setIsActive(false);
                                    $disabledMissingCount++;
                                }
                            }
                        }
                        $em->flush();
                    }

                    $usedPinsThisImport = [];
                    $importedActiveCountInThisBatch = 0;
                    
                    foreach ($results as $item) {
                        $idx = $item['row_index'];
                        $act = $actions[$idx] ?? 'skip';

                        if ($act === 'skip') continue;

                        if ($act === 'add' || ($act === 'update' && !$item['matched_orgUser_id'])) {
                            
                            $plainPin = strtoupper(trim($item['pin'] ?: ''));
                            if (!preg_match('/^[ABC][0-9]{3}$/', $plainPin)) {
                                $plainPin = $this->pinService->generateUniquePin($org, null, $usedPinsThisImport);
                            }

                            if ($this->pinService->isPinTaken($org, $plainPin) || in_array($plainPin, $usedPinsThisImport, true)) {
                                $pinSkippedCount++;
                                continue;
                            }
                            $usedPinsThisImport[] = $plainPin;

                            $orgUser = new OrgUser();
                            $orgUser->setOrganization($org);
                            $orgUser->setFirstName($item['first_name']);
                            $orgUser->setLastName($item['last_name']);
                            if ($item['email']) $orgUser->setEmail($item['email']);
                            if ($item['phone']) $orgUser->setPhone($item['phone']);
                            $orgUser->setUniqueIdHash($item['unique_hash']);
                            
                            // Determine active status using LicenseService
                            $isActive = $this->licenseService->isNextUserActiveAllowed($org, $importedActiveCountInThisBatch);
                            if ($isActive) {
                                $importedActiveCountInThisBatch++;
                                $newActiveCount++;
                            } else {
                                $newInactiveCount++;
                            }
                            
                            $orgUser->setIsActive($isActive);

                            $orgUser->setPinHash($this->pinService->hashPin($plainPin));
                            $orgUser->setPin($plainPin);
                            $orgUser->setCustomFieldsJson($item['custom']);

                            $em->persist($orgUser);
                            $importedCount++;

                        } elseif ($act === 'update' && $item['matched_orgUser_id']) {
                            $orgUser = $em->getRepository(OrgUser::class)->find($item['matched_orgUser_id']);
                            if ($orgUser && $orgUser->getOrganization()->getId() === $org->getId()) {
                                $orgUser->setFirstName($item['first_name']);
                                $orgUser->setLastName($item['last_name']);
                                if ($item['email']) $orgUser->setEmail($item['email']);
                                if ($item['phone']) $orgUser->setPhone($item['phone']);
                                $orgUser->setUniqueIdHash($item['unique_hash']);
                                
                                if ($enableInactive && !$orgUser->isActive()) {
                                    if ($this->licenseService->isNextUserActiveAllowed($org, $importedActiveCountInThisBatch)) {
                                        $orgUser->setIsActive(true);
                                        $importedActiveCountInThisBatch++;
                                        $existingEnabledCount++;
                                    }
                                }

                                if ($item['pin']) {
                                    $plainPin = strtoupper(trim($item['pin']));
                                    if (preg_match('/^[ABC][0-9]{3}$/', $plainPin)
                                        && !$this->pinService->isPinTaken($org, $plainPin, $orgUser->getId())
                                        && !in_array($plainPin, $usedPinsThisImport, true)) {
                                        $orgUser->setPinHash($this->pinService->hashPin($plainPin));
                                        $orgUser->setPin($plainPin);
                                        $usedPinsThisImport[] = $plainPin;
                                    }
                                }
                                $merged = array_merge($orgUser->getCustomFieldsJson(), $item['custom']);
                                $orgUser->setCustomFieldsJson($merged);
                                $orgUser->setUpdatedAt(new \DateTimeImmutable());

                                $updatedCount++;
                            }
                        }

                        if (($importedCount + $updatedCount) % 50 === 0) {
                            $em->flush();
                        }
                    }

                    $em->flush(); 
                });

                $this->logAudit($em, $org, 'USER_IMPORT', [
                    'added' => $importedCount,
                    'updated' => $updatedCount,
                    'new_active' => $newActiveCount,
                    'new_inactive' => $newInactiveCount,
                    'existing_enabled' => $existingEnabledCount,
                    'disabled_missing' => $disabledMissingCount,
                    'total_enabled' => $newActiveCount + $existingEnabledCount,
                    'total_disabled' => $newInactiveCount + $disabledMissingCount,
                ], $request);

                $this->eventDispatcher->dispatch(new \App\Event\UserImportEvent(
                    $org,
                    $importedCount + $updatedCount,
                    $updatedCount,
                    $newActiveCount + $existingEnabledCount,
                    $newInactiveCount + $disabledMissingCount,
                    new \DateTimeImmutable()
                ), \App\Event\UserImportEvent::NAME);
                $msg = "Import completed: {$importedCount} added, {$updatedCount} updated.";
                if ($disabledMissingCount > 0) $msg .= " {$disabledMissingCount} missing users disabled.";
                if ($pinSkippedCount > 0) $msg .= " {$pinSkippedCount} skipped due to duplicate PIN.";

                $this->addFlash('success', $msg);
                
                $session->remove('import_headers');
                $session->remove('import_rows');
                $session->remove('import_analysis_results');
                $session->remove('import_actions');
                $session->remove('import_missing_users');
                $session->remove('import_missing_users_action');
                $session->remove('import_enable_inactive');

                return $this->redirectToRoute('app_manager_users');
            }
        }

        return $this->render('manager/import_wizard.html.twig', [
            'step' => 1,
        ]);
    }

    #[Route('/reports', name: 'app_manager_reports')]
    public function reports(Request $request, EntityManagerInterface $em, SessionSweeperService $sessionSweeper): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $sessionSweeper->sweepActiveSessions($org);

        $limitsData = $this->getOrgLimits($org, $em);

        $startDateStr = $request->query->get('start_date');
        $endDateStr = $request->query->get('end_date');
        $orgUserId = $request->query->get('org_user_id');
        $categoryId = $request->query->get('category_id');

        $qb = $em->createQueryBuilder();
        $qb->select('s')
            ->from(WorkSession::class, 's')
            ->where('s.organization = :org')
            ->setParameter('org', $org);

        if ($startDateStr) {
            $qb->andWhere('s.startedAt >= :start')
                ->setParameter('start', new \DateTime($startDateStr . ' 00:00:00'));
        }
        if ($endDateStr) {
            $qb->andWhere('s.startedAt <= :end')
                ->setParameter('end', new \DateTime($endDateStr . ' 23:59:59'));
        }
        if ($orgUserId) {
            $qb->andWhere('s.orgUser = :orgUser')
                ->setParameter('orgUser', $orgUserId);
        }
        if ($categoryId) {
            $qb->andWhere('s.category = :category')
                ->setParameter('category', $categoryId);
        }

        $qb->orderBy('s.startedAt', 'DESC');
        $sessions = $qb->getQuery()->getResult();

        // Rollups
        $totalSeconds = 0;
        $outOfWindowCount = 0;
        
        foreach ($sessions as $s) {
            $totalSeconds += $s->getDurationSeconds() ?? 0;
            if ($s->isOutOfWindow()) $outOfWindowCount++;
        }

        $hours = floor($totalSeconds / 3600);
        $mins = floor(($totalSeconds % 3600) / 60);

        // Fetch filter dropdown options
        $orgUsers = $em->getRepository(OrgUser::class)->findBy(['organization' => $org], ['firstName' => 'ASC', 'lastName' => 'ASC']);
        $categories = $em->getRepository(Category::class)->findBy(['organization' => $org]);

        return $this->render('manager/reports.html.twig', [
            'sessions' => $sessions,
            'orgUsers' => $orgUsers,
            'categories' => $categories,
            'totalHours' => "{$hours}h {$mins}m",
            'outOfWindowCount' => $outOfWindowCount,
            'limitsData' => $limitsData,
            'org' => $org,
        ]);
    }

    #[Route('/reports/export', name: 'app_manager_reports_export')]
    public function exportCSV(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        // Enforce export policy gate (Silver/Gold only)
        $limitsData = $this->getOrgLimits($org, $em);
        if (!$limitsData['limits']['exports']) {
            $this->addFlash('danger', 'Data export is not available on your plan. Upgrade to Silver or Gold to unlock.');
            return $this->redirectToRoute('app_manager_reports');
        }

        $startDateStr = $request->query->get('start_date');
        $endDateStr = $request->query->get('end_date');
        $orgUserId = $request->query->get('org_user_id');
        $categoryId = $request->query->get('category_id');

        $qb = $em->createQueryBuilder();
        $qb->select('s')
            ->from(WorkSession::class, 's')
            ->where('s.organization = :org')
            ->setParameter('org', $org);

        if ($startDateStr) {
            $qb->andWhere('s.startedAt >= :start')
                ->setParameter('start', new \DateTime($startDateStr . ' 00:00:00'));
        }
        if ($endDateStr) {
            $qb->andWhere('s.startedAt <= :end')
                ->setParameter('end', new \DateTime($endDateStr . ' 23:59:59'));
        }
        if ($orgUserId) {
            $qb->andWhere('s.orgUser = :orgUser')
                ->setParameter('orgUser', $orgUserId);
        }
        if ($categoryId) {
            $qb->andWhere('s.category = :category')
                ->setParameter('category', $categoryId);
        }

        $qb->orderBy('s.startedAt', 'DESC');
        $sessions = $qb->getQuery()->getResult();

        // Format CSV response
        $rows = [];
        $rows[] = ['Session ID', 'OrgUser Name', 'Email', 'Category', 'Started At', 'Ended At', 'Duration (Hours)', 'Start Method', 'Is Out Of Window', 'Device Info'];

        foreach ($sessions as $s) {
            $durationHours = $s->getDurationSeconds() ? round($s->getDurationSeconds() / 3600, 2) : 0;
            $rows[] = [
                $s->getId(),
                $s->getOrgUser()->getFirstName() . ' ' . $s->getOrgUser()->getLastName(),
                $s->getOrgUser()->getEmail() ?? 'N/A',
                $s->getCategory() ? $s->getCategory()->getName() : 'General',
                $s->getStartedAt()->format('Y-m-d H:i:s'),
                $s->getEndedAt() ? $s->getEndedAt()->format('Y-m-d H:i:s') : 'Active',
                $durationHours,
                $s->getStartMethod(),
                $s->isOutOfWindow() ? 'Yes' : 'No',
                $s->getDeviceInfo() ?? 'N/A'
            ];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'export');
        $fp = fopen($tempFile, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row, ',', '"', '\\');
        }
        fclose($fp);

        $response = new Response(file_get_contents($tempFile));
        unlink($tempFile);

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $org->getSlug() . '_time_logs_' . date('Ymd') . '.csv'
        );

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', $disposition);

        $this->logAudit($em, $org, 'EXPORT_REPORTS', ['count' => count($sessions) - 1], $request);

        return $response;
    }

    #[Route('/reports/by-user', name: 'app_manager_reports_by_user')]
    public function reportsByUser(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $orgUserId = $request->query->get('org_user_id');
        $monthStr = $request->query->get('month', date('Y-m'));
        $view = $request->query->get('view', 'calendar');
        if (!in_array($view, ['calendar', 'list'])) {
            $view = 'calendar';
        }

        $orgUsers = $em->getRepository(OrgUser::class)->findBy(['organization' => $org], ['firstName' => 'ASC', 'lastName' => 'ASC']);
        
        $selectedUser = null;
        $calendarData = [];
        $totalMonthSeconds = 0;
        
        $startOfMonth = new \DateTime($monthStr . '-01 00:00:00');
        $daysInMonth = (int) $startOfMonth->format('t');
        $startDayOfWeek = (int) $startOfMonth->format('w'); // 0 (Sunday) to 6 (Saturday)

        if ($orgUserId) {
            $selectedUser = $em->getRepository(OrgUser::class)->findOneBy([
                'id' => $orgUserId,
                'organization' => $org
            ]);
        }

        if ($selectedUser) {
            $endOfMonth = clone $startOfMonth;
            $endOfMonth->modify('last day of this month')->setTime(23, 59, 59);

            $qb = $em->createQueryBuilder();
            $qb->select('s')
                ->from(WorkSession::class, 's')
                ->where('s.orgUser = :orgUser')
                ->andWhere('s.startedAt >= :start')
                ->andWhere('s.startedAt <= :end')
                ->setParameter('orgUser', $selectedUser)
                ->setParameter('start', $startOfMonth)
                ->setParameter('end', $endOfMonth)
                ->orderBy('s.startedAt', 'ASC');

            $sessions = $qb->getQuery()->getResult();

            for ($i = 1; $i <= $daysInMonth; $i++) {
                $dateKey = $startOfMonth->format('Y-m-') . str_pad($i, 2, '0', STR_PAD_LEFT);
                $calendarData[$dateKey] = [
                    'day' => $i,
                    'totalSeconds' => 0,
                    'sessions' => []
                ];
            }

            foreach ($sessions as $session) {
                $dateKey = $session->getStartedAt()->format('Y-m-d');
                if (isset($calendarData[$dateKey])) {
                    $dur = $session->getDurationSeconds() ?? 0;
                    $calendarData[$dateKey]['totalSeconds'] += $dur;
                    $calendarData[$dateKey]['sessions'][] = $session;
                    $totalMonthSeconds += $dur;
                }
            }
        }

        $prevMonth = (new \DateTime($monthStr . '-01'))->modify('-1 month')->format('Y-m');
        $nextMonth = (new \DateTime($monthStr . '-01'))->modify('+1 month')->format('Y-m');

        return $this->render('manager/reports_by_user.html.twig', [
            'orgUsers' => $orgUsers,
            'selectedUser' => $selectedUser,
            'calendarData' => $calendarData,
            'currentMonth' => $monthStr,
            'prevMonth' => $prevMonth,
            'nextMonth' => $nextMonth,
            'daysInMonth' => $daysInMonth,
            'startDayOfWeek' => $startDayOfWeek,
            'totalMonthSeconds' => $totalMonthSeconds,
            'monthName' => $startOfMonth->format('F Y'),
            'view' => $view,
        ]);
    }

    #[Route('/reports/by-user/export', name: 'app_manager_reports_by_user_export')]
    public function exportUserReportCSV(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        // Enforce export policy gate (Silver/Gold only)
        $limitsData = $this->getOrgLimits($org, $em);
        if (!$limitsData['limits']['exports']) {
            $this->addFlash('danger', 'Data export is not available on your plan. Upgrade to Silver or Gold to unlock.');
            return $this->redirectToRoute('app_manager_reports_by_user');
        }

        $userId = $request->query->get('org_user_id');
        $monthStr = $request->query->get('month'); // e.g., '2026-07'

        if (!$userId || !$monthStr) {
            $this->addFlash('danger', 'Invalid parameters for export.');
            return $this->redirectToRoute('app_manager_reports_by_user');
        }

        $selectedUser = $em->getRepository(OrgUser::class)->findOneBy(['id' => $userId, 'organization' => $org]);
        if (!$selectedUser) {
            $this->addFlash('danger', 'User not found.');
            return $this->redirectToRoute('app_manager_reports_by_user');
        }

        try {
            $startDate = new \DateTime($monthStr . '-01 00:00:00');
            $endDate = clone $startDate;
            $endDate->modify('last day of this month')->setTime(23, 59, 59);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Invalid month format.');
            return $this->redirectToRoute('app_manager_reports_by_user');
        }

        $qb = $em->createQueryBuilder();
        $qb->select('s')
           ->from(WorkSession::class, 's')
           ->where('s.organization = :org')
           ->andWhere('s.orgUser = :user')
           ->andWhere('s.startedAt >= :start')
           ->andWhere('s.startedAt <= :end')
           ->setParameter('org', $org)
           ->setParameter('user', $selectedUser)
           ->setParameter('start', $startDate)
           ->setParameter('end', $endDate)
           ->orderBy('s.startedAt', 'ASC');

        $sessions = $qb->getQuery()->getResult();

        $rows = [];
        $rows[] = [
            'Day',
            'Date',
            'First Name',
            'Last Name',
            'Category',
            'Start Time',
            'End Time',
            'Duration (HH:MM)',
            'End Method',
            'Device Info'
        ];

        foreach ($sessions as $s) {
            $durationStr = '00:00';
            if ($s->getDurationSeconds()) {
                $hours = floor($s->getDurationSeconds() / 3600);
                $mins = floor(($s->getDurationSeconds() % 3600) / 60);
                $durationStr = sprintf('%02d:%02d', $hours, $mins);
            }

            $rows[] = [
                $s->getStartedAt()->format('l'),
                $s->getStartedAt()->format('Y-m-d'),
                $s->getOrgUser()->getFirstName(),
                $s->getOrgUser()->getLastName(),
                $s->getCategory() ? $s->getCategory()->getName() : 'General',
                $s->getStartedAt()->format('H:i:s'),
                $s->getEndedAt() ? $s->getEndedAt()->format('H:i:s') : 'Active',
                $durationStr,
                $s->getEndMethod() ?? 'Normal',
                $s->getDeviceInfo() ?? 'N/A'
            ];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'export_user');
        $fp = fopen($tempFile, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        $filename = sprintf('user_report_%s_%s_%s.csv', 
            strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $selectedUser->getFirstName())),
            strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $selectedUser->getLastName())),
            $startDate->format('Y_m')
        );

        $response = new Response(file_get_contents($tempFile));
        unlink($tempFile);

        $disposition = $response->headers->makeDisposition(
            \Symfony\Component\HttpFoundation\HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename
        );

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', $disposition);

        $this->logAudit($em, $org, 'EXPORT_USER_REPORTS', ['user_id' => $selectedUser->getId(), 'month' => $monthStr], $request);

        return $response;
    }

    #[Route('/billing', name: 'app_manager_billing', methods: ['GET', 'POST'])]
    public function billing(Request $request, EntityManagerInterface $em, \App\Service\EmailService $emailService): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        if ($request->query->get('checkout_success')) {
            $this->logAudit($em, $org, 'STRIPE_CHECKOUT_SUCCESS', [
                'customer_id' => $org->getStripeCustomerId()
            ], $request);
            $this->addFlash('success', 'Subscription checkout completed successfully! Your organization status will update automatically.');
        } elseif ($request->query->get('checkout_cancelled')) {
            // Revert to basic plan if checkout was cancelled
            $basicPlan = $em->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => 'basic']);
            if ($basicPlan) {
                $org->setPlan($basicPlan);
                $org->clearOverrides();
                $org->setIsSubscriptionActive(true);
                $em->flush();
            }
            $this->logAudit($em, $org, 'STRIPE_CHECKOUT_CANCELLED', [
                'reverted_to' => 'basic'
            ], $request);
            $this->addFlash('warning', 'Payment setup could not be completed with Stripe. Your organization has been set up with the Basic (Free) plan.');
        }

        // Handle plan changes submitted via POST
        if ($request->isMethod('POST')) {
            $targetPlanName = strtolower((string) $request->request->get('plan'));
            $targetPlan = $em->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => $targetPlanName]);
            $currentPlan = $org->getPlan();
            $stripeSecret = $this->getParameter('stripe_secret_key');

            if (!$targetPlan || $targetPlan->getName() === $currentPlan->getName()) {
                return $this->redirectToRoute('app_manager_billing');
            }

            // Case 2: Paid -> Basic (Period-End Cancellation)
            if ($targetPlan->getName() === 'basic') {
                $periodEnd = new \DateTime('+1 month');
                $scheduledAtPeriodEnd = false;

                if ($org->getStripeSubscriptionId() && !str_contains($stripeSecret, 'placeholder')) {
                    try {
                        \Stripe\Stripe::setApiKey($stripeSecret);
                        $stripeSub = \Stripe\Subscription::retrieve($org->getStripeSubscriptionId());
                        \Stripe\Subscription::update($org->getStripeSubscriptionId(), [
                            'cancel_at_period_end' => true
                        ]);
                        if (isset($stripeSub->current_period_end)) {
                            $periodEnd = (new \DateTime())->setTimestamp($stripeSub->current_period_end);
                        }
                        $scheduledAtPeriodEnd = true;
                    } catch (\Throwable $e) {
                        // Fallback if subscription missing
                    }
                }

                if ($scheduledAtPeriodEnd || $org->getStripeSubscriptionId()) {
                    $org->setPendingPlan($targetPlan);
                    $org->setPendingPlanEffectiveAt($periodEnd);
                    $em->flush();

                    // Format date in Organization's configured timezone
                    $tz = new \DateTimeZone($org->getTimezone() ?: 'UTC');
                    $formattedDate = (clone $periodEnd)->setTimezone($tz)->format('M d, Y');

                    $this->logAudit($em, $org, 'STRIPE_SUBSCRIPTION_CANCEL_SCHEDULED', [
                        'current_plan' => $currentPlan->getName(),
                        'scheduled_downgrade' => 'basic',
                        'effective_at' => $periodEnd->format(\DateTimeInterface::ATOM)
                    ], $request);

                    try {
                        $emailService->sendPlanChangeScheduledNotice($manager, 'basic', $formattedDate);
                    } catch (\Exception $e) {
                        // Silently ignore mailer failure
                    }

                    $this->addFlash('info', sprintf('Your cancellation to the Basic (Free) plan has been scheduled for %s. You will retain full access to your %s plan until then.', $formattedDate, ucfirst($currentPlan->getName())));
                } else {
                    $org->setPlan($targetPlan);
                    $org->clearOverrides();
                    $org->setAccessMode('open');
                    $org->setStripeSubscriptionId(null);
                    $org->setPendingPlan(null);
                    $org->setPendingPlanEffectiveAt(null);
                    $org->setIsSubscriptionActive(true);
                    $org->setUpdatedAt(new \DateTimeImmutable());

                    $payment = new \App\Entity\PaymentHistory();
                    $payment->setOrganization($org);
                    $payment->setStripeInvoiceId('cancel_' . bin2hex(random_bytes(6)));
                    $payment->setAmount('0.00');
                    $payment->setStatus('cancelled');
                    $payment->setPaidAt(new \DateTime());
                    $payment->setPeriodStart(new \DateTime());
                    $payment->setPeriodEnd(new \DateTime());
                    $payment->setDescription('Downgraded to Basic (Free) Plan');
                    $em->persist($payment);

                    $em->flush();

                    $this->logAudit($em, $org, 'PLAN_DOWNGRADE_BASIC', ['plan' => 'basic'], $request);

                    try {
                        $emailService->sendSubscriptionCancelledNotice($manager);
                    } catch (\Exception $e) {
                        // Silently ignore mailer failure
                    }

                    $this->addFlash('success', 'Your subscription has been cancelled. Your organization is now on the Basic (Free) plan.');
                }

                return $this->redirectToRoute('app_manager_billing');
            }

            // Case 4: Paid Higher -> Paid Lower (Period-End Paid Tier Downgrade)
            if ($currentPlan->getName() !== 'basic' && $targetPlan->getPriceMonthly() < $currentPlan->getPriceMonthly()) {
                $periodEnd = new \DateTime('+1 month');

                if ($org->getStripeSubscriptionId() && !str_contains($stripeSecret, 'placeholder')) {
                    try {
                        \Stripe\Stripe::setApiKey($stripeSecret);
                        $stripeSub = \Stripe\Subscription::retrieve($org->getStripeSubscriptionId());
                        if (isset($stripeSub->current_period_end)) {
                            $periodEnd = (new \DateTime())->setTimestamp($stripeSub->current_period_end);
                        }

                        // Create / Update Stripe Subscription Schedule for Phase 2
                        \Stripe\SubscriptionSchedule::create([
                            'from_subscription' => $org->getStripeSubscriptionId(),
                        ]);
                    } catch (\Throwable $e) {
                        // Silently catch fallback
                    }
                }

                $org->setPendingPlan($targetPlan);
                $org->setPendingPlanEffectiveAt($periodEnd);
                $em->flush();

                $tz = new \DateTimeZone($org->getTimezone() ?: 'UTC');
                $formattedDate = (clone $periodEnd)->setTimezone($tz)->format('M d, Y');

                $this->logAudit($em, $org, 'STRIPE_SUBSCRIPTION_CHANGE_SCHEDULED', [
                    'current_plan' => $currentPlan->getName(),
                    'scheduled_plan' => $targetPlan->getName(),
                    'effective_at' => $periodEnd->format(\DateTimeInterface::ATOM)
                ], $request);

                try {
                    $emailService->sendPlanChangeScheduledNotice($manager, $targetPlan->getName(), $formattedDate);
                } catch (\Exception $e) {
                    // Silently ignore mailer failure
                }

                $this->addFlash('info', sprintf('Your plan change to %s ($%s/mo) has been scheduled for %s. You will enjoy your %s plan until your current billing period ends.', ucfirst($targetPlan->getName()), round($targetPlan->getPriceMonthly()), $formattedDate, ucfirst($currentPlan->getName())));

                return $this->redirectToRoute('app_manager_billing');
            }

            // Case 1 & Case 3: Basic -> Paid OR Paid Lower -> Paid Higher (Immediate Upgrade via Stripe Checkout)
            return $this->redirectToRoute('app_stripe_checkout', ['planName' => $targetPlan->getName()]);
        }

        $payments = $em->getRepository(PaymentHistory::class)->findBy(
            ['organization' => $org],
            ['paidAt' => 'DESC']
        );

        $plans = $em->getRepository(\App\Entity\Plan::class)->findAll();

        return $this->render('manager/billing.html.twig', [
            'org' => $org,
            'payments' => $payments,
            'plans' => $plans,
        ]);
    }

    #[Route('/audit-logs', name: 'app_manager_audit_logs', methods: ['GET'])]
    public function auditLogs(EntityManagerInterface $em): Response
    {
        /** @var \App\Entity\Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();
        $logs = $em->getRepository(AuditLog::class)->findBy(
            ['organization' => $org],
            ['createdAt' => 'DESC'],
            200 // Limit to last 200 for manager view
        );

        return $this->render('manager/audit_logs.html.twig', [
            'logs' => $logs
        ]);
    }

    #[Route('/reports/force-end/{id}', name: 'app_manager_reports_force_end', methods: ['POST'])]
    public function forceEndSession(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $session = $em->getRepository(\App\Entity\WorkSession::class)->find($id);

        if ($session && $session->getOrgUser()->getOrganization()->getId() === $org->getId() && !$session->getEndedAt()) {
            $now = new \DateTime();
            $session->setEndedAt($now);
            $session->setEndMethod('Force End');
            
            $durationSeconds = $now->getTimestamp() - $session->getStartedAt()->getTimestamp();
            $session->setDurationSeconds($durationSeconds);

            $em->flush();

            $this->logAudit($em, $org, 'FORCE_END_SESSION', [
                'session_id' => $session->getId(),
                'user' => $session->getOrgUser()->getFirstName() . ' ' . $session->getOrgUser()->getLastName(),
                'forced_by' => $manager->getFirstName() . ' ' . $manager->getLastName() . ' (' . $manager->getEmail() . ')'
            ], $request);
            
            $this->addFlash('success', "Session for {$session->getOrgUser()->getFirstName()} {$session->getOrgUser()->getLastName()} has been forcefully ended.");
        }

        $referer = $request->headers->get('referer');
        return $referer ? $this->redirect($referer) : $this->redirectToRoute('app_manager_reports');
    }

    #[Route('/users/checked-in', name: 'app_manager_users_checked_in', methods: ['GET'])]
    public function checkedInUsers(EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $activeSessions = $em->getRepository(\App\Entity\WorkSession::class)->findBy(
            ['organization' => $org, 'endedAt' => null],
            ['startedAt' => 'DESC']
        );

        $sessionsData = [];
        foreach ($activeSessions as $session) {
            $user = $session->getOrgUser();
            $phone = $user->getPhone() ?? '';
            // Format phone to (xxx) xxx-xxxx if 10 digits
            $digits = preg_replace('/\D/', '', $phone);
            if (strlen($digits) === 10) {
                $formattedPhone = sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
            } else {
                $formattedPhone = $phone ?: '—';
            }

            $sessionsData[] = [
                'session'       => $session,
                'encodedPin'    => urlencode($user->getPin() ?? ''),
                'formattedPhone'=> $formattedPhone,
            ];
        }

        return $this->render('manager/checked_in_users.html.twig', [
            'activeSessions' => $activeSessions,
            'sessionsData'   => $sessionsData,
            'org'            => $org,
        ]);
    }
}
