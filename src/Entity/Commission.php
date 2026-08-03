<?php
namespace App\Entity;

use App\Repository\CommissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommissionRepository::class)]
#[ORM\Table(name: 'commission')]
class Commission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $companyName = '';

    #[ORM\Column(length: 255)]
    private string $solution = '';

    #[ORM\Column(type: 'integer')]
    private int $amount = 0;

    #[ORM\Column(length: 50)]
    private string $status = 'pending'; // pending, encashed

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $encashedAt = null;

    #[ORM\ManyToOne(inversedBy: 'commissions')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    // One commission max per signed lead — prevents duplicates when re-saving a signed lead.
    #[ORM\OneToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(nullable: true, unique: true)]
    private ?Lead $lead = null;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getCompanyName(): string { return $this->companyName; }
    public function setCompanyName(string $name): self { $this->companyName = $name; return $this; }
    public function getSolution(): string { return $this->solution; }
    public function setSolution(string $solution): self { $this->solution = $solution; return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $amount): self { $this->amount = $amount; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getEncashedAt(): ?\DateTimeImmutable { return $this->encashedAt; }
    public function setEncashedAt(?\DateTimeImmutable $date): self { $this->encashedAt = $date; return $this; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }
    public function getLead(): ?Lead { return $this->lead; }
    public function setLead(?Lead $lead): self { $this->lead = $lead; return $this; }
}
