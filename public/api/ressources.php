<?php
/**
 * Ressources déposées par l'enseignant pour un atelier (lecture seule pour les élèves).
 * En-tête obligatoire : X-Jeton.
 *
 * GET ?app=robot                  → { ok, ressources: [ { nom, taille, type, maj } ] }
 * GET ?app=robot&nom=plan.stl     → le fichier lui-même
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
eleve_connecte();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Utilisez GET.']);
}

$app = app_requise($_GET);
$nom = nom_fichier_propre((string) ($_GET['nom'] ?? ''));

if ($nom === '') {
    $st = db()->prepare('SELECT nom, taille, type, maj FROM ressources WHERE app = ? ORDER BY nom');
    $st->execute([$app]);
    envoyer_json(200, ['ok' => true, 'ressources' => array_map(fn($r) => [
        'nom' => $r['nom'], 'taille' => (int) $r['taille'], 'type' => $r['type'], 'maj' => (int) $r['maj'],
    ], $st->fetchAll())]);
}

$st = db()->prepare('SELECT * FROM ressources WHERE app = ? AND nom = ?');
$st->execute([$app, $nom]);
$r = $st->fetch();
if (!$r) {
    envoyer_json(404, ['ok' => false, 'erreur' => 'Ressource introuvable.']);
}
servir_fichier($r['chemin'], $r['nom'], $r['type']);
