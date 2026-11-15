<?php
/**
 * POST /api/login.php
 *
 * Entrée (JSON ou formulaire) :
 *   { "code": "5A-dfgdfkj-orei-flgdf", "app": "robot-mbot" }
 *   "app" est facultatif : il permet de donner un dossier de dépôt différent par application.
 *
 * Réponse si le code est bon (200) :
 *   { "ok": true, "classe": "5A", "eleve": "07", "app": "robot-mbot",
 *     "jeton": "…", "expire": 1793370000,          ← à renvoyer dans l'en-tête X-Jeton
 *     "espace": { "utilise": 12345, "quota": 104857600 },
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
try {
    $empreinte = hash_code($code);
} catch (RuntimeException) {
    envoyer_json(503, ['ok' => false, 'erreur' => 'Connexion impossible pour le moment (réglage du serveur). Préviens ton professeur.']);
}
$st->execute([$empreinte]);
$eleve = $st->fetch();

if (!$eleve) {
    noter_echec($cle_ip);
    usleep(400000); // ralentit les essais en série
    envoyer_json(401, ['ok' => false, 'erreur' => explication_code_refuse($code, $empreinte)]);
}

/**
 * Message quand un code est refusé : l'élève a souvent tapé son identifiant (« 5F-LHA-BaleineLucide », affiché sur
 * le portail) au lieu de son code (« 5F-LHA-2345-abcd-efgh », sur sa carte), ou le code d'un élève désactivé.
 */
function explication_code_refuse(string $code, string $empreinte): string
{
    $st = db()->prepare('SELECT 1 FROM eleves WHERE code_hash = ? AND actif = 0');
    $st->execute([$empreinte]);
    if ($st->fetchColumn()) {
        return 'Ce code a été désactivé. Demande à ton professeur.';
    }
    $saisi = trim($code);
    $surnom = substr((string) strrchr($saisi, '-'), 1);
    if ($surnom !== '' && !preg_match('/\d/', $surnom)) {
        $st = db()->prepare('SELECT e.surnom, c.nom, c.etablissement_id FROM eleves e JOIN classes c ON c.id = e.classe_id
                             WHERE e.surnom = ? COLLATE NOCASE AND e.actif = 1');
        $st->execute([$surnom]);
        foreach ($st->fetchAll() as $r) {
            if (strcasecmp(identifiant_eleve(['nom' => $r['nom'], 'etablissement_id' => $r['etablissement_id']], $r['surnom']), $saisi) === 0) {
                return 'C’est ton identifiant, pas ton code. Ton code est sur la carte donnée par ton professeur : il a des chiffres, comme '
                    . substr($saisi, 0, strlen($saisi) - strlen($surnom)) . '2345-abcd-efgh.';
            }
        }
    }
    return 'Code inconnu ou désactivé. Vérifie ta carte (chiffres et lettres, tirets compris) ou demande ton code à ton professeur.';
}

db()->prepare('UPDATE eleves SET derniere_connexion = ? WHERE id = ?')
    ->execute([time(), $eleve['id']]);

$depot = depot_pour((int) $eleve['classe_id'], $app);
[$jeton, $expire] = creer_session((int) $eleve['id']);
noter_connexion((int) $eleve['id'], $app, 'code');
$usage = usage_eleve((int) $eleve['id']);

envoyer_json(200, [
    'ok'     => true,
    'classe' => $eleve['classe'],
    'eleve'  => $eleve['etiquette'],
    'app'    => $app,
    ...identite_eleve((int) $eleve['id']),   // identifiant « 4C-LHA-LoutreAgile », surnom modifiable
    'jeton'  => $jeton,
    'expire' => $expire,
    'espace' => ['utilise' => $usage, 'quota' => quota_eleve_octets()],
    'depot'  => $depot ? [
        'url'          => $depot['url'],
        'mot_de_passe' => $depot['mdp'] !== '' ? $depot['mdp'] : null,
    ] : null,
]);
