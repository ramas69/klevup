<?php
namespace App\Controller;

use App\Entity\Commission;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

        // One statement per month in which something was paid.
        $periods = array_values(array_unique(array_map(fn(Commission $c) => $c->getEncashedAt()->format('Y-m'), $encashed)));
        rsort($periods);

        return $this->render('commission/list.html.twig', [
            'commissions' => $commissions,
            'periods' => $periods,
            'stats' => [
                'pending' => $sum($pending),
                'encashed' => $sum($encashed),
                'month' => $sum(array_filter($commissions, fn(Commission $c) => $c->getCreatedAt() >= $monthStart)),
                'count' => count($commissions),
            ],
        ]);
    }

    #[Route('/commissions/releve/{period}', name: 'commission_statement', requirements: ['period' => '\d{4}-\d{2}'])]
    public function statement(string $period, EntityManagerInterface $em): Response
    {
        return $this->render('commission/statement.html.twig', self::statementData($em, $this->getUser(), $period));
    }

    /** Commissions paid to $user during $period (YYYY-MM) — shared with the admin view. */
    public static function statementData(EntityManagerInterface $em, User $user, string $period): array
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m', $period);
        if ($from === false || $from->format('Y-m') !== $period) {
            throw new NotFoundHttpException();
        }

        $lines = $em->createQuery("SELECT c FROM App\Entity\Commission c WHERE c.user = :u AND c.status = 'encashed' AND c.encashedAt >= :from AND c.encashedAt < :to ORDER BY c.encashedAt ASC")
            ->setParameter('u', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $from->modify('+1 month'))
            ->getResult();

        return [
            'apporteur' => $user,
            'period' => $from,
            'lines' => $lines,
            'total' => array_sum(array_map(fn(Commission $c) => $c->getAmount(), $lines)),
        ];
    }
}
