<?php
namespace App\Controller;

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

class ResetPasswordController extends AbstractController
{
    #[Route('/forgot-password', name: 'forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot_password', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $email = trim((string) $request->request->get('email'));
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user !== null) {
                $user->setResetToken(bin2hex(random_bytes(32)));
                $user->setResetTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
                $em->flush();

                $link = $this->generateUrl('reset_password', ['token' => $user->getResetToken()], UrlGeneratorInterface::ABSOLUTE_URL);
                try {
                    $mailer->send((new Email())
                        ->from('no-reply@klevup.fr')
                        ->to($user->getEmail())
                        ->subject('Réinitialisation de votre mot de passe Klevup')
                        ->text(sprintf(
                            "Bonjour %s,\n\nPour choisir un nouveau mot de passe, cliquez sur ce lien (valable 1 heure) :\n%s\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez cet email.\n\nL'équipe Klevup",
                            $user->getName(),
                            $link
                        )));
                } catch (\Throwable) {
                    // Neutral response below regardless — do not leak transport state.
                }
            }

            // Always the same message: never reveal whether the email exists.
            $this->addFlash('success', 'Si un compte existe avec cet email, un lien de réinitialisation vient d\'être envoyé.');

            return $this->redirectToRoute('forgot_password');
        }

        return $this->render('auth/forgot_password.html.twig');
    }

    #[Route('/reset-password/{token}', name: 'reset_password', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $user = $em->getRepository(User::class)->findOneBy(['resetToken' => $token]);
        if ($user === null || $user->getResetTokenExpiresAt() === null || $user->getResetTokenExpiresAt() < new \DateTimeImmutable()) {
            throw $this->createNotFoundException('Lien invalide ou expiré.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset_password', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $password = (string) $request->request->get('password');
            if (strlen($password) < 8) {
                return $this->render('auth/reset_password.html.twig', [
                    'token' => $token,
                    'error' => 'Mot de passe trop court — 8 caractères minimum.',
                ]);
            }

            $user->setPassword($hasher->hashPassword($user, $password));
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);
            $em->flush();

            $this->addFlash('success', 'Mot de passe mis à jour — connectez-vous.');

            return $this->redirectToRoute('login');
        }

        return $this->render('auth/reset_password.html.twig', ['token' => $token]);
    }
}
