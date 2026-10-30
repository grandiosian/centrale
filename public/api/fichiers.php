<?php
/**
 * Fichiers de l'élève connecté (projets Scratch, modèles 3D, images…).
 * En-tête obligatoire : X-Jeton.
 *
 * GET  ?app=robot                   → { ok, fichiers: [ { nom, taille, type, maj } ], espace }
 * GET  ?app=robot&nom=projet.sb3    → le fichier lui-même
 * POST (multipart) app, fichier [, nom]  → { ok, fichier, espace }   (remplace s'il existe déjà)
 * POST { app, nom, supprimer: true }     → { ok, espace }
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();
$pdo = db();

function espace(int $eleve_id): array
{
    return ['utilise' => usage_eleve($eleve_id), 'quota' => quota_eleve_octets()];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $app = app_requise($_GET);
    $nom = nom_fichier_propre((string) ($_GET['nom'] ?? ''));

    if ($nom === '') {
        $st = $pdo->prepare('SELECT nom, taille, type, maj FROM fichiers WHERE eleve_id = ? AND app = ? ORDER BY nom');
        $st->execute([$eleve['id'], $app]);
        envoyer_json(200, ['ok' => true, 'fichiers' => array_map(fn($r) => [
            'nom' => $r['nom'], 'taille' => (int) $r['taille'], 'type' => $r['type'], 'maj' => (int) $r['maj'],
        ], $st->fetchAll()), 'espace' => espace($eleve['id'])]);
    }

    $st = $pdo->prepare('SELECT * FROM fichiers WHERE eleve_id = ? AND app = ? AND nom = ?');
    $st->execute([$eleve['id'], $app, $nom]);
    $f = $st->fetch();
    if (!$f) {
        envoyer_json(404, ['ok' => false, 'erreur' => 'Fichier introuvable.']);
    }
    header('X-Maj: ' . (int) $f['maj']);
    servir_fichier($f['chemin'], $f['nom'], $f['type']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Utilisez GET ou POST.']);
}

// Fichier plus gros que ce que PHP accepte : $_POST et $_FILES arrivent vides.
$limite_php = octets_ini((string) ini_get('post_max_size'));
if ($limite_php > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limite_php) {
    envoyer_json(413, ['ok' => false, 'erreur' => 'Fichier trop gros pour le serveur.']);
}

$entree = lire_entree();
$app = app_requise($entree);

// --- Suppression
if (!empty($entree['supprimer'])) {
    $nom = nom_fichier_propre((string) ($entree['nom'] ?? ''));
    $st = $pdo->prepare('SELECT id, chemin FROM fichiers WHERE eleve_id = ? AND app = ? AND nom = ?');
    $st->execute([$eleve['id'], $app, $nom]);
    if ($f = $st->fetch()) {
        $pdo->prepare('DELETE FROM fichiers WHERE id = ?')->execute([$f['id']]);
        effacer_fichier_stocke($f['chemin']);
    }
    envoyer_json(200, ['ok' => true, 'espace' => espace($eleve['id'])]);
}

// --- Envoi
$envoi = $_FILES['fichier'] ?? null;
if (!$envoi || is_array($envoi['error'])) {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Aucun fichier reçu (champ "fichier").']);
}
if ($envoi['error'] === UPLOAD_ERR_INI_SIZE || $envoi['error'] === UPLOAD_ERR_FORM_SIZE) {
    envoyer_json(413, ['ok' => false, 'erreur' => 'Fichier trop gros pour le serveur.']);
}
if ($envoi['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($envoi['tmp_name'])) {
    envoyer_json(400, ['ok' => false, 'erreur' => "L'envoi du fichier a échoué. Réessaie."]);
}

$nom = nom_fichier_propre((string) ($entree['nom'] ?? $envoi['name']));
if ($nom === '') {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Nom de fichier invalide.']);
}
$taille = (int) filesize($envoi['tmp_name']);
if ($taille > fichier_max_octets()) {
    envoyer_json(413, ['ok' => false, 'erreur' => 'Fichier trop gros (' . format_taille(fichier_max_octets()) . ' maximum).']);
}
$type = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string) $envoi['type']) ? (string) $envoi['type'] : 'application/octet-stream';

$st = $pdo->prepare('SELECT id, chemin, taille FROM fichiers WHERE eleve_id = ? AND app = ? AND nom = ?');
$st->execute([$eleve['id'], $app, $nom]);
$ancien = $st->fetch();

$usage = usage_eleve($eleve['id']) - ($ancien ? (int) $ancien['taille'] : 0) + $taille;
if ($usage > quota_eleve_octets()) {
    envoyer_json(507, ['ok' => false, 'erreur' => 'Ton espace est plein. Préviens ton professeur.', 'espace' => espace($eleve['id'])]);
}

$chemin = ranger_fichier($envoi['tmp_name'], 'fichiers/' . $eleve['id']);
$maintenant = time();
$pdo->prepare('INSERT INTO fichiers (eleve_id, app, nom, type, taille, chemin, maj) VALUES (?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT (eleve_id, app, nom) DO UPDATE SET type = excluded.type, taille = excluded.taille,
               chemin = excluded.chemin, maj = excluded.maj')
    ->execute([$eleve['id'], $app, $nom, $type, $taille, $chemin, $maintenant]);
if ($ancien) {
    effacer_fichier_stocke($ancien['chemin']);
}

envoyer_json(200, ['ok' => true, 'fichier' => ['nom' => $nom, 'taille' => $taille, 'type' => $type, 'maj' => $maintenant],
    'espace' => espace($eleve['id'])]);
