<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

use App\Service\CaptchaService;
use Symfony\Component\HttpFoundation\Request;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, CaptchaService $captchaService, Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_redirect_by_role');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        $captchaEnabled = $captchaService->isEnabled('login');
        $captchaQuestion = $captchaEnabled ? $captchaService->generateQuestion($request, 'login') : '';
        $captchaImage = $captchaEnabled ? $captchaService->generateImageFromQuestion($captchaQuestion) : '';

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'captcha_enabled' => $captchaEnabled,
            'captcha_question' => $captchaQuestion,
            'captcha_image' => $captchaImage,
        ]);
    }

    #[Route('/redirect-by-role', name: 'app_redirect_by_role')]
    public function redirectByRole(): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        if (in_array('ROLE_SYSTEM_ADMIN', $user->getRoles())) {
            return $this->redirectToRoute('app_admin_dashboard');
        }

        if (in_array('ROLE_ORG_MANAGER', $user->getRoles())) {
            return $this->redirectToRoute('app_manager_dashboard');
        }

        return $this->redirectToRoute('app_home');
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
