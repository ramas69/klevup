<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261005204115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Business model: setup fee + monthly subscription (subscription replaces maintenance contracts)';
    }

    public function up(Schema $schema): void
    {
        // The subscription replaces maintenance contracts: their end dates are NOT cancellation dates,
        // so the old column is dropped instead of renamed (every client would otherwise look cancelled).
        $this->addSql('ALTER TABLE application ADD monthlyPrice INT DEFAULT NULL, ADD subscriptionEndsAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', DROP maintenanceUntil');
        $this->addSql('ALTER TABLE `lead` ADD monthlyAmount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD monthlyFrom INT NOT NULL DEFAULT 0');

        // Copy that promised a one-off payment — targeted replacements so admin edits are kept.
        $this->addSql(<<<'SQL'
            UPDATE product SET description = REPLACE(description, 'qui remplace Excel et les outils à 300 €/mois.', 'qui remplace Excel et les outils dispersés.')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE product SET pitch = REPLACE(pitch, 'Payé une fois (dès 3 000 €) au lieu de 150 à 300 €/mois d''abonnement : rentabilisé en moins de 2 ans.', 'Hébergement, mises à jour et support inclus dans l''abonnement : rien d''autre à gérer.')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE product SET pitch = REPLACE(pitch, 'Payé une fois, sans abonnement par utilisateur.', 'Pas de licence par utilisateur : toute l''équipe en profite.')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE application ADD maintenanceUntil DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', DROP monthlyPrice, DROP subscriptionEndsAt');
        $this->addSql('ALTER TABLE `lead` DROP monthlyAmount');
        $this->addSql('ALTER TABLE product DROP monthlyFrom');
    }
}
