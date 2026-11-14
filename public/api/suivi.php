<?php
/**
 * Suivi de classe noté par l'enseignant (appel, observations de séance, incidents, cartons, statuts, critères),
 * synchronisé entre ses ordinateurs. Les données sont rangées par classe et par NUMÉRO d'élève : les noms et les
 * photos ne quittent jamais le navigateur, et admin.js remplace par leur numéro les noms écrits dans les commentaires.
 *
 * GET  ?depuis=<ms>  → { ok, lignes: [{ type, classe, cle, valeur (JSON ou null = effacé), maj }] } modifiées depuis
 * POST { lignes: [{ type, classe, cle, valeur }], initial?: true } → enregistre (initial : sans écraser l'existant)
 *
 * Seulement pour la session enseignant (cookie centrale_admin, SameSite=Strict) ; pas d'en-tête CORS, le POST exige
 * du JSON : un autre site ne peut ni lire ni écrire.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

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

const TYPES_SUIVI = ['presences', 'observations', 'incidents', 'cartons', 'statuts', 'sorties', 'criteres'];
$pdo = db();

/** Nom de classe → id (insensible à la casse, comme la table) ; '' → 0 (réglages communs). */
function id_classe(PDO $pdo, string $nom): ?int
{
    static $cache = [];
    if ($nom === '') {
        return 0;
    }
    if (!array_key_exists($nom, $cache)) {
        $st = $pdo->prepare('SELECT id FROM classes WHERE nom = ? COLLATE NOCASE');
        $st->execute([$nom]);
        $id = $st->fetchColumn();
        $cache[$nom] = $id === false ? null : (int) $id;
    }
    return $cache[$nom];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $depuis = max(0, (int) ($_GET['depuis'] ?? 0));
    $st = $pdo->prepare("SELECT s.type, COALESCE(c.nom, '') AS classe, s.cle, s.valeur, s.maj FROM suivi_classe s
        LEFT JOIN classes c ON c.id = s.classe_id WHERE s.maj >= ? AND (s.classe_id = 0 OR c.id IS NOT NULL) ORDER BY s.maj");
    $st->execute([$depuis]);
    $lignes = array_map(fn($r) => ['type' => $r['type'], 'classe' => $r['classe'], 'cle' => $r['cle'], 'valeur' => $r['valeur'], 'maj' => (int) $r['maj']],
        $st->fetchAll());
    envoyer_json(200, ['ok' => true, 'lignes' => $lignes, 'maintenant' => (int) round(microtime(true) * 1000)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Méthode non prise en charge.']);
}
if (!str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    envoyer_json(415, ['ok' => false, 'erreur' => 'JSON attendu.']);
}
$brut = (string) file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024 + 1);
if (strlen($brut) > 8 * 1024 * 1024) {
    envoyer_json(413, ['ok' => false, 'erreur' => 'Envoi trop volumineux.']);
}
$e = json_decode($brut, true);
$lignes = is_array($e) && is_array($e['lignes'] ?? null) ? $e['lignes'] : null;
if ($lignes === null || count($lignes) > 20000) {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Lignes attendues.']);
}
$initial = !empty($e['initial']);
$maj = (int) round(microtime(true) * 1000);
$sql = $initial
    ? 'INSERT INTO suivi_classe (classe_id, type, cle, valeur, maj) VALUES (?, ?, ?, ?, ?) ON CONFLICT (classe_id, type, cle) DO NOTHING'
    : 'INSERT INTO suivi_classe (classe_id, type, cle, valeur, maj) VALUES (?, ?, ?, ?, ?)
       ON CONFLICT (classe_id, type, cle) DO UPDATE SET valeur = excluded.valeur, maj = excluded.maj';
$ins = $pdo->prepare($sql);
$ok = 0;
$ignores = 0;
$pdo->beginTransaction();
foreach ($lignes as $l) {
    $type = is_array($l) ? (string) ($l['type'] ?? '') : '';
    $cle = is_array($l) ? (string) ($l['cle'] ?? '') : '';
    $classe = is_array($l) ? id_classe($pdo, trim((string) ($l['classe'] ?? ''))) : null;
    $valeur = is_array($l) && array_key_exists('valeur', $l) && $l['valeur'] !== null ? (string) $l['valeur'] : null;
    if (!in_array($type, TYPES_SUIVI, true) || $classe === null || mb_strlen($cle) > 120 || ($valeur !== null && (strlen($valeur) > 64 * 1024 || json_decode($valeur) === null))) {
        $ignores++;   // classe inconnue (supprimée, renommée ailleurs) ou ligne invalide : ignorée
        continue;
    }
    if ($initial && $valeur === null) {
        continue;
    }
    $ins->execute([$classe, $type, $cle, $valeur, $maj]);
    $ok++;
}
$pdo->commit();
envoyer_json(200, ['ok' => true, 'enregistrees' => $ok, 'ignorees' => $ignores, 'maj' => $maj]);
