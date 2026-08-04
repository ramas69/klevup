<?php
namespace App\Controller;

use App\Entity\Application;
use App\Entity\Commission;
use App\Entity\Invitation;
use App\Entity\Lead;
use App\Entity\Ticket;
use App\Entity\TicketMessage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    private const INVITABLE_ROLES = ['ROLE_APPORTEUR', 'ROLE_CLIENT'];
    private const CSRF_ERROR = 'Jeton CSRF invalide.';
    private const LEAD_STATUSES = ['new', 'meeting', 'devis', 'signed', 'lost'];
    private const LEAD_LABELS = ['new' => 'Nouveau', 'meeting' => 'RDV planifié', 'devis' => 'Devis envoyé', 'signed' => 'Signé', 'lost' => 'Sans suite'];
    private const TICKET_STATUSES = ['new', 'in_progress', 'test', 'resolved'];

    #[Route('/admin/invite', name: 'admin_invite')]
    public function invite(Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        $inviteLink = null;

        if ($request->isMethod('POST')) {
            $role = (string) $request->request->get('role', 'ROLE_APPORTEUR');
            if (!in_array($role, self::INVITABLE_ROLES, true)) {
                $role = 'ROLE_APPORTEUR';
            }

            $email = trim((string) $request->request->get('email'));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Email invalide — invitation non créée.');

                return $this->redirectToRoute('admin_invite');
            }

            $invitation = new Invitation();
            $invitation->setCode(bin2hex(random_bytes(16)));
            $invitation->setRole($role);
            $invitation->setEmail($email !== '' ? $email : null);
            $em->persist($invitation);
            $em->flush();

            $inviteLink = $this->generateUrl('register', ['code' => $invitation->getCode()], UrlGeneratorInterface::ABSOLUTE_URL);

            if ($email !== '') {
                $this->sendInvitationEmail($mailer, $email, $role, $inviteLink);
            }
        }

        return $this->render('admin/invite.html.twig', [
            'inviteLink' => $inviteLink,
            'roles' => self::INVITABLE_ROLES,
        ]);
    }

    // Best-effort notification — never blocks the admin action if the transport fails.
    private function notify(MailerInterface $mailer, string $to, string $subject, string $body): void
    {
        try {
            $mailer->send((new Email())
                ->from('no-reply@klevup.fr')
                ->to($to)
                ->subject($subject)
                ->text($body . "\n\nL'équipe Klevup"));
        } catch (\Throwable) {
            // Silently ignore: email is a courtesy, the state change already succeeded.
        }
    }

    private function sendInvitationEmail(MailerInterface $mailer, string $email, string $role, string $inviteLink): void
    {
        try {
            $mailer->send((new Email())
                ->from('no-reply@klevup.fr')
                ->to($email)
                ->subject('Votre invitation Klevup')
                ->text(sprintf(
                    "Bonjour,\n\nVous êtes invité à rejoindre Klevup en tant que %s.\n\nCréez votre compte ici (lien valable 30 jours) :\n%s\n\nL'équipe Klevup",
                    $role === 'ROLE_APPORTEUR' ? 'apporteur d\'affaires' : 'client',
                    $inviteLink
                )));
            $this->addFlash('success', sprintf('Invitation envoyée à %s.', $email));
        } catch (\Throwable) {
            $this->addFlash('error', 'Envoi email impossible — partagez le lien manuellement.');
        }
    }

    #[Route('/admin/users', name: 'admin_users')]
    public function users(Request $request, EntityManagerInterface $em): Response
    {
        $repo = $em->getRepository(User::class);
        [$page, $pages, $offset] = $this->paginate($request, $repo->count([]));

        return $this->render('admin/users.html.twig', [
            'users' => $repo->findBy([], ['createdAt' => 'DESC'], self::PER_PAGE, $offset),
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    private const PER_PAGE = 25;

    /**
     * @return array{0: int, 1: int, 2: int} [page, pages, offset]
     */
    private function paginate(Request $request, int $total): array
    {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $pages);

        return [$page, $pages, ($page - 1) * self::PER_PAGE];
    }

    #[Route('/admin/leads', name: 'admin_leads')]
    public function leads(Request $request, EntityManagerInterface $em): Response
    {
        $repo = $em->getRepository(Lead::class);
        [$page, $pages, $offset] = $this->paginate($request, $repo->count([]));

        return $this->render('admin/leads.html.twig', [
            'leads' => $repo->findBy([], ['createdAt' => 'DESC'], self::PER_PAGE, $offset),
            'statuses' => self::LEAD_STATUSES,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    #[Route('/admin/lead/{id}/update', name: 'admin_lead_update', methods: ['POST'])]
    public function updateLead(Lead $lead, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('lead_update_' . $lead->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $status = (string) $request->request->get('status');
        if (!in_array($status, self::LEAD_STATUSES, true)) {
            $this->addFlash('error', 'Statut invalide.');

            return $this->redirectToRoute('admin_leads');
        }

        $previousStatus = $lead->getStatus();
        $lead->setStatus($status);
        $lead->setCommission(max(0, (int) $request->request->get('commission', $lead->getCommission())));

        // Keep the apporteur informed of every progress change (signed has its own richer email below).
        if ($previousStatus !== $status && $status !== 'signed') {
            $this->notify(
                $mailer,
                $lead->getApporteur()->getEmail(),
                sprintf('[Klevup] Lead %s — %s', $lead->getContact(), self::LEAD_LABELS[$status]),
                sprintf(
                    "Bonjour %s,\n\nVotre lead « %s » (%s) vient de passer au statut : %s.\n%s\nSuivez vos leads : %s",
                    $lead->getApporteur()->getName(),
                    $lead->getContact(),
                    $lead->getSolution(),
                    self::LEAD_LABELS[$status],
                    $status === 'lost' ? "Ce contact n'a pas abouti cette fois — merci pour la mise en relation, le prochain sera le bon !\n" : '',
                    $this->generateUrl('leads', [], UrlGeneratorInterface::ABSOLUTE_URL)
                )
            );
        }

        // A signed lead generates its commission exactly once (unique lead_id).
        if ($status === 'signed' && $em->getRepository(Commission::class)->findOneBy(['lead' => $lead]) === null) {
            $commission = new Commission();
            $commission->setCompanyName($lead->getContact());
            $commission->setSolution($lead->getSolution());
            $commission->setAmount($lead->getCommission());
            $commission->setUser($lead->getApporteur());
            $commission->setLead($lead);
            $em->persist($commission);
            $this->addFlash('success', sprintf('Commission de %d € créée pour %s.', $lead->getCommission(), $lead->getApporteur()->getName()));
            $this->notify(
                $mailer,
                $lead->getApporteur()->getEmail(),
                sprintf('[Klevup] Vente signée — %d € de commission', $lead->getCommission()),
                sprintf(
                    "Bonjour %s,\n\nBonne nouvelle : votre lead « %s » (%s) est signé !\nUne commission de %d € vient d'être créée — elle vous sera versée prochainement.\n\nSuivez vos commissions : %s",
                    $lead->getApporteur()->getName(),
                    $lead->getContact(),
                    $lead->getSolution(),
                    $lead->getCommission(),
                    $this->generateUrl('dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL)
                )
            );
        }

        $em->flush();
        $this->addFlash('success', 'Lead mis à jour.');

        return $this->redirectToRoute('admin_leads');
    }

    #[Route('/admin/tickets', name: 'admin_tickets')]
    public function tickets(Request $request, EntityManagerInterface $em): Response
    {
        $repo = $em->getRepository(Ticket::class);
        [$page, $pages, $offset] = $this->paginate($request, $repo->count([]));

        return $this->render('admin/tickets.html.twig', [
            'tickets' => $repo->findBy([], ['createdAt' => 'DESC'], self::PER_PAGE, $offset),
            'statuses' => self::TICKET_STATUSES,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    #[Route('/admin/ticket/{id}/update', name: 'admin_ticket_update', methods: ['POST'])]
    public function updateTicket(Ticket $ticket, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('ticket_update_' . $ticket->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $status = (string) $request->request->get('status');
        if (!in_array($status, self::TICKET_STATUSES, true)) {
            $this->addFlash('error', 'Statut invalide.');

            return $this->redirectToRoute('admin_tickets');
        }

        $previousStatus = $ticket->getStatus();
        $previousNote = $ticket->getAdminNote();

        $ticket->setStatus($status);
        $note = trim((string) $request->request->get('admin_note'));
        $ticket->setAdminNote($note !== '' ? $note : null);
        $ticket->setResolvedAt($status === 'resolved' ? ($ticket->getResolvedAt() ?? new \DateTimeImmutable()) : null);

        $em->flush();

        // Notify the client only when something they can see actually changed.
        if ($previousStatus !== $status || $previousNote !== $ticket->getAdminNote()) {
            $statusLabels = ['new' => 'Nouveau', 'in_progress' => 'En cours', 'test' => 'En test', 'resolved' => 'Résolu'];
            $body = sprintf(
                "Bonjour %s,\n\nVotre demande %s « %s » a été mise à jour.\n\nStatut : %s",
                $ticket->getUser()->getName(),
                $ticket->getReference(),
                $ticket->getTitle(),
                $statusLabels[$status] ?? $status
            );
            if ($ticket->getAdminNote() !== null) {
                $body .= "\nNote de l'équipe : " . $ticket->getAdminNote();
            }
            $body .= "\n\nSuivez vos demandes : " . $this->generateUrl('support', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->notify($mailer, $ticket->getUser()->getEmail(), sprintf('[Klevup] %s — %s', $ticket->getReference(), $statusLabels[$status] ?? $status), $body);
        }

        $this->addFlash('success', sprintf('Ticket %s mis à jour.', $ticket->getReference()));

        return $this->redirectToRoute('admin_tickets');
    }

    #[Route('/admin/ticket/{id}/message', name: 'admin_ticket_message', methods: ['POST'])]
    public function messageTicket(Ticket $ticket, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('admin_ticket_message_' . $ticket->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $content = trim((string) $request->request->get('content'));
        if ($content === '' || mb_strlen($content) > 5000) {
            $this->addFlash('error', 'Message vide ou trop long.');

            return $this->redirectToRoute('admin_tickets');
        }

        $message = new TicketMessage();
        $message->setTicket($ticket);
        $message->setAuthor($this->getUser());
        $message->setContent($content);
        $em->persist($message);
        $em->flush();

        $this->notify(
            $mailer,
            $ticket->getUser()->getEmail(),
            sprintf('[Klevup] Nouveau message sur %s', $ticket->getReference()),
            sprintf(
                "Bonjour %s,\n\nL'équipe Klevup a répondu à votre demande %s « %s » :\n\n%s\n\nRépondez ici : %s",
                $ticket->getUser()->getName(),
                $ticket->getReference(),
                $ticket->getTitle(),
                $content,
                $this->generateUrl('ticket_show', ['id' => $ticket->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
            )
        );

        $this->addFlash('success', sprintf('Message envoyé sur %s.', $ticket->getReference()));

        return $this->redirectToRoute('admin_tickets');
    }

    #[Route('/admin/commissions', name: 'admin_commissions')]
    public function commissions(Request $request, EntityManagerInterface $em): Response
    {
        $repo = $em->getRepository(Commission::class);
        [$page, $pages, $offset] = $this->paginate($request, $repo->count([]));

        // Totals over ALL commissions, not just the current page.
        $conn = $em->getConnection();
        $totalPending = (int) $conn->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM commission WHERE status != 'encashed'");
        $totalEncashed = (int) $conn->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM commission WHERE status = 'encashed'");

        return $this->render('admin/commissions.html.twig', [
            'commissions' => $repo->findBy([], ['createdAt' => 'DESC'], self::PER_PAGE, $offset),
            'totalPending' => $totalPending,
            'totalEncashed' => $totalEncashed,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    #[Route('/admin/commission/{id}/encash', name: 'admin_commission_encash', methods: ['POST'])]
    public function encashCommission(Commission $commission, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('commission_encash_' . $commission->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        if ($commission->getStatus() !== 'encashed') {
            $commission->setStatus('encashed');
            $commission->setEncashedAt(new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('success', sprintf('Commission de %d € marquée encaissée.', $commission->getAmount()));
            $this->notify(
                $mailer,
                $commission->getUser()->getEmail(),
                sprintf('[Klevup] Commission de %d € versée', $commission->getAmount()),
                sprintf(
                    "Bonjour %s,\n\nVotre commission de %d € (%s — %s) vient d'être versée.\n\nSuivez vos commissions : %s",
                    $commission->getUser()->getName(),
                    $commission->getAmount(),
                    $commission->getCompanyName(),
                    $commission->getSolution(),
                    $this->generateUrl('dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL)
                )
            );
        }

        return $this->redirectToRoute('admin_commissions');
    }

    #[Route('/admin/applications', name: 'admin_applications')]
    public function applications(EntityManagerInterface $em): Response
    {
        $clients = array_filter(
            $em->getRepository(User::class)->findAll(),
            fn(User $u) => in_array('ROLE_CLIENT', $u->getRoles(), true)
        );

        return $this->render('admin/applications.html.twig', [
            'applications' => $em->getRepository(Application::class)->findBy([], ['id' => 'DESC']),
            'clients' => $clients,
        ]);
    }

    #[Route('/admin/application/create', name: 'admin_application_create', methods: ['POST'])]
    public function createApplication(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('application_create', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $name = trim((string) $request->request->get('name'));
        $client = $em->getRepository(User::class)->find((int) $request->request->get('client_id'));

        if ($name === '' || $client === null || !in_array('ROLE_CLIENT', $client->getRoles(), true)) {
            $this->addFlash('error', 'Nom et client (rôle client) sont obligatoires.');

            return $this->redirectToRoute('admin_applications');
        }

        $app = new Application();
        $app->setName($name);
        $app->setDescription(trim((string) $request->request->get('description')));
        $app->setVersion(trim((string) $request->request->get('version')) ?: '1.0');
        $app->setUrl(trim((string) $request->request->get('url')));
        $app->setClient($client);
        $launched = (string) $request->request->get('launched_at');
        $app->setLaunchedAt($launched !== '' ? new \DateTimeImmutable($launched) : null);
        $maintenance = (string) $request->request->get('maintenance_until');
        $app->setMaintenanceUntil($maintenance !== '' ? new \DateTimeImmutable($maintenance) : null);

        $em->persist($app);
        $em->flush();
        $this->addFlash('success', sprintf('Application « %s » créée pour %s.', $app->getName(), $client->getName()));

        return $this->redirectToRoute('admin_applications');
    }

    #[Route('/admin/application/{id}/update', name: 'admin_application_update', methods: ['POST'])]
    public function updateApplication(Application $app, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('application_update_' . $app->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $version = trim((string) $request->request->get('version'));
        if ($version !== '') {
            $app->setVersion($version);
        }
        $maintenance = (string) $request->request->get('maintenance_until');
        $app->setMaintenanceUntil($maintenance !== '' ? new \DateTimeImmutable($maintenance) : null);

        $em->flush();
        $this->addFlash('success', sprintf('Application « %s » mise à jour.', $app->getName()));

        return $this->redirectToRoute('admin_applications');
    }

    #[Route('/admin/user/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        // Prevent an admin from deleting their own account while logged in.
        if ($user->getUserIdentifier() === $this->getUser()?->getUserIdentifier()) {
            $this->addFlash('error', 'Impossible de supprimer votre propre compte.');

            return $this->redirectToRoute('admin_users');
        }

        // Block deletion while related records exist (FK constraints, no cascade).
        if (!$user->getLeads()->isEmpty() || !$user->getCommissions()->isEmpty() || !$user->getTickets()->isEmpty()) {
            $this->addFlash('error', 'Utilisateur lié à des leads, commissions ou tickets — suppression impossible.');

            return $this->redirectToRoute('admin_users');
        }

        $em->remove($user);
        $em->flush();

        return $this->redirectToRoute('admin_users');
    }
}
