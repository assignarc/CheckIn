<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\OrgUser;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\PaymentHistory;
use App\Entity\WorkSession;
use App\Entity\AuditLog;
use App\Service\PinService;
use App\Service\SessionSweeperService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/manager')]
class ManagerController extends AbstractController
{
    private PinService $pinService;

    public function __construct(PinService $pinService)
    {
        $this->pinService = $pinService;
    }

    private function getOrgLimits(Organization $org, EntityManagerInterface $em): array
    {
        $orgUserCount = $em->getRepository(OrgUser::class)->count(['organization' => $org]);
        $managerCount = $em->getRepository(Manager::class)->count(['organization' => $org]);
        $categoryCount = $em->getRepository(Category::class)->count(['organization' => $org]);
        
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

    #[Route('/settings', name: 'app_manager_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        if ($request->isMethod('POST')) {
            $section = $request->request->get('_section', 'policies');

            // ── Org Profile ──────────────────────────────────────────────
            if ($section === 'org_profile') {
                $orgName = trim($request->request->get('org_name', ''));
                if ($orgName) {
                    $org->setName($orgName);
                }
                $org->setPhone($request->request->get('org_phone') ?: null);
                $org->setAddress($request->request->get('org_address') ?: null);

                $logoFile = $request->files->get('org_logo');
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
                    }
                }

                $org->setUpdatedAt(new \DateTimeImmutable());
                $em->flush();
                $this->logAudit($em, $org, 'ORG_PROFILE_UPDATE', ['name' => $orgName], $request);
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

                if ($firstName) $manager->setFirstName($firstName);
                if ($lastName)  $manager->setLastName($lastName);
                if ($email)     $manager->setEmail($email);
                $manager->setPhone($phone ?: null);

