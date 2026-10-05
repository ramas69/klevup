<?php
namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;

// Catalogue entry: drives the public site, the lead form, client cross-sell and the commission rate.
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'product')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 500)]
    private string $description = '';

    // Sales kit for apporteurs.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $target = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $pitch = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $resourceUrl = null;

    #[ORM\Column(type: 'integer')]
    private int $priceFrom = 0;

    #[ORM\Column(type: 'integer')]
    private int $commissionRate = 15;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private bool $featured = false;

    #[ORM\Column(type: 'integer')]
    private int $position = 0;

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = $description; return $this; }
    public function getTarget(): ?string { return $this->target; }
    public function setTarget(?string $target): self { $this->target = $target; return $this; }
    public function getPitch(): ?string { return $this->pitch; }
    public function setPitch(?string $pitch): self { $this->pitch = $pitch; return $this; }
    public function getResourceUrl(): ?string { return $this->resourceUrl; }
    public function setResourceUrl(?string $resourceUrl): self { $this->resourceUrl = $resourceUrl; return $this; }
    public function getPriceFrom(): int { return $this->priceFrom; }
    public function setPriceFrom(int $priceFrom): self { $this->priceFrom = $priceFrom; return $this; }
    public function getCommissionRate(): int { return $this->commissionRate; }
    public function setCommissionRate(int $commissionRate): self { $this->commissionRate = $commissionRate; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }
    public function isFeatured(): bool { return $this->featured; }
    public function setFeatured(bool $featured): self { $this->featured = $featured; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
}
