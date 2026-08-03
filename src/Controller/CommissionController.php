<?php
namespace App\Controller;

use App\Entity\Commission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_APPORTEUR')]
class CommissionController extends AbstractController
{
    #[Route('/commissions', name: 'commissions')]
    public function list(EntityManagerInterface $em): Response
    {
        $commissions = $em->getRepository(Commission::class)->findBy(
            ['user' => $this->getUser()],
            ['createdAt' => 'DESC']
        );

        $encashed = array_filter($commissions, fn(Commission $c) => $c->getStatus() === 'encashed');
        $pending = array_filter($commissions, fn(Commission $c) => $c->getStatus() !== 'encashed');
        $monthStart = new \DateTimeImmutable('first day of this month midnight');

        $sum = fn(array $items) => array_sum(array_map(fn(Commission $c) => $c->getAmount(), $items));

        return $this->render('commission/list.html.twig', [
            'commissions' => $commissions,
            'stats' => [
                'pending' => $sum($pending),
                'encashed' => $sum($encashed),
                'month' => $sum(array_filter($commissions, fn(Commission $c) => $c->getCreatedAt() >= $monthStart)),
                'count' => count($commissions),
            ],
        ]);
    }
}
