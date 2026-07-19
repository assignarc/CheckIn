<?php

namespace App\EventSubscriber;

use App\Controller\ManagerController;
use App\Entity\Manager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;

class OrganizationArchiveSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        if (is_array($controller)) {
            $controllerObject = $controller[0];
        } else {
            $controllerObject = $controller;
        }

        // Only intercept requests destined for ManagerController
        if ($controllerObject instanceof ManagerController) {
            $user = $this->security->getUser();
            if ($user instanceof Manager) {
                $org = $user->getOrganization();
                if ($org && $org->isArchived()) {
                    $request = $event->getRequest();
                    $route = $request->attributes->get('_route');

                    // Allow access to billing and logout routes
                    $allowedRoutes = [
                        'app_manager_billing',
                        'app_manager_billing_portal',
                        'app_manager_billing_checkout',
                        'app_logout',
                    ];

                    if (!in_array($route, $allowedRoutes)) {
                        // Flash warning
                        $session = $request->getSession();
                        if ($session instanceof Session) {
                            $session->getFlashBag()->add('warning', 'Your organization has been archived due to unpaid invoices. Please update your billing details to resume service.');
                        }

                        $targetUrl = $this->urlGenerator->generate('app_manager_billing');
                        $event->setController(function () use ($targetUrl) {
                            return new RedirectResponse($targetUrl);
                        });
                    }
                }
            }
        }
    }
}
