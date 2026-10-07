<?php
namespace App\Tests\Functional;

use App\Entity\Commission;
use App\Entity\Lead;
use App\Entity\Product;
use App\Entity\User;
use App\Service\CommissionCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CommissionFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $apporteur;
    private Lead $lead;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        foreach (['commission', 'ticket_message', 'ticket', '`lead`', 'invitation', 'application', 'apporteur_request', 'user'] as $table) {
            $this->em->getConnection()->executeStatement("DELETE FROM $table");
        }

        $this->admin = $this->makeUser('admin@test.fr', 'ROLE_ADMIN');
        $this->apporteur = $this->makeUser('app@test.fr', 'ROLE_APPORTEUR');
        $this->lead = $this->makeLead('Test SA');
        $this->em->flush();
    }

    private function makeUser(string $email, string $role): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName(ucfirst(explode('@', $email)[0]));
        $user->setRoles([$role]);
        $user->setPassword('irrelevant-hash');
        $this->em->persist($user);

        return $user;
    }

    private function makeLead(string $company, ?\DateTimeImmutable $signedAt = null): Lead
    {
        $lead = new Lead();
        $lead->setContact($company);
        $lead->setSolution('Gestion OF');
        // After an em->clear() the cached apporteur is detached: reload it.
        $lead->setApporteur($this->em->contains($this->apporteur) ? $this->apporteur : $this->em->find(User::class, $this->apporteur->getId()));
        if ($signedAt !== null) {
            $lead->setStatus('signed')->setSignedAt($signedAt)->setDealAmount(1000);
        }
        $this->em->persist($lead);

        return $lead;
    }

    private function updateLead(string $status, ?int $dealAmount): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/lead/' . $this->lead->getId());
        self::assertResponseIsSuccessful();

        $token = $crawler->filter(sprintf('form[action="/admin/lead/%d/update"] input[name="_token"]', $this->lead->getId()))->attr('value');
        $this->client->request('POST', sprintf('/admin/lead/%d/update', $this->lead->getId()), [
            '_token' => $token,
            'status' => $status,
            'deal_amount' => $dealAmount === null ? '' : (string) $dealAmount,
            'lost_reason' => $status === 'lost' ? 'budget' : '',
        ]);
        self::assertResponseRedirects('/admin/lead/' . $this->lead->getId());
    }

    private function commissions(): array
    {
        $this->em->clear();

        return $this->em->getRepository(Commission::class)->findAll();
    }

    public function testSigningALeadCreatesExactlyOneCommissionAtFifteenPercent(): void
    {
        $this->updateLead('signed', 4000);

        $commissions = $this->commissions();
        self::assertCount(1, $commissions);
        self::assertSame(600, $commissions[0]->getAmount());
        self::assertSame('pending', $commissions[0]->getStatus());
        self::assertSame($this->apporteur->getId(), $commissions[0]->getUser()->getId());

        // Re-saving the signed lead must NOT create a duplicate.
        $this->updateLead('signed', 4000);
        self::assertCount(1, $this->commissions());
    }

    public function testProductRateIsUsed(): void
    {
        $product = (new Product())->setName('Produit test 10 %')->setCommissionRate(10)->setActive(false);
        $this->em->persist($product);
        $this->lead->setProduct($product);
        $this->em->flush();

        $this->updateLead('signed', 5000);

        self::assertSame(500, $this->commissions()[0]->getAmount());

        $this->em->getConnection()->executeStatement("DELETE FROM commission");
        $this->em->getConnection()->executeStatement("DELETE FROM `lead`");
        $this->em->getConnection()->executeStatement("DELETE FROM product WHERE name = 'Produit test 10 %'");
    }

    public function testFourthSaleOfTheQuarterEarnsTwentyPercent(): void
    {
        $earlier = CommissionCalculator::quarterStart(new \DateTimeImmutable());
        foreach (['A', 'B', 'C'] as $company) {
            $this->makeLead($company, $earlier);
        }
        $this->em->flush();

        $this->updateLead('signed', 4000);

        self::assertSame(800, $this->em->getRepository(Commission::class)->findOneBy(['lead' => $this->lead->getId()])->getAmount());
    }

    public function testSigningRequiresADealAmount(): void
    {
        $this->updateLead('signed', null);

        self::assertCount(0, $this->commissions());
        self::assertSame('new', $this->em->find(Lead::class, $this->lead->getId())->getStatus());
    }

    public function testLostLeadCreatesNoCommission(): void
    {
        $this->updateLead('lost', 4000);

        self::assertCount(0, $this->commissions());
    }

    public function testUnsigningCancelsThePendingCommission(): void
    {
        $this->updateLead('signed', 4000);
        $this->updateLead('lost', 4000);

        self::assertCount(0, $this->commissions());
        self::assertNull($this->em->find(Lead::class, $this->lead->getId())->getSignedAt());
    }

    public function testChangingTheDealAmountAdjustsThePendingCommission(): void
    {
        $this->updateLead('signed', 4000);
        $this->updateLead('signed', 6000);

        $commissions = $this->commissions();
        self::assertCount(1, $commissions);
        self::assertSame(900, $commissions[0]->getAmount());
    }

    public function testEncashMarksCommissionPaidAndFreezesTheDeal(): void
    {
        $this->updateLead('signed', 5000);
        $commission = $this->commissions()[0];

        $crawler = $this->client->request('GET', '/admin/commissions');
        $token = $crawler->filter(sprintf('form[action="/admin/commission/%d/encash"] input[name="_token"]', $commission->getId()))->attr('value');
        $this->client->request('POST', sprintf('/admin/commission/%d/encash', $commission->getId()), ['_token' => $token]);
        self::assertResponseRedirects('/admin/commissions');

        // A paid commission can no longer be cancelled or re-priced.
        $this->updateLead('lost', 5000);
        $this->updateLead('signed', 9000);

        $commissions = $this->commissions();
        self::assertCount(1, $commissions);
        self::assertSame('encashed', $commissions[0]->getStatus());
        self::assertNotNull($commissions[0]->getEncashedAt());
        self::assertSame(750, $commissions[0]->getAmount());
        self::assertSame('signed', $this->em->find(Lead::class, $this->lead->getId())->getStatus());
    }

    public function testApporteurCannotUpdateLeads(): void
    {
        $this->client->loginUser($this->apporteur);
        $this->client->request('POST', sprintf('/admin/lead/%d/update', $this->lead->getId()), [
            'status' => 'signed',
            'deal_amount' => 99999,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->commissions());
    }

    public function testResavingAPaidSaleNeverRewritesItsAmount(): void
    {
        $product = (new Product())->setName('Produit test variable')->setCommissionRate(15)->setActive(false);
        $this->em->persist($product);
        $this->lead->setProduct($product);
        $this->em->flush();

        $this->updateLead('signed', 1000);
        $commission = $this->commissions()[0];
        $this->em->getConnection()->executeStatement("UPDATE commission SET status = 'encashed' WHERE id = " . $commission->getId());
        $this->em->getConnection()->executeStatement("UPDATE product SET commissionRate = 30 WHERE name = 'Produit test variable'");

        $this->updateLead('signed', 1000);

        self::assertSame(150, $this->commissions()[0]->getAmount());
        $this->em->getConnection()->executeStatement('DELETE FROM commission');
        $this->em->getConnection()->executeStatement('DELETE FROM `lead`');
        $this->em->getConnection()->executeStatement("DELETE FROM product WHERE name = 'Produit test variable'");
    }

    public function testTierFollowsSigningOrder(): void
    {
        // This lead is the 1st sale of the quarter; three more are signed after it.
        $this->updateLead('signed', 1000);
        $later = new \DateTimeImmutable('+1 minute');
        foreach (['B', 'C', 'D'] as $company) {
            $this->makeLead($company, $later);
        }
        $this->em->flush();

        // Re-pricing the first sale must keep it at the base rate.
        $this->updateLead('signed', 2000);

        self::assertSame(300, $this->em->getRepository(Commission::class)->findOneBy(['lead' => $this->lead->getId()])->getAmount());
    }

    public function testUnsigningAnEarlySaleReratesLaterOnes(): void
    {
        $earlier = CommissionCalculator::quarterStart(new \DateTimeImmutable());
        foreach (['A', 'B'] as $company) {
            $this->makeLead($company, $earlier);
        }
        $this->em->flush();
        $this->updateLead('signed', 1000);                 // 3rd sale → 15 %

        $fourth = $this->makeLead('D', new \DateTimeImmutable('+1 minute'));
        $this->em->persist((new Commission())->setCompanyName('D')->setSolution('Gestion OF')->setAmount(200)->setUser($this->em->find(User::class, $this->apporteur->getId()))->setLead($fourth));
        $this->em->flush();

        $this->updateLead('lost', 1000);                   // D becomes the 3rd sale → back to 15 %

        $this->em->clear();
        self::assertSame(150, $this->em->getRepository(Commission::class)->findOneBy(['lead' => $fourth->getId()])->getAmount());
    }

    public function testLostRequiresAReasonShownToTheApporteur(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/lead/' . $this->lead->getId());
        $token = $crawler->filter(sprintf('form[action="/admin/lead/%d/update"] input[name="_token"]', $this->lead->getId()))->attr('value');
        $this->client->request('POST', sprintf('/admin/lead/%d/update', $this->lead->getId()), ['_token' => $token, 'status' => 'lost', 'deal_amount' => '']);
        $this->em->clear();
        self::assertSame('new', $this->em->find(Lead::class, $this->lead->getId())->getStatus());

        $this->updateLead('lost', null);
        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'Budget insuffisant');

        $this->client->loginUser($this->em->find(User::class, $this->apporteur->getId()));
        $this->client->request('GET', '/leads');
        self::assertSelectorTextContains('#leads-table', 'Budget insuffisant');
    }

    public function testCommissionGetsAnAnnouncedPaymentDate(): void
    {
        $this->updateLead('signed', 1000);

        $due = $this->commissions()[0]->getDueAt();
        self::assertNotNull($due);
        self::assertSame((new \DateTimeImmutable('today +' . CommissionCalculator::PAYMENT_DELAY_DAYS . ' days'))->format('Y-m-d'), $due->format('Y-m-d'));
    }

    public function testReferrerEarnsABonusOnTheRefereesFirstSaleOnly(): void
    {
        $referrer = $this->makeUser('parrain@test.fr', 'ROLE_APPORTEUR');
        $this->em->flush();
        $this->em->find(User::class, $this->apporteur->getId())->setReferredBy($referrer);
        $second = $this->makeLead('Second SA');
        $this->em->flush();

        $this->updateLead('signed', 1000);
        $this->lead = $second;
        $this->updateLead('signed', 1000);

        $bonuses = $this->em->getRepository(Commission::class)->findBy(['user' => $referrer->getId()]);
        self::assertCount(1, $bonuses);
        self::assertSame(CommissionCalculator::REFERRAL_BONUS, $bonuses[0]->getAmount());
        self::assertTrue($bonuses[0]->isReferralBonus());
    }

    public function testStatementListsTheMonthsPayments(): void
    {
        $this->updateLead('signed', 2000);
        $this->em->getConnection()->executeStatement("UPDATE commission SET status = 'encashed', encashedAt = NOW()");

        $this->client->loginUser($this->em->find(User::class, $this->apporteur->getId()));
        $this->client->request('GET', '/commissions/releve/' . date('Y-m'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.total', '300,00 €');

        // Another apporteur only ever sees their own (empty) statement.
        $other = $this->makeUser('autre@test.fr', 'ROLE_APPORTEUR');
        $this->em->flush();
        $this->client->loginUser($other);
        $this->client->request('GET', '/commissions/releve/' . date('Y-m'));
        self::assertSelectorTextContains('.sheet', 'Aucune commission versée');
    }

    public function testCommissionIsOnSetupOnlyNotOnTheSubscription(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/lead/' . $this->lead->getId());
        $token = $crawler->filter(sprintf('form[action="/admin/lead/%d/update"] input[name="_token"]', $this->lead->getId()))->attr('value');
        $this->client->request('POST', sprintf('/admin/lead/%d/update', $this->lead->getId()), ['_token' => $token, 'status' => 'signed', 'deal_amount' => '2000', 'monthly_amount' => '150']);

        self::assertSame(300, $this->commissions()[0]->getAmount());
        self::assertSame(150, $this->em->find(Lead::class, $this->lead->getId())->getMonthlyAmount());
    }

    private function runRecurring(): string
    {
        $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel))->find('app:recurring-commissions'));
        $tester->execute([]);

        return $tester->getDisplay();
    }

    private function signedWithSubscription(string $signedAgo, ?string $endsAgo = null): void
    {
        $this->updateLead('signed', 2000);
        $this->em->getConnection()->executeStatement(sprintf("UPDATE `lead` SET signedAt = DATE_SUB(NOW(), INTERVAL %s), monthlyAmount = 150 WHERE id = %d", $signedAgo, $this->lead->getId()));
        $this->em->clear();
        $app = (new \App\Entity\Application())->setName('App')->setClient($this->em->find(User::class, $this->admin->getId()))
            ->setLead($this->em->find(Lead::class, $this->lead->getId()))->setMonthlyPrice(150);
        if ($endsAgo !== null) {
            $app->setSubscriptionEndsAt(new \DateTimeImmutable('-' . $endsAgo));
        }
        $this->em->persist($app);
        $this->em->flush();
    }

    public function testRecurringCommissionsAreCreatedMonthlyOnceAndStopAtChurn(): void
    {
        $this->signedWithSubscription('3 MONTH');

        self::assertStringContainsString('3 commission', $this->runRecurring());
        self::assertStringContainsString('0 commission', $this->runRecurring(), 'idempotent');

        $recurring = $this->em->getRepository(Commission::class)->findBy(['lead' => $this->lead->getId(), 'period' => [1, 2, 3]]);
        self::assertCount(3, $recurring);
        self::assertSame(8, $recurring[0]->getAmount()); // 5 % of 150 € = 7.5 → 8 €
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM commission WHERE periodIndex = 0"));
        $this->em->getConnection()->executeStatement('DELETE FROM application');
    }

    public function testRecurringStopsWhenTheClientStoppedPaying(): void
    {
        $this->signedWithSubscription('4 MONTH', '75 days'); // cancelled ~2.5 months ago → only month 1 was paid

        $this->runRecurring();

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM commission WHERE periodIndex > 0'));
        $this->em->getConnection()->executeStatement('DELETE FROM application');
    }

    public function testUnsigningCancelsPendingRecurringCommissionsToo(): void
    {
        $this->signedWithSubscription('2 MONTH');
        $this->runRecurring();
        $this->em->getConnection()->executeStatement('DELETE FROM application');

        $this->updateLead('lost', 2000);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM commission'));
    }
}