                if ($password && strlen($password) >= 8) {
                    $manager->setPasswordHash($passwordHasher->hashPassword($manager, $password));
                }
                $manager->setUpdatedAt(new \DateTimeImmutable());
                $em->flush();
                $this->logAudit($em, $org, 'MANAGER_SELF_EDIT', ['manager_id' => $manager->getId()], $request);
                $this->addFlash('success', 'Your account information has been updated.');
                return $this->redirectToRoute('app_manager_settings');
            }

            // ── Policies (default) ───────────────────────────────────────
            $accessMode   = $request->request->get('access_mode', 'secure');
            $sessionLimit = (int)$request->request->get('session_limit_hours', 2);
            $windowStart  = $request->request->get('window_start');
            $windowEnd    = $request->request->get('window_end');

            $limitsData      = $this->getOrgLimits($org, $em);
            $maxAllowedHours = $limitsData['limits']['max_session_hours'];
            if ($sessionLimit > $maxAllowedHours) {
                $sessionLimit = $maxAllowedHours;
                $this->addFlash('warning', "Session duration limit capped at {$maxAllowedHours} hours by your plan.");
            }

            $org->setAccessMode($accessMode);
            $org->setSessionLimitHoursOverride($sessionLimit);
            $org->setCheckInWindowStart($windowStart ? new \DateTime($windowStart) : null);
            $org->setCheckInWindowEnd($windowEnd     ? new \DateTime($windowEnd)   : null);

            if ($org->getPlan()->hasOvertimeRules()) {
                $org->setOvertimeGraceMinutes((int)$request->request->get('overtime_grace', 0));
                $org->setOvertimeMultiplier($request->request->get('overtime_multiplier', '1.50'));
            }

            $maskLastName = $request->request->get('mask_last_name') === '1';
            $org->setMaskLastName($maskLastName);
            $org->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();

            $this->logAudit($em, $org, 'POLICY_CHANGE', [
                'access_mode'         => $accessMode,
                'session_limit_hours' => $sessionLimit,
                'window_start'        => $windowStart,
                'window_end'          => $windowEnd,
                'mask_last_name'      => $maskLastName,
            ], $request);

            $this->addFlash('success', 'Policies updated successfully.');
            return $this->redirectToRoute('app_manager_settings');
        }

        return $this->render('manager/settings.html.twig', [
            'org' => $org,
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

    #[Route('/categories', name: 'app_manager_categories', methods: ['GET', 'POST'])]
    public function categories(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $limitsData = $this->getOrgLimits($org, $em);
        $categories = $em->getRepository(Category::class)->findBy(['organization' => $org]);

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $icon = $request->request->get('icon', 'work');
            $color = $request->request->get('color', '#8b5cf6');
            $maxDurationInput = $request->request->get('max_session_duration');
            $terminateAtMax = $request->request->has('terminate_at_max_duration');

            // Plan category limit check
            if (count($categories) >= $limitsData['limits']['max_categories']) {
                $this->addFlash('danger', "Category limit reached. Your plan allows max {$limitsData['limits']['max_categories']} categories. Upgrade to unlock more.");
                return $this->redirectToRoute('app_manager_categories');
            }

            if ($name) {
                $category = new Category();
                $category->setOrganization($org);
                $category->setName($name);
                $category->setIcon($icon);
                $category->setIsActive(true);

                // Handle session duration overrides for non-basic plans
                if ($org->getPlan()->hasCustomSessionDurations()) {
                    if ($maxDurationInput !== null && $maxDurationInput !== '') {
                        $maxMinutes = $this->parseHrMinToMinutes($maxDurationInput);
                        $planMaxMinutes = $org->getSessionLimitHours() * 60;
                        if ($maxMinutes === null) {
                            $this->addFlash('danger', "Max Session Duration must be in HR:MIN format (e.g. 08:00 or 02:30).");
                            return $this->redirectToRoute('app_manager_categories');
                        }
                        if ($maxMinutes <= 0 || $maxMinutes > $planMaxMinutes) {
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
                $this->addFlash('success', "Category '{$name}' created successfully.");
            }
            return $this->redirectToRoute('app_manager_categories');
        }

        return $this->render('manager/categories.html.twig', [
            'categories' => $categories,
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
                        if ($maxMinutes <= 0 || $maxMinutes > $planMaxMinutes) {
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
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR u.phone LIKE :q OR u.pin LIKE :q')
               ->setParameter('q', '%' . $searchQuery . '%');
        }

        if ($statusFilter === 'active') {
            $qb->andWhere('u.isActive = :status')->setParameter('status', true);
        } elseif ($statusFilter === 'inactive') {
            $qb->andWhere('u.isActive = :status')->setParameter('status', false);
        }

        $qb->orderBy('u.lastName', 'ASC');
        $orgUsers = $qb->getQuery()->getResult();

        $managers = $em->getRepository(Manager::class)->findBy(['organization' => $org]);

        return $this->render('manager/users.html.twig', [
            'orgUsers' => $orgUsers,
            'managers' => $managers,
            'limitsData' => $limitsData,
            'searchQuery' => $searchQuery,
            'statusFilter' => $statusFilter,
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
            $orgUser->setIsActive(!$orgUser->isActive());
            $orgUser->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();

            $status = $orgUser->isActive() ? 'activated' : 'deactivated';
            $this->logAudit($em, $org, 'USER_TOGGLE', ['orgUser_id' => $orgUser->getId(), 'status' => $status], $request);

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

        $limitsData = $this->getOrgLimits($org, $em);
        if ($limitsData['usage']['orgUsers'] >= $limitsData['limits']['max_orgUsers']) {
            $this->addFlash('danger', "OrgUser limit reached ({$limitsData['limits']['max_orgUsers']}). Please upgrade your plan.");
            return $this->redirectToRoute('app_manager_users');
        }

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
        $orgUser->setIsActive(true);
        $orgUser->setPinHash($this->pinService->hashPin($pin));
        $orgUser->setPin($pin);

        $em->persist($orgUser);
        $em->flush();

        $this->logAudit($em, $org, 'USER_CREATE', ['name' => "{$first} {$last}"], $request);
        $this->addFlash('success', "OrgUser {$first} {$last} added successfully with PIN: {$pin}");

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

        if ($first) $orgUser->setFirstName($first);
        if ($last)  $orgUser->setLastName($last);
        $orgUser->setEmail($email ?: null);
        $orgUser->setPhone($phone ?: null);

        if ($pin) {
            if (!preg_match('/^[ABC][0-9]{3}$/', $pin)) {
                $this->addFlash('danger', 'Invalid PIN format. Must be [A/B/C] + 3 digits.');
                return $this->redirectToRoute('app_manager_users');
            }
            if ($this->pinService->isPinTaken($org, $pin, $orgUser->getId())) {
                $this->addFlash("danger", "PIN '" . $pin . "' is already assigned to another user.");
                return $this->redirectToRoute('app_manager_users');
            }
            $orgUser->setPin($pin);
            $orgUser->setPinHash($this->pinService->hashPin($pin));
        }

        $orgUser->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->logAudit($em, $org, 'USER_EDIT', ['orgUser_id' => $orgUser->getId()], $request);
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

        if ($password && strlen($password) >= 8) {
            $target->setPasswordHash($passwordHasher->hashPassword($target, $password));
        }

        $target->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->logAudit($em, $org, 'MANAGER_EDIT', ['manager_id' => $target->getId()], $request);
        $this->addFlash('success', "Manager {$target->getFirstName()} {$target->getLastName()} updated successfully.");
        return $this->redirectToRoute('app_manager_users');
    }


    #[Route('/users/add-manager', name: 'app_manager_add_manager', methods: ['POST'])]
    public function addManager(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): Response
    {
        /** @var Manager $currentManager */
        $currentManager = $this->getUser();
        $org = $currentManager->getOrganization();

        $limitsData = $this->getOrgLimits($org, $em);
        if ($limitsData['usage']['managers'] >= $limitsData['limits']['max_managers']) {
            $this->addFlash('danger', "Manager limit reached ({$limitsData['limits']['max_managers']}) for your plan. Upgrade to Gold to add multiple managers.");
            return $this->redirectToRoute('app_manager_users');
        }

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
        $newManager->setIsActive(true);
        $newManager->setPasswordHash($passwordHasher->hashPassword($newManager, $password));

        $em->persist($newManager);
        $em->flush();

        $this->logAudit($em, $org, 'MANAGER_CREATE', ['email' => $email], $request);
        $this->addFlash('success', "Manager {$first} {$last} added successfully.");

        return $this->redirectToRoute('app_manager_users');
    }

    #[Route('/users/import', name: 'app_manager_users_import', methods: ['GET', 'POST'])]
    public function importWizard(Request $request, EntityManagerInterface $em, \App\Service\UserMatchingService $userMatchingService, \App\Service\AppConfigService $configService): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

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
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
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
                $maxFields = $limitsData['limits']['max_fields'];
                if ($mappedCustomFields > $maxFields) {
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

                $em->wrapInTransaction(function() use ($em, $org, $missingUsersAction, $missingUsers, &$disabledMissingCount, &$importedCount, &$updatedCount, &$pinSkippedCount, $results, $actions, $enableInactive, $maxLimit, $overQuotaAction) {
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
                    $currentActiveCount = $em->getRepository(OrgUser::class)->count(['organization' => $org, 'isActive' => true]);
                    
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
                            
                            $isActive = true;
                            if ($currentActiveCount >= $maxLimit) {
                                if ($overQuotaAction === 'import_disabled') {
                                    $isActive = false;
                                } else {
                                    $isActive = false;
                                }
                            }
                            
                            $orgUser->setIsActive($isActive);
                            if ($isActive) $currentActiveCount++;

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
                                    if ($currentActiveCount >= $maxLimit && $overQuotaAction !== 'import_disabled') {
                                        // Over limit, keep inactive
                                    } else {
                                        $orgUser->setIsActive(true);
                                        $currentActiveCount++;
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
                    'disabled_missing' => $disabledMissingCount
                ], $request);

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
        $overtimeCount = 0;
        $outOfWindowCount = 0;
        
        foreach ($sessions as $s) {
            $totalSeconds += $s->getDurationSeconds() ?? 0;
            if ($s->isOvertime()) $overtimeCount++;
            if ($s->isOutOfWindow()) $outOfWindowCount++;
        }

        $hours = floor($totalSeconds / 3600);
        $mins = floor(($totalSeconds % 3600) / 60);

        // Fetch filter dropdown options
        $orgUsers = $em->getRepository(OrgUser::class)->findBy(['organization' => $org]);
        $categories = $em->getRepository(Category::class)->findBy(['organization' => $org]);

        return $this->render('manager/reports.html.twig', [
            'sessions' => $sessions,
            'orgUsers' => $orgUsers,
            'categories' => $categories,
            'totalHours' => "{$hours}h {$mins}m",
            'overtimeCount' => $overtimeCount,
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
        $rows[] = ['Session ID', 'OrgUser Name', 'Email', 'Category', 'Started At', 'Ended At', 'Duration (Hours)', 'Start Method', 'Is Overtime', 'Is Out Of Window', 'Device Info'];

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
                $s->isOvertime() ? 'Yes' : 'No',
                $s->isOutOfWindow() ? 'Yes' : 'No',
                $s->getDeviceInfo() ?? 'N/A'
            ];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'export');
        $fp = fopen($tempFile, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
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

        $orgUsers = $em->getRepository(OrgUser::class)->findBy(['organization' => $org]);
        
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
        ]);
    }

    #[Route('/billing', name: 'app_manager_billing', methods: ['GET', 'POST'])]
    public function billing(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        // Simulated billing transitions
        if ($request->isMethod('POST')) {
            $newPlan = $request->request->get('plan');
            $action = $request->request->get('billing_action'); // upgrade, downgrade, fail_payment, restore_payment

            if ($action === 'fail_payment') {
                $org->setIsSubscriptionActive(false);
                $this->addFlash('danger', 'Simulated: Stripe renewal payment failed. Premium features have been locked.');
            } elseif ($action === 'restore_payment') {
                $org->setIsSubscriptionActive(true);
                $this->addFlash('success', 'Simulated: Stripe payment processed. Subscription restored.');
            } else {
                $planEntity = $em->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => $newPlan]);
                if ($planEntity) {
                    $org->setPlan($planEntity);
                }
                $org->setIsSubscriptionActive(true);

                // Add payment record for simulated upgrade
                $payment = new PaymentHistory();
                $payment->setOrganization($org);
                $payment->setStripeInvoiceId('sub_' . bin2hex(random_bytes(8)));
                $payment->setAmount(number_format($planEntity ? $planEntity->getPriceMonthly() : 0.00, 2, '.', ''));
                $payment->setStatus('paid');
                $payment->setPaidAt(new \DateTime());
                $payment->setPeriodStart(new \DateTime());
                $payment->setPeriodEnd(new \DateTime('+1 month'));
                $em->persist($payment);

                // Reset overrides to null when switching plans to inherit new defaults
                $org->setSessionLimitHoursOverride(null);
                if ($newPlan === 'basic') {
                    $org->setAccessMode('open');
                }

                $this->addFlash('success', "Subscription successfully updated to plan: " . ucfirst($newPlan));
            }

            $org->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();

            $this->logAudit($em, $org, 'PLAN_UPGRADE', ['plan' => $org->getPlan()->getName(), 'action' => $action], $request);
            return $this->redirectToRoute('app_manager_billing');
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
}
