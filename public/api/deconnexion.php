<?php
/** POST /api/deconnexion.php (en-tête X-Jeton) : met fin à la session de l'élève. */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$jeton = (string) ($_SERVER['HTTP_X_JETON'] ?? '');
if ($jeton !== '') {
    db()->prepare('DELETE FROM sessions WHERE jeton_hash = ?')->execute([hash('sha256', $jeton)]);
}
envoyer_json(200, ['ok' => true]);
