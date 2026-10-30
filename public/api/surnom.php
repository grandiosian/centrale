<?php
/**
 * POST /api/surnom.php   (en-tête X-Jeton)
 *   { "surnom": "LoutreAgile" }
 * Change la fin de l'identifiant de l'élève (« 4C-LHA-LoutreAgile »). Le début suit la classe.
 * Réponse : { ok, identifiant, prefixe, surnom, surnom_modifiable }
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Utilisez la méthode POST.']);
}
$eleve = eleve_connecte();
if (reglage('surnom_modifiable', '1') !== '1') {
    envoyer_json(403, ['ok' => false, 'erreur' => 'Ton professeur a désactivé le changement de surnom.']);
}
$surnom = trim((string) (lire_entree()['surnom'] ?? ''));
if ($erreur = surnom_refuse($surnom)) {
    envoyer_json(400, ['ok' => false, 'erreur' => $erreur]);
}
if (!surnom_libre((int) $eleve['classe_id'], $surnom, $eleve['id'])) {
    envoyer_json(409, ['ok' => false, 'erreur' => 'Ce surnom est déjà pris dans ta classe. Choisis-en un autre.']);
}
db()->prepare('UPDATE eleves SET surnom = ? WHERE id = ?')->execute([$surnom, $eleve['id']]);
envoyer_json(200, ['ok' => true] + identite_eleve($eleve['id']));
