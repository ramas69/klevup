# Déploiement Klevup — hébergement mutualisé (o2switch / OVH)

Guide pas-à-pas pour mettre Klevup en production sur un hébergement mutualisé cPanel avec accès SSH (o2switch) ou équivalent OVH.

## Prérequis côté hébergeur

- PHP **8.1 minimum** (sélectionnable dans cPanel → « Select PHP Version »). Extensions : `ctype`, `iconv`, `intl`, `pdo_mysql`.
- Une base **MySQL** créée via cPanel (nom, utilisateur, mot de passe — notez-les).
- Accès **SSH** activé (o2switch : oui par défaut ; OVH mutualisé : à activer dans l'espace client).
- Le **domaine** pointé vers un dossier dont la racine web sera `public/` (voir étape 4).

## 1. Envoyer le code

```bash
# En SSH sur l'hébergement :
cd ~
git clone https://github.com/ramas69/klevup.git
cd klevup
composer install --no-dev --optimize-autoloader
```

> Pas de `composer` sur le serveur ? `curl -sS https://getcomposer.org/installer | php` puis `php composer.phar install --no-dev --optimize-autoloader`.

## 2. Configurer l'environnement

Créer `.env.local` **sur le serveur** (jamais commité) :

```bash
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=CHANGEZ_MOI          # générez-en un : php -r "echo bin2hex(random_bytes(16));"
DATABASE_URL="mysql://UTILISATEUR:MOTDEPASSE@localhost:3306/NOM_BASE?serverVersion=8.0&charset=utf8mb4"
MAILER_DSN=smtp://UTILISATEUR:MOTDEPASSE@HOTE_SMTP:465   # SMTP o2switch/OVH ou Brevo
DEFAULT_URI=https://votre-domaine.fr                     # liens des emails envoyés par le cron
```

- **APP_SECRET** : obligatoirement une nouvelle valeur, jamais celle du dépôt.
- **MAILER_DSN** : sans SMTP réel, les emails (invitations, notifications) ne partent pas. o2switch fournit un SMTP par compte mail cPanel.

## 3. Base de données

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:create-admin admin@votredomaine.fr MOT_DE_PASSE_FORT "Votre Nom"
```

## 4. Racine web → `public/`

Le domaine doit servir **uniquement** le dossier `public/` :

- **o2switch** : cPanel → « Domaines » → modifier la racine du domaine vers `klevup/public`.
- **OVH** : espace client → Multisite → dossier racine `klevup/public`.

Le fichier `public/.htaccess` (inclus) gère la réécriture Apache — rien d'autre à faire.

⚠️ Ne pointez jamais le domaine vers la racine du projet : `.env.local`, `var/`, `src/` seraient exposés.

## 5. Cache production

```bash
APP_ENV=prod php bin/console cache:clear
APP_ENV=prod php bin/console cache:warmup
```

## 6. Vérifications post-déploiement

- [ ] `https://votredomaine.fr/` → landing OK
- [ ] `/login` → connexion admin OK, redirigé vers `/admin/users`
- [ ] Générer une invitation avec email → email reçu
- [ ] `https://votredomaine.fr/.env` → **doit renvoyer 404** (sinon la racine web est mal configurée)
- [ ] HTTPS actif (o2switch : Let's Encrypt auto ; OVH : SSL Gateway dans l'espace client)

## Mises à jour ultérieures

```bash
cd ~/klevup
git pull
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod php bin/console cache:clear
```

## Notes

- `var/` (cache, logs) doit être accessible en écriture par PHP — c'est le cas par défaut en mutualisé (même utilisateur).
- **Cron quotidien** — cPanel → « Tâches Cron », une fois par jour (ex. 8 h) :
  `cd ~/klevup && php bin/console app:maintenance-reminders --env=prod && php bin/console app:apporteur-reminders --env=prod`
  - relance les clients dont la maintenance se termine dans 30 et 7 jours ;
  - relance les apporteurs sans nouveau lead depuis 30 et 90 jours.
  Sans ce cron, aucune de ces relances ne part.
