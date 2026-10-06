<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 50)]
    private string $status = 'active';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $invitationCode = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    // Apporteur billing identity, printed on commission statements.
    #[ORM\Column(length: 14, nullable: true)]
    private ?string $siret = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $billingAddress = null;

    // Personal code in the apporteur's referral link.
    #[ORM\Column(length: 20, unique: true, nullable: true)]
    private ?string $referralCode = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $referredBy = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(mappedBy: 'apporteur', targetEntity: Lead::class)]
    private Collection $leads;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Commission::class)]
    private Collection $commissions;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Ticket::class)]
    private Collection $tickets;

    public function __construct()
    {
        $this->leads = new ArrayCollection();
        $this->commissions = new ArrayCollection();
        $this->tickets = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): self { $this->password = $password; return $this; }
    public function getRoles(): array { return $this->roles ?: ['ROLE_USER']; }
    public function setRoles(array $roles): self { $this->roles = $roles; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getInvitationCode(): ?string { return $this->invitationCode; }
    public function setInvitationCode(?string $code): self { $this->invitationCode = $code; return $this; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $this->iban = $iban; return $this; }
    public function getResetToken(): ?string { return $this->resetToken; }
    public function setResetToken(?string $token): self { $this->resetToken = $token; return $this; }
    public function getResetTokenExpiresAt(): ?\DateTimeImmutable { return $this->resetTokenExpiresAt; }
    public function setResetTokenExpiresAt(?\DateTimeImmutable $date): self { $this->resetTokenExpiresAt = $date; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLeads(): Collection { return $this->leads; }
    public function getCommissions(): Collection { return $this->commissions; }
    public function getTickets(): Collection { return $this->tickets; }
    public function getSiret(): ?string { return $this->siret; }
    public function setSiret(?string $siret): self { $this->siret = $siret; return $this; }
    public function getBillingAddress(): ?string { return $this->billingAddress; }
    public function setBillingAddress(?string $billingAddress): self { $this->billingAddress = $billingAddress; return $this; }
    public function getReferralCode(): ?string { return $this->referralCode; }
    public function setReferralCode(?string $referralCode): self { $this->referralCode = $referralCode; return $this; }
    public function getReferredBy(): ?User { return $this->referredBy; }
    public function setReferredBy(?User $referredBy): self { $this->referredBy = $referredBy; return $this; }

    public function eraseCredentials(): void {}

    // $this = user stored in the session, $user = fresh DB copy: a disabled account, a changed password
    // or a changed role ends open sessions (the user logs in again with the new rights).
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->getStatus() !== 'disabled'
            && $user->getId() === $this->id
            && $user->getPassword() === $this->password
            && $user->getRoles() === $this->getRoles();
    }
    public function getUserIdentifier(): string { return $this->email; }
}
