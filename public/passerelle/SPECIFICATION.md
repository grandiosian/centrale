# Spécification `passerelle.atelier` — version 1

> **Version de référence**, publiée par la Centrale à l'adresse `https://technoversailles.alwaysdata.net/passerelle/`.
> C'est la Centrale qui lit les descripteurs : la spécification et son schéma vivent ici.
> Le format reprend celui de NetSim et de TechnoStudio : formats des fichiers échangés (`recoit` / `produit`), activités dynamiques, clés à paramètre.

Chaque application branchée sur la Centrale publie un **descripteur d'atelier**. C'est un fichier JSON qui dit à la Centrale qui est l'application et ce qu'elle déclare, envoie et enregistre. Il concerne aujourd'hui NetSim, Établi 3D, Argile et TechnoStudio.

| Fichier | Statut |
|---|---|
| `centrale.json` | **Obligatoire.** Nom, emplacement et structure identiques pour toutes les applications. |
| `PASSERELLE.md` | Recommandé, forme libre. Le même plan est conseillé : 1. descripteur, 2. données, 3. point d'accès JavaScript, 4. évolution, 5. pistes. |
| Point d'accès JavaScript (`window.NetSim`, `window.TechnoStudio`…) | Recommandé : il évite que le code de connexion et le descripteur divergent. Le nom est libre, il est décrit dans `acces_javascript`. |
| Outil de vérification (page, script) | Facultatif, sans rôle pour la Centrale. |

Les fichiers associés :

- [`passerelle-atelier.schema.json`](passerelle-atelier.schema.json) : le schéma JSON 2020-12, qui valide n'importe quel `centrale.json` ;
- [`modele-centrale.json`](modele-centrale.json) : un modèle vierge à copier ;
- [`catalogue-ateliers.schema.json`](catalogue-ateliers.schema.json) et [`modele-ateliers.json`](modele-ateliers.json) : le catalogue des ateliers `ateliers.json` (§ 2.8).

Deux documents communs à toutes les applications complètent cette spécification :

