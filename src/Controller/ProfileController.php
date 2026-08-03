<?php
namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'profile', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $name = trim((string) $request->request->get('name'));
            $email = trim((string) $request->request->get('email'));
            $iban = strtoupper(str_replace(' ', '', (string) $request->request->get('iban')));

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) > 255 || mb_strlen($email) > 180) {
                $this->addFlash('error', 'Nom ou email invalide.');

                return $this->redirectToRoute('profile');
            }

            $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existing !== null && $existing->getId() !== $user->getId()) {
                $this->addFlash('error', 'Cet email est déjà utilisé par un autre compte.');

                return $this->redirectToRoute('profile');
            }

            if ($iban !== '' && !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban)) {
                $this->addFlash('error', 'IBAN invalide.');

                return $this->redirectToRoute('profile');
            }

            $user->setName($name);
            $user->setEmail($email);
            if (in_array('ROLE_APPORTEUR', $user->getRoles(), true)) {
                $user->setIban($iban !== '' ? $iban : null);
            }

            // Optional password change: requires the current password.
            $newPassword = (string) $request->request->get('new_password');
            if ($newPassword !== '') {
                $current = (string) $request->request->get('current_password');
                if (!$hasher->isPasswordValid($user, $current)) {
                    $this->addFlash('error', 'Mot de passe actuel incorrect — les autres modifications ont été enregistrées.');
                    $em->flush();

                    return $this->redirectToRoute('profile');
                }
                if (strlen($newPassword) < 8) {
                    $this->addFlash('error', 'Nouveau mot de passe trop court (8 caractères minimum) — les autres modifications ont été enregistrées.');
                    $em->flush();

                    return $this->redirectToRoute('profile');
                }
                $user->setPassword($hasher->hashPassword($user, $newPassword));
            }

            $em->flush();
            $this->addFlash('success', 'Profil mis à jour.');

            return $this->redirectToRoute('profile');
        }

        return $this->render('profile/edit.html.twig', [
            'isApporteur' => in_array('ROLE_APPORTEUR', $user->getRoles(), true),
        ]);
    }
}
