<?php

namespace App\Entity;

use App\Repository\LeadRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LeadRepository::class)]
#[ORM\Table(name: '`lead`')]
class Lead
{
    public const SOURCE_APPORTEUR = 'apporteur';
    public const SOURCE_SITE = 'site';
    public const SOURCE_CLIENT = 'client';

    public const TIMELINES = ['urgent' => 'Urgent (moins d\'1 mois)', '3m' => 'Sous 3 mois', '6m' => 'Sous 6 mois', 'unknown' => 'Pas encore défini'];
    public const BUDGETS = ['lt2k' => 'Moins de 2 000 €', '2k5k' => '2 000 – 5 000 €', 'gt5k' => 'Plus de 5 000 €', 'unknown' => 'Inconnu'];
    public const RELATIONSHIPS = ['client' => 'C\'est mon client', 'network' => 'Contact de mon réseau pro', 'friend' => 'Proche / ami', 'cold' => 'Je ne le connais pas encore'];
    public const LOST_REASONS = ['budget' => 'Budget insuffisant', 'timing' => 'Pas le bon moment', 'competitor' => 'A choisi un concurrent', 'no_need' => 'Pas de besoin réel', 'unreachable' => 'Injoignable', 'out_of_scope' => 'Hors de notre périmètre', 'other' => 'Autre raison'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Company / organisation name.
    #[ORM\Column(length: 255)]
    private string $contact = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    // Qualification given by the apporteur (keys of the constants above).
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $contactRole = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $timeline = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $budget = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $relationship = null;

    // When the prospect agreed to be contacted (GDPR proof for third-party referrals).
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consentAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lostReason = null;

    // Snapshot of the product name (kept even if the product is renamed or removed).
    #[ORM\Column(length: 255)]
    private string $solution = '';

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    #[ORM\Column(length: 50)]
    private string $status = 'new';

    #[ORM\Column(length: 20)]
    private string $source = self::SOURCE_APPORTEUR;

    // Setup fee in € (quoted, then signed), set by the admin — the only basis of the commission.
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dealAmount = null;

    // Monthly subscription in € — tracked for recurring revenue, not commissioned.
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $monthlyAmount = null;

    // Computed commission in € (estimate until signed, final once signed).
    #[ORM\Column(type: 'integer')]
    private int $commission = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $signedAt = null;

    // Null for inbound leads (public site, existing client).
    #[ORM\ManyToOne(inversedBy: 'leads')]
    #[ORM\JoinColumn(name: 'apporteur_id', nullable: true)]
    private ?User $apporteur = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getContact(): string { return $this->contact; }
    public function setContact(string $contact): self { $this->contact = $contact; return $this; }
    public function getContactName(): ?string { return $this->contactName; }
    public function setContactName(?string $contactName): self { $this->contactName = $contactName; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getContactRole(): ?string { return $this->contactRole; }
    public function setContactRole(?string $contactRole): self { $this->contactRole = $contactRole; return $this; }
    public function getTimeline(): ?string { return $this->timeline; }
    public function setTimeline(?string $timeline): self { $this->timeline = $timeline; return $this; }
    public function getBudget(): ?string { return $this->budget; }
    public function setBudget(?string $budget): self { $this->budget = $budget; return $this; }
    public function getRelationship(): ?string { return $this->relationship; }
    public function setRelationship(?string $relationship): self { $this->relationship = $relationship; return $this; }
    public function getConsentAt(): ?\DateTimeImmutable { return $this->consentAt; }
    public function setConsentAt(?\DateTimeImmutable $consentAt): self { $this->consentAt = $consentAt; return $this; }
    public function getLostReason(): ?string { return $this->lostReason; }
    public function setLostReason(?string $lostReason): self { $this->lostReason = $lostReason; return $this; }
    public function getSolution(): string { return $this->solution; }
    public function setSolution(string $solution): self { $this->solution = $solution; return $this; }
    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): self { $this->product = $product; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): self { $this->source = $source; return $this; }
    public function getDealAmount(): ?int { return $this->dealAmount; }
    public function setDealAmount(?int $dealAmount): self { $this->dealAmount = $dealAmount; return $this; }
    public function getMonthlyAmount(): ?int { return $this->monthlyAmount; }
    public function setMonthlyAmount(?int $monthlyAmount): self { $this->monthlyAmount = $monthlyAmount; return $this; }
    public function getCommission(): int { return $this->commission; }
    public function setCommission(int $commission): self { $this->commission = $commission; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getSignedAt(): ?\DateTimeImmutable { return $this->signedAt; }
    public function setSignedAt(?\DateTimeImmutable $signedAt): self { $this->signedAt = $signedAt; return $this; }
    public function getApporteur(): ?User { return $this->apporteur; }
    public function setApporteur(?User $apporteur): self { $this->apporteur = $apporteur; return $this; }
}
