<?php
namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResetPasswordTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (['commission', 'ticket_message', 'ticket', '`lead`', 'invitation', 'user'] as $table) {
            $this->em->getConnection()->executeStatement("DELETE FROM $table");
        }

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('reset@test.fr');
        $user->setName('Reset');
        $user->setRoles(['ROLE_CLIENT']);
        $user->setPassword($hasher->hashPassword($user, 'ancienpass8'));
        $this->em->persist($user);
        $this->em->flush();
    }

    public function testFullResetFlow(): void
    {
        // Request a reset — response must be neutral (redirect back).
        $crawler = $this->client->request('GET', '/forgot-password');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/forgot-password', ['_token' => $token, 'email' => 'reset@test.fr']);
        self::assertResponseRedirects('/forgot-password');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'reset@test.fr']);
        $this->em->refresh($user);
        self::assertNotNull($user->getResetToken());

        // Use the token to set a new password.
        $resetToken = $user->getResetToken();
        $crawler = $this->client->request('GET', '/reset-password/' . $resetToken);
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/reset-password/' . $resetToken, ['_token' => $csrf, 'password' => 'nouveaupass8']);
        self::assertResponseRedirects('/login');

        // Token consumed: reuse must 404.
        $this->client->request('GET', '/reset-password/' . $resetToken);
        self::assertResponseStatusCodeSame(404);

        $this->em->refresh($user);
        self::assertNull($user->getResetToken());
    }

    public function testUnknownEmailGetsSameNeutralResponse(): void
    {
        $crawler = $this->client->request('GET', '/forgot-password');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/forgot-password', ['_token' => $token, 'email' => 'inconnu@test.fr']);
        self::assertResponseRedirects('/forgot-password');
    }

    public function testUnknownTokenIs404(): void
    {
        $this->client->request('GET', '/reset-password/tokeninconnu');
        self::assertResponseStatusCodeSame(404);
    }
}
