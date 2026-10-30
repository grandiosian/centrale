<?php
/**
 * Sauvegarde et chargement des données d'une application (JSON), pour l'élève connecté.
 * En-tête obligatoire : X-Jeton (reçu à la connexion).
 *
 * GET  ?app=robot&cle=projet          → { ok, existe, valeur, maj }
 * GET  ?app=robot                     → { ok, cles: [ { cle, taille, maj } ] }
 * POST { app, cle, valeur [, base] }  → { ok, maj, espace }      (enregistre)
 *      base = date "maj" de la version sur laquelle on travaillait : si le serveur a plus récent,
 *      réponse 409 (conflit) et rien n'est écrasé.
 * POST { app, cle, supprimer: true }  → { ok }                   (efface)
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $app = app_requise($_GET);
    $cle = cle_propre((string) ($_GET['cle'] ?? ''));

    if ($cle === '') {
        $st = $pdo->prepare('SELECT cle, taille, maj FROM donnees WHERE eleve_id = ? AND app = ? ORDER BY cle');
        $st->execute([$eleve['id'], $app]);
        envoyer_json(200, ['ok' => true, 'cles' => array_map(fn($r) => [
            'cle' => $r['cle'], 'taille' => (int) $r['taille'], 'maj' => (int) $r['maj'],
        ], $st->fetchAll())]);
    }

    $st = $pdo->prepare('SELECT valeur, maj FROM donnees WHERE eleve_id = ? AND app = ? AND cle = ?');
    $st->execute([$eleve['id'], $app, $cle]);
    $ligne = $st->fetch();
    envoyer_json(200, $ligne
        ? ['ok' => true, 'existe' => true, 'valeur' => json_decode($ligne['valeur']), 'maj' => (int) $ligne['maj']]
        : ['ok' => true, 'existe' => false, 'valeur' => null, 'maj' => null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Utilisez GET ou POST.']);
}

$entree = lire_entree();
$app = app_requise($entree);
$cle = cle_propre((string) ($entree['cle'] ?? ''));
if ($cle === '') {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Clé manquante (ex. "projet").']);
}

if (!empty($entree['supprimer'])) {
    $pdo->prepare('DELETE FROM donnees WHERE eleve_id = ? AND app = ? AND cle = ?')->execute([$eleve['id'], $app, $cle]);
    envoyer_json(200, ['ok' => true]);
}

if (!array_key_exists('valeur', $entree)) {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Valeur manquante.']);
}
$json = json_encode($entree['valeur'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
$taille = strlen($json);
if ($taille > TAILLE_MAX_DONNEE) {
    envoyer_json(413, ['ok' => false, 'erreur' => 'Données trop grosses (2 Mo maximum par clé) : utilisez plutôt les fichiers.']);
}

$st = $pdo->prepare('SELECT valeur, taille, maj FROM donnees WHERE eleve_id = ? AND app = ? AND cle = ?');
$st->execute([$eleve['id'], $app, $cle]);
$ancien = $st->fetch();

// Conflit : l'élève a modifié ce travail ailleurs (autre ordinateur, autre onglet) depuis que
// cette copie a été chargée. On garde la version la plus récente du serveur.
if ($ancien && isset($entree['base']) && is_numeric($entree['base']) && (int) $ancien['maj'] > (int) $entree['base']) {
    envoyer_json(409, ['ok' => false, 'conflit' => true, 'maj' => (int) $ancien['maj'],
        'erreur' => 'Ton travail a été modifié sur un autre ordinateur. Recharge la page pour retrouver la dernière version.']);
}

$usage = usage_eleve($eleve['id']) - ($ancien ? (int) $ancien['taille'] : 0) + $taille;
if ($usage > quota_eleve_octets()) {
    envoyer_json(507, ['ok' => false, 'erreur' => "Ton espace est plein. Préviens ton professeur.",
        'espace' => ['utilise' => $usage - $taille, 'quota' => quota_eleve_octets()]]);
}

$maintenant = time();
$pdo->beginTransaction();

// Historique : on garde l'ancienne valeur si la dernière version a plus de 10 minutes.
if ($ancien && $ancien['valeur'] !== $json) {
    $st = $pdo->prepare('SELECT MAX(maj) FROM donnees_versions WHERE eleve_id = ? AND app = ? AND cle = ?');
    $st->execute([$eleve['id'], $app, $cle]);
    $derniere = (int) $st->fetchColumn();
    if ($maintenant - $derniere >= 600) {
        $pdo->prepare('INSERT INTO donnees_versions (eleve_id, app, cle, valeur, taille, maj) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$eleve['id'], $app, $cle, $ancien['valeur'], $ancien['taille'], $ancien['maj']]);
        $pdo->prepare('DELETE FROM donnees_versions WHERE eleve_id = ? AND app = ? AND cle = ? AND id NOT IN (
                           SELECT id FROM donnees_versions WHERE eleve_id = ? AND app = ? AND cle = ?
                           ORDER BY maj DESC, id DESC LIMIT 10)')
            ->execute([$eleve['id'], $app, $cle, $eleve['id'], $app, $cle]);
    }
}

$pdo->prepare('INSERT INTO donnees (eleve_id, app, cle, valeur, taille, maj) VALUES (?, ?, ?, ?, ?, ?)
               ON CONFLICT (eleve_id, app, cle) DO UPDATE SET valeur = excluded.valeur, taille = excluded.taille, maj = excluded.maj')
    ->execute([$eleve['id'], $app, $cle, $json, $taille, $maintenant]);
$pdo->commit();

envoyer_json(200, ['ok' => true, 'maj' => $maintenant,
    'espace' => ['utilise' => usage_eleve($eleve['id']), 'quota' => quota_eleve_octets()]]);
