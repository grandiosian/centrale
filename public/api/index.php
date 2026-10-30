<?php
/** GET /api/ : vérifie que la Centrale répond. */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
envoyer_json(200, ['ok' => true, 'service' => 'centrale-eleves', 'version' => VERSION_CENTRALE]);