- [Données, bonnes pratiques et compatibilité](./?doc=donnees) (`DONNEES-BONNES-PRATIQUES-COMPATIBILITE.md`) : principes (sans serveur d'abord, ateliers publiés avec l'application, dépôt Nuage d'urgence), fichiers publiés, procédure d'import des ateliers, adresses, stockage, échanges de fichiers, versions ;
- [Compétences](./?doc=competences) (`COMPETENCES.md`) : référentiel, choix des codes, déclaration, constats, bilan.

---

## 1. Emplacement

- Le fichier s'appelle exactement **`centrale.json`**.
- Il est placé **à la racine du site** de l'application, à côté de sa page d'accueil. Exemples :
  - `https://grandiosian.github.io/NetSim/centrale.json`
  - `https://grandiosian.github.io/TechnoStudio/centrale.json`
  - `https://grandiosian.github.io/etabli3d/centrale.json`
- Il est servi tel quel, sans authentification.
- Les copies de secours (miroir, Forge) le publient au même endroit relatif.

## 2. Sections

Toutes les sections obligatoires sont présentes, même vides. Une application peut ajouter ses propres sections, par exemple `compilation` chez TechnoStudio : la Centrale ignore ce qu'elle ne connaît pas.

| Section | Obligatoire | Contenu |
|---|---|---|
| `format` | oui | `"passerelle.atelier"` |
| `version` | oui | `1` |
| `atelier` | oui | `app`, `nom`, `url`, `version` (obligatoires) ; `description`, `urls_secours`, `icone`, `depot`, `langue`, `classes`, `parametres` |
| `activites` | oui | `liste` (obligatoire, éventuellement vide) ; `dynamiques` (facultatif) |
| `progression` | oui | `champs` : sens de `faites`, `total`, `statut`, et de `score`, `score_max`, `details` s'ils sont utilisés |
| `donnees` | oui | une entrée par clé passée à `Centrale.sauver` (`{}` si aucune) |
| `fichiers` | oui | `utilises: false`, ou ce que l'application reçoit, produit et attend en ressources |
| `ateliers` | non | `catalogue` : emplacement de `ateliers.json` s'il n'est pas à côté de `centrale.json` (§ 2.8) |
| `centrale` | non | module chargé, serveur par défaut, sites à autoriser, mode de connexion, fonctions du module utilisées, navigation dans les parcours (`parcours`, § 2.6) |
| `stockage_local` | non | clés gardées dans le navigateur (dépannage) |
| `acces_javascript` | non | `objet` (`window.X`) et `membres` |
| `enums` | non | listes de valeurs et libellés |
| `$defs` | non | schémas JSON 2020-12 référencés par `format` (`{"$ref": "#/$defs/…"}`) |

### 2.1 `atelier`

- `app` : l'identifiant passé à `Centrale.init({ app })`, en minuscules, chiffres, `-` et `_`, 40 caractères au plus. C'est la règle de `normaliser_app` dans la Centrale.
- `url` : l'adresse principale de l'application.
- `version` : la version de l'application, sous forme de texte. Elle **augmente à chaque changement du descripteur**.
- `parametres` (facultatif) : les paramètres d'adresse reconnus par l'application. Exemple : `?app=` permet de créer plusieurs ateliers Centrale qui ouvrent la même application avec des ressources et un suivi séparés.

### 2.2 `activites`

- `liste` : **exactement** ce que l'application envoie à `Centrale.init({ activites })` ou à `declarerActivites`, dans le même ordre. Chaque élément a les champs `id`, `titre`, `groupe` et `total`.
  - `id` : lettres, chiffres, `_ . / -`, 120 caractères au plus. Il est **stable** : jamais renommé, jamais réutilisé.
  - Une application sans activités publie une liste vide.
- `dynamiques` (facultatif) : pour les activités qui dépendent du contenu, par exemple les ateliers que l'enseignant dépose en ressources dans TechnoStudio.
  - `motif` : l'expression régulière de leurs `id`.
  - `description` : texte libre.
  - La Centrale accepte ces activités sans les signaler comme inconnues.

### 2.3 `progression`

Cette section décrit le sens des champs envoyés à `Centrale.progression(id, …)` dans cette application.

- `champs` : un texte par champ envoyé. `faites`, `total` et `statut` sont obligatoires ; `titre`, `score`, `score_max` et `details` sont à décrire s'ils sont utilisés.
- `champs_non_utilises` : facultatif.
- `format` : facultatif, le schéma de ce qui est envoyé.

Rappel du comportement de la Centrale :

- `faites` et `score` ne baissent jamais ;
- `termine` reste acquis ;
- `details` fait 20 Ko au plus.

### 2.4 `donnees`

Une entrée par clé de `Centrale.sauver` / `charger`. Chaque entrée contient :

- `description` (obligatoire) ;
- `format` (obligatoire) : un schéma JSON ou un `$ref` ;
- `sauvegarde`, `chargement`, `taille_typique` (facultatifs, en texte).

**Clés à paramètre** : une clé variable s'écrit avec un nom entre accolades, par exemple `at/{atelier}`, et donne son `motif` (expression régulière des clés réelles, ici `^at/[A-Za-z0-9_.-]+$`). Les caractères permis dans une clé sont ceux de la Centrale : lettres, chiffres, `- _ . /`. Une valeur fait 2 Mo au plus.

### 2.5 `fichiers`

Le format est fixé une fois pour toutes. Il est commun aux ateliers transversaux (`Centrale.projet`), aux fichiers de l'élève et aux ressources de l'enseignant.

```json
"fichiers": {
  "utilises": true,
  "dossier_projet": true,
  "recoit":     [ { "nom": "cahier.json",  "type": "application/json",  "description": "Cahier des charges", "obligatoire": false } ],
  "produit":    [ { "nom": "chassis.glb",  "type": "model/gltf-binary", "description": "Châssis modélisé" } ],
  "ressources": [ { "nom": "*.technostudio.json", "type": "application/json", "description": "Atelier déposé par l'enseignant" } ]
}
```

- `utilises: false` suffit pour une application qui n'échange aucun fichier.
- Les listes portent des objets `{ nom, type, description }`, avec deux champs facultatifs :
  - `obligatoire` (pour `recoit`) : l'application ne peut pas commencer sans ce fichier ;
  - `format` : un schéma, pour un fichier JSON.
- `nom` est le nom exact dans le dossier. `*` désigne une partie variable (`programme-*.ino`), ou n'importe quel nom pour une application qui reconnaît ses fichiers à leur extension (`*.stl`).
- `nom_conseille` (facultatif) : avec un motif, le nom à proposer au professeur (`piece.stl`). La fiche de la passerelle l'affiche.
- `type` est un type MIME.
- `dossier_projet: true` si l'application lit ou écrit dans le dossier de projet (`Centrale.etape`, `Centrale.projet`).
- Les noms de `recoit` et `produit` correspondent aux lignes « reçoit : … » et « produit : … » des étapes de parcours. La Centrale peut ainsi proposer, ou vérifier, l'enchaînement de deux applications.

### 2.6 `centrale.parcours` : passer d'une étape à l'autre

Quand l'élève ouvre une application depuis une étape d'un parcours, le module `centrale-client.js` le sait (`Centrale.contexteAtelier()`) et ajoute en bas à gauche, à côté de « ← Mes ateliers », un bouton **« Étape N : titre → »** :

- au clic, il enregistre tout, puis ouvre directement l'étape suivante : la bonne application, sur la bonne activité, avec le même dossier de projet ;
- si l'étape suivante est verrouillée (mode « dans l'ordre » ou « après validation »), le bouton porte un 🔒 et explique pourquoi au lieu d'ouvrir ;
- exception, en mode « dans l'ordre » : si l'application dépose ses fichiers en partant (`avantDeQuitter`) et que l'étape attend des fichiers (`produit`), le bouton reste cliquable. Le clic dépose, regarde de nouveau, puis ouvre l'étape suivante si l'étape en cours est maintenant terminée (sinon il explique ce qui manque) ;
- à la dernière étape, il devient « Parcours terminé → Mes ateliers » ;
- il se met à jour après chaque `Centrale.progression`, chaque dépôt `Centrale.projet.sauver` et au retour sur l'onglet.

