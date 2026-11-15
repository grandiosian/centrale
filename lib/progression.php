<?php
/**
 * Centrale élèves — progression des ateliers d'une classe.
 *
 * L'enseignant range les ateliers du catalogue dans l'ordre où la classe les fera, avec pour chacun une date
 * d'ouverture : calculée d'après l'emploi du temps et le nombre de séances, ou fixée à la main (date_fixe).
 * seances = 0 : l'atelier s'ouvre le même jour que le précédent (deux ateliers courts dans la même séance).
 *
 * Dès qu'une classe a une progression :
 *  - ses élèves ne voient que les ateliers de la progression : ouverts à partir de leur date, « Prochainement »
 *    avant (avec la date) ; un atelier hors progression ou sans date n'est pas montré (sauf s'il est déjà commencé) ;
 *  - dans un atelier ouvert, les étapes s'ouvrent une à une : la suivante quand la précédente est terminée.
 * Une classe sans progression garde le fonctionnement d'avant (périodes de disponibilité, propositions).
 * Ce que l'enseignant demande (propositions) reste ouvert : c'est une demande explicite.
 * Un parcours (onglet Parcours) peut être placé dans le calendrier comme un atelier (app = APP_PARCOURS) : c'est
 * ainsi qu'il arrive sur le portail, en une carte (cartes_parcours_calendrier).
 */
declare(strict_types=1);

/** « app » des lignes du calendrier qui sont des parcours (atelier = identifiant du parcours). */
const APP_PARCOURS = '*parcours';

function creer_tables_progression(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS progression_ateliers (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        classe_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
        ordre     INTEGER NOT NULL DEFAULT 0,
        app       TEXT NOT NULL,
        atelier   TEXT NOT NULL,                     -- identifiant de l'atelier dans le catalogue
        seances   INTEGER NOT NULL DEFAULT 1,        -- durée prévue, en séances (sert au calcul des dates)
        ouvre_le  TEXT NOT NULL DEFAULT '',          -- AAAA-MM-JJ ; vide = pas encore daté (pas montré aux élèves)
        UNIQUE (classe_id, app, atelier)
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_progression_classe ON progression_ateliers (classe_id, ordre)');
    // révision : atelier déjà passé que l'enseignant remet en avant (section « Révisions » du portail, rouvert même terminé)
    ajouter_colonne($pdo, 'progression_ateliers', 'revision', 'INTEGER NOT NULL DEFAULT 0');
    // date fixée à la main : gardée telle quelle quand les autres dates se recalculent
    ajouter_colonne($pdo, 'progression_ateliers', 'date_fixe', 'INTEGER NOT NULL DEFAULT 0');
}

/** Progression d'une classe, dans l'ordre : [ {id, ordre, app, atelier, seances, ouvre_le, revision, date_fixe} ]. */
function progression_classe(int $classe_id): array
{
    static $cache = [];
    if (isset($cache[$classe_id])) {
        return $cache[$classe_id];
    }
    $st = db()->prepare('SELECT * FROM progression_ateliers WHERE classe_id = ? ORDER BY ordre, id');
    $st->execute([$classe_id]);
    return $cache[$classe_id] = array_map(fn($r) => ['seances' => (int) $r['seances'], 'revision' => (int) ($r['revision'] ?? 0), 'date_fixe' => (int) ($r['date_fixe'] ?? 0)] + $r, $st->fetchAll());
}

/**
 * États des ateliers d'une classe d'après sa progression : [app][atelier] => ['etat', 'ouvre_le', 'jusqu_au', 'limite',
 * 'source' => 'progression', 'revision'], ou null si la classe n'a pas de progression.
 */
function disponibilites_progression(int $classe_id, ?string $date = null): ?array
{
    $p = progression_classe($classe_id);
    if (!$p) {
        return null;
    }
    $date ??= aujourd_hui();
    $res = [];
    foreach ($p as $r) {
        $d = (string) $r['ouvre_le'];
        $etat = $d === '' ? 'ferme' : ($d <= $date ? 'ouvert' : 'bientot');
        $res[$r['app']][$r['atelier']] = ['etat' => $etat, 'ouvre_le' => $etat === 'bientot' ? $d : null, 'jusqu_au' => null,
                                          'limite' => true, 'source' => 'progression', 'revision' => !empty($r['revision'])];
    }
    return $res;
}

/**
 * Jusqu'à quand compléter chaque atelier (ou parcours) daté du calendrier : [app][atelier] => AAAA-MM-JJ.
 * C'est sa dernière séance prévue (date d'ouverture + nombre de séances, d'après l'emploi du temps) ; sans emploi
 * du temps, la veille de l'atelier daté suivant. Absent : pas de date (pas daté, ou dernier atelier sans emploi du temps).
 * L'atelier ne se ferme pas à cette date : c'est un repère pour l'élève (« À compléter jusqu'au … » sur le portail).
 */
function echeances_progression(int $classe_id): array
{
    $dates = array_values(array_filter(progression_classe($classe_id), fn($r) => (string) $r['ouvre_le'] !== ''));
    if (!$dates) {
        return [];
    }
    usort($dates, fn($u, $v) => strcmp($u['ouvre_le'], $v['ouvre_le']));
    $seances = seances_annee_classe($classe_id, $dates[0]['ouvre_le']);
    $res = [];
    foreach ($dates as $i => $r) {
        $d = (string) $r['ouvre_le'];
        $apres = array_values(array_filter($seances, fn($s) => $s >= $d));
        $fin = $apres[max(1, (int) $r['seances']) - 1] ?? null;
        if ($fin === null) {   // pas d'emploi du temps (ou fin d'année) : veille de l'atelier suivant
            foreach (array_slice($dates, $i + 1) as $s) {
                if ($s['ouvre_le'] > $d) {
                    $fin = (new DateTimeImmutable($s['ouvre_le']))->modify('-1 day')->format('Y-m-d');
                    break;
                }
            }
        }
        if ($fin !== null) {
            $res[$r['app']][$r['atelier']] = $fin;
        }
    }
    return $res;
}

/** Nombre de séances d'un atelier d'après sa durée annoncée (« 3 séances », « 2 h », « 220 min »…), pour des séances d'1 h 30 ; 1 par défaut. */
function seances_d_apres_duree(string $duree): int
{
    if (preg_match('/(\d+)\s*s[ée]ances?/iu', $duree, $m)) {
        return max(1, min(30, (int) $m[1]));
    }
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*h(?:\s*(\d+))?(?!\p{L})/iu', $duree, $m)) {
        $min = (float) str_replace(',', '.', $m[1]) * 60 + (float) ($m[2] ?? 0);
        return max(1, min(30, (int) ceil($min / 90)));
    }
    if (preg_match('/(\d+)\s*min/iu', $duree, $m)) {
        return max(1, min(30, (int) ceil((int) $m[1] / 90)));
    }
    return 1;
}

