<?php
namespace App\Service;

use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

// Best-effort emails — never blocks the action that triggered them if the transport fails.
class Notifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $users,
    ) {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        try {
            $this->mailer->send((new Email())
                ->from('no-reply@klevup.fr')
                ->to($to)
                ->subject($subject)
                ->text($body . "\n\nL'équipe Klevup"));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function toAdmins(string $subject, string $body): void
    {
        foreach ($this->users->findByRole('ROLE_ADMIN') as $admin) {
            $this->send($admin->getEmail(), $subject, $body);
        }
    }
}
