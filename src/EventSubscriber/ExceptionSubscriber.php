<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

class ExceptionSubscriber implements EventSubscriberInterface
{
    private $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 2],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // Catch Access Denied exceptions
        if ($exception instanceof AccessDeniedException || $exception instanceof AccessDeniedHttpException) {
            try {
                $html = $this->twig->render('bundles/TwigBundle/Exception/error403.html.twig');
                $response = new Response($html, 403);
                $event->setResponse($response);
            } catch (\Exception $e) {
                // Fallback in case twig rendering fails
            }
        }
    }
}
