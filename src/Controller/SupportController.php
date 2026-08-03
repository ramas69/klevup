<?php
namespace App\Controller;

use App\Entity\Application;
use App\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
class SupportController extends AbstractController
{
    #[Route('/support', name: 'support')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $tickets = $em->getRepository(Ticket::class)->findBy(['user' => $user], ['createdAt' => 'DESC']);

        $monthStart = new \DateTimeImmutable('first day of this month midnight');
        $resolved = array_filter($tickets, fn(Ticket $t) => $t->getStatus() === 'resolved');

        $client = [
            'name' => $user->getName(),
            'requests_in_progress' => count($tickets) - count($resolved),
            'resolved_this_month' => count(array_filter(
                $resolved,
                fn(Ticket $t) => $t->getResolvedAt() !== null && $t->getResolvedAt() >= $monthStart
            )),
            'response_time' => $this->averageResolutionTime($resolved),
        ];

        $app = $em->getRepository(Application::class)->findOneBy(['client' => $user]);
        $application = $app === null ? null : [
            'name' => $app->getName(),
            'description' => $app->getDescription(),
            'launch_date' => $app->getLaunchedAt()?->format('d/m/Y') ?? '—',
            'version' => $app->getVersion(),
            'url' => $app->getUrl(),
            'maintenance_until' => $app->getMaintenanceUntil()?->format('d/m/Y'),
        ];

        $ticketsData = array_map(fn(Ticket $t) => [
            'id' => $t->getId(),
            'ref' => $t->getReference(),
            'title' => $t->getTitle(),
            'type' => $t->getType(),
            'date' => $t->getCreatedAt()->format('d/m/Y'),
            'priority' => $t->getPriority(),
            'status' => $t->getStatus(),
            'note' => $t->getAdminNote() ?? $t->getDescription(),
        ], $tickets);

        return $this->render('support/index.html.twig', [
            'client' => $client,
            'application' => $application,
            'tickets' => $ticketsData,
        ]);
    }

    /**
     * Average time between creation and resolution of resolved tickets.
     * Falls back to the 24h commitment when nothing has been resolved yet.
     *
     * @param Ticket[] $resolved
     */
    private function averageResolutionTime(array $resolved): string
    {
        $durations = [];
        foreach ($resolved as $ticket) {
            if ($ticket->getResolvedAt() !== null) {
                $durations[] = $ticket->getResolvedAt()->getTimestamp() - $ticket->getCreatedAt()->getTimestamp();
            }
        }

        if ($durations === []) {
            return '24 h';
        }

        $avg = (int) (array_sum($durations) / count($durations));

        return match (true) {
            $avg < 3600 => max(1, intdiv($avg, 60)) . ' min',
            $avg < 172800 => intdiv($avg, 3600) . ' h',
            default => intdiv($avg, 86400) . ' j',
        };
    }
}
