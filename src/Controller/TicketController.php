<?php
namespace App\Controller;

use App\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
class TicketController extends AbstractController
{
    private const TYPES = ['bug', 'feature', 'question'];
    private const PRIORITIES = ['critical', 'high', 'normal'];

    #[Route('/ticket/new', name: 'ticket_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('ticket_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $title = trim((string) $request->request->get('title'));
            $description = trim((string) $request->request->get('description'));
            $type = (string) $request->request->get('type', 'bug');
            $priority = (string) $request->request->get('priority', 'normal');

            if ($title === '' || $description === '') {
                return $this->render('ticket/new.html.twig', [
                    'error' => 'Titre et description sont obligatoires.',
                ]);
            }

            $ticket = new Ticket();
            $ticket->setReference($this->nextReference($em));
            $ticket->setTitle($title);
            $ticket->setDescription($description);
            $ticket->setType(in_array($type, self::TYPES, true) ? $type : 'bug');
            $ticket->setPriority(in_array($priority, self::PRIORITIES, true) ? $priority : 'normal');
            $ticket->setUser($this->getUser());

            $em->persist($ticket);
            $em->flush();

            $this->addFlash('success', sprintf('Demande %s enregistrée — réponse sous 24 h ouvrées.', $ticket->getReference()));

            return $this->redirectToRoute('support');
        }

        return $this->render('ticket/new.html.twig');
    }

    private function nextReference(EntityManagerInterface $em): string
    {
        // Highest numeric suffix among existing KLV-XXX references, so we never collide.
        $max = (int) $em->getConnection()->fetchOne(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) FROM ticket"
        );

        return sprintf('KLV-%03d', $max + 1);
    }
}
