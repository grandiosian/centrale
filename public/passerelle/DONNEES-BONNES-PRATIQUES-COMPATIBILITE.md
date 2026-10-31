# Données, bonnes pratiques et compatibilité entre applications

> Document de référence publié par la Centrale (`/passerelle/DONNEES-BONNES-PRATIQUES-COMPATIBILITE.md`, lisible sur `/passerelle/?doc=donnees`).
> Il vaut pour toutes les applications de technologie : NetSim, Établi 3D, TechnoStudio et les suivantes.
> Il complète la [spécification du descripteur](./) (`centrale.json`) et le document [Compétences](./?doc=competences).

Ce document fixe ce que chaque application publie, enregistre et échange, pour que les applications et la Centrale se comprennent sans adaptation au cas par cas. Quand une application s'en écarte, c'est elle qu'on corrige, pas la Centrale.

---

## 1. Principes

1. **Sans serveur d'abord.** Une application est une page statique (GitHub Pages, avec ses copies sur la Forge et sur ce serveur). Elle marche entièrement sans la Centrale : ouvrir un atelier, le faire, garder son travail.
2. **Les ateliers sont créés dans l'application et publiés avec elle.** Ils sont dans son code ou dans son dossier `ateliers/`, servis avec la page. Ils restent donc disponibles même si la Centrale est en panne, déplacée ou absente.
3. **Le travail de l'élève reste sur son poste.** Il est enregistré dans le navigateur. La seule copie de secours obligatoire est le **dépôt Nuage d'urgence** : l'élève enregistre un fichier et le dépose dans le dossier Nuage de sa classe (§ 9). Quand la Centrale est là, elle reçoit en plus la progression, les constats et une copie du travail : c'est un confort, jamais une condition.
4. **La Centrale range, elle ne crée pas.** Elle importe les ateliers publiés par les applications (§ 4) pour les ranger dans la page Ateliers, les relier aux compétences, aux parcours, à la bibliothèque et au suivi. Elle n'est jamais la seule à détenir un atelier.
5. **Identifiants stables.** Application, atelier, activité, clé de données, nom de fichier attendu : on en ajoute, on n'en renomme pas. Le travail et le suivi des élèves y sont rattachés.
6. **Rien de personnel.** Pas de nom d'élève hors du poste. L'élève est un numéro dans une classe ; seule la Centrale gère les codes de connexion.
7. **Compatible dans les deux sens.** Chaque fichier dit son format et sa version ; les champs inconnus sont conservés à la réécriture et ignorés à la lecture.

## 2. Vocabulaire

| Mot | Sens | Exemple |
|---|---|---|
| Application | un logiciel, une adresse, un identifiant `app` | `netsim`, `etabli3d`, `technostudio` |
| Atelier | une unité de travail créée dans une application, ouverte d'un clic | « Brancher la salle » (NetSim), « La lampe de poche » (TechnoStudio), « Premiers pas » (Établi 3D) |
| Activité | ce que la Centrale suit (progression, constats) ; un atelier en a une ou plusieurs | `e5-1` ; `charte-lampe-de-poche/P1` (une feuille) |
| Parcours | enchaînement d'étapes écrit par l'enseignant, dans une ou plusieurs applications | « Portail automatique » : modéliser → programmer → tester |
| Constat | ce que l'application a vérifié pour une compétence | `SFC15.5.1`, réussi, 1 indice |
| Descripteur | `centrale.json` : ce que l'application est, envoie et enregistre | |
| Catalogue | `ateliers.json` : la liste des ateliers de l'application | |

Dans l'espace enseignant, la page **Ateliers** a deux onglets : « Applications du portail » (les logiciels proposés aux élèves) et « Ateliers des applications » (les ateliers importés).

## 3. Ce que chaque application publie

À la racine de son site, à côté de sa page d'accueil, et à la même place sur ses copies :

| Fichier | Format | Statut | Rôle |
|---|---|---|---|
| `centrale.json` | `passerelle.atelier` v1 | obligatoire | identité, activités, progression, données, fichiers échangés ([spécification](./)) |
| `ateliers.json` | `catalogue.ateliers` v1 | obligatoire | liste des ateliers ([schéma](catalogue-ateliers.schema.json), [modèle](modele-ateliers.json)) |
| `ateliers/<id>.json` | propre à l'application | si les ateliers sont des fichiers | contenu complet d'un atelier (TechnoStudio) |
| `PASSERELLE.md` | texte | recommandé | ce que l'application fait de ses données |

