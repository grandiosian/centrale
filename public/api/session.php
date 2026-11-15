<?php
/**
 * GET /api/session.php?app=… (en-tête X-Jeton)
 * Vérifie un jeton et renvoie les infos de l'élève, comme login.php mais sans nouveau code.
 * Sert à passer d'une application à l'autre (portail, parcours) sans retaper son code.
 * essai / vue : session ouverte par l'enseignant (« Tester à la place d'un élève ») et vue choisie avec l'interrupteur
 * Élève | Enseignant des applications. POST { vue: 'eleve'|'enseignant' } la change (session d'essai seulement).
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();
$app = normaliser_app((string) ($_GET['app'] ?? '*'));

$st = db()->prepare('SELECT expire, essai, vue FROM sessions WHERE jeton_hash = ?');
$st->execute([hash('sha256', (string) $_SERVER['HTTP_X_JETON'])]);
$ligne = $st->fetch() ?: ['expire' => 0, 'essai' => 0, 'vue' => ''];
$expire = (int) $ligne['expire'];
$essai = (int) $ligne['essai'] === 1;   // l'enseignant teste à la place de l'élève (ouvert depuis l'espace enseignant)
$vue = $essai && $ligne['vue'] === 'enseignant' ? 'enseignant' : 'eleve';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {   // interrupteur Élève | Enseignant
    if (!$essai) {
        envoyer_json(403, ['ok' => false, 'erreur' => 'Réservé aux essais de l’enseignant.']);
    }
    $entree = json_decode((string) file_get_contents('php://input'), true);
    $vue = (is_array($entree) ? (string) ($entree['vue'] ?? '') : '') === 'enseignant' ? 'enseignant' : 'eleve';
    db()->prepare('UPDATE sessions SET vue = ? WHERE jeton_hash = ?')->execute([$vue, hash('sha256', (string) $_SERVER['HTTP_X_JETON'])]);
    envoyer_json(200, ['ok' => true, 'essai' => true, 'vue' => $vue]);
}
$depot = depot_pour((int) $eleve['classe_id'], $app);
if (!$essai) {
    noter_connexion($eleve['id'], $app, 'portail');   // les essais de l'enseignant ne comptent pas comme connexions de l'élève
}

envoyer_json(200, [
    'ok'     => true,
    'classe' => $eleve['classe'],
    'eleve'  => $eleve['etiquette'],
    'app'    => $app,
    ...identite_eleve((int) $eleve['id']),   // identifiant « 4C-LHA-LoutreAgile », surnom modifiable
    'jeton'  => (string) $_SERVER['HTTP_X_JETON'],
    'expire' => $expire,
    'essai'  => $essai,
    'vue'    => $vue,
    'espace' => ['utilise' => usage_eleve($eleve['id']), 'quota' => quota_eleve_octets()],
    'depot'  => $depot ? ['url' => $depot['url'], 'mot_de_passe' => $depot['mdp'] !== '' ? $depot['mdp'] : null] : null,
]);
