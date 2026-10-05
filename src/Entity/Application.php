<?php
namespace App\Entity;

use App\Repository\ApplicationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ORM\Table(name: 'application')]
class Application
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $description = '';

    #[ORM\Column(length: 50)]
    private string $version = '1.0';

    #[ORM\Column(length: 255)]
    private string $url = '';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $launchedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $maintenanceUntil = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $client;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): self { $this->product = $product; return $this; }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = $description; return $this; }
    public function getVersion(): string { return $this->version; }
    public function setVersion(string $version): self { $this->version = $version; return $this; }
    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): self { $this->url = $url; return $this; }
    public function getLaunchedAt(): ?\DateTimeImmutable { return $this->launchedAt; }
    public function setLaunchedAt(?\DateTimeImmutable $date): self { $this->launchedAt = $date; return $this; }
    public function getMaintenanceUntil(): ?\DateTimeImmutable { return $this->maintenanceUntil; }
    public function setMaintenanceUntil(?\DateTimeImmutable $date): self { $this->maintenanceUntil = $date; return $this; }
    public function getClient(): User { return $this->client; }
    public function setClient(User $client): self { $this->client = $client; return $this; }
}
