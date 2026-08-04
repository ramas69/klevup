<?php
namespace App\Tests\Functional;

use App\Entity\Commission;
use App\Entity\Lead;
use App\Entity\User;
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

        foreach (['commission', 'ticket_message', 'ticket', '`lead`', 'invitation', 'user'] as $table) {
            $this->em->getConnection()->executeStatement("DELETE FROM $table");
        }

        $this->admin = $this->makeUser('admin@test.fr', 'ROLE_ADMIN');
        $this->apporteur = $this->makeUser('app@test.fr', 'ROLE_APPORTEUR');

        $this->lead = new Lead();
        $this->lead->setContact('Test SA');
        $this->lead->setSolution('Gestion OF');
        $this->lead->setCommission(500);
        $this->lead->setApporteur($this->apporteur);
        $this->em->persist($this->lead);
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

    private function updateLead(string $status, int $commission): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/leads');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter(sprintf('form[action="/admin/lead/%d/update"] input[name="_token"]', $this->lead->getId()))->attr('value');
        $this->client->request('POST', sprintf('/admin/lead/%d/update', $this->lead->getId()), [
            '_token' => $token,
            'status' => $status,
            'commission' => $commission,
        ]);
        self::assertResponseRedirects('/admin/leads');
    }

    public function testSigningALeadCreatesExactlyOneCommission(): void
    {
        $this->updateLead('signed', 500);

        $commissions = $this->em->getRepository(Commission::class)->findBy(['lead' => $this->lead]);
        self::assertCount(1, $commissions);
        self::assertSame(500, $commissions[0]->getAmount());
        self::assertSame('pending', $commissions[0]->getStatus());
        self::assertSame($this->apporteur->getId(), $commissions[0]->getUser()->getId());

        // Re-saving the signed lead must NOT create a duplicate.
        $this->updateLead('signed', 500);
        self::assertCount(1, $this->em->getRepository(Commission::class)->findBy(['lead' => $this->lead]));
    }

    public function testLostLeadCreatesNoCommission(): void
    {
        $this->updateLead('lost', 500);

        self::assertCount(0, $this->em->getRepository(Commission::class)->findAll());
    }

    public function testEncashMarksCommissionPaid(): void
    {
        $this->updateLead('signed', 750);
        $commission = $this->em->getRepository(Commission::class)->findOneBy(['lead' => $this->lead]);

        $crawler = $this->client->request('GET', '/admin/commissions');
        $token = $crawler->filter(sprintf('form[action="/admin/commission/%d/encash"] input[name="_token"]', $commission->getId()))->attr('value');
        $this->client->request('POST', sprintf('/admin/commission/%d/encash', $commission->getId()), ['_token' => $token]);
        self::assertResponseRedirects('/admin/commissions');

        $this->em->refresh($commission);
        self::assertSame('encashed', $commission->getStatus());
        self::assertNotNull($commission->getEncashedAt());
        self::assertSame(750, $commission->getAmount());
    }

    public function testApporteurCannotUpdateLeads(): void
    {
        $this->client->loginUser($this->apporteur);
        $this->client->request('POST', sprintf('/admin/lead/%d/update', $this->lead->getId()), [
            'status' => 'signed',
            'commission' => 99999,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em->getRepository(Commission::class)->findAll());
    }
}
