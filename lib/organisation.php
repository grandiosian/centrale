<?php
/**
 * Centrale élèves — organisation : établissements, grilles horaires, semaines A/B,
 * vacances scolaires, emploi du temps (cours récurrents + modifications ponctuelles).
 */
declare(strict_types=1);

const JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi'];
const JOURS_COURTS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam'];
const MOIS = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

function fuseau(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Europe/Paris');
}

function aujourd_hui(): string
{
    return (new DateTimeImmutable('now', fuseau()))->format('Y-m-d');
}

function date_valide(string $d): bool
{
    $o = DateTimeImmutable::createFromFormat('!Y-m-d', $d, fuseau());
    return $o !== false && $o->format('Y-m-d') === $d;
}

/** Lundi de la semaine d'une date (AAAA-MM-JJ). */
function lundi_de(string $date): string
{
    $d = new DateTimeImmutable($date, fuseau());
    return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
}

function ajouter_jours(string $date, int $n): string
{
    return (new DateTimeImmutable($date, fuseau()))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
}

function jour_semaine(string $date): int
{
    return (int) (new DateTimeImmutable($date, fuseau()))->format('N');
}

/** « lundi 3 nov. » */
function date_lisible(string $date, bool $avec_jour = true): string
{
    $d = new DateTimeImmutable($date, fuseau());
    $j = (int) $d->format('N');
    return ($avec_jour ? mb_strtolower(JOURS[$j] ?? 'Dimanche') . ' ' : '') . $d->format('j') . ' ' . MOIS[(int) $d->format('n')];
}

function etablissement(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM etablissements WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function creneaux_de(int $etablissement_id): array
{
    $st = db()->prepare('SELECT * FROM creneaux WHERE etablissement_id = ? ORDER BY ordre, debut');
    $st->execute([$etablissement_id]);
    return $st->fetchAll();
}

/* ---------- Vacances ---------- */

/** Périodes de vacances d'un établissement. */
function vacances_de(int $etablissement_id): array
{
    static $cache = [];
    if (!isset($cache[$etablissement_id])) {
        $st = db()->prepare('SELECT * FROM vacances WHERE etablissement_id = ? ORDER BY debut');
        $st->execute([$etablissement_id]);
        $cache[$etablissement_id] = $st->fetchAll();
    }
    return $cache[$etablissement_id];
}

/** Libellé des vacances si la date est en vacances, sinon null. */
function en_vacances(int $etablissement_id, string $date): ?string
{
    foreach (vacances_de($etablissement_id) as $v) {
        if ($date >= $v['debut'] && $date < $v['fin']) {
            return $v['libelle'];
        }
    }
    return null;
}

/** La semaine (du lundi) est-elle entièrement en vacances (du lundi au vendredi) ? */
function semaine_de_vacances(int $etablissement_id, string $lundi): bool
{
    for ($i = 0; $i < 5; $i++) {
        if (en_vacances($etablissement_id, ajouter_jours($lundi, $i)) === null) {
            return false;
        }
    }
    return true;
}

/** Année scolaire d'une date : "2026-2027". */
function annee_scolaire(string $date): string
{
    [$a, $m] = array_map('intval', explode('-', $date));
    return $m >= 8 ? "$a-" . ($a + 1) : ($a - 1) . "-$a";
}

/**
 * Transforme les enregistrements du calendrier scolaire officiel
 * (data.education.gouv.fr, jeu « fr-en-calendrier-scolaire ») en périodes de vacances.
 * Les dates officielles sont des instants UTC (ex. 2026-10-16T22:00:00+00:00 = samedi 17 à 0 h à Paris) ;
 * la fin correspond au jour de reprise des cours (exclu).
 */
function periodes_officielles(array $enregistrements, string $fin_annee_defaut): array
{
    $periodes = [];
    foreach ($enregistrements as $r) {
        if (!empty($r['population']) && stripos((string) $r['population'], 'enseignant') !== false) {
            continue; // prérentrée des enseignants, etc.
        }
        if (empty($r['start_date']) || empty($r['description'])) {
            continue;
        }
        $debut = (new DateTimeImmutable((string) $r['start_date']))->setTimezone(fuseau())->format('Y-m-d');
        $fin = !empty($r['end_date'])
            ? (new DateTimeImmutable((string) $r['end_date']))->setTimezone(fuseau())->format('Y-m-d')
            : $fin_annee_defaut;
        if ($fin <= $debut) {
            $fin = ajouter_jours($debut, 1);
        }
        $cle = $r['description'] . '|' . $debut . '|' . $fin;
        $periodes[$cle] = ['libelle' => (string) $r['description'], 'debut' => $debut, 'fin' => $fin];
    }
    usort($periodes, fn($a, $b) => strcmp($a['debut'], $b['debut']));
    return array_values($periodes);
}

/**
 * Importe les vacances officielles de la zone de l'établissement pour une année scolaire.
 * Renvoie le nombre de périodes importées, ou lève une exception avec un message lisible.
 */
function importer_vacances(array $etab, string $annee): int
{
    if (!in_array($etab['zone'], ['A', 'B', 'C'], true)) {
        throw new RuntimeException("Choisissez d'abord la zone (A, B ou C) de l'établissement.");
    }
    $where = sprintf('zones="Zone %s" and annee_scolaire="%s"', $etab['zone'], $annee);
    $url = 'https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records?limit=100&where='
        . rawurlencode($where);
    $texte = false;
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Centrale-eleves']);
        $texte = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        if ($code !== 200) {
            $texte = false;
        }
    } else {
        $texte = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 15]]));
    }
    $json = $texte !== false ? json_decode((string) $texte, true) : null;
    if (!is_array($json) || !isset($json['results'])) {
        throw new RuntimeException("Le calendrier officiel n'a pas pu être téléchargé. Réessayez plus tard ou saisissez les vacances à la main.");
    }
    [$a1, $a2] = explode('-', $annee);
    $periodes = periodes_officielles($json['results'], "$a2-09-01");
    if (!$periodes) {
        throw new RuntimeException("Aucune période trouvée pour la zone {$etab['zone']} en $annee.");
    }
    $pdo = db();
    $pdo->beginTransaction();
    // Remplace les vacances officielles de cette année scolaire (les périodes saisies à la main restent)
    $pdo->prepare("DELETE FROM vacances WHERE etablissement_id = ? AND source = 'officiel' AND debut >= ? AND debut < ?")
        ->execute([$etab['id'], "$a1-08-01", "$a2-08-01"]);
    $ins = $pdo->prepare("INSERT INTO vacances (etablissement_id, libelle, debut, fin, source) VALUES (?, ?, ?, ?, 'officiel')");
    foreach ($periodes as $p) {
        $ins->execute([$etab['id'], $p['libelle'], $p['debut'], $p['fin']]);
    }
    $pdo->commit();
    return count($periodes);
}

