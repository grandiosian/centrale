# Créer un atelier, de la fiche papier aux élèves

Cette procédure suit un atelier **TechnoStudio**, le cas le plus vaste. Pour NetSim et Établi 3D, les ateliers sont écrits
dans le code de l'application (voir la fin). Pour voir comment un atelier est rangé, à n'importe quelle étape :
Centrale → **Ateliers** → **Inspecter** (dans « Imports et disponibilité », ou « Détail » d'un atelier → « Inspecter les données »).

## 1. Comment un atelier est rangé

Un atelier existe à trois endroits. Seul le premier est la référence ; les deux autres en sont tirés automatiquement.

| Où | Quoi | Qui l'écrit |
|---|---|---|
| **Le fichier de l'atelier**, dans l'application : `TechnoStudio/ateliers/<id>.technostudio.json` | Tout l'atelier : fiche pédagogique, compétences, feuilles, blocs, images | L'éditeur de TechnoStudio (bouton **Publier**) |
| **Le catalogue de l'application** : `ateliers.json` (et `centrale.json`) | Une fiche courte par atelier : titre, niveau, durée, compétences, liste des activités | La publication de l'application, jamais à la main |
| **Le catalogue de la Centrale** (table `catalogue_ateliers`) | Une copie de cette fiche, pour ranger, proposer, mettre au calendrier, suivre | Le bouton **Mettre à jour** de la page Ateliers |

Dans le fichier TechnoStudio, un atelier contient :

- **en-tête** : `id`, `title`, `niveau` (5e…), `seq` (n° AT SEQ, sert au tri), `duration` (min), `summary`, `problematique`, `objet`, `version` ;
- **compétences de la fiche** : `T2C3` → sous-compétences `T2C31` → lien `ref` vers le référentiel commun (`SFC31.5.1`…). **Sans `ref`, la Centrale ne reçoit pas la compétence** ;
- **fiche pédagogique** : `presentation`, `deroulement` (séances), `synthese`, `evaluation`, `materiel`, `prof` (jamais montré aux élèves) ;
- **feuilles** (`steps`) : chacune a un type — **P** feuille d'activité, **R** ressources, **S** synthèse, **E** évaluation (SEGPA : `S·P1`) — un numéro de séance et des **blocs** : texte, image, ressource, QCM, texte à trous, tableau, circuit, programme Arduino / micro:bit, robot mBot / Maqueen…

**Une activité, pour la Centrale, est une feuille qui contient au moins un bloc noté.** Son identifiant est
`<id de l'atelier>/<code de la feuille>`, par exemple `programmer-robots-mbot-maqueen/P1`. C'est à lui que sont rattachés
le travail des élèves, les étapes une à une, les parcours et les activités demandées.

## 2. Les règles qui protègent le travail des élèves

1. **Ne jamais changer l'identifiant de l'atelier** une fois publié.
2. **Ne pas renuméroter les feuilles déjà utilisées.** Le code d'une feuille P vient de son numéro de feuille ; celui d'une
   feuille R, S ou E vient de son **ordre** : insérer ou déplacer une feuille R/S/E change les codes suivants.
   On ajoute de nouvelles feuilles **à la fin**.
3. **Ne pas changer l'identifiant d'un bloc** (`p01-q`…) : la progression de l'élève dans la feuille y est rattachée.
4. **Chaque pastille de compétence d'une question** (`T3C31`) doit exister dans les compétences de la fiche, avec son lien `ref`.
5. Augmenter **`version`** à chaque nouvelle publication (ce n'est pas automatique).

## 3. Créer l'atelier

### Étape A — Sur papier (facultatif)
Fiches et présentation dans **FicheStudio** (Centrale → Ateliers → « ＋ Ajouter un atelier ») ou dans Affinity.
FicheStudio **n'exporte pas** vers TechnoStudio : on en tire un **PDF**, qui sert de point de départ à l'étape B.

### Étape B — Dans TechnoStudio (profil Enseignant)
1. Compte → Mon profil → **Enseignant**.
2. Fichier → **Mes ateliers (créer, publier)** → **Nouvel atelier**, ou **Convertir une fiche papier** (PDF ou photos : le
   texte est extrait, les pages sont jointes ; la structure P/R/S/E et les compétences sont à refaire), ou **Dupliquer** un atelier existant.
3. Remplir la **Fiche** : titre, niveau, durée, problématique, compétences (avec leur lien au référentiel), déroulement, matériel.
4. Ajouter les **Feuilles** : une par page papier, avec son type (P/R/S/E), sa séance et son numéro.
5. **Insertion** : les blocs de chaque feuille ; une question notée reçoit ses pastilles de compétences.
   L'atelier s'enregistre tout seul dans le navigateur, et l'éditeur signale ce qui n'est pas conforme.
6. Essayer l'atelier en élève (lecteur), feuille par feuille.

> L'éditeur donne à un nouvel atelier un identifiant au hasard (`at-k3j9x2ab`) et ne permet pas de le changer.
> Pour un identifiant lisible (`radar-recul-robot-mbot`), le changer **avant le premier dépôt** : ouvrir le fichier
> téléchargé à l'étape C1 dans un éditeur de texte, remplacer la valeur de `"id"`, renommer le fichier
> `<nouvel id>.technostudio.json`, puis le réimporter (Fichier → Importer un paquet…) pour continuer à travailler dessus.

### Étape C — Publier avec l'application
1. Fichier → Mes ateliers (créer, publier) → **Publier** sur la ligne de l'atelier : le fichier `<id>.technostudio.json` est téléchargé.
   (Le bouton reste grisé tant que l'atelier n'est pas conforme.)
2. Sur GitHub, dépôt **TechnoStudio**, dossier `ateliers/` : **Add file → Upload files**, déposer le fichier, **Commit changes**.
3. La publication automatique (quelques minutes) vérifie l'atelier, refait `ateliers.json`, `centrale.json` et le fichier
   unique, puis met le site à jour. Une erreur dans le fichier bloque toute la publication : regarder l'onglet **Actions** de GitHub.
4. **Centrale** : pousser la Centrale (un commit vide suffit) pour mettre à jour sa copie de TechnoStudio (miroir).

### Étape D — Dans la Centrale
1. **Ateliers** → « Imports et disponibilité » → TechnoStudio → **Mettre à jour**.
2. **Inspecter** l'atelier : vérifier l'identifiant, les activités (P1, P2…), les compétences reliées et le fichier publié.
3. Le proposer : **Calendrier** de la classe (date et nombre de séances), **Parcours**, ou activités demandées.
4. **Tester à la place d'un élève** (Applications → Tester) : vue Élève pour les verrous, vue Enseignant pour tout voir.

## 4. Modifier un atelier déjà publié

Sur un atelier publié, le bouton **Dupliquer et modifier** en fait une **copie avec un nouvel identifiant** (« ma version ») :
la publier crée un autre atelier, sans le travail des élèves. Pour garder l'identifiant :

1. Récupérer le fichier publié (Centrale → Inspecter → lien « Fichier complet », ou GitHub `ateliers/`).
2. TechnoStudio → Fichier → **Importer un paquet…** avec ce fichier : l'atelier garde son identifiant, et le bouton devient **Modifier**.
3. Modifier, en respectant les règles du §2, augmenter `version`, puis **Publier**.
4. Sur GitHub, redéposer le fichier **sous le même nom** (il remplace l'ancien), puis étapes C3, C4 et D1.

Sur l'ordinateur de l'enseignant, la copie locale d'un atelier passe avant la version publiée : après publication, supprimer
la copie locale (Mes ateliers) pour voir ce que voient les élèves.

## 5. Avant de dire « c'est fini »

- [ ] L'atelier s'ouvre dans TechnoStudio sans connexion (`#activite=<id>`) et en lien seul (`?atelier=<id>`).
- [ ] Centrale → Inspecter : identifiant lisible, une activité par feuille notée, aucune compétence « sans lien au référentiel ».
- [ ] `version` augmentée ; aucune feuille déjà utilisée renumérotée.
- [ ] Testé à la place d'un élève, vue Élève (étapes une à une) et vue Enseignant.
- [ ] Images raisonnables : elles sont dans le fichier (au-delà de 1 à 2 Mo, les postes de la salle le chargent lentement).

## 6. NetSim et Établi 3D

Leurs ateliers (exercices, tutos) sont écrits **dans le code** de l'application, avec leurs identifiants. On les ajoute dans
le dépôt de l'application (voir son `CLAUDE.md`), qui produit seule `ateliers.json` et `centrale.json` ; ensuite étapes C4 et D.

## 7. Pour aller plus loin

- Format complet d'un atelier TechnoStudio : `TechnoStudio/docs/descripteur-ateliers.md` (produit depuis le code).
- Ce que chaque application publie : [Données et compatibilité](./?doc=donnees) · [Spécification](./) · [Compétences](./?doc=competences).
