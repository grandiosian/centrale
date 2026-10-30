<?php
/**
 * POST /api/login.php
 *
 * Entrée (JSON ou formulaire) :
 *   { "code": "5A-dfgdfkj-orei-flgdf", "app": "robot-mbot" }
 *   "app" est facultatif : il permet de donner un dossier de dépôt différent par application.
 *
 * Réponse si le code est bon (200) :
 *   { "ok": true, "classe": "5A", "eleve": "Léa D.", "app": "robot-mbot",
 *     "depot": { "url": "https://nuage03.apps.education.fr/s/xxxx", "mot_de_passe": "…" } }
 *
 * Réponse sinon : { "ok": false, "erreur": "…" } avec le statut 400, 401, 403 ou 429.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Utilisez la méthode POST.']);
}

// Tous les élèves d'un collège sortent souvent par la même adresse IP :
// la limite est donc large. Les codes sont de toute façon impossibles à deviner.
$cle_ip = 'api:' . ip_client();
if (trop_d_echecs($cle_ip, 100, 15)) {
    envoyer_json(429, ['ok' => false, 'erreur' => 'Trop de codes erronés depuis ce réseau. Réessayez dans 15 minutes.']);
}

$entree = lire_entree();
$code = (string) ($entree['code'] ?? '');
$app = normaliser_app((string) ($entree['app'] ?? '*'));

if (normaliser_code($code) === '') {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Code manquant.']);
}

$st = db()->prepare('SELECT e.id, e.etiquette, e.classe_id, c.nom AS classe
                     FROM eleves e JOIN classes c ON c.id = e.classe_id
                     WHERE e.code_hash = ? AND e.actif = 1');
$st->execute([hash_code($code)]);
$eleve = $st->fetch();

if (!$eleve) {
    noter_echec($cle_ip);
    usleep(400000); // ralentit les essais en série
    envoyer_json(401, ['ok' => false, 'erreur' => 'Code inconnu ou désactivé.']);
}

db()->prepare('UPDATE eleves SET derniere_connexion = ? WHERE id = ?')
    ->execute([time(), $eleve['id']]);

$depot = depot_pour((int) $eleve['classe_id'], $app);

envoyer_json(200, [
    'ok'     => true,
    'classe' => $eleve['classe'],
    'eleve'  => $eleve['etiquette'],
    'app'    => $app,
    'depot'  => $depot ? [
        'url'          => $depot['url'],
        'mot_de_passe' => $depot['mdp'] !== '' ? $depot['mdp'] : null,
    ] : null,
]);
