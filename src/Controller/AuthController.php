<?php
namespace App\Controller;

use App\Entity\Invitation;
use App\Entity\User;
use App\Service\CommissionCalculator;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        $error = $authUtils->getLastAuthenticationError();
        $lastUsername = $authUtils->getLastUsername();
        return $this->render('auth/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(): Response {}

    #[Route('/register/{code}', name: 'register', methods: ['GET', 'POST'])]
    public function register(string $code, Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em, Notifier $notifier): Response
    {
        // Invitation must exist, be unused and not expired. Role is derived from it — never from user input.
        $invitation = $em->getRepository(Invitation::class)->findPendingByCode($code);
        if ($invitation === null || $invitation->isExpired()) {
            throw $this->createNotFoundException('Invitation invalide, expirée ou déjà utilisée.');
        }

        if ($request->isMethod('POST')) {
            // An invitation sent to a given address can only create that account.
            $email = $invitation->getEmail() ?? trim((string) $request->request->get('email'));
            $name = trim((string) $request->request->get('name'));
            $password = (string) $request->request->get('password');

            if ($email === '' || $name === '' || $password === '') {
                return $this->render('auth/register.html.twig', [
                    'code' => $code,
                    'invitedEmail' => $invitation->getEmail(),
                    'error' => 'Tous les champs sont obligatoires.',
                ]);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180 || mb_strlen($name) > 255) {
                return $this->render('auth/register.html.twig', [
                    'code' => $code,
                    'invitedEmail' => $invitation->getEmail(),
                    'error' => 'Email ou nom invalide.',
                ]);
            }

            if (strlen($password) < 8) {
                return $this->render('auth/register.html.twig', [
                    'code' => $code,
                    'invitedEmail' => $invitation->getEmail(),
                    'error' => 'Mot de passe trop court — 8 caractères minimum.',
                ]);
            }

            if ($em->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
                return $this->render('auth/register.html.twig', [
                    'code' => $code,
                    'invitedEmail' => $invitation->getEmail(),
                    'error' => 'Un compte existe déjà avec cet email.',
                ]);
            }

            $user = new User();
            $user->setEmail($email);
            $user->setName($name);
            $user->setRoles([$invitation->getRole()]);
            $user->setPassword($hasher->hashPassword($user, $password));
            $user->setInvitationCode($code);
            $user->setReferredBy($invitation->getReferrer());

            // Consume the invitation so the link can't be reused.
            $invitation->setUsedAt(new \DateTimeImmutable());

            $em->persist($user);
            $em->flush();

            $this->sendWelcome($notifier, $user);
            $this->addFlash('success', 'Compte créé — connectez-vous pour commencer.');

            return $this->redirectToRoute('login');
        }

        return $this->render('auth/register.html.twig', ['code' => $code, 'invitedEmail' => $invitation->getEmail()]);
    }

    private function sendWelcome(Notifier $notifier, User $user): void
    {
        $url = fn(string $route) => $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL);

        if (in_array('ROLE_APPORTEUR', $user->getRoles(), true)) {
            $notifier->send($user->getEmail(), 'Bienvenue dans le réseau Klevup', sprintf(
                "Bonjour %s,\n\nBienvenue dans le réseau des apporteurs Klevup ! Voici comment ça marche :\n\n"
                . "1. Repérez un contact qui a besoin d'un outil métier (gestion de formation, e-learning, prospection, commande en ligne…).\n"
                . "2. Avec son accord, transmettez-le en 2 minutes : %s\n"
                . "3. On le rappelle sous 24 h, on fait la démo et le devis. Vous suivez tout depuis votre espace et êtes prévenu à chaque étape.\n"
                . "4. À la signature, votre commission est calculée automatiquement (%d %% puis +%d points dès votre %dᵉ vente du trimestre), avec une date de versement annoncée.\n\n"
                . "Pour bien démarrer :\n- Le kit de vente (qui cibler, quoi dire) : %s\n- Renseignez votre IBAN pour être payé : %s\n- Parrainez d'autres apporteurs (%d € par filleul actif) : %s",
                $user->getName(),
                $url('lead_new'),
                CommissionCalculator::DEFAULT_RATE,
                CommissionCalculator::PALIER_BONUS,
                CommissionCalculator::PALIER_SALES + 1,
                $url('kit'),
                $url('profile'),
                CommissionCalculator::REFERRAL_BONUS,
                $url('referral')
            ));
        } elseif (in_array('ROLE_CLIENT', $user->getRoles(), true)) {
            $notifier->send($user->getEmail(), 'Bienvenue sur votre espace Klevup', sprintf(
                "Bonjour %s,\n\nVotre espace client est prêt. Vous y retrouvez vos applications et pouvez signaler un bug ou demander une évolution — réponse sous 24 h ouvrées :\n%s",
                $user->getName(),
                $url('support')
            ));
        }
    }
}
