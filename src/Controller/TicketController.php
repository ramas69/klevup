<?php
namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketMessage;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
class TicketController extends AbstractController
{
    private const TYPES = ['bug', 'feature', 'question'];
    private const PRIORITIES = ['critical', 'high', 'normal'];
    private const CSRF_ERROR = 'Jeton CSRF invalide.';

    #[Route('/ticket/new', name: 'ticket_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, Notifier $notifier): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('ticket_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(self::CSRF_ERROR);
            }

            $title = trim((string) $request->request->get('title'));
            $description = trim((string) $request->request->get('description'));
            $type = (string) $request->request->get('type', 'bug');
            $priority = (string) $request->request->get('priority', 'normal');

            if ($title === '' || $description === '' || mb_strlen($title) > 255 || mb_strlen($description) > 5000) {
                return $this->render('ticket/new.html.twig', [
                    'error' => 'Titre (255 max) et description (5000 max) sont obligatoires.',
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

            $notifier->toAdmins(
                sprintf('[Klevup] %s — %s%s', $ticket->getReference(), $ticket->getTitle(), $ticket->getPriority() === 'critical' ? ' — CRITIQUE' : ''),
                sprintf(
                    "Nouvelle demande de %s (%s)\nType : %s · Priorité : %s\n\n%s\n\nTraiter : %s",
                    $this->getUser()->getName(),
                    $this->getUser()->getUserIdentifier(),
                    $ticket->getType(),
                    $ticket->getPriority(),
                    $ticket->getDescription(),
                    $this->generateUrl('admin_tickets', [], UrlGeneratorInterface::ABSOLUTE_URL)
                )
            );

            $this->addFlash('success', sprintf('Demande %s enregistrée — réponse sous 24 h ouvrées.', $ticket->getReference()));

            return $this->redirectToRoute('support');
        }

        return $this->render('ticket/new.html.twig');
    }

    #[Route('/ticket/{id}', name: 'ticket_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Ticket $ticket): Response
    {
        $this->denyUnlessOwner($ticket);

        return $this->render('ticket/show.html.twig', ['ticket' => $ticket]);
    }

    #[Route('/ticket/{id}/message', name: 'ticket_message', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function message(Ticket $ticket, Request $request, EntityManagerInterface $em, Notifier $notifier): Response
    {
        $this->denyUnlessOwner($ticket);

        if (!$this->isCsrfTokenValid('ticket_message_' . $ticket->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $content = trim((string) $request->request->get('content'));
        if ($content === '' || mb_strlen($content) > 5000) {
            $this->addFlash('error', 'Message vide ou trop long (5000 caractères max).');

            return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()]);
        }

        $message = new TicketMessage();
        $message->setTicket($ticket);
        $message->setAuthor($this->getUser());
        $message->setContent($content);
        $em->persist($message);
        $em->flush();

        $notifier->toAdmins(
            sprintf('[Klevup] Réponse client sur %s', $ticket->getReference()),
            sprintf("%s a répondu sur %s « %s » :\n\n%s\n\nTraiter : %s", $ticket->getUser()->getName(), $ticket->getReference(), $ticket->getTitle(), $content, $this->generateUrl('admin_tickets', [], UrlGeneratorInterface::ABSOLUTE_URL))
        );

        $this->addFlash('success', 'Message envoyé.');

        return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()]);
    }

    #[Route('/ticket/{id}/reopen', name: 'ticket_reopen', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reopen(Ticket $ticket, Request $request, EntityManagerInterface $em, Notifier $notifier): Response
    {
        $this->denyUnlessOwner($ticket);

        if (!$this->isCsrfTokenValid('ticket_reopen_' . $ticket->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        if ($ticket->getStatus() === 'resolved') {
            $ticket->setStatus('in_progress');
            $ticket->setResolvedAt(null);
            $em->flush();
            $notifier->toAdmins(
                sprintf('[Klevup] %s rouvert par le client', $ticket->getReference()),
                sprintf("%s a rouvert %s « %s ».\n\nTraiter : %s", $ticket->getUser()->getName(), $ticket->getReference(), $ticket->getTitle(), $this->generateUrl('admin_tickets', [], UrlGeneratorInterface::ABSOLUTE_URL))
            );
            $this->addFlash('success', sprintf('Demande %s rouverte — notre équipe reprend le dossier.', $ticket->getReference()));
        }

        return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()]);
    }

    // 404 (not 403) so ticket existence is never leaked to other clients.
    private function denyUnlessOwner(Ticket $ticket): void
    {
        if ($ticket->getUser()->getUserIdentifier() !== $this->getUser()?->getUserIdentifier()) {
            throw $this->createNotFoundException();
        }
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
