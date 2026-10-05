<?php
namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SmokeTest extends WebTestCase
{
    public function testHomePageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'application');
    }

    public function testLoginPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('protectedRoutes')]
    public function testProtectedRoutesRedirectAnonymousToLogin(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseRedirects('/login');
    }

    public static function protectedRoutes(): iterable
    {
        yield 'dashboard' => ['/dashboard'];
        yield 'support' => ['/support'];
        yield 'admin dashboard' => ['/admin'];
        yield 'admin products' => ['/admin/products'];
        yield 'admin users' => ['/admin/users'];
        yield 'admin leads' => ['/admin/leads'];
        yield 'admin tickets' => ['/admin/tickets'];
        yield 'admin commissions' => ['/admin/commissions'];
        yield 'admin applications' => ['/admin/applications'];
        yield 'admin invite' => ['/admin/invite'];
        yield 'lead new' => ['/lead/new'];
        yield 'leads list' => ['/leads'];
        yield 'commissions list' => ['/commissions'];
        yield 'profile' => ['/profile'];
        yield 'ticket new' => ['/ticket/new'];
    }
}
