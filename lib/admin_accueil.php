<?php
/**
 * Centrale élèves — accueil de l'espace enseignant :
 * la semaine de cours de tous les établissements (une couleur par établissement), les prochains cours,
 * et le récapitulatif des connexions des élèves.
 */
declare(strict_types=1);

/** Toutes les séances d'une semaine, tous établissements confondus, avec leurs heures réelles. */
function seances_semaine_tous(string $lundi): array
{
    $res = [];
    $vacances = [];
    $samedi = false;
    foreach (db()->query('SELECT * FROM etablissements ORDER BY nom')->fetchAll() as $e) {
        $samedi = $samedi || (bool) $e['samedi'];
        $creneaux = creneaux_de((int) $e['id']);
        $index = [];
        foreach ($creneaux as $i => $c) {
            $index[(int) $c['id']] = $i;
        }
        $semaine = semaine_emploi_du_temps($e, $lundi);
        foreach ($semaine['jours'] as $d => $vac) {
            if ($vac !== null) {
                $vacances[$d][(int) $e['id']] = $vac;
            }
        }
        foreach ($semaine['seances'] as $s) {
            if (!isset($index[$s['creneau_id']])) {
                continue;
            }
            $i = $index[$s['creneau_id']];
            $fin = $creneaux[min(count($creneaux) - 1, $i + max(1, $s['duree']) - 1)]['fin'];
            $res[] = $s + ['etablissement_id' => (int) $e['id'], 'etablissement' => $e['nom'], 'semaine_ab' => $semaine['semaine'],
                           'debut' => $creneaux[$i]['debut'], 'fin' => $fin, 'creneau' => $creneaux[$i]['libelle']];
        }
    }
    usort($res, fn($a, $b) => strcmp($a['date'] . $a['debut'], $b['date'] . $b['debut']));
    return ['seances' => $res, 'vacances' => $vacances, 'samedi' => $samedi];
}

function minutes(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm . ':0'));
    return $h * 60 + $m;
}

/** Libellé de groupe d'une séance (« 4C-HAUTIERS · GR1 » → « GR1 »). */
function groupe_seance(array $s): string
{
    return str_contains($s['classe'], ' · ') ? explode(' · ', $s['classe'], 2)[1] : '';
}