Ce que l'application doit faire pour que cela marche bien :

1. **Tout déposer avant de partir.** Si elle attend avant de déposer (minuteur, bouton « Déposer »), elle passe `avantDeQuitter` à `Centrale.init`. Le module attend cette fonction (15 s au plus) avant « ← Mes ateliers » et « Étape suivante » :
   ```js
   Centrale.init({ app: 'etabli3d', avantDeQuitter: () => deposerProjet() });
   ```
2. **Laisser la place en bas à gauche** (environ 600 × 50 px), ou placer son propre bouton : `Centrale.init({ boutonSuivant: false })`, puis :
   - `await Centrale.etapeSuivante()` renvoie `{ numero, etape, fin, raison }`, ou `null` hors parcours ;
   - `await Centrale.allerEtapeSuivante()` ouvre l'étape, et renvoie `false` si elle est verrouillée.
3. **Signaler la fin de l'étape** (`Centrale.progression(id, { statut: 'termine' })` ou dépôt des fichiers `produit`) : c'est ce qui ouvre l'étape suivante en mode « dans l'ordre ».
4. **Accepter d'être rouvert sur la même page** avec une autre étape : le module recharge la page quand deux étapes de suite sont dans la même application ; `Centrale.etape()` donne alors la nouvelle étape.

Le descripteur le déclare dans `centrale.parcours` :

```json
"parcours": {
  "bouton_suivant": "module",
  "avant_de_quitter": "Dépose le projet (.json) dans le dossier de projet."
}
```

