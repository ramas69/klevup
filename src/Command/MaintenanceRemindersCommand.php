<?php
namespace App\Command;

use App\Entity\Application;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Daily cron: maintenance contracts ending in 30 or 7 days → reminder to the client and to the team.
#[AsCommand(name: 'app:maintenance-reminders', description: 'Relance les clients dont la maintenance arrive à échéance (J-30 et J-7)')]
class MaintenanceRemindersCommand extends Command
{
    private const REMIND_DAYS = [30, 7];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Notifier $notifier,
        private readonly UrlGeneratorInterface $urls,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sent = 0;
        foreach (self::REMIND_DAYS as $days) {
            $day = new \DateTimeImmutable("today +$days days");
            $apps = $this->em->createQuery('SELECT a FROM App\Entity\Application a WHERE a.maintenanceUntil >= :from AND a.maintenanceUntil < :to')
                ->setParameter('from', $day)
                ->setParameter('to', $day->modify('+1 day'))
                ->getResult();

            /** @var Application $app */
            foreach ($apps as $app) {
                $client = $app->getClient();
                $this->notifier->send(
                    $client->getEmail(),
                    sprintf('[Klevup] Votre maintenance %s se termine dans %d jours', $app->getName(), $days),
                    sprintf(
                        "Bonjour %s,\n\nLe contrat de maintenance de « %s » se termine le %s.\nPour continuer à bénéficier des corrections, mises à jour de sécurité et du support prioritaire, demandez votre renouvellement en un clic depuis votre espace :\n%s",
                        $client->getName(),
                        $app->getName(),
                        $app->getMaintenanceUntil()->format('d/m/Y'),
                        $this->urls->generate('support', [], UrlGeneratorInterface::ABSOLUTE_URL)
                    )
                );
                $this->notifier->toAdmins(
                    sprintf('[Klevup] Maintenance J-%d — %s (%s)', $days, $app->getName(), $client->getName()),
                    sprintf("La maintenance de « %s » (%s, %s) se termine le %s. Le client vient d'être relancé.", $app->getName(), $client->getName(), $client->getEmail(), $app->getMaintenanceUntil()->format('d/m/Y'))
                );
                ++$sent;
            }
        }

        $output->writeln(sprintf('%d relance(s) envoyée(s).', $sent));

        return Command::SUCCESS;
    }
}
