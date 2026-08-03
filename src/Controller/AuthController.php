<?php
namespace App\Controller;

use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
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
    public function register(string $code, Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em): Response
    {
        // Invitation must exist and be unused. Role is derived from it — never from user input.
        $invitation = $em->getRepository(Invitation::class)->findPendingByCode($code);
        if ($invitation === null) {
            throw $this->createNotFoundException('Invitation invalide ou déjà utilisée.');
        }

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email'));
            $name = trim((string) $request->request->get('name'));
            $password = (string) $request->request->get('password');

            if ($email === '' || $name === '' || $password === '') {
                return $this->render('auth/register.html.twig', [
                    'code' => $code,
                    'error' => 'Tous les champs sont obligatoires.',
                ]);
            }

            if ($em->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
                return $this->render('auth/register.html.twig', [
                    'code' => $code,
                    'error' => 'Un compte existe déjà avec cet email.',
                ]);
            }

            $user = new User();
            $user->setEmail($email);
            $user->setName($name);
            $user->setRoles([$invitation->getRole()]);
            $user->setPassword($hasher->hashPassword($user, $password));
            $user->setInvitationCode($code);

            // Consume the invitation so the link can't be reused.
            $invitation->setUsedAt(new \DateTimeImmutable());

            $em->persist($user);
            $em->flush();

            return $this->redirectToRoute('login');
        }

        return $this->render('auth/register.html.twig', ['code' => $code]);
    }
}
