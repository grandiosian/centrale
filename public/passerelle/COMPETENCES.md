# Compétences — règles communes aux applications

> Document de référence publié par la Centrale (`/passerelle/COMPETENCES.md`, lisible sur `/passerelle/?doc=competences`).
> Il s'adresse à toutes les applications : NetSim, Établi 3D, TechnoStudio et celles qui viendront.
> Il complète la [spécification du descripteur](./) (§ 2.7) et le document [Données, bonnes pratiques et compatibilité](./?doc=donnees).

Le but : qu'un même code de compétence veuille dire la même chose partout. L'enseignant voit alors, dans une seule grille, ce que l'élève a montré dans chaque logiciel et ce qu'il a lui-même évalué.

---

## 1. Un seul référentiel

| | |
|---|---|
| Adresse | `https://technoversailles.alwaysdata.net/passerelle/competences.json` |
| Format | `referentiel.competences`, version 1 |
| Identifiant | `technologie-c4-2024` (programme de technologie du cycle 4, BO du 29 février 2024) |
| Contenu | 3 thèmes, leurs compétences, sous-compétences, 114 capacités (5e, 4e, 3e) avec leurs connaissances, l'échelle de maîtrise |

Règles :

1. **On n'invente jamais de code.** Tout code envoyé à la Centrale existe dans `competences.json`. Un code inconnu est ignoré sans message.
2. **Les codes ne changent jamais.** Un nouveau programme donnera un nouveau référentiel avec un autre identifiant, pas des codes renumérotés.
3. Une application peut **embarquer une copie** du référentiel pour marcher sans serveur (afficher les intitulés, par exemple). Elle la met à jour en recopiant le fichier ; la version publiée par la Centrale fait foi.

## 2. Les codes

| Niveau | Exemple | Sens |
|---|---|---|
| Thème | `SFC` | OST, SFC ou CCRI |
| Compétence | `SFC1` | trop large pour évaluer : sert seulement à ranger |
| Sous-compétence | `SFC15` | « La circulation de l'information dans un réseau informatique » |
| Capacité | `SFC15.4.2` | sous-compétence SFC15, niveau **4**e, capacité n° **2** |

Les thèmes et leurs couleurs (les mêmes dans toutes les applications) :

| Thème | Intitulé court | Couleur | TechnoStudio |
|---|---|---|---|
| `OST` | Objets et systèmes techniques dans leur environnement | vert `#30B34A` | `T1` |
| `SFC` | Structure, fonctionnement, comportement | rose `#C45CCE` | `T2` |
| `CCRI` | Concevoir, créer, réaliser, innover | jaune orangé `#E8B624` | `T3` |

L'échelle de maîtrise (celle du LSU) :

| Niveau | Code | Libellé | Couleur |
|---|---|---|---|
| 1 | MI | Maîtrise insuffisante | `#d64035` |
| 2 | MF | Maîtrise fragile | `#f0a030` |
| 3 | MS | Maîtrise satisfaisante | `#7cb342` |
| 4 | TBM | Très bonne maîtrise | `#2e7d32` |

## 3. Choisir les bons codes

