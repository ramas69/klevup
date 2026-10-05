<?php
namespace App\Service;

use App\Entity\Lead;
use App\Repository\LeadRepository;

/**
 * Commission = sale amount × rate.
 * Rate = product rate (15 % by default), +5 points from the 4th signed sale of the quarter.
 */
class CommissionCalculator
{
    public const DEFAULT_RATE = 15;
    public const PALIER_SALES = 3;   // sales already signed in the quarter before the bonus applies
    public const PALIER_BONUS = 5;
    public const REFERRAL_BONUS = 100;      // € paid to the referrer when the referee signs a first sale
    public const PAYMENT_DELAY_DAYS = 30;   // announced payment date = signature + 30 days

    public static function dueDate(\DateTimeImmutable $from): \DateTimeImmutable
    {
        return $from->setTime(0, 0)->modify('+' . self::PAYMENT_DELAY_DAYS . ' days');
    }

    public function __construct(private readonly LeadRepository $leads)
    {
    }

    public static function quarterStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $month = intdiv((int) $date->format('n') - 1, 3) * 3 + 1;

        return $date->setDate((int) $date->format('Y'), $month, 1)->setTime(0, 0);
    }

    public function rateFor(Lead $lead, \DateTimeImmutable $at): int
    {
        $base = $lead->getProduct()?->getCommissionRate() ?? self::DEFAULT_RATE;
        if ($lead->getApporteur() === null) {
            return $base;
        }

        // Only sales signed before this one count: editing an early sale never earns it the later tier.
        $previous = $this->leads->countSignedBetween($lead->getApporteur(), self::quarterStart($at), $at, $lead);

        return $previous >= self::PALIER_SALES ? $base + self::PALIER_BONUS : $base;
    }

    public function amountFor(Lead $lead): int
    {
        if ($lead->getApporteur() === null || !$lead->getDealAmount()) {
            return 0;
        }

        $rate = $this->rateFor($lead, $lead->getSignedAt() ?? new \DateTimeImmutable());

        return (int) round($lead->getDealAmount() * $rate / 100);
    }
}
