<?php
namespace App\Controller;

use App\Entity\Commission;
use App\Entity\Lead;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_APPORTEUR')]
class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $leads = $em->getRepository(Lead::class)->findBy(['apporteur' => $user], ['createdAt' => 'DESC']);
        $commissions = $em->getRepository(Commission::class)->findBy(['user' => $user], ['createdAt' => 'DESC']);

        $signedLeads = array_filter($leads, fn(Lead $l) => $l->getStatus() === 'signed');
        $monthStart = new \DateTimeImmutable('first day of this month midnight');
        $quarterStart = new \DateTimeImmutable('first day of january midnight');
        $month = (int) (new \DateTimeImmutable())->format('n');
        $quarterStart = $quarterStart->modify('+' . (intdiv($month - 1, 3) * 3) . ' months');

        $commissionsMonth = array_sum(array_map(
            fn(Commission $c) => $c->getAmount(),
            array_filter($commissions, fn(Commission $c) => $c->getCreatedAt() >= $monthStart)
        ));

        $apporteur = [
            'name' => $user->getName(),
            'commissions_month' => $commissionsMonth,
            'leads_in_progress' => count($leads) - count($signedLeads),
            'sales_signed' => count($signedLeads),
            'total_generated' => array_sum(array_map(fn(Lead $l) => $l->getCommission(), $signedLeads)),
            'sales_trimestre' => count(array_filter($signedLeads, fn(Lead $l) => $l->getCreatedAt() >= $quarterStart)),
            'palier_target' => 3,
        ];

        $leadsData = array_map(fn(Lead $l) => [
            'contact' => $l->getContact(),
            'solution' => $l->getSolution(),
            'status' => $l->getStatus(),
            'commission' => $l->getCommission(),
        ], $leads);

        $commissionsData = array_map(fn(Commission $c) => [
            'company' => $c->getCompanyName(),
            'solution' => $c->getSolution(),
            'date' => $c->getEncashedAt()?->format('d/m/Y') ?? $c->getCreatedAt()->format('d/m/Y'),
            'amount' => $c->getAmount(),
            'status' => $c->getStatus(),
        ], $commissions);

        return $this->render('dashboard/index.html.twig', [
            'apporter' => $apporteur,
            'leads' => $leadsData,
            'commissions' => $commissionsData,
        ]);
    }
}