/* ---------- Semaines A/B ---------- */

/** 'A', 'B' ou '' (alternance désactivée) pour la semaine d'une date. */
function semaine_ab(array $etab, string $date): string
{
    if (!$etab['ab_actif'] || !date_valide($etab['ref_semaine_a'])) {
        return '';
    }
    $ref = lundi_de($etab['ref_semaine_a']);
    $cible = lundi_de($date);
    if (!$etab['ab_sans_vacances']) {
        $ecart = (int) round(((new DateTimeImmutable($cible))->getTimestamp() - (new DateTimeImmutable($ref))->getTimestamp()) / 604800);
        return $ecart % 2 === 0 ? 'A' : 'B';
    }
    // On ne compte que les semaines où il y a cours
    if (semaine_de_vacances((int) $etab['id'], $cible)) {
        return '';
    }
    $sens = $cible >= $ref ? 7 : -7;
    $n = 0;
    for ($l = $ref; $l !== $cible; $l = ajouter_jours($l, $sens)) {
        if (!semaine_de_vacances((int) $etab['id'], $l)) {
            $n++;
        }
        if (abs($n) > 600) {
            break;
        }
    }
    return $n % 2 === 0 ? 'A' : 'B';
}

/* ---------- Emploi du temps ---------- */

/**
 * Séances d'une semaine (à partir du lundi) pour un établissement : cours récurrents,
 * moins les cours annulés ou déplacés, plus les cours déplacés ici et les cours ajoutés.
 *
 * Chaque séance : [cle, cours_id, modif_id, statut (normal|annule|modifie|ajoute), date, jour,
 *   classe_id, classe, creneau_id, duree, salle_id, salle, intitule, note, date_origine]
 * Renvoie ['semaine' => A|B|'', 'jours' => [date => libellé vacances|null], 'seances' => [...]]
 */