- **De préférence une capacité** (`SFC15.5.1`) : c'est ce que l'enseignant évalue.
- **Une sous-compétence** (`SFC15`) quand l'activité n'est pas plus précise ou couvre plusieurs capacités de la même famille.
- **Jamais une compétence (`SFC1`) ni un thème** : la Centrale les refuse, ils sont trop larges pour un bilan utile.
- **Peu de codes par activité** : un ou deux. Une activité qui « travaille tout » n'apprend rien à l'enseignant.
- **Le niveau** :
  - une activité écrite pour un niveau (l'exercice 5e « Brancher la salle ») déclare la capacité de ce niveau (`.5.`) ;
  - une activité valable à tous les niveaux (un tuto de modélisation) déclare la capacité des trois niveaux (`CCRI16.5.1`, `CCRI16.4.1`, `CCRI16.3.1`) et, au moment du constat, n'envoie que celle du niveau de l'élève : le chiffre au début de sa classe (`Centrale.session().classe`, « 4B » → 4e), 5e par défaut.

## 4. Où déclarer les compétences

| Endroit | Champ | Qui le lit |
|---|---|---|
| Atelier, dans `ateliers.json` | `ateliers[].competences` | Centrale, onglet Ateliers des applications ; `api/catalogue.php` |
| Activité, dans `centrale.json` et `Centrale.init({ activites })` | `activites.liste[].competences` | Centrale : référentiel (« travaillée dans »), bibliothèque |
| Activité, dans `ateliers.json` | `ateliers[].activites[].competences` | idem |
| Question ou bloc d'une feuille (TechnoStudio) | `comp` | l'application, pour calculer ses constats |
| Étape de parcours (écrite par l'enseignant) | ligne `compétences : SFC15.5.1` | Centrale : grille de notation « À valider / À évaluer » |

Les compétences d'un atelier sont la réunion de celles de ses activités : inutile de les répéter si les activités les portent.

## 5. Envoyer des constats

Un **constat** dit : « cet élève a montré (ou pas) cette capacité, voici dans quelles conditions ». C'est l'application qui le produit, parce que c'est elle qui a vérifié le travail.

### Format

Les constats partent **avec la progression**, dans le champ `constats` :

```js
Centrale.progression('e5-1', { titre: 'Brancher la salle', statut: 'termine', faites: 5, total: 5,
  constats: [ { competence: 'SFC15.5.1', reussi: true, indices: 1, essais: 1,
                preuve: 'Exercice « Brancher la salle » : 5 étapes, 1 indice ouvert' } ] });
```

| Champ | Obligatoire | Sens |
|---|---|---|
| `competence` | oui | code du référentiel (capacité ou sous-compétence) |
| `reussi` | oui, sauf si `niveau` | l'application a vérifié que c'est réussi |
| `indices` | non (0) | aides utilisées : indices ouverts, étapes passées, corrections affichées… |
| `essais` | non (1) | tentatives avant de réussir (« Recommencer » compte un essai de plus) |
| `niveau` | non | 1 à 4 : l'application fixe elle-même le niveau (feuille notée, barème) ; prioritaire sur le reste |
| `preuve` | conseillé | phrase courte lisible par l'enseignant (200 caractères au plus), **sans nom d'élève** |

### Quand

- **À la fin d'une activité vérifiée** : exercice réussi, feuille notée rendue, critères validés. Pas à l'ouverture, pas pour un exemple que rien ne vérifie.
- **Une fois par essai** : renvoyer la même progression ne doit pas renvoyer le même constat (garder une marque « déjà constaté »). Un nouvel essai complet après « Recommencer » peut donner un nouveau constat.
- **Seulement quand l'élève est connecté.** Hors connexion, l'application garde la progression comme d'habitude ; on ne fabrique pas de constat après coup.
- 50 constats au plus par envoi. Le module `centrale-client.js` les met en file d'attente si le réseau tombe : ils ne sont ni perdus ni doublés.

### Ce que la Centrale en fait

Le niveau d'un constat :

| Situation | Niveau |
|---|---|
| `niveau` donné | ce niveau |
| `reussi: false` | 1 · MI |
| réussi, 0 indice, 1 essai | 4 · TBM |
| réussi, au plus 1 indice et 3 essais | 3 · MS |
| réussi avec davantage d'aide | 2 · MF |

Le **bilan** d'une capacité (et d'une sous-compétence) est la **moyenne haute** de tous ses constats, ceux de l'enseignant compris : la moyenne arrondie au niveau supérieur. Exemple : TBM, MF, MI → (4 + 2 + 1) / 3 = 2,33 → MS.

L'enseignant le voit dans **Suivi** (élèves × sous-compétences, ou × capacités avec « Détail par capacité », limité à une séquence de sa progression), sur la page de chaque élève (constats détaillés avec la preuve) et dans l'onglet **Compétences** (où chaque capacité est travaillée).

### Barème → niveau (applications qui notent)

Une feuille notée (TechnoStudio) envoie `niveau` d'après la part des points obtenus sur les questions de cette capacité :

| Points obtenus | Niveau |
|---|---|
| moins de 40 % | 1 · MI |
| de 40 % à moins de 70 % | 2 · MF |
| de 70 % à moins de 90 % | 3 · MS |
| 90 % et plus | 4 · TBM |

Une question corrigée par l'enseignant dans l'application compte avec sa note ; une question non corrigée ne compte pas.

## 6. Les codes TechnoStudio

TechnoStudio range ses pastilles avec les codes de la charte : `T1` = OST, `T2` = SFC, `T3` = CCRI, puis le numéro de compétence et de sous-compétence (`T2C11`). Ces sous-compétences sont propres à chaque fiche : `T2C12` d'une fiche n'est pas forcément `SFC12`.

- Chaque compétence ou sous-compétence de la fiche est donc **reliée au référentiel** par son champ `ref` : `{ "code": "T2C11", "label": "Identifier les constituants de la chaîne d'énergie…", "ref": ["SFC11.5.2"] }`.
- Une question peut aussi porter directement un code du référentiel dans `comp` (`["SFC11.5.2"]`).
- Une question notée sans pastille prend les capacités de l'atelier : `"referentiel": ["SFC11.5.1", "CCRI22.5.1"]`.
- À l'import d'un atelier TechnoStudio, la Centrale prend `ref` ; à défaut seulement, elle convertit le code (`T2C11` → `SFC11`, conversion approchée ; `T2C1` → `SFC1`, écarté car trop large).
- La table des thèmes est dans `competences.json` → `correspondances.technostudio`.

## 7. État des applications (31 octobre 2026)

| Application | Déclare | Constats | Comment |
|---|---|---|---|
| NetSim 1.7.0 | oui, par exercice | oui | `COMP_EXO` : 5e → `SFC15.5.1` / `SFC15.5.2` ; 4e → `SFC15.4.1` à `SFC15.4.3`, `CCRI17.4.1` (capteur connecté) ; 3e → `SFC15.3.1` / `SFC15.3.2`, `CCRI22.3.1` (plan d'adressage testé). Indices = indices ouverts + étapes passées ; essais = 1 + « Recommencer » ; pas réussi si plus de la moitié des étapes automatiques ont été passées. |
| Établi 3D 1.3.0 | oui, par catégorie | oui | `COMP_CAT` : modéliser, esquisser → `CCRI16` ; mouvement → `SFC11` + `CCRI16` ; structures → `CCRI21`. Capacité du niveau de la classe. Exercice sans indice → TBM ; avec l'indice ou tuto guidé → MS. Les exemples n'envoient rien. |
| TechnoStudio 3.3.0 | oui, par feuille notée | oui | Pastilles reliées par `ref`, capacités de l'atelier (`referentiel`). Constat à la fin d'une feuille, `niveau` d'après le barème (§ 5), renvoyé seulement si les niveaux changent. Ateliers du lycée (STI2D, SNT, Pro) : hors référentiel du cycle 4, pas de constat. |

## 8. Liste de contrôle pour une application

1. Chaque activité vérifiable porte un ou deux codes du référentiel (capacité de préférence).
2. `centrale.json` et `ateliers.json` les reprennent, produits à partir du code de l'application et vérifiés par un test.
3. Le constat part à la fin d'une activité vérifiée, une seule fois par essai, avec une preuve lisible et sans nom.
4. Indices et essais sont comptés honnêtement ; une application qui note envoie `niveau`.
5. Sans Centrale, rien ne change pour l'élève.
