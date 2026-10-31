<?php
/**
 * Catalogue des activités d'un atelier, déclaré par l'application.
 * Sert à afficher, dans le tableau de suivi, les colonnes des activités pas encore commencées.
 * En-tête obligatoire : X-Jeton.
 *
 * POST { app, activites: [ { id, titre, groupe?, total?, competences? }, … ] }   (dans l'ordre souhaité)
 *      competences : codes du référentiel (/passerelle/competences.json) travaillés par l'activité, ex. ["SFC15.5.1"]
 *      Remplace le catalogue de l'atelier. Une liste identique à la précédente ne fait rien.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
eleve_connecte();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Utilisez POST.']);
}

$e = lire_entree();
$app = app_requise($e);
$liste = $e['activites'] ?? null;
if (!is_array($liste) || !$liste) {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Liste "activites" vide ou absente.']);
}
if (count($liste) > 500) {
    envoyer_json(413, ['ok' => false, 'erreur' => '500 activités maximum par atelier.']);
}

$propres = [];
foreach (array_values($liste) as $i => $a) {
    if (!is_array($a)) {
        continue;
    }
    $id = activite_propre((string) ($a['id'] ?? ''));
    if ($id === '' || isset($propres[$id])) {
        continue;
    }
    $propres[$id] = [
        'titre'  => mb_substr(trim((string) ($a['titre'] ?? $id)), 0, 120),
        'groupe' => mb_substr(trim((string) ($a['groupe'] ?? '')), 0, 80),
        'total'  => isset($a['total']) && is_numeric($a['total']) ? max(0, min(1000, (int) $a['total'])) : null,
        'competences' => implode(', ', codes_valides(is_array($a['competences'] ?? null) ? $a['competences'] : (string) ($a['competences'] ?? ''))),
        'ordre'  => $i,
    ];
}
if (!$propres) {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Aucune activité valide (chaque activité doit avoir un "id").']);
}

// Pas d'écriture si rien n'a changé (300 élèves qui se connectent = 300 appels identiques)
$empreinte = hash('sha256', json_encode($propres));
if (reglage('catalogue:' . $app) === $empreinte) {
    envoyer_json(200, ['ok' => true, 'change' => false, 'nombre' => count($propres)]);
}

$pdo = db();
$pdo->beginTransaction();
$pdo->prepare('DELETE FROM activites WHERE app = ?')->execute([$app]);
$ins = $pdo->prepare('INSERT INTO activites (app, activite, titre, groupe, total, ordre, competences) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ($propres as $id => $a) {
    $ins->execute([$app, $id, $a['titre'], $a['groupe'], $a['total'], $a['ordre'], $a['competences']]);
}
$pdo->commit();
definir_reglage('catalogue:' . $app, $empreinte);

envoyer_json(200, ['ok' => true, 'change' => true, 'nombre' => count($propres)]);
