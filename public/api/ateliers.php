<?php
/**
 * GET /api/ateliers.php (en-tête X-Jeton)
 * Ateliers proposés à la classe de l'élève, avec son avancement et la liste des activités.
 *
 * → { ok, ateliers: [ { app, nom, description, url, icone, taux, terminees, total, maj,
 *        activites: [ { id, titre, groupe, statut: a_faire|commence|termine, texte, propose,
 *                       disponible, ouvre_le, jusqu_au } ] } ] }
 *
 * Périodes de disponibilité des ateliers (Ateliers des applications → Disponibilité) : une activité d'un atelier
 * pas encore ouvert est listée avec disponible = false et ouvre_le (AAAA-MM-JJ) ; celle d'un atelier fermé n'est plus
 * listée, sauf si l'élève l'a commencée. jusqu_au : dernier jour d'ouverture, s'il y en a un. Elles ne comptent
 * pas dans l'avancement tant qu'elles ne sont pas ouvertes.
 *
 * Réglage « portail_activites » (page Bibliothèque de l'espace enseignant) : « proposees » (par défaut) ne liste
 * que les activités proposées à l'élève et celles qu'il a déjà commencées ; « toutes » liste tout le catalogue.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();

$prog = progression_eleves([$eleve['id']])[$eleve['id']] ?? [];
$catalogue = catalogue_activites();
$proposees = propositions_eleve((int) $eleve['id']);
$filtrer = portail_seulement_proposees();

$cid = (int) $eleve['classe_id'];
$gid = isset($eleve['groupe_id']) ? (int) $eleve['groupe_id'] : null;
$dispos = disponibilites_classe($cid);

$res = [];
foreach (ateliers_pour_classe($eleve['classe']) as $a) {   // tous les ateliers de la classe : la disponibilité est calculée ci-dessous
    $app = $a['app'];
    // Fenêtres d'activation (calendrier) : disponible maintenant, « bientôt » (avec une date), ou caché.
    $disponible = atelier_disponible_maintenant($app, $cid, $gid);
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
        if ($filtrer && !$propose && !isset($lignes[$id])) {
            continue;
        }
        $etat = etat_etape($id, $lignes, $cat);
        $d = disponibilite_activite($dispos, $app, (string) $id);
        if ($d['etat'] === 'ferme' && !isset($lignes[$id])) {
            $fermees++;
            continue;   // atelier fermé, pas commencé : plus montré
        }
        if ($d['etat'] === 'ouvert') {
            $taux[] = $etat['taux'];
        }
        $activites[] = ['id' => $id, 'titre' => $c['titre'] ?: $id, 'groupe' => $c['groupe'],
                        'statut' => $etat['statut'], 'texte' => $etat['texte'], 'propose' => $propose,
                        'disponible' => $d['etat'] === 'ouvert', 'ouvre_le' => $d['ouvre_le'], 'jusqu_au' => $d['jusqu_au']];
    }
    foreach ($lignes as $id => $l) { // commencées mais absentes de la liste déclarée
        if (!isset($cat[$id])) {
            $etat = etat_etape($id, $lignes, []);
            $taux[] = $etat['taux'];
            $activites[] = ['id' => $id, 'titre' => $l['titre'] ?: $id, 'groupe' => '',
                            'statut' => $etat['statut'], 'texte' => $etat['texte'], 'propose' => isset($proposees[$app][$id]),
                            'disponible' => true, 'ouvre_le' => null, 'jusqu_au' => null];
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
        'terminees'   => count(array_filter($activites, fn($x) => $x['statut'] === 'termine' && $x['disponible'] !== false)),
        'total'       => count(array_filter($activites, fn($x) => $x['disponible'] !== false)),
        'maj'         => $global['maj'],
        'activites'   => $activites,
        'disponible'    => $disponible,
        'disponible_le' => $disponible_le,   // horodatage d'ouverture si « bientôt », sinon null
    ];
}

envoyer_json(200, ['ok' => true, 'ateliers' => $res]);
