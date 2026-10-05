<?php
namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Daily cron: apporteurs with no new lead for 30 or 90 days get a friendly nudge with the sales kit.
#[AsCommand(name: 'app:apporteur-reminders', description: 'Relance les apporteurs sans nouveau lead depuis 30 et 90 jours')]
class ApporteurRemindersCommand extends Command
{
    private const REMIND_DAYS = [30, 90];

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly Notifier $notifier,
        private readonly UrlGeneratorInterface $urls,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $today = new \DateTimeImmutable('today');
        $sent = 0;

        foreach ($this->users->findByRole('ROLE_APPORTEUR') as $apporteur) {
            if ($apporteur->getStatus() === 'disabled') {
                continue;
            }

            $lastLead = $this->em->createQuery('SELECT MAX(l.createdAt) FROM App\Entity\Lead l WHERE l.apporteur = :a')
                ->setParameter('a', $apporteur)
                ->getSingleScalarResult();
            $lastActivity = $lastLead !== null ? new \DateTimeImmutable($lastLead) : $apporteur->getCreatedAt();
            $idleDays = (int) $lastActivity->setTime(0, 0)->diff($today)->format('%a');

            if (!in_array($idleDays, self::REMIND_DAYS, true)) {
                continue;
            }

            $this->notifier->send(
                $apporteur->getEmail(),
                $lastLead === null ? 'Votre premier lead Klevup ?' : 'Un contact à nous présenter ?',
                sprintf(
                    "Bonjour %s,\n\n%s\n\nUne idée de qui pourrait être intéressé ? Le kit de vente vous donne, pour chaque solution, qui cibler et la question qui déclenche l'intérêt :\n%s\n\nTransmettre un contact prend 2 minutes : %s\n\nUne question ? Répondez simplement à cet email.",
                    $apporteur->getName(),
                    $lastLead === null
                        ? "Vous avez rejoint le réseau il y a {$idleDays} jours — merci encore ! Pas encore de lead envoyé : c'est souvent le premier qui est le plus dur."
                        : "Cela fait {$idleDays} jours que vous ne nous avez pas présenté de contact.",
                    $this->urls->generate('kit', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    $this->urls->generate('lead_new', [], UrlGeneratorInterface::ABSOLUTE_URL)
                )
            );
            ++$sent;
        }

        $output->writeln(sprintf('%d relance(s) apporteur envoyée(s).', $sent));

        return Command::SUCCESS;
    }
}
