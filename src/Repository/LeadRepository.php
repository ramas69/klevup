<?php

namespace App\Repository;

use App\Entity\Lead;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Lead>
 */
class LeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lead::class);
    }

    /**
     * An open lead (not lost) for the same company or email on the same solution.
     * Prevents two apporteurs from claiming the same deal.
     */
    public function findOpenDuplicate(string $company, ?string $email, string $solution): ?Lead
    {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('l.status != :lost')
            ->andWhere('LOWER(l.solution) = LOWER(:solution)')
            ->setParameter('lost', 'lost')
            ->setParameter('solution', $solution)
            ->setMaxResults(1);

        $match = $qb->expr()->orX('LOWER(l.contact) = LOWER(:company)');
        $qb->setParameter('company', $company);
        if ($email !== null && $email !== '') {
            $match->add('LOWER(l.email) = LOWER(:email)');
            $qb->setParameter('email', $email);
        }

        return $qb->andWhere($match)->getQuery()->getOneOrNullResult();
    }

    /**
     * Signed sales of an apporteur in the quarter that were signed before $lead (same second: lower id first).
     * Without $lead, counts sales signed in [from, to[.
     */
    public function countSignedBetween(User $apporteur, \DateTimeImmutable $from, \DateTimeImmutable $to, ?Lead $lead = null): int
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.apporteur = :apporteur')
            ->andWhere('l.status = :signed')
            ->andWhere('l.signedAt >= :from')
            ->setParameter('apporteur', $apporteur)
            ->setParameter('signed', 'signed')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($lead?->getId() !== null) {
            $qb->andWhere('l.id != :self AND (l.signedAt < :to OR (l.signedAt = :to AND l.id < :self))')
                ->setParameter('self', $lead->getId());
        } else {
            $qb->andWhere('l.signedAt < :to');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
