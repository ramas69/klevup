<?php
namespace App\Controller;

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
            'response_time' => '4 h',
        ];

        // TODO: replace with an Application entity once client apps are modeled.
        $application = [
            'name' => 'Formapro Gestion',
            'description' => 'votre plateforme de gestion de formation',
            'launch_date' => '12 mai 2026',
            'version' => '1.3',
            'url' => 'formapro.klevup-apps.fr',
            'maintenance_until' => '12/05/2027',
        ];

        $ticketsData = array_map(fn(Ticket $t) => [
            'ref' => $t->getReference(),
            'title' => $t->getTitle(),
            'type' => $t->getType(),
            'date' => $t->getCreatedAt()->format('d/m/Y'),
            'priority' => $t->getPriority(),
            'status' => $t->getStatus(),
            'note' => $t->getDescription(),
        ], $tickets);

        return $this->render('support/index.html.twig', [
            'client' => $client,
            'application' => $application,
            'tickets' => $ticketsData,
        ]);
    }
}