- `bouton_suivant` : `"module"` (bouton du module, par défaut), `"application"` (l'application place le sien et appelle `Centrale.allerEtapeSuivante()`) ou `"aucun"`.
- `avant_de_quitter` : ce que fait la fonction `avantDeQuitter`, ou `""` si l'application enregistre tout immédiatement.

La Centrale affiche ces informations sur la fiche de la passerelle, et signale une application qui utilise le dossier de projet sans `avant_de_quitter`.

### 2.7 Compétences

La Centrale publie le référentiel commun à l'adresse **`/passerelle/competences.json`** (format `referentiel.competences` v1) :

- thèmes `OST`, `SFC`, `CCRI` ;
- compétences (`OST1`) et sous-compétences (`OST11`) ;
- capacités par niveau (`OST11.5.2` = OST11, 5e, capacité n° 2), avec leurs connaissances ;
- l'échelle de maîtrise (1 MI, 2 MF, 3 MS, 4 TBM).

Une application qui évalue des compétences utilise **ces codes**. Ils ne changent jamais.

**Déclarer** les capacités de chaque activité (champ facultatif `competences` de `activites.liste`, et de `Centrale.init({ activites })`) :

```json
{ "id": "e5-1", "titre": "Brancher la salle", "groupe": "5e", "total": 5, "competences": ["SFC15.5.1"] }
```

La Centrale les montre dans l'onglet Compétences (« travaillée dans »). Les codes inconnus sont ignorés.

**Envoyer des constats** avec la progression, au moment où l'application sait juger, par exemple quand un critère est réussi ou que l'activité est terminée :

```js
Centrale.progression('exo-plaque', { statut: 'termine', faites: 3, total: 3,
  constats: [ { competence: 'CCRI16.5.1', reussi: true, indices: 0, essais: 1, preuve: '3 critères sur 3' } ] });
```

- `reussi` (oui / non), `indices` (aides utilisées), `essais` (tentatives) : la Centrale en tire le niveau.
  - Pas réussi : MI.
  - Réussi du premier coup, sans indice : TBM.
  - Au plus 1 indice et 3 essais : MS.
  - Davantage d'aide : MF.
- `niveau` (1 à 4) à la place de `reussi` : l'application fixe elle-même le niveau.
- Chaque constat compte. Il est gardé, même hors ligne : le module les met en file d'attente sans les perdre ni les doubler.

**Bilan** : moyenne haute de tous les constats d'une capacité (ceux de l'enseignant et ceux des applications), c'est-à-dire la moyenne arrondie au niveau supérieur.

### 2.8 Catalogue des ateliers (`ateliers.json`)

Chaque application publie, à côté de `centrale.json`, la liste des ateliers créés chez elle : **`ateliers.json`**, format `catalogue.ateliers` v1 ([schéma](catalogue-ateliers.schema.json), [modèle](modele-ateliers.json)). Les ateliers eux-mêmes sont publiés avec l'application et restent disponibles sans serveur.

```json
{ "format": "catalogue.ateliers", "version": 1, "app": "netsim", "app_version": "1.6.0",
  "ateliers": [ { "id": "e5-1", "titre": "Brancher la salle", "niveau": "5e", "competences": ["SFC15.5.1"],
                  "activites": [{ "id": "e5-1", "titre": "Brancher la salle", "total": 5 }], "ouvrir": "e5-1" } ] }
```

Un autre emplacement se déclare dans `centrale.json` : `"ateliers": { "catalogue": "data/ateliers.json" }` (relatif à `centrale.json`). Les champs et la procédure d'import sont détaillés dans [Données, bonnes pratiques et compatibilité](./?doc=donnees), § 4 et 5.

## 3. Règles

