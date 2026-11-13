<?php
/**
 * FicheStudio (outil réservé à l'enseignant, servi par /admin/appli.php/fichestudio/) : classes et avancement
 * de la correction projetée en classe.
 *
 * GET  → { ok, classes: [{ id: "c5", nom, court, niveau, etablissement, groupes: [{ id: "c5g2", nom }] }],
 *          cours: { cible, libelle, debut, fin, maintenant } | null,      (cours en cours, sinon le prochain du jour)
 *          avancement: { "<cible>": { "<document>": {…} } } }
 * POST { cible, doc, avancement: {…} | null }  → enregistre (ou efface) l'avancement d'une classe pour un document.
 * GET  ?documents                → { ok, documents: [{ nom, taille, maj }] }  documents de l'enseignant (.fstudio)
 * GET  ?document=<nom>           → le document (JSON FicheStudio, images intégrées)
 * POST ?document=<nom>           (corps : le document en JSON) → l'enregistre ; ?document=<nom>&supprimer → le retire
 *
 * Seulement pour la session enseignant (cookie centrale_admin, SameSite=Strict) ; pas d'en-tête CORS, et le POST
 * exige du JSON : un autre site ne peut ni lire ni écrire. Rien de nominatif : des classes et des numéros de feuille.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';
require_once __DIR__ . '/../../lib/simulation.php';

header('X-Content-Type-Options: nosniff');

$enseignant = false;
if (isset($_COOKIE['centrale_admin']) && preg_match('/^[A-Za-z0-9,-]{16,128}$/', (string) $_COOKIE['centrale_admin'])) {
    session_name('centrale_admin');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => est_https()]);
    session_start(['read_and_close' => true]);
    $enseignant = !empty($_SESSION['admin']);
}
if (!$enseignant) {
    envoyer_json(401, ['ok' => false, 'erreur' => "Connectez-vous à l'espace enseignant de la Centrale."]);
}

$pdo = db();

/** Cibles existantes : « c<classe> » et « c<classe>g<groupe> ». */
function cibles_valides(PDO $pdo): array
{
    $ok = [];
    foreach ($pdo->query('SELECT id FROM classes') as $c) {
        $ok['c' . $c['id']] = true;
    }
    foreach ($pdo->query('SELECT id, classe_id FROM groupes') as $g) {
        $ok['c' . $g['classe_id'] . 'g' . $g['id']] = true;
    }
    return $ok;
}

// --- Documents de l'enseignant (.fstudio, images intégrées), rangés dans data/fichestudio/ : hors racine web ---
const DOSSIER_DOCS = DOSSIER_DATA . '/fichestudio';
const TAILLE_MAX_DOC = 60 * 1024 * 1024;

/** Nom de document sûr (lettres accentuées, chiffres, espace, _ - . ( ) ; sans extension) ou null. */
function nom_document(string $nom): ?string
{
    $nom = trim(preg_replace('/\.fstudio$/i', '', $nom) ?? '');
    return $nom !== '' && $nom[0] !== '.' && mb_strlen($nom) <= 120 && preg_match('/^[\p{L}\p{N} _.()\'-]+$/u', $nom) ? $nom : null;
}