Ces fichiers sont **produits à partir du code** de l'application (script de construction ou page d'outil), jamais écrits à la main, et un test vérifie qu'ils correspondent à ce que l'application fait réellement. C'est ce que font déjà l'outil `verifier-descripteur.html` de NetSim et d'Établi 3D et `tools/centrale-json.js` de TechnoStudio.

## 4. Le catalogue des ateliers (`ateliers.json`)

```json
{
  "format": "catalogue.ateliers", "version": 1,
  "app": "netsim", "app_version": "1.6.0", "maj": "2026-10-31", "referentiel": "technologie-c4-2024",
  "ateliers": [
    { "id": "e5-1", "titre": "Brancher la salle", "niveau": "5e", "duree": "20 min",
      "resume": "Relier quatre ordinateurs à un switch et vérifier qu’ils communiquent.",
      "competences": ["SFC15.5.1"],
      "activites": [{ "id": "e5-1", "titre": "Brancher la salle", "total": 5, "competences": ["SFC15.5.1"] }],
      "ouvrir": "e5-1", "version": 1 }
  ]
}
```

| Champ | Obligatoire | Règle |
|---|---|---|
| `id` | oui | stable, lettres, chiffres, `_ . / -`, 80 caractères au plus |
| `titre` | oui | 120 caractères au plus, en français, compréhensible par un élève |
| `niveau` | non | `6e`, `5e`, `4e`, `3e`, `lycee`, ou vide pour tous niveaux |
| `resume` | non | une ou deux phrases (600 caractères au plus) |
| `duree` | non | « 20 min », « 2 séances », ou un nombre de minutes |
| `competences` | conseillé | codes du référentiel ([Compétences](./?doc=competences)) |
| `activites` | conseillé | identifiants suivis par la Centrale, avec `titre`, `total`, `competences` ; un atelier simple a une activité de même identifiant |
| `ouvrir` | non | activité à ouvrir (`e5-1` → `#activite=e5-1`), ou lien relatif propre à l'application qui commence par `#` ou `?` (`#at-lampe`) |
| `fichier` | non | contenu de l'atelier publié avec l'application, relatif à `ateliers.json` |
| `version` | non | augmente à chaque modification du contenu de l'atelier |
| `auteur` | non | équipe ou établissement, jamais un élève |

Un atelier qui disparaît du catalogue n'est pas effacé dans la Centrale : il passe « retiré », car du travail d'élève peut y être rattaché.

`centrale.json` peut indiquer un autre emplacement : `"ateliers": { "catalogue": "data/ateliers.json" }` (relatif à `centrale.json`).

## 5. La procédure d'import dans la Centrale

**Pour l'enseignant** : espace enseignant → Ateliers → onglet « Ateliers des applications ».

