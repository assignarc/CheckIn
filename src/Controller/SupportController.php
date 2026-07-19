<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/support')]
class SupportController extends AbstractController
{
    #[Route('', name: 'app_support_index')]
    public function index(): Response
    {
        return $this->render('support/index.html.twig');
    }

    #[Route('/kiosk', name: 'app_support_kiosk')]
    public function kiosk(): Response
    {
        return $this->render('support/kiosk.html.twig');
    }

    #[Route('/users', name: 'app_support_users')]
    public function users(): Response
    {
        return $this->render('support/users.html.twig');
    }

    #[Route('/reports', name: 'app_support_reports')]
    public function reports(): Response
    {
        return $this->render('support/reports.html.twig');
    }

    #[Route('/audit-settings', name: 'app_support_audit_settings')]
    public function auditSettings(): Response
    {
        return $this->render('support/audit_settings.html.twig');
    }
}
