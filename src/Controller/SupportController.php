<?php
namespace App\Controller;

use App\Entity\Application;
use App\Entity\Lead;
use App\Entity\Product;
use App\Entity\Ticket;
use App\Repository\LeadRepository;
use App\Repository\ProductRepository;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
class SupportController extends AbstractController
{
    public const MAINTENANCE_WARNING_DAYS = 30;

    #[Route('/support', name: 'support')]
    public function index(EntityManagerInterface $em, ProductRepository $products): Response
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

        $apps = $em->getRepository(Application::class)->findBy(['client' => $user], ['id' => 'ASC']);
        $warnLimit = new \DateTimeImmutable('today +' . self::MAINTENANCE_WARNING_DAYS . ' days');
        $owned = array_filter(array_map(fn(Application $a) => $a->getProduct()?->getId(), $apps));

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
            'applications' => array_map(fn(Application $a) => [
                'id' => $a->getId(),
                'name' => $a->getName(),
                'description' => $a->getDescription(),
                'launch_date' => $a->getLaunchedAt()?->format('d/m/Y') ?? '—',
                'version' => $a->getVersion(),
                'url' => $a->getUrl(),
                'maintenance_until' => $a->getMaintenanceUntil()?->format('d/m/Y'),
                'maintenance_expiring' => $a->getMaintenanceUntil() !== null && $a->getMaintenanceUntil() <= $warnLimit,
                'maintenance_expired' => $a->getMaintenanceUntil() !== null && $a->getMaintenanceUntil() < new \DateTimeImmutable('today'),
            ], $apps),
            'suggestions' => array_values(array_filter($products->findActive(), fn(Product $p) => !in_array($p->getId(), $owned, true))),
            'tickets' => $ticketsData,
        ]);
    }

    #[Route('/support/interest/{id}', name: 'client_interest', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function interest(Product $product, Request $request, EntityManagerInterface $em, LeadRepository $leads, Notifier $notifier): Response
    {
        if (!$this->isCsrfTokenValid('client_interest_' . $product->getId(), (string) $request->request->get('_token')) || !$product->isActive()) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->createClientLead($em, $leads, $notifier, $product->getName(), $product, 'Client existant intéressé par une nouvelle solution.');
        $this->addFlash('success', sprintf('Merci ! Nous vous recontactons très vite au sujet de « %s ».', $product->getName()));

        return $this->redirectToRoute('support');
    }

    #[Route('/support/application/{id}/renew', name: 'client_renew', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function renew(Application $app, Request $request, EntityManagerInterface $em, LeadRepository $leads, Notifier $notifier): Response
    {
        if ($app->getClient()->getId() !== $this->getUser()?->getId()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('client_renew_' . $app->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->createClientLead(
            $em, $leads, $notifier,
            'Maintenance — ' . $app->getName(),
            null,
            sprintf('Renouvellement de maintenance demandé (fin actuelle : %s).', $app->getMaintenanceUntil()?->format('d/m/Y') ?? '—')
        );
        $this->addFlash('success', 'Demande de renouvellement envoyée — nous vous adressons une proposition sous 48 h.');

        return $this->redirectToRoute('support');
    }

    private function createClientLead(EntityManagerInterface $em, LeadRepository $leads, Notifier $notifier, string $solution, ?Product $product, string $context): void
    {
        $user = $this->getUser();

        // A second click while the first request is still open must not create a duplicate.
        if ($leads->findOpenDuplicate($user->getName(), $user->getEmail(), $solution) !== null) {
            return;
        }

        $lead = (new Lead())
            ->setContact($user->getName())
            ->setContactName($user->getName())
            ->setEmail($user->getEmail())
            ->setSolution($solution)
            ->setProduct($product)
            ->setSource(Lead::SOURCE_CLIENT)
            ->setConsentAt(new \DateTimeImmutable())
            ->setNotes($context);
        $em->persist($lead);
        $em->flush();

        $notifier->toAdmins(
            sprintf('[Klevup] Opportunité client — %s (%s)', $user->getName(), $solution),
            LeadController::describeLead($lead, $context) . "\n\nTraiter : " . $this->generateUrl('admin_leads', [], UrlGeneratorInterface::ABSOLUTE_URL)
        );
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
