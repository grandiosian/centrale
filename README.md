# Centrale élèves

Serveur unique pour toutes les applications de technologie :

- **Connexion** des élèves par un code personnel du type `5A-dfgdfkj-orei-flgdf`.
- **Sauvegarde et chargement automatiques** du travail de chaque élève, par atelier : l'élève retrouve son travail sur n'importe quel ordinateur.
- **Pseudonymisation** : le serveur ne connaît que « 5A · élève 07 ». Les noms restent sur l'ordinateur de l'enseignant.
- **Progression et suivi** : chaque application signale l'avancement de l'élève ; tableau élèves × activités pour toute la classe.
- **Parcours** : enchaînement d'étapes dans plusieurs logiciels (simulation → modélisation → programmation → questionnaire).
- **Portail élève** (page d'accueil) : l'élève tape son code une fois, voit ses **ateliers** (En cours, Révisions, À venir, Déjà fait ; plus de parcours côté élève : un atelier peut enchaîner plusieurs applications), ouvre un atelier (ou directement une activité) déjà connecté, puis revient au portail avec « ← Mes ateliers ». Sa barre du haut est celle des applications élèves (référence NetSim) : logo à gauche (le nom « TechnoVersailles » s'efface quand la barre manque de place), **widget de l'élève** (son identifiant ; un clic ouvre son menu : identifiant et « Changer mon surnom »), au centre l'**atelier en cours** (activités faites / total) avec « Continuer → » qui le reprend à la prochaine activité, à droite « Se déconnecter ». Dessous, ses ateliers rangés : **Mon atelier en cours** en grand (la dernière activité ouverte depuis le portail, sinon l'atelier le plus avancé, avec « Tu en es là », la prochaine activité, jusqu'à quand la compléter (« À compléter jusqu'au … », sinon « Atelier permanent » / « Parcours permanent ») et, pour un parcours, les icônes de ses applications), puis **À faire** (commencés d'abord), **Prochainement** (en gris, compte à rebours) et **Déjà fait** (replié). Les activités proposées par le professeur sont dans « Demandé par ton professeur ». Pied de page : la marque à gauche, la mention RGPD au centre, la version à droite.
- **Espace enseignant** : codes, suivi, parcours, travail de chaque élève, historique, export ZIP, ressources par atelier, quotas.

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
| `public/index.php` | Portail élève : code, parcours, ouverture des logiciels |
| `public/apps/exemple/` | Application d'exemple : carnet de bord avec sauvegarde auto et fichiers |
| `lib/` | Code PHP commun (non accessible depuis internet) |
| `ateliers/<app>/` | Ateliers transférés par la Centrale : un fichier `<id>.json` par atelier, au format de l'application, versionné et déployé (servi par `api/atelier-fichier.php`) |
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

**Espace disque** : comptez *nombre d'élèves × quota par élève* (réglable dans l'espace enseignant, 100 Mo par défaut). Avec l'offre alwaysdata « Small » (50 Go) et 300 élèves, un quota de 100 à 150 Mo convient. La page Classes affiche l'espace réellement utilisé.

**Taille des fichiers envoyés** : le fichier `public/.user.ini` autorise jusqu'à 64 Mo par envoi. La limite réelle par fichier se règle dans l'espace enseignant (20 Mo par défaut).

---

## 2. Les codes élèves (sans noms sur le serveur)

Dans l'espace enseignant, classe par classe :

1. Collez la liste des élèves (« Léa D. », un par ligne) dans **Générer des codes**. Pour un élève seul, utilisez **Ajouter un élève** : nom, prénom, besoins (PPS, PAP, PAI, PPRE) et sortie éventuelle. Le code s'affiche ensuite.
   Votre navigateur garde les noms et n'envoie au serveur **que leur nombre**. Le serveur attribue des numéros : 01, 02, 03…
2. **Imprimez les fiches** (nom + code, à découper) et **téléchargez la sauvegarde** (`centrale_AAAA-MM-JJ.desanonim` : noms, codes, besoins, cartons, trombinoscopes de cet ordinateur).
   Les codes ne seront plus jamais affichés.
3. Rangez la sauvegarde dans un **dossier privé de votre Nuage**. C'est votre fichier de correspondance ; « Tout charger (.desanonim) » la relit sur un autre ordinateur. La page de la classe propose aussi les codes en CSV (« Télécharger les codes »).

Sur un autre ordinateur, ou après avoir vidé le navigateur : **Charger mon fichier de noms (CSV)** affiche à nouveau les noms à côté des numéros. Ils ne sont jamais envoyés au serveur.

**Trombinoscope** (page de la classe) : une image (feuille) par établissement, gardée sur l'ordinateur avec les noms et stockée dans IndexedDB (les images sont trop lourdes pour le stockage texte). Dans l'image, les classes se suivent de haut en bas, dans l'ordre saisi (« Ordre des classes ») ; chaque classe occupe 3 rangées de 10 photos, rangées par ordre alphabétique des noms du désanonymiseur (30 élèves au plus). Les valeurs sont en pixels de l'image d'origine, et les réglages par défaut sont ceux de la version 156 × 200 px. Le visage est centré sur le plan de classe et dans les bulles. Rien n'est envoyé au serveur ; **Oublier sur cet ordinateur** efface les images et les réglages.

Élève sorti de l'établissement : cliquez sur l'icône 🚪 (première colonne de la liste de la classe). La date du jour est gardée automatiquement, mais rien n'est à saisir. Il reste visible, grisé, et ne se place plus ; sa case du trombinoscope est conservée. La date est dans la première colonne du CSV global, « Sortie définitive », relue au chargement ; un cadre vide remet l'élève en activité.

Code perdu : **Nouveau code** sur la ligne de l'élève (l'ancien cesse de fonctionner).

