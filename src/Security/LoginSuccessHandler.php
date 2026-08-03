<?php
namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        // If the firewall stored the page the user originally tried to reach, go back there.
        $targetPath = $this->getTargetPath($request->getSession(), 'main');
        if ($targetPath) {
            $this->removeTargetPath($request->getSession(), 'main');

            return new RedirectResponse($targetPath);
        }

        // Otherwise, land on the home page of the user's role.
        $roles = $token->getRoleNames();
        $route = match (true) {
            in_array('ROLE_ADMIN', $roles, true) => 'admin_users',
            in_array('ROLE_APPORTEUR', $roles, true) => 'dashboard',
            in_array('ROLE_CLIENT', $roles, true) => 'support',
            default => 'home',
        };

        return new RedirectResponse($this->urlGenerator->generate($route));
    }
}
