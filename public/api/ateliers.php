<?php
/**
 * GET /api/ateliers.php (en-tête X-Jeton)
 * Ateliers proposés à la classe de l'élève, avec son avancement et la liste des activités.
 *
 * → { ok, ateliers: [ { app, nom, description, url, icone, taux, terminees, total, maj,
 *        activites: [ { id, titre, groupe, statut: a_faire|commence|termine, texte, propose } ] } ] }
 *
 * Réglage « portail_activites » (page Bibliothèque de l'espace enseignant) : « proposees » (par défaut) ne liste
 * que les activités proposées à l'élève et celles qu'il a déjà commencées ; « toutes » liste tout le catalogue.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();

$prog = progression_eleves([$eleve['id']])[$eleve['id']] ?? [];
$catalogue = catalogue_activites();
$proposees = propositions_eleve((int) $eleve['id']);
$filtrer = portail_seulement_proposees();

$res = [];
foreach (ateliers_pour_classe($eleve['classe']) as $a) {
    $app = $a['app'];
    $lignes = $prog[$app] ?? [];
    $cat = $catalogue[$app] ?? [];
    $activites = [];
    $taux = [];
    foreach ($cat as $id => $c) {
        $propose = isset($proposees[$app][$id]);
        if ($filtrer && !$propose && !isset($lignes[$id])) {
            continue;
        }
        $etat = etat_etape($id, $lignes, $cat);
        $taux[] = $etat['taux'];
        $activites[] = ['id' => $id, 'titre' => $c['titre'] ?: $id, 'groupe' => $c['groupe'],
                        'statut' => $etat['statut'], 'texte' => $etat['texte'], 'propose' => $propose];
    }
    foreach ($lignes as $id => $l) { // commencées mais absentes de la liste déclarée
        if (!isset($cat[$id])) {
            $etat = etat_etape($id, $lignes, []);
            $taux[] = $etat['taux'];
            $activites[] = ['id' => $id, 'titre' => $l['titre'] ?: $id, 'groupe' => '',
                            'statut' => $etat['statut'], 'texte' => $etat['texte'], 'propose' => isset($proposees[$app][$id])];
        }
    }
    $global = etat_etape('', $lignes, $cat);
    $res[] = [
        'app'         => $app,
        'nom'         => $a['nom'],
        'description' => $a['description'],
        'url'         => meilleure_adresse($a),   // adresse de secours si la principale ne répond plus
        'icone'       => $a['icone'],
        'taux'        => round($filtrer ? ($taux ? array_sum($taux) / count($taux) : 0) : $global['taux'], 3),
        'terminees'   => count(array_filter($activites, fn($x) => $x['statut'] === 'termine')),
        'total'       => count($activites),
        'maj'         => $global['maj'],
        'activites'   => $activites,
    ];
}

envoyer_json(200, ['ok' => true, 'ateliers' => $res]);
