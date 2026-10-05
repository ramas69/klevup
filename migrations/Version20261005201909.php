<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261005201909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lead qualification + consent + lost reason, sales kit, billing identity, referral, commission due date';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE apporteur_request ADD referrer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE apporteur_request ADD CONSTRAINT FK_67062A0798C22DB FOREIGN KEY (referrer_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_67062A0798C22DB ON apporteur_request (referrer_id)');
        $this->addSql('ALTER TABLE commission ADD dueAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD referralOf_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commission ADD CONSTRAINT FK_1C650158D5BB89B1 FOREIGN KEY (referralOf_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1C650158D5BB89B1 ON commission (referralOf_id)');
        $this->addSql('ALTER TABLE invitation ADD referrer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2798C22DB FOREIGN KEY (referrer_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F11D61A2798C22DB ON invitation (referrer_id)');
        $this->addSql('ALTER TABLE `lead` ADD contactRole VARCHAR(100) DEFAULT NULL, ADD timeline VARCHAR(20) DEFAULT NULL, ADD budget VARCHAR(20) DEFAULT NULL, ADD relationship VARCHAR(20) DEFAULT NULL, ADD consentAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD lostReason VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD target VARCHAR(255) DEFAULT NULL, ADD pitch LONGTEXT DEFAULT NULL, ADD resourceUrl VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD siret VARCHAR(14) DEFAULT NULL, ADD billingAddress VARCHAR(500) DEFAULT NULL, ADD referralCode VARCHAR(20) DEFAULT NULL, ADD referredBy_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D6493D4DACB7 FOREIGN KEY (referredBy_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649A4A10244 ON user (referralCode)');
        $this->addSql('CREATE INDEX IDX_8D93D6493D4DACB7 ON user (referredBy_id)');

        // Existing unpaid commissions get the standard 30-day payment date.
        $this->addSql("UPDATE commission SET dueAt = DATE_ADD(createdAt, INTERVAL 30 DAY) WHERE status != 'encashed'");

        // Starter sales kit — to be refined in Admin → Catalogue.
        $this->addSql(<<<'SQL'
            UPDATE product SET
              target = 'Organismes de formation et formateurs indépendants (1 à 50 formateurs), surtout ceux qui préparent ou renouvellent Qualiopi.',
              pitch = 'Le déclic : « Combien de temps passez-vous chaque mois sur les convocations, émargements et bilans Qualiopi ? »\n- Tout est centralisé : inscriptions, convocations, émargements, attestations.\n- Les preuves Qualiopi sont générées au fil de l''eau, pas la veille de l''audit.\n- Payé une fois (dès 3 000 €) au lieu de 150 à 300 €/mois d''abonnement : rentabilisé en moins de 2 ans.'
            WHERE position = 1
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE product SET
              target = 'Formateurs, coachs et experts qui veulent vendre leurs formations en ligne à leur nom.',
              pitch = 'Le déclic : « Combien vous coûtent les commissions de votre plateforme de cours aujourd''hui ? »\n- Plateforme à leur marque, sur leur domaine.\n- Zéro commission sur les ventes : 100 % du chiffre d''affaires pour eux.\n- Ils restent propriétaires de leurs contenus et de leurs données élèves.'
            WHERE position = 2
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE product SET
              target = 'TPE et indépendants B2B qui prospectent à la main (fichiers Excel, emails un par un).',
              pitch = 'Le déclic : « Combien de nouveaux clients vous apporte votre prospection chaque mois ? »\n- Base de prospects + campagnes email automatisées dans un seul outil.\n- Relances automatiques : plus aucun prospect oublié.\n- Payé une fois, sans abonnement par utilisateur.'
            WHERE position = 3
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE product SET
              target = 'Restaurants, traiteurs, producteurs et commerces qui prennent des commandes par téléphone ou via des plateformes à commission.',
              pitch = 'Le déclic : « Quel pourcentage de chaque commande laissez-vous aux plateformes ? »\n- Commande en ligne à leur nom, sans commission par transaction.\n- Paiement en ligne et suivi des commandes simple.\n- Rentabilisé en quelques mois face à 15 à 30 % de commission.'
            WHERE position = 4
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product DROP target, DROP pitch, DROP resourceUrl');
        $this->addSql('ALTER TABLE `lead` DROP contactRole, DROP timeline, DROP budget, DROP relationship, DROP consentAt, DROP lostReason');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D6493D4DACB7');
        $this->addSql('DROP INDEX UNIQ_8D93D649A4A10244 ON user');
        $this->addSql('DROP INDEX IDX_8D93D6493D4DACB7 ON user');
        $this->addSql('ALTER TABLE user DROP siret, DROP billingAddress, DROP referralCode, DROP referredBy_id');
        $this->addSql('ALTER TABLE apporteur_request DROP FOREIGN KEY FK_67062A0798C22DB');
        $this->addSql('DROP INDEX IDX_67062A0798C22DB ON apporteur_request');
        $this->addSql('ALTER TABLE apporteur_request DROP referrer_id');
        $this->addSql('ALTER TABLE invitation DROP FOREIGN KEY FK_F11D61A2798C22DB');
        $this->addSql('DROP INDEX IDX_F11D61A2798C22DB ON invitation');
        $this->addSql('ALTER TABLE invitation DROP referrer_id');
        $this->addSql('ALTER TABLE commission DROP FOREIGN KEY FK_1C650158D5BB89B1');
        $this->addSql('DROP INDEX UNIQ_1C650158D5BB89B1 ON commission');
        $this->addSql('ALTER TABLE commission DROP dueAt, DROP referralOf_id');
    }
}