1. « Importer les ateliers de toutes les applications », ou « Importer » / « Mettre à jour » en face d'une seule.
2. La Centrale lit `ateliers.json` à l'adresse de l'application (à défaut, sur ses copies de secours si l'adresse principale ne répond pas).
3. Si l'application ne publie pas encore `ateliers.json`, la Centrale relit son `centrale.json` et regroupe ses activités en ateliers : champ `atelier` de l'activité s'il existe, sinon ce qui précède le dernier `/` (`charte-lampe-de-poche/P1` → `charte-lampe-de-poche`), sinon une activité = un atelier. C'est signalé « pas encore de ateliers.json ».
4. Le bilan s'affiche : ajoutés, modifiés, retirés, inchangés. Les activités rejoignent le catalogue des activités (bibliothèque, parcours, colonnes du suivi).
5. Le tableau se filtre par application, niveau, et par texte ou code de compétence (`SFC15`). « Ouvrir » lance l'atelier dans son application, sans connexion.

**Importer un fichier** (même onglet) : un atelier exporté d'une application (`.json`), un `ateliers.json`, ou un atelier TechnoStudio (`.technostudio.json`, codes T1/T2/T3 convertis). Le fichier est gardé dans la Centrale avec la mention « pas encore dans l'application » et un bouton « Fichier » pour le récupérer : il ne sera disponible sans serveur qu'une fois ajouté aux ateliers publiés par l'application.

**Pour les autres applications** : `GET <Centrale>/api/catalogue.php?app=&niveau=&competence=` renvoie les ateliers importés (sans jeton, sans donnée d'élève), avec pour chacun l'`adresse` qui l'ouvre dans son application. Une application peut ainsi proposer « pour aller plus loin, l'atelier X dans NetSim ».

## 6. Ouvrir un atelier par son adresse

| Adresse | Qui l'utilise | L'application doit |
|---|---|---|
| `…#activite=<id>` | catalogue, liens entre applications | ouvrir l'activité, **même sans la Centrale** |
| `…#centrale=<jeton>&retour=<portail>&activite=<id>` | portail et parcours de la Centrale | laisser le module lire le jeton (avant toute autre lecture du `#`), puis ouvrir `Centrale.activiteDemandee()` |
| lien propre (`#at-<id>`, `?exo=<id>`) | l'application elle-même | le déclarer dans `ouvrir` s'il diffère de `#activite=` |

Le `#` n'est jamais envoyé au serveur d'hébergement : rien de ce qu'il contient n'apparaît dans ses journaux.

## 7. Enregistrer dans le navigateur

- **Clés préfixées par l'application** : `netsim-v1`, `netsim-progress`, `etabli3d.autosave`, IndexedDB `technostudio`. Jamais de clé générique (`projet`, `save`).
- **Une version dans la valeur** (`{ "version": 2, … }`) ou dans la clé (`-v1`). Une nouvelle version **lit encore l'ancienne** et la convertit : l'élève ne perd jamais son travail en changeant de version.
- `localStorage` pour les petites valeurs (moins de 1 Mo), IndexedDB au-delà. Chaque lecture et écriture est protégée (`try/catch`) : navigation privée, quota plein, stockage bloqué.
- Écrire **tout de suite** à chaque modification (regroupé sur quelques centaines de millisecondes), pas seulement à la fermeture.
- Les préférences d'affichage (thème, panneaux) sont séparées du travail.

## 8. Échanger des fichiers entre applications

Pour les parcours en plusieurs logiciels (dossier de projet de la Centrale), et pour les échanges sans serveur (fichier enregistré puis rouvert) :

| Type | Extension | Règle |
|---|---|---|
| Projet d'une application | `.json` | objet avec `app` (l'identifiant) et `version` (du format) en tête ; lisible par l'application qui l'a produit, de version en version |
| Résumé lisible par les autres | `resume.json` | `{ app, titre, quantites…, maj }` : ce qu'une autre application ou l'enseignant peut lire sans connaître le format complet |
| Pièce 3D | `.stl` (binaire), `.glb` | millimètres, Z vers le haut |
| Image | `.png` | aperçu, 2 000 px au plus de côté |
| Programme | `.ino`, `.py` | texte UTF-8 |
| Atelier TechnoStudio | `.technostudio.json`, `.gift` | format décrit dans le dépôt TechnoStudio |

- Une application reconnaît un fichier à son **extension** et à son champ `app`, pas à son nom : l'enseignant nomme les fichiers d'une étape comme il veut.
- Le descripteur déclare ce qu'elle **reçoit** et **produit** (`fichiers.recoit`, `fichiers.produit`, motifs `*.json` acceptés) ; la Centrale vérifie l'enchaînement des étapes avec ces déclarations.
- Avant de quitter une étape, l'application dépose ses sorties (`Centrale.init({ avantDeQuitter })`), du plus petit au plus gros.
- Taille : 2 Mo au plus par clé de données ; un fichier déposé, 20 Mo par défaut (réglage de la Centrale), dans le quota de l’élève (100 Mo par défaut).

## 9. Le dépôt Nuage d'urgence

C'est la seule sauvegarde hors du poste qui doit toujours marcher, avec ou sans Centrale.

1. L'application sait toujours **enregistrer le travail dans un fichier** (bouton « Enregistrer un fichier ») et le rouvrir.
2. Elle propose **« Déposer sur Nuage »**, qui ouvre le dossier de dépôt de la classe :
   - le lien donné par la Centrale si l'élève est connecté (`Centrale.ouvrirDepot()`, lien réglé par l'enseignant pour la classe, page de la classe → Dépôts Nuage) ;
   - sinon un lien que l'enseignant a collé une fois dans l'application (réglage gardé dans le navigateur du poste).
3. Le nom du fichier contient le numéro de l'élève et l'atelier (`5A-07-e5-1.json`), jamais son nom.

## 10. Échanger avec la Centrale (quand elle est là)

