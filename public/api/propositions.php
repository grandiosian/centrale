<?php
/**
 * GET /api/propositions.php (en-tête X-Jeton)
 * Activités que le professeur a proposées à l'élève (sa classe ou son groupe), avec son avancement.
 *
 * → { ok, propositions: [ { app, atelier, icone, url, activite, titre, groupe, pour_le, consigne,
 *        statut: a_faire|commence|termine, texte } ] }   (à rendre d'abord, par date ; sans date ensuite)
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();

$prog = progression_eleves([$eleve['id']])[$eleve['id']] ?? [];
$catalogue = catalogue_activites();
$ateliers = [];
foreach (db()->query('SELECT * FROM ateliers WHERE actif = 1')->fetchAll() as $a) {
    $ateliers[$a['app']] = $a;
}

$res = [];
foreach (propositions_eleve((int) $eleve['id']) as $app => $liste) {
    if (!isset($ateliers[$app])) {
        continue;   // atelier retiré ou masqué
    }
    $a = $ateliers[$app];
    foreach ($liste as $id => $p) {
        $c = $catalogue[$app][$id] ?? null;
        $etat = etat_etape($id, $prog[$app] ?? [], $catalogue[$app] ?? []);
        $res[] = ['app' => $app, 'atelier' => $a['nom'], 'icone' => $a['icone'], 'url' => meilleure_adresse($a),
                  'activite' => $id, 'titre' => $c ? ($c['titre'] ?: $id) : $id, 'groupe' => $c['groupe'] ?? '',
                  'pour_le' => $p['pour_le'], 'consigne' => $p['consigne'],
                  'statut' => $etat['statut'], 'texte' => $etat['texte']];
    }
}
usort($res, fn($x, $y) => [$x['pour_le'] === '', $x['pour_le']] <=> [$y['pour_le'] === '', $y['pour_le']]);

envoyer_json(200, ['ok' => true, 'propositions' => $res]);
