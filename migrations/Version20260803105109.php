<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260803105109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE commission (
              id INT AUTO_INCREMENT NOT NULL,
              user_id INT NOT NULL,
              companyName VARCHAR(255) NOT NULL,
              solution VARCHAR(255) NOT NULL,
              amount INT NOT NULL,
              status VARCHAR(50) NOT NULL,
              createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              encashedAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
              INDEX IDX_1C650158A76ED395 (user_id),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE invitation (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(100) NOT NULL,
              role VARCHAR(50) NOT NULL,
              email VARCHAR(180) DEFAULT NULL,
              usedAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
              createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              UNIQUE INDEX UNIQ_F11D61A277153098 (code),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE `lead` (
              id INT AUTO_INCREMENT NOT NULL,
              apporteur_id INT NOT NULL,
              contact VARCHAR(255) NOT NULL,
              solution VARCHAR(255) NOT NULL,
              status VARCHAR(50) NOT NULL,
              commission INT NOT NULL,
              createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              INDEX IDX_289161CB84FC98A0 (apporteur_id),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket (
              id INT AUTO_INCREMENT NOT NULL,
              user_id INT NOT NULL,
              reference VARCHAR(50) NOT NULL,
              title VARCHAR(255) NOT NULL,
              description LONGTEXT NOT NULL,
              type VARCHAR(50) NOT NULL,
              priority VARCHAR(50) NOT NULL,
              status VARCHAR(50) NOT NULL,
              createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              resolvedAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
              UNIQUE INDEX UNIQ_97A0ADA3AEA34913 (reference),
              INDEX IDX_97A0ADA3A76ED395 (user_id),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE user (
              id INT AUTO_INCREMENT NOT NULL,
              email VARCHAR(180) NOT NULL,
              name VARCHAR(255) NOT NULL,
              password VARCHAR(255) NOT NULL,
              roles JSON NOT NULL,
              status VARCHAR(50) NOT NULL,
              invitationCode VARCHAR(100) DEFAULT NULL,
              createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              UNIQUE INDEX UNIQ_8D93D649E7927C74 (email),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
              id BIGINT AUTO_INCREMENT NOT NULL,
              body LONGTEXT NOT NULL,
              headers LONGTEXT NOT NULL,
              queue_name VARCHAR(190) NOT NULL,
              created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
              INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (
                queue_name, available_at, delivered_at,
                id
              ),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commission
            ADD
              CONSTRAINT FK_1C650158A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              `lead`
            ADD
              CONSTRAINT FK_289161CB84FC98A0 FOREIGN KEY (apporteur_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket
            ADD
              CONSTRAINT FK_97A0ADA3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commission DROP FOREIGN KEY FK_1C650158A76ED395');
        $this->addSql('ALTER TABLE `lead` DROP FOREIGN KEY FK_289161CB84FC98A0');
        $this->addSql('ALTER TABLE ticket DROP FOREIGN KEY FK_97A0ADA3A76ED395');
        $this->addSql('DROP TABLE commission');
        $this->addSql('DROP TABLE invitation');
        $this->addSql('DROP TABLE `lead`');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
