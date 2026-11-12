<?php
/**
 * GET /api/enseignant.php
 * Ce navigateur est-il connecté à l'espace enseignant ? → { ok: true, enseignant: true|false }
 *
 * Sert aux applications copiées sur ce serveur (/miroir/<app>/, même site) : TechnoStudio s'ouvre en mode
 * enseignant quand l'enseignant est connecté ici, en mode élève sinon. Lecture seule ; aucune donnée renvoyée.
 * Pas d'en-tête CORS : un site extérieur ne peut pas lire la réponse (et le cookie de session, SameSite=Strict,
 * ne part pas depuis un autre site).
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

header('X-Content-Type-Options: nosniff');
$enseignant = false;
// On ne crée pas de session : on lit seulement celle de l'espace enseignant si son cookie est présent
if (isset($_COOKIE['centrale_admin']) && preg_match('/^[A-Za-z0-9,-]{16,128}$/', (string) $_COOKIE['centrale_admin'])) {
    session_name('centrale_admin');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => est_https()]);
    session_start(['read_and_close' => true]);
    $enseignant = !empty($_SESSION['admin']);
}
envoyer_json(200, ['ok' => true, 'enseignant' => $enseignant]);
