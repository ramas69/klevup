<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260803131612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE application (
              id INT AUTO_INCREMENT NOT NULL,
              client_id INT NOT NULL,
              name VARCHAR(255) NOT NULL,
              description VARCHAR(255) NOT NULL,
              version VARCHAR(50) NOT NULL,
              url VARCHAR(255) NOT NULL,
              launchedAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
              maintenanceUntil DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
              INDEX IDX_A45BDDC119EB6921 (client_id),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              application
            ADD
              CONSTRAINT FK_A45BDDC119EB6921 FOREIGN KEY (client_id) REFERENCES user (id)
        SQL);
        $this->addSql('ALTER TABLE invitation ADD expiresAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE application DROP FOREIGN KEY FK_A45BDDC119EB6921');
        $this->addSql('DROP TABLE application');
        $this->addSql('ALTER TABLE invitation DROP expiresAt');
    }
}
