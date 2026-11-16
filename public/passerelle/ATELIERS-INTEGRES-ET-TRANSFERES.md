# Ateliers intégrés et ateliers transférés par la Centrale

Règle décidée le 16 novembre 2026. Elle dit **où vit chaque atelier**. Les applications et la Centrale y passent
étape par étape (voir le §5) ; tant qu'un atelier n'est pas passé, il reste où il est aujourd'hui.

## 1. La règle

Chaque application a deux sortes d'ateliers.

| | **Ateliers intégrés** | **Ateliers transférés par la Centrale** |
|---|---|---|
| Ce que c'est | Les **premiers pas**, les fondations pour apprendre l'application | Tous les autres |
| Où ils vivent | Dans le dépôt de l'application, avec son code | Dans le dépôt de la Centrale : `centrale/ateliers/<app>/<id>.json` |
| Panne du serveur | Marchent toujours, même sur un poste neuf | Marchent sur un poste qui les a déjà ouverts (copie gardée par l'application) |
| Qui les modifie | Le code de l'application, puis sa publication | Un fichier de données, déposé dans la Centrale, puis son déploiement |

Les ateliers intégrés sont choisis ainsi :

| Application | Restent intégrés | Passent par la Centrale |
|---|---|---|
| **NetSim** | « Premiers pas », la session libre et **le parcours de 5e** (`e5-…`) | 4e, 3e, lycée, bac pro (`e4-…`, `e3-…`, `e2-…`, `e1-…`, `et-…`, `sb1-…`, `sb2-…`) |
| **TechnoStudio** | Exemple : la lampe de poche (`charte-lampe-de-poche`), Éclairage automatique : mon premier programme (`demo-eclairage-premier-programme`), Circuits série et dérivation (`demo-circuits-serie-derivation`) | Le portail, les parkings, les ateliers lycée, les robots (et tout nouvel atelier) |
| **Établi 3D** | Les 11 tutos (`tuto-…`) | Les exercices (`exo-…`) et les exemples (`ex-…`) |

## 2. Ce qui ne change jamais

- **Les identifiants restent les mêmes** quand un atelier passe de l'application à la Centrale : `e4-1` reste `e4-1`,
  `radar-recul-robot-mbot` reste `radar-recul-robot-mbot`. Le travail des élèves y est rattaché.
- Un atelier n'est retiré du code de l'application qu'**après** que sa version de la Centrale est en ligne, testée,
  et que l'application sait la relire (copie gardée comprise).
- L'application marche sans la Centrale : ses ateliers intégrés et les copies gardées s'ouvrent toujours.
- Rien de personnel dans un atelier ni dans sa copie.

## 3. Un atelier transféré : le fichier

- Rangé dans `centrale/ateliers/<app>/<id>.json`, versionné dans git (historique et sauvegarde), déployé avec la Centrale.
  Jamais dans `data/` (la base de production).
- Un fichier = un atelier, au format de l'application, avec son `format`, sa `version` (augmentée à chaque modification) et son `id`.
  - **TechnoStudio** : le format « Workshop » 2, déjà en données (le fichier `<id>.technostudio.json` que donne « Publier »).
  - **NetSim, Établi 3D** : un format de données à créer (§4). **Aucun code** dans le fichier.
- La Centrale l'ajoute à son catalogue (comme un atelier importé d'une application), le montre dans **Inspecter**
  (partie « Code source ») et le sert aux applications par son API.

## 4. NetSim et Établi 3D : des vérifications nommées

Aujourd'hui, leurs exercices contiennent leurs vérifications en JavaScript (par exemple « deux PC reliés à un switch »).
Un fichier de données ne doit pas porter de code. Chaque application garde donc, **dans son code**, une liste de
vérifications nommées, avec des paramètres ; l'atelier les cite :

```json
{ "t": "Relie les deux PC au switch.", "verifier": { "test": "liens-pc-switch", "min": 2 } }
```

- Une vérification nommée ne change jamais de sens ; on en ajoute de nouvelles.
- Un atelier qui cite une vérification inconnue de l'application (application trop ancienne) le dit à l'élève au lieu de planter.
- La conversion se fait exercice par exercice ; le test de l'application vérifie que chaque fichier ne cite que des vérifications connues.

## 5. Comment une application reçoit un atelier transféré

1. Ouverte depuis la Centrale (`#activite=<id>`, `?atelier=<id>`) sur un atelier qu'elle n'a pas en elle :
   elle le demande à la Centrale (API de la Centrale, à créer), puis l'ouvre.
2. Elle en **garde une copie** sur le poste (avec sa version) : pendant une panne du serveur, il s'ouvre encore.
   Une version plus récente sur la Centrale remplace la copie.
3. Connectée, sa bibliothèque montre aussi les ateliers de la Centrale ; hors connexion, les intégrés et les copies.
4. L'application publie toujours son `ateliers.json`, mais seulement pour ses ateliers intégrés. La Centrale ajoute ses propres ateliers à son catalogue.

## 6. Ordre de passage

1. **Centrale** : dossier `ateliers/<app>/`, catalogue, API, Inspecter. **TechnoStudio** d'abord (déjà en données) :
   ses ateliers publiés (`TechnoStudio/ateliers/`) et ses ateliers intégrés hors fondations passent dans la Centrale.
   L'éditeur « Publier » dépose alors le fichier dans la Centrale au lieu du dépôt de TechnoStudio.
2. **NetSim** : vérifications nommées, puis fichiers de données pour la 4e et au-delà.
3. **Établi 3D** : vérifications nommées, puis fichiers pour les exercices et les exemples.

À chaque étape : identifiants inchangés, ancien code retiré seulement après vérification (§2).
