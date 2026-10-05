<?php
namespace App\Controller;

use App\Entity\Lead;
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

#[IsGranted('ROLE_APPORTEUR')]
class LeadController extends AbstractController
{
    public const OTHER_SOLUTION = 'Autre / sur-mesure';

    #[Route('/leads', name: 'leads')]
    public function list(EntityManagerInterface $em): Response
    {
        $leads = $em->getRepository(Lead::class)->findBy(
            ['apporteur' => $this->getUser()],
            ['createdAt' => 'DESC']
        );

        $signed = array_filter($leads, fn(Lead $l) => $l->getStatus() === 'signed');
        $active = array_filter($leads, fn(Lead $l) => !in_array($l->getStatus(), ['signed', 'lost'], true));

        return $this->render('lead/list.html.twig', [
            'leads' => $leads,
            'stats' => [
                'total' => count($leads),
                'in_progress' => count($active),
                'signed' => count($signed),
                'potential' => array_sum(array_map(fn(Lead $l) => $l->getCommission(), $active)),
            ],
        ]);
    }

    #[Route('/lead/new', name: 'lead_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, ProductRepository $products, LeadRepository $leads, Notifier $notifier): Response
    {
        $catalogue = $products->findActive();

        if (!$request->isMethod('POST')) {
            return $this->render('lead/new.html.twig', ['products' => $catalogue, 'form' => ['product_id' => $request->query->getString('product')]]);
        }

        if (!$this->isCsrfTokenValid('lead_new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $form = LeadController::readForm($request);
        $product = $products->findOneBy(['id' => (int) ($form['product_id'] ?? 0), 'active' => true]);
        $error = self::validateContact($form);

        $error ??= self::validateQualification($form, $product !== null, $request->request->getBoolean('consent'));

        if ($error === null) {
            $solution = $product?->getName() ?? self::OTHER_SOLUTION;
            if ($leads->findOpenDuplicate($form['contact'], $form['email'] ?: null, $solution) !== null) {
                $error = 'Ce contact a déjà été transmis pour cette solution — écrivez-nous à contact@klevup.fr en cas de doute.';
            }
        }

        if ($error !== null) {
            return $this->render('lead/new.html.twig', ['products' => $catalogue, 'form' => $form, 'error' => $error]);
        }

        $lead = self::buildLead($form, $product);
        $lead->setApporteur($this->getUser());
        $lead->setContactRole($form['contact_role'] ?: null);
        $lead->setTimeline($form['timeline']);
        $lead->setBudget($form['budget']);
        $lead->setRelationship($form['relationship']);
        $lead->setConsentAt(new \DateTimeImmutable());
        $em->persist($lead);
        $em->flush();

        $notifier->toAdmins(
            sprintf('[Klevup] Nouveau lead — %s (%s)', $lead->getContact(), $lead->getSolution()),
            self::describeLead($lead, sprintf('Apporteur : %s (%s)', $this->getUser()->getName(), $this->getUser()->getUserIdentifier()))
                . "\n\nTraiter : " . $this->generateUrl('admin_leads', [], UrlGeneratorInterface::ABSOLUTE_URL)
        );

        $this->addFlash('success', 'Lead envoyé — l\'équipe Klevup le prend en charge sous 24 h.');

        return $this->redirectToRoute('leads');
    }

    /** Lead form fields as trimmed strings (an array-valued field is a 400, not a 500). */
    public static function readForm(Request $request): array
    {
        $form = [];
        foreach (['contact', 'contact_name', 'contact_role', 'email', 'phone', 'product_id', 'notes', 'timeline', 'budget', 'relationship'] as $field) {
            $form[$field] = trim($request->request->getString($field));
        }

        return $form;
    }

    /** Shared by apporteur and public forms. Returns an error message or null. */
    public static function validateContact(array $form): ?string
    {
        $company = $form['contact'] ?? '';
        $email = $form['email'] ?? '';
        $phone = $form['phone'] ?? '';

        if ($company === '' || mb_strlen($company) > 255 || mb_strlen($form['contact_name'] ?? '') > 255) {
            return 'Le nom de l\'entreprise est obligatoire (255 caractères max).';
        }
        if ($email === '' && $phone === '') {
            return 'Indiquez au moins un email ou un téléphone pour que nous puissions recontacter le prospect.';
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180)) {
            return 'Email invalide.';
        }
        if ($phone !== '' && !preg_match('/^[+0-9 ().-]{6,30}$/', $phone)) {
            return 'Téléphone invalide.';
        }
        if (mb_strlen($form['notes'] ?? '') > 2000) {
            return 'Notes trop longues (2000 caractères max).';
        }

        return null;
    }

    // Apporteur-only checks: the prospect must have agreed to be called, and a custom need must be described.
    private static function validateQualification(array $form, bool $fromCatalogue, bool $consent): ?string
    {
        if (!$consent) {
            return 'Confirmez que votre contact est d\'accord pour être recontacté par Klevup.';
        }
        if (!$fromCatalogue && mb_strlen($form['notes']) < 20) {
            return 'Pour un besoin sur-mesure, décrivez-le en quelques phrases (20 caractères minimum).';
        }
        if (mb_strlen($form['contact_role']) > 100) {
            return 'Fonction trop longue (100 caractères max).';
        }
        foreach (['timeline' => Lead::TIMELINES, 'budget' => Lead::BUDGETS, 'relationship' => Lead::RELATIONSHIPS] as $field => $choices) {
            if (!array_key_exists($form[$field], $choices)) {
                return 'Précisez l\'échéance, le budget et votre lien avec le contact.';
            }
        }

        return null;
    }

    public static function buildLead(array $form, ?\App\Entity\Product $product): Lead
    {
        $lead = new Lead();
        $lead->setContact($form['contact']);
        $lead->setContactName(($form['contact_name'] ?? '') ?: null);
        $lead->setEmail(($form['email'] ?? '') ?: null);
        $lead->setPhone(($form['phone'] ?? '') ?: null);
        $lead->setNotes(($form['notes'] ?? '') ?: null);
        $lead->setProduct($product);
        $lead->setSolution($product?->getName() ?? self::OTHER_SOLUTION);

        return $lead;
    }

    public static function describeLead(Lead $lead, string $origin): string
    {
        return sprintf(
            "%s\n\nEntreprise : %s\nContact : %s\nEmail : %s\nTéléphone : %s\nSolution : %s\nNotes : %s%s",
            $origin,
            $lead->getContact(),
            trim(($lead->getContactName() ?? '—') . ($lead->getContactRole() ? ' (' . $lead->getContactRole() . ')' : '')),
            $lead->getEmail() ?? '—',
            $lead->getPhone() ?? '—',
            $lead->getSolution(),
            $lead->getNotes() ?? '—',
            $lead->getTimeline() === null ? '' : sprintf(
                "\nÉchéance : %s\nBudget : %s\nLien avec l'apporteur : %s",
                Lead::TIMELINES[$lead->getTimeline()] ?? '—',
                Lead::BUDGETS[$lead->getBudget()] ?? '—',
                Lead::RELATIONSHIPS[$lead->getRelationship()] ?? '—'
            )
        );
    }
}
