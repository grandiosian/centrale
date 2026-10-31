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

$statut = (string) ($e['statut'] ?? 'commence');
if (!in_array($statut, ['commence', 'en-cours', 'termine'], true)) {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Statut attendu : commence ou termine.']);
}
$statut = $statut === 'termine' ? 'termine' : 'commence';

$entier = fn($k) => isset($e[$k]) && is_numeric($e[$k]) ? max(0, min(100000, (int) $e[$k])) : null;
$reel = fn($k) => isset($e[$k]) && is_numeric($e[$k]) ? max(-1e9, min(1e9, (float) $e[$k])) : null;
$faites = $entier('faites');
$total = $entier('total');
$score = $reel('score');
$score_max = $reel('score_max');
$titre = mb_substr(trim((string) ($e['titre'] ?? '')), 0, 120);
$details = null;
if (array_key_exists('details', $e) && $e['details'] !== null) {
    $details = json_encode($e['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($details) > 20000) {
        envoyer_json(413, ['ok' => false, 'erreur' => 'Détails trop longs (20 Ko maximum).']);
    }
}

$st = $pdo->prepare('SELECT * FROM progression WHERE eleve_id = ? AND app = ? AND activite = ?');
$st->execute([$eleve['id'], $app, $activite]);
$ancien = $st->fetch() ?: null;

$max = fn($a, $b) => $a === null ? $b : ($b === null ? $a : max($a, $b));
$maintenant = time();
$final = [
    'titre'     => $titre !== '' ? $titre : ($ancien['titre'] ?? ''),
    'statut'    => ($statut === 'termine' || ($ancien['statut'] ?? '') === 'termine') ? 'termine' : 'commence',
    'faites'    => $max($ancien ? ($ancien['faites'] === null ? null : (int) $ancien['faites']) : null, $faites),
    'total'     => $total ?? ($ancien && $ancien['total'] !== null ? (int) $ancien['total'] : null),
    'score'     => $max($ancien ? ($ancien['score'] === null ? null : (float) $ancien['score']) : null, $score),
    'score_max' => $score_max ?? ($ancien && $ancien['score_max'] !== null ? (float) $ancien['score_max'] : null),
    'details'   => $details ?? ($ancien['details'] ?? null),
    'debut'     => $ancien ? (int) $ancien['debut'] : $maintenant,
    'fin'       => $ancien && $ancien['fin'] !== null ? (int) $ancien['fin'] : null,
];
if ($final['statut'] === 'termine' && $final['fin'] === null) {
    $final['fin'] = $maintenant;
}

$pdo->prepare('INSERT INTO progression (eleve_id, app, activite, titre, statut, faites, total, score, score_max, details, debut, maj, fin)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT (eleve_id, app, activite) DO UPDATE SET titre = excluded.titre, statut = excluded.statut,
               faites = excluded.faites, total = excluded.total, score = excluded.score, score_max = excluded.score_max,
               details = excluded.details, maj = excluded.maj, fin = excluded.fin')
    ->execute([$eleve['id'], $app, $activite, $final['titre'], $final['statut'], $final['faites'], $final['total'],
        $final['score'], $final['score_max'], $final['details'], $final['debut'], $maintenant, $final['fin']]);

// Constats de compétences joints par l'application : [{ competence, reussi, indices, essais, preuve } | { competence, niveau }]
$constats = is_array($e['constats'] ?? null) ? enregistrer_constats($eleve['id'], $app, $activite, $e['constats']) : 0;

envoyer_json(200, ['ok' => true, 'constats' => $constats, 'progression' => ligne_publique($final + [
    'activite' => $activite, 'maj' => $maintenant,
])]);
