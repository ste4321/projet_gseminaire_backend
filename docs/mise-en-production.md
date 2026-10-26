# Mise en production — GSTF

Liste de contrôle pour installer ou mettre à jour l'API (ce projet), l'application de gestion
(`projet_gseminaire_gestion`) et le site vitrine (`projet_gseminaire_frontend`) sur le serveur.

> Ne jamais lancer `DemoSeeder` en production (il refuse d'ailleurs de s'exécuter si `APP_ENV=production`).
> Ne jamais mettre de mot de passe ou de clé dans un fichier versionné.

---

## A. Première installation

### 1. Prérequis serveur
- **PHP 8.2 ou plus** (exigé par Reverb), extensions `pdo_pgsql`, `mbstring`, `gd` ou `imagick`, `fileinfo`.
- PostgreSQL, Composer, Nginx, Supervisor.

### 2. Code et dépendances
```bash
cd /chemin/vers/projet_gseminaire_backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

### 3. `.env` du backend (valeurs de production)
```dotenv
APP_ENV=production
APP_DEBUG=false                     # jamais true en ligne : afficherait les erreurs internes
APP_URL=https://api.gstf-faliarivo.ovh

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

# Sites autorisés à appeler l'API (gestion + vitrine), séparés par des virgules
CORS_ALLOWED_ORIGINS=https://www.gstf-faliarivo.ovh,https://gstf-faliarivo.ovh,https://<domaine-de-la-gestion>

# Durée de validité d'une connexion, en minutes (24 h)
SANCTUM_TOKEN_EXPIRATION=1440

# Actualités en direct : voir docs/temps-reel.md
BROADCAST_DRIVER=reverb
REVERB_APP_ID=gstf
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGINS=gstf-faliarivo.ovh,*.gstf-faliarivo.ovh
```

### 4. Base de données
```bash
php artisan migrate --force
```

### 5. ⚠️ Lien de stockage des fichiers (indispensable)
```bash
php artisan storage:link
```
Crée le lien `public/storage → storage/app/public`. C'est par lui que sont servies
**les images des emplois du temps et les pièces jointes des annonces**.
Sans lui, l'envoi d'un fichier réussit mais l'image répond **404** (image cassée).
Le lien n'est pas dans Git : il faut le recréer après chaque nouveau clone du projet.
Détails : `docs/stockage-fichiers.md`.

### 6. Droits d'écriture
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 7. Mise en cache de la configuration
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 8. Reverb (annonces et actualités en direct) et Nginx
Service Supervisor `gstf-reverb` et bloc Nginx `wss` : voir **`docs/temps-reel.md`**.
Reverb doit tourner en permanence : sans lui, les annonces n'arrivent plus en direct
(l'application se rabat sur une actualisation toutes les 30 s).

### 8 bis. Performances : OPcache et cache des fichiers publics
**OPcache** (le code PHP compilé est gardé en mémoire au lieu d'être relu à chaque requête) :
mesuré sur ce projet, chaque requête de l'API passe d'environ 300 ms à 100 ms.
Dans le `php.ini` de PHP-FPM (`/etc/php/8.2/fpm/php.ini`) :
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0   ; code relu seulement au redémarrage de PHP-FPM
```
Avec `validate_timestamps=0`, ajouter `sudo systemctl reload php8.2-fpm` à chaque déploiement.

**Images et pièces jointes** (`/storage`) : chaque fichier envoyé reçoit un nom unique (un fichier
remplacé change de nom), on peut donc laisser le navigateur les garder longtemps. Dans le bloc
`server` Nginx de l'API :
```nginx
location /storage/ {
    expires 30d;
    add_header Cache-Control "public, immutable";
    try_files $uri =404;
}
```
L'emploi du temps et les pièces jointes ne sont alors plus retéléchargés à chaque visite.

### 9. Applications React
- **Gestion** : `.env.production` contient déjà `REACT_APP_API_URL=https://api.gstf-faliarivo.ovh/api`.
- **Site vitrine** : renseigner `REACT_APP_REVERB_KEY` dans `.env.production` avec la valeur
  `REVERB_APP_KEY` du `.env` du serveur (vide = pas de direct, mise à jour toutes les 30 s).

```bash
npm ci
npm run build      # puis publier le dossier build/ sur le serveur web
```
Toujours reconstruire juste avant de publier : un `build/` fait en local pour des tests
peut pointer vers une autre API.

### 10. Premier compte administrateur et vérifications
- Se connecter avec le compte admin, vérifier le tableau de bord.
- Ajouter une image d'emploi du temps et vérifier qu'elle **s'affiche** (contrôle du point 5).
- Publier une actualité et vérifier qu'elle apparaît sur le site vitrine.

---

## B. À chaque mise à jour

```bash
cd /chemin/vers/projet_gseminaire_backend
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link            # sans effet s'il existe déjà ; à garder par sécurité
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php8.2-fpm    # OPcache : prise en compte du nouveau code
sudo supervisorctl restart gstf-reverb
php artisan up
```
Puis reconstruire et publier les applications React concernées (point A.9).

---

## C. Migrations de la refonte : effets à connaître

| Migration | Effet | Action après la migration |
|---|---|---|
| `2026_10_17_000001_securise_mots_de_passe` | Supprime la table `passwords` (mots de passe en clair). Les comptes non admin devront changer leur mot de passe à la prochaine connexion | Prévenir les utilisateurs ; en cas d'oubli, « Réinitialiser » depuis « Comptes et accès » |
| `2026_10_17_000002_annonces_tous_niveaux` | Une annonce peut viser « Tous les niveaux » | Aucune |
| `2026_10_17_000003_publication_notes` | Les étudiants ne voient plus que les notes des **semestres publiés** | Page **Notes** → « Publier les notes » pour chaque semestre déjà validé |

---

## D. Sauvegardes

À sauvegarder **ensemble**, régulièrement :
- la base PostgreSQL (`pg_dump`) ;
- le dossier `storage/app/public` (images et pièces jointes : elles ne sont pas dans la base) ;
- le fichier `.env` (dans un endroit sûr, hors du serveur web).
