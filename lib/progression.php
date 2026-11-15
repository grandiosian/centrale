<?php
/**
 * Centrale élèves — progression des ateliers d'une classe.
 *
 * L'enseignant range les ateliers du catalogue dans l'ordre où la classe les fera, avec pour chacun une date
 * d'ouverture (calculée d'après l'emploi du temps et le nombre de séances, ou saisie à la main).
 *
 * Dès qu'une classe a une progression :
 *  - ses élèves ne voient que les ateliers de la progression : ouverts à partir de leur date, « Prochainement »
 *    avant (avec la date) ; un atelier hors progression ou sans date n'est pas montré (sauf s'il est déjà commencé) ;
 *  - dans un atelier ouvert, les étapes s'ouvrent une à une : la suivante quand la précédente est terminée.
 * Une classe sans progression garde le fonctionnement d'avant (périodes de disponibilité, propositions).
 * Les parcours et ce que l'enseignant demande (propositions) restent ouverts : c'est une demande explicite.
 */
declare(strict_types=1);

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
}

/** Progression d'une classe, dans l'ordre : [ {id, ordre, app, atelier, seances, ouvre_le, revision} ]. */
function progression_classe(int $classe_id): array
{
    static $cache = [];
    if (isset($cache[$classe_id])) {
        return $cache[$classe_id];
    }
    $st = db()->prepare('SELECT * FROM progression_ateliers WHERE classe_id = ? ORDER BY ordre, id');
    $st->execute([$classe_id]);
    return $cache[$classe_id] = array_map(fn($r) => ['seances' => (int) $r['seances'], 'revision' => (int) ($r['revision'] ?? 0)] + $r, $st->fetchAll());
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

/** Nombre de séances d'un atelier d'après sa durée annoncée (« 3 séances », « 2 h »…) ; 1 par défaut. */
function seances_d_apres_duree(string $duree): int
{
    if (preg_match('/(\d+)\s*s[ée]ances?/iu', $duree, $m)) {
        return max(1, min(30, (int) $m[1]));
    }
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*h\b/iu', $duree, $m)) {
        return max(1, min(30, (int) ceil((float) str_replace(',', '.', $m[1]) / 1.5)));
    }
    return 1;
}

/**
 * Dates des prochaines séances d'une classe à partir d'un jour (inclus), d'après l'emploi du temps
 * (cours de la classe, semaines A/B, vacances, séances annulées, déplacées ou ajoutées). Une date par jour de cours.
 * Si la classe a des cours en classe entière, seuls ceux-là comptent (sinon ceux du premier groupe).
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
    $g = db()->prepare('SELECT MIN(groupe_id) FROM cours WHERE classe_id = ? AND groupe_id IS NOT NULL');
    $g->execute([$classe_id]);
    $entiere = db()->prepare('SELECT COUNT(*) FROM cours WHERE classe_id = ? AND groupe_id IS NULL');
    $entiere->execute([$classe_id]);
    $groupe = (int) $entiere->fetchColumn() ? null : (($x = $g->fetchColumn()) !== null && $x !== false ? (int) $x : null);

    $dates = [];
    $lundi = lundi_de($debut);
    for ($s = 0; $s < 60 && count($dates) < $nombre; $s++) {
        $sem = semaine_emploi_du_temps($etab, $lundi);
        $jours = [];
        foreach ($sem['seances'] as $x) {
            if ($x['classe_id'] !== $classe_id || $x['statut'] === 'annule' || $x['date'] < $debut || $x['groupe_id'] !== $groupe) {
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

function classe_par_id_progression(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM classes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Calcule les dates d'ouverture d'une progression, pas à pas : le premier atelier à la première séance à partir
 * de $debut, chaque suivant à la séance qui suit la fin du précédent (d'après son nombre de séances).
 * $lignes : [ {seances, …} ] dans l'ordre → les mêmes avec ouvre_le rempli (vide au-delà de l'emploi du temps connu).
 * Renvoie null si la classe n'a pas d'emploi du temps.
 */
function calculer_dates_progression(int $classe_id, array $lignes, string $debut): ?array
{
    $total = array_sum(array_map(fn($l) => max(1, (int) $l['seances']), $lignes));
    $dates = seances_classe($classe_id, $debut, $total + 1);
    if (!$dates) {
        return null;
    }
    $i = 0;
    foreach ($lignes as $k => $l) {
        $lignes[$k]['ouvre_le'] = $dates[$i] ?? '';
        $i += max(1, (int) $l['seances']);
    }
    return $lignes;
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
