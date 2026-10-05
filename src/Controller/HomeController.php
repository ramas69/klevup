<?php
namespace App\Controller;

use App\Entity\ApporteurRequest;
use App\Entity\Lead;
use App\Entity\User;
use App\Repository\LeadRepository;
use App\Repository\ProductRepository;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(Request $request, ProductRepository $products): Response
    {
        return $this->render('home/index.html.twig', [
            'products' => $products->findActive(),
            'ref' => preg_replace('/[^a-f0-9]/', '', $request->query->getString('ref')),
        ]);
    }

    #[Route('/demo', name: 'demo_request', methods: ['POST'])]
    public function demo(Request $request, EntityManagerInterface $em, ProductRepository $products, LeadRepository $leads, Notifier $notifier, RateLimiterFactory $publicFormLimiter): Response
    {
        if ($response = $this->guard($request, 'demo', $publicFormLimiter, 'contact')) {
            return $response;
        }

        $form = LeadController::readForm($request);
        if ($error = LeadController::validateContact($form)) {
            $this->addFlash('demo_error', $error);

            return $this->redirect($this->generateUrl('home') . '#contact');
        }

        $product = $products->findOneBy(['id' => (int) ($form['product_id'] ?? 0), 'active' => true]);
        $lead = LeadController::buildLead($form, $product);
        $lead->setSource(Lead::SOURCE_SITE);
        $lead->setConsentAt(new \DateTimeImmutable()); // the prospect contacted us themselves

        // Already in the pipeline (e.g. sent by an apporteur): don't create a competing lead, just alert the team.
        $existing = $leads->findOpenDuplicate($lead->getContact(), $lead->getEmail(), $lead->getSolution());
        if ($existing === null) {
            $em->persist($lead);
            $em->flush();
        }

        $notifier->toAdmins(
            sprintf('[Klevup] Demande de démo — %s (%s)%s', $lead->getContact(), $lead->getSolution(), $existing ? ' — DÉJÀ EN COURS' : ''),
            LeadController::describeLead($lead, $existing ? sprintf('Demande reçue depuis le site — ce prospect est déjà dans le pipeline (lead #%d, statut %s).', $existing->getId(), $existing->getStatus()) : 'Demande reçue depuis le site.')
                . "\n\nTraiter : " . $this->generateUrl('admin_leads', [], UrlGeneratorInterface::ABSOLUTE_URL)
        );

        $this->addFlash('demo_success', 'Merci ! Nous vous recontactons sous 24 h ouvrées pour caler la démo.');

        return $this->redirect($this->generateUrl('home') . '#contact');
    }

    #[Route('/devenir-apporteur', name: 'apporteur_request', methods: ['POST'])]
    public function apporteurRequest(Request $request, EntityManagerInterface $em, Notifier $notifier, RateLimiterFactory $publicFormLimiter): Response
    {
        if ($response = $this->guard($request, 'apporteur_request', $publicFormLimiter, 'apporteur')) {
            return $response;
        }

        $name = trim($request->request->getString('name'));
        $email = trim($request->request->getString('email'));
        $phone = trim($request->request->getString('phone'));
        $message = trim($request->request->getString('message'));

        if ($name === '' || mb_strlen($name) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180 || mb_strlen($phone) > 30 || mb_strlen($message) > 2000) {
            $this->addFlash('apporteur_error', 'Nom et email valides sont obligatoires.');

            return $this->redirect($this->generateUrl('home') . '#apporteur');
        }

        $ref = $request->request->getString('ref');
        $referrer = $ref === '' ? null : $em->getRepository(User::class)->findOneBy(['referralCode' => $ref, 'status' => 'active']);

        $candidate = (new ApporteurRequest())
            ->setName($name)
            ->setEmail($email)
            ->setPhone($phone ?: null)
            ->setMessage($message ?: null)
            ->setReferrer($referrer);
        $em->persist($candidate);
        $em->flush();

        $notifier->toAdmins(
            sprintf('[Klevup] Candidature apporteur — %s', $name),
            sprintf("Nom : %s\nEmail : %s\nTéléphone : %s\nMessage : %s\nParrain : %s\n\nInviter : %s", $name, $email, $phone ?: '—', $message ?: '—', $referrer?->getName() ?? '—', $this->generateUrl('admin_invite', [], UrlGeneratorInterface::ABSOLUTE_URL))
        );

        $this->addFlash('apporteur_success', 'Candidature reçue ! Nous revenons vers vous très vite avec votre accès.');

        return $this->redirect($this->generateUrl('home') . '#apporteur');
    }

    // CSRF + honeypot + per-IP rate limit for anonymous forms. Returns a redirect when the request must stop.
    private function guard(Request $request, string $csrfId, RateLimiterFactory $limiter, string $anchor): ?Response
    {
        $back = $this->redirect($this->generateUrl('home') . '#' . $anchor);
        $errorKey = $anchor === 'contact' ? 'demo_error' : 'apporteur_error';

        // Usually an expired session on a page left open: an access-denied here would bounce a prospect to /login.
        if (!$this->isCsrfTokenValid($csrfId, $request->request->getString('_token'))) {
            $this->addFlash($errorKey, 'Votre session a expiré — merci de renvoyer le formulaire.');

            return $back;
        }

        // Bots fill the hidden "website" field: pretend success, store nothing.
        if ($request->request->getString('website') !== '') {
            return $back;
        }

        if (!$limiter->create($request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash($errorKey, 'Trop de demandes — réessayez dans une heure ou écrivez à contact@klevup.fr.');

            return $back;
        }

        return null;
    }
}