if (isset($_GET['documents'])) {
    $liste = [];
    foreach (glob(DOSSIER_DOCS . '/*.fstudio') ?: [] as $f) {
        $liste[] = ['nom' => basename($f, '.fstudio'), 'taille' => filesize($f), 'maj' => filemtime($f)];
    }
    usort($liste, fn($a, $b) => strcasecmp($a['nom'], $b['nom']));
    envoyer_json(200, ['ok' => true, 'documents' => $liste]);
}
if (isset($_GET['document'])) {
    $nom = nom_document((string) $_GET['document']);
    if ($nom === null) {
        envoyer_json(400, ['ok' => false, 'erreur' => 'Nom de document invalide.']);
    }
    $chemin = DOSSIER_DOCS . '/' . $nom . '.fstudio';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!is_file($chemin)) {
            envoyer_json(404, ['ok' => false, 'erreur' => 'Document introuvable.']);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . filesize($chemin));
        readfile($chemin);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['supprimer'])) {
        if (is_file($chemin)) {
            unlink($chemin);
        }
        envoyer_json(200, ['ok' => true]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            envoyer_json(415, ['ok' => false, 'erreur' => 'JSON attendu.']);
        }
        $corps = (string) file_get_contents('php://input', false, null, 0, TAILLE_MAX_DOC + 1);
        if (strlen($corps) > TAILLE_MAX_DOC) {
            envoyer_json(413, ['ok' => false, 'erreur' => 'Document trop volumineux (60 Mo au plus).']);
        }
        // contrôle léger (le document peut peser des dizaines de Mo) : objet JSON au format FicheStudio
        if (($corps[0] ?? '') !== '{' || !preg_match('/"format"\s*:\s*"fichestudio"/', $corps)) {
            envoyer_json(400, ['ok' => false, 'erreur' => "Ce n'est pas un document FicheStudio."]);
        }
        if (!is_dir(DOSSIER_DOCS)) {
            mkdir(DOSSIER_DOCS, 0700, true);
        }
        $tmp = $chemin . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $corps) === false || !rename($tmp, $chemin)) {
            @unlink($tmp);
            envoyer_json(500, ['ok' => false, 'erreur' => "Enregistrement impossible sur le serveur."]);
        }
        envoyer_json(200, ['ok' => true, 'nom' => $nom, 'taille' => strlen($corps), 'maj' => time()]);
    }
    envoyer_json(405, ['ok' => false, 'erreur' => 'Méthode non prise en charge.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        envoyer_json(415, ['ok' => false, 'erreur' => 'JSON attendu.']);
    }
    $brut = (string) file_get_contents('php://input', false, null, 0, 512 * 1024 + 1);
    if (strlen($brut) > 512 * 1024) {
        envoyer_json(413, ['ok' => false, 'erreur' => 'Avancement trop volumineux.']);
    }
    $e = json_decode($brut, true);
    $cible = is_array($e) ? (string) ($e['cible'] ?? '') : '';
    $doc = is_array($e) ? trim((string) ($e['doc'] ?? '')) : '';
    if (!isset(cibles_valides($pdo)[$cible]) || $doc === '' || mb_strlen($doc) > 200) {
        envoyer_json(400, ['ok' => false, 'erreur' => 'Classe ou document inconnu.']);
    }
    $av = $e['avancement'] ?? null;
    if ($av === null) {
        $pdo->prepare('DELETE FROM fichestudio_avancement WHERE cible = ? AND doc = ?')->execute([$cible, $doc]);
    } elseif (is_array($av)) {
        $pdo->prepare('INSERT INTO fichestudio_avancement (cible, doc, valeur, maj) VALUES (?, ?, ?, ?)
            ON CONFLICT (cible, doc) DO UPDATE SET valeur = excluded.valeur, maj = excluded.maj')
            ->execute([$cible, $doc, json_encode($av, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
    } else {
        envoyer_json(400, ['ok' => false, 'erreur' => 'Avancement invalide.']);
    }
    envoyer_json(200, ['ok' => true, 'maj' => time()]);
}

// --- Classes, groupes (demi-groupes) et établissement ---
$etabs = [];
foreach ($pdo->query('SELECT id, nom FROM etablissements') as $et) {
    $etabs[(int) $et['id']] = $et['nom'];
}
$groupes = [];
foreach ($pdo->query('SELECT id, classe_id, nom FROM groupes ORDER BY ordre, nom') as $g) {
    $groupes[(int) $g['classe_id']][] = ['id' => 'c' . $g['classe_id'] . 'g' . $g['id'], 'nom' => $g['nom']];
}
$classes = [];
foreach ($pdo->query('SELECT * FROM classes ORDER BY nom COLLATE NOCASE') as $c) {
    $eid = (int) ($c['etablissement_id'] ?? 0);
    $classes[] = [
        'id' => 'c' . $c['id'], 'nom' => $c['nom'], 'court' => nom_court_classe($c), 'niveau' => niveau_classe($c['nom']) ?? '',
        'etablissement' => $etabs[$eid] ?? '', 'simule' => isset($etabs[$eid]) && est_etablissement_simule($etabs[$eid]),
        'groupes' => $groupes[(int) $c['id']] ?? [],
    ];
}
// établissements réels d'abord, puis classes sans établissement (TEST…), puis simulées ; par nom
usort($classes, fn($a, $b) => ($a['simule'] <=> $b['simule']) ?: (($a['etablissement'] === '') <=> ($b['etablissement'] === ''))
    ?: strcasecmp($a['etablissement'], $b['etablissement']) ?: strcasecmp($a['nom'], $b['nom']));

// --- Cours en cours (ou terminé depuis moins de 10 min, pour marquer la fin), sinon le prochain du jour ---
$cours = null;
$maintenant = new DateTimeImmutable('now', fuseau());
$hm = $maintenant->format('H:i');
$apres = $maintenant->modify('-10 minutes')->format('H:i');
foreach (seances_du_jour(aujourd_hui()) as $s) {
    if ($s['statut'] === 'annule' || est_etablissement_simule($s['etablissement'])) {
        continue;
    }
    $en_cours = $s['debut'] <= $hm && $apres < $s['fin'];
    if ($en_cours || ($s['debut'] > $hm && $cours === null)) {
        $cours = ['cible' => 'c' . $s['classe_id'] . ($s['groupe_id'] ? 'g' . $s['groupe_id'] : ''), 'libelle' => $s['classe'],
                  'intitule' => $s['intitule'], 'debut' => $s['debut'], 'fin' => $s['fin'], 'maintenant' => $en_cours];
        if ($en_cours) {
            break;
        }
    }
}

// --- Avancement enregistré ---
$avancement = [];
$valides = cibles_valides($pdo);
foreach ($pdo->query('SELECT cible, doc, valeur FROM fichestudio_avancement') as $r) {
    $v = json_decode($r['valeur'], true);
    if (isset($valides[$r['cible']]) && is_array($v)) {
        $avancement[$r['cible']][$r['doc']] = $v;
    }
}

envoyer_json(200, ['ok' => true, 'classes' => $classes, 'cours' => $cours, 'avancement' => (object) $avancement]);
