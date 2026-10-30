# Centrale élèves

Serveur unique pour toutes les applications de technologie :

- **Connexion** des élèves par un code personnel du type `5A-dfgdfkj-orei-flgdf`.
- **Sauvegarde et chargement automatiques** du travail de chaque élève, par atelier : l'élève retrouve son travail sur n'importe quel ordinateur.
- **Pseudonymisation** : le serveur ne connaît que « 5A · élève 07 ». Les noms restent sur l'ordinateur de l'enseignant.
- **Espace enseignant** : codes, travail de chaque élève, historique, export ZIP, ressources par atelier, quotas.

```
Application (GitHub, Forge, alwaysdata…)
   │  1. code 5A-dfgdfkj-orei-flgdf  ──►  jeton de session
   │  2. Centrale.charger("projet")   ◄──  travail enregistré
   │  3. Centrale.sauver("projet", …) ──►  enregistré (toutes les 3 s au plus)
   ▼
Centrale (alwaysdata, en France)  ─ ─ ►  export ZIP / dépôt Nuage en secours
```

## Contenu

| Dossier | Rôle |
|---|---|
| `public/` | Racine du site web (seul dossier visible depuis internet) |
| `public/admin/` | Espace enseignant |
| `public/api/` | Points d'entrée appelés par les applications |
| `public/js/centrale-client.js` | Module à inclure dans chaque application |
| `public/apps/exemple/` | Application d'exemple : carnet de bord avec sauvegarde auto et fichiers |
| `lib/` | Code PHP commun (non accessible depuis internet) |
| `data/` | Base SQLite et fichiers des élèves, créés automatiquement (non accessibles, jamais envoyés sur GitHub) |

---

## 1. Installation sur alwaysdata

1. **Site** : dans l'administration alwaysdata, **Web → Sites**, modifiez le site :
   - Type : **PHP** (8.1 ou plus récent)
   - Répertoire racine : **`/www/centrale/public`** (il doit bien se terminer par `/public`)
2. **Code** : déploiement automatique depuis GitHub (partie 6), ou dans le terminal SSH :
   `cd ~/www && git clone https://github.com/VOTREPSEUDO/centrale.git centrale`
3. Ouvrez `https://VOTRECOMPTE.alwaysdata.net/admin/` et choisissez le mot de passe administrateur.

Vérification : `https://VOTRECOMPTE.alwaysdata.net/api/` affiche `{"ok":true,…}`.

**Espace disque** : comptez *nombre d'élèves × quota par élève* (réglable dans l'espace enseignant, 100 Mo par défaut). Avec l'offre alwaysdata « Small » (50 Go) et 300 élèves, un quota de 100 à 150 Mo convient. Le tableau de bord affiche l'espace réellement utilisé.

**Taille des fichiers envoyés** : le fichier `public/.user.ini` autorise jusqu'à 64 Mo par envoi. La limite réelle par fichier se règle dans l'espace enseignant (20 Mo par défaut).

---

## 2. Les codes élèves (sans noms sur le serveur)

Dans l'espace enseignant, classe par classe :

1. Collez la liste des élèves (« Léa D. », un par ligne) dans **Générer des codes**.
   Votre navigateur garde les noms et n'envoie au serveur **que leur nombre**. Le serveur attribue des numéros : 01, 02, 03…
2. **Imprimez les fiches** (nom + code, à découper) et **téléchargez le CSV** (Classe ; Numéro ; Nom ; Code).
   Les codes ne seront plus jamais affichés.
3. Rangez le CSV dans un **dossier privé de votre Nuage**. C'est votre fichier de correspondance.

Sur un autre ordinateur, ou après avoir vidé le navigateur : **Charger mon fichier de noms (CSV)** affiche à nouveau les noms à côté des numéros. Ils ne sont jamais envoyés au serveur.

Code perdu : **Nouveau code** sur la ligne de l'élève (l'ancien cesse de fonctionner).

Classes créées avec l'ancienne version (noms enregistrés en ligne) : bouton **Anonymiser la classe**. Les noms sont remplacés par des numéros, et la correspondance est téléchargée.

---

## 3. Brancher une application

```html
<div id="connexion"></div>
<div id="etat"></div>

<script src="https://VOTRECOMPTE.alwaysdata.net/js/centrale-client.js"></script>
<script>
  Centrale.init({
    api: "https://VOTRECOMPTE.alwaysdata.net/api/",
    app: "robot-mbot",                          // identifiant de l'atelier
    surDeconnexion: () => location.reload(),    // session expirée
  });
  Centrale.afficherEtat(document.getElementById("etat"));   // « ✓ Enregistré à 14:32 »

  Centrale.afficherConnexion(document.getElementById("connexion"), {
    onConnecte: async (infos) => {
      // infos.classe → "5A", infos.eleve → "07"
      let projet = await Centrale.charger("projet", { blocs: [] });   // reprend le travail
      // … à chaque modification :
      Centrale.sauver("projet", projet);
    }
  });
</script>
```

Puis ajoutez l'adresse du site de l'application dans **Réglages → Sites autorisés** (par exemple `https://monpseudo.github.io`).

### Fonctions

