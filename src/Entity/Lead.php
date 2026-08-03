<?php

namespace App\Entity;

use App\Repository\LeadRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LeadRepository::class)]
#[ORM\Table(name: '`lead`')]
class Lead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $contact = '';

    #[ORM\Column(length: 255)]
    private string $solution = '';

    #[ORM\Column(length: 50)]
    private string $status = 'new';

    #[ORM\Column(type: 'integer')]
    private int $commission = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(inversedBy: 'leads')]
    #[ORM\JoinColumn(name: 'apporteur_id', nullable: false)]
    private User $apporteur;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getContact(): string { return $this->contact; }
    public function setContact(string $contact): self { $this->contact = $contact; return $this; }
    public function getSolution(): string { return $this->solution; }
    public function setSolution(string $solution): self { $this->solution = $solution; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getCommission(): int { return $this->commission; }
    public function setCommission(int $commission): self { $this->commission = $commission; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getApporteur(): User { return $this->apporteur; }
    public function setApporteur(User $apporteur): self { $this->apporteur = $apporteur; return $this; }
}
