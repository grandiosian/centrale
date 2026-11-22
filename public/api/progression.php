<?php
/**
 * Avancement de l'élève connecté dans les activités d'un atelier.
 * En-tête obligatoire : X-Jeton.
 *
 * POST { app, activite, titre?, statut?, faites?, total?, score?, score_max?, details? }
 *      statut : "commence" (par défaut) ou "termine".
 *      Le serveur garde le MEILLEUR résultat : une activité terminée le reste,
 *      faites et score ne baissent jamais si l'élève recommence.
 * GET  ?app=netsim     → { ok, progression: [ { activite, titre, statut, faites, total, score, score_max, debut, maj, fin } ] }
 * POST … constats: [ { competence: "SFC15.5.1", reussi, indices?, essais?, preuve? } ]  → évaluations (source = l'application)
 * POST … mesures: { temps_actif, essais, questions, premier_coup, revelees, delai_median, detail: [ { q, essais, ok1, okA, delai, revele } ] }
 *      Monitoring (facultatif) : cumuls de l'application, qui REMPLACENT les précédents (SPECIFICATION.md, § mesures).
 * GET  (sans app)      → { ok, progression: { app: [ … ] } }
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();
$pdo = db();

function ligne_publique(array $p): array
{
    return [
        'activite'  => $p['activite'],
        'titre'     => $p['titre'],
        'statut'    => $p['statut'],
        'faites'    => $p['faites'] === null ? null : (int) $p['faites'],
        'total'     => $p['total'] === null ? null : (int) $p['total'],
        'score'     => $p['score'] === null ? null : (float) $p['score'],
        'score_max' => $p['score_max'] === null ? null : (float) $p['score_max'],
        'debut'     => (int) $p['debut'],
        'maj'       => (int) $p['maj'],
        'fin'       => $p['fin'] === null ? null : (int) $p['fin'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $app = normaliser_app((string) ($_GET['app'] ?? ''));
    if ($app !== '*') {
        $st = $pdo->prepare('SELECT * FROM progression WHERE eleve_id = ? AND app = ? ORDER BY debut');
        $st->execute([$eleve['id'], $app]);
        envoyer_json(200, ['ok' => true, 'progression' => array_map('ligne_publique', $st->fetchAll())]);
    }
    $st = $pdo->prepare('SELECT * FROM progression WHERE eleve_id = ? ORDER BY app, debut');
    $st->execute([$eleve['id']]);
    $par_app = [];
    foreach ($st->fetchAll() as $p) {
        $par_app[$p['app']][] = ligne_publique($p);
    }
    envoyer_json(200, ['ok' => true, 'progression' => (object) $par_app]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Utilisez GET ou POST.']);
}

$e = lire_entree();
$app = app_requise($e);
$activite = activite_propre((string) ($e['activite'] ?? ''));
if ($activite === '') {
    envoyer_json(400, ['ok' => false, 'erreur' => "Identifiant d'activité manquant."]);
}

// Avancement resté en file dans le navigateur d'avant une remise à zéro par le professeur : ignoré (le module l'oublie)
if (prepare_avant_remise($eleve, $e['age'] ?? null)) {
    envoyer_json(200, ['ok' => true, 'ignore' => true, 'remis' => true]);
}

try {
    $r = ecrire_avancement_eleve((int) $eleve['id'], $app, $activite, $e);
} catch (InvalidArgumentException $x) {
    envoyer_json($x->getCode() ?: 400, ['ok' => false, 'erreur' => $x->getMessage()]);
}
$final = $r['ligne'];
$constats = $r['constats'];

envoyer_json(200, ['ok' => true, 'constats' => $constats, 'progression' => ligne_publique($final)]);
