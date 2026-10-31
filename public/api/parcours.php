<?php
/**
 * GET /api/parcours.php (en-tête X-Jeton)
 * Parcours de la classe de l'élève, avec son avancement à chaque étape.
 *
 * → { ok, parcours: [ { id, nom, description, mode, deblocage, equipe, coequipiers, sans_equipe, fichiers, taux, etapes: [
 *        { ordre, titre, app, activite, url, consigne, entrees, sorties, deposees,
 *          statut: a_faire|commence|termine, taux, texte, ouverte } ] } ] }
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();

$prog = progression_eleves([$eleve['id']])[$eleve['id']] ?? [];
$catalogue = catalogue_activites();

// Adresse de secours : si une étape pointe vers un atelier dont l'adresse principale ne répond plus,
// on la remplace par la première adresse de secours qui répond.
$remplacements = [];
foreach (db()->query('SELECT * FROM ateliers')->fetchAll() as $a) {
    $meilleure = meilleure_adresse($a);
    if ($meilleure !== $a['url']) {
        $remplacements[$a['url']] = $meilleure;
    }
}
$adresse = function (string $url) use ($remplacements): string {
    foreach ($remplacements as $de => $vers) {
        if ($url !== '' && str_starts_with($url, $de)) {
            return $vers . substr($url, strlen($de));
        }
    }
    return $url;
};

$res = [];
foreach (parcours_pour_classe($eleve['classe']) as $p) {
    // Avancement du dossier de projet : celui de l'élève, ou de son équipe (parcours en équipe)
    $proprio = proprietaire_projet($eleve, $p);
    $etats = etapes_projet($p, $proprio, $proprio['type'] === 'eleve' ? [$eleve['id'] => $prog] : null, $catalogue);
    $etapes = [];
    $somme = 0.0;
    foreach ($p['etapes'] as $i => $et) {
        $e = $etats[$i];
        $somme += $e['taux'];
        $etapes[] = [
            'ordre'    => $i,
            'titre'    => $et['titre'],
            'app'      => $et['app'],
            'activite' => $et['activite'],
            'url'      => $adresse($et['url']),
            'consigne' => $et['consigne'],
            'entrees'  => $e['entrees'],
            'sorties'  => $e['sorties'],
            'deposees' => $e['deposees'],
            'statut'   => $e['statut'],
            'taux'     => round($e['taux'], 3),
            'texte'    => $e['texte'],
            'ouverte'  => $e['ouverte'],
        ];
    }
    $membres = $proprio['type'] === 'equipe' ? identifiants_eleves(array_diff($proprio['membres'], [$eleve['id']])) : [];
    $res[] = [
        'id'          => (int) $p['id'],
        'nom'         => $p['nom'],
        'description' => $p['description'],
        'mode'        => $p['mode'],
        'deblocage'   => $p['deblocage'],
        'equipe'      => $proprio['equipe']['nom'] ?? null,
        'coequipiers' => array_values($membres),
        'sans_equipe' => !empty($proprio['sans_equipe']),
        'fichiers'    => count(fichiers_projet((int) $p['id'], $proprio['cle'])),
        'taux'        => $etapes ? round($somme / count($etapes), 3) : 0,
        'etapes'      => $etapes,
    ];
}

envoyer_json(200, ['ok' => true, 'parcours' => $res]);
