# Actualités en direct (WebSocket — Laravel Reverb)

Quand l'administration publie, modifie ou supprime une actualité (page « Actualités du site »
de l'application de gestion), le site vitrine se met à jour **sans rechargement** :
la carte apparaît, le badge du menu « Info » s'incrémente et une annonce s'affiche.

```
Gestion ──POST /api/infos──▶ Laravel ──événement──▶ Reverb (port 8080) ──WebSocket──▶ Site vitrine
```

- Événement : `App\Events\ActualiteMiseAJour`, canal public `actualites`, nom `actualite.maj`.
- Seuls les champs publics de l'actualité sont diffusés (les mêmes que `GET /api/infos`).
- Si Reverb est arrêté, la publication **réussit quand même** (avertissement dans les logs)
  et le site vitrine se met à jour toutes les 30 secondes et quand le visiteur revient sur l'onglet
  (la nouvelle actualité est alors annoncée de la même façon).

## En local

```bash
php artisan serve          # API
php artisan reverb:start   # serveur WebSocket (port 8080) — ajouter --debug pour voir les messages
```

Le `.env` du backend et le `.env.development` du site vitrine contiennent déjà les valeurs locales.

## En production (serveur Ubuntu)

**Prérequis : PHP 8.2 ou plus** (exigé par Reverb).

### 1. `.env` du backend

```dotenv
BROADCAST_DRIVER=reverb
REVERB_APP_ID=gstf
REVERB_APP_KEY=<générer : php -r "echo bin2hex(random_bytes(10));">
REVERB_APP_SECRET=<générer : php -r "echo bin2hex(random_bytes(20));">
# Laravel envoie les événements à Reverb sur la même machine
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
# Sites autorisés à se connecter
REVERB_ALLOWED_ORIGINS=gstf-faliarivo.ovh,*.gstf-faliarivo.ovh
```

Puis `php artisan config:cache`.

### 2. Reverb comme service permanent (supervisor)

`/etc/supervisor/conf.d/gstf-reverb.conf` :

```ini
[program:gstf-reverb]
command=php /chemin/vers/projet_gseminaire_backend/artisan reverb:start --host=127.0.0.1 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/gstf-reverb.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status gstf-reverb
```

Après chaque déploiement du backend : `sudo supervisorctl restart gstf-reverb`.

### 3. Nginx : WebSocket sécurisé (wss) sur le domaine de l'API

Dans le bloc `server` HTTPS de `api.gstf-faliarivo.ovh` :

```nginx
location ~ ^/(app|apps)/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 120s;
}
```

Le port 8080 reste fermé vers l'extérieur : seul Nginx (443) est exposé.

### 4. Site vitrine (`.env.production`)

```dotenv
REACT_APP_REVERB_KEY=<même valeur que REVERB_APP_KEY>   # clé publique, pas le secret
REACT_APP_REVERB_HOST=api.gstf-faliarivo.ovh
REACT_APP_REVERB_PORT=443
REACT_APP_REVERB_SCHEME=https
```

Puis `npm run build`. Sans clé, le site fonctionne mais sans direct (mise à jour toutes les 30 secondes).

### Vérifier

Ouvrir le site vitrine : la section Actualités affiche « **En direct** ». Publier une actualité
depuis la gestion : elle apparaît dans les secondes qui suivent.
