<?php
/**
 * Centrale élèves — repères « SUR LA PLATEFORME » des élèves d'une classe, en icônes sur le plan de classe de l'accueil.
 *
 * Calculés à partir de ce que le serveur sait déjà (progression, mesures, connexions), toujours par comparaison
 * avec le reste de la classe :
 *   avance       activités terminées : au moins 1,5 fois la médiane de la classe
 *   retard       activités terminées : au plus 60 % de la médiane
 *   difficulte   moins de 40 % de réussite du premier coup, ou 3 essais par question ou plus
 *   corrections  affiche souvent les corrections
 *   retardataire se connecte en général 8 min ou plus après les autres élèves de son groupe
 *   absences     peu de connexions : deux fois moins de jours que la classe (8 dernières semaines)
 *   decroche     plus aucune activité depuis 2 semaines alors que la classe travaille
 *   jamais       jamais connecté
 * Les icônes et couleurs sont dans public/admin/plan.js (PlanSalle.REPERES). Ce qui est OBSERVÉ EN CLASSE (appel, cartons)
 * reste dans le navigateur de l'enseignant et s'affiche à part (PlanSalle.CLASSE) : on ne mélange jamais les deux sources.
 */
declare(strict_types=1);

/** Médiane d'une liste de nombres (null si vide). */
function mediane_nombres(array $v): ?float
{
    $v = array_values(array_filter($v, fn($x) => $x !== null));
    if (!$v) {
        return null;
    }
    sort($v);
    $n = count($v);
    return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

/**
 * Chiffres de la plateforme par élève (feuille de séance de l'accueil) : eleve_id => [ terminees, commencees,
 * premier_coup (% ou null), temps (secondes actives), derniere (horodatage ou null), jours (de connexion, 8 semaines) ],
 * plus « classe » => médianes. Calculés avec les repères (reperes_classe), une fois par requête.
 */
function statistiques_classe(int $classe_id, ?array $definir = null): array
{
    static $cache = [];
    if ($definir !== null) {
        return $cache[$classe_id] = $definir;
    }
    if (!isset($cache[$classe_id])) {
        reperes_classe($classe_id);
    }
    return $cache[$classe_id] ?? [];
}

/** Repères des élèves d'une classe : eleve_id => [ ['code' => …, 'texte' => …], … ]. Mémoïsé par requête. */
function reperes_classe(int $classe_id): array
{
    static $cache = [];
    if (isset($cache[$classe_id])) {
        return $cache[$classe_id];
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT id, groupe_id, derniere_connexion FROM eleves WHERE classe_id = ? AND actif = 1');
    $st->execute([$classe_id]);
    $eleves = [];
    foreach ($st->fetchAll() as $e) {
        $eleves[(int) $e['id']] = ['groupe' => (int) $e['groupe_id'], 'dc' => $e['derniere_connexion'] !== null ? (int) $e['derniere_connexion'] : null,
            'fin' => 0, 'q' => 0, 'ok1' => 0, 'essais' => 0, 'rev' => 0, 'maj' => 0, 'jours' => [], 'premier' => [], 'temps' => 0, 'commencees' => 0,
            'en_cours' => null, 'derniere_act' => null];
    }
    if (!$eleves) {
        statistiques_classe($classe_id, []);
        return $cache[$classe_id] = [];
    }
    $ids = implode(',', array_keys($eleves));
    $vues = [];   // activités travaillées par au moins un élève de la classe : le « programme » déjà abordé
    foreach ($pdo->query("SELECT eleve_id, app, activite, titre, statut, faites, total, questions, premier_coup, essais, revelees, temps_actif, maj FROM progression WHERE eleve_id IN ($ids)") as $p) {
        $x = &$eleves[(int) $p['eleve_id']];
        $vues[$p['app'] . '|' . $p['activite']] = true;
        $x['commencees']++;
        if (!$x['derniere_act'] || (int) $p['maj'] > $x['derniere_act']['maj']) {   // ce sur quoi l'élève a travaillé en dernier (atelier en cours de la classe)
            $x['derniere_act'] = ['app' => (string) $p['app'], 'activite' => (string) $p['activite'], 'titre' => (string) $p['titre'], 'maj' => (int) $p['maj']];
        }
        if ($p['statut'] !== 'termine' && (!$x['en_cours'] || (int) $p['maj'] > $x['en_cours']['maj'])) {   // l'activité en cours la plus récente
            $x['en_cours'] = ['titre' => $p['titre'] !== '' ? $p['titre'] : $p['activite'], 'app' => $p['app'],
                'faites' => $p['faites'] !== null ? (int) $p['faites'] : null, 'total' => $p['total'] !== null ? (int) $p['total'] : null, 'maj' => (int) $p['maj']];
        }
        $x['temps'] += (int) $p['temps_actif'];
        $x['fin'] += $p['statut'] === 'termine' ? 1 : 0;
        $x['q'] += (int) $p['questions'];
        $x['ok1'] += (int) $p['premier_coup'];
        $x['essais'] += (int) $p['essais'];
        $x['rev'] += (int) $p['revelees'];
        $x['maj'] = max($x['maj'], (int) $p['maj']);
        unset($x);
    }
    $depuis = time() - 56 * 86400;
    $tz = fuseau();
    foreach ($pdo->query("SELECT eleve_id, ts FROM connexions WHERE eleve_id IN ($ids) AND ts >= $depuis ORDER BY ts") as $c) {
        $x = &$eleves[(int) $c['eleve_id']];
        $jour = (new DateTimeImmutable('@' . $c['ts']))->setTimezone($tz)->format('Y-m-d');
        $x['jours'][$jour] = true;
        $x['premier'][$jour] ??= (int) $c['ts'];
        $x['maj'] = max($x['maj'], (int) $c['ts']);
        unset($x);
    }

    // La classe de référence : les élèves qui ont déjà travaillé ou se sont connectés
    $actifs = array_filter($eleves, fn($e) => $e['maj'] > 0 || $e['dc']);
    $med_fin = mediane_nombres(array_column($actifs, 'fin'));
    $med_jours = mediane_nombres(array_map(fn($e) => count($e['jours']), $actifs));
    $med_maj = mediane_nombres(array_column($actifs, 'maj'));

    // Retards à l'arrivée : écart entre la première connexion de l'élève et celle, médiane, de son groupe le même jour
    $ecarts = [];
    $par_jour = [];
    foreach ($eleves as $id => $e) {
        foreach ($e['premier'] as $jour => $ts) {
            $par_jour[$jour . '|' . $e['groupe']][$id] = $ts;
        }
    }
    foreach ($par_jour as $lot) {
        if (count($lot) < 4) {
            continue;   // trop peu d'élèves ce jour-là (travail à la maison) : pas de comparaison
        }
        $ref = mediane_nombres(array_values($lot));
        foreach ($lot as $id => $ts) {
            $ecarts[$id][] = ($ts - $ref) / 60;
        }
    }

    $res = [];
    foreach ($eleves as $id => $e) {
        $r = [];
        $vu = $e['maj'] > 0 || $e['dc'];
        if (!$vu) {
            $res[$id] = [['code' => 'jamais', 'texte' => 'Jamais connecté']];
            continue;
        }
        if ($med_fin !== null && $med_fin >= 2) {
            if ($e['fin'] >= $med_fin * 1.5 && $e['fin'] >= $med_fin + 3) {
                $r[] = ['code' => 'avance', 'texte' => $e['fin'] . ' activités terminées (classe : ' . round($med_fin) . ')'];
            } elseif ($e['fin'] <= $med_fin * .6) {
                $r[] = ['code' => 'retard', 'texte' => $e['fin'] . ' activité' . ($e['fin'] > 1 ? 's' : '') . ' terminée' . ($e['fin'] > 1 ? 's' : '') . ' (classe : ' . round($med_fin) . ')'];
            }
        }
        if ($e['q'] >= 8) {
            $taux = $e['ok1'] / $e['q'];
            $par_q = $e['essais'] / $e['q'];
            if ($taux < .4 || $par_q >= 3) {
                $r[] = ['code' => 'difficulte', 'texte' => round($taux * 100) . ' % de réussite du 1er coup, ' . str_replace('.', ',', (string) round($par_q, 1)) . ' essais par question'];
            }
        }
        if ($e['rev'] >= 5 && $e['rev'] >= .3 * max(1, $e['q'])) {
            $r[] = ['code' => 'corrections', 'texte' => $e['rev'] . ' corrections affichées'];
        }
        if (count($ecarts[$id] ?? []) >= 3 && ($m = mediane_nombres($ecarts[$id])) >= 8) {
            $r[] = ['code' => 'retardataire', 'texte' => 'Se connecte en général ' . round($m) . ' min après son groupe (' . count($ecarts[$id]) . ' séances)'];
        }
        if ($med_jours !== null && $med_jours >= 3 && count($e['jours']) <= $med_jours * .5) {
            $r[] = ['code' => 'absences', 'texte' => 'Connecté ' . count($e['jours']) . ' jour' . (count($e['jours']) > 1 ? 's' : '') . ' en 8 semaines (classe : ' . round($med_jours) . ')'];
        }
        $derniere = max($e['maj'], (int) $e['dc']);
        if ($med_maj !== null && $med_maj >= time() - 7 * 86400 && $derniere < time() - 14 * 86400) {
            $r[] = ['code' => 'decroche', 'texte' => 'Plus d’activité depuis le ' . date('d/m', $derniere)];
        }
        $res[$id] = $r;
    }
    $nb_vues = count($vues);
    $stats = ['classe' => ['terminees' => $med_fin, 'jours' => $med_jours, 'programme' => $nb_vues,
        'avancement' => $nb_vues ? mediane_nombres(array_map(fn($e) => round(100 * $e['fin'] / $nb_vues), $actifs)) : null,
        'temps' => mediane_nombres(array_column($actifs, 'temps')),
        'premier_coup' => mediane_nombres(array_map(fn($e) => $e['q'] >= 3 ? round(100 * $e['ok1'] / $e['q']) : null, $actifs)),
        'atelier' => atelier_en_cours($classe_id, $eleves)]];
    foreach ($eleves as $id => $e) {
        $stats[$id] = ['terminees' => $e['fin'], 'commencees' => $e['commencees'], 'en_cours' => $e['en_cours'],
                       'avancement' => $nb_vues ? (int) round(100 * $e['fin'] / $nb_vues) : null, 'premier_coup' => $e['q'] >= 3 ? (int) round(100 * $e['ok1'] / $e['q']) : null,
                       'temps' => $e['temps'], 'derniere' => max($e['maj'], (int) $e['dc']) ?: null, 'jours' => count($e['jours'])];
    }
    statistiques_classe($classe_id, $stats);
    return $cache[$classe_id] = $res;
}

/**
 * Atelier en cours d'une classe (feuille de séance) : celui sur lequel le plus d'élèves ont travaillé en dernier, dans
 * les 3 dernières semaines ; à défaut, un atelier ouvert pour la classe aujourd'hui (période de disponibilité).
 * (La présentation et la correction de l'atelier papier passent par FicheStudio : fiche_en_cours(), lib/admin_accueil.php.)
 */
function atelier_en_cours(int $classe_id, array $eleves): ?array
{
    $compte = [];
    $depuis = time() - 21 * 86400;
    foreach ($eleves as $e) {
        $x = $e['derniere_act'] ?? null;
        if (!$x || $x['maj'] < $depuis || $x['activite'] === '') {
            continue;
        }
        $at = explode('/', $x['activite'])[0];   // TechnoStudio : « atelier/feuille »
        $k = $x['app'] . '|' . $at;
        $compte[$k] ??= ['app' => $x['app'], 'atelier' => $at, 'titre' => $x['titre'], 'nb' => 0, 'maj' => 0];
        $compte[$k]['nb']++;
        $compte[$k]['maj'] = max($compte[$k]['maj'], $x['maj']);
    }
    $choix = null;
    foreach ($compte as $c) {
        if (!$choix || $c['nb'] > $choix['nb'] || ($c['nb'] === $choix['nb'] && $c['maj'] > $choix['maj'])) {
            $choix = $c;
        }
    }
    if ($choix) {
        $choix['source'] = 'eleves';
        $choix['sur'] = count(array_filter($eleves, fn($e) => $e['maj'] > 0 || $e['dc']));
    } else {
        try {   // aucun travail récent : un atelier ouvert pour la classe aujourd'hui
            $auj = aujourd_hui();
            $st = db()->prepare("SELECT app, atelier FROM atelier_periodes WHERE classe_id = ? AND (debut = '' OR debut <= ?) AND (fin = '' OR fin >= ?) ORDER BY debut DESC, id DESC LIMIT 1");
            $st->execute([$classe_id, $auj, $auj]);
            if ($r = $st->fetch()) {
                $choix = ['app' => (string) $r['app'], 'atelier' => (string) $r['atelier'], 'titre' => '', 'nb' => 0, 'sur' => 0, 'source' => 'periode'];
            }
        } catch (Throwable $e) {
            $choix = null;
        }
    }
    if (!$choix) {
        return null;
    }
    try {
        $st = db()->prepare('SELECT titre FROM catalogue_ateliers WHERE app = ? AND atelier = ?');
        $st->execute([$choix['app'], $choix['atelier']]);
        if (($t = (string) $st->fetchColumn()) !== '') {
            $choix['titre'] = $t;
        }
    } catch (Throwable $e) {
        // pas de catalogue : on garde le titre de l'activité
    }
    $st = db()->prepare('SELECT nom FROM ateliers WHERE app = ?');
    $st->execute([$choix['app']]);
    $choix['app_nom'] = (string) ($st->fetchColumn() ?: $choix['app']);
    if ($choix['titre'] === '') {
        $choix['titre'] = $choix['atelier'];
    }
    unset($choix['maj']);
    return $choix;
}