/**
 * Dates des prochaines séances d'une classe à partir d'un jour (inclus), d'après l'emploi du temps
 * (cours de la classe, semaines A/B, vacances, séances annulées, déplacées ou ajoutées). Une date par jour de cours.
 * Comptent les cours en classe entière et ceux du premier groupe (demi-groupes) : un élève suit les deux.
 * → liste de dates AAAA-MM-JJ (au plus $nombre), vide si la classe n'a pas d'emploi du temps.
 */
function seances_classe(int $classe_id, string $debut, int $nombre): array
{
    $c = classe_par_id_progression($classe_id);
    $etab = $c && $c['etablissement_id'] ? etablissement_par_id((int) $c['etablissement_id']) : null;
    if (!$etab || $nombre <= 0) {
        return [];
    }
    $n = db()->prepare('SELECT COUNT(*) FROM cours WHERE classe_id = ?');
    $n->execute([$classe_id]);
    if (!(int) $n->fetchColumn()) {
        return [];
    }
    $groupe = premier_groupe_cours($classe_id);

    $dates = [];
    $lundi = lundi_de($debut);
    for ($s = 0; $s < 60 && count($dates) < $nombre; $s++) {
        $sem = semaine_emploi_du_temps($etab, $lundi);
        $jours = [];
        foreach ($sem['seances'] as $x) {
            if ($x['classe_id'] !== $classe_id || $x['statut'] === 'annule' || $x['date'] < $debut
                || ($x['groupe_id'] !== null && $x['groupe_id'] !== $groupe)) {
                continue;
            }
            $jours[$x['date']] = true;
        }
        ksort($jours);
        foreach (array_keys($jours) as $d) {
            if (count($dates) < $nombre) {
                $dates[] = $d;
            }
        }
        $lundi = ajouter_jours($lundi, 7);
    }
    return $dates;
}

/** Premier groupe (demi-groupe) qui a des cours dans la classe, ou null. Ses séances comptent pour le calendrier. */
function premier_groupe_cours(int $classe_id): ?int
{
    $g = db()->prepare('SELECT MIN(groupe_id) FROM cours WHERE classe_id = ? AND groupe_id IS NOT NULL');
    $g->execute([$classe_id]);
    $x = $g->fetchColumn();
    return $x !== null && $x !== false ? (int) $x : null;
}

