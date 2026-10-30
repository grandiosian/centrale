# Centrale élèves

Petit serveur qui centralise la connexion des élèves pour toutes vos applications de technologie.

- Chaque élève a un **code personnel** du type `5A-dfgdfkj-orei-flgdf`.
- Une application envoie ce code à la Centrale. Si le code est bon, la Centrale répond avec la **classe**, le **nom de l'élève** et l'**adresse du dossier de dépôt Nuage** de sa classe.
- Les fichiers des élèves sont déposés dans **Nuage** (apps.education.fr), jamais sur le serveur.

```
Application élève (GitHub, Forge, alwaysdata…)
        │  code 5A-dfgdfkj-orei-flgdf
        ▼
Centrale (alwaysdata)  ──►  { classe: "5A", eleve: "Léa D.", depot: { url: "https://nuage…/s/…" } }
        │
        ▼
L'élève dépose son fichier dans le dossier Nuage de sa classe
```

## Contenu

| Dossier | Rôle |
|---|---|
| `public/` | Racine du site web (seul dossier visible depuis internet) |
| `public/admin/` | Espace enseignant : classes, liens Nuage, codes élèves |
| `public/api/login.php` | Point d'entrée appelé par les applications |
| `public/js/centrale-client.js` | Module à inclure dans chaque application élève |
| `public/apps/exemple/` | Application d'exemple (compte rendu + dépôt) |
| `lib/` | Code PHP commun (non accessible depuis internet) |
| `data/` | Base SQLite, créée automatiquement (non accessible, jamais envoyée sur GitHub) |

---

## 1. Installation sur alwaysdata

### Envoyer les fichiers

**Méthode simple (FileZilla)** : connectez-vous en SFTP à `ssh-VOTRECOMPTE.alwaysdata.net` avec vos identifiants alwaysdata, puis copiez tout le dossier `centrale-eleves` dans `www/centrale`.

**Méthode automatique (GitHub)** : voir la partie 5 plus bas.

### Configurer le site

Dans l'administration alwaysdata : **Web → Sites**, modifiez le site existant (ou ajoutez-en un) :

- **Adresse** : `VOTRECOMPTE.alwaysdata.net`
- **Type** : PHP (version 8.1 ou plus récente)
- **Répertoire racine** : `/www/centrale/public`

⚠️ Le répertoire racine doit bien se terminer par `/public` : c'est ce qui rend `lib/` et `data/` invisibles depuis internet.

### Créer le mot de passe administrateur

Ouvrez `https://VOTRECOMPTE.alwaysdata.net/admin/`. À la première visite, la page vous demande de choisir un mot de passe (10 caractères minimum).

Pour vérifier que tout fonctionne, `https://VOTRECOMPTE.alwaysdata.net/api/` doit afficher `{"ok":true,…}`.

---

## 2. Préparer les dossiers de dépôt dans Nuage

Pour chaque classe :

1. Dans Nuage, créez un dossier (ex. `Techno 5A - Dépôts`).
2. **Partager → Lien de partage → ⋯ → « Dépôt de fichiers »**.
3. Ajoutez un **mot de passe** au lien et, si vous voulez, une date d'expiration.
4. Copiez le lien (`https://nuageXX.apps.education.fr/s/…`).

Les élèves déposent sans compte et sans voir les fichiers des autres.

---

## 3. Créer les classes et les codes

Dans l'espace enseignant :

1. **Créer la classe** (ex. `5A`). Le nom sert de préfixe aux codes.
2. **Lien de dépôt Nuage** : collez le lien et son mot de passe. Laissez `*` dans « Application » pour qu'il serve à toutes vos applications.
   Pour qu'une application ait son propre dossier, ajoutez une ligne avec son identifiant (ex. `robot-mbot`).
3. **Générer des codes** : collez la liste des élèves (« Léa D. », un par ligne) ou indiquez un nombre.
4. **Imprimez les fiches** (2 colonnes, à découper) ou téléchargez le CSV **tout de suite** : les codes ne sont plus jamais affichés ensuite.

Code perdu ? Bouton **Nouveau code** sur la ligne de l'élève (l'ancien cesse de fonctionner).
Élève parti ? **Désactiver** ou **Supprimer**.

---

## 4. Brancher une application

Ajoutez dans la page HTML de l'application :

