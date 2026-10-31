<?php
/**
 * GET /api/catalogue.php[?app=netsim][&niveau=5e][&competence=SFC15]
 * Ateliers importés depuis les applications (page Ateliers → « Ateliers des applications »), sans jeton :
 * une application peut ainsi proposer les ateliers des autres (« pour aller plus loin : … dans NetSim »).
 * Aucune donnée d'élève. Sites autorisés : comme le reste de l'API.
 *
 * → { ok, format: "catalogue.ateliers", version: 1, ateliers: [ { app, nom_app, id, titre, niveau, theme, resume, duree,
 *        competences: [], activites: [ { id, titre, total, competences } ], ouvrir, adresse, fichier, version } ] }
 * « adresse » ouvre l'atelier dans son application (#activite=…), sans passer par la Centrale.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$app = normaliser_app((string) ($_GET['app'] ?? ''));
$niveau = niveau_atelier((string) ($_GET['niveau'] ?? ''));
$comp = strtoupper(preg_replace('/[^A-Za-z0-9.]/', '', (string) ($_GET['competence'] ?? '')) ?? '');

$res = [];
foreach (catalogue_ateliers($app === '*' ? null : $app, false) as $r) {
    if ($niveau !== '' && $r['niveau'] !== '' && $r['niveau'] !== $niveau) {
        continue;
    }
    if ($comp !== '' && !array_filter($r['codes'], fn($c) => str_starts_with($c, $comp))) {
        continue;
    }
    $res[] = ['app' => $r['app'], 'nom_app' => (string) ($r['nom_app'] ?? ''), 'id' => $r['atelier'], 'titre' => $r['titre'],
              'niveau' => $r['niveau'], 'theme' => $r['theme'], 'resume' => $r['resume'], 'duree' => $r['duree'],
              'competences' => $r['codes'], 'activites' => $r['activites'], 'ouvrir' => $r['ouvrir'],
              'adresse' => adresse_ouvrir_atelier($r), 'fichier' => $r['fichier'], 'version' => $r['version']];
}
header('Cache-Control: public, max-age=300');
envoyer_json(200, ['ok' => true, 'format' => FORMAT_CATALOGUE, 'version' => 1, 'ateliers' => $res]);
