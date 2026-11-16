<?php
/**
 * Ateliers transférés par la Centrale (dossier ateliers/<app>/ du dépôt, lib/ateliers_centrale.php).
 * Lecture seule, publique (aucune donnée d'élève), ouverte à tous les sites (CORS « * », sans cookie) :
 * une application les lit même sans élève connecté, puis en garde une copie pour les pannes du serveur.
 *
 * GET /api/atelier-fichier.php?app=technostudio
 *   → { ok, format: "ateliers.centrale", version: 1, app, ateliers: [ { id, format, version, titre, fichier, octets,
 *        level, niveau, seq, resume, problematique, feuilles } ] }  (champs de l'atelier recopiés s'ils existent)
 * GET /api/atelier-fichier.php?app=technostudio&id=radar-recul-robot-mbot
 *   → le fichier de l'atelier, tel quel (format de l'application, avec son « format », sa « version » et son « id »).
 *   En-têtes : ETag (If-None-Match → 304), X-Version (version de l'atelier).
 * Erreurs : 400 (adresse), 404 (pas de fichier), 422 (fichier illisible) : { ok: false, erreur }.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: If-None-Match');
header('Access-Control-Expose-Headers: ETag, X-Version');
header('Access-Control-Max-Age: 86400');
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($methode === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($methode !== 'GET' && $methode !== 'HEAD') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Lecture seule : GET.']);
}
$app = (string) ($_GET['app'] ?? '');
$id = (string) ($_GET['id'] ?? '');
if (!app_atelier_centrale_valide($app) || ($id !== '' && !id_atelier_centrale_valide($id))) {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Adresse invalide : ?app=<application>[&id=<atelier>].']);
}

if ($id === '') {
    $liste = [];
    foreach (array_keys(fichiers_ateliers_centrale($app)) as $x) {
        [$j, $brut] = lire_atelier_centrale($app, $x);
        if (!$j) {
            continue;
        }
        $e = ['id' => $x, 'format' => $j['format'], 'version' => $j['version'], 'titre' => (string) ($j['title'] ?? $j['titre'] ?? $x),
              'fichier' => adresse_api_atelier_centrale($app, $x), 'octets' => strlen($brut)];
        foreach (['level', 'niveau', 'seq', 'summary', 'problematique', 'duration'] as $k) {
            if (isset($j[$k]) && is_scalar($j[$k])) {
                $e[$k === 'summary' ? 'resume' : $k] = is_string($j[$k]) ? mb_substr($j[$k], 0, 600) : $j[$k];
            }
        }
        if (is_array($j['steps'] ?? null)) {
            $e['feuilles'] = count($j['steps']);
        }
        $liste[] = $e;
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode(['ok' => true, 'format' => 'ateliers.centrale', 'version' => 1, 'app' => $app, 'ateliers' => $liste],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

[$j, $brut, $err] = lire_atelier_centrale($app, $id);
if (!$j) {
    envoyer_json($brut === '' && str_starts_with($err, 'Pas de fichier') ? 404 : 422, ['ok' => false, 'erreur' => $err]);
}
$etag = '"' . sha1($brut) . '"';
header('ETag: ' . $etag);
header('X-Version: ' . $j['version']);
header('Cache-Control: no-cache');   // toujours revalidé : une nouvelle version remplace la copie gardée
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: inline; filename="' . $id . '.json"');
header('Content-Length: ' . strlen($brut));
if ($methode !== 'HEAD') {
    echo $brut;
}
