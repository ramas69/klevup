<?php
namespace App\Controller;

use App\Entity\Application;
use App\Entity\Commission;
use App\Entity\Invitation;
use App\Entity\Lead;
use App\Entity\Ticket;
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
    private const LEAD_STATUSES = ['new', 'meeting', 'devis', 'signed'];
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
    public function users(EntityManagerInterface $em): Response
    {
        $users = $em->getRepository(User::class)->findAll();

        return $this->render('admin/users.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/admin/leads', name: 'admin_leads')]
    public function leads(EntityManagerInterface $em): Response
    {
        return $this->render('admin/leads.html.twig', [
            'leads' => $em->getRepository(Lead::class)->findBy([], ['createdAt' => 'DESC']),
            'statuses' => self::LEAD_STATUSES,
        ]);
    }

    #[Route('/admin/lead/{id}/update', name: 'admin_lead_update', methods: ['POST'])]
    public function updateLead(Lead $lead, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('lead_update_' . $lead->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $status = (string) $request->request->get('status');
        if (!in_array($status, self::LEAD_STATUSES, true)) {
            $this->addFlash('error', 'Statut invalide.');

            return $this->redirectToRoute('admin_leads');
        }

        $lead->setStatus($status);
        $lead->setCommission(max(0, (int) $request->request->get('commission', $lead->getCommission())));

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
        }

        $em->flush();
        $this->addFlash('success', 'Lead mis à jour.');

        return $this->redirectToRoute('admin_leads');
    }

    #[Route('/admin/tickets', name: 'admin_tickets')]
    public function tickets(EntityManagerInterface $em): Response
    {
        return $this->render('admin/tickets.html.twig', [
            'tickets' => $em->getRepository(Ticket::class)->findBy([], ['createdAt' => 'DESC']),
            'statuses' => self::TICKET_STATUSES,
        ]);
    }

    #[Route('/admin/ticket/{id}/update', name: 'admin_ticket_update', methods: ['POST'])]
    public function updateTicket(Ticket $ticket, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('ticket_update_' . $ticket->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $status = (string) $request->request->get('status');
        if (!in_array($status, self::TICKET_STATUSES, true)) {
            $this->addFlash('error', 'Statut invalide.');

            return $this->redirectToRoute('admin_tickets');
        }

        $ticket->setStatus($status);
        $note = trim((string) $request->request->get('admin_note'));
        $ticket->setAdminNote($note !== '' ? $note : null);
        $ticket->setResolvedAt($status === 'resolved' ? ($ticket->getResolvedAt() ?? new \DateTimeImmutable()) : null);

        $em->flush();
        $this->addFlash('success', sprintf('Ticket %s mis à jour.', $ticket->getReference()));

        return $this->redirectToRoute('admin_tickets');
    }

    #[Route('/admin/commissions', name: 'admin_commissions')]
    public function commissions(EntityManagerInterface $em): Response
    {
        return $this->render('admin/commissions.html.twig', [
            'commissions' => $em->getRepository(Commission::class)->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/admin/commission/{id}/encash', name: 'admin_commission_encash', methods: ['POST'])]
    public function encashCommission(Commission $commission, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('commission_encash_' . $commission->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        if ($commission->getStatus() !== 'encashed') {
            $commission->setStatus('encashed');
            $commission->setEncashedAt(new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('success', sprintf('Commission de %d € marquée encaissée.', $commission->getAmount()));
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
