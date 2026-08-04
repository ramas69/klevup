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

        if (!$request->isMethod('POST')) {
            return $this->renderProfile($user);
        }

        if (!$this->isCsrfTokenValid('profile', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($error = $this->applyIdentity($user, $request, $em)) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('profile');
        }

        if ($error = $this->applyPasswordChange($user, $request, $hasher)) {
            // Identity changes are still saved even when the password part fails.
            $em->flush();
            $this->addFlash('error', $error . ' — les autres modifications ont été enregistrées.');

            return $this->redirectToRoute('profile');
        }

        $em->flush();
        $this->addFlash('success', 'Profil mis à jour.');

        return $this->redirectToRoute('profile');
    }

    private function applyIdentity(User $user, Request $request, EntityManagerInterface $em): ?string
    {
        $name = trim((string) $request->request->get('name'));
        $email = trim((string) $request->request->get('email'));
        $iban = strtoupper(str_replace(' ', '', (string) $request->request->get('iban')));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) > 255 || mb_strlen($email) > 180) {
            return 'Nom ou email invalide.';
        }

        $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing !== null && $existing->getId() !== $user->getId()) {
            return 'Cet email est déjà utilisé par un autre compte.';
        }

        if ($iban !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return 'IBAN invalide.';
        }

        $user->setName($name);
        $user->setEmail($email);
        if (in_array('ROLE_APPORTEUR', $user->getRoles(), true)) {
            $user->setIban($iban !== '' ? $iban : null);
        }

        return null;
    }

    private function applyPasswordChange(User $user, Request $request, UserPasswordHasherInterface $hasher): ?string
    {
        $newPassword = (string) $request->request->get('new_password');
        if ($newPassword === '') {
            return null;
        }

        if (!$hasher->isPasswordValid($user, (string) $request->request->get('current_password'))) {
            return 'Mot de passe actuel incorrect';
        }

        if (strlen($newPassword) < 8) {
            return 'Nouveau mot de passe trop court (8 caractères minimum)';
        }

        $user->setPassword($hasher->hashPassword($user, $newPassword));

        return null;
    }

    private function renderProfile(User $user): Response
    {
        $roles = $user->getRoles();

        // Render inside the sidebar layout matching the user's space; clients keep the standalone card.
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return $this->render('profile/edit_sidebar.html.twig', [
                'layout' => 'admin/base_admin.html.twig',
                'isApporteur' => false,
            ]);
        }

        if (in_array('ROLE_APPORTEUR', $roles, true)) {
            return $this->render('profile/edit_sidebar.html.twig', [
                'layout' => 'apporteur/base_apporteur.html.twig',
                'isApporteur' => true,
            ]);
        }

        return $this->render('profile/edit.html.twig', ['isApporteur' => false]);
    }
}
