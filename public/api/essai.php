<?php
/**
 * Essai de l'enseignant (« Tester à la place d'un élève ») : changer d'élève sans repasser par l'espace enseignant.
 * Réservé aux sessions d'essai (ouvertes par l'enseignant connecté) : en-tête X-Jeton d'une session marquée essai.
 *
 * GET  /api/essai.php            → { ok, eleve (id actuel), classes: [ { classe, test, eleves: [ { id, etiquette, identifiant } ] } ] }
 *                                   Classes de test d'abord. Rien de nominatif : classe, numéro et identifiant (surnom) ;
 *                                   les noms restent sur l'ordinateur de l'enseignant (module : localStorage « centrale-noms »).
 * POST /api/essai.php { eleve }  → { ok, jeton, expire } : nouvelle session d'essai (2 h) pour cet élève, avec la même vue
 *                                   (Élève | Enseignant). Avec un vrai élève, ce qui est fait s'enregistre dans son dossier.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
eleve_connecte();   // jeton valide (sinon 401)
$jeton_hash = hash('sha256', (string) ($_SERVER['HTTP_X_JETON'] ?? ''));
$st = db()->prepare('SELECT eleve_id, essai, vue FROM sessions WHERE jeton_hash = ?');
$st->execute([$jeton_hash]);
$session = $st->fetch();
if (!$session || (int) $session['essai'] !== 1) {
    envoyer_json(403, ['ok' => false, 'erreur' => 'Réservé aux essais de l’enseignant (« Tester à la place d’un élève »).']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $entree = lire_entree();
    $eid = (int) ($entree['eleve'] ?? 0);
    $st = db()->prepare('SELECT e.id FROM eleves e JOIN classes c ON c.id = e.classe_id WHERE e.id = ? AND e.actif = 1');
    $st->execute([$eid]);
    if (!$st->fetchColumn()) {
        envoyer_json(404, ['ok' => false, 'erreur' => 'Élève introuvable.']);
    }
    $jeton = bin2hex(random_bytes(32));
    $expire = time() + 2 * 3600;
    db()->prepare('INSERT INTO sessions (jeton_hash, eleve_id, expire, essai, vue) VALUES (?, ?, ?, 1, ?)')
        ->execute([hash('sha256', $jeton), $eid, $expire, $session['vue'] === 'enseignant' ? 'enseignant' : 'eleve']);
    envoyer_json(200, ['ok' => true, 'jeton' => $jeton, 'expire' => $expire]);
}

$classes = [];
foreach (db()->query('SELECT e.id, e.etiquette, e.surnom, c.nom, c.etablissement_id FROM eleves e JOIN classes c ON c.id = e.classe_id
                      WHERE e.actif = 1 ORDER BY c.nom, e.etiquette')->fetchAll() as $l) {
    $classes[$l['nom']] ??= ['classe' => $l['nom'], 'test' => est_classe_test((string) $l['nom']), 'eleves' => []];
    $classes[$l['nom']]['eleves'][] = ['id' => (int) $l['id'], 'etiquette' => $l['etiquette'],
        'identifiant' => $l['surnom'] ? identifiant_eleve(['nom' => $l['nom'], 'etablissement_id' => $l['etablissement_id']], $l['surnom']) : ''];
}
$classes = array_values($classes);
usort($classes, fn($a, $b) => $b['test'] <=> $a['test'] ?: strnatcasecmp($a['classe'], $b['classe']));
envoyer_json(200, ['ok' => true, 'eleve' => (int) $session['eleve_id'], 'classes' => $classes]);
