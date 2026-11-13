<?php
/**
 * GET /admin/appli.php/<app>/<fichier>
 * Applications réservées à l'enseignant (ex. FicheStudio), copiées dans prive/miroir/<app>/ : HORS de la racine
 * web, donc introuvables à /miroir/. Ce script ne les sert qu'au navigateur connecté à l'espace enseignant ;
 * sinon il renvoie vers la page de connexion. Les fichiers sont des pages statiques : jamais exécutés ici.
 *
 * Le chemin passe par PATH_INFO pour que les adresses relatives de l'application (./assets/…) restent sous ce script.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/commun.php';

const DOSSIER_PRIVE = __DIR__ . '/../../prive/miroir';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

function refuser(int $statut, string $message): never
{
    http_response_code($statut);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($message);
}

// Session enseignant : lue seulement (comme api/enseignant.php), jamais créée ici
$enseignant = false;
if (isset($_COOKIE['centrale_admin']) && preg_match('/^[A-Za-z0-9,-]{16,128}$/', (string) $_COOKIE['centrale_admin'])) {
    session_name('centrale_admin');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => est_https()]);
    session_start(['read_and_close' => true]);
    $enseignant = !empty($_SESSION['admin']);
}
if (!$enseignant) {
    header('Cache-Control: no-store');
    header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/appli.php'), '/') . '/');   // page de connexion
    exit;
}

// <app>/<chemin> : nom d'application simple, aucun segment caché ni « .. »
$chemin = (string) ($_SERVER['PATH_INFO'] ?? '');
if (!preg_match('#^/([A-Za-z0-9_-]+)(/.*)?$#', $chemin, $m)) {
    refuser(404, 'Application inconnue.');
}
$app = $m[1];
$reste = $m[2] ?? '';
if ($reste === '') {
    // /admin/appli.php/fichestudio → avec la barre finale, sinon ./assets/ partirait de /admin/appli.php/
    header('Location: ' . $_SERVER['SCRIPT_NAME'] . '/' . $app . '/');
    exit;
}
$racine = realpath(DOSSIER_PRIVE . '/' . $app);
if ($racine === false || !is_dir($racine)) {
    refuser(404, "Cette application n'est pas installée sur ce serveur.");
}
$segments = array_values(array_filter(explode('/', rawurldecode($reste)), 'strlen'));
foreach ($segments as $s) {
    if ($s[0] === '.' || str_contains($s, "\0")) {
        refuser(404, 'Fichier introuvable.');
    }
}
$fichier = $racine . ($segments ? '/' . implode('/', $segments) : '');
if (is_dir($fichier)) {
    $fichier .= '/index.html';
}
// Le dossier de l'app peut être un lien (copie locale) : on compare au dossier résolu
$reel = realpath($fichier);
if ($reel === false || !is_file($reel) || !str_starts_with($reel, $racine . DIRECTORY_SEPARATOR)) {
    refuser(404, 'Fichier introuvable.');
}

$types = [
    'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
    'css' => 'text/css; charset=utf-8', 'json' => 'application/json; charset=utf-8', 'map' => 'application/json; charset=utf-8',
    'txt' => 'text/plain; charset=utf-8', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    'wasm' => 'application/wasm', 'webmanifest' => 'application/manifest+json', 'pdf' => 'application/pdf',
];
$ext = strtolower(pathinfo($reel, PATHINFO_EXTENSION));
if (!isset($types[$ext])) {
    refuser(404, 'Fichier introuvable.');
}

// Cache du navigateur seulement (privé), revalidé à chaque fois : une nouvelle copie se voit tout de suite
$date = filemtime($reel) ?: time();
$etag = '"' . dechex($date) . '-' . dechex(filesize($reel) ?: 0) . '"';
header('Cache-Control: private, no-cache');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $date) . ' GMT');
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($reel));
readfile($reel);
