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
                @rewind($logoBlob);
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

        // Choose one of 5 beautiful stock patterns deterministically based on organization ID
        $stockIndex = $org->getId() % 5;
        $escapedInitials = htmlspecialchars($initials);

        switch ($stockIndex) {
            case 0:
                // Tech Hexagons
                $svg = sprintf(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
                        <rect width="128" height="128" fill="#0f172a" rx="16"/>
                        <polygon points="64,20 100,40 100,80 64,100 28,80 28,40" fill="none" stroke="#6366f1" stroke-width="2" opacity="0.3"/>
                        <polygon points="64,30 92,46 92,74 64,90 36,74 36,46" fill="none" stroke="#a855f7" stroke-width="1.5" opacity="0.4"/>
                        <polygon points="64,40 84,52 84,68 64,80 44,68 44,52" fill="none" stroke="#3b82f6" stroke-width="1" opacity="0.5"/>
                        <text x="50%%" y="54%%" dominant-baseline="middle" text-anchor="middle" font-family="-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif" font-size="36" font-weight="800" fill="#f8fafc">%s</text>
                    </svg>',
                    $escapedInitials
                );
                break;
            case 1:
                // Sunset Waves
                $svg = sprintf(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
                        <rect width="128" height="128" fill="#180b0b" rx="16"/>
                        <path d="M 0,90 Q 32,70 64,90 T 128,90 L 128,128 L 0,128 Z" fill="#b91c1c" opacity="0.4"/>
                        <path d="M 0,100 Q 32,85 64,100 T 128,100 L 128,128 L 0,128 Z" fill="#ea580c" opacity="0.5"/>
                        <path d="M 0,110 Q 32,100 64,110 T 128,110 L 128,128 L 0,128 Z" fill="#eab308" opacity="0.6"/>
                        <text x="50%%" y="45%%" dominant-baseline="middle" text-anchor="middle" font-family="-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif" font-size="36" font-weight="800" fill="#f8fafc">%s</text>
                    </svg>',
                    $escapedInitials
                );
                break;
            case 2:
                // Cosmic Rings
                $svg = sprintf(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
                        <rect width="128" height="128" fill="#0b0f19" rx="16"/>
                        <circle cx="64" cy="64" r="50" fill="none" stroke="#22d3ee" stroke-width="1.5" stroke-dasharray="4,4" opacity="0.4"/>
                        <circle cx="64" cy="64" r="40" fill="none" stroke="#818cf8" stroke-width="2" opacity="0.5"/>
                        <circle cx="64" cy="64" r="30" fill="none" stroke="#c084fc" stroke-width="1" stroke-dasharray="8,2" opacity="0.6"/>
                        <text x="50%%" y="54%%" dominant-baseline="middle" text-anchor="middle" font-family="-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif" font-size="36" font-weight="800" fill="#f8fafc">%s</text>
                    </svg>',
                    $escapedInitials
                );
                break;
            case 3:
                // Abstract Triangles
                $svg = sprintf(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
                        <rect width="128" height="128" fill="#1e1b4b" rx="16"/>
                        <polygon points="0,128 64,32 128,128" fill="#4f46e5" opacity="0.3"/>
                        <polygon points="20,128 74,48 128,128" fill="#06b6d4" opacity="0.4"/>
                        <polygon points="40,128 84,64 128,128" fill="#ec4899" opacity="0.25"/>
                        <text x="50%%" y="45%%" dominant-baseline="middle" text-anchor="middle" font-family="-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif" font-size="36" font-weight="800" fill="#f8fafc">%s</text>
                    </svg>',
                    $escapedInitials
                );
                break;
            case 4:
            default:
                // Network Grid
                $svg = sprintf(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
                        <rect width="128" height="128" fill="#090d16" rx="16"/>
                        <line x1="20" y1="20" x2="64" y2="40" stroke="#10b981" stroke-width="1" opacity="0.3"/>
                        <line x1="64" y1="40" x2="108" y2="20" stroke="#10b981" stroke-width="1" opacity="0.3"/>
                        <line x1="20" y1="108" x2="64" y2="88" stroke="#10b981" stroke-width="1" opacity="0.3"/>
                        <line x1="64" y1="88" x2="108" y2="108" stroke="#10b981" stroke-width="1" opacity="0.3"/>
                        <line x1="64" y1="40" x2="64" y2="88" stroke="#34d399" stroke-width="1.5" opacity="0.4"/>
                        <circle cx="20" cy="20" r="4" fill="#10b981"/>
                        <circle cx="108" cy="20" r="4" fill="#10b981"/>
                        <circle cx="64" cy="40" r="5" fill="#34d399"/>
                        <circle cx="64" cy="88" r="5" fill="#34d399"/>
                        <circle cx="20" cy="108" r="4" fill="#10b981"/>
                        <circle cx="108" cy="108" r="4" fill="#10b981"/>
                        <text x="50%%" y="54%%" dominant-baseline="middle" text-anchor="middle" font-family="-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif" font-size="36" font-weight="800" fill="#f8fafc">%s</text>
                    </svg>',
                    $escapedInitials
                );
                break;
        }

        $response = new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400', // 1 day
        ]);
        $response->setLastModified($org->getUpdatedAt() ?: new \DateTimeImmutable());
        $response->setPublic();

        return $response;
    }
}
