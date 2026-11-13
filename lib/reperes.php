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
            'fin' => 0, 'q' => 0, 'ok1' => 0, 'essais' => 0, 'rev' => 0, 'maj' => 0, 'jours' => [], 'premier' => []];
    }
    if (!$eleves) {
        return $cache[$classe_id] = [];
    }
    $ids = implode(',', array_keys($eleves));
    foreach ($pdo->query("SELECT eleve_id, statut, questions, premier_coup, essais, revelees, maj FROM progression WHERE eleve_id IN ($ids)") as $p) {
        $x = &$eleves[(int) $p['eleve_id']];
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
    return $cache[$classe_id] = $res;
}