- Toujours par le module **`centrale-client.js`** de la Centrale, jamais par des appels directs à l'API (seule exception : `api/catalogue.php`, en lecture).
- Charger le module sans bloquer la page : s'il ne se charge pas, l'application continue comme avant.
- `Centrale.progression(id, { titre, faites, total, statut, constats })` : la Centrale garde le meilleur résultat ; les constats suivent [Compétences](./?doc=competences).
- `Centrale.sauver(cle, valeur)` : copie du travail, 2 Mo par clé ; un conflit (409, travail plus récent ailleurs) se règle en relisant et en fusionnant.
- Hors ligne, le module garde les envois en file d'attente et les renvoie au retour du réseau.

## 11. Valeurs communes

| Quoi | Règle |
|---|---|
| Texte | UTF-8, en français, ton simple et direct (élèves de collège) |
| Dates | millisecondes depuis 1970 dans les données (`maj: 1761900000000`) ; `AAAA-MM-JJ` dans les catalogues |
| Unités | millimètres, newtons, mégapascals, secondes, volts, ampères |
| Niveaux | `6e`, `5e`, `4e`, `3e`, `lycee` |
| Classe | celle de la Centrale (`5A`) ; son premier chiffre donne le niveau |
| Compétences | codes du référentiel (`SFC15.5.1`) |
| Identifiants | minuscules, chiffres, `-`, `_` (et `/` pour une activité dans un atelier) |

## 12. Versions et compatibilité

- **Version de l'application** (`1.6.0`) : elle augmente à chaque changement de ce qu'elle publie (`centrale.json`, `ateliers.json`). `atelier.version` de `centrale.json` et `app_version` de `ateliers.json` sont la même valeur.
- **Version d'un format** (`passerelle.atelier` 1, `catalogue.ateliers` 1, format de projet) :
  - un ajout facultatif ne change pas la version ;
  - une rupture (champ renommé, sens changé) passe à la version suivante, et les lecteurs continuent de lire l'ancienne au moins une année scolaire.
- **Version d'un atelier** : elle augmente quand son contenu change ; son `id`, ceux de ses activités et de ses questions ne changent pas.
- **Lecture tolérante** : un champ inconnu est ignoré ; un atelier ou un fichier d'une version plus récente est lu s'il n'y a pas d'erreur bloquante, sinon refusé avec un message clair.

## 13. Liste de contrôle avant de publier une application

1. Elle s'ouvre et fonctionne sans la Centrale (module injoignable).
2. `centrale.json` et `ateliers.json` sont produits depuis le code, vérifiés par un test, et leurs versions sont à jour.
3. Chaque atelier s'ouvre par `#activite=<id>` sans connexion, et par le portail avec connexion.
4. Le travail est enregistré dans le navigateur, se met dans un fichier et se rouvre ; « Déposer sur Nuage » marche.
5. Aucun identifiant n'a été renommé ; une ancienne sauvegarde se rouvre.
6. Les compétences et les constats suivent [Compétences](./?doc=competences).
7. Après la mise en ligne : Centrale → Ateliers → « Ateliers des applications » → « Mettre à jour », et vérifier le bilan.

## 14. État des applications (31 octobre 2026)

Les trois applications suivent ce document.

| | NetSim 1.7.0 | Établi 3D 1.3.0 | TechnoStudio 3.3.0 |
|---|---|---|---|
| `centrale.json` produit et vérifié | oui | oui | oui |
| `ateliers.json` produit et vérifié | oui, `NetSim.ateliers()` + `outils/verifier-descripteur.html` | oui, `Etabli3D.ateliers()` + `npm test` | oui, `tools/centrale-json.js` (refait à chaque publication) |
| Ateliers | 18 exercices | 39 tutos, exercices, exemples | ateliers intégrés + dossier `ateliers/` des enseignants |
| Ouvrir par `#activite=` sans connexion | oui | oui | oui (`<atelier>` ou `<atelier>/<feuille>`) |
| Compétences déclarées et constats | oui (indices, essais) | oui (indice, niveau de la classe) | oui (niveau d'après le barème) |
| Fichier de travail et « Déposer sur Nuage » | Exporter le schéma | Fichier → Déposer sur Nuage | Fichier → Enregistrer / Ouvrir mon travail, Déposer sur Nuage |
| Lien Nuage gardé sur le poste | `netsim-nuage` | `etabli3d.nuage` | `ts-nuage` |

Dans TechnoStudio, les ressources déposées sur la Centrale restent lues à la connexion, en attendant que les ateliers des enseignants soient publiés dans `ateliers/` (voir `ateliers/LISEZMOI.md` du dépôt TechnoStudio).

Une nouvelle application suit la liste de contrôle du § 13 ; ensuite : Centrale → Ateliers → « Ateliers des applications » → « Importer ».
