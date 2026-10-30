<?php
/**
 * GET /api/parcours.php (en-tête X-Jeton)
 * Parcours de la classe de l'élève, avec son avancement à chaque étape.
 *
 * → { ok, parcours: [ { id, nom, description, taux, etapes: [
 *        { titre, app, activite, url, statut: a_faire|commence|termine, taux, texte } ] } ] }
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();

$prog = progression_eleves([$eleve['id']])[$eleve['id']] ?? [];
$catalogue = catalogue_activites();

$res = [];
foreach (parcours_pour_classe($eleve['classe']) as $p) {
    $etapes = [];
    $somme = 0.0;
    foreach ($p['etapes'] as $et) {
        $etat = etat_etape($et['activite'], $prog[$et['app']] ?? [], $catalogue[$et['app']] ?? []);
        $somme += $etat['taux'];
        $etapes[] = [
            'titre'    => $et['titre'],
            'app'      => $et['app'],
            'activite' => $et['activite'],
            'url'      => $et['url'],
            'statut'   => $etat['statut'],
            'taux'     => round($etat['taux'], 3),
            'texte'    => $etat['texte'],
        ];
    }
    $res[] = [
        'id'          => (int) $p['id'],
        'nom'         => $p['nom'],
        'description' => $p['description'],
        'taux'        => $etapes ? round($somme / count($etapes), 3) : 0,
        'etapes'      => $etapes,
    ];
}

envoyer_json(200, ['ok' => true, 'parcours' => $res]);
