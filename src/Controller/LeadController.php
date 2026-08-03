<?php
namespace App\Controller;

use App\Entity\Lead;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_APPORTEUR')]
class LeadController extends AbstractController
{
    #[Route('/leads', name: 'leads')]
    public function list(EntityManagerInterface $em): Response
    {
        $leads = $em->getRepository(Lead::class)->findBy(
            ['apporteur' => $this->getUser()],
            ['createdAt' => 'DESC']
        );

        $signed = array_filter($leads, fn(Lead $l) => $l->getStatus() === 'signed');

        return $this->render('lead/list.html.twig', [
            'leads' => $leads,
            'stats' => [
                'total' => count($leads),
                'in_progress' => count($leads) - count($signed),
                'signed' => count($signed),
                'potential' => array_sum(array_map(
                    fn(Lead $l) => $l->getCommission(),
                    array_filter($leads, fn(Lead $l) => $l->getStatus() !== 'signed')
                )),
            ],
        ]);
    }

    #[Route('/lead/new', name: 'lead_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('lead_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $contact = trim((string) $request->request->get('contact'));
            $solution = trim((string) $request->request->get('solution'));
            $commission = max(0, (int) $request->request->get('commission', 0));

            if ($contact === '' || $solution === '' || mb_strlen($contact) > 255 || mb_strlen($solution) > 255) {
                return $this->render('lead/new.html.twig', [
                    'error' => 'Contact et solution sont obligatoires (255 caractères max).',
                ]);
            }

            $lead = new Lead();
            $lead->setContact($contact);
            $lead->setSolution($solution);
            $lead->setCommission($commission);
            $lead->setApporteur($this->getUser());

            $em->persist($lead);
            $em->flush();

            $this->addFlash('success', 'Lead envoyé — l\'équipe Klevup prend le relais.');

            return $this->redirectToRoute('leads');
        }

        return $this->render('lead/new.html.twig');
    }
}
