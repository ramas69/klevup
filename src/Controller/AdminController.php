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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    private const INVITABLE_ROLES = ['ROLE_APPORTEUR', 'ROLE_CLIENT'];

    #[Route('/admin/invite', name: 'admin_invite')]
    public function invite(Request $request, EntityManagerInterface $em): Response
    {
        $inviteLink = null;

        if ($request->isMethod('POST')) {
            $role = (string) $request->request->get('role', 'ROLE_APPORTEUR');
            if (!in_array($role, self::INVITABLE_ROLES, true)) {
                $role = 'ROLE_APPORTEUR';
            }

            $invitation = new Invitation();
            $invitation->setCode(bin2hex(random_bytes(16)));
            $invitation->setRole($role);
            $em->persist($invitation);
            $em->flush();

            $inviteLink = $this->generateUrl('register', ['code' => $invitation->getCode()], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $this->render('admin/invite.html.twig', [
            'inviteLink' => $inviteLink,
            'roles' => self::INVITABLE_ROLES,
        ]);
    }

    #[Route('/admin/users', name: 'admin_users')]
    public function users(EntityManagerInterface $em): Response
    {
        $users = $em->getRepository(User::class)->findAll();

        return $this->render('admin/users.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/admin/user/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        // Prevent an admin from deleting their own account while logged in.
        if ($user->getUserIdentifier() === $this->getUser()?->getUserIdentifier()) {
            $this->addFlash('error', 'Impossible de supprimer votre propre compte.');

            return $this->redirectToRoute('admin_users');
        }

        // Block deletion while related records exist (FK constraints, no cascade).
        if (!$user->getLeads()->isEmpty() || !$user->getCommissions()->isEmpty() || !$user->getTickets()->isEmpty()) {
            $this->addFlash('error', 'Utilisateur lié à des leads, commissions ou tickets — suppression impossible.');

            return $this->redirectToRoute('admin_users');
        }

        $em->remove($user);
        $em->flush();

        return $this->redirectToRoute('admin_users');
    }
}
