# Fichiers envoyés (emplois du temps, pièces jointes des annonces)

Les fichiers sont enregistrés dans `storage/app/public/…` (disque Laravel `public`)
et la base ne garde que leur chemin public, par exemple `/storage/edt/abc.jpg`.

Ils sont servis par le lien `public/storage → storage/app/public`.
**Ce lien doit être créé une fois sur chaque installation** (poste de développement, serveur) :

```bash
php artisan storage:link
```

Sans lui, l'envoi réussit mais l'image répond **404** : l'application affiche alors
« L'image de cet emploi du temps est introuvable ».

Points de vigilance en production :
- le dossier `storage/app/public` doit être accessible en écriture par PHP ;
- il doit être **sauvegardé** avec la base de données (les fichiers n'y sont pas) ;
- le lien `public/storage` n'est pas versionné (`.gitignore`) : à recréer après un nouveau clone.
