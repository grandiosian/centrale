# Centrale — notes pour Claude

Serveur PHP 8.1 + SQLite (alwaysdata) : portail élève, espace enseignant, API des applications, passerelle.
Ce fichier est la carte du projet. Le détail est dans [README.md](README.md) (sections numérotées) et, pour le contrat
avec les applications, dans [public/passerelle/](public/passerelle/). Vue d'ensemble de l'écosystème : [../CLAUDE.md](../CLAUDE.md).

## Règles
- **Rien de nominatif sur le serveur.** Élèves = classe + numéro (+ surnom choisi) ; noms, photos, PAP/PAI restent dans le navigateur de l'enseignant. Tout texte libre envoyé passe par `sansNoms` (admin.js).
- **Ne jamais lire ni modifier la base de production** (`data/` sur alwaysdata, pas de SSH vers elle). Pour essayer : une copie locale.
- Tous les textes visibles en français, simples (élèves de collège) ; pas de framework, pas de build côté Centrale.
- Les tables se créent toutes seules (`CREATE TABLE IF NOT EXISTS`, `creer_tables_*` appelés par `lib/commun.php`) : une nouvelle table = une fonction `creer_tables_…` appelée là.
- **Une seule portée PHP pour l'espace enseignant** : `public/admin/index.php` inclut tous les `lib/admin_*.php`.
  - Un **nom de fonction** déjà pris fait planter la page (exemple : `page_progression` existe dans `admin_competences.php`). Vérifiez avec `grep -rn "function nom(" lib public`.
  - Chaque formulaire envoie `action=…`, et **toutes** les fonctions `action_*()` reçoivent cette action, la première qui la reconnaît la traite. Un **nom d'action** doit donc être unique dans tout le projet (`grep -rn "'mon_action'" lib public`) : sinon un autre fichier la traite à votre place.
- **`public/js/centrale-client.js` est chargé en direct par les applications.** Toute modification doit rester compatible : on ajoute des fonctions, on n'en change pas le sens. Les applications testent une fonction récente avant de l'appeler.
- Ne jamais écrire `centrale.json` / `ateliers.json` des applications : ils sont produits par chaque application.

## Carte
| Je touche à… | Fichiers | Doc |
|---|---|---|
| Portail élève (barre du haut, ateliers, cartes, menu de l'élève) | `public/index.php` (HTML + CSS + JS dans le même fichier) | README §Contenu, SPECIFICATION §2.6 |
| Module des applications (connexion, sauvegarde, progression, `boutonPrecedent`, `menuEleve`…) | `public/js/centrale-client.js` | README §3 « Fonctions », SPECIFICATION |
| API JSON (élèves et applications) | `public/api/*.php`, `lib/api.php` | README §10 |
| Ateliers proposés à l'élève, disponibilité, calendrier, étapes une à une | `public/api/ateliers.php`, `lib/catalogue_ateliers.php` (périodes), `lib/progression.php` (calendrier) | README §4 « Calendrier des ateliers » |
| Espace enseignant : routes, barre, petits outils (`bouton()`, `csrf()`, `flash()`, `rediriger()`) | `public/admin/index.php` | README §7 |
| Pages enseignant | `lib/admin_accueil.php` (tableau de bord), `admin_organisation.php` (établissements, emploi du temps, plans), `admin_catalogue.php` (Ateliers), `admin_progression.php` (Calendrier), `admin_suivi.php` (Applications, parcours, suivi), `admin_competences.php`, `admin_bibliotheque.php`, `admin_tester.php` (élèves test)… | README §4–7 |
| JS et CSS de l'espace enseignant (plan de classe 2D/3D, feuille de séance, suivi) | `public/admin/admin.js`, `plan.js`, `plan3d.js`, `admin.css` | README §5 |
| Emploi du temps (séances, semaines A/B, vacances) | `lib/organisation.php` (`semaine_emploi_du_temps`, `seances_classe` dans progression.php) | README §5 |
| Essai de l'enseignant (« Tester à la place d'un élève ») : tous les élèves, session `essai`, interrupteur Élève \| Enseignant | `lib/admin_tester.php`, `sessions.essai` / `sessions.vue`, `api/session.php` (POST vue), `session_essai()` (lib/api.php), `api/ateliers.php` (vue enseignant sans calendrier), `Centrale.boutonVue()` | SPECIFICATION §2.6 |
| Contrat passerelle (lu par les applications) | `public/passerelle/*.md`, schémas JSON | — |
| Copies des applications servies par la Centrale | `miroirs.txt` (construit au déploiement dans `public/miroir/`, `prive/miroir/`) | README §1 |

## Essayer en local
- Copier le dépôt dans le dossier de travail temporaire, avec une copie de `data/`, puis `php -S 127.0.0.1:8099 -t public`. Après chaque modification, recopier les fichiers changés dans la copie. Les applications se recopient dans `public/miroir/<app>/` (TechnoStudio sans `node_modules`, `.git`, `tests`, `serveur-compilation` ; Établi 3D depuis `dist/`).
- Élève test : espace enseignant → Applications → « Ouvrir son portail » (élève TEST-…). Le navigateur garde les fichiers en cache : `fetch(url, {cache:'reload'})` puis recharger.
- Il n'y a pas de tests automatiques : `php -l` sur chaque fichier modifié, puis vérification dans le navigateur (enseignant et élève).

## Publier
Push sur `main` → `.github/workflows/deploy.yml` envoie le site sur alwaysdata et construit les miroirs (`miroirs.txt`). Les applications sont poussées **avant** la Centrale. Pour vérifier : comparer le `shasum` d'un fichier modifié, en ligne et en local. Messages de commit en français, du type `feat(portail): …` / `fix(api): …`.
