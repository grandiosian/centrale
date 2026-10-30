<?php
/**
 * GET /api/session.php?app=… (en-tête X-Jeton)
 * Vérifie un jeton et renvoie les infos de l'élève, comme login.php mais sans nouveau code.
 * Sert à passer d'une application à l'autre (portail, parcours) sans retaper son code.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();
$app = normaliser_app((string) ($_GET['app'] ?? '*'));

$st = db()->prepare('SELECT expire FROM sessions WHERE jeton_hash = ?');
$st->execute([hash('sha256', (string) $_SERVER['HTTP_X_JETON'])]);
$expire = (int) $st->fetchColumn();
$depot = depot_pour((int) $eleve['classe_id'], $app);
noter_connexion($eleve['id'], $app, 'portail');

envoyer_json(200, [
    'ok'     => true,
    'classe' => $eleve['classe'],
    'eleve'  => $eleve['etiquette'],
    'app'    => $app,
    'jeton'  => (string) $_SERVER['HTTP_X_JETON'],
    'expire' => $expire,
    'espace' => ['utilise' => usage_eleve($eleve['id']), 'quota' => quota_eleve_octets()],
    'depot'  => $depot ? ['url' => $depot['url'], 'mot_de_passe' => $depot['mdp'] !== '' ? $depot['mdp'] : null] : null,
]);
