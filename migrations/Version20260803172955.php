<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260803172955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket_message (
              id INT AUTO_INCREMENT NOT NULL,
              ticket_id INT NOT NULL,
              author_id INT NOT NULL,
              content LONGTEXT NOT NULL,
              createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              INDEX IDX_BA71692D700047D2 (ticket_id),
              INDEX IDX_BA71692DF675F31B (author_id),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket_message
            ADD
              CONSTRAINT FK_BA71692D700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket_message
            ADD
              CONSTRAINT FK_BA71692DF675F31B FOREIGN KEY (author_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              user
            ADD
              iban VARCHAR(34) DEFAULT NULL,
            ADD
              resetToken VARCHAR(100) DEFAULT NULL,
            ADD
              resetTokenExpiresAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_message DROP FOREIGN KEY FK_BA71692D700047D2');
        $this->addSql('ALTER TABLE ticket_message DROP FOREIGN KEY FK_BA71692DF675F31B');
        $this->addSql('DROP TABLE ticket_message');
        $this->addSql('ALTER TABLE user DROP iban, DROP resetToken, DROP resetTokenExpiresAt');
    }
}
