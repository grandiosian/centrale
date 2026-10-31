<?php
/**
 * Compilation des programmes de cartes pour TechnoStudio (et toute application qui en a besoin).
 *
 * Le compilateur est installé hors du site par le workflow « Chaîne de compilation »
 * (.github/workflows/compilation.yml) : arduino-cli, cœurs Arduino AVR et ESP32, MicroPython micro:bit,
 * et le code de TechnoStudio (serveur-compilation/compiler-cli.js) dans ~/technostudio-compilation/.
 * Cette page ne fait que contrôler l'accès et transmettre la demande.
 *
 * GET                       → { ok, version, cartes: { uno|nano|esp32|microbit: { installee } } }   (sans connexion)
 * POST { carte, code }      → { ok, format: hex | bin | hex-microbit, hex | images, journal, duree }
 *                             ou { ok: true, enCours: true, travail } si la compilation dure (ESP32 à froid)
 * GET ?travail=<id>         → résultat ou { enCours: true }
 *
 * Accès : en-tête X-Jeton d'un élève connecté, ou X-Cle (clé enseignant, fichier de configuration).
 * Limite : 15 compilations par minute et par élève.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

// En-tête supplémentaire accepté pour la clé enseignant (posé juste avant l'envoi, même pour OPTIONS)
header_register_callback(function (): void {
    if (isset($_SERVER['HTTP_ORIGIN']) && in_array('Access-Control-Allow-Origin', array_map(fn($h) => strstr($h, ':', true), headers_list()), true)) {
        header('Access-Control-Allow-Headers: Content-Type, X-Jeton, X-Cle');
    }
});
appliquer_cors();

function compilation_config(): array
{
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    if (!$home && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $home = posix_getpwuid(posix_geteuid())['dir'] ?? '';
    }
    $f = rtrim($home, '/') . '/technostudio-compilation/config.json';
    $c = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($c) || empty($c['node']) || empty($c['cli']) || empty($c['dossier'])) {
        envoyer_json(503, ['ok' => false, 'erreur' => "La chaîne de compilation n'est pas encore installée sur le serveur."]);
    }
    return $c;
}

function compilation_commande(array $c, string $args): string
{
    return escapeshellarg($c['node']) . ' ' . escapeshellarg($c['cli']) . ' ' . $args
        . ' --dossier ' . escapeshellarg($c['dossier']);
}

$conf = compilation_config();
$travaux = rtrim($conf['dossier'], '/') . '/travaux';

/* ---------- État (public, gardé une minute) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['travail'])) {
    $cache = rtrim($conf['dossier'], '/') . '/etat.json';
    if (!is_file($cache) || filemtime($cache) < time() - 60) {
        $sortie = shell_exec(compilation_commande($conf, 'etat') . ' 2>/dev/null');
        if ($sortie && json_decode($sortie, true)) {
            @file_put_contents($cache, $sortie);
        }
    }
    $etat = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    if (!$etat) {
        envoyer_json(503, ['ok' => false, 'erreur' => 'Le compilateur ne répond pas.']);
    }
    $etat['auth'] = 'centrale' . (!empty($conf['cles']) ? '+cle' : '');
    envoyer_json(200, $etat);
}

/* ---------- Accès : élève connecté ou clé enseignant ---------- */
$cle = (string) ($_SERVER['HTTP_X_CLE'] ?? '');
$qui = null;
if ($cle !== '' && strlen($cle) >= 12) {
    foreach ((array) ($conf['cles'] ?? []) as $k) {
        if (is_string($k) && strlen($k) >= 12 && hash_equals($k, $cle)) {
            $qui = 'cle:' . substr(hash('sha256', $k), 0, 8);
        }
    }
    if (!$qui) {
        envoyer_json(401, ['ok' => false, 'erreur' => 'Clé enseignant inconnue.']);
    }
} else {
    $eleve = eleve_connecte();   // 401 si absent ou expiré
    $qui = 'eleve:' . $eleve['id'];
}

/* ---------- Suivi d'un travail en cours ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (string) $_GET['travail'];
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        envoyer_json(400, ['ok' => false, 'erreur' => 'Travail inconnu.']);
    }
    $f = "$travaux/$id.json";
    $t = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!$t) {
        envoyer_json(is_file("$travaux/$id.demande.json") ? 200 : 404, is_file("$travaux/$id.demande.json")
            ? ['ok' => true, 'enCours' => true, 'travail' => $id, 'statut' => 'attente']
            : ['ok' => false, 'erreur' => 'Travail inconnu ou trop ancien.']);
    }
    if (empty($t['fini'])) {
        envoyer_json(200, ['ok' => true, 'enCours' => true, 'travail' => $id, 'statut' => $t['statut'] ?? 'compilation']);
    }
    envoyer_json(200, (array) $t['resultat']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Utilisez GET ou POST.']);
}

/* ---------- Nouvelle compilation ---------- */
if (trop_d_echecs('compil:' . $qui, 15, 1)) {
    envoyer_json(429, ['ok' => false, 'erreur' => 'Trop de compilations en une minute : attends un peu.']);
}
noter_echec('compil:' . $qui);   // compteur de demandes (même table que les limites de connexion)

$e = lire_entree();
$carte = (string) ($e['carte'] ?? '');
$code = $e['code'] ?? '';
if (!preg_match('/^[a-z0-9-]{2,20}$/', $carte) || !is_string($code) || strlen($code) > 100 * 1024) {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Demande incomplète (carte, code).']);
}

if (!is_dir($travaux)) {
    @mkdir($travaux, 0700, true);
}
$id = bin2hex(random_bytes(16));
file_put_contents("$travaux/$id.demande.json", json_encode(['carte' => $carte, 'code' => $code, 'qui' => $qui], JSON_UNESCAPED_UNICODE));

if (!function_exists('exec')) {
    envoyer_json(503, ['ok' => false, 'erreur' => 'Le serveur ne permet pas de lancer le compilateur.']);
}
// En arrière-plan : la requête web n'attend pas plus de 25 s une compilation longue
exec('setsid nohup ' . compilation_commande($conf, 'travail ' . $id) . ' > /dev/null 2>&1 &');

ignore_user_abort(true);
$fin = microtime(true) + 25;
while (microtime(true) < $fin) {
    usleep(250000);
    clearstatcache(true, "$travaux/$id.json");
    $t = is_file("$travaux/$id.json") ? json_decode((string) file_get_contents("$travaux/$id.json"), true) : null;
    if ($t && !empty($t['fini'])) {
        $r = (array) $t['resultat'];
        envoyer_json(!empty($r['refuse']) ? 400 : 200, $r);
    }
}
envoyer_json(200, ['ok' => true, 'enCours' => true, 'travail' => $id, 'statut' => 'compilation']);
