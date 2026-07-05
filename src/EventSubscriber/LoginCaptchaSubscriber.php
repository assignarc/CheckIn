<?php

namespace App\EventSubscriber;

use App\Service\CaptchaService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class LoginCaptchaSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CaptchaService $captchaService,
        private UrlGeneratorInterface $urlGenerator
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Target POST /login requests
        if ($request->isMethod('POST') && $request->getPathInfo() === '/login') {
            if ($this->captchaService->isEnabled('login')) {
                $captchaAnswer = $request->request->get('captcha_answer');
                if (!$this->captchaService->validate($request, 'login', $captchaAnswer)) {
                    $session = $request->getSession();
                    
                    // Inject custom authentication exception to display in login form
                    $session->set(
                        SecurityRequestAttributes::AUTHENTICATION_ERROR, 
                        new CustomUserMessageAuthenticationException('Incorrect math captcha answer. Please try again.')
                    );
                    
                    // Retain the username input
                    $session->set(
                        SecurityRequestAttributes::LAST_USERNAME, 
                        $request->request->get('_username')
                    );

                    // Redirect back to login, preventing security firewall authenticator check
                    $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
                }
            }
        }
    }
}
