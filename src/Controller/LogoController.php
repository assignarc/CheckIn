<?php

namespace App\Controller;

use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LogoController extends AbstractController
{
    #[Route('/org/{slug}/logo', name: 'app_org_logo')]
    public function serveLogo(string $slug, EntityManagerInterface $em): Response
    {
        $org = $em->getRepository(Organization::class)->findOneBy(['slug' => $slug]);
        if (!$org) {
            return new Response('Organization not found', 404);
        }

        $logoBlob = $org->getLogoBlob();

        if ($logoBlob) {
            // Handle resource stream
            if (is_resource($logoBlob)) {
                $data = stream_get_contents($logoBlob);
            } else {
                $data = $logoBlob;
            }

            $response = new Response($data, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=31536000', // 1 year
            ]);
            $response->setLastModified($org->getUpdatedAt() ?: new \DateTimeImmutable());
            $response->setPublic();
            
            return $response;
        }

        // Generate dynamic fallback SVG logo based on initials
        $name = $org->getName();
        $initials = '';
        if ($name) {
            $words = array_filter(explode(' ', $name));
            $words = array_values($words);
            if (!empty($words)) {
                $initials .= strtoupper(substr($words[0], 0, 1));
                if (isset($words[1])) {
                    $initials .= strtoupper(substr($words[1], 0, 1));
                } else if (strlen($name) > 1) {
                    $initials .= strtoupper(substr($name, 1, 1));
                }
            }
        }
        if (empty($initials)) {
            $initials = 'TF';
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
                <rect width="128" height="128" fill="#1e293b" rx="16"/>
                <text x="50%%" y="52%%" dominant-baseline="middle" text-anchor="middle" font-family="-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif" font-size="44" font-weight="800" fill="#6366f1">
                    %s
                </text>
            </svg>',
            htmlspecialchars($initials)
        );

        $response = new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400', // 1 day
        ]);
        $response->setLastModified($org->getUpdatedAt() ?: new \DateTimeImmutable());
        $response->setPublic();

        return $response;
    }
}