| Fonction | Rôle |
|---|---|
| `Centrale.init({ api, app, surEtat, surDeconnexion })` | À appeler en premier |
| `Centrale.afficherConnexion(element, { onConnecte })` | Formulaire « Mon code » prêt à l'emploi |
| `Centrale.afficherEtat(element)` | Indicateur « Enregistré / Hors ligne… » |
| `await Centrale.charger(cle, defaut)` | Charge une donnée (objet, tableau, texte…) |
| `Centrale.sauver(cle, valeur)` | Sauvegarde (regroupée : une fois toutes les 3 s au plus) |
| `await Centrale.supprimer(cle)` / `listeDonnees()` | Efface une donnée / liste les clés |
| `await Centrale.envoyerFichier(fichier, nom)` | Envoie un fichier (File, Blob ou texte) |
| `await Centrale.listeFichiers()` | Fichiers de l'élève pour cet atelier |
| `await Centrale.chargerFichier(nom)` | Récupère un fichier (Blob) |
| `await Centrale.supprimerFichier(nom)` | Supprime un fichier |
| `await Centrale.listeRessources()` / `chargerRessource(nom)` | Fichiers déposés par l'enseignant pour l'atelier |
| `Centrale.session()` | Infos de l'élève connecté (`classe`, `eleve`, `espace`) |
| `Centrale.deconnexion()` | Envoie ce qui reste à enregistrer, puis déconnecte |
| `Centrale.nomFichier("sb3")` / `telecharger(contenu, nom)` | Nom standard, enregistrement sur l'ordinateur |

### Ce qui est géré automatiquement

- **Coupure réseau** : le travail est gardé dans le navigateur, puis envoyé au retour du réseau, ou à la prochaine connexion de l'élève sur ce poste.
- **Deux ordinateurs** : si l'élève a avancé ailleurs entre-temps, une ancienne copie n'écrase jamais le travail plus récent. L'onglet concerné affiche « modifié sur un autre ordinateur, recharge la page ».
- **Historique** : une version est gardée au plus toutes les 10 minutes, les 10 dernières par donnée. L'enseignant peut la restaurer depuis la page **Travail** de l'élève.
- **Fermeture de l'onglet** : un dernier envoi est tenté ; sinon la copie locale est renvoyée à la prochaine connexion.
- **Poste partagé** : la connexion dure le temps de l'onglet, et le jeton expire après 12 h.

---

## 4. Espace enseignant

- **Tableau de bord** : classes, nombre d'élèves, espace utilisé, ressources des ateliers, réglages (sites autorisés, quotas).
- **Classe** : codes, élèves (numéro, nom affiché localement, dernière connexion, espace), **export ZIP** de tout le travail, **effacer le travail** (fin d'année : les codes restent valables), dépôts Nuage facultatifs.
- **Travail d'un élève** : données et fichiers par atelier, téléchargement, historique avec restauration.
- **Ressources des ateliers** : déposez des fichiers (modèles 3D, consignes, images) que les élèves d'un atelier peuvent charger.

---

## 5. Données et RGPD

Sur le serveur (en France, chez alwaysdata) :

- classe et **numéro** de l'élève (aucun nom) ;
- **empreinte** du code (SHA-256 ; le code n'est jamais stocké) et jetons de session (empreintes) ;
- date de dernière connexion ;
- travail sauvegardé par les applications et fichiers envoyés.

Les noms restent dans le navigateur de l'enseignant et dans son fichier CSV. Ce sont des données **pseudonymisées**, ce qui est un bon niveau de protection. Pensez tout de même à mentionner ce traitement au DPO de votre académie et à effacer le travail des classes en fin d'année.

**Sauvegardes** : alwaysdata sauvegarde automatiquement chaque jour. Exportez aussi le ZIP de chaque classe dans votre Nuage de temps en temps, ou au moins en fin de trimestre.

---

## 6. Déploiement automatique depuis GitHub

À chaque `push` sur `main`, GitHub copie le code sur alwaysdata (`.github/workflows/deploy.yml`). Le dossier `data/` n'est jamais touché.

1. `ssh-keygen -t ed25519 -f ~/centrale_deploiement -N ""`
2. `ssh-copy-id -i ~/centrale_deploiement.pub VOTRECOMPTE@ssh-VOTRECOMPTE.alwaysdata.net`
3. Sur GitHub, dans **Settings → Secrets and variables → Actions** :
   - `ALWAYSDATA_COMPTE` : le nom du compte ;
   - `ALWAYSDATA_SSH_KEY` : le contenu de `~/centrale_deploiement` (clé privée).

---

## 7. Référence de l'API

Toutes les requêtes, sauf `login.php`, demandent l'en-tête `X-Jeton` reçu à la connexion.

| Requête | Rôle |
|---|---|
| `POST login.php` `{code, app}` | → `{classe, eleve, jeton, expire, espace, depot}` |
| `POST deconnexion.php` | Invalide le jeton |
| `GET donnees.php?app=&cle=` | → `{existe, valeur, maj}` |
| `GET donnees.php?app=` | → `{cles: [{cle, taille, maj}]}` |
| `POST donnees.php` `{app, cle, valeur, base?}` | Enregistre (2 Mo max par clé). 409 si `base` est plus ancien que le serveur |
| `POST donnees.php` `{app, cle, supprimer: true}` | Efface |
| `GET fichiers.php?app=` / `?app=&nom=` | Liste / télécharge |
| `POST fichiers.php` (multipart `app`, `fichier`, `nom?`) | Envoie (remplace le fichier du même nom) |
| `POST fichiers.php` `{app, nom, supprimer: 1}` | Supprime |
| `GET ressources.php?app=` / `?app=&nom=` | Ressources de l'atelier |

Codes d'erreur : 400 (demande incomplète), 401 (non connecté ou session expirée), 403 (site non autorisé), 409 (conflit), 413 (trop gros), 429 (trop d'essais de codes), 507 (espace de l'élève plein).
