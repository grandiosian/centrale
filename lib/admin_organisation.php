<?php
/**
 * Centrale élèves — espace enseignant : établissements, emploi du temps, salles et plans de classe.
 * Inclus par public/admin/index.php (utilise ses fonctions d'affichage).
 */
declare(strict_types=1);

require_once __DIR__ . '/organisation.php';

/* ======================================================================
   Actions
   ====================================================================== */

function action_organisation(string $action): bool
{
    $pdo = db();
    $p = $_POST;
    $eid = (int) ($p['etablissement_id'] ?? 0);
    $retour = (string) ($p['retour'] ?? '');
    $retour = preg_match('#^\./\?p=[a-z_]+[A-Za-z0-9_=&%:.-]*(\#[a-z0-9_-]+)?$#', $retour) ? $retour : './?p=edt';

    switch ($action) {
        /* ----- Établissements ----- */
        case 'enregistrer_etablissement':
            $nom = mb_substr(trim((string) ($p['nom'] ?? '')), 0, 80);
            $zone = in_array($p['zone'] ?? '', ['A', 'B', 'C'], true) ? $p['zone'] : '';
            $ref = trim((string) ($p['ref_semaine_a'] ?? ''));
            [$grille, $erreurs] = lire_grille((string) ($p['grille'] ?? ''));
            if ($nom === '') {
                $erreurs[] = "Donnez un nom à l'établissement.";
            }
            $code = strtoupper(trim((string) ($p['code'] ?? '')));
            if ($code === '' && $nom !== '') {
                $code = code_etablissement_suggere($nom);
            }
            if (!preg_match('/^[A-Z]{3}$/', $code)) {
                $erreurs[] = 'Le code de l’établissement doit faire exactement 3 lettres (ex. LHA).';
            } else {
                $st = $pdo->prepare('SELECT nom FROM etablissements WHERE code = ? AND id <> ?');
                $st->execute([$code, $eid]);
                if ($autre = $st->fetchColumn()) {
                    $erreurs[] = "Le code $code est déjà celui de « $autre ».";
                }
            }
            if (!empty($p['ab_actif']) && !date_valide($ref)) {
                $erreurs[] = 'Indiquez une date de semaine A (un jour de n’importe quelle semaine A).';
            }
            if (!$grille) {
                $erreurs[] = 'La grille horaire doit contenir au moins un créneau.';
            }
            if ($erreurs) {
                $_SESSION['brouillon_etab'] = $p;
                foreach ($erreurs as $e) {
                    flash($e, 'err');
                }
                rediriger('./?p=etablissements' . ($eid ? '&id=' . $eid : '&nouveau=1'));
            }
            $champs = [$nom, $zone, empty($p['ab_actif']) ? 0 : 1, date_valide($ref) ? lundi_de($ref) : '',
                       empty($p['ab_sans_vacances']) ? 0 : 1, empty($p['samedi']) ? 0 : 1];
            $couleur = preg_match('/^#[0-9a-f]{6}$/i', (string) ($p['couleur'] ?? '')) ? strtolower($p['couleur']) : '';
            $pdo->beginTransaction();
            if ($eid) {
                $pdo->prepare('UPDATE etablissements SET nom = ?, zone = ?, ab_actif = ?, ref_semaine_a = ?, ab_sans_vacances = ?, samedi = ?, code = ?, couleur = ? WHERE id = ?')
                    ->execute([...$champs, $code, $couleur, $eid]);
            } else {
                $pdo->prepare('INSERT INTO etablissements (nom, zone, ab_actif, ref_semaine_a, ab_sans_vacances, samedi, code, couleur, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$champs, $code, $couleur, time()]);
                $eid = (int) $pdo->lastInsertId();
            }
            // Grille : on met à jour les créneaux existants dans l'ordre, pour ne pas perdre les cours qui y sont rattachés
            $anciens = creneaux_de($eid);
            foreach ($grille as $i => $c) {
                if (isset($anciens[$i])) {
                    $pdo->prepare('UPDATE creneaux SET ordre = ?, libelle = ?, debut = ?, fin = ? WHERE id = ?')
                        ->execute([$i, $c['libelle'], $c['debut'], $c['fin'], $anciens[$i]['id']]);
                } else {
                    $pdo->prepare('INSERT INTO creneaux (etablissement_id, ordre, libelle, debut, fin) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$eid, $i, $c['libelle'], $c['debut'], $c['fin']]);
                }
            }
            $refuses = 0;
            foreach (array_slice($anciens, count($grille)) as $c) {
                $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM cours WHERE creneau_id = ?) + (SELECT COUNT(*) FROM seances_modifiees WHERE creneau_id = ?)');
                $st->execute([$c['id'], $c['id']]);
                if ((int) $st->fetchColumn() > 0) {
                    $refuses++;
                    $pdo->prepare('UPDATE creneaux SET ordre = ? WHERE id = ?')->execute([100 + (int) $c['ordre'], $c['id']]);
                } else {
                    $pdo->prepare('DELETE FROM creneaux WHERE id = ?')->execute([$c['id']]);
                }
            }
            $pdo->commit();
            flash("Établissement « $nom » enregistré." . ($refuses
                ? " $refuses créneau(x) retiré(s) de la grille sont encore utilisés par des cours : ils restent en fin de grille jusqu'à ce que ces cours soient déplacés."
                : ''), $refuses ? 'err' : 'ok');
            rediriger('./?p=etablissements&id=' . $eid);

        case 'supprimer_etablissement':
            $pdo->prepare('UPDATE classes SET etablissement_id = NULL WHERE etablissement_id = ?')->execute([$eid]);
            $pdo->prepare('DELETE FROM cours WHERE classe_id IN (SELECT id FROM classes WHERE etablissement_id = ?)')->execute([$eid]);
            $pdo->prepare('DELETE FROM etablissements WHERE id = ?')->execute([$eid]);
            flash('Établissement supprimé (les classes et leurs élèves sont conservés).');
            rediriger('./?p=etablissements');

        case 'importer_vacances':
            $e = etablissement($eid);
            $annee = preg_match('/^\d{4}-\d{4}$/', (string) ($p['annee'] ?? '')) ? $p['annee'] : annee_scolaire(aujourd_hui());
            try {
                $n = importer_vacances($e, $annee);
                flash("$n périodes de vacances importées (zone {$e['zone']}, $annee).");
            } catch (RuntimeException $ex) {
                flash($ex->getMessage(), 'err');
            }
            rediriger('./?p=etablissements&id=' . $eid . '#vacances');

        case 'ajouter_vacances':
            $debut = (string) ($p['debut'] ?? '');
            $reprise = (string) ($p['reprise'] ?? '');
            if (!date_valide($debut) || !date_valide($reprise) || $reprise <= $debut) {
                flash('Dates invalides : le jour de reprise doit être après le premier jour de vacances.', 'err');
            } else {
                $pdo->prepare("INSERT INTO vacances (etablissement_id, libelle, debut, fin, source) VALUES (?, ?, ?, ?, 'manuel')")
                    ->execute([$eid, mb_substr(trim((string) ($p['libelle'] ?? '')) ?: 'Vacances', 0, 80), $debut, $reprise]);
                flash('Période ajoutée.');
            }
            rediriger('./?p=etablissements&id=' . $eid . '#vacances');

        case 'supprimer_vacances':
            $pdo->prepare('DELETE FROM vacances WHERE id = ? AND etablissement_id = ?')->execute([(int) ($p['vacances_id'] ?? 0), $eid]);
            rediriger('./?p=etablissements&id=' . $eid . '#vacances');

        /* ----- Salles ----- */
        case 'ajouter_salle':
            $nom = mb_substr(trim((string) ($p['nom'] ?? '')), 0, 40);
            $st = $pdo->prepare('SELECT COUNT(*) FROM salles WHERE etablissement_id = ? AND nom = ? COLLATE NOCASE');
            $st->execute([$eid, $nom]);
            if ($nom !== '' && $st->fetchColumn()) {
                flash("Une salle « $nom » existe déjà dans cet établissement.", 'err');
                rediriger('./?p=etablissements&id=' . $eid . '#salles');
            }
            if ($nom !== '') {
                $pdo->prepare('INSERT INTO salles (etablissement_id, nom, plan) VALUES (?, ?, ?)')
                    ->execute([$eid, $nom, json_encode(['largeur' => 16, 'hauteur' => 11, 'objets' => []])]);
                rediriger('./?p=salle&id=' . $pdo->lastInsertId());
            }
            rediriger('./?p=etablissements&id=' . $eid . '#salles');

        case 'supprimer_salle':
            $pdo->prepare('DELETE FROM salles WHERE id = ? AND etablissement_id = ?')->execute([(int) ($p['salle_id'] ?? 0), $eid]);
            flash('Salle supprimée.');
            rediriger('./?p=etablissements&id=' . $eid . '#salles');

        case 'enregistrer_plan':
            $sid = (int) ($p['salle_id'] ?? 0);
            $plan = plan_propre((string) ($p['plan'] ?? ''));
            $nom = mb_substr(trim((string) ($p['nom'] ?? '')), 0, 40);
            if ($plan === null) {
                flash('Plan illisible : rien n’a été enregistré.', 'err');
            } else {
                $pdo->prepare('UPDATE salles SET plan = ?' . ($nom !== '' ? ', nom = ?' : '') . ' WHERE id = ?')
                    ->execute($nom !== '' ? [json_encode($plan, JSON_UNESCAPED_UNICODE), $nom, $sid] : [json_encode($plan, JSON_UNESCAPED_UNICODE), $sid]);
                // Les élèves placés sur une place qui n'existe plus sont retirés
                $places = array_column(array_filter($plan['objets'], fn($o) => in_array($o['type'], TYPES_PLACES, true)), 'id');
                $st = $pdo->prepare('SELECT DISTINCT place FROM placements WHERE salle_id = ?');
                $st->execute([$sid]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pl) {
                    if (!in_array($pl, $places, true)) {
                        $pdo->prepare('DELETE FROM placements WHERE salle_id = ? AND place = ?')->execute([$sid, $pl]);
                    }
                }
                flash('Plan de la salle enregistré (' . count($places) . ' places élèves).');
            }
            rediriger('./?p=salle&id=' . $sid);

        case 'enregistrer_placement':
            $cid = (int) ($p['classe_id'] ?? 0);
            $sid = (int) ($p['salle_id'] ?? 0);
            $gid = (int) ($p['groupe_id'] ?? 0);
            $g = $gid ? groupe_par_id($gid) : null;
            $gid = $g && (int) $g['classe_id'] === $cid ? $gid : 0;
            $carte = json_decode((string) ($p['placement'] ?? ''), true);
            if (!is_array($carte)) {
                flash('Placement illisible.', 'err');
                rediriger($retour);
            }
            if (!salle_compatible($pdo, $cid, $sid)) {
                flash('Cette salle n’appartient pas à l’établissement de la classe.', 'err');
                rediriger('./?p=classe&id=' . $cid . '#organisation');
            }
            $st = $pdo->prepare('SELECT id FROM eleves WHERE classe_id = ?' . ($gid ? ' AND groupe_id = ?' : ''));
            $st->execute($gid ? [$cid, $gid] : [$cid]);
            $eleves = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $st = $pdo->prepare('SELECT * FROM salles WHERE id = ?');
            $st->execute([$sid]);
            $salle = $st->fetch();
            $places = $salle ? array_column(array_filter(plan_de($salle)['objets'], fn($o) => in_array($o['type'], TYPES_PLACES, true)), 'id') : [];
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM placements WHERE classe_id = ? AND groupe_id = ? AND salle_id = ?')->execute([$cid, $gid, $sid]);
            $ins = $pdo->prepare('INSERT INTO placements (classe_id, groupe_id, salle_id, place, eleve_id) VALUES (?, ?, ?, ?, ?)');
            $deja = [];
            foreach ($carte as $place => $eleve) {
                $eleve = (int) $eleve;
                if (in_array((string) $place, $places, true) && in_array($eleve, $eleves, true) && !isset($deja[$eleve])) {
                    $ins->execute([$cid, $gid, $sid, (string) $place, $eleve]);
                    $deja[$eleve] = true;
                }
            }
            $pdo->commit();
            flash('Plan de classe enregistré (' . count($deja) . ' élèves placés).');
            rediriger('./?p=placement&classe=' . $cid . '&salle=' . $sid . ($gid ? '&groupe=' . $gid : ''));

        case 'enregistrer_version':
            $cid = (int) ($p['classe_id'] ?? 0);
            $sid = (int) ($p['salle_id'] ?? 0);
            $gid = (int) ($p['groupe_id'] ?? 0);
            $g = $gid ? groupe_par_id($gid) : null;
            $gid = $g && (int) $g['classe_id'] === $cid ? $gid : 0;
            $nom = mb_substr(trim((string) ($p['nom_version'] ?? '')), 0, 60);
            $carte = json_decode((string) ($p['placement'] ?? ''), true);
            if ($nom === '' || !is_array($carte)) {
                flash('Donnez un nom à la version du plan.', 'err');
                rediriger('./?p=placement&classe=' . $cid . '&salle=' . $sid . ($gid ? '&groupe=' . $gid : ''));
            }
            if (!salle_compatible($pdo, $cid, $sid)) {
                flash('Cette salle n’appartient pas à l’établissement de la classe.', 'err');
                rediriger('./?p=classe&id=' . $cid . '#organisation');
            }
            $propre = carte_placement_valide($pdo, $cid, $gid, $sid, $carte);
            $insertion = $pdo->prepare('INSERT INTO plans_versions (classe_id, groupe_id, salle_id, nom, carte, cree_le) VALUES (?, ?, ?, ?, ?, ?)');
            // premier enregistrement pour ce plan : le plan en cours (tel qu'enregistré avant) est gardé comme « Plan initial »,
            // pour pouvoir y revenir comme aux autres versions
            $st = $pdo->prepare('SELECT COUNT(*) FROM plans_versions WHERE classe_id = ? AND groupe_id = ? AND salle_id = ?');
            $st->execute([$cid, $gid, $sid]);
            if (!(int) $st->fetchColumn()) {
                $st = $pdo->prepare('SELECT eleve_id, place FROM placements WHERE classe_id = ? AND groupe_id = ? AND salle_id = ?');
                $st->execute([$cid, $gid, $sid]);
                $initial = array_map('intval', array_column($st->fetchAll(), 'eleve_id', 'place'));
                if ($initial) {
                    $insertion->execute([$cid, $gid, $sid, 'Plan initial', json_encode((object) $initial), date('Y-m-d H:i')]);
                }
            }
            $insertion->execute([$cid, $gid, $sid, $nom, json_encode((object) $propre), date('Y-m-d H:i')]);
            flash('Version « ' . $nom . ' » enregistrée (' . count($propre) . ' élèves placés).');
            rediriger('./?p=placement&classe=' . $cid . '&salle=' . $sid . ($gid ? '&groupe=' . $gid : ''));

        case 'charger_version':
            $cid = (int) ($p['classe_id'] ?? 0);
            $sid = (int) ($p['salle_id'] ?? 0);
            $gid = (int) ($p['groupe_id'] ?? 0);
            $st = $pdo->prepare('SELECT nom, carte FROM plans_versions WHERE id = ? AND classe_id = ? AND salle_id = ? AND groupe_id = ?');
            $st->execute([(int) ($p['version_id'] ?? 0), $cid, $sid, $gid]);
            $version = $st->fetch();
            if (!$version) {
                flash('Version introuvable.', 'err');
                rediriger('./?p=placement&classe=' . $cid . '&salle=' . $sid . ($gid ? '&groupe=' . $gid : ''));
            }
            if (!salle_compatible($pdo, $cid, $sid)) {
                flash('Cette salle n’appartient pas à l’établissement de la classe.', 'err');
                rediriger('./?p=classe&id=' . $cid . '#organisation');
            }
            // la version est revalidée : élèves et places encore présents
            $propre = carte_placement_valide($pdo, $cid, $gid, $sid, json_decode((string) $version['carte'], true) ?: []);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM placements WHERE classe_id = ? AND groupe_id = ? AND salle_id = ?')->execute([$cid, $gid, $sid]);
            $ins = $pdo->prepare('INSERT INTO placements (classe_id, groupe_id, salle_id, place, eleve_id) VALUES (?, ?, ?, ?, ?)');
            foreach ($propre as $place => $eleve) {
                $ins->execute([$cid, $gid, $sid, (string) $place, $eleve]);
            }
            $pdo->commit();
            flash('Version « ' . $version['nom'] . ' » chargée (' . count($propre) . ' élèves placés).');
            rediriger('./?p=placement&classe=' . $cid . '&salle=' . $sid . ($gid ? '&groupe=' . $gid : ''));

        case 'supprimer_version':
            $cid = (int) ($p['classe_id'] ?? 0);
            $sid = (int) ($p['salle_id'] ?? 0);
            $gid = (int) ($p['groupe_id'] ?? 0);
            $pdo->prepare('DELETE FROM plans_versions WHERE id = ? AND classe_id = ? AND salle_id = ? AND groupe_id = ?')
                ->execute([(int) ($p['version_id'] ?? 0), $cid, $sid, $gid]);
            flash('Version supprimée.');
            rediriger('./?p=placement&classe=' . $cid . '&salle=' . $sid . ($gid ? '&groupe=' . $gid : ''));

        case 'classe_etablissement':
            $cid = (int) ($p['classe_id'] ?? 0);
            $pdo->prepare('UPDATE classes SET etablissement_id = ? WHERE id = ?')->execute([$eid ?: null, $cid]);
            flash($eid ? 'Classe rattachée à l’établissement.' : 'Classe détachée de son établissement.');
            rediriger(($p['depuis'] ?? '') === 'etablissement' ? './?p=etablissements&id=' . $eid . '#classes' : './?p=classe&id=' . $cid . '#organisation');

        /* ----- Cours récurrents ----- */
        case 'enregistrer_cours':
            $id = (int) ($p['cours_id'] ?? 0);
            $c = lire_cours($p);
            if (is_string($c)) {
                flash($c, 'err');
                rediriger($retour);
            }
            if ($id) {
                $pdo->prepare('UPDATE cours SET classe_id = ?, jour = ?, creneau_id = ?, duree = ?, semaine = ?, salle_id = ?, intitule = ?, debut = ?, fin = ?, groupe_id = ? WHERE id = ?')
                    ->execute([...array_values($c), $id]);
                flash('Cours modifié pour toutes les semaines.');
            } else {
                $pdo->prepare('INSERT INTO cours (classe_id, jour, creneau_id, duree, semaine, salle_id, intitule, debut, fin, groupe_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute(array_values($c));
                flash('Cours ajouté à l’emploi du temps.');
            }
            rediriger($retour);

        case 'supprimer_cours':
            $pdo->prepare('DELETE FROM cours WHERE id = ?')->execute([(int) ($p['cours_id'] ?? 0)]);
            flash('Cours retiré de l’emploi du temps (toutes les semaines).');
            rediriger($retour);

        /* ----- Une séance précise ----- */
        case 'seance_annuler':
            $cours_id = (int) ($p['cours_id'] ?? 0);
            $date = (string) ($p['date_origine'] ?? '');
            $st = $pdo->prepare('SELECT classe_id FROM cours WHERE id = ?');
            $st->execute([$cours_id]);
            $cid = $st->fetchColumn();
            if ($cid !== false && date_valide($date)) {
                $pdo->prepare('DELETE FROM seances_modifiees WHERE cours_id = ? AND date_origine = ?')->execute([$cours_id, $date]);
                $pdo->prepare("INSERT INTO seances_modifiees (cours_id, classe_id, date_origine, statut, note) VALUES (?, ?, ?, 'annule', ?)")
                    ->execute([$cours_id, $cid, $date, mb_substr(trim((string) ($p['note'] ?? '')), 0, 200)]);
                flash('Cours du ' . date_lisible($date) . ' annulé.');
            }
            rediriger($retour);

        case 'seance_modifier':
        case 'seance_ajouter':
            $modif_id = (int) ($p['modif_id'] ?? 0);
            $cours_id = (int) ($p['cours_id'] ?? 0);
            $date = (string) ($p['date'] ?? '');
            $creneau = (int) ($p['creneau_id'] ?? 0);
            if (!date_valide($date) || !$creneau) {
                flash('Indiquez une date et un créneau.', 'err');
                rediriger($retour);
            }
            $salle = (int) ($p['salle_id'] ?? 0) ?: null;
            $duree = max(1, min(8, (int) ($p['duree'] ?? 1)));
            $note = mb_substr(trim((string) ($p['note'] ?? '')), 0, 200);
            $intitule = mb_substr(trim((string) ($p['intitule'] ?? '')), 0, 60);
            if ($modif_id) {
                $pdo->prepare("UPDATE seances_modifiees SET statut = CASE WHEN statut = 'ajoute' THEN 'ajoute' ELSE 'modifie' END,
                               date = ?, creneau_id = ?, duree = ?, salle_id = ?, note = ?, intitule = ? WHERE id = ?")
                    ->execute([$date, $creneau, $duree, $salle, $note, $intitule, $modif_id]);
            } elseif ($cours_id) {
                $date_origine = (string) ($p['date_origine'] ?? '');
                $st = $pdo->prepare('SELECT classe_id, intitule, groupe_id FROM cours WHERE id = ?');
                $st->execute([$cours_id]);
                $c = $st->fetch();
                if (!$c || !date_valide($date_origine)) {
                    rediriger($retour);
                }
                $pdo->prepare('DELETE FROM seances_modifiees WHERE cours_id = ? AND date_origine = ?')->execute([$cours_id, $date_origine]);
                $pdo->prepare("INSERT INTO seances_modifiees (cours_id, classe_id, date_origine, statut, date, creneau_id, duree, salle_id, intitule, note, groupe_id)
                               VALUES (?, ?, ?, 'modifie', ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$cours_id, $c['classe_id'], $date_origine, $date, $creneau, $duree, $salle, $intitule ?: $c['intitule'], $note, $c['groupe_id']]);
            } else {
                [$cid, $gid] = lire_classe_groupe((string) ($p['classe_id'] ?? ''));
                if (!classe_par_id($cid)) {
                    flash('Choisissez une classe.', 'err');
                    rediriger($retour);
                }
                $pdo->prepare("INSERT INTO seances_modifiees (cours_id, classe_id, statut, date, creneau_id, duree, salle_id, intitule, note, groupe_id)
                               VALUES (NULL, ?, 'ajoute', ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$cid, $date, $creneau, $duree, $salle, $intitule, $note, $gid]);
            }
            flash('Séance du ' . date_lisible($date) . ' enregistrée.');
            rediriger(preg_replace('/&semaine=[0-9-]+/', '', $retour) . '&semaine=' . lundi_de($date));

        case 'seance_retablir':
            $pdo->prepare('DELETE FROM seances_modifiees WHERE id = ?')->execute([(int) ($p['modif_id'] ?? 0)]);
            flash('Le cours prévu est rétabli.');
            rediriger(preg_replace('/&seance=[a-z0-9_-]+/i', '', $retour));
    }
    return false;
}

/** Valide un cours récurrent envoyé par formulaire. Renvoie les champs, ou un message d'erreur. */
/** Placements acceptés pour une classe, un groupe et une salle : seulement des places élèves et des élèves de la classe, sans doublon. */
function carte_placement_valide(PDO $pdo, int $cid, int $gid, int $sid, array $carte): array
{
    $st = $pdo->prepare('SELECT id FROM eleves WHERE classe_id = ?' . ($gid ? ' AND groupe_id = ?' : ''));
    $st->execute($gid ? [$cid, $gid] : [$cid]);
    $eleves = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = $pdo->prepare('SELECT * FROM salles WHERE id = ?');
    $st->execute([$sid]);
    $salle = $st->fetch();
    $places = $salle ? array_column(array_filter(plan_de($salle)['objets'], fn($o) => in_array($o['type'], TYPES_PLACES, true)), 'id') : [];
    $propre = [];
    $deja = [];
    foreach ($carte as $place => $eleve) {
        $eleve = (int) $eleve;
        if (in_array((string) $place, $places, true) && in_array($eleve, $eleves, true) && !isset($deja[$eleve])) {
            $propre[(string) $place] = $eleve;
            $deja[$eleve] = true;
        }
    }
    return $propre;
}

function lire_cours(array $p): array|string
{
    [$cid, $gid] = lire_classe_groupe((string) ($p['classe_id'] ?? ''));
    $classe = classe_par_id($cid);
    if (!$classe || !$classe['etablissement_id']) {
        return 'Choisissez une classe rattachée à un établissement.';
    }
    $jour = (int) ($p['jour'] ?? 0);
    if (!isset(JOURS[$jour])) {
        return 'Jour invalide.';
    }
    $st = db()->prepare('SELECT id FROM creneaux WHERE id = ? AND etablissement_id = ?');
    $st->execute([(int) ($p['creneau_id'] ?? 0), $classe['etablissement_id']]);
    if (!$st->fetchColumn()) {
        return 'Choisissez un créneau de la grille de l’établissement.';
    }
    $debut = trim((string) ($p['debut'] ?? ''));
    $fin = trim((string) ($p['fin'] ?? ''));
    if (($debut !== '' && !date_valide($debut)) || ($fin !== '' && !date_valide($fin))) {
        return 'Dates de début ou de fin invalides.';
    }
    return [
        'classe_id'  => (int) $classe['id'],
        'jour'       => $jour,
        'creneau_id' => (int) $p['creneau_id'],
        'duree'      => max(1, min(8, (int) ($p['duree'] ?? 1))),
        'semaine'    => in_array($p['semaine'] ?? '', ['A', 'B'], true) ? $p['semaine'] : '',
        'salle_id'   => (int) ($p['salle_id'] ?? 0) ?: null,
        'intitule'   => mb_substr(trim((string) ($p['intitule'] ?? '')), 0, 60),
        'debut'      => $debut,
        'fin'        => $fin,
        'groupe_id'  => $gid,
    ];
}

/* ======================================================================
   Pages
   ====================================================================== */

function options_html(array $liste, $choisi, string $vide = ''): string
{
    $h = $vide !== '' ? '<option value="">' . h($vide) . '</option>' : '';
    foreach ($liste as $v => $libelle) {
        $h .= '<option value="' . h((string) $v) . '"' . ((string) $v === (string) $choisi ? ' selected' : '') . '>' . h($libelle) . '</option>';
    }
    return $h;
}

function page_etablissements(): void
{
    $pdo = db();
    $liste = $pdo->query('SELECT e.*, (SELECT COUNT(*) FROM classes c WHERE c.etablissement_id = e.id) AS nb_classes,
                                 (SELECT COUNT(*) FROM salles s WHERE s.etablissement_id = e.id) AS nb_salles
                          FROM etablissements e ORDER BY e.nom')->fetchAll();
    $id = (int) ($_GET['id'] ?? 0);
    $nouveau = !empty($_GET['nouveau']) || !$liste;
    $brouillon = $_SESSION['brouillon_etab'] ?? null;
    unset($_SESSION['brouillon_etab']);
    $e = $id ? etablissement($id) : null;

    entete('Établissements');
    barre('etablissements');
    ?>
    <main>
        <div class="titre-section">
            <h1>Établissements</h1>
            <?php if (!$nouveau): ?><a class="btn" href="./?p=etablissements&nouveau=1">Ajouter un établissement</a><?php endif; ?>
        </div>
        <p class="sous">Chaque établissement a sa grille horaire, ses semaines A/B, ses vacances et ses salles.
            Les classes y sont rattachées depuis leur page.</p>
        <?php messages(); ?>

        <?php if ($liste && !$e): ?>
            <div class="grille-classes">
                <?php foreach ($liste as $x): ?>
                    <a class="carte classe avec-etab" style="--etab:<?= h(couleur_etablissement((int) $x['id'])) ?>" href="./?p=etablissements&id=<?= (int) $x['id'] ?>">
                        <strong><span class="puce-etab"></span><?= h($x['nom']) ?></strong>
                        <span><?= (int) $x['nb_classes'] ?> classe<?= $x['nb_classes'] > 1 ? 's' : '' ?> · <?= (int) $x['nb_salles'] ?> salle<?= $x['nb_salles'] > 1 ? 's' : '' ?></span>
                        <span class="discret">Code <?= h(code_etablissement((int) $x['id'])) ?> · <?= $x['zone'] ? 'Zone ' . h($x['zone']) : 'Zone non précisée' ?><?= $x['ab_actif'] ? ' · semaines A/B' : '' ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($nouveau || $e):
            $v = $brouillon ?? ($e ?? []);
            $grille = $brouillon['grille'] ?? ($e ? implode("\n", array_map(fn($c) => $c['libelle'] . ' ' . $c['debut'] . ' ' . $c['fin'], creneaux_de($id))) : '');
            ?>
            <?php if ($e): ?><p class="fil"><a href="./?p=etablissements">← Tous les établissements</a></p><h2><?= h($e['nom']) ?></h2><?php endif; ?>
            <form method="post" class="carte">
                <?= csrf() ?>
                <input type="hidden" name="action" value="enregistrer_etablissement">
                <input type="hidden" name="etablissement_id" value="<?= $id ?>">
                <div class="deux-colonnes">
                    <div>
                        <label>Nom<input name="nom" required maxlength="80" value="<?= h($v['nom'] ?? '') ?>" placeholder="Collège Jean Moulin"></label>
                        <label>Code (3 lettres)
                            <span class="aide">Il apparaît dans les codes de connexion des élèves : 4C-<strong><?= h(($v['code'] ?? '') ?: 'LHA') ?></strong>-9943-kfmz-tqpr.
                                Le changer ne modifie pas les codes déjà distribués.</span>
                            <input name="code" maxlength="3" pattern="[A-Za-z]{3}" class="code-etab" value="<?= h($v['code'] ?? ($e ? code_etablissement($id) : '')) ?>"
                                   placeholder="<?= h(code_etablissement_suggere($v['nom'] ?? '')) ?>"></label>
                        <label>Couleur
                            <span class="aide">Liseré des pastilles de classe et couleur dans l'emploi du temps de l'accueil.</span>
                            <input type="color" name="couleur" value="<?= h(($v['couleur'] ?? '') ?: couleur_etablissement($id ?: (count($liste) + 1))) ?>" class="choix-couleur"></label>
                        <label>Zone de vacances
                            <select name="zone"><?= options_html(['A' => 'Zone A', 'B' => 'Zone B', 'C' => 'Zone C'], $v['zone'] ?? '', 'Non précisée') ?></select></label>
                        <label class="case"><input type="checkbox" name="samedi" value="1" <?= !empty($v['samedi']) ? 'checked' : '' ?>> Cours le samedi</label>
                        <fieldset class="encadre">
                            <legend><label class="case"><input type="checkbox" name="ab_actif" value="1" <?= !empty($v['ab_actif']) ? 'checked' : '' ?>> Semaines A/B</label></legend>
                            <label>Une date de semaine A
                                <span class="aide">N'importe quel jour d'une semaine A connue (ex. la semaine de la rentrée).</span>
                                <input type="date" name="ref_semaine_a" value="<?= h($v['ref_semaine_a'] ?? '') ?>"></label>
                            <label class="case"><input type="checkbox" name="ab_sans_vacances" value="1" <?= ($v['ab_sans_vacances'] ?? 1) ? 'checked' : '' ?>>
                                Les semaines de vacances ne comptent pas (l'alternance reprend après les vacances)</label>
                        </fieldset>
                    </div>
                    <label>Grille horaire, un créneau par ligne
                        <span class="aide">Nom, début, fin. Ex. <code>M1 08:00 08:55</code>. Les cours se placent sur ces créneaux.</span>
                        <textarea name="grille" rows="11" class="mono" placeholder="M1 08:00 08:55
M2 08:55 09:50
M3 10:05 11:00
M4 11:00 11:55
S1 13:30 14:25
S2 14:25 15:20
S3 15:35 16:30
S4 16:30 17:25"><?= h($grille) ?></textarea></label>
                </div>
                <div class="ligne-actions"><button>Enregistrer</button><?php if ($liste): ?><a href="./?p=etablissements">Annuler</a><?php endif; ?></div>
            </form>

            <?php if ($e): section_vacances($e); section_salles($e); section_classes_etab($e); ?>
                <section class="zone-danger">
                    <?= bouton('supprimer_etablissement', 'Supprimer l’établissement', ['etablissement_id' => $id], 'danger',
                        'Supprimer cet établissement, sa grille, ses salles et l’emploi du temps de ses classes ? Les classes et les élèves sont conservés.') ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php
    pied();
}

function section_vacances(array $e): void
{
    $vac = vacances_de((int) $e['id']);
    $annee = annee_scolaire(aujourd_hui());
    [$a1] = explode('-', $annee);
    $suivante = ($a1 + 1) . '-' . ($a1 + 2);
    ?>
    <section id="vacances">
        <h2>Vacances</h2>
        <div class="ligne-actions">
            <?php foreach ([$annee, $suivante] as $an): ?>
                <?= bouton('importer_vacances', "Importer le calendrier officiel $an", ['etablissement_id' => $e['id'], 'annee' => $an], 'secondaire') ?>
            <?php endforeach; ?>
            <span class="discret"><?= $e['zone'] ? 'Zone ' . h($e['zone']) . ' · source : data.education.gouv.fr' : 'Choisissez la zone de l’établissement pour importer le calendrier.' ?></span>
        </div>
        <?php if ($vac): ?>
            <div class="defile" style="margin-top:12px"><table>
                <thead><tr><th>Période</th><th>Du</th><th>Reprise le</th><th>Source</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($vac as $x): ?>
                    <tr>
                        <td><?= h($x['libelle']) ?></td>
                        <td><?= h(date_lisible($x['debut'])) ?> <?= substr($x['debut'], 0, 4) ?></td>
                        <td><?= h(date_lisible($x['fin'])) ?> <?= substr($x['fin'], 0, 4) ?></td>
                        <td class="discret"><?= $x['source'] === 'officiel' ? 'calendrier officiel' : 'saisie' ?></td>
                        <td class="actions"><?= bouton('supprimer_vacances', 'Supprimer', ['etablissement_id' => $e['id'], 'vacances_id' => $x['id']], 'danger') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <form method="post" class="carte formulaire-ligne">
            <?= csrf() ?>
            <input type="hidden" name="action" value="ajouter_vacances">
            <input type="hidden" name="etablissement_id" value="<?= (int) $e['id'] ?>">
            <label>Libellé<input name="libelle" maxlength="80" placeholder="Pont de l’Ascension"></label>
            <label>Premier jour sans cours<input type="date" name="debut" required></label>
            <label>Jour de reprise<input type="date" name="reprise" required></label>
            <button class="secondaire">Ajouter</button>
        </form>
    </section>
    <?php
}

function section_salles(array $e): void
{
    $st = db()->prepare('SELECT * FROM salles WHERE etablissement_id = ? ORDER BY nom');
    $st->execute([$e['id']]);
    $salles = $st->fetchAll();
    ?>
    <section id="salles">
        <h2>Salles et plans</h2>
        <?php if ($salles): ?>
            <div class="grille-classes">
                <?php foreach ($salles as $s): $plan = plan_de($s);
                    $nb = count(array_filter($plan['objets'], fn($o) => in_array($o['type'], TYPES_PLACES, true))); ?>
                    <div class="carte classe">
                        <strong><?= h($s['nom']) ?></strong>
                        <?php $nbo = count(array_filter($plan['objets'], fn($o) => $o['type'] === 'poste')); ?>
                        <span><?= $nb ?> place<?= $nb > 1 ? 's' : '' ?> élève<?= $nb > 1 ? 's' : '' ?><?= $nbo ? ' · ' . $nbo . ' ordinateur' . ($nbo > 1 ? 's' : '') : '' ?></span>
                        <div class="ligne-actions" style="margin-top:8px">
                            <a class="btn petit" href="./?p=salle&id=<?= (int) $s['id'] ?>">Dessiner le plan</a>
                            <?= bouton('supprimer_salle', 'Supprimer', ['etablissement_id' => $e['id'], 'salle_id' => $s['id']], 'danger',
                                'Supprimer cette salle et les plans de classe qui l’utilisent ?') ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="en-ligne" style="margin-top:12px">
            <?= csrf() ?>
            <input type="hidden" name="action" value="ajouter_salle">
            <input type="hidden" name="etablissement_id" value="<?= (int) $e['id'] ?>">
            <input name="nom" required maxlength="40" placeholder="ex. Techno 1" aria-label="Nom de la salle">
            <button>Ajouter une salle</button>
        </form>
    </section>
    <?php
}

function section_classes_etab(array $e): void
{
    $st = db()->prepare('SELECT id, nom FROM classes WHERE etablissement_id = ? ORDER BY nom');
    $st->execute([$e['id']]);
    $classes = $st->fetchAll();
    $libres = db()->query('SELECT id, nom FROM classes WHERE etablissement_id IS NULL ORDER BY nom')->fetchAll();
    ?>
    <section id="classes">
        <h2>Classes</h2>
        <p class="liste-pastilles"><?= $classes ? implode(' ', array_map(fn($c) => '<a href="./?p=classe&id=' . (int) $c['id'] . '" title="' . h($c['nom']) . '">' . pastille_classe((int) $c['id']) . '</a>', $classes))
            : '<span class="vide">Aucune classe rattachée.</span>' ?></p>
        <?php if ($libres): ?>
            <form method="post" class="en-ligne">
                <?= csrf() ?>
                <input type="hidden" name="action" value="classe_etablissement">
                <input type="hidden" name="depuis" value="etablissement">
                <input type="hidden" name="etablissement_id" value="<?= (int) $e['id'] ?>">
                <select name="classe_id" aria-label="Classe à rattacher"><?= options_html(array_column($libres, 'nom', 'id'), '') ?></select>
                <button class="secondaire">Rattacher</button>
            </form>
        <?php endif; ?>
    </section>
    <?php
}

/* ---------- Emploi du temps ---------- */

function page_edt(): void
{
    $pdo = db();
    $etabs = $pdo->query('SELECT * FROM etablissements ORDER BY nom')->fetchAll();
    entete('Emploi du temps');
    barre('edt');
    if (!$etabs) {
        echo '<main><h1>Emploi du temps</h1>';
        messages();
        echo '<p class="vide">Commencez par créer un établissement et sa grille horaire dans l’onglet <a href="./?p=etablissements">Établissements</a>.</p></main>';
        pied();
        return;
    }
    $eid = (int) ($_GET['etab'] ?? $etabs[0]['id']);
    $e = etablissement($eid) ?? $etabs[0];
    $eid = (int) $e['id'];
    $lundi = lundi_de(date_valide((string) ($_GET['semaine'] ?? '')) ? $_GET['semaine'] : aujourd_hui());
    $creneaux = creneaux_de($eid);
    $semaine = semaine_emploi_du_temps($e, $lundi);
    $base = './?p=edt&etab=' . $eid . '&semaine=' . $lundi;
    $index = [];
    foreach ($creneaux as $i => $c) {
        $index[(int) $c['id']] = $i;
    }
    $st = $pdo->prepare('SELECT id, nom FROM salles WHERE etablissement_id = ? ORDER BY nom');
    $st->execute([$eid]);
    $salles = array_column($st->fetchAll(), 'nom', 'id');
    $st = $pdo->prepare('SELECT id, nom FROM classes WHERE etablissement_id = ? ORDER BY nom');
    $st->execute([$eid]);
    $classes = array_column($st->fetchAll(), 'nom', 'id');
    $choisie = null;
    foreach ($semaine['seances'] as $s) {
        if ($s['cle'] === ($_GET['seance'] ?? '')) {
            $choisie = $s;
        }
    }
    // Séances regroupées par case de départ (plusieurs classes au même moment = côte à côte)
    $cases = [];
    foreach ($semaine['seances'] as $s) {
        if (isset($index[$s['creneau_id']])) {
            $cases[$s['date'] . '|' . $s['creneau_id']][] = $s;
        }
    }
    $jours = array_keys($semaine['jours']);
    ?>
    <main class="large">
        <div class="titre-section">
            <h1>Emploi du temps</h1>
            <form method="get" class="en-ligne">
                <input type="hidden" name="p" value="edt">
                <select name="etab" onchange="this.form.submit()" aria-label="Établissement"><?= options_html(array_column($etabs, 'nom', 'id'), $eid) ?></select>
                <input type="date" name="semaine" value="<?= h($lundi) ?>" onchange="this.form.submit()" aria-label="Semaine">
            </form>
        </div>
        <?php messages(); ?>
        <div class="nav-semaine">
            <a class="btn petit secondaire" href="./?p=edt&etab=<?= $eid ?>&semaine=<?= ajouter_jours($lundi, -7) ?>">← Semaine précédente</a>
            <strong>Semaine du <?= h(date_lisible($lundi, false)) ?> <?= substr($lundi, 0, 4) ?><?= $semaine['semaine'] ? ' · <span class="badge-ab">Semaine ' . $semaine['semaine'] . '</span>' : '' ?></strong>
            <a class="btn petit secondaire" href="./?p=edt&etab=<?= $eid ?>">Aujourd'hui</a>
            <a class="btn petit secondaire" href="./?p=edt&etab=<?= $eid ?>&semaine=<?= ajouter_jours($lundi, 7) ?>">Semaine suivante →</a>
        </div>

        <?php if ($choisie) panneau_seance($choisie, $e, $creneaux, $salles, $base); ?>

        <?php if (!$creneaux): ?>
            <p class="vide">La grille horaire de cet établissement est vide : <a href="./?p=etablissements&id=<?= $eid ?>">ajoutez des créneaux</a>.</p>
        <?php else: ?>
            <div class="edt" style="grid-template-columns: 90px repeat(<?= count($jours) ?>, minmax(120px, 1fr)); grid-template-rows: auto repeat(<?= count($creneaux) ?>, minmax(54px, auto));">
                <div class="edt-coin"></div>
                <?php foreach ($jours as $i => $d): $aujourd = $d === aujourd_hui(); ?>
                    <div class="edt-jour<?= $aujourd ? ' aujourdhui' : '' ?>" style="grid-column:<?= $i + 2 ?>"><?= h(JOURS_COURTS[jour_semaine($d)]) ?> <?= h(date_lisible($d, false)) ?></div>
                <?php endforeach; ?>
                <?php foreach ($creneaux as $r => $c): ?>
                    <div class="edt-heure" style="grid-row:<?= $r + 2 ?>"><strong><?= h($c['libelle']) ?></strong><span><?= h($c['debut']) ?>–<?= h($c['fin']) ?></span></div>
                    <?php foreach ($jours as $i => $d): ?><div class="edt-fond" style="grid-row:<?= $r + 2 ?>;grid-column:<?= $i + 2 ?>"></div><?php endforeach; ?>
                <?php endforeach; ?>
                <?php foreach ($jours as $i => $d): if ($semaine['jours'][$d] !== null): ?>
                    <div class="edt-vacances" style="grid-column:<?= $i + 2 ?>;grid-row:2 / span <?= count($creneaux) ?>"><span><?= h($semaine['jours'][$d]) ?></span></div>
                <?php endif; endforeach; ?>
                <?php foreach ($cases as $cle => $groupe):
                    [$d, $cr] = explode('|', $cle);
                    $col = array_search($d, $jours, true);
                    if ($col === false) continue;
                    $duree = max(array_column($groupe, 'duree')); ?>
                    <div class="edt-case" style="grid-column:<?= $col + 2 ?>;grid-row:<?= $index[(int) $cr] + 2 ?> / span <?= min($duree, count($creneaux) - $index[(int) $cr]) ?>">
                        <?php foreach ($groupe as $s): ?>
                            <a class="seance st-<?= h($s['statut']) ?><?= ($choisie['cle'] ?? '') === $s['cle'] ? ' choisie' : '' ?>" href="<?= h($base . '&seance=' . $s['cle']) ?>#panneau"
                               title="<?= h($s['classe'] . ($s['intitule'] ? ' · ' . $s['intitule'] : '') . ($s['salle'] ? ' · ' . $s['salle'] : '') . ($s['note'] ? ' — ' . $s['note'] : '')) ?>">
                                <strong><?= pastille_classe((int) $s['classe_id'], (str_contains($s['classe'], ' · ') ? explode(' · ', $s['classe'], 2)[1] : '')) ?></strong>
                                <?php if ($s['intitule']): ?><span><?= h($s['intitule']) ?></span><?php endif; ?>
                                <?php if ($s['salle']): ?><span class="salle"><?= h($s['salle']) ?></span><?php endif; ?>
                                <?php if ($s['statut'] === 'annule'): ?><em>Annulé<?= $s['note'] ? ' : ' . h($s['note']) : '' ?></em>
                                <?php elseif ($s['statut'] === 'modifie'): ?><em>Modifié<?= $s['date_origine'] !== $s['date'] ? ' (prévu ' . h(date_lisible($s['date_origine'], false)) . ')' : '' ?></em>
                                <?php elseif ($s['statut'] === 'ajoute'): ?><em>Ajouté</em><?php endif; ?>
                                <?php if ($s['note'] && $s['statut'] !== 'annule'): ?><small>📝 <?= h($s['note']) ?></small><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="discret" style="margin-top:8px">Cliquez sur un cours pour l'annuler, le déplacer, changer de salle ou ajouter une note pour ce jour-là.</p>
        <?php endif; ?>

        <?php section_cours_types($e, $creneaux, $salles, $classes, $base); ?>
    </main>
    <?php
    pied();
}

function panneau_seance(array $s, array $e, array $creneaux, array $salles, string $base): void
{
    $retour = $base . '&seance=' . $s['cle'];
    $lib_creneaux = array_column($creneaux, 'libelle', 'id');
    foreach ($creneaux as $c) {
        $lib_creneaux[$c['id']] = $c['libelle'] . ' (' . $c['debut'] . ')';
    }
    $statuts = ['normal' => 'Prévu', 'annule' => 'Annulé', 'modifie' => 'Modifié', 'ajoute' => 'Ajouté ponctuellement'];
    ?>
    <section id="panneau" class="carte panneau-seance">
        <div class="titre-section">
            <h2><?= h($s['classe']) ?><?= $s['intitule'] ? ' · ' . h($s['intitule']) : '' ?> — <?= h(date_lisible($s['date'])) ?>, <?= h($lib_creneaux[$s['creneau_id']] ?? '') ?></h2>
            <a href="<?= h($base) ?>" class="discret">Fermer ✕</a>
        </div>
        <p><span class="pastille-statut st-<?= h($s['statut']) ?>"><?= $statuts[$s['statut']] ?></span>
            <?= $s['salle'] ? ' Salle ' . h($s['salle']) : ' Pas de salle' ?>
            <?= $s['statut'] === 'modifie' && $s['date_origine'] !== $s['date'] ? ' · prévu initialement le ' . h(date_lisible($s['date_origine'])) : '' ?>
            <?= $s['note'] ? ' · 📝 ' . h($s['note']) : '' ?></p>
        <div class="ligne-actions">
            <a class="btn petit secondaire" href="./?p=classe&id=<?= (int) $s['classe_id'] ?>">Page de la classe</a>
            <?php if ($s['salle_id']): ?><a class="btn petit secondaire" href="./?p=placement&classe=<?= (int) $s['classe_id'] ?>&salle=<?= (int) $s['salle_id'] ?><?= !empty($s['groupe_id']) ? '&groupe=' . (int) $s['groupe_id'] : '' ?>">Plan de classe</a><?php endif; ?>
            <?php if ($s['statut'] === 'normal'): ?>
                <form method="post" class="en-ligne">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="seance_annuler">
                    <input type="hidden" name="retour" value="<?= h($retour) ?>">
                    <input type="hidden" name="cours_id" value="<?= (int) $s['cours_id'] ?>">
                    <input type="hidden" name="date_origine" value="<?= h($s['date']) ?>">
                    <input name="note" maxlength="200" placeholder="Motif (sortie, absence…)" aria-label="Motif de l'annulation">
                    <button class="petit danger">Annuler ce cours</button>
                </form>
            <?php elseif ($s['statut'] === 'ajoute'): ?>
                <?= bouton('seance_retablir', 'Supprimer ce cours ajouté', ['modif_id' => $s['modif_id'], 'retour' => $retour], 'danger', 'Supprimer ce cours ajouté ?') ?>
            <?php else: ?>
                <?= bouton('seance_retablir', 'Rétablir le cours prévu', ['modif_id' => $s['modif_id'], 'retour' => $retour], 'secondaire') ?>
            <?php endif; ?>
        </div>
        <?php if ($s['statut'] !== 'annule'): ?>
            <details <?= $s['statut'] !== 'normal' ? 'open' : '' ?>>
                <summary><?= $s['statut'] === 'normal' ? 'Modifier ce cours-là seulement (date, heure, salle, note)' : 'Modifier' ?></summary>
                <form method="post" class="formulaire-ligne">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="seance_modifier">
                    <input type="hidden" name="retour" value="<?= h($retour) ?>">
                    <input type="hidden" name="modif_id" value="<?= (int) $s['modif_id'] ?>">
                    <input type="hidden" name="cours_id" value="<?= (int) $s['cours_id'] ?>">
                    <input type="hidden" name="date_origine" value="<?= h($s['date_origine']) ?>">
                    <label>Date<input type="date" name="date" value="<?= h($s['date']) ?>" required></label>
                    <label>Créneau<select name="creneau_id"><?= options_html($lib_creneaux, $s['creneau_id']) ?></select></label>
                    <label>Durée<select name="duree"><?= options_html([1 => '1 créneau', 2 => '2 créneaux', 3 => '3 créneaux', 4 => '4 créneaux'], $s['duree']) ?></select></label>
                    <label>Salle<select name="salle_id"><?= options_html($salles, $s['salle_id'] ?? '', '—') ?></select></label>
                    <label>Intitulé<input name="intitule" maxlength="60" value="<?= h($s['intitule']) ?>"></label>
                    <label class="large">Note<input name="note" maxlength="200" value="<?= h($s['statut'] === 'normal' ? '' : $s['note']) ?>" placeholder="Évaluation, apporter les clés USB…"></label>
                    <button>Enregistrer</button>
                </form>
            </details>
        <?php endif; ?>
    </section>
    <?php
}

function section_cours_types(array $e, array $creneaux, array $salles, array $classes, string $base): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT co.*, cl.nom || COALESCE(\' · \' || g.nom, \'\') AS classe, cr.libelle AS creneau, cr.debut AS heure, s.nom AS salle
                         FROM cours co JOIN classes cl ON cl.id = co.classe_id JOIN creneaux cr ON cr.id = co.creneau_id
                         LEFT JOIN salles s ON s.id = co.salle_id LEFT JOIN groupes g ON g.id = co.groupe_id
                         WHERE cl.etablissement_id = ? ORDER BY co.jour, cr.ordre, cl.nom');
    $st->execute([$e['id']]);
    $cours = $st->fetchAll();
    $modifier = (int) ($_GET['cours'] ?? 0);
    $courant = ['id' => 0, 'classe_id' => '', 'groupe_id' => null, 'jour' => 1, 'creneau_id' => '', 'duree' => 1, 'semaine' => '', 'salle_id' => '', 'intitule' => 'Technologie', 'debut' => '', 'fin' => ''];
    foreach ($cours as $c) {
        if ((int) $c['id'] === $modifier) {
            $courant = $c;
        }
    }
    $lib_creneaux = [];
    foreach ($creneaux as $c) {
        $lib_creneaux[$c['id']] = $c['libelle'] . ' (' . $c['debut'] . ')';
    }
    $jours = $e['samedi'] ? JOURS : array_slice(JOURS, 0, 5, true);
    $semaines = $e['ab_actif'] ? ['' => 'Toutes les semaines', 'A' => 'Semaine A', 'B' => 'Semaine B'] : ['' => 'Toutes les semaines'];
    ?>
    <section id="cours">
        <h2>Semaine type</h2>
        <p class="aide">Les cours qui reviennent chaque semaine (ou une semaine sur deux). Une modification ici vaut pour toutes les semaines ;
            pour un seul jour, cliquez sur le cours dans la grille.</p>
        <?php if (!$classes): ?>
            <p class="vide">Aucune classe rattachée à cet établissement. Rattachez-en une depuis la page de la classe ou la page de l’établissement.</p>
        <?php else: ?>
            <?php if ($cours): ?>
                <div class="defile"><table>
                    <thead><tr><th>Jour</th><th>Créneau</th><th>Classe</th><th>Intitulé</th><th>Salle</th><th>Semaines</th><th>Période</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($cours as $c): ?>
                        <tr<?= (int) $c['id'] === $modifier ? ' class="choisie"' : '' ?>>
                            <td><?= h(JOURS[(int) $c['jour']]) ?></td>
                            <td><?= h($c['creneau']) ?> <span class="discret"><?= h($c['heure']) ?><?= $c['duree'] > 1 ? ' · ' . (int) $c['duree'] . ' créneaux' : '' ?></span></td>
                            <td><strong><?= h($c['classe']) ?></strong></td>
                            <td><?= h($c['intitule']) ?></td>
                            <td><?= h($c['salle'] ?? '—') ?></td>
                            <td><?= $c['semaine'] ? 'Semaine ' . h($c['semaine']) : 'Toutes' ?></td>
                            <td class="discret"><?= $c['debut'] || $c['fin'] ? h(($c['debut'] ? 'du ' . date_lisible($c['debut'], false) : '') . ($c['fin'] ? ' au ' . date_lisible($c['fin'], false) : '')) : 'toute l’année' ?></td>
                            <td class="actions">
                                <a class="btn petit secondaire" href="<?= h($base . '&cours=' . $c['id']) ?>#form-cours">Modifier</a>
                                <?= bouton('supprimer_cours', 'Retirer', ['cours_id' => $c['id'], 'retour' => $base . '#cours'], 'danger', 'Retirer ce cours de toutes les semaines ?') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
            <form method="post" class="carte formulaire-ligne" id="form-cours">
                <?= csrf() ?>
                <input type="hidden" name="action" value="enregistrer_cours">
                <input type="hidden" name="cours_id" value="<?= (int) $courant['id'] ?>">
                <input type="hidden" name="retour" value="<?= h($base . '#cours') ?>">
                <label>Classe ou groupe<select name="classe_id" required><?= options_classes_groupes($classes, (string) $courant['classe_id'] . (!empty($courant['groupe_id']) ? ':' . $courant['groupe_id'] : '')) ?></select></label>
                <label>Jour<select name="jour"><?= options_html($jours, $courant['jour']) ?></select></label>
                <label>Créneau<select name="creneau_id" required><?= options_html($lib_creneaux, $courant['creneau_id'], '—') ?></select></label>
                <label>Durée<select name="duree"><?= options_html([1 => '1 créneau', 2 => '2 créneaux', 3 => '3 créneaux', 4 => '4 créneaux'], $courant['duree']) ?></select></label>
                <label>Semaines<select name="semaine"><?= options_html($semaines, $courant['semaine']) ?></select></label>
                <label>Salle<select name="salle_id"><?= options_html($salles, $courant['salle_id'] ?? '', '—') ?></select></label>
                <label>Intitulé<input name="intitule" maxlength="60" value="<?= h($courant['intitule']) ?>" placeholder="Technologie, groupe 1…"></label>
                <label>À partir du<input type="date" name="debut" value="<?= h($courant['debut']) ?>"></label>
                <label>Jusqu’au<input type="date" name="fin" value="<?= h($courant['fin']) ?>"></label>
                <button><?= $courant['id'] ? 'Enregistrer les modifications' : 'Ajouter ce cours' ?></button>
                <?php if ($courant['id']): ?><a href="<?= h($base) ?>#cours">Annuler</a><?php endif; ?>
            </form>
            <details class="carte">
                <summary>Ajouter un cours ponctuel (rattrapage, séance supplémentaire…)</summary>
                <form method="post" class="formulaire-ligne">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="seance_ajouter">
                    <input type="hidden" name="retour" value="<?= h($base) ?>">
                    <label>Classe ou groupe<select name="classe_id" required><?= options_classes_groupes($classes) ?></select></label>
                    <label>Date<input type="date" name="date" required></label>
                    <label>Créneau<select name="creneau_id" required><?= options_html($lib_creneaux, '', '—') ?></select></label>
                    <label>Durée<select name="duree"><?= options_html([1 => '1 créneau', 2 => '2 créneaux', 3 => '3 créneaux', 4 => '4 créneaux'], 1) ?></select></label>
                    <label>Salle<select name="salle_id"><?= options_html($salles, '', '—') ?></select></label>
                    <label>Intitulé<input name="intitule" maxlength="60" value="Technologie"></label>
                    <label class="large">Note<input name="note" maxlength="200" placeholder="Rattrapage…"></label>
                    <button>Ajouter</button>
                </form>
            </details>
        <?php endif; ?>
    </section>
    <?php
}

/** Bloc « Aujourd'hui » du tableau de bord. */
function section_aujourdhui(): void
{
    if (!db()->query('SELECT COUNT(*) FROM etablissements')->fetchColumn()) {
        return;
    }
    $date = aujourd_hui();
    $seances = seances_du_jour($date);
    ?>
    <section>
        <div class="titre-section">
            <h2>Aujourd'hui, <?= h(date_lisible($date)) ?></h2>
            <a class="btn petit secondaire" href="./?p=edt">Emploi du temps</a>
        </div>
        <?php if (!$seances): ?>
            <p class="vide">Pas de cours aujourd'hui.</p>
        <?php else: ?>
            <div class="defile"><table>
                <tbody>
                <?php foreach ($seances as $s): ?>
                    <tr class="<?= $s['statut'] === 'annule' ? 'inactif' : '' ?>">
                        <td class="numero"><?= h($s['debut']) ?>–<?= h($s['fin']) ?></td>
                        <td><?= pastille_classe((int) $s['classe_id'], (str_contains($s['classe'], ' · ') ? explode(' · ', $s['classe'], 2)[1] : '')) ?> <?= h($s['intitule']) ?>
                            <?php if ($s['statut'] === 'annule'): ?><span class="alerte"> · annulé<?= $s['note'] ? ' (' . h($s['note']) . ')' : '' ?></span><?php endif; ?>
                            <?php if ($s['statut'] === 'modifie'): ?><span class="alerte"> · modifié</span><?php endif; ?>
                            <?php if ($s['note'] && $s['statut'] !== 'annule'): ?><br><span class="discret">📝 <?= h($s['note']) ?></span><?php endif; ?></td>
                        <td><?= h($s['salle'] ?: '—') ?> <span class="discret">· <?= h($s['etablissement']) ?></span></td>
                        <td class="actions">
                            <?php if ($s['salle_id'] && $s['statut'] !== 'annule'): ?><a class="btn petit secondaire" href="./?p=placement&classe=<?= (int) $s['classe_id'] ?>&salle=<?= (int) $s['salle_id'] ?><?= !empty($s['groupe_id']) ? '&groupe=' . (int) $s['groupe_id'] : '' ?>">Plan de classe</a><?php endif; ?>
                            <a class="btn petit secondaire" href="./?p=edt&etab=<?= (int) $s['etablissement_id'] ?>&semaine=<?= lundi_de($date) ?>&seance=<?= h($s['cle']) ?>#panneau">Modifier</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <?php
}

/** Bloc « Organisation » de la page d'une classe : établissement, plans de classe. */
function section_organisation_classe(array $classe): void
{
    $etabs = db()->query('SELECT id, nom FROM etablissements ORDER BY nom')->fetchAll();
    $salles = [];
    if ($classe['etablissement_id']) {
        $st = db()->prepare('SELECT s.id, s.nom, (SELECT COUNT(*) FROM placements p WHERE p.salle_id = s.id AND p.classe_id = ? AND p.groupe_id = 0) AS places
                             FROM salles s WHERE s.etablissement_id = ? ORDER BY s.nom');
        $st->execute([$classe['id'], $classe['etablissement_id']]);
        $salles = $st->fetchAll();
    }
    ?>
    <section id="organisation">
        <h2>Établissement et plans de classe</h2>
        <div class="ligne-actions">
            <form method="post" class="en-ligne">
                <?= csrf() ?>
                <input type="hidden" name="action" value="classe_etablissement">
                <input type="hidden" name="classe_id" value="<?= (int) $classe['id'] ?>">
                <select name="etablissement_id" aria-label="Établissement"><?= options_html(array_column($etabs, 'nom', 'id'), $classe['etablissement_id'] ?? '', 'Aucun établissement') ?></select>
                <button class="secondaire">Enregistrer</button>
            </form>
            <?php if (!$etabs): ?><a href="./?p=etablissements&nouveau=1">Créer un établissement</a><?php endif; ?>
        </div>
        <?php if ($salles): ?>
            <p style="margin-top:12px">
                <?php foreach ($salles as $s): ?>
                    <a class="btn petit secondaire" href="./?p=placement&classe=<?= (int) $classe['id'] ?>&salle=<?= (int) $s['id'] ?>">Plan en <?= h($s['nom']) ?><?= $s['places'] ? ' (' . (int) $s['places'] . ' placés)' : '' ?></a>
                    <?php foreach (groupes_de((int) $classe['id']) as $g): ?>
                        <a class="btn petit secondaire" href="./?p=placement&classe=<?= (int) $classe['id'] ?>&salle=<?= (int) $s['id'] ?>&groupe=<?= (int) $g['id'] ?>"><?= h($g['nom']) ?> en <?= h($s['nom']) ?></a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </p>
        <?php elseif ($classe['etablissement_id']): ?>
            <p class="vide">Aucune salle dans cet établissement : <a href="./?p=etablissements&id=<?= (int) $classe['etablissement_id'] ?>#salles">en ajouter une</a>.</p>
        <?php endif; ?>
    </section>
    <?php
}

/* ---------- Éditeur de salle et placement ---------- */

function page_salle(int $id): void
{
    $st = db()->prepare('SELECT s.*, e.nom AS etablissement FROM salles s JOIN etablissements e ON e.id = s.etablissement_id WHERE s.id = ?');
    $st->execute([$id]);
    $salle = $st->fetch();
    if (!$salle) {
        rediriger('./?p=etablissements');
    }
    entete('Salle ' . $salle['nom']);
    barre('etablissements');
    ?>
    <main class="large">
        <p class="fil"><a href="./?p=etablissements&id=<?= (int) $salle['etablissement_id'] ?>#salles">← <?= h($salle['etablissement']) ?></a></p>
        <h1>Plan de la salle <?= h($salle['nom']) ?></h1>
        <?php messages(); ?>
        <form method="post" id="form-plan">
            <?= csrf() ?>
            <input type="hidden" name="action" value="enregistrer_plan">
            <input type="hidden" name="salle_id" value="<?= (int) $salle['id'] ?>">
            <input type="hidden" name="plan" id="champ-plan">
            <div class="editeur">
                <aside class="palette carte">
                    <label>Nom de la salle<input name="nom" value="<?= h($salle['nom']) ?>" maxlength="40"></label>
                    <div class="taille-salle">
                        <label>Largeur<input type="number" id="largeur" min="4" max="60" step="0.5"></label>
                        <label>Profondeur<input type="number" id="hauteur" min="4" max="60" step="0.5"></label>
                    </div>
                    <p class="aide">1 carreau ≈ 50 cm. Cliquez pour ajouter, glissez pour déplacer.</p>
                    <div class="boutons-palette" id="palette"></div>
                    <details>
                        <summary>Disposition rapide</summary>
                        <div class="taille-salle">
                            <label>Rangées<input type="number" id="r-rangees" min="1" max="12" value="4"></label>
                            <label>Tables/rangée<input type="number" id="r-tables" min="1" max="8" value="3"></label>
                            <label>Places/table<input type="number" id="r-places" min="1" max="4" value="2"></label>
                        </div>
                        <label>Ordinateurs<select id="r-postes">
                            <option value="0">Aucun</option><option value="1">Un par place</option><option value="0.5">Un pour deux places</option></select></label>
                        <button type="button" class="secondaire petit" id="r-generer">Générer (remplace le plan)</button>
                    </details>
                    <fieldset class="vue-salle">
                        <legend>Affichage du plan</legend>
                        <label>Sol<select id="sol">
                            <option value="uni">Uni</option><option value="bois">Bois</option><option value="parquet">Parquet</option></select></label>
                        <label><input type="checkbox" id="vue-iso"> Vue 3/4 (isométrique)</label>
                        <label class="curseur-angle">Angle de la vue 3/4 <span id="vue-angle-valeur">0°</span>
                            <input type="range" id="vue-angle" min="0" max="359" step="1" value="0"></label>
                        <label><input type="checkbox" id="vue-portrait"> Portrait (salle en hauteur)</label>
                        <label><input type="checkbox" id="vue-miroir-x"> Miroir horizontal</label>
                        <label><input type="checkbox" id="vue-miroir-y"> Miroir vertical</label>
                        <p class="aide">Appliqué au plan de placement, à l'aperçu et à l'impression. Cet éditeur reste dans le sens réel de la salle.</p>
                    </fieldset>
                </aside>
                <div class="zone-plan carte">
                    <div class="outils-objet" id="outils" hidden>
                        <strong id="outil-titre"></strong>
                        <label>Étiquette<input id="outil-label" maxlength="20"></label>
                        <button type="button" class="petit secondaire" data-outil="rotation" title="Tourner (R)">⟳ Tourner</button>
                        <button type="button" class="petit secondaire" data-outil="largeur-" title="Plus étroit">↔−</button>
                        <button type="button" class="petit secondaire" data-outil="largeur+" title="Plus large">↔+</button>
                        <button type="button" class="petit secondaire" data-outil="hauteur-" title="Moins profond">↕−</button>
                        <button type="button" class="petit secondaire" data-outil="hauteur+" title="Plus profond">↕+</button>
                        <button type="button" class="petit secondaire" data-outil="dupliquer" title="Dupliquer (D)">Dupliquer</button>
                        <button type="button" class="petit danger" data-outil="supprimer" title="Supprimer (Suppr)">Supprimer</button>
                    </div>
                    <svg id="plan" class="plan-svg" xmlns="http://www.w3.org/2000/svg"></svg>
                    <div class="ligne-actions" style="margin-top:12px">
                        <button>Enregistrer le plan</button>
                        <span class="discret" id="compte-places"></span>
                    </div>
                </div>
            </div>
        </form>
        <script type="application/json" id="donnees-plan"><?= json_encode(plan_de($salle), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <script src="plan.js?v=<?= @filemtime(__DIR__ . '/../public/admin/plan.js') ?: 1 ?>"></script>
        <script>PlanSalle.editeur();</script>
    </main>
    <?php
    pied();
}

/** Vrai si la salle appartient à l'établissement de la classe : une classe ne se place que dans les salles de son collège. */
function salle_compatible(PDO $pdo, int $classe_id, int $salle_id): bool
{
    $st = $pdo->prepare('SELECT 1 FROM classes c JOIN salles s ON s.etablissement_id = c.etablissement_id WHERE c.id = ? AND s.id = ?');
    $st->execute([$classe_id, $salle_id]);
    return (bool) $st->fetchColumn();
}

/** Salle à utiliser pour une classe : la salle demandée si elle convient, sinon la salle de son établissement où la classe a déjà un plan, sinon la première. Null si l'établissement n'a aucune salle. */
function salle_pour_classe(PDO $pdo, int $classe_id, int $salle_id): ?int
{
    if (salle_compatible($pdo, $classe_id, $salle_id)) {
        return $salle_id;
    }
    $st = $pdo->prepare('SELECT s.id FROM salles s JOIN classes c ON c.etablissement_id = s.etablissement_id
        WHERE c.id = ? ORDER BY (SELECT COUNT(*) FROM placements p WHERE p.classe_id = c.id AND p.salle_id = s.id) DESC, s.nom LIMIT 1');
    $st->execute([$classe_id]);
    $id = $st->fetchColumn();
    return $id ? (int) $id : null;
}

/** Lien vers le plan d'une classe, dans une salle qui lui convient (sinon sa fiche, si son établissement n'a pas de salle). */
function lien_placement_classe(int $classe_id, int $salle_id): string
{
    $sid = salle_pour_classe(db(), $classe_id, $salle_id);
    return $sid ? './?p=placement&classe=' . $classe_id . '&salle=' . $sid : './?p=classe&id=' . $classe_id . '#organisation';
}

function page_placement(int $classe_id, int $salle_id, int $groupe_id = 0): void
{
    $classe = classe_par_id($classe_id);
    $groupes = groupes_de($classe_id);
    $groupe = null;
    foreach ($groupes as $g) {
        if ((int) $g['id'] === $groupe_id) {
            $groupe = $g;
        }
    }
    $groupe_id = $groupe ? $groupe_id : 0;
    $titre = ($classe['nom'] ?? '') . ($groupe ? ' · ' . $groupe['nom'] : '');
    $st = db()->prepare('SELECT * FROM salles WHERE id = ?');
    $st->execute([$salle_id]);
    $salle = $st->fetch();
    if (!$classe || !$salle) {
        rediriger('./');
    }
    if (!salle_compatible(db(), $classe_id, $salle_id)) {
        $sid = salle_pour_classe(db(), $classe_id, $salle_id);
        flash('Salle changée : celle de l’établissement de la classe.');
        rediriger($sid ? './?p=placement&classe=' . $classe_id . '&salle=' . $sid : './?p=classe&id=' . $classe_id . '#organisation');
    }
    $st = db()->prepare("SELECT id, etiquette, actif FROM eleves WHERE classe_id = ?" . ($groupe_id ? ' AND groupe_id = ?' : '') . "
        ORDER BY CASE WHEN etiquette GLOB '[0-9]*' THEN CAST(etiquette AS INTEGER) ELSE 9999 END, etiquette");
    $st->execute($groupe_id ? [$classe_id, $groupe_id] : [$classe_id]);
    $eleves = $st->fetchAll();
    $st = db()->prepare('SELECT place, eleve_id FROM placements WHERE classe_id = ? AND groupe_id = ? AND salle_id = ?');
    $st->execute([$classe_id, $groupe_id, $salle_id]);
    $placement = array_map('intval', array_column($st->fetchAll(), 'eleve_id', 'place'));
    // barre des classes : précédente, suivante, et une pastille par classe (la classe affichée est soulignée)
    // classées par établissement (les classes sans établissement en dernier), puis par nom : la barre se lit par collège
    $toutes = db()->query('SELECT c.id, c.nom, c.etablissement_id, e.nom AS college FROM classes c LEFT JOIN etablissements e ON e.id = c.etablissement_id
        ORDER BY (e.nom IS NULL), e.nom, c.nom')->fetchAll();
    $position = array_search($classe_id, array_map('intval', array_column($toutes, 'id')), true);
    $precedente = $position > 0 ? $toutes[$position - 1] : null;
    $suivante = ($position !== false && $position < count($toutes) - 1) ? $toutes[$position + 1] : null;
    $autres = db()->prepare('SELECT id, nom FROM salles WHERE etablissement_id = ? AND id <> ? ORDER BY nom');
    $autres->execute([$salle['etablissement_id'], $salle_id]);
    $donnees = [
        'plan' => plan_de($salle),
        'classe' => $classe['nom'],
        'eleves' => array_map(fn($e) => ['id' => (int) $e['id'], 'numero' => $e['etiquette'], 'actif' => (bool) $e['actif']], $eleves),
        'placement' => (object) $placement,
    ];
    // versions du plan de cette classe, cette salle et ce groupe (la plus récente d'abord)
    $st = db()->prepare('SELECT id, nom, cree_le, carte FROM plans_versions WHERE classe_id = ? AND salle_id = ? AND groupe_id = ? ORDER BY id DESC');
    $st->execute([$classe_id, $salle_id, $groupe_id]);
    $versions = $st->fetchAll();
    // version appliquée : celle dont le placement est identique au plan affiché (la plus récente d'abord)
    $normaliser = function (array $c): array { $c = array_map('intval', $c); ksort($c); return $c; };
    $actuel = $normaliser($placement);
    $version_appliquee = null;
    foreach ($versions as $v) {
        if ($normaliser(json_decode((string) $v['carte'], true) ?: []) === $actuel) {
            $version_appliquee = (int) $v['id'];
            break;
        }
    }
    $nom_applique = null;
    foreach ($versions as $v) {
        if ((int) $v['id'] === $version_appliquee) {
            $nom_applique = $v['nom'];
        }
    }
    entete('Plan de classe ' . $titre);
    barre('classes');
    ?>
    <main class="large">
        <p class="fil"><a href="./?p=classe&id=<?= $classe_id ?>#organisation">← Classe <?= h($classe['nom']) ?></a></p>
        <div class="titre-section">
            <h1>Plan de classe · <?= h($titre) ?> en <?= h($salle['nom']) ?></h1>
            <div class="ligne-actions">
                <?php foreach ($autres->fetchAll() as $a): ?><a class="btn petit secondaire" href="./?p=placement&classe=<?= $classe_id ?>&salle=<?= (int) $a['id'] ?><?= $groupe_id ? '&groupe=' . $groupe_id : '' ?>"><?= h($a['nom']) ?></a><?php endforeach; ?>
                <a class="btn petit secondaire" href="./?p=salle&id=<?= $salle_id ?>">Modifier la salle</a>
            </div>
        </div>
        <nav class="classes-rail" aria-label="Changer de classe">
            <?php if ($precedente): ?>
                <a class="btn petit secondaire" href="<?= h(lien_placement_classe((int) $precedente['id'], $salle_id)) ?>" title="Classe précédente : <?= h($precedente['nom']) ?>" aria-label="Classe précédente : <?= h($precedente['nom']) ?>">‹</a>
            <?php endif; ?>
            <?php $college_precedent = null; ?>
            <?php foreach ($toutes as $c): ?>
                <?php $college = (string) ($c['college'] ?? ''); ?>
                <?php if ($college !== $college_precedent): ?>
                    <?php if ($college_precedent !== null): ?><span class="rail-separateur" aria-hidden="true"></span><?php endif; ?>
                    <span class="rail-college"><?= h($college !== '' ? $college : 'Sans établissement') ?></span>
                    <?php $college_precedent = $college; ?>
                <?php endif; ?>
                <a class="classe-lien<?= (int) $c['id'] === $classe_id ? ' actuelle' : '' ?>" href="<?= h(lien_placement_classe((int) $c['id'], $salle_id)) ?>"<?= (int) $c['id'] === $classe_id ? ' aria-current="page"' : '' ?>><?= pastille_classe((int) $c['id']) ?></a>
            <?php endforeach; ?>
            <?php if ($suivante): ?>
                <a class="btn petit secondaire" href="<?= h(lien_placement_classe((int) $suivante['id'], $salle_id)) ?>" title="Classe suivante : <?= h($suivante['nom']) ?>" aria-label="Classe suivante : <?= h($suivante['nom']) ?>">›</a>
            <?php endif; ?>
        </nav>
        <?php if ($groupes): ?>
            <nav class="choix-groupe" aria-label="Classe entière ou groupe">
                <a class="btn petit <?= $groupe_id ? 'secondaire' : '' ?>" href="./?p=placement&classe=<?= $classe_id ?>&salle=<?= $salle_id ?>">Classe entière</a>
                <?php foreach ($groupes as $g): ?>
                    <a class="btn petit <?= (int) $g['id'] === $groupe_id ? '' : 'secondaire' ?>" href="./?p=placement&classe=<?= $classe_id ?>&salle=<?= $salle_id ?>&groupe=<?= (int) $g['id'] ?>"><?= h($g['nom']) ?> (<?= (int) $g['nb'] ?>)</a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <?php messages(); ?>
        <?php liste_locale(); ?>
        <div id="barre-vue"></div>
        <section class="versions-plan carte" aria-label="Versions du plan">
            <p class="aide">Versions : gardez plusieurs plans (début d’année, Toussaint, atelier…) et revenez à l’un d’eux.
                « Enregistrer comme version » garde le plan affiché (le premier enregistrement garde aussi le plan initial) ; « Charger » remplace le plan en cours (enregistrez d’abord vos changements).</p>
            <div class="versions-ligne">
                <form method="post" class="versions-form" id="form-version">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="enregistrer_version">
                    <input type="hidden" name="classe_id" value="<?= $classe_id ?>">
                    <input type="hidden" name="salle_id" value="<?= $salle_id ?>">
                    <input type="hidden" name="groupe_id" value="<?= $groupe_id ?>">
                    <input type="hidden" name="placement" id="champ-version">
                    <input type="text" name="nom_version" maxlength="60" required placeholder="Nom (ex. Toussaint, AT-xyz)" aria-label="Nom de la version">
                    <button type="submit" class="petit">Enregistrer comme version</button>
                </form>
                <?php if ($versions): ?>
                    <p class="versions-applique aide">Plan appliqué : <?= $nom_applique !== null ? '<strong>' . h($nom_applique) . '</strong>' : ($actuel ? 'plan modifié, aucune version identique' : 'aucun élève placé') ?></p>
                    <form method="post" class="versions-form" onsubmit="return confirm('Remplacer le plan en cours par cette version ?');">
                        <?= csrf() ?>
                        <input type="hidden" name="action" value="charger_version">
                        <input type="hidden" name="classe_id" value="<?= $classe_id ?>">
                        <input type="hidden" name="salle_id" value="<?= $salle_id ?>">
                        <input type="hidden" name="groupe_id" value="<?= $groupe_id ?>">
                        <select name="version_id" aria-label="Version à charger">
                            <?php foreach ($versions as $v): ?>
                                <option value="<?= (int) $v['id'] ?>"><?= (int) $v['id'] === $version_appliquee ? '✓ ' : '' ?><?= h($v['nom']) ?> · <?= h($v['cree_le']) ?> (<?= count(json_decode((string) $v['carte'], true) ?: []) ?> élèves)</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="petit secondaire">Charger</button>
                    </form>
                    <form method="post" class="versions-form" onsubmit="return confirm('Supprimer cette version du plan ?');">
                        <?= csrf() ?>
                        <input type="hidden" name="action" value="supprimer_version">
                        <input type="hidden" name="classe_id" value="<?= $classe_id ?>">
                        <input type="hidden" name="salle_id" value="<?= $salle_id ?>">
                        <input type="hidden" name="groupe_id" value="<?= $groupe_id ?>">
                        <select name="version_id" aria-label="Version à supprimer">
                            <?php foreach ($versions as $v): ?>
                                <option value="<?= (int) $v['id'] ?>"><?= h($v['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="petit secondaire">Supprimer</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <form method="post" id="form-placement">
            <?= csrf() ?>
            <input type="hidden" name="action" value="enregistrer_placement">
            <input type="hidden" name="classe_id" value="<?= $classe_id ?>">
            <input type="hidden" name="salle_id" value="<?= $salle_id ?>">
            <input type="hidden" name="groupe_id" value="<?= $groupe_id ?>">
            <input type="hidden" name="placement" id="champ-placement">
            <div class="editeur">
                <aside class="palette carte">
                    <p class="aide">Cliquez sur un élève puis sur une place (ou glissez-le). Cliquez sur une place occupée pour la libérer.</p>
                    <ul class="liste-eleves" id="liste-eleves"></ul>
                    <div class="ligne-actions">
                        <button type="button" class="petit secondaire" id="p-aleatoire">Placement aléatoire</button>
                        <button type="button" class="petit secondaire" id="p-vider">Tout vider</button>
                    </div>
                </aside>
                <div class="zone-plan carte">
                    <svg id="plan" class="plan-svg" xmlns="http://www.w3.org/2000/svg"></svg>
                    <div class="ligne-actions" style="margin-top:12px">
                        <button>Enregistrer le plan de classe</button>
                        <button type="button" class="secondaire" onclick="window.print()">Imprimer</button>
                        <span class="discret" id="compte-places"></span>
                    </div>
                </div>
            </div>
        </form>
        <div class="impression-titre">Plan de classe · <?= h($titre) ?> · salle <?= h($salle['nom']) ?> · <?= h(date_lisible(aujourd_hui())) ?></div>
        <script type="application/json" id="donnees-plan"><?= json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <script src="plan.js?v=<?= @filemtime(__DIR__ . '/../public/admin/plan.js') ?: 1 ?>"></script>
        <script>PlanSalle.placement();</script>
    </main>
    <?php
    pied();
}
