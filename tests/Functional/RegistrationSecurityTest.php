<?php
namespace App\Tests\Functional;

use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegistrationSecurityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Isolate each test run.
        $this->em->getConnection()->executeStatement('DELETE FROM user');
        $this->em->getConnection()->executeStatement('DELETE FROM invitation');
    }

    private function createInvitation(string $code, string $role = 'ROLE_APPORTEUR', bool $expired = false): Invitation
    {
        $invitation = new Invitation();
        $invitation->setCode($code);
        $invitation->setRole($role);
        if ($expired) {
            $invitation->setExpiresAt(new \DateTimeImmutable('-1 day'));
        }
        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }

    public function testUnknownCodeReturns404(): void
    {
        $this->client->request('GET', '/register/doesnotexist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredInvitationReturns404(): void
    {
        $this->createInvitation('expiredcode', expired: true);

        $this->client->request('GET', '/register/expiredcode');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShortPasswordIsRejected(): void
    {
        $this->createInvitation('validcode1');

        $this->client->request('POST', '/register/validcode1', [
            'email' => 'new@test.fr',
            'name' => 'Test',
            'password' => 'court',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '8 caractères minimum');
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'new@test.fr']));
    }

    public function testRoleComesFromInvitationNotFromPost(): void
    {
        $this->createInvitation('validcode2', 'ROLE_CLIENT');

        // Attacker posts role=ROLE_ADMIN — it must be ignored.
        $this->client->request('POST', '/register/validcode2', [
            'email' => 'attacker@test.fr',
            'name' => 'Attacker',
            'password' => 'motdepasse8',
            'role' => 'ROLE_ADMIN',
        ]);

        self::assertResponseRedirects('/login');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'attacker@test.fr']);
        self::assertNotNull($user);
        self::assertSame(['ROLE_CLIENT'], $user->getRoles());
    }

    public function testInvitationIsSingleUse(): void
    {
        $this->createInvitation('validcode3');

        $this->client->request('POST', '/register/validcode3', [
            'email' => 'first@test.fr',
            'name' => 'First',
            'password' => 'motdepasse8',
        ]);
        self::assertResponseRedirects('/login');

        // Second use of the same code must 404.
        $this->client->request('GET', '/register/validcode3');
        self::assertResponseStatusCodeSame(404);
    }
}
