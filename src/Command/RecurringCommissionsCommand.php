<?php
namespace App\Command;

use App\Entity\Application;
use App\Entity\Commission;
use App\Service\CommissionCalculator;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Daily cron: for every subscription linked to an apporteur's sale, creates the recurring commission
 * of each elapsed month (5 % of the subscription, months 1 to 12 after signature) while the client keeps paying.
 * Idempotent: a (lead, month) pair is created once, missed runs are caught up.
 */
#[AsCommand(name: 'app:recurring-commissions', description: 'Crée les commissions récurrentes des apporteurs (abonnements actifs)')]
class RecurringCommissionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Notifier $notifier,
        private readonly UrlGeneratorInterface $urls,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $today = new \DateTimeImmutable('today');
        $created = [];

        $apps = $this->em->createQuery("SELECT a FROM App\Entity\Application a JOIN a.lead l WHERE l.status = 'signed' AND l.apporteur IS NOT NULL AND l.signedAt IS NOT NULL")->getResult();

        /** @var Application $app */
        foreach ($apps as $app) {
            $lead = $app->getLead();
            $monthly = $app->getMonthlyPrice() ?: $lead->getMonthlyAmount();
            if (!$monthly) {
                continue;
            }
            $existing = [];
            foreach ($this->em->getRepository(Commission::class)->findBy(['lead' => $lead]) as $c) {
                $existing[$c->getPeriod()] = true;
            }

            $start = $lead->getSignedAt()->setTime(0, 0);
            for ($month = 1; $month <= CommissionCalculator::RECURRING_MONTHS; ++$month) {
                $monthDate = $start->modify("+$month months");
                // Not yet due, or the client had stopped paying by then.
                if ($monthDate > $today || ($app->getSubscriptionEndsAt() !== null && $app->getSubscriptionEndsAt() < $monthDate)) {
                    break;
                }
                if (isset($existing[$month])) {
                    continue;
                }

                $commission = (new Commission())
                    ->setLead($lead)
                    ->setPeriod($month)
                    ->setUser($lead->getApporteur())
                    ->setCompanyName($lead->getContact())
                    ->setSolution(sprintf('Abonnement — mois %d/%d', $month, CommissionCalculator::RECURRING_MONTHS))
                    ->setAmount(CommissionCalculator::recurringAmount($monthly))
                    ->setDueAt(CommissionCalculator::dueDate($monthDate));
                $this->em->persist($commission);
                $created[$lead->getApporteur()->getId()][] = $commission;
            }
        }
        $this->em->flush();

        // One summary email per apporteur.
        foreach ($created as $commissions) {
            $apporteur = $commissions[0]->getUser();
            $lines = array_map(fn(Commission $c) => sprintf('- %s · %s : %d € (versement prévu le %s)', $c->getCompanyName(), $c->getSolution(), $c->getAmount(), $c->getDueAt()->format('d/m/Y')), $commissions);
            $this->notifier->send(
                $apporteur->getEmail(),
                sprintf('[Klevup] Vos commissions récurrentes — %d €', array_sum(array_map(fn(Commission $c) => $c->getAmount(), $commissions))),
                sprintf("Bonjour %s,\n\nVos clients continuent de payer leur abonnement — voici vos nouvelles commissions récurrentes :\n\n%s\n\nSuivez vos commissions : %s",
                    $apporteur->getName(), implode("\n", $lines), $this->urls->generate('commissions', [], UrlGeneratorInterface::ABSOLUTE_URL))
            );
        }

        $output->writeln(sprintf('%d commission(s) récurrente(s) créée(s).', array_sum(array_map('count', $created))));

        return Command::SUCCESS;
    }
}
