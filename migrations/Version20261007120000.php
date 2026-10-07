<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalogue: add "Automatisation & IA" and "Application sur mesure" (entry ticket 1 500 €)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO product (name, description, target, pitch, priceFrom, monthlyFrom, commissionRate, active, featured, position)
            SELECT 'Automatisation & agents IA',
              'Agents IA, chatbots et automatisations qui font le travail répétitif à votre place : relances, réponses clients, saisie, devis — connectés à vos outils.',
              'TPE, organismes de formation, cabinets et commerces qui passent des heures sur des tâches répétitives : relances, saisie, réponses clients, devis.',
              'Le déclic : « Quelle tâche répétitive vous prend le plus de temps chaque semaine ? »\n- Agents IA et automatisations sur mesure : relances, tri des emails, réponses clients, saisie, devis.\n- Connectés à leurs outils actuels : email, agenda, CRM, Google, facturation.\n- En place en quelques jours, maintenus et améliorés chaque mois dans l''abonnement.',
              1500, 0, 15, 1, 0, 5
            FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM product WHERE name = 'Automatisation & agents IA')
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO product (name, description, target, pitch, priceFrom, monthlyFrom, commissionRate, active, featured, position)
            SELECT 'Application sur mesure',
              'Un besoin métier qui ne rentre dans aucune case ? Nous concevons votre application à votre marque, sur devis ferme sous 48 h.',
              'Toute entreprise avec un processus spécifique géré sur Excel, sur papier ou avec plusieurs outils qui ne se parlent pas.',
              'Le déclic : « Quel outil vous manque pour gagner du temps chaque jour ? »\n- Une application conçue pour leur façon de travailler, pas l''inverse.\n- Devis ferme sous 48 h après un échange de 30 minutes.\n- Hébergement, maintenance et évolutions inclus dans l''abonnement.',
              1500, 0, 15, 1, 0, 6
            FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM product WHERE name = 'Application sur mesure')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM product WHERE name IN ('Automatisation & agents IA', 'Application sur mesure')");
    }
}