Remettre un élève à zéro (page de l'élève, en bas : **Remettre à zéro**) : son avancement (tableau de suivi, ateliers, parcours), ses données et leur historique, ses fichiers, son dossier de projet individuel, ses connexions et les constats des applications sont effacés ; son code reste valable. Une case efface aussi vos évaluations. Utile après un essai « Tester à la place d'un élève ». La date de la remise à zéro est notée (`eleves.remis_le`, aussi pour « Effacer le travail de la classe ») : ce que le navigateur avait gardé d'avant (copie de secours d'une donnée, avancements en file) n'est plus accepté. Le module envoie l'âge de chaque copie (`age`, en ms, sans se fier à l'heure du poste) ; `charger()` oublie une copie plus ancienne, `donnees.php` la refuse (410 `remis`) et `progression.php` l'ignore. Pour toute une classe : **Effacer le travail de la classe**.

Code et identifiant : le **code** (« 5F-LHA-2345-abcd-efgh », sur la fiche) sert à se connecter ; l'**identifiant** (« 5F-LHA-BaleineLucide », affiché sur le portail) n'est qu'un nom visible. Si un élève tape son identifiant, la connexion le lui dit ; le code d'un élève désactivé est signalé comme tel. Un ancien code remplacé par **Nouveau code** est simplement inconnu (`api/login.php`).

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

### Passerelle : le descripteur `centrale.json`

Chaque application publie à la racine de son site un fichier `centrale.json` au format **`passerelle.atelier` v1**. Il décrit ce qu'elle déclare, envoie et enregistre :

- l'atelier : identifiant, nom, adresse, version ;
- ses activités ;
- les champs de progression ;
- les clés de données ;
- les fichiers qu'elle reçoit ou produit ;
- les sites à autoriser.

La spécification, le schéma JSON et un modèle vierge sont publiés par la Centrale à l'adresse **`/passerelle/`** (dossier `public/passerelle/`).

**Créer un atelier** : en haut de la page **Ateliers**, **＋ Ajouter un atelier** ouvre FicheStudio (dans un nouvel onglet) sur un nouveau document « Nouvel atelier », avec les modèles de la charte déjà dépliés. Un atelier se crée d'abord **sur papier** (FicheStudio, ou Affinity puis ouvert dans FicheStudio), se **convertit ensuite pour TechnoStudio** (atelier numérique, activités, compétences), puis s'**importe ici** (« Importer les ateliers… ») : ces trois étapes sont rappelées sous le titre. Ajouter une application au portail se fait dans la page **Applications** (« Brancher une application »).

Dans la page **Applications** (barre du haut), la carte « Brancher une application par sa passerelle » prend l'adresse de l'application. La Centrale lit son `centrale.json` côté serveur, puis :

- elle crée la fiche de l'atelier, ou complète ses champs vides sans écraser vos réglages ;
- elle garde une copie du descripteur, mise à jour par le bouton « Relire » ;
- elle ajoute les activités au catalogue : les colonnes du suivi et les identifiants des parcours sont connus avant la première connexion d'un élève ;
- elle ouvre la **fiche de la passerelle**, avec les activités par groupe, la progression, les données, les fichiers, les remarques sur le descripteur et le JSON complet ;
- elle signale chaque site à autoriser qui manque, avec un bouton « Autoriser ».

Dans **Parcours**, l'enchaînement des étapes est vérifié avec les descripteurs. Ces remarques ne sont jamais bloquantes. La Centrale signale :

- un fichier qu'un atelier ne déclare pas recevoir ou produire ;
- un fichier reçu qu'aucune étape précédente ne produit et qui ne fait pas partie des fichiers fournis ;
- un atelier qui n'utilise pas encore le dossier de projet.

| Application | Passerelle |
|---|---|
| NetSim | `centrale.json` complet : 18 activités, schéma et découvertes, pas de fichiers |
| TechnoStudio | `centrale.json` complet : 28 activités et activités dynamiques, ressources `*.technostudio.json` / `*.gift`, pas encore de dossier de projet |
| Établi 3D | `centrale.json` complet : 39 activités (tutos, exercices, exemples), projet, **dossier de projet** : reçoit un projet `.json`, produit projet `.json`, `resume.json`, `.stl`, `.png` (noms au choix du professeur, reconnus par leur extension) ; dépose ses sorties tout seul avant « Étape suivante » |

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
| `Centrale.progression(activite, infos)` | Signale l'avancement (voir partie 4) |
| `await Centrale.mesProgressions()` | Avancement de l'élève dans cet atelier (pour cocher les activités faites) |
| `Centrale.init({ activites: [...] })` | Liste des activités de l'atelier (colonnes du suivi) |
| `await Centrale.parcours()` | Parcours de la classe avec l'avancement de l'élève |
| `Centrale.lien(url, { activite })` / `Centrale.ouvrir(url, { activite })` | Ouvre une autre application (et une activité) sans retaper le code |
| `Centrale.activiteDemandee()` | Activité demandée par le portail, à ouvrir directement |
| `Centrale.retour()` | Revient au portail (bouton « ← Mes ateliers » ajouté automatiquement) |
| `await Centrale.etapeSuivante()` | Dans un parcours : `{ numero, etape, fin, raison }` de l'étape suivante (`raison` si elle est verrouillée), `null` hors parcours |
| `await Centrale.allerEtapeSuivante()` | Enregistre tout et ouvre l'étape suivante (bouton « Étape suivante → » ajouté automatiquement) ; `false` si elle est verrouillée |
| `Centrale.navigation(rappel, { activite })` | Navigation intégrée à l'application (pied du panneau consigne) : le rappel reçoit `{ contexte: parcours \| atelier \| portail, position, precedent, suivant }` (chaque bouton a `libelle`, `verrou`, `raison`, `aller()`), selon la façon dont l'application a été ouverte ; les boutons flottants du module disparaissent. Détail : spécification de la passerelle, § 2.6 |
| `Centrale.boutonPrecedent({ classe })` | Bouton « ← » du widget de l'élève (barre du haut) : « ← Étape précédente » dans un parcours (rouvre l'étape d'avant, dans son application), « ← Mes ateliers » sinon, caché hors Centrale ; renvoie `{ bouton, maj }`. Aussi `await Centrale.precedent()` et `await Centrale.allerPrecedent()` |
| `Centrale.menuEleve(ancre, { liens, deconnexion, onDeconnexion, onChange })` | Menu de l'élève connecté, ouvert sous son identifiant (barre du haut), le même dans toutes les applications : « Connecté », classe et numéro, identifiant et « Changer mon surnom », aide, liens propres à l'application (`[{ texte, action }]`) puis « Se déconnecter ». Un 2e appel le ferme (aussi : clic dehors, Échap). Renvoie `false` sans connexion |
| `await Centrale.ateliers()` | Ateliers proposés à la classe, avec l'avancement |
| `await Centrale.propositions()` | Activités proposées par le professeur à l'élève (classe ou groupe) : date, consigne, avancement |

### Ce qui est géré automatiquement

- **Coupure réseau** : le travail est gardé dans le navigateur, puis envoyé au retour du réseau, ou à la prochaine connexion de l'élève sur ce poste.
- **Deux ordinateurs** : si l'élève a avancé ailleurs entre-temps, une ancienne copie n'écrase jamais le travail plus récent. L'onglet concerné affiche « modifié sur un autre ordinateur, recharge la page ».
- **Historique** : une version est gardée au plus toutes les 10 minutes, les 10 dernières par donnée. L'enseignant peut la restaurer depuis la page **Travail** de l'élève.
- **Fermeture de l'onglet** : un dernier envoi est tenté ; sinon la copie locale est renvoyée à la prochaine connexion.
- **Poste partagé** : la connexion dure le temps de l'onglet, et le jeton expire après 12 h.

---

## 4. Ateliers, progression, parcours et suivi

### Organisation recommandée

- **Les activités vivent dans leur application.** NetSim garde ses exercices et leur validation automatique, et le modeleur 3D garde ses défis. Ce sont les applications qui savent vérifier ce que fait l'élève.
- **La Centrale est le hub.** Elle gère :
  - la connexion ;
  - la liste des ateliers ;
  - la sauvegarde ;
  - la progression, les parcours et le suivi.

  Chaque application lui *déclare* ses activités, et la Centrale peut alors les afficher, les ouvrir directement et les suivre.
- Ce qui est de la donnée pure (questionnaire, TP noté, consignes) peut être un petit module hébergé sur la Centrale.

### Hébergements multiples et copie de secours

- Chaque atelier a **cinq emplacements**, réglables dans la page Applications (bouton « Modifier ») :
  - **Local** : la copie servie par cette Centrale, `/miroir/<nom>/` (rempli par défaut), ou un dossier local (`http://localhost:8080/…`) ;
  - **Nom de domaine de la Centrale** (ex. `https://technoversailles.fr/miroir/netsim/`) : laissé vide, il se remplit tout seul avec la copie `/miroir/<nom>/` au nom de domaine réglé dans « Ordre des emplacements » (`domaine_centrale`, par défaut `https://technoversailles.fr` ; vide = aucun). Ce domaine pointe vers le même serveur que alwaysdata : sa copie est vérifiée sur le disque ;
  - **GitHub Pages** (ex. `https://grandiosian.github.io/NetSim/`) ;
  - **GitLab Pages**, sur la Forge ÉdNat (ex. `https://lionelcaruana.forge.apps.education.fr/netsim/`) ;
  - **Serveur alwaysdata** (ex. `https://technoversailles.alwaysdata.net/miroir/netsim/`).
  Des « autres adresses » (une par ligne) peuvent s'ajouter ; elles sont essayées après.
- **Ordre de préférence** (carte « Ordre des emplacements », en bas de la page Applications) :
  - Centrale **en local** (localhost, 127.0.0.1, adresse 192.168… / 10…, nom en .local) : local → GitHub → GitLab → nom de domaine → alwaysdata ;
  - Centrale **en ligne** : nom de domaine (`technoversailles.fr/miroir/…`) → alwaysdata (`technoversailles.alwaysdata.net/miroir/…`) → GitHub → GitLab (le local est ignoré : les élèves ne peuvent pas joindre l'ordinateur du professeur).
  Un ordre enregistré avant l'ajout du nom de domaine le reçoit juste avant alwaysdata.
  La détection est automatique ; on peut forcer « en local » ou « en ligne », et changer chaque ordre.
- La Centrale vérifie elle-même quelles adresses répondent (cache de 5 minutes ; la copie `/miroir/` est vérifiée directement sur le disque). Le portail, les propositions, les parcours et « Tester comme élève » ouvrent **le premier emplacement qui répond** ; si aucun ne répond, la première adresse publique. Les liens des étapes de parcours (souvent vers GitHub) sont redirigés de la même façon. Dans l'onglet Ateliers, ● veut dire que l'adresse répond, ○ qu'elle ne répond pas, – qu'elle n'est pas utilisée ici ; « ← ouvert par le portail » montre le choix actuel.
- À la mise à jour, les anciennes « adresse principale » et « adresses de secours » sont rangées automatiquement dans les emplacements. L'import d'un `centrale.json` (`url`, `urls_secours`) remplit aussi les emplacements vides.
- **Copie de secours sur ce serveur** : chaque application listée dans `miroirs.txt` est copiée dans `public/miroir/<nom>/`, servie à `https://VOTRECOMPTE.alwaysdata.net/miroir/<nom>/` (NetSim, TechnoStudio et Établi 3D). Une ligne peut ajouter le dossier à publier et la commande qui l'assemble : `etabli3d https://github.com/grandiosian/etabli3d dist python3 build.py`. En local, `techno-local.sh miroirs` fait de `public/miroir/*` des liens vers les clones : l'emplacement « Local » ouvre alors la copie de l'ordinateur.
- **Outils réservés à l'enseignant** : une ligne `enseignant:<nom> …` de `miroirs.txt` (ex. `enseignant:fichestudio https://github.com/grandiosian/FicheStudio dist npm ci && npm run build`) copie l'outil dans `prive/miroir/<nom>/`, **hors de la racine web** : `/miroir/<nom>/` n'existe pas. Il est servi par `/admin/appli.php/<nom>/`, seulement au navigateur connecté à l'espace enseignant (sinon : page de connexion). **FicheStudio** (mise en page des fiches d'atelier, compatible Affinity) s'ouvre ainsi par le bouton « FicheStudio » du tableau de bord. Il y prend les classes et demi-groupes de la Centrale, choisit d'office le cours en cours (emploi du temps) et enregistre l'avancement de la correction projetée par classe et par document (`api/fichestudio.php`, table `fichestudio_avancement`, réservé à la session enseignant). Les documents FicheStudio de l'enseignant (`.fstudio`, images intégrées) sont rangés dans `data/fichestudio/` (jamais public, jamais touché par le déploiement) : enregistrés et rouverts depuis FicheStudio (« ☁ Enregistrer », « ☁ Ouvrir… »), ou déposés directement dans ce dossier par SSH. En local, `techno-local.sh miroirs` relie `prive/miroir/fichestudio` au `dist/` du clone FicheStudio (`FICHESTUDIO=…` pour un autre dossier).
- Une application ouverte depuis une copie `…/miroir/<nom>/` se connecte à **la Centrale qui la sert** (NetSim, TechnoStudio, Établi 3D) : depuis une Centrale locale, la progression et les fichiers sont enregistrés en local. Ouverte depuis GitHub ou GitLab, elle utilise la Centrale en ligne (ou `window.CENTRALE_URL`).
  - La copie est mise à jour à chaque déploiement et toutes les heures, par le workflow GitHub.
  - Si une copie échoue, rien n'est envoyé et le serveur garde la version précédente.
- Les applications fonctionnent aussi **sans la Centrale**, avec un enregistrement dans le navigateur. Si la Centrale est injoignable, le travail est gardé localement et renvoyé à son retour.

### D'où se connectent les élèves

À chaque ouverture d'atelier, la Centrale note l'hébergement d'où l'application a été chargée : GitHub, Forge, ce serveur… Elle ne stocke pas d'adresse IP, et garde 120 jours d'historique.

- **Onglet Ateliers** : nombre d'ouvertures sur 7 jours par hébergement.
- **Page Travail d'un élève** : ses 30 dernières connexions, avec l'atelier, l'hébergement, et s'il est passé par le portail ou a tapé son code.

### Ateliers : les activités des applications

**Navigation** : la barre du haut a deux entrées distinctes — **Applications** (les applications du portail des élèves : brancher, emplacements, activation, tester comme élève ; en tête, la carte **Applications de l'enseignant** avec **FicheStudio** — lien enseignant `admin/appli.php/fichestudio/`, « Ouvrir » et « ＋ Nouvel atelier », date de la copie sur le serveur — réservé à l'espace enseignant, jamais sur le portail des élèves) et **Ateliers** (leur contenu). L'ancienne page Bibliothèque et l'ancien onglet « Ateliers des applications » montraient les mêmes ateliers : ils sont réunis dans la page **Ateliers** — les cartes d'activités (ci-dessous) et, dans la section dépliable **Imports et disponibilité**, l'import depuis les applications, les périodes, les classes et les ateliers retirés. Les anciennes adresses (`?p=bibliotheque`, `?p=ateliers&vue=catalogue`) mènent à la nouvelle page.

La page **Ateliers** de l'espace enseignant réunit toutes les activités de toutes les applications : celles déclarées par les passerelles (`centrale.json`) et celles déclarées à la connexion des élèves.

- **Rangement** : par application, puis par groupe.
- **Filtres** :
  - par niveau (6ᵉ, 5ᵉ, 4ᵉ, 3ᵉ, Lycée / Pro, deviné d'après le groupe ou le titre) ;
  - par application ;
  - par texte ;
  - « Déjà proposées » ou « Proposées à 5A ».
- **Essayer** : ouvre l'activité avec le compte test (classe TEST), dans un nouvel onglet.
- **Proposer** : cochez des activités, puis choisissez des classes ou des groupes, une date « pour le » et une consigne (les deux facultatives).
  - La pastille de la classe apparaît alors sur l'activité ; × retire la proposition.
  - Si un groupe est supprimé, ses propositions passent à la classe entière.
- **Ajouter au parcours** : les activités cochées deviennent des étapes, soit d'un nouveau parcours (le formulaire est prérempli), soit à la fin d'un parcours existant.
- **Côté élève** :
  - en haut du portail, la section **À faire** liste les activités proposées à sa classe ou à son groupe, avec la date (signalée si elle est dépassée), la consigne et un bouton Commencer / Continuer / Revoir ;
  - dans la carte de chaque atelier, seules les activités proposées et celles déjà commencées apparaissent. Un réglage en bas de la page Ateliers permet de les montrer toutes ;
  - dans l'application elle-même, l'élève peut toujours tout ouvrir.

### Élèves test : tester à la place d'un élève

Onglets **Ateliers** et **Parcours**, carte « Tester à la place d'un élève » :

- **Créer les classes de test** : 5TEST et 4TEST, 3 élèves fictifs chacune. Le chiffre du nom donne le niveau. Leurs codes s'affichent une fois, pour essayer aussi la connexion par code. Toute classe nommée `TEST`, `5TEST`, `4 TEST`… compte comme classe de test.
- **Choisir un élève test**, puis :
  - **Ouvrir son portail ↗** : nouvel onglet connecté comme cet élève (2 h, sans code), avec ses ateliers, ses parcours, son dossier de projet et « Étape suivante », comme en classe ;
  - **Tester ↗** sur une ligne d'atelier : l'atelier s'ouvre directement, connecté ;
  - **Tester ↗** sur une ligne de parcours : son portail, avec le parcours. Si le parcours n'est pas proposé à sa classe, la Centrale le dit.
- L'élève choisi est gardé d'une page à l'autre. Tous les élèves sont proposés : les classes de test et simulées d'abord, puis les vraies classes (marquées « vrais élèves : leur travail est modifié » : ce que l'enseignant fait à leur place s'enregistre dans leur dossier). Ces essais ne comptent pas dans les connexions de l'élève.
- La session ouverte ainsi est marquée **essai** (`sessions.essai = 1`, renvoyé par `api/session.php`). Les applications y affichent, juste après l'atelier au centre de la barre du haut, l'interrupteur **Élève | Enseignant** : vue de l'élève (règles de l'atelier, fenêtres et fonctions de l'élève) ou vue enseignant (tous les modes), sans quitter la session de l'élève. TechnoStudio le fait depuis la v3.13.0 ; une session d'élève normale n'a jamais cet interrupteur.
- **Remettre les élèves test à zéro** : efface leur travail, leur avancement, leurs dossiers de projet et les étapes validées, pour rejouer un parcours depuis le début. Les classes simulées ne sont pas touchées.

### Classes simulées : voir le suivi comme avec une vraie classe

Onglet **Ateliers**, carte « Classes simulées » (`lib/simulation.php`) :

- **Créer les classes simulées** : 5SIM et 4SIM, 24 élèves fictifs chacune, en GR1 / GR2, qui ont travaillé 8 semaines (une séance par semaine, la dernière avant-hier) sur les ateliers de leur niveau déclarés par les applications (groupes « 5e · … », « 5ᵉ · … », pris à tour de rôle entre applications).
- Profils : 3 rapides, 9 réguliers, 4 en difficulté (beaucoup d'essais, corrections affichées), 2 retardataires (arrivent en retard, moins de temps de travail), 2 absentéistes (une séance sur trois environ, trous dans le suivi), 2 observateurs (lisent longtemps, regardent les corrections, laissent des activités inachevées), 1 décrocheur (s'arrête au bout de 4 semaines), 1 jamais connecté. Le profil est dans le surnom (`Retardataire1`) et s'affiche à la place du nom (« Retardataire 1 · simulé »).
- Tout passe par les vraies tables : progression avec mesures (temps actif, essais, premier coup, délai de réponse, détail par question ; certaines activités et questions sont plus difficiles que d'autres), constats de compétences « envoyés par l'application », connexions portail + atelier, dernière connexion. Suivi, page élève, bilan de compétences et page classe s'affichent comme pour une vraie classe.
- Établissement **Collège SIM** (créé avec les classes, supprimé avec elles ; les classes simulées qui n'y sont pas encore rangées le sont toutes seules à l'ouverture de l'accueil ou de la page Applications) : grille horaire M1–S4, salle **SIM1** meublée (6 îlots de 4 places), un cours de Technologie par groupe chaque jour du lundi au samedi (5SIM : GR1 en M2, GR2 en M3 ; 4SIM : GR1 en S1, GR2 en S2) et les élèves placés (par groupe et classe entière). Les classes simulées apparaissent donc dans « Les cours aujourd'hui » et « Voir une classe », avec leur plan en 3D ; elles restent hors du calendrier, des prochains cours et de l'horloge de l'accueil.
- La génération est reproductible (mêmes élèves à chaque fois) ; **Régénérer** la refait à la date du jour, **Supprimer** efface tout. Les classes simulées comptent comme classes de test (« Tester à la place d'un élève ») mais sont écartées des chiffres de connexion du tableau de bord et des compteurs de la bibliothèque.

### Ateliers du portail

Onglet **Ateliers** de l'espace enseignant : icône, nom, identifiant (`app`), adresse, classes concernées.

- Sur le portail, chaque atelier est une carte avec son avancement et la liste de ses activités.
  - **Ouvrir** lance l'atelier dans le même onglet, déjà connecté.
  - **Ouvrir** sur une activité lance directement cette activité, par exemple l'exercice e5-1 de NetSim.
- Dans l'atelier, le bouton **« ← Mes ateliers »** (en bas à gauche, ajouté automatiquement par le module) ramène au portail. Il envoie d'abord ce qui n'est pas encore enregistré. L'élève peut alors ouvrir un autre atelier sans rapport.
- Les applications déjà branchées mais absentes du portail sont proposées en un clic.

Côté application, pour l'ouverture directe d'une activité :

```js
Centrale.afficherConnexion(element, {
  onConnecte: () => {
    const demande = Centrale.activiteDemandee();   // ex. "e5-1", ou null
    if (demande) ouvrirExercice(demande);
  }
});
```

`Centrale.init({ boutonRetour: false })` masque le bouton retour automatique, si l'application préfère le placer elle-même avec `Centrale.retour()`.

### Imports et disponibilité des ateliers

Les ateliers sont créés **dans les applications**. Les premiers pas restent dans leur code et sont publiés avec elles ; les autres
ateliers sont **transférés par la Centrale** : un fichier versionné par atelier dans `ateliers/<app>/<id>.json` de ce dépôt
(règle : `public/passerelle/ATELIERS-INTEGRES-ET-TRANSFERES.md`). La Centrale range les deux dans son catalogue.

- **Ateliers transférés** (`lib/ateliers_centrale.php`) : la Centrale relit le dossier `ateliers/` dès qu'il change (empreinte des
  fichiers, à la première lecture du catalogue après un déploiement) et au bouton « Mettre à jour » ; source « fichier de la Centrale ».
  Pour TechnoStudio, les activités sont calculées comme dans son `js/passerelle.js`. Un fichier supprimé passe l'atelier à
  « retiré ». Un fichier de ce dossier passe avant ce que l'application publie sous le même identifiant.
  Les applications les lisent par `api/atelier-fichier.php` (sans jeton) et en gardent une copie pour les pannes du serveur.
  Ils ne vont jamais dans `data/`.

- Page **Ateliers**, section dépliable **Imports et disponibilité** : « Importer les ateliers de toutes les applications », ou « Importer / Mettre à jour » pour une seule.
- La Centrale lit le catalogue `ateliers.json` publié à côté de `centrale.json`. À défaut, elle regroupe les activités de `centrale.json` en ateliers et le signale.
- Bilan affiché : ajoutés, modifiés, retirés (jamais effacés), inchangés. Les activités rejoignent les cartes de la page Ateliers, les parcours et le suivi.
- Filtres par application, niveau, texte ou code de compétence ; « Ouvrir » lance l'atelier sans connexion.
- **Ouvrir** (dans le tableau, « Détail », Inspecter) : ouvre l'atelier dans la copie de l'application servie par la Centrale, connecté comme un élève des classes de test (celui choisi dans Applications → « Tester à la place d'un élève », sinon le premier), en session d'essai de 2 h : interrupteur **Élève | Enseignant** dans la barre du haut, « ← » et « Suivant » comme depuis une carte du portail. Jamais un vrai élève. Sans classe de test : en visiteur. Route `./?p=essayer` (`lib/admin_tester.php`).
- **Inspecter** (dans le tableau, ou « Détail » d'un atelier → « Inspecter les données ») : tout ce qui décrit l'atelier, couche par couche — la fiche du catalogue de la Centrale, ses activités (identifiants, compétences, élèves), où il sert (calendriers, périodes, parcours, activités demandées), puis ce que l'application publie, lu à l'instant chez elle (`ateliers.json`, et pour TechnoStudio le fichier complet ; pour un atelier transféré, son fichier `ateliers/<app>/<id>.json` lu par l'API de la Centrale : feuilles P/R/S/E avec leurs codes, blocs notés, compétences reliées ou non au référentiel, poids des images), et enfin le **code source** : le fichier complet (Télécharger, Copier, lien GitHub) ou, pour un atelier écrit dans le code de l'application, l'extrait de ce code lu sur GitHub avec son numéro de ligne (fichiers déclarés dans `SOURCES_CODE_ATELIERS`). Rien n'y est modifié. `lib/admin_inspecter.php`, `admin/inspecter.js`.
- **Procédure de création d'un atelier** (surtout TechnoStudio), avec les règles qui protègent le travail des élèves : `public/passerelle/CREER-UN-ATELIER.md`, en ligne sur `passerelle/?doc=creer-atelier`, liée en haut de la page Ateliers.
- Un fichier exporté d'une application (atelier `.json`, `ateliers.json`, `.technostudio.json`) peut aussi être importé à la main : il reste marqué « pas encore dans l'application » tant qu'elle ne le publie pas.
- Les autres applications lisent ce catalogue : `GET /api/catalogue.php?app=&niveau=&competence=`.
- Colonne **Disponibilité** : « + Période » ouvre un atelier **du … au …** (dates incluses, une borne peut rester vide), pour toutes les classes ou pour une seule. Sans période, l'atelier est ouvert en permanence ; avec plusieurs, il est ouvert pendant chacune.
  - Avant sa période, ses activités apparaissent sur le portail en « Bientôt » avec la date d'ouverture. Après, elles disparaissent, sauf pour l'élève qui les a commencées : il les voit marquées « Fermé ».
  - Les étapes de parcours et les propositions de cet atelier suivent les mêmes périodes. `api/catalogue.php` publie les périodes communes à toutes les classes (champ `disponibilite`).
  - Les applications restent ouvrables sans la Centrale : ces périodes règlent ce que la Centrale montre aux élèves.

### Calendrier des ateliers (progression d'une classe, pas à pas)

Onglet **Calendrier** (`?p=calendrier`, code : `lib/progression.php`, `lib/admin_progression.php` et l'éditeur dans `public/admin/admin.js`, table `progression_ateliers`). Pour chaque classe, l'enseignant choisit ses ateliers, les range dans l'ordre où elle les fera, et chacun reçoit une **date d'ouverture**.

- **Choisir une classe** : une pastille par classe, avec son nombre d'ateliers au calendrier (– : pas de calendrier).
- **Classe sans calendrier** : la page montre ce que ses élèves voient aujourd'hui (`vue_classe_sans_calendrier`, mêmes règles que `api/ateliers.php`) : les applications ouvertes à la classe (onglet Ateliers), et dans chacune les ateliers non fermés par une période. Avec le réglage « activités proposées » (par défaut), seulement les ateliers où une étape a été proposée à la classe ; une application sans proposition garde quand même sa carte dans « En cours » (« Ouvrir »), avec ce que chaque élève a déjà commencé. « Partir de ces ateliers » les met dans l'éditeur (ceux du niveau de la classe si tout le catalogue est montré).
- **Partir d'une autre classe** : « Partir du calendrier de… » (ou « Remplacer par… ») reprend la liste d'une autre classe, dates recalculées sur l'emploi du temps de celle-ci. Rien n'est enregistré avant « Enregistrer ».
- **Parcours** (filtre « Parcours » du catalogue) : les parcours de la classe (onglet Parcours) se placent et se datent comme un atelier, avec une séance par étape par défaut. Sur le portail, chacun devient une carte 🧭 « Parcours », ouverte à sa date (« À venir » avant). Ses étapes s'ouvrent selon son réglage (libre, dans l'ordre, après validation) ; chaque étape ouvre sa propre application, avec le bouton « Étape N : … → » pour passer à la suivante. Un parcours qui n'est dans aucun calendrier n'est pas montré sur le portail (lignes `app = '*parcours'`, `cartes_parcours_calendrier()`).
- **Ajouter** : catalogue à gauche, avec recherche, niveau (celui de la classe par défaut, ou tous) et application. Le bouton « + » ajoute l'atelier à la fin, **déjà daté**. Le nombre de séances vient de sa durée annoncée (« 3 séances », « 2 h », « 220 min ») pour des séances d'1 h 30, 1 sinon.
- **Ranger** : glisser une ligne (poignée ⠿ ou titre), ou ↑ ↓. ✕ retire l'atelier. ↺ le remet en **révision** : chez les élèves, il passe dans « Révisions » (bouton « Réviser »), même s'ils l'ont terminé (colonne `revision`, champ `revision` de `api/ateliers.php`).
- **Dates calculées en direct** à partir de « Premier atelier le … » : chaque atelier s'ouvre à la séance qui suit la fin du précédent, d'après sa durée en séances.
  - « avec le précédent » (`seances = 0`) : il s'ouvre le même jour que le précédent (deux ateliers courts dans une séance).
  - Une date changée à la main est **fixée** 📌 (colonne `date_fixe`) et les suivantes repartent de là ; cliquer 📌 la remet en calcul. Un atelier qui s'ouvre avant le précédent est signalé (⚠).
  - Le calcul suit l'emploi du temps : semaines A/B, vacances, séances annulées, déplacées ou ajoutées. Il compte les cours en classe entière **et** ceux du premier demi-groupe (un élève suit les deux) ; la page affiche les cours comptés.
  - Sans emploi du temps : une séance par semaine à partir de la date de début.
  - À l'ouverture de la page, une date enregistrée qui ne suit plus le calcul (saisie à la main avant, emploi du temps changé) est gardée et fixée ; « Tout recalculer » les remet toutes en calcul.
- **Enregistrer** : un seul bouton pour toute la page ; la page prévient si on la quitte sans enregistrer. Une liste vidée puis enregistrée supprime le calendrier.
- **Copier vers d'autres classes** : le calendrier enregistré, avec les dates recalculées d'après l'emploi du temps de chaque classe si on le coche.
- **Ce que voit l'élève** dès que sa classe a un calendrier :
  - seulement les ateliers du calendrier, dans leur ordre ;
  - ouverts à partir de leur date, en « À venir » avant (gris, compte à rebours) ;
  - rangés en **En cours** (l'atelier courant en grand, puis les autres ouverts), **Révisions**, **À venir** et **Déjà fait** (replié) ;
  - un atelier hors calendrier ou sans date n'est pas montré, même commencé. Le travail reste enregistré et revient si l'atelier est remis au calendrier.
- **Une carte par atelier** sur le portail (et non par application) : un atelier fait va dans « Déjà fait » pendant que le suivant de la même application est en « À venir ». Le nom de l'application est écrit au-dessus du titre, et « Revoir » rouvre l'atelier, pas toute l'application.
- **Étapes une à une** (pour toutes les classes, avec ou sans calendrier) : dans un atelier ouvert, une étape s'ouvre quand la précédente est terminée. La vue enseignant d'un essai (« Tester à la place d'un élève ») lève ces verrous. Les suivantes sont affichées 🔒 avec « S'ouvre quand tu as terminé « … » ». Le bouton de la carte ouvre la prochaine étape, pas toute l'application.
- **Restent toujours ouverts** : ce que l'enseignant demande à un élève (propositions), car c'est une demande explicite ; cela ne suit que les périodes de disponibilité. Les étapes d'un parcours placé dans le calendrier ignorent aussi le calendrier des ateliers : c'est la date du parcours qui compte.
- **À compléter jusqu'au…** : pour chaque atelier ou parcours daté, le portail affiche dans « Tu en es là » sa dernière séance prévue (date d'ouverture + nombre de séances, d'après l'emploi du temps ; sans emploi du temps, la veille de l'atelier daté suivant). Ce n'est qu'un repère : l'atelier ne se ferme pas à cette date. Sans date (pas de calendrier ni de fin de période) : « Atelier permanent » ou « Parcours permanent ». Calcul : `echeances_progression()` (lib/progression.php), champ `a_completer` de `api/ateliers.php`.
- **Sans calendrier**, une classe garde les périodes de disponibilité, le réglage « activités proposées » et une carte par application ; les étapes s'ouvrent quand même une à une dans chaque atelier. « Supprimer le calendrier » (en bas de la page) y ramène la classe.
- **Limite** : une application reste ouvrable seule, et sa propre bibliothèque n'est pas filtrée par la Centrale. Le calendrier règle ce que montrent le portail et `api/ateliers.php`, qui renvoie `verrou` et `apres` pour une étape verrouillée.

Règles communes : [`public/passerelle/DONNEES-BONNES-PRATIQUES-COMPATIBILITE.md`](public/passerelle/DONNEES-BONNES-PRATIQUES-COMPATIBILITE.md) et [`public/passerelle/COMPETENCES.md`](public/passerelle/COMPETENCES.md), lisibles en ligne sur `/passerelle/?doc=donnees` et `/passerelle/?doc=competences`.

### Dans une application

```js
Centrale.init({
  api: "https://VOTRECOMPTE.alwaysdata.net/api/",
  app: "netsim",
  // Liste des activités, dans l'ordre : ce seront les colonnes du tableau de suivi
  activites: [
    { id: "e5-1", titre: "Brancher la salle", groupe: "5e", total: 5 },
    { id: "e5-2", titre: "Le Wi-Fi de la salle", groupe: "5e", total: 5 },
  ],
});

// Quand l'élève avance (étape validée, niveau réussi…)
Centrale.progression("e5-1", { titre: "Brancher la salle", faites: 3, total: 5 });
// Quand c'est fini
Centrale.progression("e5-1", { statut: "termine" });
// Questionnaire noté (type Moodle) : score + réponses visibles par l'enseignant
Centrale.progression("quiz-reseau", { statut: "termine", score: 8, score_max: 10, details: { reponses } });
```

- Le serveur garde le **meilleur** résultat : une activité terminée le reste, et `faites` et `score` ne baissent pas si l'élève recommence.
- Hors ligne, les envois sont mis en file d'attente puis envoyés au retour du réseau.
- Un module seul (comme NetSim) n'a besoin de rien d'autre. Ses exercices apparaissent dans le suivi.

### Parcours sur plusieurs logiciels

Dans l'espace enseignant, onglet **Parcours** : une étape par ligne.

```
Titre de l'étape            | atelier      | activité                 | adresse du logiciel
Brancher la salle           | netsim       | e5-1                     | https://grandiosian.github.io/NetSim/
Modéliser le support        | etabli3d     |                          | https://grandiosian.github.io/etabli3d/
Programmer la lampe         | technostudio | charte-lampe-de-poche/P1 | https://grandiosian.github.io/TechnoStudio/
```

- **Activité vide** = tout l'atelier. L'étape est terminée quand toutes ses activités déclarées le sont.
- **Ateliers de vos applications** (sous le formulaire) : par application, puis par atelier du catalogue, chaque activité avec son étape toute prête : `Titre | app | activité | adresse`, puis `reçoit :` et `produit :` (fichiers du dossier de projet que l'application déclare dans son `centrale.json`, nom conseillé pour un motif ; aujourd'hui seul Établi 3D en échange) et `compétences :` (codes de l'activité, sinon de l'atelier). Pour une première étape, retirez « reçoit » ou déposez le fichier dans « Fichiers fournis » : sinon la vérification signale un fichier reçu que rien ne produit. L'adresse est celle que le portail ouvre en premier (en ligne : `https://technoversailles.fr/miroir/…`) ; une étape écrite avec une autre adresse connue de l'application (GitHub…) est de toute façon redirigée. « + » l'ajoute à la fin des étapes, « + tout l'atelier » ajoute une étape par activité, « + toute l'application » une étape sans activité précise. Une recherche filtre ateliers et activités ; « Autres activités » regroupe celles qu'aucun atelier ne contient (`catalogue_pour_parcours()`).
- **Classes concernées** : vide = toutes.
- Sur le **portail élève** (`https://VOTRECOMPTE.alwaysdata.net/`), un parcours n'apparaît que s'il est placé dans le **Calendrier** de la classe : une carte 🧭 avec ses étapes, l'étape suivante, et **Commencer** ou **Continuer**. Le logiciel s'ouvre déjà connecté : le jeton passe dans l'adresse (`#centrale=…`) et le module le retire aussitôt.
- **Bouton « Étape N : titre → »** : le module l'ajoute dans toute application ouverte depuis une étape de parcours, en bas à gauche, à côté de « ← Mes ateliers ».
  - Au clic, il enregistre tout, puis ouvre directement l'étape suivante : la bonne application, la bonne activité, le même dossier de projet, sans repasser par le portail.
  - Si l'étape suivante est verrouillée (« dans l'ordre » ou « après validation »), il affiche un 🔒 et explique pourquoi. En mode « dans l'ordre », quand l'application dépose ses fichiers en partant (Établi 3D), il reste cliquable : il dépose d'abord, puis ouvre l'étape suivante si l'étape est terminée.
  - À la dernière étape, il devient « Parcours terminé → Mes ateliers ».
- **Boutons des applications** (formulaire du parcours) : « ← » en haut (dès la 2ᵉ étape) revient à l'étape précédente ou à la Centrale ; le bouton du bas ouvre l'étape suivante ou ramène à la Centrale (« Mes ateliers → », la suite du parcours est sur le portail). Pour une seule étape, ajoutez sous elle `avant : mes ateliers` ou `après : mes ateliers` (`étape` pour revenir au comportement habituel).
  - Il se met à jour tout seul après chaque progression ou dépôt de fichier.
- **Côté application** (détails : spécification de la passerelle, § 2.6) :
  - `Centrale.init({ avantDeQuitter: async () => … })` : dépose ce qui attend encore, avant « ← Mes ateliers » ou « Étape suivante » (15 s au plus) ;
  - `Centrale.init({ boutonSuivant: false })` masque le bouton, pour placer le sien avec `Centrale.etapeSuivante()` et `Centrale.allerEtapeSuivante()` ;
  - le descripteur le déclare dans `centrale.parcours` (`bouton_suivant`, `avant_de_quitter`).
- Entre vos propres applications, `Centrale.ouvrir(url)` ouvre une autre application déjà connectée.

### Tableau de suivi

Onglet **Suivi** : une classe, et au choix un parcours ou un atelier.

- Une ligne par élève, une colonne par activité ou par étape.
- Chaque case indique : ✓ terminé, 3/5 en cours, vide pas commencé.
- On y trouve aussi le pourcentage d'avancement, la dernière activité et le total « terminé par » de chaque colonne.
- Les noms s'affichent depuis votre ordinateur.
- **Exporter (CSV)** produit le tableau avec les noms, à ouvrir dans un tableur.
- La page **Travail** d'un élève détaille son avancement : dates, résultats, réponses envoyées dans `details`.
- **Mesures pendant l'atelier** : quand l'application les envoie (champ `mesures`, TechnoStudio depuis la version 3.10), elles sont rangées avec la progression.
  - **Sous le tableau**, une ligne par activité : élèves mesurés, temps actif médian et maximal, essais par question, part réussie du premier coup, délai médian avant la première réponse, corrections affichées.
  - **« Questions, de la plus difficile à la plus facile »** classe les questions par nombre moyen d'essais. Une question est surlignée à partir de 3 essais en moyenne ou de moins de 40 % de réussite du premier coup.
  - **Sur la page de l'élève**, la colonne « Temps actif · essais » résume les mesures, et le lien « questions » détaille chaque question.

---

## 4 bis. Compétences

Le référentiel est celui du programme de technologie du cycle 4 (BO du 29 février 2024) : thèmes **OST**, **SFC**, **CCRI**, compétences (OST1), sous-compétences (OST11) et **114 capacités** différentes en 5e, 4e et 3e, avec leurs connaissances.

- **Codes** : `OST11.5.2` = sous-compétence OST11, niveau 5e, capacité n° 2. Ils ne changent jamais.
- **Fichier** : `public/passerelle/competences.json`, servi à `/passerelle/competences.json`. Les applications lisent le même fichier. TechnoStudio code T1 = OST, T2 = SFC, T3 = CCRI.
- **Mise à jour** : il est produit à partir des tableaux du programme (CSV OST, SFC, CCRI) :
  `python3 outils/referentiel_csv.py OST.csv SFC.csv CCRI.csv > public/passerelle/competences.json`
- **Onglet Compétences** de l'espace enseignant :
  - le référentiel, filtrable par niveau et par texte ;
  - un clic sur un code le copie ;
  - pour chaque capacité, les parcours qui l'évaluent et le nombre de notes.
- **Dans un parcours**, sous une étape : `compétences : SFC15.5.1, CCRI16.5.1`. La carte « Enchaînement » signale un code inconnu ou une capacité d'un autre niveau que les classes du parcours.
- **Notation** (page Dossiers de projet, onglet « À valider » ou « À évaluer ») :
  - chaque étape terminée qui porte des compétences affiche une grille **MI · MF · MS · TBM** (échelle du LSU), un clic par compétence ;
  - « Valider » enregistre les notes et ouvre l'étape suivante ; « Enregistrer » note seulement (parcours sans validation, ou dernière étape) ;
  - en équipe, la note va à chaque membre.
- **Constats des applications** : chaque activité déclare ses capacités (`competences`). L'application envoie des constats avec sa progression (`constats: [{ competence, reussi, indices, essais }]`), voir la spécification de la passerelle, § 2.7.
- **Bilan = moyenne haute** de tous les constats d'une capacité (les vôtres et ceux des applications), arrondie au niveau supérieur. Une sous-compétence prend la moyenne haute des constats de ses capacités.
- **Suivi → Compétences 5e / 4e / 3e** :
  - élèves × sous-compétences (ou × capacités avec « Détail par capacité ») ;
  - couleurs des thèmes : OST vert, SFC rose, CCRI jaune orangé ;
  - limitable à une séquence de la progression.
- **Onglet Compétences → Progression 5e / 4e / 3e** : vos 8 séquences (thème, support) et les capacités travaillées dans chacune, comme vos tableaux de progression. Les capacités placées dans aucune séquence sont signalées.
- **Page Travail de l'élève**, section **Compétences** : le bilan de chaque capacité, le nombre de constats, et le détail (vous ou application, indices, essais). Rien n'est effacé.

## 5. Organisation : établissements, emploi du temps, plans de classe

### Établissements

Onglet **Établissements**. Pour chaque établissement, on règle :

- **la grille horaire**, un créneau par ligne, par exemple `M1 08:00 08:55` ;
- **la zone de vacances** (A, B ou C) et l'**import du calendrier officiel** (data.education.gouv.fr) pour l'année en cours ou la suivante. On peut aussi saisir des périodes à la main, par exemple un pont ;
- **les semaines A/B**, avec une date de semaine A comme référence. Une option permet de ne pas compter les semaines de vacances dans l'alternance ;
- les cours du samedi ;
- **les salles**, avec leur plan, et les classes rattachées. Une classe se rattache aussi depuis sa page.

### Emploi du temps

Onglet **Emploi du temps** : une grille par semaine, par établissement, avec l'indication de la semaine A ou B et les jours de vacances hachurés.

- **La semaine type** regroupe les cours récurrents : classe, jour, créneau, durée en créneaux, toutes les semaines ou seulement A ou B, salle, intitulé, et une période de validité facultative.
- **Cliquer sur un cours de la grille** permet d'agir sur ce jour-là seulement :
  - l'**annuler**, avec un motif ;
  - le **modifier** : autre date, autre créneau, autre salle, ou une note comme « Évaluation » ;
  - le **rétablir** comme prévu.
- **Cours ponctuel** : un rattrapage ou une séance en plus.
- L'**Accueil** affiche le calendrier de la semaine et les prochains cours, avec un accès direct au plan de classe.

### Plans de classe

- **Plan de la salle**, dessiné une seule fois. On y place les tables, les postes informatiques, les places élèves, la paillasse, le bureau, le tableau, la porte et les fenêtres.
  - **Vue 3/4** : la salle en perspective isométrique, sur une dalle, avec murs de fond, mobilier en volumes et chaises bleues. Le **titre de la classe** est un **bandeau blanc en haut du mur, sur toute sa largeur**, au-dessus des meubles, en deux lignes : **1.** la pastille de la classe, puis « Salle · Collège » ; **2.** « Atelier : … · élèves · observations ». Seul le bandeau du mur face à la caméra est montré ; à l'accueil, son texte glisse le long du mur là où les widgets ne le cachent pas (au milieu, à égalité). Il passe devant les meubles adossés ; les élèves et les liens restent devant lui. Quatre angles (0°, 90°, 180°, 270°) et un bouton **Tour** qui les fait défiler, à régler dans l'éditeur ou dans la barre **Présentation** (accueil et plan de placement), qui s'applique à tous les plans affichés sur cet ordinateur.
  - **Affichage** : sol uni, bois ou parquet (couleurs adaptées au mode nuit), salle en portrait, miroir horizontal ou vertical. Ces réglages s'appliquent au plan de placement, à l'aperçu et à l'impression (A4 suit l'orientation) ; les noms restent droits et lisibles. L'éditeur garde le sens réel de la salle.
  - On glisse les éléments pour les déplacer. On peut les tourner, les redimensionner, les dupliquer ou leur donner une étiquette, au clavier ou avec la souris.
  - La **disposition rapide** génère des rangées de tables, avec ou sans ordinateurs.
- **Placement d'une classe dans une salle** : on **glisse un élève** (depuis la liste, ou directement sur le plan, en vue à plat comme en 3D) sur une place ; si elle est prise, les deux élèves **échangent leurs places**. Glisser un élève du plan vers la liste le retire. On peut aussi cliquer sur un élève puis sur une place, faire un placement aléatoire (qui sépare les élèves notés ensemble pour une dissipation ou une altercation dans la feuille de séance), vider la salle, ou imprimer. « ● modifications non enregistrées » rappelle d'enregistrer.
  - **Proposer un nouveau plan…** : un **aperçu** d'un plan calculé d'après les liens entre élèves de la feuille de séance — il **éloigne les élèves à séparer** (altercation, dissipation) et **rapproche les bonnes dynamiques**. Encadré bleu au-dessus du plan : **avant → après** (paires à séparer assises trop près, bonnes dynamiques côte à côte) et le nombre d'élèves qui changent de place, **cerclés en bleu** sur le plan à plat. Deux façons : **Changer le moins de places possible** (part du plan affiché ; par défaut s'il y en a un) ou **Tout redistribuer** ; **↻ Autre proposition** en calcule une autre. Rien n'est appliqué tant qu'on n'a pas cliqué **✓ Garder ce plan** (puis « Enregistrer le plan de classe ») ; **Revenir au plan actuel** ou Échap annule. **＋ Ajouter comme version** (avec un champ nom, « Proposition 1 » par défaut) enregistre la proposition comme **nouvelle variante sans modifier le plan de la classe** (action `enregistrer_version` ; à la première variante, le plan d'origine est gardé comme « Plan initial ») : la page revient sur le plan d'origine et la variante s'ouvre plus tard d'un clic dans « Variantes ». Si le plan affiché avait des changements non enregistrés, un second clic est demandé (ils seraient perdus). Pendant l'aperçu, on ne peut ni déplacer d'élève ni enregistrer (l'encadré le rappelle). Calcul : échanges de places au hasard gardés quand ils n'aggravent pas le coût, depuis plusieurs départs (`chercherPlan` dans `plan.js`).
- **Hors de la classe** (sous le plan) : on y glisse un élève (du plan, en 2D ou en 3D, ou de la liste) et on choisit le motif : **exclu du cours aujourd'hui** (il garde sa place, grisée, pour les cours suivants), **exclu de l'établissement** ou **sorti de l'établissement** (sa place est libérée). Le motif et sa date sont enregistrés sur la Centrale avec le suivi de classe (par numéro d'élève) ; « ↩ revenir » l'efface. Sur l'accueil, l'élève porte la pastille ⇥ (observé en classe) et sa fiche l'indique.
- **Variantes du plan** (même classe, même salle, même groupe) : pastilles en haut de la page, une par variante. **⧉ Dupliquer en variante** copie le plan affiché sous un nouveau nom (« Variante 1 », « Variante 2 »… si on n'en donne pas) et l'ouvre ; la première fois, le plan d'avant est gardé comme « Plan initial ». On glisse alors les élèves, puis **Enregistrer** met à jour le plan de la classe **et** la variante ouverte (case cochée). Un clic sur une autre pastille l'ouvre (confirmation si le plan affiché n'est pas enregistré) ; « Supprimer » retire la variante ouverte.
  - Les noms affichés viennent de votre ordinateur. Le serveur ne garde que « place → élève n° 07 ».

## 5 bis. Ateliers transversaux (parcours en plusieurs logiciels)

Un parcours peut enchaîner plusieurs applications : NetSim → Établi 3D → TechnoStudio… Chaque élève (parcours **individuel**) ou chaque équipe (parcours **en équipe**) a un **dossier de projet** pour ce parcours, partagé par toutes les applications. Une étape y dépose ses productions, l'étape suivante les relit.

**Côté enseignant** (onglet Parcours) :

- **Travail** : individuel ou en équipe. Les équipes se forment sur la page de chaque classe, section « Équipes » : au hasard, par 2 à 5, dans chaque groupe si la classe en a, puis à ajuster.
- **Ouverture des étapes** :
  - libre ;
  - dans l'ordre : une étape s'ouvre quand la précédente est terminée ;
  - après validation : vous validez l'étape précédente dans « Dossiers de projet ».
- **Étapes** : une ligne `Titre | atelier | activité | adresse`, suivie si besoin de ces lignes :
  ```
    > consigne affichée à l'élève (plusieurs lignes possibles)
    reçoit : support.etabli3d.json
    produit : support.etabli3d.json, support.stl
  ```
  Avec Établi 3D, les noms sont libres pourvu que l'extension soit la bonne : `.json` (projet), `resume.json`, `.stl`, `.png`. Le fichier reçu (ici le gabarit fourni) s'ouvre à l'arrivée dans l'étape ; les fichiers produits sont déposés tout seuls quand l'élève clique « Étape suivante » ou « ← Mes ateliers ».
  Une étape est terminée quand tous les fichiers de sa ligne « produit » sont déposés, ou quand l'application déclare l'activité terminée.
- **Fichiers fournis** : vos gabarits et fichiers de départ, visibles dans tous les dossiers. Si l'élève dépose un fichier du même nom, c'est le sien qui est lu.
- **Dossiers de projet** : l'avancement de chaque élève ou équipe étape par étape, la validation des étapes, et le téléchargement de leurs fichiers.
- **À valider** (parcours « après validation ») : onglet de la page Dossiers de projet, et pastille « N à valider » sur la ligne du parcours. Toutes les classes du parcours réunies, l'élève ou l'équipe qui attend depuis le plus longtemps en premier, avec les fichiers de l'étape et le bouton Valider. Dans le tableau complet, les cases en attente sont encadrées en orange.

**Côté élève** (portail) :

- les étapes s'affichent avec leur consigne, les fichiers reçus et produits, et un cadenas tant qu'elles ne sont pas ouvertes ;
- en équipe, l'élève voit ses coéquipiers par leur **identifiant** (jamais leur nom) ;
- « Mon dossier de projet » permet de télécharger ses fichiers.

**Côté application** (quelques lignes, l'application marche aussi hors atelier) :

```js
const info = await Centrale.etape();          // null si l'appli n'a pas été ouverte depuis un atelier
if (info) {
  info.etape.consigne;                          // texte de l'enseignant
  for (const nom of info.etape.entrees) {       // ce que l'étape reçoit
    const contenu = await Centrale.projet.charger(nom);   // JSON, texte ou Blob ; null s'il n'existe pas
  }
  await Centrale.projet.sauver("support.stl", blob);      // dépose une production (objet → JSON)
}
```

En équipe, si un coéquipier a enregistré le même fichier depuis votre lecture, `sauver` lève une erreur avec `e.conflit = true` et `e.auteur`. L'application recharge alors le fichier, ou force l'enregistrement avec `{ forcer: true }`.

Les étapes verrouillées refusent les dépôts. Les fichiers comptent dans l'espace de l'élève qui les dépose.

## 6. Fichiers du serveur

Onglet **Fichiers** :

- l'espace total utilisé ;
- l'espace par classe (fichiers et données des applications), avec l'export ZIP ;
- la liste des fichiers des élèves, filtrable par classe, atelier et nom, triable par date, taille ou nom ;
- le téléchargement ou la suppression de plusieurs fichiers à la fois.

## 7. Espace enseignant

Adresse : **https://technoversailles.alwaysdata.net/admin/**. Elle n'apparaît pas sur la page des élèves, qui ne montre que la connexion par code.

- **Importer depuis Pronote** (bouton sur la page Classes) :
  - Source : un export CSV de la liste des élèves de Pronote, ou un copier-coller de la liste (Ctrl+A, Ctrl+C).
  - Encodage et séparateur : UTF-8 ou Windows-1252, séparateur tabulation, « ; » ou « , ». Une ligne de titre au-dessus des en-têtes est acceptée.
  - Colonnes reconnues : « Élève » (NOM Prénom) ou « Nom » + « Prénom », et « Classe ». « 5°A » devient « 5A ». Les autres colonnes sont ignorées.
  - Lecture du fichier : elle se fait **dans le navigateur**. Pour chaque classe, le serveur ne reçoit que le nom de la classe et le nombre d'élèves. Il crée la classe si besoin et renvoie les codes.
  - Après l'import : les noms sont enregistrés sur cet ordinateur. Les fiches sont prêtes à imprimer, et un fichier `codes_import_DATE.csv` (classe, numéro, nom, code) est téléchargé : rangez-le dans un dossier privé de votre Nuage.
  - Réimport : un élève déjà présent (même classe, même nom sur cet ordinateur) n'est pas recréé. On peut donc réimporter après l'arrivée d'un nouvel élève.
  - **PAP, PPS, PAI, PPRE** : ajoutez la colonne « Projets d'accompagnement » à la liste Pronote avant l'export. Seul le type de projet est gardé, jamais le détail (par exemple la nature d'une allergie).
    - Ces informations restent sur votre ordinateur et dans votre fichier de noms (colonne « Besoins »). Elles ne sont jamais envoyées au serveur.
    - Elles apparaissent en pastilles à côté des noms (classe, suivi, plan de classe) et sont masquées à l'impression.
    - Réimporter le fichier les met à jour.
- **Format des codes élèves** : `4C-LHA-9943-kfmz-tqpr`.
  - Le code commence par la classe, puis le code à 3 lettres de l'établissement (onglet Établissements, champ « Code »), puis une partie personnelle tirée au hasard.
  - Si le nom de la classe contient déjà l'établissement (« 4C-HAUTIERS »), le code reprend seulement « 4C ».
  - Les majuscules et les espaces ne comptent pas à la saisie.
- **Identifiant de l'élève** : `4C-LHA-LoutreAgile`.
  - C'est la classe et l'établissement, puis un surnom tiré au hasard, attribué automatiquement.
  - L'élève le voit sur son portail et peut changer le surnom : 3 à 20 lettres ou chiffres, unique dans la classe, gros mots refusés.
  - Le début suit la classe et l'établissement. Les applications le lisent avec `Centrale.identifiant()`, ou l'affichent avec `Centrale.afficherIdentifiant(element)`.
  - Essai de l'enseignant, vue **Enseignant** : le widget du portail affiche le **nom** de l'élève, lu dans les noms gardés sur cet ordinateur (liste chargée dans l'espace enseignant), ou « pas désanonymisé ». Rien n'est envoyé au serveur. Les applications font de même avec `Centrale.nomAffiche()` ; seules celles servies par la Centrale (`/miroir/…`) voient les noms, qui restent propres au site.
  - Côté enseignant (colonne « Identifiant ») : un surnom qui ressemble au vrai nom est signalé, et ↻ en donne un autre au hasard. La comparaison se fait sur votre ordinateur.
  - Le changement de surnom par les élèves peut être désactivé dans les Réglages.
  - L'identifiant sert à afficher l'élève. Pour se connecter, l'élève utilise toujours son code.
- **Réinitialiser tous les codes et télécharger la sauvegarde (.desanonim)** (bouton rouge en haut de la page Classes) :
  - Tous les élèves de tous les établissements reçoivent un nouveau code, après une confirmation. Le travail des élèves est conservé.
  - La sauvegarde `.desanonim`, avec les nouveaux codes, est téléchargée et toutes les fiches sont prêtes à imprimer.
- **Liste globale** (« Télécharger la liste ») : un seul fichier pour tous les établissements et toutes les classes, avec les colonnes Établissement ; Classe ; Groupe ; Numéro ; Identifiant ; Nom ; Code ; Besoins.
- **Groupes** (section « Groupes » de la page de la classe) :
  - « Diviser la classe en deux groupes » crée GR1 et GR2. Vous pouvez renommer ou ajouter des groupes.
  - Chaque élève est dans un seul groupe. Le bouton « Répartir par ordre alphabétique » propose une répartition moitié / moitié, à vérifier puis enregistrer.
  - **Emploi du temps** : un cours peut être pour la classe entière ou pour un groupe (« 4C · GR1 »).
  - **Plans de classe** : un plan par groupe, indépendant de celui de la classe entière. Un élève qui change de groupe quitte le plan de son ancien groupe.
  - **Suivi** : un filtre par groupe est disponible.
  - **Liste globale** : une colonne « Groupe » est ajoutée.
  - Supprimer un groupe retire le groupe à ses élèves, et ses cours deviennent ceux de la classe entière.
- **Renommer une classe** (bouton à côté du titre de la classe) :
  - Les codes déjà distribués restent valables : ils gardent l'ancien préfixe. Les nouveaux codes prennent le nouveau nom.
  - Les ateliers et parcours réservés à cette classe sont mis à jour.
  - Chaque ordinateur range lui-même ses noms, PAP… et codes sous le nouveau nom à la prochaine visite. Un ancien fichier CSV chargé plus tard est lui aussi rangé sous le nouveau nom.
- **Codes de connexion** (page de la classe) : le serveur ne garde qu'une empreinte des codes et ne peut donc pas les réafficher.
  - « Télécharger la liste » produit un seul fichier à jour pour toutes les classes : noms, codes et PAP / PPS / PAI. Rechargé ailleurs, il restaure tout.
  - Votre navigateur garde une copie des codes créés ou importés sur cet ordinateur. Charger un fichier de codes (CSV) avec « Charger mon fichier de noms » restaure aussi cette copie.
  - Avec cette copie, vous pouvez **réimprimer les fiches** ou **retélécharger le CSV** à tout moment. Les codes gardés ici sont vérifiés auprès du serveur par leur empreinte.
  - Si des codes manquent ou ne sont plus valables : « Nouveaux codes pour les élèves sans code ici » ou « Nouveaux codes pour toute la classe ». Le travail des élèves est conservé.
- **Accueil** (première page de l'espace enseignant) :
  - Calendrier de la semaine, avec tous les établissements réunis. Chaque cours prend la couleur de son établissement ; les cours annulés sont barrés et les vacances hachurées. Une ligne marque l'heure actuelle, et ◀ ▶ change de semaine.
  - Les 8 prochains cours.
  - **Les cours aujourd'hui**, sous « Voir une classe » : la grille horaire du jour (du premier au dernier cours, par établissement), avec pour chaque cours la classe, la salle et l'heure de fin. Le créneau en cours est surligné, les cours passés sont estompés et les cours annulés barrés. Un clic affiche le plan de classe de ce cours dans la vue (la salle vide si personne n'est placé) ; un second clic revient au cours en cours. Le widget se replie (réglage gardé sur cet ordinateur).
  - **Feuille de séance** (comme une feuille d'appel), sous les cours du jour : un tableau avec tous les élèves de la séance affichée, une ligne chacun, en deux groupes de colonnes. Le widget rappelle la classe, le cours, le statut de sa feuille et la **note de la classe** pour ce cours (corrigée, sinon moyenne des élèves présents ; « — » tant que rien n'est noté).
    - **En tête** : une grosse case **Feuille terminée** (aussi en bas de la feuille, les deux suivent ensemble ; la décocher rouvre la feuille), puis :
      - **Atelier papier** : le document **FicheStudio** présenté le plus récemment à cette classe (ou à ce demi-groupe), d'après l'avancement de la correction que FicheStudio enregistre sur la Centrale, avec l'endroit où la classe s'est arrêtée (🏁 fin du cours, feuille, étape). **Correction en cours →** ouvre FicheStudio dans le même onglet, **sur la classe du cours** (le demi-groupe) et ce document, et lance la correction à l'étape où la classe s'était arrêtée (et au repère 📍). Si le document n'a été présenté qu'à l'autre groupe, ce groupe part du début. Sans document, **Choisir dans FicheStudio →** ouvre l'espace Présentation avec la classe choisie (☁ Ouvrir… pour prendre le document). Dans FicheStudio, **↩ Feuille d'appel** (barre de présentation et espace Présentation) enregistre l'avancement et ramène à la feuille de séance de ce cours (`/admin/#feuille=c<classe>g<groupe>&seance=…` ; sinon le cours du jour de la classe, sinon la classe). Liens `appli.php/fichestudio/#presenter=<document>&classe=<cible>&lancer=correction&seance=…`, calculés par `fiche_en_cours()` (`lib/admin_accueil.php`) ; le document doit être rangé sur la Centrale (☁ Enregistrer dans FicheStudio), sinon un avertissement le rappelle.
      - **Sur la plateforme** (pour information) : l'atelier sur lequel le plus d'élèves ont travaillé en dernier ces 3 dernières semaines (à défaut, un atelier ouvert pour la classe aujourd'hui), et combien d'élèves y sont (`atelier_en_cours()` dans `lib/reperes.php`).
    - **Statut** (dans « Les cours aujourd'hui », à côté de chaque cours, et dans le widget) : **à venir**, **● à remplir** (cours en ce moment, rien de noté), **! à remplir** (cours passé, rien de noté), **✎ commencée** (au moins un élève, un incident ou la classe noté) ou **✓ terminée** (case « Feuille terminée » cochée). D'après ce qui est noté sur cet ordinateur. Un clic sur le statut d'un cours affiche son plan et ouvre sa feuille.
    - **En classe** (noté par vous, enregistré sur la Centrale par numéro d'élève) : petite photo (agrandie au survol), **Absent**, **Retard** (l'un ou l'autre), **Exclusion de cours** (case ; le motif, facultatif, s'écrit au survol comme pour les critères, et une pastille blanche « C » signale qu'il est rempli ; l'élève passe « hors de la classe » pour la journée et la note de séance compte −2), **Oubli matériel** (case), puis **Cahier/classeur**, **Comportement/bavardage**, **Participation** (et les autres critères choisis) : une pastille par case (résumé au survol, fenêtre d'évaluation au clic : voir **Évaluer un critère**), avec la pastille blanche « C » bleu marine quand il y a des commentaires, puis **À séparer** (voir plus bas). Colonnes étroites : chaque en-tête porte le nom en grand à la verticale et l'abrégé en petit dessous (Abs., Ret., Oubli, Cahier, Comport., Particip., Term., 1er coup, Temps, Dern., Rep.), le détail au survol. Au départ, tout le monde est présent et « comme d'habitude » : on ne coche que ce qui ressort ; chaque case s'enregistre aussitôt et le plan 3D, l'appel, le bilan et la fiche suivent. Résumé en haut : absents, retards, oublis, + et −.
    - **Plateforme** (calculé par la Centrale, `statistiques_classe()` dans `lib/reperes.php`) : d'abord l'**avancement** (part des activités déjà abordées par la classe que l'élève a terminées, en jauge) et l'activité **en cours** (questions faites sur le total, titre au survol), puis activités **terminées**, réussite du **1er coup**, **temps actif**, **dernière** activité, **repères** (icônes, détail au survol). En vert ou en rouge par rapport à la médiane de la classe (au survol).
    - **Couleurs par gravité** (feuille, légende, pastilles du plan 3D ; `PlanSalle.GRAVITE`) : **jaune** pour le léger (retard, bavardage/comportement, participation, dissipation), **orange** pour ce qui est à surveiller (cahier non tenu, oubli de matériel, matériel, rangement), **rouge** pour le grave (exclusion, altercation, sécurité) ; « −− » dans un ton plus foncé. Absent est en **gris bleu** : une information, pas un comportement. Chaque critère porte sa gravité (`CRITERES[k].gravite`).
    - **Photo** : au survol (ou au toucher), une grande photo flottante se pose sur la vignette, avec le nom, sans être coupée par le tableau.
    - **Mise en page** : en haut, le titre, la classe, le cours et le nombre d'élèves à gauche, la case **Feuille terminée** à droite ; la barre de l'atelier en trois colonnes alignées (libellé, en cours, bouton). En bas, le résumé de la séance sur une ligne avec la note de la classe, et la même case.
    - La ligne survolée (ou dont une case est ouverte) ressort nettement, avec une barre à gauche, pour bien se repérer.
    - **Tablette, smartphone** : on touche la pastille, la photo ou 💬 au lieu de survoler (cibles agrandies) ; sur smartphone, la feuille prend tout l'écran, la colonne Élève reste visible quand on fait défiler vers la droite, et le choix du niveau comme le commentaire s'ouvrent en bandeau en bas de l'écran.
    - La case en haut à gauche (au-dessus de « Élève ») réunit le **résumé de la séance** (absents, retards, exclusions, oublis, + et −, interruption), **⚡ Interruption** (intervention, exercice incendie, confinement, autre, avec une précision : pour toute la séance, marquée ⚡ sur les graphiques), **ⓘ Légende des icônes** (fenêtre par-dessus le tableau : chaque icône de la feuille, et la règle de chaque repère de la plateforme) et le bouton **Critères…**.
    - **Entre élèves** (colonne Sép.) : un clic ouvre un panneau pour noter une **💬 dissipation** (bavardages, chahut ensemble), une **⚔️ altercation** (les deux : à séparer) ou une **🤝 bonne dynamique** (travaillent bien ensemble : à garder ensemble) avec **un ou plusieurs** camarades, avec une précision facultative. Les incidents déjà notés sont listés avec leurs élèves : × retire un élève, **＋ ajouter** en ajoute d'autres (un clic sur chaque élève), ✕ supprime l'incident (deux clics). La case montre 💬 ou ⚔️ et le nombre de camarades à séparer (🤝 s'il n'y a que de bonnes dynamiques), avec qui au survol ; celle de la rangée Classe ouvre tous les incidents de la classe et permet d'en noter un entre n'importe quels élèves. Enregistré sur la Centrale avec le suivi de classe. Sur la page **Plan de classe**, chaque incident compte pour toutes ses paires d'élèves : un encadré au-dessus de la liste signale ceux qui sont assis **côte à côte** (même table, en face ou en diagonale : trait rouge sur le plan à plat) ou **tout proches** (trait orange pointillé), et le **placement aléatoire** fait plusieurs tirages pour garder celui qui les éloigne le plus. Les bonnes dynamiques ne comptent pas dans « À séparer » ni dans le placement aléatoire. Ni l'encadré ni les liens ne s'impriment.
    - **Liens entre élèves sur le plan** (accueil en 3D, page Plan de classe à plat ou en 3/4) : un **arc** relie deux élèves notés ensemble, avec un rond à son sommet (💬 ⚔️ 🤝 et le nombre de fois) — **rouge** altercation, **jaune** bavardage/dissipation, **vert** bonne dynamique ; trait plus épais quand c'est noté plusieurs fois, et, à plat, plus marqué (rouge plein) ou pointillé quand deux élèves à séparer sont assis côte à côte ou tout proches. Au **survol d'un élève**, seuls ses liens restent visibles. Dans « Repères des élèves » (accueil), la case **Liens entre élèves** les cache ou les montre (réglage gardé sur cet ordinateur). Calculé par `liensClasse` / `liensPlaces` (`plan.js`) et `liensEleves` (`plan3d.js`).
    - **Note de la séance** (colonne Note) : calculée pour chaque élève — moyenne de ses critères, un critère non noté comptant « comme d'habitude » (0) et un oubli de matériel −1 — et affichée de −2 à +2 (« = » : comme d'habitude). Au survol, la même bulle que les critères permet de la **corriger** (−− à ++, ✎ et liseré jaune), de revenir au calcul (**Auto**) et d'ajouter un commentaire.
    - **Rangée « Classe »** en tête : absents, retards, oublis, moyenne de chaque critère, **note de la classe** (moyenne des élèves présents, corrigeable de la même façon) et médianes de la plateforme.
    - **Évolution** : un clic sur « Classe » ou sur le nom d'un élève ouvre le graphique de toutes les séances (note calculée ou corrigée, séances d'absence marquées A, séances interrompues ⚡, détail au survol de chaque point, moyenne et dernière note).
    - **Détail de la plateforme** : un clic sur une case de la partie Plateforme ouvre le détail de l'élève (avancement, activité en cours, activités, 1er coup, temps actif, dernière activité, chacun comparé à la classe) et ses repères avec leur règle.
    - **Critères…** : Travail/autonomie, Matériel (soin, utilisation), Travail en équipe, Rangement, Sécurité, Usage du numérique, en plus des trois de base. Dans la fiche de l'élève, onglet **Observations** : niveaux de la séance, commentaire par critère, cumul et dernières séances. Tout reste sur cet ordinateur (`centrale-observations`, `centrale-criteres`), sauvegarde `.desanonim` comprise.
  - **Repères des élèves** : pastilles de chaque élève sur le plan (accueil et page Plan de classe, en 3D comme à plat), en **deux rangées** qu'on ne mélange jamais : la **plateforme** (☁ puis les repères) **au-dessus de la photo**, ce qui est noté **en classe sous le nom** (sous la photo si le nom a dû passer au-dessus) ; aux couleurs de la gravité de la feuille de séance, jamais imprimées :
    - **Observé en classe** (sous le nom, ronds et cartons) : ce que l'enseignant note, enregistré sur la Centrale — appel de la séance (A absent en gris bleu, R en retard en jaune), notes de la séance (C−, Ca−−… en jaune, orange ou rouge selon le critère), exclu (⇥ rouge ; sorti de l'établissement : gris), absences répétées (A+, 3 ou plus à l'appel), retards répétés (R+, 2 ou plus), cartons d'observation et récompenses.
    - **Sur la plateforme** (au-dessus de la photo, ☁ puis carrés ; `lib/reperes.php`) : calculé d'après l'activité dans les ateliers, en comparant l'élève à sa classe — ▲ en avance (au moins 1,5 fois la médiane des activités terminées), ▼ en retard dans le travail (60 % ou moins), ! en difficulté (moins de 40 % du 1er coup ou 3 essais par question), ? regarde souvent les corrections, ⏱ se connecte 8 min ou plus après son groupe, ∅ peu de connexions (deux fois moins de jours que la classe), Z plus d'activité depuis 2 semaines alors que la classe travaille, – jamais connecté.
    - Le bouton « Repères des élèves » déplie les deux légendes, chacune avec sa case pour la cacher.
  - **Fiche de l'élève** (clic sur un élève du plan, ou page Plan de classe) : en-tête fixe (photo, nom, code), menu d'onglets **Résumé · Observations · Appel · Plateforme** qui fait défiler le contenu et suit la partie affichée ; les onglets « en classe » sont jaunes, celui de la plateforme bleu. La fiche passe au-dessus des boutons de l'accueil et garde sa position après un clic (appel, observation).
    - **Évaluer un critère** : au **survol** d'une pastille, une **bulle résumé** (critère, élève, niveau, commentaires de la séance) ; au **clic**, une **fenêtre d'évaluation** : les cinq niveaux (−− gros souci · − à revoir · = comme d'habitude · + bien · ++ très bien ; re-cliquer sur le niveau choisi l'efface), avec **au-dessus de chacun son paquet de cartons** (−− · − aux couleurs de la gravité du critère, plus foncé pour −− ; + · ++ en vert) : replié, le premier carton et leur nombre ; **déplié sur place au survol** (tous les cartons en entier, par-dessus les boutons, dans une **liste défilante** qui ne dépasse jamais de la fenêtre ; une ombre en bas signale qu'il reste des cartons plus bas) ; sur tablette, le premier toucher déplie le paquet, le second choisit un carton. Dessous, les **commentaires de la séance**. Un carton « − » / « + » met le critère de ce côté (sans toucher au niveau s'il y est déjà), un carton « −− » / « ++ » le met toujours à « −− » / « ++ » ; son motif s'ajoute aux commentaires. Cartons par critère (`MOTIFS_CRITERES` dans `plan.js`) — comportement : bavardage à voix haute, hors de son groupe, parle sans demander la parole, ne lève pas la main avant de prendre la parole, interrompt le prof quand il parle, crie sans raison, se lève sans autorisation, se lève sans raison, sans autorisation / −− : insolent envers l'adulte, refuse les consignes, perturbe toute la classe, jette un projectile dans la classe, jette un projectile derrière mon dos, propos blessants, gestes dangereux ; participation : ne travaille pas sur son atelier, ne participe pas, surfe sur l'ordinateur pendant la correction / −− : refuse de participer, aucun travail, aucun travail malgré les relances ; cahier : non tenu, classeur incomplet, ne colle pas les feuilles distribuées, ne prend pas la correction / −− : pas de cahier, cahier perdu ou abîmé ; et les « + » / « ++ » (calme et sérieux, comportement exemplaire, travail remarquable, moteur dans le groupe…). Les cartons marqués **👥** (bavardage à voix haute, bavardage hors de son groupe, propos blessants, gestes dangereux ; et en « + » / « ++ » : aide ses camarades, travaille calmement avec son voisin, bonne entente avec ses camarades, travaille bien en binôme, bonne dynamique de groupe) ouvrent d'abord **« avec qui ? »** : les élèves de la classe en pastilles (photo, nom), à cocher ; **Valider**, **Sans préciser** ou Annuler. Le commentaire enregistre les **numéros** (« Bavardage hors de son groupe avec n° 11, n° 13 »), jamais les noms ; à l'écran, les numéros s'affichent avec le nom (gardé sur cet ordinateur). Bavardage → **chaque camarade choisi reçoit aussi le carton** (« [09:12] [−] Bavardage à voix haute avec n° 21 », son niveau passe à la moyenne de ses cartons, sa pastille et sa note suivent) et les élèves sont notés **« À séparer »** (dissipation) ; propos blessants, gestes dangereux → altercation ; les cartons « + » / « ++ » avec des camarades → **« Bonne dynamique »** (lien vert sur le plan), et ceux qu'on partage (travaille calmement avec son voisin, bonne entente, travaille bien en binôme, bonne dynamique de groupe) sont aussi donnés aux camarades choisis, comme le bavardage (`MOTIFS_AVEC`, `MOTIFS_POUR_TOUS` dans `plan.js`). Pareil depuis la fiche de l'élève. Fenêtre compacte, sans pied : en-tête (photo, élève · n°, critère · classe · séance sur une ligne), les niveaux et leurs paquets, puis les commentaires ; au-dessus de « = », la **moyenne des cartons** (« moyenne de 3 cartons », et « niveau choisi à la main » s'il diffère). Fermeture : ×, Échap ou clic à côté. Smartphone : la fenêtre s'ouvre en bas de l'écran.
    - **Commentaires horodatés, niveau = moyenne des cartons** : chaque commentaire porte son **heure** ; un carton y garde son **niveau** (pastille −− · − · + · ++ aux couleurs du critère), un commentaire écrit est marqué ✎. Ils peuvent se contredire au fil du cours (« bavarde » à 9 h 05, « calme et sérieux » à 9 h 40) : le niveau du critère est la **moyenne de tous les cartons** de la séance (arrondie), recalculée quand on en ajoute, en modifie ou en retire un ; **✎ sur chaque ligne** pour la modifier sur place : son niveau (−− · − · ✎ commentaire écrit · + · ++) et son texte (l'heure reste) ; pour un carton **avec des camarades**, le bouton **👥 … — changer** rouvre la fenêtre de sélection (élèves déjà cochés) : pour un bavardage, le camarade retiré perd le carton répercuté, le camarade ajouté le reçoit (même heure) et est noté « À séparer » ; les autres modifications (niveau, texte) ne concernent que l'élève de la fenêtre ; la fenêtre l'indique (« moyenne des 3 cartons (−0,7) »), et dit si le niveau a été choisi à la main. Enregistré dans le même texte : « [09:12] [−] Bavardage hors de son groupe avec n° 11 ; [09:40] [+] Calme et sérieux ».
    - **Commentaires qui s'accumulent** pendant le cours (fenêtre d'évaluation et fiche de l'élève) : un champ pour en ajouter un, validé par **↵** (qui apparaît dès qu'on écrit) ou Entrée ; dessous, la liste (dépliée dans la fenêtre ; dans la fiche, repliée « ▸ 3 commentaires · le dernier »), chaque commentaire avec × pour le retirer. Chaque carton cliqué s'ajoute à la liste ; le même carton une nouvelle fois compte « (×2) ». Ils sont enregistrés ensemble pour le critère et la séance (séparés par « ; »).
    - **Les cartons sont devenus des motifs** des observations de séance : sous chaque critère de la fiche de l'élève (et dans la fenêtre d'évaluation de la feuille), des motifs prêts à l'emploi, en **petits cartons comme avant**, de part et d'autre des niveaux : **À revoir** (jaune, orange ou rouge selon la gravité du critère) à gauche de −−, **Bien** (vert) à droite de ++ (sur smartphone : les niveaux en haut, les deux paquets dessous), chaque paquet **replié à la hauteur des boutons** (le premier carton, sur une ligne, et le nombre de motifs en pastille) ; au survol, les autres cartons s'ouvrent dans une petite liste flottante, sans agrandir le popup (toujours ouverte sur écran tactile) — comportement : bavardage à voix haute, hors de son groupe, parle sans demander la parole, crie, se lève sans permission / calme et sérieux ; participation : ne travaille pas sur son atelier, ne participe pas / travail sérieux, efforts, participe activement ; cahier : non tenu, classeur incomplet, fiches non collées / soigné ; etc. (`MOTIFS_CRITERES` dans `plan.js`). Un clic met le critère à « − » ou « + » (s'il n'est pas déjà de ce côté) et ajoute le motif au commentaire de la séance. Les cartons donnés avant restent visibles dans la fiche (« Anciens cartons », avec retrait) et sur le plan.
  - Sur tablette et grand écran, la **colonne de gauche** de l'accueil (alerte, à valider, classes, cours du jour, appel, repères) est un menu qui défile et s'arrête au-dessus de Calendrier / Monitoring.
  - **L'appel** se fait dans la feuille de séance (cases Absent, Retard) ; sur le plan, pastille rouge « A » ou orange « R » au-dessus de l'étiquette de l'élève. L'appel est rangé par séance (date + heure du cours ; la journée si le plan n'est pas celui d'un cours). La fiche de l'élève (clic sur l'élève, ou page Plan de classe) montre sa présence à la séance (Présent / Absent / En retard) et le relevé de ses absences et retards. L'appel est enregistré sur la Centrale avec le reste du suivi de classe (voir **Suivi de classe sur la Centrale**).
  - **Suivi de classe sur la Centrale** : tout ce que vous notez en classe — appel, critères et notes de séance, commentaires, exclusions, interruptions, case « Feuille terminée », incidents « À séparer », anciens cartons, statuts (exclu, sorti), critères choisis — est enregistré **sur la Centrale**, rangé par classe et **numéro d'élève** (table `suivi_classe`, `api/suivi.php`, réservé à la session enseignant). On le retrouve sur tous ses ordinateurs (récupéré à l'ouverture d'une page, au retour sur l'onglet et chaque minute), et il est sauvegardé avec le serveur.
    - **Restent dans le navigateur** : les noms, les photos (trombinoscopes), les codes de connexion et les PAP/PPS/PAI/PPRE.
    - **Contrôle des noms** : un nom d'élève écrit dans un commentaire (motif, précision, incident) est remplacé par son numéro avant d'être gardé et envoyé (« bavarde avec Léa Martin » → « bavarde avec n° 07 », « n° 03 (4F) » pour une autre classe), avec un message qui le signale. La comparaison se fait dans le navigateur, avec les noms chargés sur cet ordinateur.
    - **Hors ligne** : chaque changement est gardé ici et attend dans une file (`centrale-suivi-attente`) ; il repart tout seul quand la Centrale répond, avec un bandeau tant qu'il n'est pas parti. Sur la page de connexion, rien n'est envoyé (il partira après connexion).
    - **Première ouverture** après la mise à jour : ce qui était déjà noté sur cet ordinateur part une fois sur la Centrale, sans écraser ce qu'elle a déjà. « Oublier » efface la copie locale ; le suivi reste sur la Centrale et revient à la prochaine connexion. La sauvegarde `.desanonim` contient toujours tout (utile pour les noms et les photos).
  - Le récapitulatif des connexions : élèves connectés depuis 1 h, aujourd'hui et sur 7 jours, sessions ouvertes, histogramme des 7 derniers jours, connexions par classe et par atelier, 10 dernières connexions.
- **Code couleur des classes** : une pastille avec le nom court de la classe en bleu marine.
  - Couleur de la pastille selon le niveau : jaune pour les 4ᵉ, vert pour les 5ᵉ, rouge pour les 3ᵉ (bleu clair pour les 6ᵉ).
  - Liseré de la couleur de l'établissement, choisie dans l'onglet Établissements (champ « Couleur »).
  - On retrouve ces pastilles dans les classes, l'emploi du temps, l'accueil et le récapitulatif des connexions.
- **Classes** : les classes rangées par établissement, avec le nombre d'élèves et l'espace utilisé. C'est aussi là que se trouvent l'import Pronote, la liste globale et la réinitialisation des codes.
- **Réglages** : ressources des ateliers, « Brancher une application », clé secrète des codes, sites autorisés, quotas.
- **Classe** : codes, élèves (numéro, nom affiché localement, dernière connexion, espace), **export ZIP** de tout le travail, **effacer le travail** (fin d'année : les codes restent valables), dépôts Nuage facultatifs.
- **Travail d'un élève** : données et fichiers par atelier, téléchargement, historique avec restauration.
- **Ressources des ateliers** : déposez des fichiers (modèles 3D, consignes, images) que les élèves d'un atelier peuvent charger.

---

## 8. Données et RGPD

Sur le serveur (en France, chez alwaysdata) :

- classe et **numéro** de l'élève (aucun nom) ;
- **empreinte** du code (SHA-256 ; le code n'est jamais stocké) et jetons de session (empreintes) ;
- date de dernière connexion ;
- travail sauvegardé par les applications et fichiers envoyés ;
- avancement dans les activités (statut, étapes, scores, détails envoyés par l'application) ;
- **suivi de classe** noté par l'enseignant, par numéro d'élève : appel, observations de séance et commentaires (noms remplacés par le numéro), incidents, cartons, statuts ;
- avancement de la correction projetée avec FicheStudio (par classe et document).

Les noms, les photos et les PAP/PPS/PAI restent dans le navigateur de l'enseignant et dans son fichier CSV. Ce sont des données **pseudonymisées**, ce qui est un bon niveau de protection. Pensez tout de même à mentionner ce traitement au DPO de votre académie et à effacer le travail des classes en fin d'année.

**Sauvegardes** : alwaysdata sauvegarde automatiquement chaque jour. Exportez aussi le ZIP de chaque classe dans votre Nuage de temps en temps, ou au moins en fin de trimestre.

---

### Clé secrète des codes

L'empreinte d'un code élève est calculée ainsi : `HMAC-SHA256(clé secrète, SHA-256(code))`.

- La clé est créée automatiquement sur le serveur, dans `~/centrale-cle/cle_secrete.txt`. Ce fichier est hors du site web, hors de la base `data/`, hors du déploiement et hors de GitHub.
- Une copie de la base seule ne permet donc pas de retrouver les codes en les essayant hors ligne.
- Le passage à la clé s'est fait sans regénérer les codes : chaque ancienne empreinte a été convertie.
- **Copie de secours** : Réglages → « Clé secrète des codes » → Télécharger. Rangez-la dans un dossier privé du Nuage.
- Si la clé est perdue, une alerte s'affiche partout dans l'espace enseignant. Collez alors la copie de secours dans « Restaurer la clé ». Sans copie, il reste « Créer une nouvelle clé », puis il faut réinitialiser tous les codes.

## 9. Déploiement automatique depuis GitHub

À chaque `push` sur `main`, GitHub copie le code sur alwaysdata (`.github/workflows/deploy.yml`). Le dossier `data/` n'est jamais touché.

1. `ssh-keygen -t ed25519 -f ~/centrale_deploiement -N ""`
2. `ssh-copy-id -i ~/centrale_deploiement.pub VOTRECOMPTE@ssh-VOTRECOMPTE.alwaysdata.net`
3. Sur GitHub, dans **Settings → Secrets and variables → Actions** :
   - `ALWAYSDATA_COMPTE` : le nom du compte ;
   - `ALWAYSDATA_SSH_KEY` : le contenu de `~/centrale_deploiement` (clé privée).

**Version en cours** : le déploiement écrit `version.json` à la racine (commit, date du commit, message, heure de mise en ligne ; hors de la racine web, pas dans git). La barre du bas de l'espace enseignant l'affiche (« Centrale 1.0 · version 7455bf7 du … », détails au survol, lien vers le commit), avec le commit de chaque copie d'application (fichier `.copie` des miroirs). Sur une copie locale sans `version.json`, elle lit le dépôt git et l'indique « locale ».

---

## 10. Référence de l'API

Toutes les requêtes, sauf `login.php`, `catalogue.php`, `atelier-fichier.php` et `compilation.php` (état), demandent l'en-tête `X-Jeton` reçu à la connexion.

| Requête | Rôle |
|---|---|
| `POST login.php` `{code, app}` | → `{classe, eleve, jeton, expire, espace, depot}` |
| `POST deconnexion.php` | Invalide le jeton |
| `GET donnees.php?app=&cle=` | → `{existe, valeur, maj}` |
| `GET donnees.php?app=` | → `{cles: [{cle, taille, maj}]}` |
| `POST donnees.php` `{app, cle, valeur, base?, age?}` | Enregistre (2 Mo max par clé). 409 si `base` est plus ancien que le serveur ; 410 `{remis: true}` si la version (`age` en ms) date d'avant une remise à zéro de l'élève |
| `POST donnees.php` `{app, cle, supprimer: true}` | Efface |
| `GET fichiers.php?app=` / `?app=&nom=` | Liste / télécharge |
| `POST fichiers.php` (multipart `app`, `fichier`, `nom?`) | Envoie (remplace le fichier du même nom) |
| `POST fichiers.php` `{app, nom, supprimer: 1}` | Supprime |
| `GET ressources.php?app=` / `?app=&nom=` | Ressources de l'atelier |
| `POST progression.php` `{app, activite, titre?, statut?, faites?, total?, score?, score_max?, details?, age?}` | Avancement (le meilleur est gardé) ; ignoré (`ignore: true`) s'il date d'avant une remise à zéro de l'élève |
| `GET progression.php?app=` | Avancement de l'élève (`app=*` : tous les ateliers) |
| `POST activites.php` `{app, activites: [{id, titre, groupe?, total?}]}` | Catalogue des activités de l'atelier |
| `GET parcours.php` | Parcours de la classe avec l'avancement de l'élève |
| `GET ateliers.php` | Ateliers proposés à la classe, avec l'avancement et les activités |
| `GET session.php?app=` | Vérifie un jeton (passage d'une application à l'autre) |
| `GET catalogue.php?app=&niveau=&competence=` | Ateliers importés depuis les applications, avec l'adresse qui les ouvre (sans connexion, sans donnée d'élève) |
| `GET atelier-fichier.php?app=` | Ateliers transférés par la Centrale (dossier `ateliers/<app>/`) : `{format: "ateliers.centrale", version: 1, ateliers: [{id, format, version, titre, fichier, …}]}` (sans connexion, CORS ouvert) |
| `GET atelier-fichier.php?app=&id=` | Le fichier de l'atelier, tel quel (format de l'application, avec `format`, `version`, `id`) ; ETag, `X-Version` |
| `GET compilation.php` | État du compilateur des cartes (sans connexion) |
| `POST compilation.php` `{carte, code}` | Compile pour `uno`, `nano`, `esp32` (C++) ou `microbit` (MicroPython) → `.hex` / `.bin` ; `{enCours, travail}` au-delà de 25 s |
| `GET compilation.php?travail=` | Résultat d’une compilation longue |
| `GET suivi.php?depuis=` (session enseignant) | Suivi de classe modifié depuis `depuis` (ms) → `{lignes: [{type, classe, cle, valeur, maj}]}` |
| `POST suivi.php` `{lignes, initial?}` (session enseignant) | Enregistre le suivi de classe (`valeur` null : effacé ; `initial` : sans écraser) |

Codes d'erreur : 400 (demande incomplète), 401 (non connecté ou session expirée), 403 (site non autorisé), 409 (conflit), 413 (trop gros), 429 (trop d'essais de codes), 507 (espace de l'élève plein).

## 11. Compilation des cartes (TechnoStudio)

`api/compilation.php` compile les programmes des cartes pour les applications (TechnoStudio) : Arduino Uno et Nano, ESP32 et micro:bit (MicroPython). Le compilateur lui-même (arduino-cli, cœurs, MicroPython) est installé **hors du site**, dans `~/technostudio-compilation/`, par le workflow **Chaîne de compilation** (`.github/workflows/compilation.yml`). Ce workflow utilise les mêmes secrets que le déploiement, tourne chaque nuit et à la demande (Actions → Run workflow), et compile un essai par carte.

- Cartes installées : Arduino Uno, Nano, ESP32 (C++, cœur 2.0.17) et micro:bit. La chaîne arduino-cli est préparée sur la machine GitHub puis copiée, car alwaysdata interrompt arduino-cli pendant l'installation des cœurs.
- ESP32 en C++ : environ 700 Mo de plus, installé par défaut (hébergement de 100 Go). Pour s'en passer, mettre la variable de dépôt `COMPILATION_ESP32 = non` (Settings → Secrets and variables → Actions → Variables) et relancer le workflow.
- Accès : élève connecté (`X-Jeton`) ou clé enseignant (`X-Cle`, lisible dans `~/technostudio-compilation/config.json`).
- Limite : 15 compilations par minute et par élève, une compilation à la fois.
- Code et détails : dépôt [TechnoStudio](https://github.com/grandiosian/TechnoStudio), dossier `serveur-compilation/`.

