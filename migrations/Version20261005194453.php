<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261005194453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product catalogue, richer leads (contact, deal amount, source, signedAt), apporteur requests';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE apporteur_request (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(30) DEFAULT NULL, message LONGTEXT DEFAULT NULL, createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', handledAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(500) NOT NULL, priceFrom INT NOT NULL, commissionRate INT NOT NULL, active TINYINT(1) NOT NULL, featured TINYINT(1) NOT NULL, position INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE application ADD product_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE application ADD CONSTRAINT FK_A45BDDC14584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A45BDDC14584665A ON application (product_id)');
        $this->addSql('ALTER TABLE `lead` ADD product_id INT DEFAULT NULL, ADD contactName VARCHAR(255) DEFAULT NULL, ADD email VARCHAR(180) DEFAULT NULL, ADD phone VARCHAR(30) DEFAULT NULL, ADD notes LONGTEXT DEFAULT NULL, ADD source VARCHAR(20) NOT NULL, ADD dealAmount INT DEFAULT NULL, ADD signedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE apporteur_id apporteur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `lead` ADD CONSTRAINT FK_289161CB4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_289161CB4584665A ON `lead` (product_id)');

        // Data: existing leads came from apporteurs; signed ones get their signature date from their commission.
        $this->addSql("UPDATE `lead` SET source = 'apporteur'");
        $this->addSql("UPDATE `lead` l JOIN commission c ON c.lead_id = l.id SET l.signedAt = c.createdAt WHERE l.status = 'signed'");
        $this->addSql("UPDATE `lead` SET signedAt = createdAt WHERE status = 'signed' AND signedAt IS NULL");

        // Initial catalogue = the four offers of the public site.
        $this->addSql(<<<'SQL'
            INSERT INTO product (name, description, priceFrom, commissionRate, active, featured, position) VALUES
            ('Gestion d''organisme de formation', 'Inscriptions, émargements, convocations, documents Qualiopi : la plateforme tout-en-un qui remplace Excel et les outils à 300 €/mois.', 3000, 15, 1, 1, 1),
            ('Plateforme e-learning (LMS)', 'Vendez et diffusez vos formations en ligne sur votre propre plateforme, à votre marque, sans commission ni dépendance.', 2500, 15, 1, 0, 2),
            ('Prospection + emailing', 'Trouvez vos prospects et automatisez vos campagnes : votre machine commerciale interne, simple et efficace.', 2000, 15, 1, 0, 3),
            ('Prise de commande en ligne', 'Vos clients commandent en ligne, vous encaissez 100 %. Zéro commission par transaction, contrairement aux grandes plateformes.', 2000, 15, 1, 0, 4)
        SQL);
        $this->addSql("UPDATE `lead` l JOIN product p ON p.position = 1 SET l.product_id = p.id, l.solution = p.name WHERE l.solution = 'Gestion OF'");
        $this->addSql("UPDATE `lead` l JOIN product p ON p.position = 3 SET l.product_id = p.id, l.solution = p.name WHERE l.solution = 'Prospection'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE application DROP FOREIGN KEY FK_A45BDDC14584665A');
        $this->addSql('ALTER TABLE `lead` DROP FOREIGN KEY FK_289161CB4584665A');
        $this->addSql('DROP TABLE apporteur_request');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP INDEX IDX_A45BDDC14584665A ON application');
        $this->addSql('ALTER TABLE application DROP product_id');
        $this->addSql('DROP INDEX IDX_289161CB4584665A ON `lead`');
        $this->addSql('ALTER TABLE `lead` DROP product_id, DROP contactName, DROP email, DROP phone, DROP notes, DROP source, DROP dealAmount, DROP signedAt, CHANGE apporteur_id apporteur_id INT NOT NULL');
    }
}
