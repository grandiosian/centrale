<?php
/**
 * Centrale élèves — espace enseignant : tableau de suivi et parcours.
 * Inclus par public/admin/index.php (utilise ses fonctions d'affichage).
 */
declare(strict_types=1);

/** Traite les formulaires liés aux parcours. Renvoie false si l'action ne concerne pas ce fichier. */
function action_suivi(string $action): bool
{
    $pdo = db();
    switch ($action) {
        case 'enregistrer_parcours':
            $id = (int) ($_POST['parcours_id'] ?? 0);
            $nom = mb_substr(trim((string) ($_POST['nom'] ?? '')), 0, 80);
            $description = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
            $classes = implode(',', array_filter(array_map('trim', explode(',', (string) ($_POST['classes'] ?? '')))));
            $mode = isset(MODES_PARCOURS[$_POST['mode'] ?? '']) ? $_POST['mode'] : 'individuel';
            $deblocage = isset(DEBLOCAGES[$_POST['deblocage'] ?? '']) ? $_POST['deblocage'] : 'libre';
            [$etapes, $erreurs] = lire_etapes((string) ($_POST['etapes'] ?? ''));
            if ($nom === '') {
                $erreurs[] = 'Donnez un nom au parcours.';
            }
            if (!$etapes) {
                $erreurs[] = 'Ajoutez au moins une étape.';
            }
            if ($erreurs) {
                $_SESSION['brouillon_parcours'] = $_POST;
                foreach ($erreurs as $e) {
                    flash($e, 'err');
                }
                rediriger('./?p=parcours' . ($id ? '&modifier=' . $id : '&nouveau=1'));
            }
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE parcours SET nom = ?, description = ?, classes = ?, mode = ?, deblocage = ? WHERE id = ?')
                    ->execute([$nom, $description, $classes, $mode, $deblocage, $id]);
                $pdo->prepare('DELETE FROM parcours_etapes WHERE parcours_id = ?')->execute([$id]);
            } else {
                $pdo->prepare('INSERT INTO parcours (nom, description, classes, mode, deblocage, cree_le) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$nom, $description, $classes, $mode, $deblocage, time()]);
                $id = (int) $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO parcours_etapes (parcours_id, ordre, titre, app, activite, url, consigne, entrees, sorties, competences) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($etapes as $i => $et) {
                $ins->execute([$id, $i, $et['titre'], $et['app'], $et['activite'], $et['url'], $et['consigne'], $et['entrees'], $et['sorties'], $et['competences']]);
            }
            $pdo->commit();
            flash("Parcours « $nom » enregistré (" . count($etapes) . ' étapes).');
            rediriger('./?p=parcours&modifier=' . $id . '#fournis');

        case 'ajouter_fourni':
            $id = (int) ($_POST['parcours_id'] ?? 0);
            $envoi = $_FILES['fichier'] ?? null;
            if (!parcours_par_id($id) || !$envoi || is_array($envoi['error']) || $envoi['error'] !== UPLOAD_ERR_OK) {
                flash('Envoi du fichier impossible (trop gros ou absent).', 'err');
                rediriger('./?p=parcours&modifier=' . $id . '#fournis');
            }
            $nom = nom_fichier_propre((string) ($_POST['nom'] ?? '') ?: (string) $envoi['name']);
            $type = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string) $envoi['type']) ? (string) $envoi['type'] : 'application/octet-stream';
            $st = $pdo->prepare('SELECT chemin FROM parcours_fournis WHERE parcours_id = ? AND nom = ?');
            $st->execute([$id, $nom]);
            $ancien = $st->fetchColumn();
            $chemin = ranger_fichier($envoi['tmp_name'], 'fournis/' . $id);
            $pdo->prepare('INSERT INTO parcours_fournis (parcours_id, nom, chemin, taille, type, maj) VALUES (?, ?, ?, ?, ?, ?)
                           ON CONFLICT (parcours_id, nom) DO UPDATE SET chemin = excluded.chemin, taille = excluded.taille, type = excluded.type, maj = excluded.maj')
                ->execute([$id, $nom, $chemin, (int) filesize(chemin_stockage($chemin)), $type, time()]);
            if ($ancien) {
                effacer_fichier_stocke($ancien);
            }
            flash("Fichier « $nom » fourni aux élèves de ce parcours.");
            rediriger('./?p=parcours&modifier=' . $id . '#fournis');

        case 'supprimer_fourni':
            $id = (int) ($_POST['parcours_id'] ?? 0);
            $st = $pdo->prepare('SELECT id, chemin FROM parcours_fournis WHERE id = ? AND parcours_id = ?');
            $st->execute([(int) ($_POST['fourni_id'] ?? 0), $id]);
            if ($f = $st->fetch()) {
                $pdo->prepare('DELETE FROM parcours_fournis WHERE id = ?')->execute([$f['id']]);
                effacer_fichier_stocke($f['chemin']);
                flash('Fichier fourni retiré.');
            }
            rediriger('./?p=parcours&modifier=' . $id . '#fournis');

        case 'valider_etape':
            $id = (int) ($_POST['parcours_id'] ?? 0);
            $proprio = (string) ($_POST['proprietaire'] ?? '');
            $ordre = (int) ($_POST['ordre'] ?? -1);
            $notees = 0;
            if (preg_match('/^([eq])(\d+)$/', $proprio, $mp) && $ordre >= 0) {
                // Notes des compétences de l'étape (grille MI · MF · MS · TBM) : à l'élève, ou à chaque membre de l'équipe
                $p = parcours_par_id($id);
                $codes = liste_codes_competences((string) ($p['etapes'][$ordre]['competences'] ?? ''));
                $notes = array_intersect_key(array_map('intval', (array) ($_POST['notes'] ?? [])), array_flip($codes));
                if ($notes) {
                    if ($mp[1] === 'e') {
                        $membres = [(int) $mp[2]];
                    } else {
                        $st = $pdo->prepare('SELECT id FROM eleves WHERE equipe_id = ? AND actif = 1');
                        $st->execute([(int) $mp[2]]);
                        $membres = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                    }
                    $deja = notes_etape($id, $ordre, $membres);
                    foreach ($notes as $code => $niveau) {
                        if (($deja[$code] ?? 0) !== $niveau) {   // on n'empile pas deux fois la même note
                            noter_competence($membres, $code, $niveau, $id, $ordre);
                        }
                        $notees++;
                    }
                }
                if (!empty($_POST['annuler'])) {
                    $pdo->prepare('DELETE FROM parcours_validations WHERE parcours_id = ? AND proprietaire = ? AND ordre = ?')->execute([$id, $proprio, $ordre]);
                } elseif (empty($_POST['noter_seulement'])) {
                    $pdo->prepare('INSERT OR REPLACE INTO parcours_validations (parcours_id, proprietaire, ordre, valide_le) VALUES (?, ?, ?, ?)')
                        ->execute([$id, $proprio, $ordre, time()]);
                }
            }
            if (($_POST['retour'] ?? '') === 'a_valider') {   // depuis la liste « À valider / À évaluer »
                $n = $notees ? " ($notees compétence" . ($notees > 1 ? 's notées' : ' notée') . ')' : '';
                flash(empty($_POST['noter_seulement']) ? "Étape validée$n : l’étape suivante est ouverte." : "Évaluation enregistrée$n.");
                rediriger('./?p=projets&parcours=' . $id . '&vue=a_valider');
            }
            rediriger('./?p=projets&parcours=' . $id . '&classe=' . (int) ($_POST['classe_id'] ?? 0) . '#' . $proprio);

        case 'supprimer_parcours':
            $id = (int) ($_POST['parcours_id'] ?? 0);
            supprimer_fichiers_projet('parcours_id = ?', [$id]);
            $st = $pdo->prepare('SELECT chemin FROM parcours_fournis WHERE parcours_id = ?');
            $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
                effacer_fichier_stocke($c);
            }
            $pdo->prepare('DELETE FROM parcours WHERE id = ?')->execute([$id]);
            flash('Parcours supprimé (la progression des élèves est conservée).');
            rediriger('./?p=parcours');

        case 'enregistrer_atelier':
            $id = (int) ($_POST['atelier_id'] ?? 0);
            $app = normaliser_app((string) ($_POST['app'] ?? ''));
            $nom = mb_substr(trim((string) ($_POST['nom'] ?? '')), 0, 80);
            $url = trim((string) ($_POST['url'] ?? ''));
            $erreurs = [];
            if ($app === '*') {
                $erreurs[] = "Identifiant de l'atelier manquant : c'est le nom donné à app dans Centrale.init (ex. netsim).";
            }
            if ($nom === '') {
                $erreurs[] = "Donnez un nom à l'atelier.";
            }
            if (!preg_match('#^https?://\S+$#', $url)) {
                $erreurs[] = "L'adresse doit commencer par https://";
            }
            $secours = [];
            foreach (preg_split('/\R/', (string) ($_POST['urls_secours'] ?? '')) ?: [] as $u) {
                $u = trim($u);
                if ($u === '') {
                    continue;
                }
                if (!preg_match('#^https?://\S+$#', $u)) {
                    $erreurs[] = "Adresse de secours invalide : $u";
                } elseif ($u !== $url) {
                    $secours[] = $u;
                }
            }
            if ($erreurs) {
                $_SESSION['brouillon_atelier'] = $_POST;
                foreach ($erreurs as $e) {
                    flash($e, 'err');
                }
                rediriger('./?p=ateliers' . ($id ? '&modifier=' . $id : '&nouveau=1'));
            }
            $champs = [$app, $nom, mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 300), $url,
                mb_substr(trim((string) ($_POST['icone'] ?? '')), 0, 4),
                implode(',', array_filter(array_map('trim', explode(',', (string) ($_POST['classes'] ?? ''))))),
                (int) ($_POST['ordre'] ?? 0), empty($_POST['actif']) ? 0 : 1, implode("\n", array_unique($secours))];
            try {
                if ($id) {
                    $pdo->prepare('UPDATE ateliers SET app = ?, nom = ?, description = ?, url = ?, icone = ?, classes = ?, ordre = ?, actif = ?, urls_secours = ? WHERE id = ?')
                        ->execute([...$champs, $id]);
                } else {
                    $pdo->prepare('INSERT INTO ateliers (app, nom, description, url, icone, classes, ordre, actif, urls_secours) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute($champs);
                }
            } catch (PDOException) {
                $_SESSION['brouillon_atelier'] = $_POST;
                flash("Un atelier utilise déjà l'identifiant « $app ».", 'err');
                rediriger('./?p=ateliers' . ($id ? '&modifier=' . $id : '&nouveau=1'));
            }
            flash("Atelier « $nom » enregistré.");
            rediriger('./?p=ateliers');

        case 'lire_passerelle':
            $id = (int) ($_POST['atelier_id'] ?? 0);
            $adresse = trim((string) ($_POST['adresse'] ?? ''));
            if ($id) {
                $st = $pdo->prepare('SELECT * FROM ateliers WHERE id = ?');
                $st->execute([$id]);
                $a = $st->fetch();
                $adresse = $a ? ($a['descripteur_url'] ?: $a['url']) : '';
            }
            [$d, $url_d, $erreur] = lire_passerelle_distante($adresse);
            if (!$d) {
                flash($erreur, 'err');
                rediriger('./?p=ateliers' . ($id ? '' : '&nouveau=1'));
            }
            [$err, $av] = verifier_descripteur($d);
            if ($err) {
                flash('Ce centrale.json ne suit pas le format passerelle.atelier v1 : ' . implode(' ', array_slice($err, 0, 4)), 'err');
                rediriger('./?p=ateliers' . ($id ? '' : '&nouveau=1'));
            }
            if ($id && $a && $a['app'] !== $d['atelier']['app']) {
                flash("Ce descripteur décrit l'atelier « {$d['atelier']['app']} », pas « {$a['app']} ». Rien n'a été changé.", 'err');
                rediriger('./?p=ateliers');
            }
            [$app, $cree, $n] = enregistrer_descripteur($d, $url_d);
            flash(($cree ? "Atelier « {$d['atelier']['nom']} » ajouté au portail" : "Passerelle de « {$d['atelier']['nom']} » relue")
                . (isset($d['_converti']) ? ' (fiche tirée de son manifeste postMessage).' : " : $n activité" . ($n > 1 ? 's' : '') . ' déclarée' . ($n > 1 ? 's' : '') . '.')
                . ($av && !isset($d['_converti']) ? ' ' . count($av) . ' remarque' . (count($av) > 1 ? 's' : '') . ' sur le descripteur (voir la fiche).' : ''));
            rediriger('./?p=passerelle&app=' . rawurlencode($app));

        case 'autoriser_site':
            $site = rtrim(trim((string) ($_POST['site'] ?? '')), '/');
            if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $site)) {
                $liste = origines_autorisees();
                if (!in_array($site, $liste, true)) {
                    definir_reglage('origines', implode("\n", [...$liste, $site]));
                }
                flash("Site $site autorisé.");
            }
            rediriger('./?p=passerelle&app=' . rawurlencode(normaliser_app((string) ($_POST['app'] ?? ''))));

        case 'oublier_passerelle':
            $pdo->prepare("UPDATE ateliers SET descripteur = '', descripteur_url = '', descripteur_lu = 0 WHERE id = ?")
                ->execute([(int) ($_POST['atelier_id'] ?? 0)]);
            flash('Descripteur oublié (la fiche de l’atelier et ses activités sont gardées).');
            rediriger('./?p=ateliers');

        case 'supprimer_atelier':
            $pdo->prepare('DELETE FROM ateliers WHERE id = ?')->execute([(int) ($_POST['atelier_id'] ?? 0)]);
            flash("Atelier retiré du portail (le travail et la progression des élèves sont conservés).");
            rediriger('./?p=ateliers');

        case 'oublier_catalogue':
            $app = normaliser_app((string) ($_POST['app'] ?? ''));
            $pdo->prepare('DELETE FROM activites WHERE app = ?')->execute([$app]);
            $pdo->prepare('DELETE FROM reglages WHERE cle = ?')->execute(['catalogue:' . $app]);
            flash("Liste des activités de « $app » effacée. L'application la renverra à la prochaine connexion d'un élève.");
            rediriger('./?p=suivi');
    }
    return false;
}

