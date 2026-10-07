<?php
namespace App\Tests\Functional;

use App\Entity\Application;
use App\Entity\ApporteurRequest;
use App\Entity\Lead;
use App\Entity\Product;
use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

// Inbound channels, cross-sell, account controls and the bug fixes from the October 2026 audit.
class SalesFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        foreach (['commission', 'ticket_message', 'ticket', '`lead`', 'invitation', 'application', 'apporteur_request', 'user'] as $table) {
            $this->em->getConnection()->executeStatement("DELETE FROM $table");
        }
    }

    private function makeUser(string $email, string $role, string $password = 'irrelevant-hash'): User
    {
        $user = (new User())->setEmail($email)->setName(ucfirst(explode('@', $email)[0]))->setRoles([$role])->setPassword($password);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function product(): Product
    {
        return $this->em->getRepository(Product::class)->findOneBy(['active' => true], ['position' => 'ASC']);
    }

    /** Submits the first form whose action ends with $action on the page at $page. */
    private function submit(string $page, string $action, array $fields): void
    {
        $crawler = $this->client->request('GET', $page);
        $form = $crawler->filter($action === '' ? 'form' : sprintf('form[action$="%s"]', $action))->form();
        $this->client->submit($form, $fields);
    }

    private function leads(): array
    {
        $this->em->clear();

        return $this->em->getRepository(Lead::class)->findAll();
    }

    public function testHomeListsTheCatalogue(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#solutions', $this->product()->getName());
    }

    public function testDemoRequestCreatesASiteLead(): void
    {
        $this->submit('/', '/demo', ['contact' => 'Formapro', 'email' => 'julie@formapro.fr', 'product_id' => $this->product()->getId()]);
        self::assertResponseRedirects('/#contact');

        $leads = $this->leads();
        self::assertCount(1, $leads);
        self::assertSame(Lead::SOURCE_SITE, $leads[0]->getSource());
        self::assertNull($leads[0]->getApporteur());
        self::assertSame($this->product()->getName(), $leads[0]->getSolution());
    }

    public function testExpiredCsrfOnPublicFormStaysOnTheSite(): void
    {
        $this->client->request('POST', '/demo', ['_token' => 'stale', 'contact' => 'Formapro', 'email' => 'julie@formapro.fr']);

        self::assertResponseRedirects('/#contact');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.ko', 'session a expiré');
        self::assertCount(0, $this->leads());
    }

    public function testDemoHoneypotStoresNothing(): void
    {
        $this->submit('/', '/demo', ['contact' => 'Bot', 'email' => 'bot@spam.io', 'website' => 'http://spam']);

        self::assertCount(0, $this->leads());
    }

    public function testApporteurApplicationIsStoredAndCanBeInvited(): void
    {
        $this->submit('/', '/devenir-apporteur', ['name' => 'Paul Martin', 'email' => 'paul@reseau.fr']);
        self::assertResponseRedirects('/#apporteur');
        self::assertCount(1, $this->em->getRepository(ApporteurRequest::class)->findAll());

        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));
        $request = $this->em->getRepository(ApporteurRequest::class)->findOneBy([]);
        $this->submit('/admin/invite', sprintf('/admin/apporteur-request/%d/invite', $request->getId()), []);

        $this->em->clear();
        self::assertNotNull($this->em->find(ApporteurRequest::class, $request->getId())->getHandledAt());
        self::assertSame('paul@reseau.fr', $this->em->getConnection()->fetchOne('SELECT email FROM invitation'));
    }

    public function testApporteurLeadNeedsContactDetailsAndIsDeduplicated(): void
    {
        $this->client->loginUser($this->makeUser('app@test.fr', 'ROLE_APPORTEUR'));
        $productId = $this->product()->getId();

        $this->submit('/lead/new', '', ['contact' => 'Cabinet Morel', 'product_id' => $productId]);
        self::assertSelectorTextContains('.error', 'email ou un téléphone');
        self::assertCount(0, $this->leads());

        $qualified = ['timeline' => '3m', 'budget' => '2k5k', 'relationship' => 'network', 'consent' => '1'];
        $this->submit('/lead/new', '', ['contact' => 'Cabinet Morel', 'phone' => '06 12 34 56 78', 'product_id' => $productId] + $qualified);
        self::assertResponseRedirects('/leads');
        self::assertCount(1, $this->leads());

        // Same company, same solution, other apporteur → refused.
        $this->client->loginUser($this->makeUser('app2@test.fr', 'ROLE_APPORTEUR'));
        $this->submit('/lead/new', '', ['contact' => 'cabinet morel', 'email' => 'x@morel.fr', 'product_id' => $productId] + $qualified);
        self::assertSelectorTextContains('.error', 'déjà été transmis');
        self::assertCount(1, $this->leads());
    }

    public function testClientSeesAllApplicationsAndCanAskForAnotherProduct(): void
    {
        $client = $this->makeUser('client@test.fr', 'ROLE_CLIENT');
        $owned = $this->product();
        foreach (['App Une', 'App Deux'] as $name) {
            $this->em->persist((new Application())->setName($name)->setClient($client)->setProduct($owned)->setMonthlyPrice(150));
        }
        $this->em->flush();
        $this->client->loginUser($client);

        $crawler = $this->client->request('GET', '/support');
        self::assertCount(2, $crawler->filter('.app-card'));
        self::assertSelectorTextContains('.app-card', 'Abonnement actif — 150 €/mois');
        self::assertStringNotContainsString(sprintf('/support/interest/%d"', $owned->getId()), $this->client->getResponse()->getContent());

        $other = $this->em->getRepository(Product::class)->findOneBy(['active' => true, 'position' => 2]);
        $this->submit('/support', sprintf('/support/interest/%d', $other->getId()), []);
        $this->submit('/support', sprintf('/support/interest/%d', $other->getId()), []);

        $leads = $this->leads();
        self::assertCount(1, $leads, 'a second click must not duplicate the opportunity');
        self::assertSame(Lead::SOURCE_CLIENT, $leads[0]->getSource());
    }

    public function testNewTicketIsNotShownAsResolved(): void
    {
        $client = $this->makeUser('client@test.fr', 'ROLE_CLIENT');
        $this->em->persist((new Ticket())->setReference('KLV-001')->setTitle('Bug export')->setDescription('...')->setType('question')->setUser($client));
        $this->em->flush();
        $this->client->loginUser($client);

        $this->client->request('GET', '/support');

        self::assertSelectorTextContains('#tickets tbody', 'Reçue');
        self::assertSelectorTextNotContains('#tickets tbody', 'Résolu');
        self::assertSelectorTextContains('#tickets tbody', 'Question');
    }

    public function testDeletingAUserWithAnApplicationDoesNotCrash(): void
    {
        $client = $this->makeUser('client@test.fr', 'ROLE_CLIENT');
        $this->em->persist((new Application())->setName('App')->setClient($client));
        $this->em->flush();
        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));

        $this->submit('/admin/users', sprintf('/admin/user/%d/delete', $client->getId()), []);

        self::assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'désactivez-le');
    }

    public function testInvalidApplicationDateIsRejectedCleanly(): void
    {
        $client = $this->makeUser('client@test.fr', 'ROLE_CLIENT');
        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));

        $crawler = $this->client->request('GET', '/admin/applications');
        $token = $crawler->filter('form[action$="/admin/application/create"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/admin/application/create', ['_token' => $token, 'name' => 'X', 'client_id' => $client->getId(), 'launched_at' => 'n/importe']);

        self::assertResponseRedirects('/admin/applications');
        self::assertCount(0, $this->em->getRepository(Application::class)->findAll());
    }

    public function testDisabledAccountCannotLogIn(): void
    {
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $user = $this->makeUser('app@test.fr', 'ROLE_APPORTEUR');
        $user->setPassword($hasher->hashPassword($user, 'motdepasse1'))->setStatus('disabled');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form')->form(), ['_username' => 'app@test.fr', '_password' => 'motdepasse1']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'désactivé');
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testInviteRequiresCsrf(): void
    {
        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));

        $this->client->request('POST', '/admin/invite', ['role' => 'ROLE_APPORTEUR', 'email' => 'attacker@evil.io']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invitation'));
    }

    public function testChangingIbanRequiresCurrentPassword(): void
    {
        $user = $this->makeUser('app@test.fr', 'ROLE_APPORTEUR');
        $this->client->loginUser($user);

        $this->submit('/profile', '/profile', ['name' => 'App', 'email' => 'app@test.fr', 'iban' => 'FR7630006000011234567890189']);

        $this->em->clear();
        self::assertNull($this->em->find(User::class, $user->getId())->getIban());
    }

    public function testEndedSubscriptionIsShownAndLeavesTheMrr(): void
    {
        $client = $this->makeUser('client@test.fr', 'ROLE_CLIENT');
        $this->em->persist((new Application())->setName('Active')->setClient($client)->setMonthlyPrice(150));
        $this->em->persist((new Application())->setName('Partie')->setClient($client)->setMonthlyPrice(90)->setSubscriptionEndsAt(new \DateTimeImmutable('-1 day')));
        $this->em->flush();

        $this->client->loginUser($client);
        $this->client->request('GET', '/support');
        self::assertSelectorTextContains('body', 'Abonnement terminé');

        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));
        $crawler = $this->client->request('GET', '/admin');
        self::assertStringContainsString('150 €', $crawler->filter('.kpi')->eq(4)->text());
    }

    public function testEveryAdminPageRendersWithData(): void
    {
        $apporteur = $this->makeUser('app@test.fr', 'ROLE_APPORTEUR');
        $client = $this->makeUser('client@test.fr', 'ROLE_CLIENT');
        $this->em->persist((new Lead())->setContact('Signée SA')->setSolution($this->product()->getName())->setProduct($this->product())->setApporteur($apporteur)->setStatus('signed')->setDealAmount(3000)->setCommission(450)->setSignedAt(new \DateTimeImmutable()));
        $this->em->persist((new Lead())->setContact('Site SARL')->setSolution('Autre / sur-mesure')->setSource(Lead::SOURCE_SITE)->setEmail('a@b.fr'));
        $this->em->persist((new Application())->setName('App')->setClient($client)->setMonthlyPrice(150)->setSubscriptionEndsAt(new \DateTimeImmutable('+10 days')));
        $this->em->persist((new ApporteurRequest())->setName('Candidat')->setEmail('c@d.fr'));
        $this->em->flush();
        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));

        $productId = $this->product()->getId();
        foreach (['/admin', '/admin/leads', '/admin/commissions', '/admin/tickets', '/admin/applications', '/admin/products', '/admin/product/new', '/admin/product/' . $productId . '/edit', '/admin/leads?tab=signed', '/admin/users', '/admin/invite', '/profile'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
        }

        foreach ($this->em->getRepository(Lead::class)->findAll() as $lead) {
            $this->client->request('GET', '/admin/lead/' . $lead->getId());
            self::assertResponseIsSuccessful('lead ' . $lead->getContact());
        }

        $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('body', '3 000 €');
        self::assertSelectorTextContains('body', 'Abonnements résiliés');

        $this->client->loginUser($apporteur);
        foreach (['/leads', '/lead/new', '/commissions', '/dashboard'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
        }
        self::assertSelectorTextContains('body', 'IBAN');
    }

    public function testDisabledUserIsLoggedOutOnTheVeryNextRequest(): void
    {
        $user = $this->makeUser('app@test.fr', 'ROLE_APPORTEUR');
        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $this->em->getConnection()->executeStatement("UPDATE user SET status = 'disabled' WHERE id = " . $user->getId());

        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testApporteurApplicationCannotBeInvitedTwice(): void
    {
        $this->em->persist((new ApporteurRequest())->setName('Paul')->setEmail('paul@reseau.fr'));
        $this->em->flush();
        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));
        $request = $this->em->getRepository(ApporteurRequest::class)->findOneBy([]);
        $crawler = $this->client->request('GET', '/admin/invite');
        $token = $crawler->filter('form[action$="/invite"] input[name=_token]')->attr('value');

        foreach ([1, 2] as $i) {
            $this->client->request('POST', sprintf('/admin/apporteur-request/%d/invite', $request->getId()), ['_token' => $token]);
        }

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invitation'));
    }

    public function testArrayInputOnPublicFormIsABadRequest(): void
    {
        $crawler = $this->client->request('GET', '/');
        $token = $crawler->filter('form[action$="/demo"] input[name=_token]')->attr('value');

        $this->client->request('POST', '/demo', ['_token' => $token, 'contact' => ['x'], 'email' => 'a@b.fr']);

        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $this->leads());
    }

    public function testLeadNeedsConsentAndCustomNeedsADescription(): void
    {
        $this->client->loginUser($this->makeUser('app@test.fr', 'ROLE_APPORTEUR'));
        $base = ['contact' => 'Morel', 'phone' => '0612345678', 'timeline' => '3m', 'budget' => 'unknown', 'relationship' => 'client'];

        // Browser-side "required" is bypassed on purpose: the server must refuse too.
        $crawler = $this->client->request('GET', '/lead/new');
        $token = $crawler->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', '/lead/new', ['_token' => $token, 'product_id' => $this->product()->getId()] + $base);
        self::assertSelectorTextContains('.error', 'd\'accord pour être recontacté');

        $this->client->request('POST', '/lead/new', ['_token' => $token, 'product_id' => '0', 'consent' => '1', 'notes' => 'court'] + $base);
        self::assertSelectorTextContains('.error', 'sur-mesure');
        self::assertCount(0, $this->leads());

        $this->client->request('POST', '/lead/new', ['_token' => $token, 'product_id' => '0', 'consent' => '1', 'notes' => 'Application de planning pour 12 techniciens itinérants'] + $base);
        self::assertResponseRedirects('/leads');
        $lead = $this->leads()[0];
        self::assertNotNull($lead->getConsentAt());
        self::assertSame('client', $lead->getRelationship());
    }

    public function testReferralLinkFlowsFromApplicationToRegistration(): void
    {
        $referrer = $this->makeUser('parrain@test.fr', 'ROLE_APPORTEUR');
        $this->client->loginUser($referrer);
        $this->client->request('GET', '/parrainage');
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $code = $this->em->find(User::class, $referrer->getId())->getReferralCode();
        self::assertNotNull($code);

        $this->client->request('GET', '/logout');
        $this->submit('/?ref=' . $code, '/devenir-apporteur', ['name' => 'Filleul', 'email' => 'filleul@test.fr']);

        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));
        $request = $this->em->getRepository(ApporteurRequest::class)->findOneBy([]);
        self::assertSame($referrer->getId(), $request->getReferrer()?->getId());
        $this->submit('/admin/invite', sprintf('/admin/apporteur-request/%d/invite', $request->getId()), []);

        $this->client->request('GET', '/logout');
        $code = $this->em->getConnection()->fetchOne('SELECT code FROM invitation');
        $this->client->request('POST', '/register/' . $code, ['name' => 'Filleul', 'email' => 'ignored@x.fr', 'password' => 'motdepasse1']);
        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
        self::assertEmailSubjectContains(self::getMailerMessage(), 'Bienvenue');

        $this->em->clear();
        $referee = $this->em->getRepository(User::class)->findOneBy(['email' => 'filleul@test.fr']);
        self::assertSame($referrer->getId(), $referee->getReferredBy()?->getId());
    }

    public function testKitShowsThePitch(): void
    {
        $this->client->loginUser($this->makeUser('app@test.fr', 'ROLE_APPORTEUR'));
        $this->client->request('GET', '/kit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kit', 'Le déclic');
    }

    public function testIdleApporteurIsNudgedAtThirtyDays(): void
    {
        $this->makeUser('actif@test.fr', 'ROLE_APPORTEUR');
        $idle = $this->makeUser('idle@test.fr', 'ROLE_APPORTEUR');
        $this->em->getConnection()->executeStatement('UPDATE user SET createdAt = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ' . $idle->getId());
        $this->em->clear();

        $tester = new CommandTester((new ConsoleApplication(self::$kernel))->find('app:apporteur-reminders'));
        $tester->execute([]);

        self::assertStringContainsString('1 relance', $tester->getDisplay());
    }

    public function testUsersCanBeSearchedFilteredAndCounted(): void
    {
        $this->makeUser('julie@test.fr', 'ROLE_APPORTEUR');
        $this->makeUser('marc@test.fr', 'ROLE_APPORTEUR');
        $this->makeUser('formapro@test.fr', 'ROLE_CLIENT');
        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));

        $crawler = $this->client->request('GET', '/admin/users?role=ROLE_APPORTEUR');
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('h1', '4');

        $crawler = $this->client->request('GET', '/admin/users?q=formapro');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'formapro@test.fr');
    }

    public function testAdminCanPromoteSomeoneButNotChangeOwnRole(): void
    {
        $julie = $this->makeUser('julie@test.fr', 'ROLE_APPORTEUR');
        $admin = $this->makeUser('admin@test.fr', 'ROLE_ADMIN');
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users');
        $token = $crawler->filter(sprintf('form[action="/admin/user/%d/role"] input[name=_token]', $julie->getId()))->attr('value');
        $this->client->request('POST', sprintf('/admin/user/%d/role', $julie->getId()), ['_token' => $token, 'role' => 'ROLE_ADMIN']);
        self::assertResponseRedirects('/admin/users');
        $this->em->clear();
        self::assertSame(['ROLE_ADMIN'], $this->em->find(User::class, $julie->getId())->getRoles());

        // Own row has no role form at all; a forged request is refused too.
        self::assertCount(0, $crawler->filter(sprintf('form[action="/admin/user/%d/role"]', $admin->getId())));
        $this->client->request('POST', sprintf('/admin/user/%d/role', $admin->getId()), ['_token' => $token, 'role' => 'ROLE_CLIENT']);
        $this->em->clear();
        self::assertSame(['ROLE_ADMIN'], $this->em->find(User::class, $admin->getId())->getRoles());
    }

    public function testRoleChangeEndsTheOpenSession(): void
    {
        $julie = $this->makeUser('julie@test.fr', 'ROLE_APPORTEUR');
        $this->client->loginUser($julie);
        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $this->em->getConnection()->executeStatement('UPDATE user SET roles = \'["ROLE_CLIENT"]\' WHERE id = ' . $julie->getId());

        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testInviteFromUsersPageStaysThereAndCanCreateAnAdmin(): void
    {
        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('#invite-dialog form')->form(), ['role' => 'ROLE_ADMIN', 'email' => 'associe@test.fr']);

        self::assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        self::assertSelectorExists('.link-box');
        self::assertSame('ROLE_ADMIN', $this->em->getConnection()->fetchOne('SELECT role FROM invitation'));
    }

    public function testApporteurPageUsesSharedHeaderAndAnswersOnTheSamePage(): void
    {
        $crawler = $this->client->request('GET', '/devenir-apporteur');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.site-nav .k-logo');
        self::assertSelectorExists('.site-footer');
        self::assertSelectorTextContains('h1', (string) (int) round(3000 * \App\Service\CommissionCalculator::DEFAULT_RATE / 100));

        $this->client->submit($crawler->filter('#rejoindre form')->form(), ['name' => 'Paul', 'email' => 'paul@reseau.fr']);
        self::assertResponseRedirects('/devenir-apporteur#rejoindre');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#rejoindre', 'Candidature reçue');
    }

    public function testEveryPublicPageHasTheSameLogoAndMenu(): void
    {
        foreach (['/', '/devenir-apporteur', '/mentions-legales', '/confidentialite'] as $page) {
            $this->client->request('GET', $page);
            self::assertSelectorExists('.site-nav .k-logo', $page);
            self::assertSelectorExists('.site-nav .burger', $page);
        }
        foreach (['/login', '/forgot-password'] as $page) {
            $this->client->request('GET', $page);
            self::assertSelectorExists('.auth-logo .k-logo', $page);
        }
    }

    public function testContractGeneratorRenders(): void
    {
        $this->client->loginUser($this->makeUser('admin@test.fr', 'ROLE_ADMIN'));
        $this->client->request('GET', '/admin/contrat-apporteur');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar .k-logo');
    }
}