```html
<div id="connexion"></div>

<script src="https://VOTRECOMPTE.alwaysdata.net/js/centrale-client.js"></script>
<script>
  Centrale.init({
    api: "https://VOTRECOMPTE.alwaysdata.net/api/login.php",
    app: "robot-mbot"            // identifiant libre de l'application
  });

  Centrale.afficherConnexion(document.getElementById("connexion"), {
    onConnecte: (infos) => {
      // infos.classe        → "5A"
      // infos.eleve         → "Léa D."
      // infos.depot.url     → lien de dépôt Nuage
      // infos.depot.mot_de_passe → mot de passe du lien (ou null)
    }
  });
</script>
```

Puis, dans **Réglages → Sites autorisés**, ajoutez l'adresse du site qui héberge l'application, par exemple :

```
https://monpseudo.github.io
https://monpseudo.forge.apps.education.fr
```

(Liste vide = tous les sites sont autorisés, pratique pour les essais.)

### Fonctions disponibles

| Fonction | Rôle |
|---|---|
| `Centrale.init({ api, app })` | À appeler en premier |
| `Centrale.afficherConnexion(element, { onConnecte })` | Formulaire « Mon code » prêt à l'emploi |
| `await Centrale.connexion(code)` | Connexion manuelle (si vous faites votre propre formulaire) |
| `Centrale.session()` | Infos de l'élève connecté, ou `null` |
| `Centrale.deconnexion()` | Déconnecte l'élève |
| `Centrale.nomFichier("sb3")` | Nom standard : `5A_Lea-D_robot-mbot_2026-10-30.sb3` |
| `Centrale.telecharger(contenu, nom)` | Fait enregistrer un fichier (texte ou Blob) |
| `Centrale.ouvrirDepot()` | Ouvre le dossier de dépôt Nuage dans un nouvel onglet |

La connexion dure le temps de l'onglet : sur un poste partagé, fermer l'onglet déconnecte l'élève.

### Pourquoi l'élève dépose-t-il lui-même le fichier ?

Nuage n'accepte pas l'envoi de fichiers depuis un autre site (sécurité du navigateur). Le fonctionnement le plus fiable est donc : l'application **enregistre** le fichier avec un nom standard, puis **ouvre la page de dépôt** où l'élève le glisse.

---

## 5. Déploiement automatique depuis GitHub

À chaque `push` sur `main`, GitHub copie le code sur alwaysdata (`.github/workflows/deploy.yml`). Le dossier `data/` n'est jamais écrasé.

1. Créez une paire de clés sur votre ordinateur :
   `ssh-keygen -t ed25519 -f centrale_deploiement -N ""`
2. Sur alwaysdata (SSH), ajoutez le contenu de `centrale_deploiement.pub` à la fin de `~/.ssh/authorized_keys`.
3. Sur GitHub, dans **Settings → Secrets and variables → Actions** :
   - Secret `ALWAYSDATA_COMPTE` : votre nom de compte alwaysdata
   - Secret `ALWAYSDATA_SSH_KEY` : le contenu de `centrale_deploiement` (clé **privée**)
   - Variable `DOSSIER_DISTANT` (facultative) : `www/centrale` par défaut

Le dépôt GitHub peut être **public** : il ne contient aucun mot de passe ni aucune donnée d'élève.

---

## 6. Données et RGPD

Ce qui est enregistré sur le serveur (en France, chez alwaysdata) :

- le nom affiché de l'élève (prénom + initiale conseillé) et sa classe ;
- **l'empreinte** de son code (SHA-256) : le code lui-même n'est jamais stocké ;
- la date de sa dernière connexion ;
- les liens de dépôt Nuage et leurs mots de passe.

Aucun fichier d'élève n'est stocké sur le serveur. Pensez tout de même à déclarer ce traitement auprès du DPO de votre académie et à supprimer les classes en fin d'année.

**Sauvegarde** : alwaysdata sauvegarde automatiquement chaque jour. Vous pouvez aussi récupérer `data/centrale.sqlite` par SFTP.

## 7. Référence de l'API

`POST /api/login.php` — JSON `{ "code": "...", "app": "..." }` (ou formulaire classique)

| Statut | Réponse |
|---|---|
| 200 | `{ "ok": true, "classe": "5A", "eleve": "Léa D.", "app": "robot-mbot", "depot": { "url": "...", "mot_de_passe": "..." } }` |
| 400 | Code manquant |
| 401 | Code inconnu ou désactivé |
| 403 | Site non autorisé (voir Réglages) |
| 429 | Trop de codes erronés depuis ce réseau (100 en 15 min) |

`GET /api/` — vérifie que la Centrale répond.
