<?php
/**
 * GET /api/ateliers.php (en-tête X-Jeton)
 * Ateliers proposés à la classe de l'élève, avec son avancement et la liste des activités.
 *
 * → { ok, ateliers: [ { app, nom, description, url, icone, taux, terminees, total, maj,
 *        activites: [ { id, titre, groupe, statut: a_faire|commence|termine, texte, propose,
 *                       disponible, ouvre_le, jusqu_au, revision } ] } ] }
 *   revision : atelier remis en avant par l'enseignant dans le Calendrier (section « Révisions » du portail).
 *   atelier_ouvrir : lien propre à l'atelier (« #atelier=premiers-pas »), ajouté à l'adresse de l'application.
 *
 * Périodes de disponibilité des ateliers (Ateliers des applications → Disponibilité) : une activité d'un atelier
 * pas encore ouvert est listée avec disponible = false et ouvre_le (AAAA-MM-JJ) ; celle d'un atelier fermé n'est plus
 * listée, sauf si l'élève l'a commencée. jusqu_au : dernier jour d'ouverture, s'il y en a un. Elles ne comptent
 * pas dans l'avancement tant qu'elles ne sont pas ouvertes.
 *
 * Réglage « portail_activites » (page Bibliothèque de l'espace enseignant) : « proposees » (par défaut) ne liste
 * que les activités proposées à l'élève et celles qu'il a déjà commencées ; « toutes » liste tout le catalogue.
 *
 * Dans chaque atelier, avec ou sans calendrier, les étapes s'ouvrent une à une (verrou = true, apres = étape à terminer
 * d'abord) ; ce que l'enseignant a demandé reste ouvert.
 * Classe avec une progression (page Calendrier, lib/progression.php) : seuls les ateliers de la progression sont
 * listés (ouverts à leur date, « bientôt » avant), dans son ordre. Chaque activité porte alors atelier et
 * atelier_titre : le portail fait une carte par atelier (sans calendrier : une carte par application).
 *
 * Vue enseignant d'un essai (« Tester à la place d'un élève », interrupteur Élève | Enseignant) : tout le catalogue
 * des ateliers de la classe, ouverts, sans calendrier ni étapes verrouillées (vue = 'enseignant' dans la réponse).
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();
$vue_prof = session_essai()['vue'] === 'enseignant';

$prog = progression_eleves([$eleve['id']])[$eleve['id']] ?? [];
$catalogue = catalogue_activites();
$proposees = propositions_eleve((int) $eleve['id']);
$filtrer = portail_seulement_proposees() && !$vue_prof;

$cid = (int) $eleve['classe_id'];
$gid = isset($eleve['groupe_id']) ? (int) $eleve['groupe_id'] : null;
$dispos = disponibilites_classe($cid);
$avec_progression = isset($dispos['*progression']);
$rang = [];   // atelier du catalogue => place dans la progression
foreach ($avec_progression ? progression_classe($cid) : [] as $i => $r) {
    $rang[$r['app']][$r['atelier']] = $i;
}
$titres = [];  // titres des ateliers du catalogue (une carte par atelier sur le portail, avec une progression)
$ouvertures = [];   // lien propre à l'atelier dans son application (#atelier=premiers-pas…), s'il en a un
foreach ($avec_progression ? catalogue_ateliers() : [] as $r) {
    $titres[$r['app']][$r['atelier']] = $r['titre'] ?: $r['atelier'];
    if (preg_match('/^#[A-Za-z0-9_.\/%=&+-]+$/', (string) $r['ouvrir'])) {
        $ouvertures[$r['app']][$r['atelier']] = (string) $r['ouvrir'];
    }
}

$res = [];
foreach (ateliers_pour_classe($eleve['classe']) as $a) {   // tous les ateliers de la classe : la disponibilité est calculée ci-dessous
    $app = $a['app'];
    // Fenêtres d'activation (calendrier) : disponible maintenant, « bientôt » (avec une date), ou caché.
    $disponible = $vue_prof || atelier_disponible_maintenant($app, $cid, $gid);
    $disponible_le = null;
    if (!$disponible) {
        $disponible_le = prochaine_disponibilite($app, $cid, $gid);
        if ($disponible_le === null) {
            continue;   // ni maintenant ni bientôt : pas montré
        }
    }
    $lignes = $prog[$app] ?? [];
    $cat = $catalogue[$app] ?? [];
    $activites = [];
    $taux = [];
    $fermees = 0;
    foreach ($cat as $id => $c) {
        $propose = isset($proposees[$app][$id]);
        if ($filtrer && !$avec_progression && !$propose && !isset($lignes[$id])) {
            continue;
        }
        $etat = etat_etape($id, $lignes, $cat);
        $d = disponibilite_activite($dispos, $app, (string) $id);
        // fermé : plus montré, sauf commencé (périodes) ; avec une progression, hors progression = jamais montré
        // (le travail de l'élève reste enregistré et revient si l'atelier est remis dans la progression)
        $par_progression = ($d['source'] ?? '') === 'progression';
        if ($vue_prof) {
            $d = ['etat' => 'ouvert', 'ouvre_le' => null, 'jusqu_au' => null, 'revision' => !empty($d['revision'])] + $d;
        }
        if ($d['etat'] === 'ferme' && !($avec_progression && $propose) && (!isset($lignes[$id]) || $par_progression)) {
            $fermees++;
            continue;   // atelier fermé, pas commencé : plus montré
        }
        if ($d['etat'] === 'ouvert') {
            $taux[] = $etat['taux'];
        }
        $activites[] = ['id' => $id, 'titre' => $c['titre'] ?: $id, 'groupe' => $c['groupe'],
                        'statut' => $etat['statut'], 'texte' => $etat['texte'], 'propose' => $propose,
                        'disponible' => $d['etat'] === 'ouvert' || ($avec_progression && $propose),
                        'ouvre_le' => $d['ouvre_le'], 'jusqu_au' => $d['jusqu_au'], 'revision' => !empty($d['revision'])];
    }
    foreach ($lignes as $id => $l) { // commencées mais absentes de la liste déclarée
        if (!isset($cat[$id]) && !($avec_progression && disponibilite_activite($dispos, $app, (string) $id)['etat'] !== 'ouvert')) {
            $etat = etat_etape($id, $lignes, []);
            $taux[] = $etat['taux'];
            $activites[] = ['id' => $id, 'titre' => $l['titre'] ?: $id, 'groupe' => '',
                            'statut' => $etat['statut'], 'texte' => $etat['texte'], 'propose' => isset($proposees[$app][$id]),
                            'disponible' => true, 'ouvre_le' => null, 'jusqu_au' => null];
        }
    }
    if ($avec_progression) {   // dans l'ordre de la progression
        $place = fn($x) => $rang[$app][atelier_de_activite_classe($dispos, $app, (string) $x['id']) ?? ''] ?? PHP_INT_MAX;
        $num = array_flip(array_keys($activites));
        uksort($activites, fn($i, $j) => $place($activites[$i]) <=> $place($activites[$j]) ?: $num[$i] <=> $num[$j]);
    }
    // étapes une à une dans chaque atelier, avec ou sans calendrier (une étape s'ouvre quand la précédente est terminée)
    $activites = verrouiller_etapes($app, array_values($activites), $dispos);
    if ($vue_prof) {   // vue enseignant d'un essai : aucune étape verrouillée
        foreach ($activites as $k => $x) {
            $activites[$k]['verrou'] = false;
            $activites[$k]['disponible'] = true;
            unset($activites[$k]['apres']);
        }
    }
    foreach ($avec_progression ? $activites : [] as $k => $x) {   // l'atelier de chaque étape : le portail fait une carte par atelier
        $at = atelier_de_activite_classe($dispos, $app, (string) $x['id']);
        if ($at !== null) {
            $activites[$k]['atelier'] = $at;
            $activites[$k]['atelier_titre'] = $titres[$app][$at] ?? $at;
            if (isset($ouvertures[$app][$at])) {
                $activites[$k]['atelier_ouvrir'] = $ouvertures[$app][$at];   // le portail ouvre l'atelier entier (ex. NetSim « Premiers pas »)
            }
        }
    }
    $global = etat_etape('', $lignes, $cat);
    // Toutes les activités de l'application attendent l'ouverture de leur atelier : carte « Bientôt » ;
    // toutes fermées (et pas commencées) : l'application n'est plus montrée.
    $ouvertes = array_filter($activites, fn($x) => $x['disponible'] !== false);
    if ($cat && !$ouvertes && $disponible) {
        $dates = array_filter(array_column($activites, 'ouvre_le'));
        if (!$dates && $fermees) {
            continue;
        }
        if ($dates) {
            $disponible = false;
            $disponible_le = (new DateTimeImmutable(min($dates) . ' 00:00', fuseau()))->getTimestamp();
        }
    }
    $res[] = [
        'app'         => $app,
        'nom'         => $a['nom'],
        'description' => $a['description'],
        'url'         => meilleure_adresse($a),   // adresse de secours si la principale ne répond plus
        'icone'       => $a['icone'],
        'taux'        => round($filtrer || count($ouvertes) < count($activites) ? ($taux ? array_sum($taux) / count($taux) : 0) : $global['taux'], 3),
        'terminees'   => count(array_filter($activites, fn($x) => $x['statut'] === 'termine' && ($x['disponible'] !== false || !empty($x['verrou'])))),
        'total'       => count(array_filter($activites, fn($x) => $x['disponible'] !== false || !empty($x['verrou']))),
        'maj'         => $global['maj'],
        'activites'   => $activites,
        'disponible'    => $disponible,
        'disponible_le' => $disponible_le,   // horodatage d'ouverture si « bientôt », sinon null
    ];
}

envoyer_json(200, ['ok' => true, 'ateliers' => $res, 'vue' => $vue_prof ? 'enseignant' : 'eleve']);