/**
 * Lit les étapes d'un parcours, une par ligne :  Titre | atelier | activité (facultatif) | adresse (facultatif)
 * Renvoie [étapes, erreurs].
 */
function lire_etapes(string $texte): array
{
    $etapes = [];
    $erreurs = [];
    foreach (preg_split('/\R/', $texte) ?: [] as $n => $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '' || str_starts_with($ligne, '#')) {
            continue;
        }
        // Lignes de détail sous une étape : « > consigne », « reçoit : a.json », « produit : b.glb »
        $dernier = count($etapes) - 1;
        if (str_starts_with($ligne, '>')) {
            if ($dernier >= 0) {
                $etapes[$dernier]['consigne'] = trim($etapes[$dernier]['consigne'] . "\n" . trim(substr($ligne, 1)));
            }
            continue;
        }
        if (preg_match('/^(re[çc]oit|lit|entr[ée]es?)\s*:\s*(.*)$/iu', $ligne, $m)) {
            if ($dernier >= 0) {
                $etapes[$dernier]['entrees'] = implode(', ', liste_noms($m[2]));
            }
            continue;
        }
        if (preg_match('/^(produit|d[ée]pose|sorties?)\s*:\s*(.*)$/iu', $ligne, $m)) {
            if ($dernier >= 0) {
                $etapes[$dernier]['sorties'] = implode(', ', liste_noms($m[2]));
            }
            continue;
        }
        if (preg_match('/^(comp[ée]tences?|[ée]value)\s*:\s*(.*)$/iu', $ligne, $m)) {
            if ($dernier >= 0) {
                $etapes[$dernier]['competences'] = implode(', ', liste_codes_competences($m[2]));
            }
            continue;
        }
        $champs = array_map('trim', explode('|', $ligne));
        $titre = mb_substr($champs[0] ?? '', 0, 120);
        $app = normaliser_app($champs[1] ?? '');
        $activite = activite_propre($champs[2] ?? '');
        $url = $champs[3] ?? '';
        if ($titre === '' || $app === '*') {
            $erreurs[] = 'Ligne ' . ($n + 1) . ' : il faut au moins « Titre | atelier ».';
            continue;
        }
        if ($url !== '' && !preg_match('#^https?://\S+$#', $url)) {
            $erreurs[] = 'Ligne ' . ($n + 1) . ' : l\'adresse doit commencer par https://';
            continue;
        }
        $etapes[] = ['titre' => $titre, 'app' => $app, 'activite' => $activite, 'url' => $url, 'consigne' => '', 'entrees' => '', 'sorties' => '', 'competences' => ''];
    }
    if (count($etapes) > 100) {
        $erreurs[] = '100 étapes maximum.';
    }
    return [$etapes, $erreurs];
}

