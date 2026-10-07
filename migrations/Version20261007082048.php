<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007082048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurring commissions (period per lead) + application linked to its originating sale';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE application ADD lead_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE application ADD CONSTRAINT FK_A45BDDC155458D FOREIGN KEY (lead_id) REFERENCES `lead` (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A45BDDC155458D ON application (lead_id)');
        $this->addSql('ALTER TABLE commission DROP INDEX UNIQ_1C65015855458D, ADD INDEX IDX_1C65015855458D (lead_id)');
        $this->addSql('ALTER TABLE commission ADD period INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_commission_lead_period ON commission (lead_id, period)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE application DROP FOREIGN KEY FK_A45BDDC155458D');
        $this->addSql('DROP INDEX UNIQ_A45BDDC155458D ON application');
        $this->addSql('ALTER TABLE application DROP lead_id');
        $this->addSql('ALTER TABLE commission DROP INDEX IDX_1C65015855458D, ADD UNIQUE INDEX UNIQ_1C65015855458D (lead_id)');
        $this->addSql('DROP INDEX uniq_commission_lead_period ON commission');
        $this->addSql('ALTER TABLE commission DROP period');
    }
}
