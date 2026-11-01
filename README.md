# Centrale élèves

Serveur unique pour toutes les applications de technologie :

- **Connexion** des élèves par un code personnel du type `5A-dfgdfkj-orei-flgdf`.
- **Sauvegarde et chargement automatiques** du travail de chaque élève, par atelier : l'élève retrouve son travail sur n'importe quel ordinateur.
- **Pseudonymisation** : le serveur ne connaît que « 5A · élève 07 ». Les noms restent sur l'ordinateur de l'enseignant.
- **Progression et suivi** : chaque application signale l'avancement de l'élève ; tableau élèves × activités pour toute la classe.
- **Parcours** : enchaînement d'étapes dans plusieurs logiciels (simulation → modélisation → programmation → questionnaire).
- **Portail élève** (page d'accueil) : l'élève tape son code une fois, voit ses **ateliers** et ses parcours, ouvre un atelier (ou directement une activité) déjà connecté, puis revient au portail avec « ← Mes ateliers ».
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

### Passerelle : le descripteur `centrale.json`

Chaque application publie à la racine de son site un fichier `centrale.json` au format **`passerelle.atelier` v1**. Il décrit ce qu'elle déclare, envoie et enregistre :

- l'atelier : identifiant, nom, adresse, version ;
- ses activités ;
- les champs de progression ;
- les clés de données ;
- les fichiers qu'elle reçoit ou produit ;
- les sites à autoriser.

La spécification, le schéma JSON et un modèle vierge sont publiés par la Centrale à l'adresse **`/passerelle/`** (dossier `public/passerelle/`).

Dans l'onglet **Ateliers**, la carte « Brancher une application par sa passerelle » prend l'adresse de l'application. La Centrale lit son `centrale.json` côté serveur, puis :

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

- Chaque atelier a **quatre emplacements**, réglables dans l'onglet Ateliers (bouton « Modifier ») :
  - **Local** : la copie servie par cette Centrale, `/miroir/<nom>/` (rempli par défaut), ou un dossier local (`http://localhost:8080/…`) ;
  - **GitHub Pages** (ex. `https://grandiosian.github.io/NetSim/`) ;
  - **GitLab Pages**, sur la Forge ÉdNat (ex. `https://lionelcaruana.forge.apps.education.fr/netsim/`) ;
  - **Serveur alwaysdata** (ex. `https://technoversailles.alwaysdata.net/miroir/netsim/`).
  Des « autres adresses » (une par ligne) peuvent s'ajouter ; elles sont essayées après.
- **Ordre de préférence** (carte « Ordre des emplacements », en bas de l'onglet Ateliers) :
  - Centrale **en local** (localhost, 127.0.0.1, adresse 192.168… / 10…, nom en .local) : local → GitHub → GitLab → alwaysdata ;
  - Centrale **en ligne** : alwaysdata (la copie `/miroir/…` de ce serveur) → GitHub → GitLab (le local est ignoré : les élèves ne peuvent pas joindre l'ordinateur du professeur).
  La détection est automatique ; on peut forcer « en local » ou « en ligne », et changer chaque ordre.
- La Centrale vérifie elle-même quelles adresses répondent (cache de 5 minutes ; la copie `/miroir/` est vérifiée directement sur le disque). Le portail, les propositions, les parcours et « Tester comme élève » ouvrent **le premier emplacement qui répond** ; si aucun ne répond, la première adresse publique. Les liens des étapes de parcours (souvent vers GitHub) sont redirigés de la même façon. Dans l'onglet Ateliers, ● veut dire que l'adresse répond, ○ qu'elle ne répond pas, – qu'elle n'est pas utilisée ici ; « ← ouvert par le portail » montre le choix actuel.
- À la mise à jour, les anciennes « adresse principale » et « adresses de secours » sont rangées automatiquement dans les emplacements. L'import d'un `centrale.json` (`url`, `urls_secours`) remplit aussi les emplacements vides.
- **Copie de secours sur ce serveur** : chaque application listée dans `miroirs.txt` est copiée dans `public/miroir/<nom>/`, servie à `https://VOTRECOMPTE.alwaysdata.net/miroir/<nom>/` (NetSim, TechnoStudio et Établi 3D). Une ligne peut ajouter le dossier à publier et la commande qui l'assemble : `etabli3d https://github.com/grandiosian/etabli3d dist python3 build.py`. En local, `techno-local.sh miroirs` fait de `public/miroir/*` des liens vers les clones : l'emplacement « Local » ouvre alors la copie de l'ordinateur.
- Une application ouverte depuis une copie `…/miroir/<nom>/` se connecte à **la Centrale qui la sert** (NetSim, TechnoStudio, Établi 3D) : depuis une Centrale locale, la progression et les fichiers sont enregistrés en local. Ouverte depuis GitHub ou GitLab, elle utilise la Centrale en ligne (ou `window.CENTRALE_URL`).
  - La copie est mise à jour à chaque déploiement et toutes les heures, par le workflow GitHub.
  - Si une copie échoue, rien n'est envoyé et le serveur garde la version précédente.
- Les applications fonctionnent aussi **sans la Centrale**, avec un enregistrement dans le navigateur. Si la Centrale est injoignable, le travail est gardé localement et renvoyé à son retour.

### D'où se connectent les élèves

À chaque ouverture d'atelier, la Centrale note l'hébergement d'où l'application a été chargée : GitHub, Forge, ce serveur… Elle ne stocke pas d'adresse IP, et garde 120 jours d'historique.

- **Onglet Ateliers** : nombre d'ouvertures sur 7 jours par hébergement.
- **Page Travail d'un élève** : ses 30 dernières connexions, avec l'atelier, l'hébergement, et s'il est passé par le portail ou a tapé son code.

### Bibliothèque des activités

L'onglet **Bibliothèque** de l'espace enseignant réunit toutes les activités de toutes les applications : celles déclarées par les passerelles (`centrale.json`) et celles déclarées à la connexion des élèves.

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
  - dans la carte de chaque atelier, seules les activités proposées et celles déjà commencées apparaissent. Un réglage en bas de la Bibliothèque permet de les montrer toutes ;
  - dans l'application elle-même, l'élève peut toujours tout ouvrir.

### Élèves test : tester à la place d'un élève

Onglets **Ateliers** et **Parcours**, carte « Tester à la place d'un élève » :

- **Créer les classes de test** : 5TEST et 4TEST, 3 élèves fictifs chacune. Le chiffre du nom donne le niveau. Leurs codes s'affichent une fois, pour essayer aussi la connexion par code. Toute classe nommée `TEST`, `5TEST`, `4 TEST`… compte comme classe de test.
- **Choisir un élève test**, puis :
  - **Ouvrir son portail ↗** : nouvel onglet connecté comme cet élève (2 h, sans code), avec ses ateliers, ses parcours, son dossier de projet et « Étape suivante », comme en classe ;
  - **Tester ↗** sur une ligne d'atelier : l'atelier s'ouvre directement, connecté ;
  - **Tester ↗** sur une ligne de parcours : son portail, avec le parcours. Si le parcours n'est pas proposé à sa classe, la Centrale le dit.
- L'élève choisi est gardé d'une page à l'autre. Seuls les élèves des classes de test sont proposés : on ne travaille jamais dans le dossier d'un vrai élève.
- **Remettre les élèves test à zéro** : efface leur travail, leur avancement, leurs dossiers de projet et les étapes validées, pour rejouer un parcours depuis le début.

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

### Ateliers des applications (import)

Les ateliers sont créés **dans les applications** et publiés avec elles : ils marchent sans serveur. La Centrale les importe pour les ranger.

- Onglet **Ateliers → « Ateliers des applications »** : « Importer les ateliers de toutes les applications », ou « Importer / Mettre à jour » pour une seule.
- La Centrale lit le catalogue `ateliers.json` publié à côté de `centrale.json`. À défaut, elle regroupe les activités de `centrale.json` en ateliers et le signale.
- Bilan affiché : ajoutés, modifiés, retirés (jamais effacés), inchangés. Les activités rejoignent la bibliothèque, les parcours et le suivi.
- Filtres par application, niveau, texte ou code de compétence ; « Ouvrir » lance l'atelier sans connexion.
- Un fichier exporté d'une application (atelier `.json`, `ateliers.json`, `.technostudio.json`) peut aussi être importé à la main : il reste marqué « pas encore dans l'application » tant qu'elle ne le publie pas.
- Les autres applications lisent ce catalogue : `GET /api/catalogue.php?app=&niveau=&competence=`.

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
- **Classes concernées** : vide = toutes.
- Sur le **portail élève** (`https://VOTRECOMPTE.alwaysdata.net/`), l'élève voit ses parcours, l'étape suivante, et clique sur **Commencer** ou **Continuer**. Le logiciel s'ouvre déjà connecté : le jeton passe dans l'adresse (`#centrale=…`) et le module le retire aussitôt.
- **Bouton « Étape N : titre → »** : le module l'ajoute dans toute application ouverte depuis une étape de parcours, en bas à gauche, à côté de « ← Mes ateliers ».
  - Au clic, il enregistre tout, puis ouvre directement l'étape suivante : la bonne application, la bonne activité, le même dossier de projet, sans repasser par le portail.
  - Si l'étape suivante est verrouillée (« dans l'ordre » ou « après validation »), il affiche un 🔒 et explique pourquoi. En mode « dans l'ordre », quand l'application dépose ses fichiers en partant (Établi 3D), il reste cliquable : il dépose d'abord, puis ouvre l'étape suivante si l'étape est terminée.
  - À la dernière étape, il devient « Parcours terminé → Mes ateliers ».
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
  - On glisse les éléments pour les déplacer. On peut les tourner, les redimensionner, les dupliquer ou leur donner une étiquette, au clavier ou avec la souris.
  - La **disposition rapide** génère des rangées de tables, avec ou sans ordinateurs.
- **Placement d'une classe dans une salle** : on clique sur un élève puis sur une place, ou on le glisse. On peut aussi faire un placement aléatoire, vider la salle, ou imprimer.
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
  - Côté enseignant (colonne « Identifiant ») : un surnom qui ressemble au vrai nom est signalé, et ↻ en donne un autre au hasard. La comparaison se fait sur votre ordinateur.
  - Le changement de surnom par les élèves peut être désactivé dans les Réglages.
  - L'identifiant sert à afficher l'élève. Pour se connecter, l'élève utilise toujours son code.
- **Réinitialiser tous les codes et télécharger la liste** (bouton rouge en haut de la page Classes) :
  - Tous les élèves de tous les établissements reçoivent un nouveau code, après une confirmation. Le travail des élèves est conservé.
  - La liste globale est téléchargée et toutes les fiches sont prêtes à imprimer.
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
- avancement dans les activités (statut, étapes, scores, détails envoyés par l'application).

Les noms restent dans le navigateur de l'enseignant et dans son fichier CSV. Ce sont des données **pseudonymisées**, ce qui est un bon niveau de protection. Pensez tout de même à mentionner ce traitement au DPO de votre académie et à effacer le travail des classes en fin d'année.

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

---

## 10. Référence de l'API

Toutes les requêtes, sauf `login.php`, `catalogue.php` et `compilation.php` (état), demandent l'en-tête `X-Jeton` reçu à la connexion.

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
| `POST progression.php` `{app, activite, titre?, statut?, faites?, total?, score?, score_max?, details?}` | Avancement (le meilleur est gardé) |
| `GET progression.php?app=` | Avancement de l'élève (`app=*` : tous les ateliers) |
| `POST activites.php` `{app, activites: [{id, titre, groupe?, total?}]}` | Catalogue des activités de l'atelier |
| `GET parcours.php` | Parcours de la classe avec l'avancement de l'élève |
| `GET ateliers.php` | Ateliers proposés à la classe, avec l'avancement et les activités |
| `GET session.php?app=` | Vérifie un jeton (passage d'une application à l'autre) |
| `GET catalogue.php?app=&niveau=&competence=` | Ateliers importés depuis les applications, avec l'adresse qui les ouvre (sans connexion, sans donnée d'élève) |
| `GET compilation.php` | État du compilateur des cartes (sans connexion) |
| `POST compilation.php` `{carte, code}` | Compile pour `uno`, `nano`, `esp32` (C++) ou `microbit` (MicroPython) → `.hex` / `.bin` ; `{enCours, travail}` au-delà de 25 s |
| `GET compilation.php?travail=` | Résultat d’une compilation longue |

Codes d'erreur : 400 (demande incomplète), 401 (non connecté ou session expirée), 403 (site non autorisé), 409 (conflit), 413 (trop gros), 429 (trop d'essais de codes), 507 (espace de l'élève plein).

## 11. Compilation des cartes (TechnoStudio)

`api/compilation.php` compile les programmes des cartes pour les applications (TechnoStudio) : Arduino Uno et Nano, ESP32 et micro:bit (MicroPython). Le compilateur lui-même (arduino-cli, cœurs, MicroPython) est installé **hors du site**, dans `~/technostudio-compilation/`, par le workflow **Chaîne de compilation** (`.github/workflows/compilation.yml`). Ce workflow utilise les mêmes secrets que le déploiement, tourne chaque nuit et à la demande (Actions → Run workflow), et compile un essai par carte.

- Cartes installées : Arduino Uno, Nano, ESP32 (C++, cœur 2.0.17) et micro:bit. La chaîne arduino-cli est préparée sur la machine GitHub puis copiée, car alwaysdata interrompt arduino-cli pendant l'installation des cœurs.
- ESP32 en C++ : environ 700 Mo de plus, installé par défaut (hébergement de 100 Go). Pour s'en passer, mettre la variable de dépôt `COMPILATION_ESP32 = non` (Settings → Secrets and variables → Actions → Variables) et relancer le workflow.
- Accès : élève connecté (`X-Jeton`) ou clé enseignant (`X-Cle`, lisible dans `~/technostudio-compilation/config.json`).
- Limite : 15 compilations par minute et par élève, une compilation à la fois.
- Code et détails : dépôt [TechnoStudio](https://github.com/grandiosian/TechnoStudio), dossier `serveur-compilation/`.