function etapes_en_texte(array $etapes): string
{
    $lignes = [];
    foreach ($etapes as $e) {
        $lignes[] = rtrim(implode(' | ', [$e['titre'], $e['app'], $e['activite'], $e['url']]), ' |');
        foreach (array_filter(explode("\n", (string) ($e['consigne'] ?? ''))) as $c) {
            $lignes[] = '  > ' . $c;
        }
        if (($e['entrees'] ?? '') !== '') {
            $lignes[] = '  reçoit : ' . $e['entrees'];
        }
        if (($e['sorties'] ?? '') !== '') {
            $lignes[] = '  produit : ' . $e['sorties'];
        }
        if (($e['competences'] ?? '') !== '') {
            $lignes[] = '  compétences : ' . $e['competences'];
        }
    }
    return implode("\n", $lignes);
}

/* ---------------------------------------------------------------------- */

function cellule_suivi(array $etat, string $titre): string
{
    $classes = ['a_faire' => 'st-a-faire', 'commence' => 'st-commence', 'termine' => 'st-termine'];
    $contenu = match ($etat['statut']) {
        'termine'  => '✓' . ($etat['texte'] !== '' && !preg_match('#^(\d+)/\1$#', $etat['texte']) ? ' <small>' . h($etat['texte']) . '</small>' : ''),
        'commence' => $etat['texte'] !== '' ? h($etat['texte']) : '…',
        default    => '',
    };
    $info = $titre . ' — ' . ['a_faire' => 'pas commencé', 'commence' => 'en cours', 'termine' => 'terminé'][$etat['statut']]
        . ($etat['texte'] !== '' ? ' (' . $etat['texte'] . ')' : '')
        . ($etat['maj'] ? ' · ' . date('d/m H:i', $etat['maj']) : '');
    $pct = (int) round($etat['taux'] * 100);
    return '<td class="cellule ' . $classes[$etat['statut']] . '" title="' . h($info) . '" data-taux="' . $pct . '"'
        . ($etat['statut'] === 'commence' ? ' style="--taux:' . $pct . '%"' : '') . '>' . $contenu . '</td>';
}

