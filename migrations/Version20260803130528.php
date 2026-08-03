<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260803130528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commission ADD lead_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commission
            ADD
              CONSTRAINT FK_1C65015855458D FOREIGN KEY (lead_id) REFERENCES `lead` (id)
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1C65015855458D ON commission (lead_id)');
        $this->addSql('ALTER TABLE ticket ADD adminNote LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket DROP adminNote');
        $this->addSql('ALTER TABLE commission DROP FOREIGN KEY FK_1C65015855458D');
        $this->addSql('DROP INDEX UNIQ_1C65015855458D ON commission');
        $this->addSql('ALTER TABLE commission DROP lead_id');
    }
}
