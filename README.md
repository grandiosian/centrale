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
| `Centrale.progression(activite, infos)` | Signale l'avancement (voir partie 4) |
| `await Centrale.mesProgressions()` | Avancement de l'élève dans cet atelier (pour cocher les activités faites) |
| `Centrale.init({ activites: [...] })` | Liste des activités de l'atelier (colonnes du suivi) |
| `await Centrale.parcours()` | Parcours de la classe avec l'avancement de l'élève |
| `Centrale.lien(url, { activite })` / `Centrale.ouvrir(url, { activite })` | Ouvre une autre application (et une activité) sans retaper le code |
| `Centrale.activiteDemandee()` | Activité demandée par le portail, à ouvrir directement |
| `Centrale.retour()` | Revient au portail (bouton « ← Mes ateliers » ajouté automatiquement) |
| `await Centrale.ateliers()` | Ateliers proposés à la classe, avec l'avancement |

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

- Chaque atelier a une **adresse principale** et des **adresses de secours**, une par ligne : copie sur la Forge, copie sur ce serveur…
- La Centrale vérifie elle-même quelles adresses répondent, avec un cache de 5 minutes. Le portail et les parcours ouvrent la première qui répond. Dans l'onglet Ateliers, ● veut dire que l'adresse répond, ○ qu'elle ne répond pas.
- **Copie de secours sur ce serveur** : chaque application listée dans `miroirs.txt` est copiée dans `public/miroir/<nom>/`, servie à `https://VOTRECOMPTE.alwaysdata.net/miroir/<nom>/`.
  - La copie est mise à jour à chaque déploiement et toutes les heures, par le workflow GitHub.
  - Si une copie échoue, rien n'est envoyé et le serveur garde la version précédente.
- Les applications fonctionnent aussi **sans la Centrale**, avec un enregistrement dans le navigateur. Si la Centrale est injoignable, le travail est gardé localement et renvoyé à son retour.

### D'où se connectent les élèves

À chaque ouverture d'atelier, la Centrale note l'hébergement d'où l'application a été chargée : GitHub, Forge, ce serveur… Elle ne stocke pas d'adresse IP, et garde 120 jours d'historique.

- **Onglet Ateliers** : nombre d'ouvertures sur 7 jours par hébergement.
- **Page Travail d'un élève** : ses 30 dernières connexions, avec l'atelier, l'hébergement, et s'il est passé par le portail ou a tapé son code.

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
Titre de l'étape            | atelier  | activité | adresse du logiciel
Brancher la salle           | netsim   | e5-1     | https://grandiosian.github.io/NetSim/
Modéliser le boîtier        | etabli3d | boitier  | https://grandiosian.github.io/etabli3d/
Programmer le capteur       | blocs    |          | https://…
```

- **Activité vide** = tout l'atelier. L'étape est terminée quand toutes ses activités déclarées le sont.
- **Classes concernées** : vide = toutes.
- Sur le **portail élève** (`https://VOTRECOMPTE.alwaysdata.net/`), l'élève voit ses parcours, l'étape suivante, et clique sur **Commencer** ou **Continuer**. Le logiciel s'ouvre déjà connecté : le jeton passe dans l'adresse (`#centrale=…`) et le module le retire aussitôt.
- Entre vos propres applications, `Centrale.ouvrir(url)` fait la même chose (bouton « Étape suivante », par exemple).

### Tableau de suivi

Onglet **Suivi** : une classe, et au choix un parcours ou un atelier.

- Une ligne par élève, une colonne par activité ou par étape.
- Chaque case indique : ✓ terminé, 3/5 en cours, vide pas commencé.
- On y trouve aussi le pourcentage d'avancement, la dernière activité et le total « terminé par » de chaque colonne.
- Les noms s'affichent depuis votre ordinateur.
- **Exporter (CSV)** produit le tableau avec les noms, à ouvrir dans un tableur.
- La page **Travail** d'un élève détaille son avancement : dates, résultats, réponses envoyées dans `details`.

---

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
- Le **tableau de bord** affiche les cours du jour, avec un accès direct au plan de classe.

### Plans de classe

