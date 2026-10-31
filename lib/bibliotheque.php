<?php
/**
 * Centrale élèves — bibliothèque : toutes les activités de toutes les applications (déclarées par leurs
 * passerelles ou à la connexion des élèves), et activités proposées par l'enseignant à une classe ou un groupe.
 */
declare(strict_types=1);

const NIVEAUX_BIBLIOTHEQUE = ['6' => '6ᵉ', '5' => '5ᵉ', '4' => '4ᵉ', '3' => '3ᵉ', 'lycee' => 'Lycée / Pro', '' => 'Tous niveaux'];

/** Niveau scolaire d'une activité, d'après son groupe ou son titre (« 5ᵉ · Débutant », « 4e · Le parking »…). */
function niveau_activite(string $groupe, string $titre = ''): string
{
    foreach ([$groupe, $titre] as $t) {
        if (preg_match('/(?<![\p{L}\d])([3-6])\s*(?:e|è|ᵉ|ème|eme)(?![\p{L}])/iu', $t, $m)) {
            return $m[1];
        }
        if (preg_match('/\b(2nde|seconde|1re|1ère|première|terminale|STI2D|SNT|NSI|lycée|Pro|BTS|CAP)\b/iu', $t)) {
            return 'lycee';
        }
    }
    return '';
}

/**
 * Toutes les activités connues, par application (ordre des ateliers), avec le nombre d'élèves
 * qui les ont commencées / terminées.
 */
function bibliotheque(): array
{
    $pdo = db();
    $ateliers = [];
    foreach ($pdo->query('SELECT * FROM ateliers ORDER BY ordre, nom')->fetchAll() as $a) {
        $ateliers[$a['app']] = $a;
    }
    $stats = [];
    foreach ($pdo->query("SELECT app, activite, SUM(statut = 'termine') AS t, COUNT(*) AS n FROM progression GROUP BY app, activite") as $r) {
        $stats[$r['app']][$r['activite']] = [(int) $r['t'], (int) $r['n']];
    }
    $res = [];
    foreach (catalogue_activites() as $app => $acts) {
        if ($app === 'portail') {
            continue;
        }
        $d = descripteur_de($app);
        $details = [];
        foreach ((array) ($d['activites']['liste'] ?? []) as $x) {
            if (is_array($x) && isset($x['id'])) {
                $details[(string) $x['id']] = $x;
            }
        }
        foreach ($acts as $id => $c) {
            $x = $details[$id] ?? [];
            $res[] = [
                'app' => $app, 'activite' => $id, 'titre' => $c['titre'] ?: $id, 'groupe' => $c['groupe'],
                'total' => $c['total'] !== null ? (int) $c['total'] : null,
                'description' => is_string($x['description'] ?? null) ? $x['description'] : '',
                'niveau' => niveau_activite((string) $c['groupe'], (string) $c['titre']),
                'atelier' => $ateliers[$app] ?? null,
                'terminees' => $stats[$app][$id][0] ?? 0, 'commencees' => $stats[$app][$id][1] ?? 0,
                'ordre' => (int) $c['ordre'],
            ];
        }
    }
    $rang = array_flip(array_keys($ateliers));
    usort($res, fn($a, $b) => [$rang[$a['app']] ?? 999, $a['app'], $a['ordre']] <=> [$rang[$b['app']] ?? 999, $b['app'], $b['ordre']]);
    return $res;
}

/** Propositions de toutes les classes, par « app|activite ». */
function propositions_par_activite(): array
{
    $res = [];
    $st = db()->query('SELECT p.*, c.nom AS classe, g.nom AS groupe FROM propositions p JOIN classes c ON c.id = p.classe_id
                       LEFT JOIN groupes g ON g.id = p.groupe_id ORDER BY c.nom, g.nom');
    foreach ($st->fetchAll() as $p) {
        $res[$p['app'] . '|' . $p['activite']][] = $p;
    }
    return $res;
}

/** Activités proposées à un élève (sa classe entière ou son groupe), « app » => [activite => proposition]. */
function propositions_eleve(int $eleve_id): array
{
    $st = db()->prepare('SELECT p.* FROM propositions p JOIN eleves e ON e.classe_id = p.classe_id
                         WHERE e.id = ? AND (p.groupe_id = 0 OR p.groupe_id = e.groupe_id)
                         ORDER BY p.pour_le = \'\', p.pour_le, p.cree_le, p.id');
    $st->execute([$eleve_id]);
    $res = [];
    foreach ($st->fetchAll() as $p) {
        $res[$p['app']][$p['activite']] ??= $p;   // classe entière et groupe : la première suffit
    }
    return $res;
}

/** Le portail montre-t-il toutes les activités des ateliers, ou seulement celles proposées (et celles commencées) ? */
function portail_seulement_proposees(): bool
{
    return reglage('portail_activites', 'proposees') !== 'toutes';
}
