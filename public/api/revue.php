<?php
/**
 * GET /api/revue.php?eleve=<id>&app=technostudio&atelier=<id>
 * Travail d'un élève dans un atelier, pour la vue de correction de l'enseignant (Suivi → clic sur une case :
 * TechnoStudio ?revue=<atelier>/<feuille>&eleve=<id>, dans une fenêtre). → { ok, eleve: {classe, numero}, travail: {…}|null, maj }
 *
 * Réservé à l'espace enseignant : on lit sa session (cookie centrale_admin, SameSite=Strict) sans en créer.
 * Pas d'en-tête CORS : seules les applications copiées sur ce serveur (/miroir/<app>/) peuvent lire la réponse.
 * Rien de nominatif : classe et numéro seulement (les noms restent dans le navigateur de l'enseignant).
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
    envoyer_json(403, ['ok' => false, 'erreur' => 'Réservé à l’espace enseignant (connectez-vous sur la Centrale).']);
}

$eleve_id = (int) ($_GET['eleve'] ?? 0);
$app = normaliser_app((string) ($_GET['app'] ?? 'technostudio'));
$atelier = (string) ($_GET['atelier'] ?? '');
if ($eleve_id <= 0 || !preg_match('/^[A-Za-z0-9_.\-]{1,120}$/', $atelier)) {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Élève ou atelier manquant.']);
}
$st = db()->prepare('SELECT e.etiquette, c.nom AS classe FROM eleves e JOIN classes c ON c.id = e.classe_id WHERE e.id = ?');
$st->execute([$eleve_id]);
$eleve = $st->fetch();
if (!$eleve) {
    envoyer_json(404, ['ok' => false, 'erreur' => 'Élève introuvable.']);
}
// même clé que TechnoStudio (js/passerelle.js, cleAtelier)
$cle = 'at/' . preg_replace('/[^A-Za-z0-9_\-.]/', '-', $atelier);
$st = db()->prepare('SELECT valeur, maj FROM donnees WHERE eleve_id = ? AND app = ? AND cle = ?');
$st->execute([$eleve_id, $app, $cle]);
$d = $st->fetch();
envoyer_json(200, ['ok' => true, 'eleve' => ['classe' => $eleve['classe'], 'numero' => $eleve['etiquette']],
                   'travail' => $d ? json_decode((string) $d['valeur'], true) : null, 'maj' => $d ? (int) $d['maj'] : null]);