/**
 * Cours de la classe comptés pour le calendrier, en clair : [ « lundi M1 », « vendredi S2 (sem. A, GR1) » ].
 * Seulement ceux qui ne sont pas terminés à la date $depuis.
 */
function cours_comptes_calendrier(int $classe_id, string $depuis): array
{
    $groupe = premier_groupe_cours($classe_id);
    $st = db()->prepare('SELECT c.jour, c.semaine, c.fin, cr.libelle, g.nom AS groupe FROM cours c
                         JOIN creneaux cr ON cr.id = c.creneau_id LEFT JOIN groupes g ON g.id = c.groupe_id
                         WHERE c.classe_id = ? AND (c.groupe_id IS NULL OR c.groupe_id = ?) AND (c.fin = \'\' OR c.fin >= ?)
                         ORDER BY c.jour, cr.ordre');
    $st->execute([$classe_id, $groupe ?? 0, $depuis]);
    $semaines = [];   // « lundi M1 » + groupe => semaines (A, B ou '' = toutes)
    foreach ($st->fetchAll() as $c) {
        $cle = mb_strtolower(JOURS[(int) $c['jour']] ?? '') . ' ' . $c['libelle'] . "\t" . ($c['groupe'] ?? '');
        $semaines[$cle][$c['semaine']] = true;
    }
    $res = [];
    foreach ($semaines as $cle => $s) {
        [$quand, $groupe] = explode("\t", $cle);
        $sem = isset($s['']) || (isset($s['A']) && isset($s['B'])) ? '' : 'sem. ' . array_key_first($s);
        $plus = array_filter([$sem, $groupe]);
        $res[] = $quand . ($plus ? ' (' . implode(', ', $plus) . ')' : '');
    }
    return $res;
}

/** Toutes les séances de la classe sur l'année scolaire de $date (dès le 1er août) : dates AAAA-MM-JJ, vide sans emploi du temps. */
function seances_annee_classe(int $classe_id, string $date): array
{
    return seances_classe($classe_id, substr(annee_scolaire($date), 0, 4) . '-08-01', 400);
}

function classe_par_id_progression(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM classes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Calcule les dates d'ouverture d'une progression, pas à pas (même règle que l'éditeur, admin.js) :
 *  - le premier atelier à la première séance à partir de $debut ;
 *  - chaque suivant à la séance qui suit la fin du précédent (d'après son nombre de séances) ;
 *  - seances = 0 : le même jour que le précédent ;
 *  - date_fixe : la date est gardée, les suivants repartent de là.
 * $seances : dates des séances de la classe (seances_annee_classe) ; vide = pas d'emploi du temps, une séance par semaine.
 * $lignes : [ {seances, ouvre_le, date_fixe, …} ] dans l'ordre → les mêmes avec ouvre_le rempli (vide au-delà des séances connues).
 */
function dates_progression(array $lignes, string $debut, array $seances): array
{
    $lignes = array_values($lignes);
    $hebdo = !$seances;
    $serie = $hebdo ? semaines_depuis($debut) : $seances;
    $pos = premiere_seance_depuis($serie, $debut);
    $avant = '';
    foreach ($lignes as $k => $l) {
        $n = (int) ($l['seances'] ?? 1);
        $d = (string) ($l['ouvre_le'] ?? '');
        if (!empty($l['date_fixe']) && date_valide($d)) {
            if ($hebdo) {
                $serie = semaines_depuis($d);
            }
            $pos = premiere_seance_depuis($serie, $d) + max(1, $n);
        } elseif ($n === 0 && $k > 0) {
            $d = $avant;
        } else {
            $d = $serie[$pos] ?? '';
            $pos += max(1, $n);
        }
        $lignes[$k]['ouvre_le'] = $d;
        $avant = $d;
    }
    return $lignes;
}

/** Une date par semaine à partir de $debut (classe sans emploi du temps), sur un an. */
function semaines_depuis(string $debut): array
{
    $res = [];
    for ($i = 0; $i < 52; $i++) {
        $res[] = ajouter_jours($debut, 7 * $i);
    }
    return $res;
}

/** Rang de la première date de $serie (triée) qui tombe le $date ou après (count($serie) s'il n'y en a pas). */
function premiere_seance_depuis(array $serie, string $date): int
{
    foreach ($serie as $i => $d) {
        if ($d >= $date) {
            return $i;
        }
    }
    return count($serie);
}

/** Dates d'une progression d'après l'emploi du temps de la classe (voir dates_progression). */
function calculer_dates_progression(int $classe_id, array $lignes, string $debut): array
{
    return array_values(dates_progression($lignes, $debut, seances_annee_classe($classe_id, $debut)));
}

/**
 * Étapes une à une, dans chaque atelier ouvert d'une progression : une étape est accessible si c'est la première
 * de son atelier, si la précédente est terminée, si l'élève l'a déjà commencée ou si l'enseignant la lui a demandée.
 * $activites : liste de l'API (id, titre, statut, propose, disponible…) d'une application → la même, les étapes
 * verrouillées ayant disponible = false, verrou = true et apres = titre de l'étape à terminer avant.
 */
function verrouiller_etapes(string $app, array $activites, ?array $dispos = null): array
{
    $precedente = [];   // atelier => [terminee, titre]
    foreach ($activites as $k => $x) {
        if ($x['disponible'] === false) {
            continue;
        }
        $at = ($dispos !== null ? atelier_de_activite_classe($dispos, $app, (string) $x['id']) : atelier_de_activite($app, (string) $x['id'])) ?? '';
        $p = $precedente[$at] ?? null;
        $libre = $p === null || $p[0] || $x['statut'] !== 'a_faire' || !empty($x['propose']);
        if (!$libre) {
            $activites[$k]['disponible'] = false;
            $activites[$k]['verrou'] = true;
            $activites[$k]['apres'] = $p[1];
        }
        $precedente[$at] = [$x['statut'] === 'termine', (string) ($x['titre'] ?: $x['id'])];
    }
    return $activites;
}

/**
 * Ce que voient aujourd'hui les élèves d'une classe SANS calendrier (même règles que api/ateliers.php) :
 *  - les applications ouvertes à la classe (onglet Ateliers : classes concernées, fenêtres d'activation) ;
 *  - dans chacune, les ateliers du catalogue qui ne sont pas fermés par une période de disponibilité ;
 *  - réglage « activités proposées » (par défaut) : seulement les ateliers où une étape a été proposée à la classe
 *    ou à un de ses groupes (plus, pour chaque élève, ce qu'il a déjà commencé : non compté ici).
 * → ['mode' => 'proposees'|'toutes', 'apps' => [ {app, nom, icone, etat, ouvre_le,
 *      ateliers => [ {cle, titre, etat: ouvert|bientot, ouvre_le, proposees} ] } ] ]
 */
function vue_classe_sans_calendrier(int $classe_id, string $classe): array
{
    $proposees_seulement = portail_seulement_proposees();
    $dispos = disponibilites_classe($classe_id);
    unset($dispos['*progression']);
    $proposees = [];   // [app][atelier] => nombre d'étapes proposées à la classe ou à un de ses groupes
    $st = db()->prepare('SELECT app, activite FROM propositions WHERE classe_id = ?');
    $st->execute([$classe_id]);
    foreach ($st->fetchAll() as $p) {
        $at = atelier_de_activite((string) $p['app'], (string) $p['activite']);
        if ($at !== null) {
            $proposees[$p['app']][$at] = ($proposees[$p['app']][$at] ?? 0) + 1;
        }
    }
    $par_app = [];
    foreach (catalogue_ateliers(null, true) as $r) {
        if ($r['etat'] === 'actif') {
            $par_app[$r['app']][] = $r;
        }
    }
    $apps = [];
    foreach (ateliers_pour_classe($classe) as $a) {
        $app = (string) $a['app'];
        $ouvre_app = null;
        if (!atelier_disponible_maintenant($app, $classe_id)) {
            $t = prochaine_disponibilite($app, $classe_id);
            if ($t === null) {
                continue;   // application fermée à la classe
            }
            $ouvre_app = (new DateTimeImmutable('@' . $t))->setTimezone(fuseau())->format('Y-m-d');
        }
        $ateliers = [];
        foreach ($par_app[$app] ?? [] as $r) {
            $d = $dispos[$app][$r['atelier']] ?? ['etat' => 'ouvert', 'ouvre_le' => null];
            $n = $proposees[$app][$r['atelier']] ?? 0;
            if ($d['etat'] === 'ferme' || ($proposees_seulement && !$n)) {
                continue;
            }
            $ouvre = $ouvre_app !== null ? max($ouvre_app, (string) $d['ouvre_le']) : $d['ouvre_le'];
            $ateliers[] = ['cle' => $app . '|' . $r['atelier'], 'titre' => $r['titre'] ?: $r['atelier'],
                           'etat' => $ouvre_app !== null || $d['etat'] === 'bientot' ? 'bientot' : 'ouvert', 'ouvre_le' => $ouvre, 'proposees' => $n];
        }
        $apps[] = ['app' => $app, 'nom' => $a['nom'], 'icone' => $a['icone'], 'etat' => $ouvre_app !== null ? 'bientot' : 'ouvert',
                   'ouvre_le' => $ouvre_app, 'ateliers' => $ateliers];
    }
    return ['mode' => $proposees_seulement ? 'proposees' : 'toutes', 'apps' => $apps];
}

/**
 * Parcours placés dans le calendrier d'une classe (lignes app = APP_PARCOURS) : une carte par parcours pour
 * api/ateliers.php, au même format qu'un atelier (app « parcours »). Ouvert à sa date, « bientôt » avant, pas montré
 * sans date. Ses étapes s'ouvrent selon son réglage (libre, dans l'ordre, après validation) ; chaque étape porte
 * l'adresse de son application, son activité, le parcours et son rang (le portail l'ouvre comme une étape de parcours :
 * bouton « Étape suivante » dans les applications). Vue enseignant d'un essai : tout ouvert.
 * Un parcours hors du calendrier n'est pas montré sur le portail.
 */
function cartes_parcours_calendrier(array $eleve, array $prog, bool $vue_prof): array
{
    $cid = (int) $eleve['classe_id'];
    $places = [];
    foreach (progression_classe($cid) as $r) {
        if ($r['app'] === APP_PARCOURS) {
            $places[(string) $r['atelier']] = true;
        }
    }
    if (!$places) {
        return [];
    }
    $dispos = disponibilites_progression($cid)[APP_PARCOURS] ?? [];
    $echeances = echeances_progression($cid)[APP_PARCOURS] ?? [];
    $ateliers = db()->query('SELECT * FROM ateliers')->fetchAll();
    $catalogue = catalogue_activites();
    $res = [];
    foreach (parcours_pour_classe((string) $eleve['classe']) as $p) {
        $pid = (string) $p['id'];
        if (!isset($places[$pid]) || !$p['etapes']) {
            continue;
        }
        $d = $dispos[$pid] ?? ['etat' => 'ferme', 'ouvre_le' => null, 'revision' => false];
        if ($vue_prof) {
            $d = ['etat' => 'ouvert', 'ouvre_le' => null] + $d;
        }
        if ($d['etat'] === 'ferme') {
            continue;   // pas encore daté
        }
        $ouvert = $d['etat'] === 'ouvert';
        $proprio = proprietaire_projet($eleve, $p);
        $etats = etapes_projet($p, $proprio, $proprio['type'] === 'eleve' ? [(int) $eleve['id'] => $prog] : null, $catalogue);
        $acts = [];
        $precedente = '';
        foreach ($p['etapes'] as $i => $et) {
            $e = $etats[$i];
            $libre = $vue_prof || $e['ouverte'];
            $x = ['id' => 'parcours-' . $pid . '-' . $i, 'titre' => $et['titre'] ?: ('Étape ' . ($i + 1)), 'groupe' => '',
                  'statut' => $e['statut'], 'texte' => $e['texte'], 'propose' => false,
                  'disponible' => $ouvert && $libre, 'ouvre_le' => $ouvert ? null : $d['ouvre_le'], 'jusqu_au' => null,
                  'revision' => !empty($d['revision']), 'atelier' => $pid, 'atelier_titre' => $p['nom'],
                  'url' => lien_vers_meilleure_adresse((string) $et['url'], $ateliers), 'activite' => (string) $et['activite'],
                  'parcours' => (int) $pid, 'etape' => $i, 'app' => (string) $et['app'],
                  'a_completer' => $echeances[$pid] ?? null];
            if ($ouvert && !$libre) {
                $x['verrou'] = true;
                $x['apres'] = ($precedente ?: 'l’étape précédente') . ($p['deblocage'] === 'validation' ? ', validée par ton professeur' : '');
            }
            $acts[] = $x;
            $precedente = $x['titre'];
        }
        $faites = count(array_filter($acts, fn($x) => $x['statut'] === 'termine'));
        $res[] = [
            'app' => 'parcours', 'nom' => 'Parcours', 'description' => (string) $p['description'], 'icone' => '🧭',
            'url' => $acts[0]['url'], 'parcours' => (int) $pid,
            'apps' => array_values(array_unique(array_filter(array_column($acts, 'app')))),   // icônes des applications du parcours
            'taux' => round(array_sum(array_column($etats, 'taux')) / count($etats), 3),
            'terminees' => $faites, 'total' => count($acts), 'maj' => null, 'activites' => $acts,
            'disponible' => $ouvert,
            'disponible_le' => $ouvert ? null : (new DateTimeImmutable($d['ouvre_le'] . ' 00:00', fuseau()))->getTimestamp(),
        ];
    }
    return $res;
}