function semaine_emploi_du_temps(array $etab, string $lundi): array
{
    $pdo = db();
    $eid = (int) $etab['id'];
    $nb_jours = $etab['samedi'] ? 6 : 5;
    $jours = [];
    for ($i = 0; $i < $nb_jours; $i++) {
        $d = ajouter_jours($lundi, $i);
        $jours[$d] = en_vacances($eid, $d);
    }
    $dimanche = ajouter_jours($lundi, 6);
    $sem = semaine_ab($etab, $lundi);

    $salles = [];
    foreach ($pdo->query('SELECT id, nom FROM salles')->fetchAll() as $s) {
        $salles[(int) $s['id']] = $s['nom'];
    }

    $st = $pdo->prepare('SELECT co.*, cl.nom AS classe FROM cours co JOIN classes cl ON cl.id = co.classe_id
                         WHERE cl.etablissement_id = ?');
    $st->execute([$eid]);
    $cours = $st->fetchAll();

    // Modifications concernant cette semaine (par date d'origine ou par date effective)
    $st = $pdo->prepare('SELECT m.*, cl.nom AS classe FROM seances_modifiees m JOIN classes cl ON cl.id = m.classe_id
                         WHERE cl.etablissement_id = ? AND ((m.date_origine BETWEEN ? AND ?) OR (m.date BETWEEN ? AND ?))');
    $st->execute([$eid, $lundi, $dimanche, $lundi, $dimanche]);
    $modifs = $st->fetchAll();
    $par_origine = [];
    foreach ($modifs as $m) {
        if ($m['cours_id'] !== null && $m['date_origine'] !== '') {
            $par_origine[$m['cours_id'] . '|' . $m['date_origine']] = $m;
        }
    }

    $seances = [];
    foreach ($jours as $d => $vac) {
        if ($vac !== null) {
            continue;
        }
        $j = jour_semaine($d);
        foreach ($cours as $c) {
            if ((int) $c['jour'] !== $j || ($c['semaine'] !== '' && $c['semaine'] !== $sem)) {
                continue;
            }
            if (($c['debut'] !== '' && $d < $c['debut']) || ($c['fin'] !== '' && $d > $c['fin'])) {
                continue;
            }
            $m = $par_origine[$c['id'] . '|' . $d] ?? null;
            if ($m && $m['statut'] === 'modifie') {
                continue; // affichée à sa nouvelle place (ci-dessous)
            }
            $seances[] = [
                'cle' => 'c' . $c['id'] . '_' . $d, 'cours_id' => (int) $c['id'], 'modif_id' => $m ? (int) $m['id'] : null,
                'statut' => $m ? 'annule' : 'normal', 'date' => $d, 'jour' => $j, 'classe_id' => (int) $c['classe_id'],
                'classe' => $c['classe'], 'creneau_id' => (int) $c['creneau_id'], 'duree' => max(1, (int) $c['duree']),
                'salle_id' => $c['salle_id'] !== null ? (int) $c['salle_id'] : null,
                'salle' => $c['salle_id'] !== null ? ($salles[(int) $c['salle_id']] ?? '') : '',
                'intitule' => $c['intitule'], 'note' => $m['note'] ?? '', 'date_origine' => $d,
            ];
        }
    }
    foreach ($modifs as $m) {
        if ($m['statut'] === 'annule' || $m['date'] < $lundi || $m['date'] > $dimanche || $m['creneau_id'] === null) {
            continue;
        }
        $sid = $m['salle_id'] !== null ? (int) $m['salle_id'] : null;
        $seances[] = [
            'cle' => 'm' . $m['id'], 'cours_id' => $m['cours_id'] !== null ? (int) $m['cours_id'] : null, 'modif_id' => (int) $m['id'],
            'statut' => $m['statut'], 'date' => $m['date'], 'jour' => jour_semaine($m['date']), 'classe_id' => (int) $m['classe_id'],
            'classe' => $m['classe'], 'creneau_id' => (int) $m['creneau_id'], 'duree' => max(1, (int) $m['duree']),
            'salle_id' => $sid, 'salle' => $sid !== null ? ($salles[$sid] ?? '') : '',
            'intitule' => $m['intitule'], 'note' => $m['note'], 'date_origine' => $m['date_origine'],
        ];
    }
    return ['semaine' => $sem, 'jours' => $jours, 'seances' => $seances];
}

/** Séances d'un jour, tous établissements confondus, triées par heure (pour le tableau de bord). */
function seances_du_jour(string $date): array
{
    $res = [];
    foreach (db()->query('SELECT * FROM etablissements ORDER BY nom')->fetchAll() as $e) {
        $creneaux = [];
        foreach (creneaux_de((int) $e['id']) as $c) {
            $creneaux[(int) $c['id']] = $c;
        }
        $semaine = semaine_emploi_du_temps($e, lundi_de($date));
        foreach ($semaine['seances'] as $s) {
            if ($s['date'] !== $date || !isset($creneaux[$s['creneau_id']])) {
                continue;
            }
            $c = $creneaux[$s['creneau_id']];
            $fin = $c['fin'];
            // Fin réelle : dernier créneau couvert par la durée
            $liste = array_values($creneaux);
            $idx = array_search($s['creneau_id'], array_column($liste, 'id'));
            if ($idx !== false && isset($liste[$idx + $s['duree'] - 1])) {
                $fin = $liste[$idx + $s['duree'] - 1]['fin'];
            }
            $res[] = $s + ['etablissement' => $e['nom'], 'etablissement_id' => (int) $e['id'], 'debut' => $c['debut'], 'fin' => $fin,
                           'creneau' => $c['libelle']];
        }
    }
    usort($res, fn($a, $b) => strcmp($a['debut'], $b['debut']) ?: strcmp($a['classe'], $b['classe']));
    return $res;
}

/** Lit une grille horaire saisie en texte : « M1 08:00 08:55 » par ligne. */
function lire_grille(string $texte): array
{
    $res = [];
    $erreurs = [];
    foreach (preg_split('/\R/', $texte) ?: [] as $n => $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '') {
            continue;
        }
        if (!preg_match('/^(\S+)\s+(\d{1,2})[:hH](\d{2})\s*[-–à ]?\s*(\d{1,2})[:hH](\d{2})$/u', $ligne, $m)) {
            $erreurs[] = 'Ligne ' . ($n + 1) . ' : format attendu « M1 08:00 08:55 ».';
            continue;
        }
        $debut = sprintf('%02d:%02d', $m[2], $m[3]);
        $fin = sprintf('%02d:%02d', $m[4], $m[5]);
        if ($fin <= $debut) {
            $erreurs[] = 'Ligne ' . ($n + 1) . " : l'heure de fin doit être après le début.";
            continue;
        }
        $res[] = ['libelle' => mb_substr($m[1], 0, 10), 'debut' => $debut, 'fin' => $fin];
    }
    usort($res, fn($a, $b) => strcmp($a['debut'], $b['debut']));
    return [$res, $erreurs];
}

/* ---------- Plans de salle ---------- */

const TYPES_PLAN = ['table', 'poste', 'place', 'tableau', 'bureau', 'porte', 'fenetre', 'armoire', 'paillasse'];
const TYPES_PLACES = ['poste', 'place'];

/** Nettoie un plan envoyé par l'éditeur. */
function plan_propre(string $json): ?array
{
    $p = json_decode($json, true);
    if (!is_array($p) || !isset($p['objets']) || !is_array($p['objets'])) {
        return null;
    }
    $largeur = max(4, min(60, (float) ($p['largeur'] ?? 16)));
    $hauteur = max(4, min(60, (float) ($p['hauteur'] ?? 11)));
    $objets = [];
    $ids = [];
    foreach (array_slice($p['objets'], 0, 400) as $o) {
        if (!is_array($o) || !in_array($o['type'] ?? '', TYPES_PLAN, true)) {
            continue;
        }
        $id = preg_replace('/[^a-z0-9_-]/i', '', (string) ($o['id'] ?? '')) ?: 'o' . bin2hex(random_bytes(4));
        if (isset($ids[$id])) {
            $id .= '-' . bin2hex(random_bytes(2));
        }
        $ids[$id] = true;
        $objets[] = [
            'id'    => substr($id, 0, 24),
            'type'  => $o['type'],
            'x'     => round(max(0, min($largeur, (float) ($o['x'] ?? 0))) * 2) / 2,
            'y'     => round(max(0, min($hauteur, (float) ($o['y'] ?? 0))) * 2) / 2,
            'l'     => max(0.5, min(20, round((float) ($o['l'] ?? 1) * 2) / 2)),
            'h'     => max(0.5, min(20, round((float) ($o['h'] ?? 1) * 2) / 2)),
            'r'     => in_array((int) ($o['r'] ?? 0), [0, 90, 180, 270], true) ? (int) $o['r'] : 0,
            'label' => mb_substr(trim((string) ($o['label'] ?? '')), 0, 20),
        ];
    }
    return ['largeur' => $largeur, 'hauteur' => $hauteur, 'objets' => $objets];
}

function plan_de(array $salle): array
{
    $p = json_decode((string) $salle['plan'], true);
    return is_array($p) && isset($p['objets']) ? $p : ['largeur' => 16, 'hauteur' => 11, 'objets' => []];
}