function page_suivi(): void
{
    $pdo = db();
    $classes = $pdo->query('SELECT id, nom FROM classes ORDER BY nom')->fetchAll();
    $catalogue = catalogue_activites();
    $apps_vues = $pdo->query('SELECT DISTINCT app FROM progression ORDER BY app')->fetchAll(PDO::FETCH_COLUMN);
    $apps = array_values(array_unique(array_merge(array_keys($catalogue), $apps_vues)));
    sort($apps);
    $tous_parcours = $pdo->query('SELECT id, nom FROM parcours ORDER BY nom')->fetchAll();

    $classe_id = (int) ($_GET['classe'] ?? ($classes[0]['id'] ?? 0));
    $vue = (string) ($_GET['vue'] ?? '');
    if ($vue === '') {
        $vue = $tous_parcours ? 'parcours:' . $tous_parcours[0]['id'] : ($apps ? 'app:' . $apps[0] : '');
    }

    entete('Suivi');
    barre('suivi');
    ?>
    <main class="large">
        <h1>Suivi de l'avancement</h1>
        <?php messages(); ?>
        <form method="get" class="filtres">
            <input type="hidden" name="p" value="suivi">
            <label>Classe
                <select name="classe" onchange="this.form.submit()">
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $c['id'] == $classe_id ? 'selected' : '' ?>><?= h($c['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php $groupes_suivi = $classe_id ? groupes_de($classe_id) : []; $groupe_id = (int) ($_GET['groupe'] ?? 0);
                  if (!in_array($groupe_id, array_map(fn($g) => (int) $g['id'], $groupes_suivi), true)) { $groupe_id = 0; } ?>
            <?php if ($groupes_suivi): ?>
                <label>Groupe
                    <select name="groupe" onchange="this.form.submit()">
                        <option value="0">Classe entière</option>
                        <?php foreach ($groupes_suivi as $g): ?>
                            <option value="<?= (int) $g['id'] ?>" <?= (int) $g['id'] === $groupe_id ? 'selected' : '' ?>><?= h($g['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label>Afficher
                <select name="vue" onchange="this.form.submit()">
                    <?php if ($tous_parcours): ?><optgroup label="Parcours">
                        <?php foreach ($tous_parcours as $p): ?>
                            <option value="parcours:<?= (int) $p['id'] ?>" <?= $vue === 'parcours:' . $p['id'] ? 'selected' : '' ?>><?= h($p['nom']) ?></option>
                        <?php endforeach; ?></optgroup><?php endif; ?>
                    <?php if ($apps): ?><optgroup label="Ateliers (toutes les activités)">
                        <?php foreach ($apps as $a): ?>
                            <option value="app:<?= h($a) ?>" <?= $vue === 'app:' . $a ? 'selected' : '' ?>><?= h($a) ?></option>
                        <?php endforeach; ?></optgroup><?php endif; ?>
                </select>
            </label>
            <noscript><button>Afficher</button></noscript>
        </form>
        <?php liste_locale(); ?>
        <?php
        $classe = $classe_id ? classe_par_id($classe_id) : null;
        if (!$classe || $vue === '') {
            echo '<p class="vide" style="margin-top:24px">Rien à afficher pour l\'instant : il faut une classe, et au moins une application '
                . 'qui envoie de la progression (ou un parcours).</p></main>';
            pied();
            return;
        }

        // Colonnes
        $colonnes = [];
        $parcours_vu = null;   // vue d'un parcours : mêmes états que le portail (fichiers déposés, équipe)
        if (str_starts_with($vue, 'parcours:')) {
            $parcours_vu = parcours_par_id((int) substr($vue, 9));
            foreach ($parcours_vu['etapes'] ?? [] as $et) {
                $colonnes[] = ['app' => $et['app'], 'activite' => $et['activite'], 'titre' => $et['titre'],
                               'groupe' => $et['app'], 'sous' => $et['activite'] !== '' ? $et['activite'] : 'tout l\'atelier'];
            }
        } else {
            $app = substr($vue, 4);
            foreach ($catalogue[$app] ?? [] as $a) {
                $colonnes[] = ['app' => $app, 'activite' => $a['activite'], 'titre' => $a['titre'] ?: $a['activite'],
                               'groupe' => $a['groupe'], 'sous' => ''];
            }
            // Activités commencées mais absentes du catalogue
            $st = $pdo->prepare('SELECT DISTINCT p.activite, p.titre FROM progression p JOIN eleves e ON e.id = p.eleve_id
                                 WHERE p.app = ? AND e.classe_id = ? ORDER BY p.debut');
            $st->execute([$app, $classe_id]);
            foreach ($st->fetchAll() as $a) {
                if (!isset($catalogue[$app][$a['activite']])) {
                    $colonnes[] = ['app' => $app, 'activite' => $a['activite'], 'titre' => $a['titre'] ?: $a['activite'],
                                   'groupe' => 'Autres', 'sous' => ''];
                }
            }
        }

        $st = $pdo->prepare("SELECT id, etiquette, actif FROM eleves WHERE classe_id = ?" . ($groupe_id ? ' AND groupe_id = ?' : '') . "
            ORDER BY CASE WHEN etiquette GLOB '[0-9]*' THEN CAST(etiquette AS INTEGER) ELSE 9999 END, etiquette");
        $st->execute($groupe_id ? [$classe_id, $groupe_id] : [$classe_id]);
        $eleves = $st->fetchAll();
        $prog = progression_eleves(array_map(fn($e) => (int) $e['id'], $eleves));

        // Groupes d'en-tête (ex. « 5e · Débutant », ou l'atelier pour un parcours)
        $groupes = [];
        foreach ($colonnes as $c) {
            $dernier = array_key_last($groupes);
            if ($dernier !== null && $groupes[$dernier]['nom'] === $c['groupe']) {
                $groupes[$dernier]['n']++;
            } else {
                $groupes[] = ['nom' => $c['groupe'], 'n' => 1];
            }
        }
        $avec_groupes = count(array_filter($groupes, fn($g) => $g['nom'] !== '')) > 0;
        $termines_col = array_fill(0, count($colonnes), 0);
        $total_classe = 0.0;
        ?>
        <?php if (!$colonnes): ?>
            <p class="vide" style="margin-top:24px">Aucune activité pour cette vue. Les colonnes apparaissent quand l'application déclare ses activités
                (<code>Centrale.init({ activites: […] })</code>) ou dès qu'un élève en commence une.</p>
        <?php elseif (!$eleves): ?>
            <p class="vide" style="margin-top:24px">Aucun élève dans cette classe.</p>
        <?php else: ?>
            <div class="titre-section" style="margin-top:20px">
                <p class="legende"><span class="pastille st-termine">✓</span> terminé
                    <span class="pastille st-commence" style="--taux:50%">3/5</span> en cours
                    <span class="pastille st-a-faire"></span> pas commencé</p>
                <button type="button" class="secondaire" id="exporter-suivi"
                        data-fichier="suivi_<?= h(prefixe_classe($classe['nom'])) ?>_<?= h(preg_replace('/[^a-z0-9-]/i', '-', $vue)) ?>">Exporter (CSV)</button>
            </div>
            <div class="defile suivi"><table id="table-suivi">
                <thead>
                <?php if ($avec_groupes): ?>
                    <tr class="groupes"><th colspan="2"></th>
                        <?php foreach ($groupes as $g): ?><th colspan="<?= $g['n'] ?>"><?= h($g['nom']) ?></th><?php endforeach; ?>
                        <th colspan="2"></th></tr>
                <?php endif; ?>
                <tr>
                    <th class="fixe">N°</th><th class="fixe2">Élève</th>
                    <?php foreach ($colonnes as $c): ?>
                        <th class="col-act" title="<?= h($c['titre'] . ($c['sous'] ? ' (' . $c['app'] . ' · ' . $c['sous'] . ')' : '')) ?>"><span><?= h($c['titre']) ?></span></th>
                    <?php endforeach; ?>
                    <th>Avancement</th><th>Dernière activité</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($eleves as $e): $somme = 0.0; $derniere = 0;
                    $etats = null;
                    if ($parcours_vu) {
                        $proprio = proprietaire_projet(['id' => (int) $e['id']], $parcours_vu);
                        $etats = etapes_projet($parcours_vu, $proprio, $proprio['type'] === 'eleve' ? [(int) $e['id'] => $prog[(int) $e['id']] ?? []] : null, $catalogue);
                    } ?>
                    <tr class="<?= $e['actif'] ? '' : 'inactif' ?>">
                        <td class="numero fixe"><?= h($e['etiquette']) ?></td>
                        <td class="fixe2"><a href="./?p=eleve&id=<?= (int) $e['id'] ?>"><?= nom_eleve($classe['nom'], $e['etiquette']) ?></a></td>
                        <?php foreach ($colonnes as $i => $c):
                            $etat = $etats[$i] ?? etat_etape($c['activite'], $prog[(int) $e['id']][$c['app']] ?? [], $catalogue[$c['app']] ?? []);
                            $somme += $etat['taux'];
                            $derniere = max($derniere, (int) $etat['maj']);
                            if ($etat['statut'] === 'termine') {
                                $termines_col[$i]++;
                            }
                            echo cellule_suivi($etat, $c['titre']);
                        endforeach;
                        $pct = (int) round(100 * $somme / count($colonnes));
                        $total_classe += $pct; ?>
                        <td class="avancement"><?= $pct ?> %<?= jauge($pct, 100) ?></td>
                        <td class="discret"><?= $derniere ? date('d/m H:i', $derniere) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <td class="fixe"></td><td class="fixe2">Terminé par</td>
                    <?php foreach ($termines_col as $n): ?><td class="cellule"><?= $n ?>/<?= count($eleves) ?></td><?php endforeach; ?>
                    <td class="avancement"><?= (int) round($total_classe / count($eleves)) ?> %</td><td></td>
                </tr>
                </tfoot>
            </table></div>
            <?php if (str_starts_with($vue, 'app:') && isset($catalogue[substr($vue, 4)])): ?>
                <p class="discret" style="margin-top:16px">Liste des activités envoyée par l'application <code><?= h(substr($vue, 4)) ?></code>.
                    <?= bouton('oublier_catalogue', 'Effacer cette liste', ['app' => substr($vue, 4)], 'secondaire',
                        "Effacer la liste des activités de cet atelier ? (La progression des élèves est conservée.)") ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php
    pied();
}

function page_parcours(): void
{
    $pdo = db();
    $liste = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM parcours_etapes e WHERE e.parcours_id = p.id) AS nb
                          FROM parcours p ORDER BY p.nom')->fetchAll();
    $modifier = (int) ($_GET['modifier'] ?? 0);
    $nouveau = !empty($_GET['nouveau']) || !$liste;
    $brouillon = $_SESSION['brouillon_parcours'] ?? null;
    unset($_SESSION['brouillon_parcours']);
    $catalogue = catalogue_activites();

    $courant = ['id' => 0, 'nom' => '', 'description' => '', 'classes' => '', 'etapes' => '', 'mode' => 'individuel', 'deblocage' => 'libre'];
    if ($modifier) {
        $st = $pdo->prepare('SELECT * FROM parcours WHERE id = ?');
        $st->execute([$modifier]);
        if ($p = $st->fetch()) {
            $se = $pdo->prepare('SELECT * FROM parcours_etapes WHERE parcours_id = ? ORDER BY ordre, id');
            $se->execute([$modifier]);
            $courant = ['id' => (int) $p['id'], 'nom' => $p['nom'], 'description' => $p['description'],
                        'classes' => $p['classes'], 'etapes' => etapes_en_texte($se->fetchAll()), 'mode' => $p['mode'], 'deblocage' => $p['deblocage']];
        }
    }
    if ($brouillon) {
        $courant = ['id' => (int) ($brouillon['parcours_id'] ?? 0), 'nom' => (string) ($brouillon['nom'] ?? ''),
                    'description' => (string) ($brouillon['description'] ?? ''), 'classes' => (string) ($brouillon['classes'] ?? ''),
                    'etapes' => (string) ($brouillon['etapes'] ?? ''), 'mode' => (string) ($brouillon['mode'] ?? 'individuel'),
                    'deblocage' => (string) ($brouillon['deblocage'] ?? 'libre')];
    }
    $afficher_form = $nouveau || $modifier || $brouillon;

    entete('Parcours');
    barre('parcours');
    ?>
    <main>
        <div class="titre-section">
            <h1>Parcours</h1>
            <?php if (!$afficher_form): ?><a class="btn" href="./?p=parcours&nouveau=1">Nouveau parcours</a><?php endif; ?>
        </div>
        <p class="sous">Un parcours enchaîne des étapes, éventuellement dans plusieurs logiciels (modélisation → programmation → questionnaire…).
            L'élève les retrouve sur la page d'accueil de la Centrale et passe de l'une à l'autre sans retaper son code.</p>
        <?php messages(); ?>

        <?php if ($afficher_form): ?>
            <form method="post" class="carte">
                <?= csrf() ?>
                <input type="hidden" name="action" value="enregistrer_parcours">
                <input type="hidden" name="parcours_id" value="<?= (int) $courant['id'] ?>">
                <div class="deux-colonnes">
                    <label>Nom<input name="nom" required maxlength="80" value="<?= h($courant['nom']) ?>" placeholder="Le robot suiveur de ligne"></label>
                    <label>Classes concernées
                        <span class="aide">Séparées par des virgules (ex. 5A, 5B). Vide = toutes.</span>
                        <input name="classes" value="<?= h($courant['classes']) ?>" placeholder="5A, 5B"></label>
                </div>
                <div class="deux-colonnes">
                    <label>Travail
                        <select name="mode"><?= options_html(MODES_PARCOURS, $courant['mode']) ?></select>
                        <span class="aide">En équipe : un seul dossier de projet par équipe (équipes formées sur la page de chaque classe).</span></label>
                    <label>Ouverture des étapes
                        <select name="deblocage"><?= options_html(DEBLOCAGES, $courant['deblocage']) ?></select></label>
                </div>
                <label>Consigne générale (facultative)<input name="description" maxlength="500" value="<?= h($courant['description']) ?>"></label>
                <label>Étapes, une par ligne
                    <span class="aide"><code>Titre | atelier | activité | adresse</code> — l'activité est facultative (vide = tout l'atelier),
                        l'adresse est celle du logiciel à ouvrir. Sous une étape, facultatif :
                        <code>&gt; consigne</code>, <code>reçoit : fichier1, fichier2</code> (ce que l'étape lit dans le dossier de projet),
                        <code>produit : fichier</code> (ce qu'elle doit y déposer ; l'étape est terminée quand tout est déposé),
                        <code>compétences : SFC15.5.1, CCRI16.5.1</code> (ce que vous évaluez à cette étape : codes de l'onglet <a href="./?p=competences" target="_blank">Compétences</a>).</span>
                    <textarea name="etapes" rows="12" class="mono" placeholder="Câbler le réseau de la salle | netsim | e5-1 | https://grandiosian.github.io/NetSim/
  > Branche les ordinateurs de la salle.
Modéliser le support de la lampe | etabli3d | | https://grandiosian.github.io/etabli3d/
  > Pars du gabarit fourni et adapte-le.
  reçoit : support.etabli3d.json
  produit : support.etabli3d.json, support.stl
  compétences : CCRI16.5.1, CCRI12.5.1
Programmer la lampe | technostudio | charte-lampe-de-poche/P1 | https://grandiosian.github.io/TechnoStudio/"><?= h($courant['etapes']) ?></textarea>
                </label>
                <?php if ($catalogue): ?>
                    <details class="aide-catalogue"><summary>Activités connues (déclarées par vos applications)</summary>
                        <?php foreach ($catalogue as $app => $acts): ?>
                            <p><strong><?= h($app) ?></strong> :
                                <?= implode(', ', array_map(fn($a) => '<code title="' . h($a['titre']) . '">' . h($a['activite']) . '</code>', $acts)) ?></p>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>
                <div class="ligne-actions">
                    <button>Enregistrer le parcours</button>
                    <?php if ($liste): ?><a href="./?p=parcours">Annuler</a><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($courant['id'] && !$brouillon) { carte_enchainement((int) $courant['id']); section_fournis((int) $courant['id']); } ?>

        <?php $avec_test = $liste && !$afficher_form ? carte_tester('./?p=parcours') : false; ?>
        <?php if ($liste): ?>
            <div class="defile" style="margin-top:20px"><table>
                <thead><tr><th>Parcours</th><th>Travail</th><th>Classes</th><th>Étapes</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($liste as $p): ?>
                    <tr>
                        <td><strong><?= h($p['nom']) ?></strong><?= $p['description'] ? '<br><span class="discret">' . h($p['description']) . '</span>' : '' ?></td>
                        <td><?= h(MODES_PARCOURS[$p['mode']] ?? '') ?><?= $p['deblocage'] !== 'libre' ? '<br><span class="discret">' . ($p['deblocage'] === 'ordre' ? 'étapes dans l’ordre' : 'étapes après validation') . '</span>' : '' ?></td>
                        <td><?= $p['classes'] !== '' ? h($p['classes']) : '<span class="discret">toutes</span>' ?></td>
                        <td><?= (int) $p['nb'] ?></td>
                        <td class="actions">
                            <?= $avec_test ? bouton_tester('parcours:' . (int) $p['id'], 'Ouvrir le portail de l’élève test choisi au-dessus, pour suivre ce parcours à sa place') : '' ?>
                            <?php $nb_av = count(a_valider_parcours(parcours_par_id((int) $p['id']) ?? ['deblocage' => '', 'etapes' => []])); ?>
                            <?php if ($nb_av): ?><a class="btn petit urgent" href="./?p=projets&parcours=<?= (int) $p['id'] ?>&vue=a_valider"
                                title="Élèves ou équipes qui ont terminé une étape et vous attendent (validation ou évaluation)"><?= $nb_av ?> <?= $p['deblocage'] === 'validation' ? 'à valider' : 'à évaluer' ?></a><?php endif; ?>
                            <a class="btn petit" href="./?p=projets&parcours=<?= (int) $p['id'] ?>">Dossiers de projet</a>
                            <a class="btn petit secondaire" href="./?p=suivi&vue=parcours:<?= (int) $p['id'] ?>">Suivi</a>
                            <a class="btn petit secondaire" href="./?p=parcours&modifier=<?= (int) $p['id'] ?>">Modifier</a>
                            <?= bouton('supprimer_parcours', 'Supprimer', ['parcours_id' => $p['id']], 'danger', 'Supprimer ce parcours ?') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </main>
    <?php
    pied();
}

/** Fichiers de départ fournis par l'enseignant (gabarit 3D, consigne PDF, modèle de programme…). */
function section_fournis(int $parcours_id): void
{
    $fournis = fichiers_fournis($parcours_id);
    ?>
    <section id="fournis" class="carte" style="margin-top:16px">
        <h3>Fichiers fournis aux élèves</h3>
        <p class="aide">Ils sont dans le dossier de projet de chaque élève (ou équipe) dès le début. Une étape qui « reçoit » un fichier
            du même nom lit d'abord celui de l'élève s'il en a déposé un, sinon le vôtre : pratique pour un gabarit de départ.</p>
        <?php if ($fournis): ?>
            <ul class="liste-fournis">
                <?php foreach ($fournis as $f): ?>
                    <li><a href="./?p=fourni&id=<?= (int) $f['id'] ?>"><?= h($f['nom']) ?></a> <span class="discret"><?= format_taille((int) $f['taille']) ?></span>
                        <?= bouton('supprimer_fourni', 'Retirer', ['parcours_id' => $parcours_id, 'fourni_id' => $f['id']], 'danger', 'Retirer ce fichier fourni ?') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="formulaire-ligne">
            <?= csrf() ?>
            <input type="hidden" name="action" value="ajouter_fourni">
            <input type="hidden" name="parcours_id" value="<?= $parcours_id ?>">
            <label>Fichier<input type="file" name="fichier" required></label>
            <label>Nom dans le dossier (facultatif)<input name="nom" maxlength="120" placeholder="gabarit.glb"></label>
            <button>Ajouter</button>
        </form>
    </section>
    <?php
}

function page_ateliers(): void
{
    $pdo = db();
    $liste = $pdo->query('SELECT * FROM ateliers ORDER BY ordre, nom')->fetchAll();
    $catalogue = catalogue_activites();
    $ouvertures = [];
    $st = $pdo->prepare('SELECT app, origine, COUNT(*) AS n FROM connexions WHERE ts >= ? GROUP BY app, origine');
    $st->execute([time() - 7 * 86400]);
    foreach ($st->fetchAll() as $o) {
        $nom_o = nom_origine($o['origine']);
        $ouvertures[$o['app']][$nom_o] = ($ouvertures[$o['app']][$nom_o] ?? 0) + (int) $o['n'];
    }
    $connues = array_values(array_unique(array_merge(array_keys($catalogue),
        $pdo->query("SELECT DISTINCT app FROM progression UNION SELECT DISTINCT app FROM donnees")->fetchAll(PDO::FETCH_COLUMN))));
    $enregistrees = array_column($liste, 'app');
    $suggestions = array_values(array_diff($connues, $enregistrees, ['portail']));
    sort($suggestions);

    $modifier = (int) ($_GET['modifier'] ?? 0);
    $brouillon = $_SESSION['brouillon_atelier'] ?? null;
    unset($_SESSION['brouillon_atelier']);
    $courant = ['id' => 0, 'app' => (string) ($_GET['app'] ?? ''), 'nom' => '', 'description' => '', 'url' => '',
                'icone' => '', 'classes' => '', 'ordre' => count($liste) + 1, 'actif' => 1, 'urls_secours' => ''];
    if ($modifier) {
        $st = $pdo->prepare('SELECT * FROM ateliers WHERE id = ?');
        $st->execute([$modifier]);
        $courant = $st->fetch() ?: $courant;
    }
    if ($brouillon) {
        $courant = array_merge($courant, array_intersect_key($brouillon, $courant), ['id' => (int) ($brouillon['atelier_id'] ?? 0),
            'actif' => empty($brouillon['actif']) ? 0 : 1]);
    }
    $afficher_form = !empty($_GET['nouveau']) || $modifier || $brouillon || isset($_GET['app']) || !$liste;

    entete('Ateliers');
    barre('ateliers');
    ?>
    <main>
        <div class="titre-section">
            <h1>Ateliers</h1>
            <?php if (!$afficher_form): ?><a class="btn" href="./?p=ateliers&nouveau=1">Ajouter un atelier</a><?php endif; ?>
        </div>
        <p class="sous">Les ateliers apparaissent sur le portail des élèves (<a href="../" target="_blank"><?= h(url_base()) ?>/</a>).
            L'élève les ouvre d'un clic, déjà connecté, et revient au portail avec le bouton « ← Mes ateliers ».</p>
        <?php messages(); ?>
        <?php if (!$modifier && !$brouillon) carte_brancher_passerelle(); ?>

        <?php if ($suggestions && !$afficher_form): ?>
            <p class="carte">Applications déjà branchées mais pas encore sur le portail :
                <?php foreach ($suggestions as $s): ?>
                    <a class="btn petit secondaire" href="./?p=ateliers&app=<?= rawurlencode($s) ?>">+ <?= h($s) ?></a>
                <?php endforeach; ?></p>
        <?php endif; ?>

        <?php if ($afficher_form): ?>
            <form method="post" class="carte">
                <?= csrf() ?>
                <input type="hidden" name="action" value="enregistrer_atelier">
                <input type="hidden" name="atelier_id" value="<?= (int) $courant['id'] ?>">
                <div class="grille-atelier">
                    <label>Icône<input name="icone" maxlength="4" value="<?= h($courant['icone']) ?>" placeholder="🌐"></label>
                    <label>Nom affiché<input name="nom" required maxlength="80" value="<?= h($courant['nom']) ?>" placeholder="NetSim — réseaux"></label>
                    <label>Identifiant (app)
                        <input name="app" required maxlength="40" value="<?= h($courant['app']) ?>" placeholder="netsim" list="apps-connues">
                        <datalist id="apps-connues"><?php foreach ($connues as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>
                    </label>
                </div>
                <label>Adresse de l'application<input name="url" type="url" required value="<?= h($courant['url']) ?>" placeholder="https://grandiosian.github.io/NetSim/"></label>
                <label>Adresses de secours (facultatives)
                    <span class="aide">Une par ligne : copie sur la Forge, sur ce serveur (<code><?= h(url_base()) ?>/miroir/…/</code>)…
                        Si l'adresse principale ne répond plus, le portail ouvre automatiquement la première qui répond.</span>
                    <textarea name="urls_secours" rows="2" class="mono" placeholder="<?= h(url_base()) ?>/miroir/netsim/"><?= h($courant['urls_secours']) ?></textarea>
                </label>
                <label>Description (facultative)<input name="description" maxlength="300" value="<?= h($courant['description']) ?>" placeholder="Construire et tester des réseaux"></label>
                <div class="grille-atelier reglages-atelier">
                    <label>Classes<span class="aide">Ex. 5A, 5B. Vide = toutes.</span><input name="classes" value="<?= h($courant['classes']) ?>"></label>
                    <label>Ordre<span class="aide">Position sur le portail.</span><input type="number" name="ordre" value="<?= (int) $courant['ordre'] ?>"></label>
                    <label class="case"><input type="checkbox" name="actif" value="1" <?= $courant['actif'] ? 'checked' : '' ?>> Visible par les élèves</label>
                </div>
                <div class="ligne-actions">
                    <button>Enregistrer</button>
                    <?php if ($liste): ?><a href="./?p=ateliers">Annuler</a><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>

        <?php $avec_test = $liste && !$afficher_form ? carte_tester('./?p=ateliers') : false; ?>
        <?php if ($liste): ?>
            <div class="defile" style="margin-top:20px"><table>
                <thead><tr><th></th><th>Atelier</th><th>Passerelle</th><th>Activités</th><th>Ouvertures (7 j)</th><th>Classes</th><th>État</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($liste as $a): ?>
                    <tr class="<?= $a['actif'] ? '' : 'inactif' ?>">
                        <td style="font-size:1.4rem"><?= h($a['icone']) ?></td>
                        <td><strong><?= h($a['nom']) ?></strong> <code><?= h($a['app']) ?></code>
                            <?php foreach (adresses_atelier($a) as $i => $u): $repond = adresse_repond($u); ?>
                                <br><span class="<?= $repond ? 'ok' : 'alerte' ?>" title="<?= $repond ? 'Répond' : 'Ne répond pas' ?> (vérifié il y a moins de 5 min)"><?= $repond ? '●' : '○' ?></span>
                                <a href="<?= h($u) ?>" target="_blank" rel="noopener" class="discret"><?= h($u) ?></a><?= $i ? ' <span class="discret">(secours)</span>' : '' ?>
                            <?php endforeach; ?></td>
                        <td><?= cellule_passerelle($a) ?></td>
                        <td><?= count($catalogue[$a['app']] ?? []) ?: '<span class="discret" title="Les activités apparaissent quand l\'application les déclare (à la première connexion d\'un élève)">—</span>' ?></td>
                        <td class="discret"><?php
                            $o = $ouvertures[$a['app']] ?? [];
                            arsort($o);
                            echo $o ? implode('<br>', array_map(fn($k, $n) => h($k) . ' : ' . $n, array_keys($o), $o)) : '—';
                        ?></td>
                        <td><?= $a['classes'] !== '' ? h($a['classes']) : '<span class="discret">toutes</span>' ?></td>
                        <td><?= $a['actif'] ? 'Visible' : 'Masqué' ?></td>
                        <td class="actions">
                            <?= $avec_test ? bouton_tester('app:' . $a['app'], 'Ouvrir cet atelier connecté comme l’élève test choisi au-dessus') : '' ?>
                            <a class="btn petit secondaire" href="./?p=suivi&vue=app:<?= rawurlencode($a['app']) ?>">Suivi</a>
                            <a class="btn petit secondaire" href="./?p=ateliers&modifier=<?= (int) $a['id'] ?>">Modifier</a>
                            <?= bouton('supprimer_atelier', 'Retirer', ['atelier_id' => $a['id']], 'danger', 'Retirer cet atelier du portail ?') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </main>
    <?php
    pied();
}

/** Section « Connexions » de la page d'un élève : quel atelier, depuis quel hébergement. */
function section_connexions_eleve(int $eleve_id): void
{
    $st = db()->prepare('SELECT * FROM connexions WHERE eleve_id = ? ORDER BY ts DESC LIMIT 30');
    $st->execute([$eleve_id]);
    $lignes = $st->fetchAll();
    if (!$lignes) {
        return;
    }
    ?>
    <section>
        <h2>Connexions</h2>
        <p class="aide">Atelier ouvert et site d'où il a été chargé (30 dernières, 120 jours maximum). Aucune adresse IP n'est enregistrée.</p>
        <div class="defile"><table>
            <thead><tr><th>Date</th><th>Atelier</th><th>Hébergement</th><th>Connexion</th></tr></thead>
            <tbody>
            <?php foreach ($lignes as $c): ?>
                <tr>
                    <td><?= quand((int) $c['ts']) ?></td>
                    <td><code><?= h($c['app']) ?></code></td>
                    <td title="<?= h($c['origine']) ?>"><?= h(nom_origine($c['origine'])) ?></td>
                    <td class="discret"><?= $c['moyen'] === 'portail' ? 'depuis le portail' : 'avec son code' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}

/** Section « Avancement » de la page d'un élève. */
function section_avancement_eleve(int $eleve_id): void
{
    $st = db()->prepare('SELECT * FROM progression WHERE eleve_id = ? ORDER BY app, debut');
    $st->execute([$eleve_id]);
    $lignes = $st->fetchAll();
    if (!$lignes) {
        return;
    }
    ?>
    <section>
        <h2>Avancement</h2>
        <div class="defile"><table>
            <thead><tr><th>Atelier</th><th>Activité</th><th>État</th><th>Résultat</th><th>Commencée</th><th>Terminée</th><th>Détails</th></tr></thead>
            <tbody>
            <?php foreach ($lignes as $p): $etat = etat_etape($p['activite'], [$p['activite'] => $p], []); ?>
                <tr>
                    <td><code><?= h($p['app']) ?></code></td>
                    <td><?= h($p['titre'] !== '' ? $p['titre'] : $p['activite']) ?></td>
                    <td><?= $p['statut'] === 'termine' ? '<span class="ok">✓ terminée</span>' : 'en cours' ?></td>
                    <td><?= h($etat['texte']) ?></td>
                    <td><?= quand((int) $p['debut']) ?></td>
                    <td><?= quand($p['fin'] ? (int) $p['fin'] : null) ?></td>
                    <td><?php if ($p['details']): ?><details><summary>voir</summary><pre class="code"><?=
                        h((string) json_encode(json_decode($p['details']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}
