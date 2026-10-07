<?php
namespace App\Controller;

use App\Entity\Commission;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Service\CommissionCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Apporteur enablement: sales kit and referral program.
#[IsGranted('ROLE_APPORTEUR')]
class ApporteurController extends AbstractController
{
    #[Route('/kit', name: 'kit')]
    public function kit(ProductRepository $products): Response
    {
        return $this->render('apporteur/kit.html.twig', [
            'products' => $products->findActive(),
            'palierSales' => CommissionCalculator::PALIER_SALES,
            'palierBonus' => CommissionCalculator::PALIER_BONUS,
            'recurringRate' => CommissionCalculator::RECURRING_RATE,
            'recurringMonths' => CommissionCalculator::RECURRING_MONTHS,
        ]);
    }

    #[Route('/parrainage', name: 'referral')]
    public function referral(EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->getReferralCode() === null) {
            $user->setReferralCode(bin2hex(random_bytes(5)));
            $em->flush();
        }

        $referees = $em->getRepository(User::class)->findBy(['referredBy' => $user], ['createdAt' => 'DESC']);
        $bonuses = [];
        foreach ($em->getRepository(Commission::class)->findBy(['user' => $user, 'solution' => Commission::REFERRAL_LABEL]) as $bonus) {
            if ($bonus->getReferralOf() !== null) {
                $bonuses[$bonus->getReferralOf()->getId()] = $bonus;
            }
        }

        return $this->render('apporteur/referral.html.twig', [
            'link' => $this->generateUrl('home', ['ref' => $user->getReferralCode()], UrlGeneratorInterface::ABSOLUTE_URL) . '#apporteur',
            'referees' => $referees,
            'bonuses' => $bonuses,
            'pendingRequests' => $em->getRepository(\App\Entity\ApporteurRequest::class)->count(['referrer' => $user, 'handledAt' => null]),
            'bonus' => CommissionCalculator::REFERRAL_BONUS,
        ]);
    }
}
