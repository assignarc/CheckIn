<?php

namespace App\Controller;

use App\Entity\OrgUser;
use App\Entity\Manager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manager')]
#[IsGranted('ROLE_ORG_MANAGER')]
class BarcodeController extends AbstractController
{
    #[Route('/users/print-barcodes', name: 'app_manager_print_barcodes')]
    public function printBarcodes(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $userId = $request->query->get('id');
        $template = $request->query->get('template', '5160'); // L7156, 5160, 6572
        $startDateStr = $request->query->get('start_date');
        $endDateStr = $request->query->get('end_date');

        $users = [];
        if ($userId) {
            $user = $entityManager->getRepository(OrgUser::class)->findOneBy([
                'id' => $userId,
                'organization' => $org,
                'isActive' => true
            ]);
            if ($user) {
                $users[] = $user;
            }
        } else {
            $qb = $entityManager->createQueryBuilder()
                ->select('u')
                ->from(OrgUser::class, 'u')
                ->where('u.organization = :org')
                ->andWhere('u.isActive = :isActive')
                ->setParameter('org', $org)
                ->setParameter('isActive', true);

            if ($startDateStr) {
                try {
                    $qb->andWhere('u.createdAt >= :startDate')
                       ->setParameter('startDate', new \DateTime($startDateStr . ' 00:00:00'));
                } catch (\Exception $e) {}
            }
            if ($endDateStr) {
                try {
                    $qb->andWhere('u.createdAt <= :endDate')
                       ->setParameter('endDate', new \DateTime($endDateStr . ' 23:59:59'));
                } catch (\Exception $e) {}
            }

            $qb->orderBy('u.firstName', 'ASC')
               ->addOrderBy('u.lastName', 'ASC');

            $users = $qb->getQuery()->getResult();
        }

        return $this->render('manager/print_barcode.html.twig', [
            'org' => $org,
            'users' => $users,
            'template' => $template,
        ]);
    }
}