- **Plan de la salle**, dessiné une seule fois. On y place les tables, les postes informatiques, les places élèves, la paillasse, le bureau, le tableau, la porte et les fenêtres.
  - On glisse les éléments pour les déplacer. On peut les tourner, les redimensionner, les dupliquer ou leur donner une étiquette, au clavier ou avec la souris.
  - La **disposition rapide** génère des rangées de tables, avec ou sans ordinateurs.
- **Placement d'une classe dans une salle** : on clique sur un élève puis sur une place, ou on le glisse. On peut aussi faire un placement aléatoire, vider la salle, ou imprimer.
  - Les noms affichés viennent de votre ordinateur. Le serveur ne garde que « place → élève n° 07 ».

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
- **Réinitialiser tous les codes et télécharger la liste** (bouton rouge en haut du tableau de bord) :
  - Tous les élèves de tous les établissements reçoivent un nouveau code, après une confirmation. Le travail des élèves est conservé.
  - La liste globale est téléchargée et toutes les fiches sont prêtes à imprimer.
- **Liste globale** (« Télécharger la liste ») : un seul fichier pour tous les établissements et toutes les classes, avec les colonnes Établissement ; Classe ; Numéro ; Identifiant ; Nom ; Code ; Besoins.
- **Renommer une classe** (bouton à côté du titre de la classe) :
  - Les codes déjà distribués restent valables : ils gardent l'ancien préfixe. Les nouveaux codes prennent le nouveau nom.
  - Les ateliers et parcours réservés à cette classe sont mis à jour.
  - Chaque ordinateur range lui-même ses noms, PAP… et codes sous le nouveau nom à la prochaine visite. Un ancien fichier CSV chargé plus tard est lui aussi rangé sous le nouveau nom.
- **Codes de connexion** (page de la classe) : le serveur ne garde qu'une empreinte des codes et ne peut donc pas les réafficher.
  - « Télécharger la liste » produit un seul fichier à jour pour toutes les classes : noms, codes et PAP / PPS / PAI. Rechargé ailleurs, il restaure tout.
  - Votre navigateur garde une copie des codes créés ou importés sur cet ordinateur. Charger un fichier de codes (CSV) avec « Charger mon fichier de noms » restaure aussi cette copie.
  - Avec cette copie, vous pouvez **réimprimer les fiches** ou **retélécharger le CSV** à tout moment. Les codes gardés ici sont vérifiés auprès du serveur par leur empreinte.
  - Si des codes manquent ou ne sont plus valables : « Nouveaux codes pour les élèves sans code ici » ou « Nouveaux codes pour toute la classe ». Le travail des élèves est conservé.
- **Tableau de bord** : classes, nombre d'élèves, espace utilisé, ressources des ateliers, réglages (sites autorisés, quotas).
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

## 9. Déploiement automatique depuis GitHub

À chaque `push` sur `main`, GitHub copie le code sur alwaysdata (`.github/workflows/deploy.yml`). Le dossier `data/` n'est jamais touché.

1. `ssh-keygen -t ed25519 -f ~/centrale_deploiement -N ""`
2. `ssh-copy-id -i ~/centrale_deploiement.pub VOTRECOMPTE@ssh-VOTRECOMPTE.alwaysdata.net`
3. Sur GitHub, dans **Settings → Secrets and variables → Actions** :
   - `ALWAYSDATA_COMPTE` : le nom du compte ;
   - `ALWAYSDATA_SSH_KEY` : le contenu de `~/centrale_deploiement` (clé privée).

---

## 10. Référence de l'API

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
| `POST progression.php` `{app, activite, titre?, statut?, faites?, total?, score?, score_max?, details?}` | Avancement (le meilleur est gardé) |
| `GET progression.php?app=` | Avancement de l'élève (`app=*` : tous les ateliers) |
| `POST activites.php` `{app, activites: [{id, titre, groupe?, total?}]}` | Catalogue des activités de l'atelier |
| `GET parcours.php` | Parcours de la classe avec l'avancement de l'élève |
| `GET ateliers.php` | Ateliers proposés à la classe, avec l'avancement et les activités |
| `GET session.php?app=` | Vérifie un jeton (passage d'une application à l'autre) |

Codes d'erreur : 400 (demande incomplète), 401 (non connecté ou session expirée), 403 (site non autorisé), 409 (conflit), 413 (trop gros), 429 (trop d'essais de codes), 507 (espace de l'élève plein).
