<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

// "Devenir apporteur" form submission from the public site, reviewed by an admin before inviting.
#[ORM\Entity]
#[ORM\Table(name: 'apporteur_request')]
class ApporteurRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    // Apporteur who shared the referral link (earns the referral bonus).
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $referrer = null;

    public function getReferrer(): ?User { return $this->referrer; }
    public function setReferrer(?User $referrer): self { $this->referrer = $referrer; return $this; }

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $handledAt = null;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): self { $this->message = $message; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getHandledAt(): ?\DateTimeImmutable { return $this->handledAt; }
    public function setHandledAt(?\DateTimeImmutable $handledAt): self { $this->handledAt = $handledAt; return $this; }
}