1. **Ce que déclare `centrale.json` correspond à ce que l'application envoie réellement.** Le plus sûr est de produire le fichier à partir du code, comme TechnoStudio, ou de le vérifier contre l'application, comme le fait l'outil de vérification de NetSim.
2. **Identifiants stables** : `atelier.app`, les `id` d'activité et les clés de données ne changent pas. On en ajoute, on n'en renomme pas : la progression et le travail des élèves y sont rattachés.
3. `atelier.version` augmente à chaque changement du descripteur.
4. **Évolution du format** : un ajout facultatif ne change pas `version`. Une rupture passe à `version: 2`, et la Centrale continue de lire la version 1.
5. **Tolérance** : la Centrale ignore les sections et champs inconnus, et une application ne doit pas compter sur leur lecture.
6. **Codes élèves** : ils sont gérés **uniquement par la Centrale**, ce qui couvre leur génération, leur format, leur saisie, leurs messages et leur durée de session. Une application affiche le formulaire du module (`Centrale.afficherConnexion`) ou reçoit le jeton du portail. Elle ne lit, ne contrôle et ne garde aucun code.
7. L'application fonctionne aussi sans la Centrale.

## 4. Ce que la Centrale en fait

- **Onglet Ateliers → « Lire la passerelle »** : à partir de l'adresse de l'application, la Centrale télécharge `centrale.json`, le vérifie, crée ou complète la fiche de l'atelier (nom, description, icône, adresses de secours : une fiche déjà réglée par l'enseignant n'est pas écrasée) et garde une copie du descripteur. Le bouton « Relire » le met à jour après une nouvelle version.
- **Activités** : `activites.liste` est ajoutée au catalogue (table `activites`). Les colonnes du suivi et les identifiants des étapes de parcours sont connus avant la première connexion d'un élève. Les activités déclarées ensuite à la connexion (`Centrale.declarerActivites`) restent prioritaires.
- **Fiche de la passerelle** (`admin/?p=passerelle&app=…`) : activités par groupe, champs de progression, clés de données, fichiers lus / reçus / produits, remarques de vérification, descripteur complet.
- **Sites à autoriser** : chaque `centrale.sites_a_autoriser` absent des origines autorisées est signalé, avec un bouton pour l'ajouter.
- **Parcours** : l'enchaînement des étapes est vérifié avec les `fichiers` déclarés : fichier qu'un atelier ne déclare pas recevoir ou produire (motifs `*` acceptés), fichier reçu que rien ne produit ni ne fournit, atelier qui n'utilise pas encore le dossier de projet (`fichiers.utilises: false` ou `dossier_projet: false`).
- **Navigation dans les parcours** : `centrale.parcours` est affiché sur la fiche de la passerelle (§ 2.6).
- **Ateliers des applications** (onglet de la page Ateliers) : « Importer » lit `ateliers.json` (à défaut, regroupe les activités de `centrale.json`), range les ateliers avec leurs compétences, marque « retiré » ce qui n'est plus publié, et ajoute les activités au catalogue. Ils sont aussi servis aux applications par `GET /api/catalogue.php`.
- **Manifeste `postMessage`** : si l'adresse n'a pas de `centrale.json` mais publie `passerelle/manifest.json` (`"protocol": "passerelle"`), la Centrale en tire seulement la fiche (nom, adresse, description) et le signale.

Pistes non encore faites : valider les valeurs des clés de `donnees` avec leur schéma dans la page Travail, signaler dans le suivi les activités hors `liste` et hors `dynamiques`.

## 5. Valider un descripteur

Avec le schéma : n'importe quel validateur JSON Schema 2020-12. En Node :

```bash
npm i ajv@8
node -e "const A=require('ajv/dist/2020'),f=require('fs');const v=new A({strict:false}).compile(JSON.parse(f.readFileSync('passerelle-atelier.schema.json')));const ok=v(JSON.parse(f.readFileSync('centrale.json')));console.log(ok||v.errors)"
```

Les descripteurs de NetSim, TechnoStudio et Établi 3D sont valides. Établi 3D est la première application qui utilise le dossier de projet : ses noms de fichiers sont des motifs par extension (`*.json`, `*.stl`, `*.png`, `resume.json`) avec un `nom_conseille`, et il déclare `centrale.parcours` (dépôt automatique avant de changer d'étape).
