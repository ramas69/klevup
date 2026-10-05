<?php
namespace App\Controller;

use App\Entity\Application;
use App\Entity\ApporteurRequest;
use App\Entity\Commission;
use App\Entity\Invitation;
use App\Entity\Lead;
use App\Entity\Product;
use App\Entity\Ticket;
use App\Entity\TicketMessage;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Service\CommissionCalculator;
use App\Service\Notifier;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    private const INVITABLE_ROLES = ['ROLE_APPORTEUR', 'ROLE_CLIENT'];
    private const CSRF_ERROR = 'Jeton CSRF invalide.';
    public const LEAD_STATUSES = ['new', 'meeting', 'devis', 'signed', 'lost'];
    public const LEAD_LABELS = ['new' => 'Nouveau', 'meeting' => 'RDV planifié', 'devis' => 'Devis envoyé', 'signed' => 'Signé', 'lost' => 'Sans suite'];
    private const TICKET_STATUSES = ['new', 'in_progress', 'test', 'resolved'];
    private const PER_PAGE = 25;

    public function __construct(private readonly Notifier $notifier)
    {
    }

    #[Route('/admin', name: 'admin_dashboard')]
    public function dashboard(EntityManagerInterface $em): Response
    {
        $signed = "CASE WHEN l.status = 'signed' THEN 1 ELSE 0 END";
        $open = "CASE WHEN l.status NOT IN ('signed', 'lost') THEN 1 ELSE 0 END";
        $revenue = "CASE WHEN l.status = 'signed' THEN COALESCE(l.dealAmount, 0) ELSE 0 END";
        $pipeline = "CASE WHEN l.status NOT IN ('signed', 'lost') THEN COALESCE(l.dealAmount, 0) ELSE 0 END";

        $byProduct = $em->createQuery("SELECT l.solution AS name, COUNT(l.id) AS total, SUM($open) AS open, SUM($signed) AS signed, SUM($revenue) AS revenue, SUM($pipeline) AS pipeline FROM App\Entity\Lead l GROUP BY l.solution ORDER BY revenue DESC, total DESC")->getArrayResult();
        $bySource = $em->createQuery("SELECT l.source AS source, COUNT(l.id) AS total, SUM($signed) AS signed, SUM($revenue) AS revenue FROM App\Entity\Lead l GROUP BY l.source")->getArrayResult();
        $byApporteur = $em->createQuery("SELECT a.id, a.name, a.iban, COUNT(l.id) AS total, SUM($signed) AS signed, SUM($revenue) AS revenue FROM App\Entity\Lead l JOIN l.apporteur a GROUP BY a.id, a.name, a.iban ORDER BY revenue DESC, total DESC")->getArrayResult();

        $pendingByUser = [];
        foreach ($em->createQuery("SELECT IDENTITY(c.user) AS uid, SUM(c.amount) AS amount FROM App\Entity\Commission c WHERE c.status != 'encashed' GROUP BY c.user")->getArrayResult() as $row) {
            $pendingByUser[$row['uid']] = (int) $row['amount'];
        }

        $now = new \DateTimeImmutable('today');
        $expiring = $em->createQuery('SELECT a FROM App\Entity\Application a WHERE a.maintenanceUntil IS NOT NULL AND a.maintenanceUntil <= :limit ORDER BY a.maintenanceUntil ASC')
            ->setParameter('limit', $now->modify('+60 days'))
            ->getResult();

        $quarterStart = CommissionCalculator::quarterStart($now);

        return $this->render('admin/dashboard.html.twig', [
            'byProduct' => $byProduct,
            'bySource' => $bySource,
            'byApporteur' => $byApporteur,
            'pendingByUser' => $pendingByUser,
            'expiring' => $expiring,
            'today' => $now,
            'kpi' => [
                'new_leads' => $em->getRepository(Lead::class)->count(['status' => 'new']),
                'open_tickets' => (int) $em->createQuery("SELECT COUNT(t.id) FROM App\Entity\Ticket t WHERE t.status != 'resolved'")->getSingleScalarResult(),
                'to_pay' => array_sum($pendingByUser),
                'requests' => $em->getRepository(ApporteurRequest::class)->count(['handledAt' => null]),
                'revenue_quarter' => (int) $em->createQuery("SELECT COALESCE(SUM(l.dealAmount), 0) FROM App\Entity\Lead l WHERE l.status = 'signed' AND l.signedAt >= :from")->setParameter('from', $quarterStart)->getSingleScalarResult(),
            ],
        ]);
    }

    #[Route('/admin/invite', name: 'admin_invite')]
    public function invite(Request $request, EntityManagerInterface $em): Response
    {
        $inviteLink = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('invite', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(self::CSRF_ERROR);
            }

            $role = (string) $request->request->get('role', 'ROLE_APPORTEUR');
            if (!in_array($role, self::INVITABLE_ROLES, true)) {
                $role = 'ROLE_APPORTEUR';
            }

            $email = trim((string) $request->request->get('email'));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Email invalide — invitation non créée.');

                return $this->redirectToRoute('admin_invite');
            }

            $inviteLink = $this->createInvitation($em, $role, $email !== '' ? $email : null);
        }

        return $this->render('admin/invite.html.twig', [
            'inviteLink' => $inviteLink,
            'roles' => self::INVITABLE_ROLES,
            'requests' => $em->getRepository(ApporteurRequest::class)->findBy(['handledAt' => null], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/admin/apporteur-request/{id}/{action}', name: 'admin_apporteur_request', methods: ['POST'], requirements: ['action' => 'invite|dismiss'])]
    public function handleApporteurRequest(ApporteurRequest $apporteurRequest, string $action, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('apporteur_request_' . $apporteurRequest->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        // Double click / back button: never send a second invitation.
        if ($apporteurRequest->getHandledAt() !== null) {
            return $this->redirectToRoute('admin_invite');
        }

        $apporteurRequest->setHandledAt(new \DateTimeImmutable());
        if ($action === 'invite') {
            $this->createInvitation($em, 'ROLE_APPORTEUR', $apporteurRequest->getEmail(), $apporteurRequest->getReferrer());
        } else {
            $em->flush();
            $this->addFlash('success', sprintf('Candidature de %s archivée.', $apporteurRequest->getName()));
        }

        return $this->redirectToRoute('admin_invite');
    }

    private function createInvitation(EntityManagerInterface $em, string $role, ?string $email, ?User $referrer = null): string
    {
        $invitation = new Invitation();
        $invitation->setReferrer($referrer);
        $invitation->setCode(bin2hex(random_bytes(16)));
        $invitation->setRole($role);
        $invitation->setEmail($email);
        $em->persist($invitation);
        $em->flush();

        $link = $this->generateUrl('register', ['code' => $invitation->getCode()], UrlGeneratorInterface::ABSOLUTE_URL);

        if ($email !== null) {
            $sent = $this->notifier->send($email, 'Votre invitation Klevup', sprintf(
                "Bonjour,\n\nVous êtes invité à rejoindre Klevup en tant que %s.\n\nCréez votre compte ici (lien valable 30 jours) :\n%s",
                $role === 'ROLE_APPORTEUR' ? 'apporteur d\'affaires' : 'client',
                $link
            ));
            $sent
                ? $this->addFlash('success', sprintf('Invitation envoyée à %s.', $email))
                : $this->addFlash('error', 'Envoi email impossible — partagez le lien manuellement : ' . $link);
        }

        return $link;
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
            'labels' => self::LEAD_LABELS,
            'lostReasons' => Lead::LOST_REASONS,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    #[Route('/admin/lead/{id}/update', name: 'admin_lead_update', methods: ['POST'])]
    public function updateLead(Lead $lead, Request $request, EntityManagerInterface $em, CommissionCalculator $calculator): Response
    {
        if (!$this->isCsrfTokenValid('lead_update_' . $lead->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $status = (string) $request->request->get('status');
        if (!in_array($status, self::LEAD_STATUSES, true)) {
            $this->addFlash('error', 'Statut invalide.');

            return $this->redirectToRoute('admin_leads');
        }

        $rawAmount = trim((string) $request->request->get('deal_amount'));
        $dealAmount = $rawAmount === '' ? null : max(0, (int) $rawAmount);
        if ($status === 'signed' && !$dealAmount) {
            $this->addFlash('error', sprintf('« %s » : renseignez le montant de la vente pour le passer en Signé.', $lead->getContact()));

            return $this->redirectToRoute('admin_leads');
        }

        $lostReason = $request->request->getString('lost_reason');
        if ($status === 'lost' && !array_key_exists($lostReason, Lead::LOST_REASONS)) {
            $this->addFlash('error', sprintf('« %s » : indiquez le motif — l\'apporteur le verra.', $lead->getContact()));

            return $this->redirectToRoute('admin_leads');
        }

        $commission = $em->getRepository(Commission::class)->findOneBy(['lead' => $lead]);
        $previousStatus = $lead->getStatus();

        // A paid commission freezes the deal: un-signing or re-pricing it would silently desync the books.
        if ($commission?->getStatus() === 'encashed' && ($status !== 'signed' || $dealAmount !== $lead->getDealAmount())) {
            $this->addFlash('error', sprintf('« %s » : la commission est déjà versée — vente non modifiable.', $lead->getContact()));

            return $this->redirectToRoute('admin_leads');
        }
        if ($commission?->getStatus() === 'encashed') {
            // Nothing editable left; never recompute a paid amount (the product rate may have changed since).
            return $this->redirectToRoute('admin_leads');
        }
        $previousSignedAt = $lead->getSignedAt();

        $lead->setStatus($status);
        $lead->setDealAmount($dealAmount);
        $lead->setLostReason($status === 'lost' ? $lostReason : null);
        if ($status === 'signed') {
            $lead->setSignedAt($lead->getSignedAt() ?? new \DateTimeImmutable());
        } else {
            $lead->setSignedAt(null);
        }
        $lead->setCommission($calculator->amountFor($lead));

        $apporteur = $lead->getApporteur();

        if ($status !== 'signed' && $commission !== null) {
            // Deal fell through after signature: the pending commission is cancelled.
            $em->remove($commission);
            $this->addFlash('success', sprintf('Commission de %d € annulée.', $commission->getAmount()));
        }
        if ($status !== 'signed' && $previousStatus === 'signed' && $apporteur !== null) {
            $this->revokeReferralBonusIfNoSale($em, $apporteur, $lead);
        }

        if ($status === 'signed' && $apporteur !== null) {
            if ($commission === null) {
                $commission = new Commission();
                $commission->setCompanyName($lead->getContact());
                $commission->setSolution($lead->getSolution());
                $commission->setUser($apporteur);
                $commission->setLead($lead);
                $commission->setAmount($lead->getCommission());
                $commission->setDueAt(CommissionCalculator::dueDate($lead->getSignedAt()));
                $em->persist($commission);
                $this->grantReferralBonus($em, $apporteur);
                $this->addFlash('success', sprintf('Commission de %d € créée pour %s.', $lead->getCommission(), $apporteur->getName()));
                $this->notifier->send(
                    $apporteur->getEmail(),
                    sprintf('[Klevup] Vente signée — %d € de commission', $lead->getCommission()),
                    sprintf(
                        "Bonjour %s,\n\nBonne nouvelle : votre lead « %s » (%s) est signé !\nUne commission de %d € vient d'être créée — versement prévu le %s.%s\n\nSuivez vos commissions : %s",
                        $apporteur->getName(),
                        $lead->getContact(),
                        $lead->getSolution(),
                        $lead->getCommission(),
                        $commission->getDueAt()->format('d/m/Y'),
                        $apporteur->getIban() === null ? "\n\nPensez à renseigner votre IBAN dans votre profil pour recevoir le virement." : '',
                        $this->generateUrl('commissions', [], UrlGeneratorInterface::ABSOLUTE_URL)
                    )
                );
            } elseif ($commission->getAmount() !== $lead->getCommission()) {
                $this->addFlash('success', sprintf('Commission ajustée : %d € → %d €.', $commission->getAmount(), $lead->getCommission()));
                $commission->setAmount($lead->getCommission());
            }
        }

        if ($apporteur !== null && $previousStatus !== $status && $status !== 'signed') {
            $this->notifier->send(
                $apporteur->getEmail(),
                sprintf('[Klevup] Lead %s — %s', $lead->getContact(), self::LEAD_LABELS[$status]),
                sprintf(
                    "Bonjour %s,\n\nVotre lead « %s » (%s) vient de passer au statut : %s.\n%s\nSuivez vos leads : %s",
                    $apporteur->getName(),
                    $lead->getContact(),
                    $lead->getSolution(),
                    self::LEAD_LABELS[$status],
                    $status === 'lost' ? sprintf("Motif : %s.\nCe contact n'a pas abouti cette fois — merci pour la mise en relation, le prochain sera le bon !\n", Lead::LOST_REASONS[$lostReason]) : '',
                    $this->generateUrl('leads', [], UrlGeneratorInterface::ABSOLUTE_URL)
                )
            );
        }

        $em->flush();

        // Signing, un-signing or re-dating a sale moves the tier of the sales signed after it.
        if ($apporteur !== null) {
            foreach (array_unique(array_filter([$previousSignedAt, $lead->getSignedAt()]), SORT_REGULAR) as $day) {
                $this->rebalanceQuarter($em, $calculator, $apporteur, $day);
            }
        }

        $this->addFlash('success', sprintf('Lead « %s » mis à jour.', $lead->getContact()));

        return $this->redirectToRoute('admin_leads');
    }

    // First signed sale of a referred apporteur → fixed bonus for the referrer (once per referee, enforced by a unique key).
    private function grantReferralBonus(EntityManagerInterface $em, User $referee): void
    {
        $referrer = $referee->getReferredBy();
        if ($referrer === null || $referrer->getStatus() === 'disabled' || $em->getRepository(Commission::class)->findOneBy(['referralOf' => $referee]) !== null) {
            return;
        }

        $bonus = (new Commission())
            ->setCompanyName($referee->getName())
            ->setSolution(Commission::REFERRAL_LABEL)
            ->setAmount(CommissionCalculator::REFERRAL_BONUS)
            ->setUser($referrer)
            ->setReferralOf($referee)
            ->setDueAt(CommissionCalculator::dueDate(new \DateTimeImmutable()));
        $em->persist($bonus);

        $this->notifier->send(
            $referrer->getEmail(),
            sprintf('[Klevup] Prime de parrainage — %d €', CommissionCalculator::REFERRAL_BONUS),
            sprintf(
                "Bonjour %s,\n\n%s, que vous avez parrainé(e), vient de signer sa première vente !\nVous recevez une prime de %d € — versement prévu le %s.\n\nMerci de faire grandir le réseau : %s",
                $referrer->getName(),
                $referee->getName(),
                CommissionCalculator::REFERRAL_BONUS,
                $bonus->getDueAt()->format('d/m/Y'),
                $this->generateUrl('referral', [], UrlGeneratorInterface::ABSOLUTE_URL)
            )
        );
    }

    private function revokeReferralBonusIfNoSale(EntityManagerInterface $em, User $referee, Lead $unsigned): void
    {
        $bonus = $em->getRepository(Commission::class)->findOneBy(['referralOf' => $referee, 'status' => 'pending']);
        $otherSales = (int) $em->createQuery("SELECT COUNT(l.id) FROM App\Entity\Lead l WHERE l.apporteur = :u AND l.status = 'signed' AND l.id != :lead")
            ->setParameter('u', $referee)
            ->setParameter('lead', $unsigned->getId())
            ->getSingleScalarResult();
        if ($bonus !== null && $otherSales === 0) {
            $em->remove($bonus);
        }
    }

    // Re-rates the quarter's unpaid commissions in signing order; paid ones are never touched.
    private function rebalanceQuarter(EntityManagerInterface $em, CommissionCalculator $calculator, User $apporteur, \DateTimeImmutable $day): void
    {
        $from = CommissionCalculator::quarterStart($day);
        $leads = $em->createQuery("SELECT l FROM App\Entity\Lead l WHERE l.apporteur = :a AND l.status = 'signed' AND l.signedAt >= :from AND l.signedAt < :to ORDER BY l.signedAt ASC, l.id ASC")
            ->setParameter('a', $apporteur)
            ->setParameter('from', $from)
            ->setParameter('to', $from->modify('+3 months'))
            ->getResult();

        foreach ($leads as $signed) {
            $commission = $em->getRepository(Commission::class)->findOneBy(['lead' => $signed]);
            if ($commission === null || $commission->getStatus() === 'encashed') {
                continue;
            }
            $amount = $calculator->amountFor($signed);
            $signed->setCommission($amount);
            $commission->setAmount($amount);
        }
        $em->flush();
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
            $body .= "\n\nSuivez vos demandes : " . $this->generateUrl('ticket_show', ['id' => $ticket->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->notifier->send($ticket->getUser()->getEmail(), sprintf('[Klevup] %s — %s', $ticket->getReference(), $statusLabels[$status] ?? $status), $body);
        }

        $this->addFlash('success', sprintf('Ticket %s mis à jour.', $ticket->getReference()));

        return $this->redirectToRoute('admin_tickets');
    }

    #[Route('/admin/ticket/{id}/message', name: 'admin_ticket_message', methods: ['POST'])]
    public function messageTicket(Ticket $ticket, Request $request, EntityManagerInterface $em): Response
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

        $this->notifier->send(
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
    public function encashCommission(Commission $commission, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('commission_encash_' . $commission->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        if ($commission->getStatus() !== 'encashed') {
            $commission->setStatus('encashed');
            $commission->setEncashedAt(new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('success', sprintf('Commission de %d € marquée versée.', $commission->getAmount()));
            $this->notifier->send(
                $commission->getUser()->getEmail(),
                sprintf('[Klevup] Commission de %d € versée', $commission->getAmount()),
                sprintf(
                    "Bonjour %s,\n\nVotre commission de %d € (%s — %s) vient d'être versée.\n\nSuivez vos commissions : %s",
                    $commission->getUser()->getName(),
                    $commission->getAmount(),
                    $commission->getCompanyName(),
                    $commission->getSolution(),
                    $this->generateUrl('commissions', [], UrlGeneratorInterface::ABSOLUTE_URL)
                )
            );
        }

        return $this->redirectToRoute('admin_commissions');
    }

    #[Route('/admin/commission/{id}/due', name: 'admin_commission_due', methods: ['POST'])]
    public function commissionDueDate(Commission $commission, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('commission_due_' . $commission->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $due = $this->parseDate($request->request->get('due_at'));
        if ($due === false || $due === null || $commission->getStatus() === 'encashed') {
            $this->addFlash('error', 'Date de versement invalide.');

            return $this->redirectToRoute('admin_commissions');
        }

        $commission->setDueAt($due);
        $em->flush();
        $this->notifier->send(
            $commission->getUser()->getEmail(),
            sprintf('[Klevup] Date de versement de votre commission de %d €', $commission->getAmount()),
            sprintf("Bonjour %s,\n\nVotre commission de %d € (%s) sera versée le %s.\n\nSuivez vos commissions : %s", $commission->getUser()->getName(), $commission->getAmount(), $commission->getCompanyName(), $due->format('d/m/Y'), $this->generateUrl('commissions', [], UrlGeneratorInterface::ABSOLUTE_URL))
        );
        $this->addFlash('success', sprintf('Versement prévu le %s — l\'apporteur est prévenu.', $due->format('d/m/Y')));

        return $this->redirectToRoute('admin_commissions');
    }

    #[Route('/admin/releve/{id}/{period}', name: 'admin_statement', requirements: ['period' => '\d{4}-\d{2}'])]
    public function statement(User $user, string $period, EntityManagerInterface $em): Response
    {
        return $this->render('commission/statement.html.twig', CommissionController::statementData($em, $user, $period));
    }

    #[Route('/admin/applications', name: 'admin_applications')]
    public function applications(EntityManagerInterface $em, UserRepository $users, ProductRepository $products): Response
    {
        return $this->render('admin/applications.html.twig', [
            'applications' => $em->getRepository(Application::class)->findBy([], ['id' => 'DESC']),
            'clients' => $users->findByRole('ROLE_CLIENT'),
            'products' => $products->findBy([], ['position' => 'ASC']),
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

        $launched = $this->parseDate($request->request->get('launched_at'));
        $maintenance = $this->parseDate($request->request->get('maintenance_until'));
        if ($launched === false || $maintenance === false) {
            $this->addFlash('error', 'Date invalide (format attendu : AAAA-MM-JJ).');

            return $this->redirectToRoute('admin_applications');
        }

        $app = new Application();
        $app->setName($name);
        $app->setDescription(trim((string) $request->request->get('description')));
        $app->setVersion(trim((string) $request->request->get('version')) ?: '1.0');
        $app->setUrl(trim((string) $request->request->get('url')));
        $app->setClient($client);
        $app->setProduct($em->getRepository(Product::class)->find((int) $request->request->get('product_id')));
        $app->setLaunchedAt($launched);
        $app->setMaintenanceUntil($maintenance);

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

        $maintenance = $this->parseDate($request->request->get('maintenance_until'));
        if ($maintenance === false) {
            $this->addFlash('error', 'Date invalide (format attendu : AAAA-MM-JJ).');

            return $this->redirectToRoute('admin_applications');
        }

        $version = trim((string) $request->request->get('version'));
        if ($version !== '') {
            $app->setVersion($version);
        }
        $app->setMaintenanceUntil($maintenance);

        $em->flush();
        $this->addFlash('success', sprintf('Application « %s » mise à jour.', $app->getName()));

        return $this->redirectToRoute('admin_applications');
    }

    /** null when empty, false when malformed. */
    private function parseDate(mixed $value): \DateTimeImmutable|null|false
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : false;
    }

    #[Route('/admin/products', name: 'admin_products')]
    public function products(ProductRepository $products): Response
    {
        return $this->render('admin/products.html.twig', [
            'products' => $products->findBy([], ['position' => 'ASC', 'id' => 'ASC']),
        ]);
    }

    #[Route('/admin/product/save/{id}', name: 'admin_product_save', methods: ['POST'], defaults: ['id' => null])]
    public function saveProduct(?int $id, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('product_save_' . ($id ?? 'new'), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        $product = $id === null ? new Product() : $em->getRepository(Product::class)->find($id);
        if ($product === null) {
            throw $this->createNotFoundException();
        }

        $name = trim((string) $request->request->get('name'));
        $rate = (int) $request->request->get('commission_rate', CommissionCalculator::DEFAULT_RATE);
        if ($name === '' || mb_strlen($name) > 255 || $rate < 0 || $rate > 50) {
            $this->addFlash('error', 'Nom obligatoire et taux de commission entre 0 et 50 %.');

            return $this->redirectToRoute('admin_products');
        }

        $product->setName($name);
        $product->setDescription(mb_substr(trim((string) $request->request->get('description')), 0, 500));
        $product->setPriceFrom(max(0, (int) $request->request->get('price_from')));
        $product->setTarget(mb_substr(trim($request->request->getString('target')), 0, 255) ?: null);
        $product->setPitch(trim($request->request->getString('pitch')) ?: null);
        $resource = trim($request->request->getString('resource_url'));
        $product->setResourceUrl(preg_match('#^https?://#', $resource) && mb_strlen($resource) <= 500 ? $resource : null);
        $product->setCommissionRate($rate);
        $product->setPosition((int) $request->request->get('position'));
        $product->setActive($request->request->getBoolean('active'));
        $product->setFeatured($request->request->getBoolean('featured'));
        $em->persist($product);
        $em->flush();

        $this->addFlash('success', sprintf('Produit « %s » enregistré.', $product->getName()));

        return $this->redirectToRoute('admin_products');
    }

    #[Route('/admin/user/{id}/toggle', name: 'admin_user_toggle', methods: ['POST'])]
    public function toggleUser(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('toggle_user_' . $user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        if ($user->getId() === $this->getUser()?->getId()) {
            $this->addFlash('error', 'Impossible de désactiver votre propre compte.');

            return $this->redirectToRoute('admin_users');
        }

        $user->setStatus($user->getStatus() === 'disabled' ? 'active' : 'disabled');
        $em->flush();
        $this->addFlash('success', sprintf('Compte de %s %s.', $user->getName(), $user->getStatus() === 'disabled' ? 'désactivé' : 'réactivé'));

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/admin/user/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(self::CSRF_ERROR);
        }

        // Prevent an admin from deleting their own account while logged in.
        if ($user->getId() === $this->getUser()?->getId()) {
            $this->addFlash('error', 'Impossible de supprimer votre propre compte.');

            return $this->redirectToRoute('admin_users');
        }

        // Any linked record (lead, commission, ticket, message, application) blocks deletion at the DB level.
        try {
            $em->remove($user);
            $em->flush();
            $this->addFlash('success', sprintf('Utilisateur %s supprimé.', $user->getName()));
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', 'Utilisateur lié à des leads, commissions, tickets ou applications — désactivez-le plutôt.');
        }

        return $this->redirectToRoute('admin_users');
    }
}