/** Liste « élèves à valider » d'une classe (réutilisée par « En cours » réel et simulé). */
/** Trombinoscope d'une classe en cours, sur l'accueil : photo, nom, et « Mot de passe » (la fiche s'ouvre au clic). */
function trombi_accueil(int $classe_id): void
{
    $classe = classe_par_id($classe_id);
    if (!$classe) {
        return;
    }
    $st = db()->prepare("SELECT etiquette, surnom FROM eleves WHERE classe_id = ? AND actif = 1
        ORDER BY CASE WHEN etiquette GLOB '[0-9]*' THEN CAST(etiquette AS INTEGER) ELSE 9999 END, etiquette");
    $st->execute([$classe_id]);
    $eleves = array_filter($st->fetchAll(), fn($e) => est_pseudonyme($e['etiquette']));
    if (!$eleves) {
        return;
    }
    $nom = $classe['nom'];
    $prefixe = prefixe_codes($classe);
    ?>
    <div class="ec-trombi">
        <div class="ec-sous-titre">Trombinoscope · <?= h($nom) ?></div>
        <ul class="trombi trombi-accueil">
            <?php foreach ($eleves as $e): ?>
                <li data-numero="<?= h($e['etiquette']) ?>">
                    <div class="trombi-photo photo-accueil sans-photo" data-classe="<?= h($nom) ?>" data-numero="<?= h($e['etiquette']) ?>"></div>
                    <div class="trombi-nom"><span class="numero"><?= h($e['etiquette']) ?></span> <?= nom_eleve($nom, $e['etiquette']) ?></div>
                    <span class="surnom" data-classe="<?= h($nom) ?>" data-numero="<?= h($e['etiquette']) ?>" hidden><span class="discret"><?= h($prefixe) ?>-</span><?= h((string) $e['surnom']) ?></span>
                    <button type="button" class="lien-bouton voir-mdp" data-classe="<?= h($nom) ?>" data-numero="<?= h($e['etiquette']) ?>">Mot de passe</button>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

function rendu_file_valider(array $items): void
{
    if (!$items) {
        echo '<p class="vide" style="margin:0">Rien à valider pour cette classe.</p>';
        return;
    }
    echo '<ol class="file-valider">';
    foreach (array_slice($items, 0, 6) as $it) {
        $l = $it['ligne'];
        $qui = ($l['titre'] ?? '') !== '' ? $l['titre'] : implode(', ', array_map(fn($m) => $m['etiquette'], $l['membres'] ?? []));
        printf('<li><a href="./?p=projets&amp;parcours=%d&amp;vue=a_valider"><span class="qui">%s</span> <span class="quoi">%s · ét. %d</span> <span class="quand discret">%s</span> <span class="tag %s">%s</span></a></li>',
            (int) $it['pid'], h($qui), h($it['pnom']), (int) $it['ordre'] + 1, h(depuis_texte((int) $it['depuis'])),
            $it['valider'] ? 't-valider' : 't-noter', $it['valider'] ? 'à valider' : 'à évaluer');
    }
    echo '</ol>';
    if (count($items) > 6) {
        printf('<p class="discret" style="margin:4px 0 0">+ %d autre(s).</p>', count($items) - 6);
    }
}

/** Pastille d'une classe pour l'étiquette 3D : nom court, fond = couleur du niveau, bord = couleur de l'établissement. */
function couleurs_pastille(array $classe, string $salle = ''): array
{
    $niv = niveau_classe($classe['nom']);
    $etab = isset($classe['etablissement_id']) ? etablissement_par_id((int) $classe['etablissement_id']) : null;
    return [
        'texte' => nom_court_classe($classe),
        'fond'  => $niv ? COULEURS_NIVEAUX[$niv] : '#dee2e6',
        'bord'  => couleur_etablissement(isset($classe['etablissement_id']) ? (int) $classe['etablissement_id'] : null) ?: null,
        'infos' => implode(' - ', array_filter([$salle, $etab['nom'] ?? ''], fn($x) => $x !== '')),
    ];
}

/**
 * Données d'un plan de classe (salle + élèves placés) pour une séance, ou null si pas de salle.
 * $exiger_placement = false : renvoie quand même la salle (pièce) si aucun élève n'y est placé (simulation).
 */
function donnees_plan_seance(array $s, bool $exiger_placement = true): ?array
{
    $salle_id = (int) ($s['salle_id'] ?? 0);
    if (!$salle_id) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM salles WHERE id = ?');
    $st->execute([$salle_id]);
    $salle = $st->fetch();
    if (!$salle) {
        return null;
    }
    $classe_id = (int) $s['classe_id'];
    $groupe_id = (int) ($s['groupe_id'] ?? 0);
    $st = db()->prepare("SELECT id, etiquette, actif FROM eleves WHERE classe_id = ?" . ($groupe_id ? ' AND groupe_id = ?' : '') . "
        ORDER BY CASE WHEN etiquette GLOB '[0-9]*' THEN CAST(etiquette AS INTEGER) ELSE 9999 END, etiquette");
    $st->execute($groupe_id ? [$classe_id, $groupe_id] : [$classe_id]);
    $eleves = $st->fetchAll();
    $st = db()->prepare('SELECT place, eleve_id FROM placements WHERE classe_id = ? AND groupe_id = ? AND salle_id = ?');
    $st->execute([$classe_id, $groupe_id, $salle_id]);
    $placement = array_map('intval', array_column($st->fetchAll(), 'eleve_id', 'place'));
    if ($exiger_placement && !$placement) {
        return null;   // salle sans plan de classe enregistré pour cette classe : rien à montrer
    }
    $college = db()->prepare('SELECT e.nom FROM classes c JOIN etablissements e ON e.id = c.etablissement_id WHERE c.id = ?');
    $college->execute([$classe_id]);
    return [
        'plan'      => plan_de($salle),
        'etablissement' => (string) $college->fetchColumn(),
        'pastille'  => pastille_classe($classe_id),
        'etiquette' => ($c = classe_par_id($classe_id)) ? couleurs_pastille($c, (string) $salle['nom']) : null,
        'classe'    => classe_par_id($classe_id)['nom'] ?? '',
        'eleves'    => array_map(fn($e) => ['id' => (int) $e['id'], 'numero' => $e['etiquette'], 'actif' => (bool) $e['actif']], $eleves),
        'placement' => (object) $placement,
        'salle'     => $salle['nom'],
        'salle_id'  => $salle_id,
        'classe_id' => $classe_id,
        'groupe_id' => $groupe_id,
    ];
}

/** Icône de trait pour les widgets du tableau de bord (couleur héritée du bouton). */
function dash_icone(string $nom): string
{
    $formes = [
        'alerte'   => '<path d="M12 3 2 20.5h20L12 3z"/><path d="M12 10v4.5M12 17.5v.01"/>',
        'valider'  => '<circle cx="12" cy="12" r="9"/><path d="m8 12.2 2.8 2.8L16 9.5"/>',
        'simuler'  => '<path d="M7.3 4.5v15l9.4-7.5z"/>',
        'calendrier' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'cours'    => '<path d="M3 20.5V9l9-5.5 9 5.5v11.5"/><path d="M9 20.5v-6h6v6"/>',
        'connexions' => '<circle cx="9" cy="8" r="3.6"/><path d="M2.5 20c.8-3.6 3.4-5.6 6.5-5.6s5.7 2 6.5 5.6"/><path d="M16 4.6a3.6 3.6 0 0 1 0 6.8"/>',
        'prochain' => '<path d="M4 12h14M13 6l6 6-6 6"/>',
        'oeil'     => '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'pause'    => '<rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">' . ($formes[$nom] ?? '') . '</svg>';
}

/** Pastille du menu « Voir une classe » : seulement le nom de la classe (4B), le groupe en petit. */
function pastille_menu(array $classe, string $groupe = ''): string
{
    $html = pastille_classe($classe, $groupe, $groupe !== '' ? 'petite' : '');   // les groupes ont une pastille plus petite
    $court = preg_replace('/-[A-Z]{3}$/', '', nom_court_classe($classe)) ?? '';
    return str_replace('>' . h(nom_court_classe($classe)) . '<', '>' . h($court) . '<', $html);
}

function page_tableau_de_bord(): void
{
    $pdo = db();
    $aujourdhui = aujourd_hui();
    $lundi = lundi_de(date_valide((string) ($_GET['semaine'] ?? '')) ? (string) $_GET['semaine'] : $aujourdhui);
    $etabs = $pdo->query('SELECT * FROM etablissements ORDER BY nom')->fetchAll();
    $sem = seances_semaine_tous($lundi);
    $maintenant = new DateTimeImmutable('now', fuseau());
    $heure = $maintenant->format('H:i');

    // Prochains cours : à partir de maintenant, sur trois semaines
    $prochains = [];
    foreach ([$lundi === lundi_de($aujourdhui) ? $sem : seances_semaine_tous(lundi_de($aujourdhui)),
              seances_semaine_tous(ajouter_jours(lundi_de($aujourdhui), 7)),
              seances_semaine_tous(ajouter_jours(lundi_de($aujourdhui), 14))] as $bloc) {
        foreach ($bloc['seances'] as $s) {
            if ($s['date'] > $aujourdhui || ($s['date'] === $aujourdhui && $s['fin'] > $heure)) {
                $prochains[$s['cle'] . $s['etablissement_id']] = $s;
            }
        }
    }
    $prochains = array_slice(array_values($prochains), 0, 8);

    // En cours maintenant : séance d'aujourd'hui dont l'heure courante est dans [début, fin[ (la plus proche de la fin d'abord)
    $en_cours_maintenant = array_values(array_filter($sem['seances'],
        fn($s) => $s['date'] === $aujourdhui && $s['statut'] !== 'annule' && $s['debut'] <= $heure && $s['fin'] > $heure));
    usort($en_cours_maintenant, fn($a, $b) => strcmp($a['fin'], $b['fin']));

    // File des élèves à valider / à évaluer, tous parcours confondus, le plus ancien d'abord
    $a_valider = [];
    foreach ($pdo->query('SELECT id FROM parcours')->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $parc = parcours_par_id((int) $pid);
        if (!$parc) {
            continue;
        }
        foreach (a_valider_parcours($parc) as $it) {
            $a_valider[] = $it + ['pid' => (int) $pid, 'pnom' => $parc['nom'],
                                  'etape_titre' => $parc['etapes'][$it['ordre']]['titre'] ?? ('Étape ' . ($it['ordre'] + 1))];
        }
    }
    usort($a_valider, fn($a, $b) => ($a['depuis'] ?: PHP_INT_MAX) <=> ($b['depuis'] ?: PHP_INT_MAX));
    $a_valider_tous = $a_valider;                 // liste complète, pour filtrer par classe dans « En cours »
    $total_av = count($a_valider);
    $a_valider = array_slice($a_valider, 0, 8);

    // Prochain cours (le premier à venir qui n'a pas encore commencé), pour le message d'info de « En cours »
    $suivant = null;
    foreach ($prochains as $p) {
        if ($p['date'] > $aujourdhui || $p['debut'] > $heure) {
            $suivant = $p;
            break;
        }
    }

    // « Simuler un cours » : une vraie classe au hasard, de préférence avec un plan de classe (élèves placés),
    // sinon une classe qui a un cours dans une salle dessinée (on montre la pièce), sinon n'importe quelle classe.
    $simulation = null;
    $exige_plac = true;
    $cand = $pdo->query("SELECT classe_id, salle_id, groupe_id FROM placements GROUP BY classe_id, salle_id, groupe_id ORDER BY RANDOM() LIMIT 1")->fetch();
    if (!$cand) {
        $exige_plac = false;
        $cand = $pdo->query("SELECT DISTINCT c.classe_id, c.salle_id, COALESCE(c.groupe_id, 0) AS groupe_id
                             FROM cours c JOIN salles s ON s.id = c.salle_id WHERE COALESCE(s.plan, '') <> '' ORDER BY RANDOM() LIMIT 1")->fetch();
    }
    if (!$cand) {
        $c = $pdo->query("SELECT id FROM classes WHERE nom <> 'TEST' ORDER BY RANDOM() LIMIT 1")->fetch();
        if ($c) {
            $cand = ['classe_id' => (int) $c['id'], 'salle_id' => 0, 'groupe_id' => 0];
        }
    }
    if ($cand) {
        $simulation = [
            'classe_id' => (int) $cand['classe_id'],
            'groupe_id' => (int) $cand['groupe_id'],
            'plan'      => donnees_plan_seance(['classe_id' => (int) $cand['classe_id'], 'salle_id' => (int) $cand['salle_id'], 'groupe_id' => (int) $cand['groupe_id']], $exige_plac),
            'a_valider' => array_values(array_filter($a_valider_tous, fn($it) => (int) $it['classe']['id'] === (int) $cand['classe_id'])),
        ];
    }

    entete('Accueil');
    barre('accueil');
    ?>
    <main class="large accueil-dash">
        <?php messages(); ?>

        <?php
        $plan_suivant = !$en_cours_maintenant && $suivant ? donnees_plan_seance($suivant, false) : null;
        // toutes les classes : chaque plan de classe posé (classe, salle, groupe) est une entrée ; ses versions enregistrées
        // en sont les dérivés (sous-menu) ; les classes sans plan ont une entrée à part
        $contextes = [];
        $avec_plan = [];
        $nouveau_contexte = fn(array $l) => ['classe' => $l['classe'], 'college' => (string) ($l['college'] ?? ''), 'libelle' => $l['salle'],
            'pastille' => pastille_menu(['nom' => $l['classe'], 'etablissement_id' => $l['etablissement_id']], (string) ($l['groupe'] ?? '')),
            'donnees' => null, 'versions' => []];
        foreach (db()->query('SELECT DISTINCT p.classe_id, p.groupe_id, p.salle_id, c.nom AS classe, c.etablissement_id, s.nom AS salle, g.nom AS groupe, e.nom AS college
                              FROM placements p JOIN classes c ON c.id = p.classe_id JOIN salles s ON s.id = p.salle_id
                              LEFT JOIN groupes g ON g.id = p.groupe_id LEFT JOIN etablissements e ON e.id = c.etablissement_id
                              ORDER BY c.nom, g.nom, s.nom')->fetchAll() as $ligne) {
            $donnees = donnees_plan_seance(['classe_id' => $ligne['classe_id'], 'groupe_id' => $ligne['groupe_id'], 'salle_id' => $ligne['salle_id']]);
            if ($donnees) {
                $cle = $ligne['classe_id'] . '-' . $ligne['groupe_id'] . '-' . $ligne['salle_id'];
                $contextes[$cle] ??= $nouveau_contexte($ligne);
                $contextes[$cle]['donnees'] = $donnees;
                $avec_plan[(int) $ligne['classe_id']] = true;
            }
        }
        foreach (db()->query('SELECT v.classe_id, v.groupe_id, v.salle_id, v.nom AS version, v.carte, v.cree_le, c.nom AS classe, c.etablissement_id,
                              s.nom AS salle, g.nom AS groupe, e.nom AS college
                              FROM plans_versions v JOIN classes c ON c.id = v.classe_id JOIN salles s ON s.id = v.salle_id
                              LEFT JOIN groupes g ON g.id = v.groupe_id LEFT JOIN etablissements e ON e.id = c.etablissement_id
                              ORDER BY c.nom, g.nom, s.nom, v.id')->fetchAll() as $v) {
            $donnees = donnees_plan_seance(['classe_id' => $v['classe_id'], 'groupe_id' => $v['groupe_id'], 'salle_id' => $v['salle_id']], false);
            if ($donnees) {
                $donnees['placement'] = (object) (json_decode((string) $v['carte'], true) ?: []);
                $cle = $v['classe_id'] . '-' . $v['groupe_id'] . '-' . $v['salle_id'];
                $contextes[$cle] ??= $nouveau_contexte($v);
                $contextes[$cle]['versions'][] = ['nom' => $v['version'], 'cree_le' => date('d/m/Y', strtotime($v['cree_le'])), 'donnees' => $donnees];
                $avec_plan[(int) $v['classe_id']] = true;
            }
        }
        foreach (db()->query('SELECT c.id, c.nom, c.etablissement_id, e.nom AS college FROM classes c LEFT JOIN etablissements e ON e.id = c.etablissement_id')->fetchAll() as $classe) {
            if (empty($avec_plan[(int) $classe['id']])) {
                $contextes['sans-' . $classe['id']] = ['classe' => $classe['nom'], 'college' => (string) ($classe['college'] ?? ''), 'libelle' => 'sans plan de classe',
                    'pastille' => pastille_menu($classe), 'donnees' => null, 'versions' => []];
            }
        }
        // plan appliqué = celui dont le placement est identique au plan en cours ; sinon le plan en cours lui-même
        $norme = function ($placement) { $c = array_map('intval', (array) $placement); ksort($c); return $c; };
        foreach ($contextes as $k => $ctx) {
            $contextes[$k]['applique'] = null;
            if ($ctx['donnees'] !== null) {
                $actuel = $norme($ctx['donnees']['placement']);
                $contextes[$k]['applique'] = 'live';
                for ($j = count($ctx['versions']) - 1; $j >= 0; $j--) {
                    if ($norme($ctx['versions'][$j]['donnees']['placement']) === $actuel) {
                        $contextes[$k]['applique'] = (string) $j;
                        break;
                    }
                }
            }
        }
        $contextes = array_values($contextes);
        usort($contextes, fn($a, $b) => strcasecmp($a['college'], $b['college']) ?: strcasecmp($a['classe'], $b['classe']) ?: strcasecmp($a['pastille'], $b['pastille']));
        $plans_classes = $contextes;
        $suivant_jours = $suivant ? (int) round((strtotime($suivant['date']) - strtotime($aujourdhui)) / 86400) : 0;
        $suivant_debut = $suivant ? minutes($suivant['debut']) * 60 : 0;
        $sec_maintenant = (int) $maintenant->format('H') * 3600 + (int) $maintenant->format('i') * 60 + (int) $maintenant->format('s');
        $cours_classes = [];
        foreach (array_merge($en_cours_maintenant, $suivant ? [$suivant] : []) as $c) {
            $classe = classe_par_id((int) $c['classe_id']);
            if ($classe) {
                $cours_classes[(int) $classe['id']] = ['nom' => $classe['nom'], 'id' => (int) $classe['id'], 'html' => pastille_classe($classe)];
            }
        }
        ?>
        <div class="dashboard" id="dashboard">
            <div class="dash-vue" id="dash-vue">
                <div class="dash-plan" id="dash-plan"></div>
                <div class="dash-vide" id="dash-vide">
                    <div>
                        <p class="dash-vide-titre">Pas de cours en ce moment</p>
                        <?php if ($suivant): ?>
                            <p class="discret">Prochain : <strong><?= h(quand_seance($suivant['date'], $aujourdhui)) ?></strong> à <?= h($suivant['debut']) ?> — <?= h($suivant['intitule'] ?: 'Cours') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <nav class="dash-widgets" aria-label="Accès rapide">
                    <div class="dz dz-haut-centre">
                        <button type="button" class="dash-widget dash-alerte" id="dash-alerte" data-panneau="alerte-detail" data-titre="Session non désanonymisée" hidden>
                            <?= dash_icone('alerte') ?>
                            <span><strong>Session non désanonymisée</strong><small>Charger les noms des classes</small></span>
                        </button>
                        <?php if ($a_valider): ?>
                            <button type="button" class="dash-widget dash-valider" data-panneau="a-valider" data-titre="Élèves à valider">
                                <?= dash_icone('valider') ?>
                                <span>À valider <b class="compte-pastille"><?= (int) $total_av ?></b></span>
                            </button>
                        <?php endif; ?>
                        <?php if ($plans_classes): ?>
                            <div class="dash-choix-classe" id="choix-classe">
                                <button type="button" class="dash-widget choix-bouton" id="choix-bouton" aria-haspopup="listbox" aria-expanded="false">
                                    <?= dash_icone('cours') ?><span class="choix-courant" id="choix-courant">Voir une classe…</span>
                                </button>
                                <ul class="choix-liste" id="choix-liste" role="listbox" hidden>
                                    <li role="option" data-valeur="">Voir une classe…</li>
                                    <?php $etablissement_precedent = null; ?>
                                    <?php foreach ($plans_classes as $i => $p): ?>
                                        <?php $etablissement = $p['college'] !== '' ? $p['college'] : 'Sans établissement'; ?>
                                        <?php if ($etablissement !== $etablissement_precedent): ?>
                                            <li role="presentation" class="choix-etablissement"><?= h($etablissement) ?></li>
                                            <?php $etablissement_precedent = $etablissement; ?>
                                        <?php endif; ?>
                                        <li role="option" data-valeur="<?= $i ?>"><?= $p['pastille'] ?> <span><?= h($p['libelle']) ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="choix-versions" id="choix-versions" hidden>
                                    <p class="choix-versions-titre">Versions</p>
                                    <ul class="choix-versions-liste" id="choix-versions-liste" role="listbox"></ul>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="dz dz-haut-droite">
                        <div class="dash-horloge" id="dash-horloge" hidden
                         data-debut="<?= $en_cours_maintenant ? minutes($en_cours_maintenant[0]['debut']) * 60 : '' ?>"
                         data-fin="<?= $en_cours_maintenant ? minutes($en_cours_maintenant[0]['fin']) * 60 : '' ?>"
                         data-maintenant="<?= $sec_maintenant ?>">
                        <svg class="dash-cadran" viewBox="-100 -100 200 200" aria-hidden="true">
                            <circle r="96" class="cadran-fond"/>
                            <path id="horloge-secteur" class="cadran-secteur" d=""/>
                            <g id="horloge-graduations"></g>
                            <line id="aig-h" class="aiguille aiguille-h" x1="0" y1="0" x2="0" y2="-50"/>
                            <line id="aig-m" class="aiguille aiguille-m" x1="0" y1="0" x2="0" y2="-74"/>
                            <line id="aig-s" class="aiguille aiguille-s" x1="0" y1="0" x2="0" y2="-84"/>
                            <circle r="4" class="cadran-centre"/>
                        </svg>
                        <dl class="horloge-infos">
                            <div><dt>Heure</dt><dd id="horloge-heure">--:--</dd></div>
                            <div><dt>Début</dt><dd id="barre-debut">--:--</dd></div>
                            <div><dt>Fin</dt><dd id="barre-fin">--:--</dd></div>
                            <div><dt>Reste</dt><dd id="barre-reste">--</dd></div>
                        </dl>
                        <div class="barre-piste"><div class="barre-remplie" id="barre-fill"></div></div>
                    </div>
                    </div>
                    <div class="dz dz-bas-centre">
                        <div class="compte-carte<?= $suivant ? '' : ' vide' ?>">
                        <?php if ($suivant): ?>
                            <button type="button" class="dash-compte" id="dash-compte" data-panneau="prochains" data-titre="Prochains cours"
                                data-jours="<?= $suivant_jours ?>" data-debut="<?= $suivant_debut ?>">
                                <span class="compte-cadran">
                                    <svg class="compte-anneau" viewBox="-100 -100 200 200" aria-hidden="true">
                                        <circle class="cadran-fond" r="96"/>
                                        <path id="compte-secteur" class="cadran-secteur" d=""/>
                                        <g id="compte-graduations"></g>
                                        <line id="compte-aig-h" class="aiguille aiguille-h" x1="0" y1="0" x2="0" y2="-48"/>
                                        <line id="compte-aig-m" class="aiguille aiguille-m" x1="0" y1="0" x2="0" y2="-70"/>
                                        <line id="compte-aig-s" class="aiguille aiguille-s" x1="0" y1="0" x2="0" y2="-80"/>
                                        <circle r="4" class="cadran-centre"/>
                                    </svg>
                                </span>
                                <dl class="horloge-infos compte-infos">
                                    <div><dt>Heure</dt><dd id="compte-heure">--:--</dd></div>
                                    <div><dt>Début</dt><dd><?= h($suivant['debut']) ?></dd></div>
                                    <div><dt>Fin</dt><dd><?= h($suivant['fin']) ?></dd></div>
                                    <div><dt>Reste</dt><dd id="compte-reste">--</dd></div>
                                </dl>
                                <span class="compte-texte">
                                    <small>Prochain cours · <?= h(quand_seance($suivant['date'], $aujourdhui)) ?> <?= h($suivant['debut']) ?>–<?= h($suivant['fin']) ?></small>
                                    <strong><?= h($suivant['intitule'] ?: 'Cours') ?> · <?= pastille_classe($suivant['classe_id'], groupe_seance($suivant)) ?></strong>
                                    <em><?= $suivant['salle'] ? 'Salle ' . h($suivant['salle']) . ' · ' : '' ?><?= h($suivant['etablissement']) ?></em>
                                </span>
                            </button>
                        <?php else: ?>
                            <p class="dash-compte-vide">Aucun cours prévu</p>
                        <?php endif; ?>
                        <div class="dz-simuler">
                            <button type="button" class="dash-simuler-rond" id="simuler-cours" aria-pressed="false"
                                title="Afficher un cours de démonstration avec un compte à rebours, sans toucher à l'emploi du temps" aria-label="Simuler un cours">
                                <span class="icone-play"><?= dash_icone('simuler') ?></span>
                                <span class="icone-pause"><?= dash_icone('pause') ?></span>
                            </button>
                            <span class="simuler-titre" id="simuler-titre">Simuler un cours</span>
                        </div>
                        </div>
                    </div>
                    <div class="dz dz-bas-gauche">
                        <?php if ($etabs): ?>
                            <button type="button" class="dash-widget" data-panneau="calendrier" data-titre="Semaine de cours"><?= dash_icone('calendrier') ?><span>Calendrier</span></button>
                        <?php endif; ?>
                        <button type="button" class="dash-widget" data-panneau="connexions" data-titre="Monitoring"><?= dash_icone('connexions') ?><span>Monitoring</span></button>
                    </div>
                    <div class="dz dz-centre-bas">
                    </div>
                    <div class="dz dz-bas-droite">
                        <form method="post" target="_blank" id="form-apercu" class="dash-forme">
                            <?= csrf() ?>
                            <input type="hidden" name="action" value="apercu_eleve">
                            <button class="dash-widget" title="Ouvre le portail élève avec le compte test de la classe TEST, sans taper de code"><?= dash_icone('oeil') ?><span>Démo élève</span></button>
                        </form>
                    </div>
                </nav>
                <div class="dash-panneau" id="dash-panneau" hidden>
                    <div class="dash-panneau-tete"><h2 id="dash-titre"></h2><button type="button" class="dash-fermer" id="dash-fermer" aria-label="Fermer">×</button></div>
                    <div class="dash-panneau-corps" id="dash-corps"></div>
                </div>
            </div>
        </div>

        <div id="dash-stock" hidden>
        <script type="application/json" id="json-plans-classes"><?= json_encode($plans_classes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <?php if ($plan_suivant): ?>
        <div class="ec-plan" id="plan-suivant">
            <div class="ec-sous-titre">Pas de cours en ce moment · salle vide pour le prochain cours : <?= h($suivant['intitule'] ?: 'Cours') ?> · <?= h($plan_suivant['salle']) ?> · <?= h(quand_seance($suivant['date'], $aujourdhui)) ?> à <?= h($suivant['debut']) ?></div>
            <svg class="plan-svg apercu-plan" id="plan-suivant-svg" xmlns="http://www.w3.org/2000/svg"></svg>
            <script type="application/json" class="plan-data" data-cible="plan-suivant-svg"><?= json_encode($plan_suivant, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        </div>
        <?php endif; ?>
        <section class="carte alerte-panneau" id="alerte-detail">
            <div class="alerte-entete">
                <span class="alerte-icone" aria-hidden="true">!</span>
                <div>
                    <h2>Session non désanonymisée</h2>
                    <p>Les noms des élèves ne sont pas chargés sur cet ordinateur : les places n’affichent que des numéros.</p>
                </div>
            </div>
            <div class="alerte-grille">
                <label class="alerte-option principale">
                    <span class="alerte-option-titre">Charger la sauvegarde complète</span>
                    <span class="alerte-option-texte">Fichier .desanonim (ou ancienne archive .zip) : noms, codes, cartons et trombinoscopes.</span>
                    <input type="file" accept=".desanonim,.zip,application/json,application/zip" class="charger-zip">
                </label>
                <a class="alerte-option" href="./?p=classes">
                    <span class="alerte-option-titre">Classe par classe</span>
                    <span class="alerte-option-texte">Charger le fichier de noms d’une seule classe.</span>
                </a>
                <a class="alerte-option" href="./?p=reglages">
                    <span class="alerte-option-titre">Réglages et sauvegardes</span>
                    <span class="alerte-option-texte">Sauvegarder, restaurer, ou effacer les données de cet ordinateur.</span>
                </a>
            </div>
            <div class="alerte-classes">
                <h3>Classes concernées aujourd’hui</h3>
                <ul class="alerte-liste" id="alerte-liste"></ul>
            </div>
        </section>
        <script type="application/json" id="json-classes-cours"><?= json_encode(array_values($cours_classes), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <section class="carte en-cours-carte" id="encours">
            <div class="ec-tete">
                <h2>En cours</h2>
            </div>
            <?php if ($simulation): ?>
            <div class="ec-bloc" id="demo-bloc" hidden style="--etab:var(--accent)">
                <div class="ec-ligne">
                    <?= pastille_classe($simulation['classe_id']) ?>
                    <span class="intitule">Cours simulé</span>
                    <span class="ou"><?= $simulation['plan'] ? 'Salle ' . h($simulation['plan']['salle']) . ' · ' : '' ?>démonstration (55 min)</span>
                    <span class="rebours" id="rebours-demo">—</span>
                </div>
                <div class="ec-details">
                    <?php if ($simulation['plan']): ?>
                        <div class="ec-plan">
                            <div class="ec-sous-titre">Plan de classe · <?= h($simulation['plan']['salle']) ?>
                                <a class="btn petit secondaire" href="./?p=placement&classe=<?= (int) $simulation['classe_id'] ?>&salle=<?= (int) $simulation['plan']['salle_id'] ?><?= $simulation['plan']['groupe_id'] ? '&groupe=' . (int) $simulation['plan']['groupe_id'] : '' ?>">Ouvrir</a></div>
                            <svg class="plan-svg apercu-plan" id="plan-demo" xmlns="http://www.w3.org/2000/svg"></svg>
                            <script type="application/json" class="plan-data" data-cible="plan-demo"><?= json_encode($simulation['plan'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
                        </div>
                    <?php endif; ?>
                    <div class="ec-valider">
                        <div class="ec-sous-titre">Élèves à valider<?= $simulation['a_valider'] ? ' · ' . count($simulation['a_valider']) : '' ?></div>
                        <?php rendu_file_valider($simulation['a_valider']); ?>
                    </div>
                </div>
                <?php if ($suivant): ?>
                    <p class="ec-prochain">➡ Prochain cours : <strong><?= h(quand_seance($suivant['date'], $aujourdhui)) ?></strong> à <?= h($suivant['debut']) ?> — <?= pastille_classe($suivant['classe_id'], groupe_seance($suivant)) ?> <?= h($suivant['intitule'] ?: 'Cours') ?>.</p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <ul class="en-cours-liste" id="demo-cours" hidden>
                <li style="--etab:var(--accent)">
                    <span class="pastille-classe">DÉMO</span>
                    <span class="intitule">Cours simulé</span>
                    <span class="ou">démonstration (55 min)</span>
                    <span class="rebours" id="rebours-demo">—</span>
                </li>
            </ul>
            <?php endif; ?>
            <div id="en-cours-reel">
            <?php if ($en_cours_maintenant): ?>
                <?php foreach ($en_cours_maintenant as $i => $s):
                    $reste = max(0, (new DateTimeImmutable($aujourdhui . ' ' . $s['fin'], fuseau()))->getTimestamp() - $maintenant->getTimestamp());
                    $av_classe = array_values(array_filter($a_valider_tous, fn($it) => (int) $it['classe']['id'] === (int) $s['classe_id']));
                    $plan = donnees_plan_seance($s);
                    if ($plan) { $plan['atelier'] = $s['intitule'] ?: 'Cours'; }
                    $cle_plan = 'plan-ec-' . $i; ?>
                    <div class="ec-bloc" style="--etab:<?= h(couleur_etablissement($s['etablissement_id'])) ?>">
                        <div class="ec-ligne">
                            <?= pastille_classe($s['classe_id'], groupe_seance($s)) ?>
                            <span class="intitule"><?= h($s['intitule'] ?: 'Cours') ?></span>
                            <span class="ou"><span class="puce-etab"></span><?= h($s['etablissement']) ?><?= $s['salle'] ? ' · ' . h($s['salle']) : '' ?> · <?= h($s['creneau']) ?> (<?= h($s['debut']) ?>–<?= h($s['fin']) ?>)</span>
                            <span class="rebours" data-reste="<?= (int) $reste ?>" title="Temps restant jusqu'à la fin du cours">jusqu'à <?= h($s['fin']) ?></span>
                        </div>
                        <div class="ec-details">
                            <?php if ($plan): ?>
                                <div class="ec-plan">
                                    <div class="ec-sous-titre">Plan de classe · <?= h($plan['salle']) ?>
                                        <a class="btn petit secondaire" href="./?p=placement&classe=<?= (int) $plan['classe_id'] ?>&salle=<?= (int) $plan['salle_id'] ?><?= $plan['groupe_id'] ? '&groupe=' . (int) $plan['groupe_id'] : '' ?>">Ouvrir</a></div>
                                    <svg class="plan-svg apercu-plan" id="<?= $cle_plan ?>" xmlns="http://www.w3.org/2000/svg"></svg>
                                    <script type="application/json" class="plan-data" data-cible="<?= $cle_plan ?>"><?= json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
                                </div>
                            <?php endif; ?>
                            <?php trombi_accueil((int) $s['classe_id']); ?>
                            <div class="ec-valider">
                                <div class="ec-sous-titre">Élèves à valider<?= $av_classe ? ' · ' . count($av_classe) : '' ?></div>
                                <?php rendu_file_valider($av_classe); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($suivant): ?>
                    <p class="ec-prochain">➡ Prochain cours : <strong><?= h(quand_seance($suivant['date'], $aujourdhui)) ?></strong> à <?= h($suivant['debut']) ?> — <?= pastille_classe($suivant['classe_id'], groupe_seance($suivant)) ?> <?= h($suivant['intitule'] ?: 'Cours') ?><?= $suivant['salle'] ? ' · ' . h($suivant['salle']) : '' ?>.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="vide" style="margin:0">Pas de cours en ce moment.<?php if ($suivant): ?> Prochain : <strong><?= h(quand_seance($suivant['date'], $aujourdhui)) ?></strong> à <?= h($suivant['debut']) ?> — <?= pastille_classe($suivant['classe_id'], groupe_seance($suivant)) ?> <?= h($suivant['intitule'] ?: 'Cours') ?>.<?php endif; ?></p>
            <?php endif; ?>
            </div>
        </section>
        <script>
        (function () {
            function fmt(s) { var h = (s / 3600) | 0, m = ((s % 3600) / 60) | 0, x = s % 60;
                return (h ? h + ' h ' : '') + (h || m ? m + ' min ' : '') + x + ' s'; }
            document.querySelectorAll('.rebours[data-reste]').forEach(function (el) {
                var r = parseInt(el.dataset.reste, 10) || 0;
                (function tick() {
                    if (r <= 0) { el.textContent = 'cours terminé'; el.classList.add('fini'); return; }
                    el.textContent = fmt(r) + ' restantes'; r--; setTimeout(tick, 1000);
                })();
            });
            // Bouton « Simuler un cours » : affiche un cours de démonstration avec compte à rebours.
            var b = document.getElementById('simuler-cours'),
                demo = document.getElementById('demo-bloc') || document.getElementById('demo-cours'),
                reel = document.getElementById('en-cours-reel'),
                eld = document.getElementById('rebours-demo'), tdemo;
            if (b && demo) b.addEventListener('click', function () {
                if (demo.hidden) {
                    demo.hidden = false; if (reel) reel.hidden = true;
                    document.getElementById('simuler-titre').textContent = 'Arrêter la simulation'; b.setAttribute('aria-pressed', 'true');
                    if (window.dashVuePlan) window.dashVuePlan(demo.querySelector('.ec-plan'));
                    if (window.dashHorloge) { var t0 = window.dashHorloge.maintenant(); window.dashHorloge.regler(t0, t0 + 55 * 60); document.getElementById('dash-horloge').hidden = true; }
                    eld.classList.remove('fini');
                    var r = 55 * 60;
                    (function tick() {
                        if (r <= 0) { eld.textContent = 'cours terminé'; eld.classList.add('fini'); return; }
                        eld.textContent = fmt(r) + ' restantes'; r--; tdemo = setTimeout(tick, 1000);
                    })();
                } else {
                    clearTimeout(tdemo); demo.hidden = true; if (reel) reel.hidden = false;
                    document.getElementById('simuler-titre').textContent = 'Simuler un cours'; b.setAttribute('aria-pressed', 'false');
                    if (window.dashVuePlan) window.dashVuePlan(window.dashPlanParDefaut());
                    if (window.dashHorloge) window.dashHorloge.reelle();
                }
            });
        })();
        </script>
        <?php if ($en_cours_maintenant || ($simulation && $simulation['plan']) || $plan_suivant || $plans_classes): ?>
        <script src="plan.js?v=<?= @filemtime(__DIR__ . '/../public/admin/plan.js') ?: 1 ?>"></script>
        <script>
        document.querySelectorAll('.plan-data').forEach(function (s) {
            try { PlanSalle.apercu(document.getElementById(s.dataset.cible), JSON.parse(s.textContent)); } catch (e) {}
        });
        </script>
        <?php endif; ?>

        <?php if (!$etabs): ?>
            <section class="carte" style="margin-top:16px">
                <p>Ajoutez vos établissements (grille horaire, vacances, salles) pour voir ici votre semaine de cours.</p>
                <a class="btn" href="./?p=etablissements&nouveau=1">Ajouter un établissement</a>
            </section>
        <?php else: ?>
            <?php section_calendrier($lundi, $sem, $etabs, $aujourdhui, $heure); ?>
        <?php endif; ?>

        <?php if ($a_valider): ?>
            <section class="carte a-valider-accueil" id="a-valider">
                <h2>Élèves à valider <span class="compte-pastille"><?= (int) $total_av ?></span></h2>
                <p class="aide" style="margin:2px 0 10px">Étapes de parcours terminées qui attendent ta validation ou ta notation, le plus ancien d'abord.</p>
                <ol class="file-valider">
                    <?php foreach ($a_valider as $it):
                        $l = $it['ligne'];
                        $qui = ($l['titre'] ?? '') !== '' ? $l['titre']
                             : implode(', ', array_map(fn($m) => $m['etiquette'], $l['membres'] ?? [])); ?>
                        <li>
                            <a href="./?p=projets&amp;parcours=<?= (int) $it['pid'] ?>&amp;vue=a_valider">
                                <?= pastille_classe($it['classe']) ?>
                                <span class="qui"><?= h($qui) ?></span>
                                <span class="quoi"><?= h($it['pnom']) ?> · ét. <?= (int) $it['ordre'] + 1 ?> « <?= h($it['etape_titre']) ?> »</span>
                                <span class="quand discret"><?= h(depuis_texte((int) $it['depuis'])) ?></span>
                                <span class="tag <?= $it['valider'] ? 't-valider' : 't-noter' ?>"><?= $it['valider'] ? 'à valider' : 'à évaluer' ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($total_av > count($a_valider)): ?>
                    <p class="discret" style="margin:8px 0 0">+ <?= (int) ($total_av - count($a_valider)) ?> autre(s) — ouvre un parcours pour tout voir.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <div class="accueil-colonnes">
            <section class="carte" id="prochains">
                <h2>Prochains cours</h2>
                <?php if (!$prochains): ?>
                    <p class="vide">Aucun cours prévu dans les trois prochaines semaines.</p>
                <?php else: ?>
                    <ol class="prochains">
                        <?php foreach ($prochains as $s):
                            $en_cours = $s['date'] === $aujourdhui && $s['debut'] <= $heure; ?>
                            <li class="<?= $s['statut'] === 'annule' ? 'annule' : '' ?><?= $en_cours ? ' en-cours' : '' ?>" style="--etab:<?= h(couleur_etablissement($s['etablissement_id'])) ?>">
                                <div class="quand">
                                    <strong><?= h(quand_seance($s['date'], $aujourdhui)) ?></strong>
                                    <span><?= h($s['debut']) ?>–<?= h($s['fin']) ?></span>
                                </div>
                                <div class="quoi">
                                    <?= pastille_classe($s['classe_id'], groupe_seance($s)) ?>
                                    <span class="intitule"><?= h($s['intitule']) ?></span>
                                    <span class="ou"><span class="puce-etab"></span><?= h($s['etablissement']) ?><?= $s['salle'] ? ' · ' . h($s['salle']) : '' ?><?= $s['semaine_ab'] ? ' · sem. ' . h($s['semaine_ab']) : '' ?></span>
                                    <?php if ($en_cours): ?><span class="etiquette-en-cours">En cours</span><?php endif; ?>
                                    <?php if ($s['statut'] === 'annule'): ?><span class="alerte">Annulé<?= $s['note'] ? ' : ' . h($s['note']) : '' ?></span>
                                    <?php elseif ($s['note']): ?><span class="discret">📝 <?= h($s['note']) ?></span><?php endif; ?>
                                </div>
                                <div class="actions">
                                    <?php if ($s['salle_id'] && $s['statut'] !== 'annule'): ?>
                                        <a class="btn petit secondaire" href="./?p=placement&classe=<?= (int) $s['classe_id'] ?>&salle=<?= (int) $s['salle_id'] ?><?= !empty($s['groupe_id']) ? '&groupe=' . (int) $s['groupe_id'] : '' ?>">Plan</a>
                                    <?php endif; ?>
                                    <a class="btn petit secondaire" href="./?p=edt&etab=<?= (int) $s['etablissement_id'] ?>&semaine=<?= h(lundi_de($s['date'])) ?>&seance=<?= h($s['cle']) ?>#panneau">Modifier</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
            <?php section_connexions_accueil(); ?>
        </div>
        </div>
        <script>
        (function () {
            var vue = document.getElementById('dash-vue'), plan = document.getElementById('dash-plan'),
                vide = document.getElementById('dash-vide'), panneau = document.getElementById('dash-panneau'),
                corps = document.getElementById('dash-corps'), titre = document.getElementById('dash-titre'),
                horloge = document.getElementById('dash-horloge'), alerte = document.getElementById('dash-alerte'),
                liste = document.getElementById('alerte-liste'), ouvert = null, planAffiche = null, reglage = null;

            /* La barre haute est mesurée : le tableau de bord la remplit sous elle, sans dépasser l'écran. */
            function mesurer() {
                var b = document.querySelector('header.barre');
                document.documentElement.style.setProperty('--barre-h', (b ? b.offsetHeight : 60) + 'px');
            }
            mesurer();
            window.addEventListener('resize', mesurer);

            /* Un élément déplacé dans la vue ou un panneau revient à sa place d'origine quand il est remplacé. */
            function sortir(el) {
                el._marque = document.createComment(el.id || 'element');
                el.parentNode.insertBefore(el._marque, el);
                return el;
            }
            function rentrer(el) {
                el._marque.parentNode.insertBefore(el, el._marque);
            }

            /* Plan affiché dans la vue : le cours en cours, sinon la salle vide du prochain cours. */
            function planParDefaut() {
                return document.querySelector('#en-cours-reel .ec-plan') || document.getElementById('plan-suivant');
            }
            function montrerPlan(noeud) {
                if (planAffiche && planAffiche.noeud === noeud) return;
                if (planAffiche) { rentrer(planAffiche.noeud); planAffiche = null; }
                plan.replaceChildren();
                if (noeud) { sortir(noeud); plan.appendChild(noeud); planAffiche = { noeud: noeud }; }
                vide.hidden = !!noeud;
                document.dispatchEvent(new Event('centrale-vue'));   // les plans 3D se redessinent à leur taille
            }
            window.dashVuePlan = montrerPlan;
            window.dashPlanParDefaut = planParDefaut;

            /* Horloge du cours : cadran avec le temps écoulé en secteur, puis barre temporelle dessous. */
            var SVGNS = 'http://www.w3.org/2000/svg', R = 92;
            var base = Number(horloge.dataset.maintenant) || 0, t0 = Date.now();
            var secteur = document.getElementById('horloge-secteur'), graduations = document.getElementById('horloge-graduations');
            var aiguilles = { h: document.getElementById('aig-h'), m: document.getElementById('aig-m'), s: document.getElementById('aig-s') };
            var remplie = document.getElementById('barre-fill'), debutTxt = document.getElementById('barre-debut'),
                finTxt = document.getElementById('barre-fin'), resteTxt = document.getElementById('barre-reste');
            for (var i = 0; i < 12; i++) {
                var a = i * Math.PI / 6, trait = document.createElementNS(SVGNS, 'line');
                trait.setAttribute('class', 'cadran-trait');
                trait.setAttribute('x1', ((i % 3 ? 84 : 76) * Math.sin(a)).toFixed(2)); trait.setAttribute('y1', (-(i % 3 ? 84 : 76) * Math.cos(a)).toFixed(2));
                trait.setAttribute('x2', (88 * Math.sin(a)).toFixed(2)); trait.setAttribute('y2', (-88 * Math.cos(a)).toFixed(2));
                graduations.appendChild(trait);
            }
            /* Couleur du cours selon la durée écoulée : bleu au début, puis vert, orange et rouge à la fin. */
            var PALETTE_DUREE = [[21, 101, 192], [46, 125, 50], [239, 108, 0], [198, 40, 40]];
            function couleurDuree(k) {
                var x = Math.min(0.9999, Math.max(0, k)) * (PALETTE_DUREE.length - 1), i = Math.floor(x), f = x - i;
                var a = PALETTE_DUREE[i], b = PALETTE_DUREE[i + 1];
                return 'rgb(' + a.map(function (v, j) { return Math.round(v + (b[j] - v) * f); }).join(',') + ')';
            }
            function maintenant() { return base + (Date.now() - t0) / 1000; }
            function hm(s) {
                s = Math.floor(s);
                return String(Math.floor(s / 3600)).padStart(2, '0') + ':' + String(Math.floor(s % 3600 / 60)).padStart(2, '0');
            }
            function aiguille(el, angle, longueur) {
                el.setAttribute('x2', (longueur * Math.sin(angle)).toFixed(2));
                el.setAttribute('y2', (-longueur * Math.cos(angle)).toFixed(2));
            }
            function dessinerHorloge() {
                if (!reglage) return;
                var t = maintenant(), debut = reglage.debut, fin = reglage.fin;
                var k = Math.min(1, Math.max(0, (t - debut) / (fin - debut)));
                horloge.style.setProperty('--horloge-c', couleurDuree(k));
                if (k <= 0) secteur.setAttribute('d', '');
                else if (k >= 0.9999) secteur.setAttribute('d', 'M0,-' + R + ' A' + R + ',' + R + ' 0 1,1 0,' + R + ' A' + R + ',' + R + ' 0 1,1 0,-' + R + ' Z');
                else {
                    var fleche = k * 2 * Math.PI;
                    secteur.setAttribute('d', 'M0,0 L0,-' + R + ' A' + R + ',' + R + ' 0 ' + (k > 0.5 ? 1 : 0) + ',1 ' +
                        (R * Math.sin(fleche)).toFixed(2) + ',' + (-R * Math.cos(fleche)).toFixed(2) + ' Z');
                }
                var jour = t % 86400;
                aiguille(aiguilles.h, (jour % 43200) / 43200 * 2 * Math.PI, 50);
                aiguille(aiguilles.m, (jour % 3600) / 3600 * 2 * Math.PI, 74);
                aiguille(aiguilles.s, (jour % 60) / 60 * 2 * Math.PI, 84);
                remplie.style.width = (k * 100).toFixed(2) + '%';
                debutTxt.textContent = hm(debut);
                finTxt.textContent = hm(fin);
                document.getElementById('horloge-heure').textContent = hm(t % 86400);
                resteTxt.textContent = t < fin ? Math.ceil((fin - t) / 60) + ' min' : 'terminé';
            }
            /* Ambiance : la couleur du fond suit le moment (cours, entre les cours, soir à partir de 18 h). */
            function ambiance() {
                var jour = maintenant() % 86400;
                vue.dataset.ambiance = reglage ? 'cours' : jour >= 18 * 3600 ? 'soir' : 'entre';
            }
            function regler(debut, fin) {
                reglage = debut == null ? null : { debut: debut, fin: fin };
                horloge.hidden = !reglage;
                dessinerHorloge();
                ambiance();
            }
            function reelle() {
                var d = horloge.dataset.debut;
                regler(d ? Number(d) : null, Number(horloge.dataset.fin));
            }
            window.dashHorloge = { maintenant: maintenant, regler: regler, reelle: reelle };
            reelle();
            setInterval(dessinerHorloge, 1000);
            ambiance();
            setInterval(ambiance, 30000);

            /* Prochain cours : l'anneau montre le temps restant (tour par heure), le chiffre le total. */
            var compte = document.getElementById('dash-compte');
            function hms(s) {
                s = Math.floor(s);
                return [Math.floor(s / 3600), Math.floor(s % 3600 / 60), s % 60].map(function (n) { return String(n).padStart(2, '0'); }).join(':');
            }
            var compteSecteur = document.getElementById('compte-secteur'), compteReste = document.getElementById('compte-reste'), compteHeure = document.getElementById('compte-heure');
            var compteAig = { h: document.getElementById('compte-aig-h'), m: document.getElementById('compte-aig-m'), s: document.getElementById('compte-aig-s') };
            var compteGraduations = document.getElementById('compte-graduations');
            if (compteGraduations) {
                for (var g = 0; g < 12; g++) {
                    var angle = g * Math.PI / 6, trait = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                    trait.setAttribute('class', 'cadran-trait');
                    trait.setAttribute('x1', ((g % 3 ? 84 : 76) * Math.sin(angle)).toFixed(2)); trait.setAttribute('y1', (-(g % 3 ? 84 : 76) * Math.cos(angle)).toFixed(2));
                    trait.setAttribute('x2', (88 * Math.sin(angle)).toFixed(2)); trait.setAttribute('y2', (-88 * Math.cos(angle)).toFixed(2));
                    compteGraduations.appendChild(trait);
                }
            }
            /* Cadran du prochain cours : aiguilles = heure actuelle ; secteur coloré = temps restant (sur 12 h, plein au-delà). */
            function dessinerCompte() {
                if (!compte) return;
                var t = maintenant(), reste = Number(compte.dataset.jours) * 86400 + Number(compte.dataset.debut) - t;
                var jour = t % 86400;
                aiguille(compteAig.h, (jour % 43200) / 43200 * 2 * Math.PI, 48);
                aiguille(compteAig.m, (jour % 3600) / 3600 * 2 * Math.PI, 70);
                aiguille(compteAig.s, (jour % 60) / 60 * 2 * Math.PI, 80);
                compteHeure.textContent = hm(jour);
                if (reste <= 0) { compteSecteur.setAttribute('d', ''); compteReste.textContent = 'en cours'; return; }
                var k = Math.min(1, reste / 43200);
                if (k >= 0.9999) compteSecteur.setAttribute('d', 'M0,-' + R + ' A' + R + ',' + R + ' 0 1,1 0,' + R + ' A' + R + ',' + R + ' 0 1,1 0,-' + R + ' Z');
                else {
                    var fleche = k * 2 * Math.PI;
                    compteSecteur.setAttribute('d', 'M0,0 L0,-' + R + ' A' + R + ',' + R + ' 0 ' + (k > 0.5 ? 1 : 0) + ',1 ' +
                        (R * Math.sin(fleche)).toFixed(2) + ',' + (-R * Math.cos(fleche)).toFixed(2) + ' Z');
                }
                compteReste.textContent = reste >= 86400 ? Math.floor(reste / 86400) + ' j ' + Math.floor(reste % 86400 / 3600) + ' h'
                    : reste >= 3600 ? Math.floor(reste / 3600) + ' h ' + String(Math.floor(reste % 3600 / 60)).padStart(2, '0') : Math.floor(reste / 60) + ' min';
            }
            dessinerCompte();
            setInterval(dessinerCompte, 1000);

            /* Alerte : une classe des cours du jour dont les noms ne sont pas chargés sur cet ordinateur. */
            var classes = JSON.parse(document.getElementById('json-classes-cours').textContent);
            function verifierNoms() {
                if (!window.CentraleNoms) return;
                var manque = classes.filter(function (c) { return Object.keys(CentraleNoms.carte(c.nom)).length === 0; });
                alerte.hidden = manque.length === 0;
                liste.replaceChildren();
                manque.forEach(function (c) {
                    var li = document.createElement('li'), lien = document.createElement('a');
                    lien.href = './?p=classe&id=' + c.id;
                    lien.innerHTML = c.html + ' <span>Charger les noms →</span>';
                    li.appendChild(lien);
                    liste.appendChild(li);
                });
            }
            window.addEventListener('load', verifierNoms);
            document.addEventListener('centrale-noms', verifierNoms);

            /* Panneau : une section est sortie de la réserve et posée dans le panneau, sans défilement. */
            function fermer() {
                if (!ouvert) return;
                rentrer(ouvert);
                ouvert = null;
                panneau.hidden = true;
            }
            function ouvrir(id, libelle) {
                var el = document.getElementById(id);
                if (!el) return;
                if (ouvert === el) { fermer(); return; }
                fermer();
                sortir(el);
                corps.appendChild(el);
                ouvert = el;
                titre.textContent = libelle;
                panneau.hidden = false;
                corps.scrollTop = 0;
                document.dispatchEvent(new Event('centrale-vue'));
            }
            document.querySelectorAll('[data-panneau]').forEach(function (b) {
                b.addEventListener('click', function () { ouvrir(b.dataset.panneau, b.dataset.titre); });
            });
            document.getElementById('dash-fermer').addEventListener('click', fermer);
            document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') fermer(); });

            /* Voir une classe : menu des plans (un par classe, salle et groupe). Les versions du plan choisi
               sont dans le sous-menu « Versions » ; chaque plan se rend à la demande (une fois). */
            var choixBouton = document.getElementById('choix-bouton'), choixListe = document.getElementById('choix-liste'), choixCourant = document.getElementById('choix-courant');
            var choixVersions = document.getElementById('choix-versions'), choixVersionsListe = document.getElementById('choix-versions-liste');
            if (choixBouton) {
                var plansClasses = JSON.parse(document.getElementById('json-plans-classes').textContent), blocs = {}, valeurCourante = null;
                var videTitre = vide.querySelector('.dash-vide-titre'), videTitreDefaut = videTitre.textContent, libelleDefaut = choixCourant.textContent;
                // un bloc de plan par classe et par version (cle « live » = plan en cours)
                function blocPour(valeur, cle, donnees) {
                    var id = valeur + ':' + cle;
                    if (!blocs[id]) {
                        var bloc = document.createElement('div');
                        bloc.className = 'ec-plan';
                        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                        svg.setAttribute('class', 'plan-svg apercu-plan');
                        bloc.appendChild(svg);
                        document.getElementById('dash-stock').appendChild(bloc);
                        bloc._donnees = donnees;
                        if (window.PlanSalle) PlanSalle.apercu(svg, donnees);
                        blocs[id] = bloc;
                    }
                    return blocs[id];
                }
                function ajouterVersion(id, nom, cle, date, applique) {
                    var li = document.createElement('li');
                    li.setAttribute('role', 'option');
                    li.dataset.cle = id;
                    li.setAttribute('aria-selected', String(id === cle));
                    li.textContent = nom;
                    if (date) {
                        var quand = document.createElement('span');
                        quand.className = 'choix-versions-date';
                        quand.textContent = date;
                        li.appendChild(quand);
                    }
                    if (applique) {
                        var marque = document.createElement('span');
                        marque.className = 'choix-versions-applique';
                        marque.textContent = 'appliqué';
                        li.appendChild(marque);
                    }
                    choixVersionsListe.appendChild(li);
                }
                // sous-menu « Versions » : le plan en cours, puis les versions (la plus récente d'abord)
                function sousMenu(p, cle) {
                    choixVersionsListe.textContent = '';
                    choixVersions.hidden = !p.versions.length;
                    if (p.donnees && p.applique === 'live') ajouterVersion('live', 'Plan en cours', cle, '', true);
                    for (var i = p.versions.length - 1; i >= 0; i--) ajouterVersion(String(i), p.versions[i].nom, cle, p.versions[i].cree_le, p.applique === String(i));
                }
                function montrerChoix(valeur, cle) {
                    var p = plansClasses[valeur];
                    var donnees = cle === 'live' ? p.donnees : p.versions[cle].donnees;
                    choixCourant.innerHTML = p.pastille;
                    choixCourant.append(' ' + (cle === 'live' ? p.libelle : p.libelle + ' · ' + p.versions[cle].nom));
                    sousMenu(p, cle);
                    if (!donnees) {   // classe sans plan de classe posé
                        montrerPlan(null);
                        videTitre.textContent = p.classe + ' n’a pas encore de plan de classe.';
                        return;
                    }
                    montrerPlan(blocPour(valeur, cle, donnees));
                }
                function choisir(valeur) {
                    choixListe.hidden = true;
                    choixBouton.setAttribute('aria-expanded', 'false');
                    if (valeur === '') {
                        valeurCourante = null;
                        choixCourant.textContent = libelleDefaut;
                        choixVersions.hidden = true;
                        montrerPlan(planParDefaut());
                        videTitre.textContent = videTitreDefaut;
                        return;
                    }
                    var p = plansClasses[valeur];
                    valeurCourante = valeur;
                    montrerChoix(valeur, p.donnees || !p.versions.length ? 'live' : String(p.versions.length - 1));
                }
                choixBouton.addEventListener('click', function () {
                    var ouvrir = choixListe.hidden;
                    choixListe.hidden = !ouvrir;
                    choixBouton.setAttribute('aria-expanded', String(ouvrir));
                });
                choixListe.addEventListener('click', function (ev) {
                    var li = ev.target.closest('li[data-valeur]');
                    if (li) choisir(li.dataset.valeur);
                });
                choixVersionsListe.addEventListener('click', function (ev) {
                    var li = ev.target.closest('li[data-cle]');
                    if (li && valeurCourante !== null) montrerChoix(valeurCourante, li.dataset.cle);
                });
                document.addEventListener('click', function (ev) {
                    if (!ev.target.closest('#choix-classe')) { choixListe.hidden = true; choixBouton.setAttribute('aria-expanded', 'false'); }
                });
            }

            /* Sur grand écran, la colonne de l'horloge se place juste sous le compte à rebours (dz-bas-centre), sans le recouvrir. */
            var colonneHorloge = document.querySelector('.dz-haut-droite'), blocCompte = document.querySelector('.dz-bas-centre');
            function placerHorloge() {
                if (!colonneHorloge || !blocCompte) return;
                if (!window.matchMedia('(min-width: 721px)').matches) { colonneHorloge.style.top = ''; return; }
                var conteneur = colonneHorloge.parentNode.getBoundingClientRect();
                colonneHorloge.style.top = (blocCompte.getBoundingClientRect().bottom - conteneur.top + 12) + 'px';
            }
            placerHorloge();
            window.addEventListener('resize', placerHorloge);
            window.addEventListener('load', placerHorloge);

            montrerPlan(planParDefaut());
        })();
        </script>
    </main>
    <?php
    pied();
}

/** « Aujourd'hui », « Demain », « Jeudi 5 nov. » */
function quand_seance(string $date, string $aujourdhui): string
{
    if ($date === $aujourdhui) {
        return 'Aujourd’hui';
    }
    if ($date === ajouter_jours($aujourdhui, 1)) {
        return 'Demain';
    }
    return date_lisible($date);
}

/** Semaine de cours, tous établissements : une colonne par jour, les cours placés à leur heure réelle. */
function section_calendrier(string $lundi, array $sem, array $etabs, string $aujourdhui, string $heure): void
{
    $nb = $sem['samedi'] ? 6 : 5;
    $jours = array_map(fn($i) => ajouter_jours($lundi, $i), range(0, $nb - 1));
    // Plage horaire : du premier au dernier créneau de tous les établissements, à l'heure pleine
    $bornes = db()->query('SELECT MIN(debut), MAX(fin) FROM creneaux')->fetch(PDO::FETCH_NUM);
    $debut = (int) floor(minutes($bornes[0] ?: '08:00') / 60) * 60;
    $fin = (int) ceil(minutes($bornes[1] ?: '17:30') / 60) * 60;
    $duree = max(60, $fin - $debut);
    $px = 1.05;   // pixels par minute
    $par_jour = [];
    foreach ($sem['seances'] as $s) {
        $par_jour[$s['date']][] = $s;
    }
    $ab = [];
    foreach ($etabs as $e) {
        if ($e['ab_actif'] && ($x = semaine_ab($e, $lundi)) !== '') {
            $ab[] = h($e['nom']) . ' : semaine ' . h($x);
        }
    }
    ?>
    <section class="carte calendrier" id="calendrier">
        <div class="titre-section">
            <h2>Semaine du <?= h(date_lisible($lundi, false)) ?></h2>
            <div class="boutons">
                <a class="btn petit secondaire" href="./?semaine=<?= h(ajouter_jours($lundi, -7)) ?>#calendrier" aria-label="Semaine précédente">◀</a>
                <?php if ($lundi !== lundi_de($aujourdhui)): ?><a class="btn petit secondaire" href="./#calendrier">Cette semaine</a><?php endif; ?>
                <a class="btn petit secondaire" href="./?semaine=<?= h(ajouter_jours($lundi, 7)) ?>#calendrier" aria-label="Semaine suivante">▶</a>
                <a class="btn petit secondaire" href="./?p=edt">Modifier l’emploi du temps</a>
            </div>
        </div>
        <p class="legende-etabs">
            <?php foreach ($etabs as $e): ?>
                <span><span class="puce-etab" style="--etab:<?= h(couleur_etablissement((int) $e['id'])) ?>"></span><?= h($e['nom']) ?></span>
            <?php endforeach; ?>
            <?php if ($ab): ?><span class="discret"><?= implode(' · ', $ab) ?></span><?php endif; ?>
        </p>
        <div class="cal" style="--jours:<?= $nb ?>;--hauteur:<?= (int) round($duree * $px) ?>px">
            <div class="cal-heures">
                <?php for ($m = $debut; $m <= $fin; $m += 60): ?>
                    <span style="top:<?= round(($m - $debut) * $px) ?>px"><?= sprintf('%02dh', intdiv($m, 60)) ?></span>
                <?php endfor; ?>
            </div>
            <?php foreach ($jours as $d):
                $vac = $sem['vacances'][$d] ?? [];
                $tout_vac = $vac && count($vac) === count($etabs); ?>
                <div class="cal-jour<?= $d === $aujourdhui ? ' aujourdhui' : '' ?><?= $tout_vac ? ' vacances' : '' ?>">
                    <div class="cal-tete"><?= h(JOURS_COURTS[jour_semaine($d)]) ?> <strong><?= (int) substr($d, 8, 2) ?></strong></div>
                    <div class="cal-corps">
                        <?php for ($m = $debut; $m <= $fin; $m += 60): ?><i class="ligne" style="top:<?= round(($m - $debut) * $px) ?>px"></i><?php endfor; ?>
                        <?php if ($vac): ?>
                            <div class="cal-vacances"><?= h(implode(' · ', array_unique($vac))) ?><?= !$tout_vac ? '<br><small>' . h(implode(', ', array_map(fn($id) => etablissement_par_id($id)['nom'] ?? '', array_keys($vac)))) . '</small>' : '' ?></div>
                        <?php endif; ?>
                        <?php if ($d === $aujourdhui && minutes($heure) >= $debut && minutes($heure) <= $fin): ?>
                            <i class="maintenant" style="top:<?= round((minutes($heure) - $debut) * $px) ?>px"></i>
                        <?php endif; ?>
                        <?php
                        // Cours qui se chevauchent (deux établissements, ou une classe et un groupe) : côte à côte
                        $liste = $par_jour[$d] ?? [];
                        $voies = [];
                        foreach ($liste as $k => $s) {
                            $v = 0;
                            while (isset($voies[$v]) && $voies[$v] > minutes($s['debut'])) {
                                $v++;
                            }
                            $voies[$v] = minutes($s['fin']);
                            $liste[$k]['voie'] = $v;
                        }
                        $nb_voies = max(1, count($voies));
                        foreach ($liste as $s):
                            $haut = (minutes($s['debut']) - $debut) * $px;
                            $h = max(22, (minutes($s['fin']) - minutes($s['debut'])) * $px - 2); ?>
                            <a class="cal-cours st-<?= h($s['statut']) ?>" style="top:<?= round($haut) ?>px;height:<?= round($h) ?>px;--etab:<?= h(couleur_etablissement($s['etablissement_id'])) ?>;left:calc(<?= $s['voie'] ?> * 100% / <?= $nb_voies ?>);width:calc(100% / <?= $nb_voies ?> - 3px)"
                               href="./?p=edt&etab=<?= (int) $s['etablissement_id'] ?>&semaine=<?= h($lundi) ?>&seance=<?= h($s['cle']) ?>#panneau"
                               title="<?= h($s['classe'] . ' · ' . $s['debut'] . '–' . $s['fin'] . ($s['salle'] ? ' · ' . $s['salle'] : '') . ' · ' . $s['etablissement'] . ($s['statut'] === 'annule' ? ' · ANNULÉ' : '') . ($s['note'] ? ' — ' . $s['note'] : '')) ?>">
                                <?= pastille_classe($s['classe_id'], groupe_seance($s)) ?>
                                <span class="heure"><?= h($s['debut']) ?></span>
                                <?php if ($s['salle']): ?><span class="salle"><?= h($s['salle']) ?></span><?php endif; ?>
                                <?php if ($s['statut'] === 'annule'): ?><em>Annulé</em><?php elseif ($s['statut'] !== 'normal'): ?><em><?= $s['statut'] === 'ajoute' ? 'Ajouté' : 'Modifié' ?></em><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

/** Récapitulatif des connexions : maintenant, aujourd'hui, sur 7 jours, par classe, par atelier, les dernières. */
function section_connexions_accueil(): void
{
    $pdo = db();
    $minuit = (new DateTimeImmutable('today', fuseau()))->getTimestamp();
    $q = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $st = $pdo->prepare($sql); $st->execute($p); return $st; })();
    $heure = (int) $q('SELECT COUNT(DISTINCT eleve_id) FROM connexions WHERE ts >= ?', [time() - 3600])->fetchColumn();
    $jour = (int) $q('SELECT COUNT(DISTINCT eleve_id) FROM connexions WHERE ts >= ?', [$minuit])->fetchColumn();
    $sessions = (int) $q('SELECT COUNT(*) FROM sessions WHERE expire > ?', [time()])->fetchColumn();
    $semaine = (int) $q('SELECT COUNT(DISTINCT eleve_id) FROM connexions WHERE ts >= ?', [$minuit - 6 * 86400])->fetchColumn();
    $total = (int) $pdo->query('SELECT COUNT(*) FROM eleves WHERE actif = 1')->fetchColumn();
    // 7 derniers jours : élèves différents par jour
    $jours = [];
    for ($i = 6; $i >= 0; $i--) {
        $a = $minuit - $i * 86400;
        $jours[] = ['date' => date('Y-m-d', $a), 'n' => (int) $q('SELECT COUNT(DISTINCT eleve_id) FROM connexions WHERE ts >= ? AND ts < ?', [$a, $a + 86400])->fetchColumn()];
    }
    $max = max(1, ...array_column($jours, 'n'));
    $classes = $q('SELECT c.id, c.nom, c.etablissement_id, COUNT(DISTINCT x.eleve_id) AS n,
                       (SELECT COUNT(*) FROM eleves e2 WHERE e2.classe_id = c.id AND e2.actif = 1) AS total
                   FROM connexions x JOIN eleves e ON e.id = x.eleve_id JOIN classes c ON c.id = e.classe_id
                   WHERE x.ts >= ? GROUP BY c.id ORDER BY n DESC, c.nom', [$minuit - 6 * 86400])->fetchAll();
    $apps = $q('SELECT x.app, COUNT(DISTINCT x.eleve_id) AS n, COUNT(*) AS ouvertures, a.nom, a.icone
                FROM connexions x LEFT JOIN ateliers a ON a.app = x.app WHERE x.ts >= ? GROUP BY x.app ORDER BY n DESC', [$minuit - 6 * 86400])->fetchAll();
    $dernieres = $pdo->query('SELECT x.ts, x.app, x.moyen, x.origine, e.etiquette, e.surnom, c.id AS classe_id, c.nom AS classe
                              FROM connexions x JOIN eleves e ON e.id = x.eleve_id JOIN classes c ON c.id = e.classe_id
                              ORDER BY x.ts DESC LIMIT 10')->fetchAll();
    ?>
    <section class="carte" id="connexions">
        <h2>Connexions des élèves</h2>
        <div class="chiffres">
            <div><strong><?= $heure ?></strong><span>depuis une heure</span></div>
            <div><strong><?= $jour ?></strong><span>aujourd’hui</span></div>
            <div><strong><?= $semaine ?><small> / <?= $total ?></small></strong><span>sur 7 jours</span></div>
            <div><strong><?= $sessions ?></strong><span>session<?= $sessions > 1 ? 's' : '' ?> ouverte<?= $sessions > 1 ? 's' : '' ?></span></div>
        </div>
        <div class="histo" role="img" aria-label="Élèves connectés chaque jour sur 7 jours">
            <?php foreach ($jours as $j): ?>
                <div class="histo-barre" title="<?= h(date_lisible($j['date'])) ?> : <?= $j['n'] ?> élève<?= $j['n'] > 1 ? 's' : '' ?>">
                    <span class="valeur"><?= $j['n'] ?: '' ?></span>
                    <i style="height:<?= round($j['n'] / $max * 100) ?>%"></i>
                    <span class="jour"><?= h(JOURS_COURTS[jour_semaine($j['date'])] ?? 'Dim') ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($classes): ?>
            <h3>Par classe (7 jours)</h3>
            <ul class="par-classe">
                <?php foreach ($classes as $c): $pct = $c['total'] ? round($c['n'] / $c['total'] * 100) : 0; ?>
                    <li><a href="./?p=classe&id=<?= (int) $c['id'] ?>"><?= pastille_classe($c) ?></a>
                        <span class="jauge-classe"><i style="width:<?= $pct ?>%"></i></span>
                        <span class="discret"><?= (int) $c['n'] ?> / <?= (int) $c['total'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($apps): ?>
            <h3>Par atelier (7 jours)</h3>
            <p class="par-atelier">
                <?php foreach ($apps as $a): ?>
                    <span><?= h($a['icone'] ?: '🧩') ?> <?= h($a['nom'] ?: $a['app']) ?> <strong><?= (int) $a['n'] ?></strong> élève<?= $a['n'] > 1 ? 's' : '' ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <h3>Dernières connexions</h3>
        <?php if (!$dernieres): ?>
            <p class="vide">Aucune connexion pour l’instant.</p>
        <?php else: ?>
            <table class="dernieres">
                <tbody>
                <?php foreach ($dernieres as $x): ?>
                    <tr>
                        <td class="discret"><?= h(quand((int) $x['ts'])) ?></td>
                        <td><?= pastille_classe((int) $x['classe_id']) ?> <span class="numero"><?= h($x['etiquette']) ?></span> <?= nom_eleve($x['classe'], $x['etiquette']) ?></td>
                        <td><?= h($x['app'] === 'portail' ? 'Portail' : $x['app']) ?> <span class="discret"><?= h($x['moyen'] === 'code' ? 'avec son code' : 'depuis le portail') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
}
