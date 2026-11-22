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
            $avant = isset(NAVIGATION_AVANT[$_POST['avant'] ?? '']) ? $_POST['avant'] : 'etape';
            $apres = isset(NAVIGATION_APRES[$_POST['apres'] ?? '']) ? $_POST['apres'] : 'etape';
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
                $pdo->prepare('UPDATE parcours SET nom = ?, description = ?, classes = ?, mode = ?, deblocage = ?, avant = ?, apres = ? WHERE id = ?')
                    ->execute([$nom, $description, $classes, $mode, $deblocage, $avant, $apres, $id]);
                $pdo->prepare('DELETE FROM parcours_etapes WHERE parcours_id = ?')->execute([$id]);
            } else {
                $pdo->prepare('INSERT INTO parcours (nom, description, classes, mode, deblocage, avant, apres, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$nom, $description, $classes, $mode, $deblocage, $avant, $apres, time()]);
                $id = (int) $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO parcours_etapes (parcours_id, ordre, titre, app, activite, url, consigne, entrees, sorties, competences, avant, apres) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($etapes as $i => $et) {
                $ins->execute([$id, $i, $et['titre'], $et['app'], $et['activite'], $et['url'], $et['consigne'], $et['entrees'], $et['sorties'], $et['competences'],
                               $et['avant'], $et['apres']]);
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
            $erreurs = [];
            if ($app === '*') {
                $erreurs[] = "Identifiant de l'atelier manquant : c'est le nom donné à app dans Centrale.init (ex. netsim).";
            }
            if ($nom === '') {
                $erreurs[] = "Donnez un nom à l'atelier.";
            }
            // Emplacements : local (chemin /miroir/… ou adresse), GitHub Pages, GitLab Pages, alwaysdata
            $emplacements = [];
            foreach (EMPLACEMENTS as $type => $libelle) {
                $u = trim((string) ($_POST['url_' . $type] ?? ''));
                $permis = $type === 'local' ? '#^(https?://\S+|/\S*)$#' : '#^https?://\S+$#';
                if ($u !== '' && !preg_match($permis, $u)) {
                    $erreurs[] = "$libelle : l'adresse doit commencer par https://" . ($type === 'local' ? ' ou par / (ex. /miroir/netsim/)' : '');
                }
                $emplacements[$type] = $u;
            }
            $secours = [];
            foreach (preg_split('/\R/', (string) ($_POST['urls_secours'] ?? '')) ?: [] as $u) {
                $u = trim($u);
                if ($u === '') {
                    continue;
                }
                if (!preg_match('#^https?://\S+$#', $u)) {
                    $erreurs[] = "Autre adresse invalide : $u";
                } elseif (!in_array($u, $emplacements, true)) {
                    $secours[] = $u;
                }
            }
            // Adresse de référence (descripteur, liens des parcours) : la première adresse publique
            $url = '';
            foreach (['github', 'gitlab', 'alwaysdata', 'local'] as $type) {
                if ($url === '' && $emplacements[$type] !== '') {
                    $url = adresse_complete($emplacements[$type]);
                }
            }
            $url = $url !== '' ? $url : ($secours[0] ?? '');
            if ($url === '') {
                $erreurs[] = "Indiquez au moins un emplacement pour l'application.";
            }
            if ($erreurs) {
                $_SESSION['brouillon_atelier'] = $_POST;
                foreach ($erreurs as $e) {
                    flash($e, 'err');
                }
                rediriger('./?p=applications' . ($id ? '&modifier=' . $id : '&nouveau=1'));
            }
            $champs = [$app, $nom, mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 300), $url,
                mb_substr(trim((string) ($_POST['icone'] ?? '')), 0, 4),
                implode(',', array_filter(array_map('trim', explode(',', (string) ($_POST['classes'] ?? ''))))),
                (int) ($_POST['ordre'] ?? 0), empty($_POST['actif']) ? 0 : 1, implode("\n", array_unique($secours)),
                $emplacements['local'], $emplacements['github'], $emplacements['gitlab'], $emplacements['alwaysdata'], $emplacements['domaine']];
            try {
                if ($id) {
                    $pdo->prepare('UPDATE ateliers SET app = ?, nom = ?, description = ?, url = ?, icone = ?, classes = ?, ordre = ?, actif = ?, urls_secours = ?,
                                   url_local = ?, url_github = ?, url_gitlab = ?, url_alwaysdata = ?, url_domaine = ? WHERE id = ?')
                        ->execute([...$champs, $id]);
                } else {
                    $pdo->prepare('INSERT INTO ateliers (app, nom, description, url, icone, classes, ordre, actif, urls_secours,
                                   url_local, url_github, url_gitlab, url_alwaysdata, url_domaine) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute($champs);
                }
            } catch (PDOException) {
                $_SESSION['brouillon_atelier'] = $_POST;
                flash("Un atelier utilise déjà l'identifiant « $app ».", 'err');
                rediriger('./?p=applications' . ($id ? '&modifier=' . $id : '&nouveau=1'));
            }
            flash("Atelier « $nom » enregistré.");
            rediriger('./?p=applications');

        case 'emplacements_apps':
            // Où tourne la Centrale, et dans quel ordre essayer les emplacements des applications
            $lieu = (string) ($_POST['lieu_centrale'] ?? 'auto');
            definir_reglage('lieu_centrale', in_array($lieu, ['local', 'en_ligne'], true) ? $lieu : 'auto');
            $domaine = rtrim(trim((string) ($_POST['domaine_centrale'] ?? '')), '/');
            if ($domaine !== '' && !preg_match('#^https?://[^/\s]+$#', $domaine)) {
                flash('Nom de domaine : indiquez seulement l’adresse du site, ex. https://technoversailles.fr', 'err');
                rediriger('./?p=applications#emplacements');
            }
            definir_reglage('domaine_centrale', $domaine);
            foreach (array_keys(ORDRE_EMPLACEMENTS) as $l) {
                $ordre = array_values(array_unique(array_intersect((array) ($_POST['ordre_' . $l] ?? []), array_keys(EMPLACEMENTS))));
                definir_reglage('ordre_' . $l, $ordre ? implode(',', $ordre) : ORDRE_EMPLACEMENTS[$l]);
            }
            flash('Ordre des emplacements enregistré.');
            rediriger('./?p=applications#emplacements');

        case 'enregistrer_activation':
            $id = (int) ($_POST['atelier_id'] ?? 0);
            $app = normaliser_app((string) ($_POST['app'] ?? ''));
            $classe_id = (int) ($_POST['classe_id'] ?? 0);
            $groupe_id = (int) ($_POST['groupe_id'] ?? 0);
            $mode = in_array($_POST['mode'] ?? '', ['periode', 'cours'], true) ? (string) $_POST['mode'] : 'periode';
            $debut = date_valide((string) ($_POST['debut'] ?? '')) ? (string) $_POST['debut'] : '';
            $fin = date_valide((string) ($_POST['fin'] ?? '')) ? (string) $_POST['fin'] : '';
            $pendant = empty($_POST['pendant_cours']) ? 0 : 1;
            $retour = './?p=applications&modifier=' . $id . '#activation';
            if ($app === '*' || !$classe_id) {
                flash("Choisissez une classe pour l'activation.", 'err');
                rediriger($retour);
            }
            if ($mode === 'periode' && $debut !== '' && $fin !== '' && $fin < $debut) {
                flash("La date de fin doit être après le début.", 'err');
                rediriger($retour);
            }
            $pdo->prepare('INSERT INTO atelier_activations (app, classe_id, groupe_id, mode, pendant_cours, debut, fin, cree_le)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$app, $classe_id, $groupe_id, $mode, $pendant,
                           $mode === 'periode' ? $debut : '', $mode === 'periode' ? $fin : '', time()]);
            flash("Fenêtre d'activation ajoutée.");
            rediriger($retour);

        case 'supprimer_activation':
            $id = (int) ($_POST['atelier_id'] ?? 0);
            $pdo->prepare('DELETE FROM atelier_activations WHERE id = ?')->execute([(int) ($_POST['activation_id'] ?? 0)]);
            flash("Fenêtre d'activation retirée.");
            rediriger('./?p=applications&modifier=' . $id . '#activation');

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
                rediriger('./?p=applications' . ($id ? '' : '&nouveau=1'));
            }
            [$err, $av] = verifier_descripteur($d);
            if ($err) {
                flash('Ce centrale.json ne suit pas le format passerelle.atelier v1 : ' . implode(' ', array_slice($err, 0, 4)), 'err');
                rediriger('./?p=applications' . ($id ? '' : '&nouveau=1'));
            }
            if ($id && $a && $a['app'] !== $d['atelier']['app']) {
                flash("Ce descripteur décrit l'atelier « {$d['atelier']['app']} », pas « {$a['app']} ». Rien n'a été changé.", 'err');
                rediriger('./?p=applications');
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
            rediriger('./?p=applications');

        case 'supprimer_atelier':
            $pdo->prepare('DELETE FROM ateliers WHERE id = ?')->execute([(int) ($_POST['atelier_id'] ?? 0)]);
            flash("Atelier retiré du portail (le travail et la progression des élèves sont conservés).");
            rediriger('./?p=applications');

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
        if (preg_match('/^(av[ae]nt|retour|pr[ée]c[ée]dent)\s*:\s*(.*)$/iu', $ligne, $m)) {   // bouton « ← » à cette étape (« avent » toléré)
            if ($dernier >= 0) {
                $etapes[$dernier]['avant'] = navigation_lue($m[2]);
            }
            continue;
        }
        if (preg_match('/^(apr[èe]s|suivant)\s*:\s*(.*)$/iu', $ligne, $m)) {   // bouton du bas à cette étape
            if ($dernier >= 0) {
                $etapes[$dernier]['apres'] = navigation_lue($m[2]);
            }
            continue;
        }
        if (preg_match('/^(comp[ée]tences?|[ée]value)\s*:\s*(.*)$/iu', $ligne, $m)) {
            if ($dernier >= 0) {
                $etapes[$dernier]['competences'] = implode(', ', liste_codes_competences($m[2]));
            }
            continue;
        }
        // Ligne de détail inconnue (« mot : … » sans « | ») : on le dit clairement, plutôt qu'« il faut Titre | atelier »
        if (!str_contains($ligne, '|') && preg_match('/^([\p{L}\s]{2,20}?)\s*:/u', $ligne, $m)) {
            $erreurs[] = 'Ligne ' . ($n + 1) . ' : « ' . trim($m[1]) . ' : » n’est pas reconnu. Sous une étape, écrivez '
                . '« > consigne », « reçoit : », « produit : », « compétences : », « avant : » ou « après : ».';
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
        $etapes[] = ['titre' => $titre, 'app' => $app, 'activite' => $activite, 'url' => $url, 'consigne' => '', 'entrees' => '', 'sorties' => '', 'competences' => '',
                     'avant' => '', 'apres' => ''];
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
        foreach (['avant' => 'avant', 'apres' => 'après'] as $champ => $mot) {
            if (($e[$champ] ?? '') !== '') {
                $lignes[] = '  ' . $mot . ' : ' . ($e[$champ] === 'portail' ? 'mes ateliers' : 'étape');
            }
        }
    }
    return implode("\n", $lignes);
}

/* ---------------------------------------------------------------------- */

/* ---------- Mesures (monitoring) envoyées avec la progression : temps actif, essais, délai de réponse ---------- */

/** « 45 s », « 12 min », « 1 h 05 ». */
function duree_courte(?int $s): string
{
    if ($s === null) {
        return '—';
    }
    if ($s < 60) {
        return $s . ' s';
    }
    $m = intdiv($s + 30, 60);
    return $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h ' . sprintf('%02d', $m % 60);
}

function mediane(array $v): ?float
{
    $v = array_values(array_filter($v, fn($x) => $x !== null));
    if (!$v) {
        return null;
    }
    sort($v);
    $n = count($v);
    return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

/** Une ligne de progression a-t-elle des mesures ? */
function a_des_mesures(?array $p): bool
{
    return $p !== null && (($p['temps_actif'] ?? null) !== null || ($p['essais'] ?? null) !== null);
}

/** Texte court des mesures d'une ligne : « 12 min · 14 essais · 3/6 du 1er coup ». */
function texte_mesures(array $p): string
{
    $t = [];
    if ($p['temps_actif'] !== null) {
        $t[] = duree_courte((int) $p['temps_actif']);
    }
    if ($p['essais'] !== null) {
        $t[] = (int) $p['essais'] . ' essai' . ((int) $p['essais'] > 1 ? 's' : '');
    }
    if ($p['questions']) {
        $t[] = (int) $p['premier_coup'] . '/' . (int) $p['questions'] . ' du 1er coup';
    }
    return implode(' · ', $t);
}

/** Tableau des questions d'une ligne de progression (détail des mesures). */
function tableau_questions_mesures(array $detail): string
{
    if (!$detail) {
        return '';
    }
    $h = '<table class="mesures-q"><thead><tr><th>Question</th><th>Essais</th><th>1er coup</th><th>Réussie à l’essai</th><th>1re réponse après</th></tr></thead><tbody>';
    foreach ($detail as $q) {
        $h .= '<tr><td>' . h((string) ($q['q'] ?? '')) . '</td><td>' . (int) ($q['essais'] ?? 0) . '</td><td>'
            . (isset($q['ok1']) ? ($q['ok1'] ? '✓' : '✗') : '—') . (!empty($q['revele']) ? ' <span class="discret" title="Correction affichée">corrigé</span>' : '')
            . '</td><td>' . (isset($q['okA']) ? (int) $q['okA'] : '—') . '</td><td>' . duree_courte(isset($q['delai']) ? (int) $q['delai'] : null) . '</td></tr>';
    }
    return $h . '</tbody></table>';
}

/**
 * Bilan des mesures de la classe sous le tableau du suivi : une ligne par activité (colonne),
 * puis, à la demande, les questions les plus difficiles (essais moyens, réussite du premier coup, délai).
 */
function section_mesures_classe(array $colonnes, array $eleves, array $prog): void
{
    $lignes = [];
    foreach ($colonnes as $c) {
        if ($c['activite'] === '') {
            continue;
        }
        $rows = [];
        foreach ($eleves as $e) {
            $p = $prog[(int) $e['id']][$c['app']][$c['activite']] ?? null;
            if ($p && a_des_mesures($p)) {
                $rows[] = $p;
            }
        }
        if (!$rows) {
            continue;
        }
        $q = [];   // par question : [essais], [ok1], [delai], révélées
        foreach ($rows as $p) {
            foreach (json_decode((string) ($p['mesures'] ?? ''), true) ?: [] as $x) {
                $k = (string) ($x['q'] ?? '');
                $q[$k] ??= ['essais' => [], 'ok1' => [], 'delai' => [], 'revele' => 0];
                $q[$k]['essais'][] = (int) ($x['essais'] ?? 0);
                if (isset($x['ok1'])) {
                    $q[$k]['ok1'][] = $x['ok1'] ? 1 : 0;
                }
                if (isset($x['delai'])) {
                    $q[$k]['delai'][] = (int) $x['delai'];
                }
                $q[$k]['revele'] += !empty($x['revele']) ? 1 : 0;
            }
        }
        foreach ($q as $k => $v) {
            $q[$k]['moy'] = array_sum($v['essais']) / max(1, count($v['essais']));
        }
        uasort($q, fn($a, $b) => $b['moy'] <=> $a['moy']);
        $essais = array_sum(array_map(fn($p) => (int) $p['essais'], $rows));
        $questions = array_sum(array_map(fn($p) => (int) $p['questions'], $rows));
        $premier = array_sum(array_map(fn($p) => (int) $p['premier_coup'], $rows));
        $lignes[] = ['c' => $c, 'n' => count($rows), 'temps' => mediane(array_map(fn($p) => $p['temps_actif'] === null ? null : (int) $p['temps_actif'], $rows)),
                     'temps_max' => max(array_map(fn($p) => (int) $p['temps_actif'], $rows)),
                     'essais_q' => $questions ? $essais / $questions : null, 'premier' => $questions ? $premier / $questions : null,
                     'delai' => mediane(array_map(fn($p) => $p['delai_median'] === null ? null : (int) $p['delai_median'], $rows)),
                     'revelees' => array_sum(array_map(fn($p) => (int) $p['revelees'], $rows)), 'q' => $q];
    }
    if (!$lignes) {
        return;
    }
    $f1 = fn($x) => $x === null ? '—' : str_replace('.', ',', (string) round($x, 1));
    ?>
    <section class="mesures-classe">
        <h2>Mesures pendant l’atelier</h2>
        <p class="aide">Envoyées par l’application avec la progression : temps de travail effectif (page visible, élève actif), essais
            (jamais remis à zéro), réussite du premier coup, délai entre la question affichée et la première réponse. Médianes sur les élèves mesurés.</p>
        <div class="defile"><table>
            <thead><tr><th>Activité</th><th>Élèves</th><th>Temps actif (médian)</th><th>Essais par question</th><th>Du 1er coup</th>
                <th>1re réponse après (médian)</th><th>Corrections affichées</th></tr></thead>
            <tbody>
            <?php foreach ($lignes as $l): ?>
                <tr>
                    <td><strong><?= h($l['c']['titre']) ?></strong></td>
                    <td><?= $l['n'] ?>/<?= count($eleves) ?></td>
                    <td><?= duree_courte($l['temps'] === null ? null : (int) round($l['temps'])) ?> <span class="discret">(max <?= duree_courte($l['temps_max']) ?>)</span></td>
                    <td><?= $f1($l['essais_q']) ?></td>
                    <td><?= $l['premier'] === null ? '—' : (int) round($l['premier'] * 100) . ' %' ?></td>
                    <td><?= duree_courte($l['delai'] === null ? null : (int) round($l['delai'])) ?></td>
                    <td><?= $l['revelees'] ?: '' ?></td>
                </tr>
                <?php if ($l['q']): ?><tr class="mesures-detail"><td colspan="7"><details><summary>Questions, de la plus difficile à la plus facile</summary>
                            <table class="mesures-q"><thead><tr><th>Question</th><th>Essais (moyenne)</th><th>Du 1er coup</th><th>1re réponse après</th><th>Corrigées</th></tr></thead><tbody>
                            <?php foreach ($l['q'] as $k => $v): $ok = $v['ok1'] ? array_sum($v['ok1']) / count($v['ok1']) : null; ?>
                                <tr class="<?= $v['moy'] >= 3 || ($ok !== null && $ok < 0.4) ? 'difficile' : '' ?>"><td><?= h($k) ?></td><td><?= $f1($v['moy']) ?></td>
                                    <td><?= $ok === null ? '—' : (int) round($ok * 100) . ' %' ?></td><td><?= duree_courte(($m = mediane($v['delai'])) === null ? null : (int) round($m)) ?></td>
                                    <td><?= $v['revele'] ?: '' ?></td></tr>
                            <?php endforeach; ?></tbody></table></details></td></tr><?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}

/** $cible : ['eleve', 'app', 'activite', 'total'] pour que l'enseignant puisse valider l'étape (clic, admin.js). */
function cellule_suivi(array $etat, string $titre, array $cible = []): string
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
    $marques = '';
    if (!empty($etat['prof'])) {
        $info .= ' · validé par vous le ' . date('d/m', (int) $etat['prof']);
        $marques .= ' valide-prof';
    }
    if (!empty($etat['hl'])) {
        $info .= ' · travail hors ligne importé le ' . date('d/m', (int) $etat['hl']);
        $marques .= ' hors-ligne';
    }
    $data = '';
    if ($cible) {
        $info .= $cible['app'] === 'technostudio' && str_contains((string) $cible['activite'], '/') ? ' — clic : voir la feuille, valider' : ' — clic : valider';
        $data = ' data-eleve="' . (int) $cible['eleve'] . '" data-app="' . h($cible['app']) . '" data-activite="' . h($cible['activite']) . '"'
            . ' data-total="' . h((string) ($cible['total'] ?? '')) . '" data-titre="' . h($titre) . '" data-statut="' . h($etat['statut']) . '"'
            . (!empty($etat['prof']) ? ' data-prof="1"' : '')
            . (isset($cible['parcours']) ? ' data-parcours="' . (int) $cible['parcours'] . '" data-ordre="' . (int) $cible['ordre'] . '"' : '');
    }
    $pct = (int) round($etat['taux'] * 100);
    return '<td class="cellule ' . $classes[$etat['statut']] . $marques . '" title="' . h($info) . '" data-taux="' . $pct . '"' . $data
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
                    <optgroup label="Compétences (bilan : moyenne haute)">
                        <?php foreach (options_vue_competences() as $v => $t): ?>
                            <option value="<?= h($v) ?>" <?= $vue === $v ? 'selected' : '' ?>><?= h($t) ?></option>
                        <?php endforeach; ?></optgroup>
                </select>
            </label>
            <?php if (str_starts_with($vue, 'comp:')): ?>
                <label class="case"><input type="checkbox" name="detail" value="1" <?= !empty($_GET['detail']) ? 'checked' : '' ?> onchange="this.form.submit()"> Détail par capacité</label>
            <?php endif; ?>
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
        if (str_starts_with($vue, 'comp:')) {
            tableau_suivi_competences($classe, $groupe_id, $vue, !empty($_GET['detail']));
            echo '</main>';
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
                <a class="btn secondaire" href="./?p=hors_ligne&classe=<?= (int) $classe_id ?>">Travail hors ligne</a>
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
                            $tot = $c['activite'] !== '' ? ($catalogue[$c['app']][$c['activite']]['total'] ?? null) : null;
                            echo cellule_suivi($etat, $c['titre'], $c['activite'] !== '' || isset($catalogue[$c['app']])
                                || $parcours_vu
                                ? ['eleve' => (int) $e['id'], 'app' => $c['app'], 'activite' => $c['activite'], 'total' => $tot]
                                    + ($parcours_vu ? ['parcours' => (int) $parcours_vu['id'], 'ordre' => $i] : []) : []);
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
            <p class="aide">Cliquez sur une case pour <strong>valider l’étape</strong> vous-même (travail fait sur papier, avec un autre identifiant…) :
                elle compte comme terminée et l’étape suivante s’ouvre. <span class="pastille st-termine valide-prof">✓</span> validée par vous
                · <span class="pastille st-termine hors-ligne">✓</span> travail hors ligne importé.</p>
            <form method="post" id="form-valider-suivi" hidden>
                <?= csrf() ?>
                <input type="hidden" name="action" value="valider_activite_prof">
                <input type="hidden" name="eleve"><input type="hidden" name="app"><input type="hidden" name="activite">
                <input type="hidden" name="total"><input type="hidden" name="titre"><input type="hidden" name="annuler">
                <input type="hidden" name="parcours"><input type="hidden" name="ordre">
                <input type="hidden" name="retour" value="<?= h('./?' . http_build_query(array_intersect_key($_GET, array_flip(['p', 'classe', 'groupe', 'vue'])))) ?>">
            </form>
            <details class="hl-suivi"><summary>Importer un rendu hors ligne (fichier .tvrendu d’un élève)</summary>
                <?php formulaire_import_rendus('suivi'); ?>
            </details>
            <?php section_mesures_classe($colonnes, $eleves, $prog); ?>
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

/**
 * Ateliers et activités des applications, pour écrire les étapes d'un parcours : par application, puis par atelier du
 * catalogue, chaque activité avec son étape toute prête : « Titre | app | activité | adresse », puis ses lignes de détail
 * « reçoit : » et « produit : » (fichiers du dossier de projet déclarés par l'application dans son centrale.json, nom
 * conseillé pour un motif) et « compétences : » (codes de l'activité, sinon de l'atelier). Les activités qu'aucun atelier
 * ne regroupe sont dans « Autres activités ».
 * → [ app => ['nom', 'icone', 'url', 'fichiers', 'ateliers' => [ ['titre', 'info', 'lignes' => [ ['titre', 'id', 'ligne', 'details', 'bloc'] ]] ], 'tout'] ]
 */
function catalogue_pour_parcours(): array
{
    $apps = db()->query('SELECT * FROM ateliers ORDER BY ordre, nom')->fetchAll();
    $par_app = [];
    foreach (catalogue_ateliers(null, true) as $r) {
        if ($r['etat'] === 'actif') {
            $par_app[$r['app']][] = $r;
        }
    }
    $connues = catalogue_activites();
    // adresse des lignes : le premier emplacement public de l'ordre de préférence (en ligne : le nom de domaine de la Centrale),
    // celui que le portail ouvre ; toute adresse connue de l'application est de toute façon reconnue et redirigée
    $publique = function (array $a): string {
        foreach (adresses_atelier($a) as $u) {
            if (type_emplacement($u) !== 'local') {
                return $u;
            }
        }
        return (string) $a['url'];
    };
    // Fichiers du dossier de projet que l'application déclare recevoir et produire (noms à écrire dans l'étape)
    $fichiers = function (string $app): array {
        $d = descripteur_de($app);
        $f = is_array($d['fichiers'] ?? null) ? $d['fichiers'] : [];
        $res = ['recoit' => [], 'produit' => []];
        if (!$d || ($f['utilises'] ?? true) === false || ($f['dossier_projet'] ?? true) === false) {
            return $res;
        }
        foreach (array_keys($res) as $liste) {
            foreach (fichiers_declares($d, $liste) as $x) {
                $nom = (string) (($x['nom_conseille'] ?? '') ?: $x['nom']);
                if ($nom !== '' && !str_contains($nom, '*')) {
                    $res[$liste][] = $nom;
                }
            }
            $res[$liste] = array_values(array_unique($res[$liste]));
        }
        return $res;
    };
    $codes = function ($c): string {
        $l = is_array($c) ? $c : preg_split('/[\s,;]+/', (string) $c);
        return implode(', ', array_values(array_unique(array_filter(array_map('trim', (array) $l), 'is_string'))));
    };
    // Une étape : sa ligne, ses détails (reçoit, produit, compétences) et le bloc à ajouter au champ « Étapes »
    $etape = function (string $titre, string $app, string $id, string $url, array $fic, string $competences): array {
        $ligne = str_replace('|', '/', $titre) . ' | ' . $app . ' | ' . $id . ' | ' . $url;
        $details = array_values(array_filter([
            $fic['recoit'] ? 'reçoit : ' . implode(', ', $fic['recoit']) : '',
            $fic['produit'] ? 'produit : ' . implode(', ', $fic['produit']) : '',
            $competences !== '' ? 'compétences : ' . $competences : '',
        ]));
        return ['titre' => $titre, 'id' => $id, 'ligne' => $ligne, 'details' => $details,
                'bloc' => implode("\n", array_merge([$ligne], array_map(fn($d) => '  ' . $d, $details)))];
    };
    $res = [];
    foreach ($apps as $a) {
        $app = (string) $a['app'];
        $url = $publique($a);
        $fic = $fichiers($app);
        $vues = [];
        $ateliers = [];
        foreach ($par_app[$app] ?? [] as $r) {
            $lignes = [];
            foreach ($r['activites'] as $x) {
                $id = (string) ($x['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $vues[$id] = true;
                $titre = (string) (($x['titre'] ?? '') ?: ($connues[$app][$id]['titre'] ?? '') ?: $id);
                $lignes[] = $etape($titre, $app, $id, $url, $fic, $codes($x['competences'] ?? []) ?: $codes($r['competences']));
            }
            if ($lignes) {
                $ateliers[] = ['titre' => $r['titre'] ?: $r['atelier'], 'info' => implode(' · ', array_filter([(string) $r['niveau'], (string) $r['duree']])), 'lignes' => $lignes];
            }
        }
        $autres = [];
        foreach ($connues[$app] ?? [] as $id => $c) {
            if (!isset($vues[(string) $id])) {
                $titre = (string) ($c['titre'] ?: $id);
                $autres[] = $etape($titre, $app, (string) $id, $url, $fic, $codes($c['competences'] ?? ''));
            }
        }
        if ($autres) {
            $ateliers[] = ['titre' => 'Autres activités', 'info' => 'déclarées par l’application, hors atelier', 'lignes' => $autres];
        }
        $res[$app] = ['nom' => $a['nom'], 'icone' => $a['icone'], 'url' => $url, 'fichiers' => $fic, 'ateliers' => $ateliers,
                      'tout' => $etape((string) $a['nom'], $app, '', $url, $fic, '')];
    }
    return $res;
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
    $catalogue = catalogue_pour_parcours();

    $courant = ['id' => 0, 'nom' => '', 'description' => '', 'classes' => '', 'etapes' => '', 'mode' => 'individuel', 'deblocage' => 'libre',
                'avant' => 'etape', 'apres' => 'etape'];
    if ($modifier) {
        $st = $pdo->prepare('SELECT * FROM parcours WHERE id = ?');
        $st->execute([$modifier]);
        if ($p = $st->fetch()) {
            $se = $pdo->prepare('SELECT * FROM parcours_etapes WHERE parcours_id = ? ORDER BY ordre, id');
            $se->execute([$modifier]);
            $courant = ['id' => (int) $p['id'], 'nom' => $p['nom'], 'description' => $p['description'],
                        'classes' => $p['classes'], 'etapes' => etapes_en_texte($se->fetchAll()), 'mode' => $p['mode'], 'deblocage' => $p['deblocage'],
                        'avant' => $p['avant'] ?? 'etape', 'apres' => $p['apres'] ?? 'etape'];
        }
    }
    if ($brouillon) {
        $courant = ['id' => (int) ($brouillon['parcours_id'] ?? 0), 'nom' => (string) ($brouillon['nom'] ?? ''),
                    'description' => (string) ($brouillon['description'] ?? ''), 'classes' => (string) ($brouillon['classes'] ?? ''),
                    'etapes' => (string) ($brouillon['etapes'] ?? ''), 'mode' => (string) ($brouillon['mode'] ?? 'individuel'),
                    'deblocage' => (string) ($brouillon['deblocage'] ?? 'libre'),
                    'avant' => (string) ($brouillon['avant'] ?? 'etape'), 'apres' => (string) ($brouillon['apres'] ?? 'etape')];
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
            Pour que les élèves le voient sur leur portail, placez-le dans le <a href="./?p=calendrier">Calendrier</a> de leur classe, à sa date :
            il y devient une carte, et l'élève passe d'une étape à l'autre sans retaper son code.</p>
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
                <div class="deux-colonnes">
                    <label>Bouton « ← » des applications (en haut, dès la 2ᵉ étape)
                        <select name="avant"><?= options_html(NAVIGATION_AVANT, $courant['avant']) ?></select></label>
                    <label>Bouton du bas des applications
                        <select name="apres"><?= options_html(NAVIGATION_APRES, $courant['apres']) ?></select>
                        <span class="aide">Pour une seule étape : ajoutez sous elle <code>avant : mes ateliers</code> ou <code>après : mes ateliers</code> (ou <code>étape</code>).</span></label>
                </div>
                <label>Consigne générale (facultative)<input name="description" maxlength="500" value="<?= h($courant['description']) ?>"></label>
                <label>Étapes, une par ligne
                    <span class="aide"><code>Titre | atelier | activité | adresse</code> — l'activité est facultative (vide = tout l'atelier),
                        l'adresse est celle du logiciel à ouvrir. Sous une étape, facultatif :
                        <code>&gt; consigne</code>, <code>reçoit : fichier1, fichier2</code> (ce que l'étape lit dans le dossier de projet),
                        <code>produit : fichier</code> (ce qu'elle doit y déposer ; l'étape est terminée quand tout est déposé),
                        <code>compétences : SFC15.5.1, CCRI16.5.1</code> (ce que vous évaluez à cette étape : codes de l'onglet <a href="./?p=competences" target="_blank">Compétences</a>),
                        <code>avant : mes ateliers</code> / <code>après : mes ateliers</code> (à cette étape, le bouton « ← » ou celui du bas ramène à la Centrale ; <code>étape</code> pour l’étape d’à côté).</span>
                    <textarea name="etapes" rows="12" class="mono" placeholder="Câbler le réseau de la salle | netsim | e5-1 | https://grandiosian.github.io/NetSim/
  > Branche les ordinateurs de la salle.
Modéliser le support de la lampe | etabli3d | | https://grandiosian.github.io/etabli3d/
  > Pars du gabarit fourni et adapte-le.
  reçoit : support.etabli3d.json
  produit : support.etabli3d.json, support.stl
  compétences : CCRI16.5.1, CCRI12.5.1
Programmer la lampe | technostudio | charte-lampe-de-poche/P1 | https://grandiosian.github.io/TechnoStudio/"><?= h($courant['etapes']) ?></textarea>
                </label>
                <div class="ligne-actions">
                    <button>Enregistrer le parcours</button>
                    <?php if ($liste): ?><a href="./?p=parcours">Annuler</a><?php endif; ?>
                </div>
                <?php if ($catalogue): ?>
                    <section class="parcours-catalogue" aria-label="Ateliers de vos applications">
                        <h3>Ateliers de vos applications</h3>
                        <p class="aide">Chaque bloc est une étape toute prête, avec ce qu’elle <strong>reçoit</strong> et <strong>produit</strong> dans le dossier de projet
                            (si l’application en échange) et ses <strong>compétences</strong>. « + » l’ajoute à la fin des étapes ci-dessus ;
                            « + tout l’atelier » ajoute une étape par activité. Vous pouvez aussi la copier, puis changer son titre ou retirer une ligne.</p>
                        <input type="search" class="pc-cherche" placeholder="Chercher un atelier ou une activité…" aria-label="Chercher un atelier ou une activité">
                        <?php foreach ($catalogue as $app => $c): ?>
                            <details class="pc-app" data-app="<?= h($app) ?>">
                                <summary><?= icone_app($app, $c['icone']) ?> <strong><?= h($c['nom']) ?></strong> <code><?= h($app) ?></code>
                                    <span class="discret">· <?= count($c['ateliers']) ?> atelier<?= count($c['ateliers']) > 1 ? 's' : '' ?></span></summary>
                                <?php if ($c['fichiers']['recoit'] || $c['fichiers']['produit']): ?>
                                    <p class="pc-fichiers">Dossier de projet : reçoit <code><?= h(implode(', ', $c['fichiers']['recoit']) ?: '—') ?></code>,
                                        produit <code><?= h(implode(', ', $c['fichiers']['produit']) ?: '—') ?></code> (déclarés par l’application ; à adapter si besoin).</p>
                                <?php endif; ?>
                                <div class="pc-ligne pc-tout"><code><?= h($c['tout']['bloc']) ?></code>
                                    <button type="button" class="petit secondaire" data-ligne="<?= h($c['tout']['bloc']) ?>" title="Une étape : toute l’application, sans activité précise">+ toute l’application</button></div>
                                <?php foreach ($c['ateliers'] as $at): ?>
                                    <div class="pc-atelier">
                                        <div class="pc-atelier-tete"><strong><?= h($at['titre']) ?></strong>
                                            <?= $at['info'] !== '' ? '<span class="discret">' . h($at['info']) . '</span>' : '' ?>
                                            <?php if (count($at['lignes']) > 1): ?>
                                                <button type="button" class="petit secondaire" data-ligne="<?= h(implode("\n", array_column($at['lignes'], 'bloc'))) ?>">+ tout l’atelier (<?= count($at['lignes']) ?> étapes)</button>
                                            <?php endif; ?></div>
                                        <?php foreach ($at['lignes'] as $l): ?>
                                            <div class="pc-ligne"><code title="<?= h($l['titre']) ?>"><?= h($l['bloc']) ?></code>
                                                <button type="button" class="petit secondaire" data-ligne="<?= h($l['bloc']) ?>" aria-label="Ajouter l’étape « <?= h($l['titre']) ?> »">+</button></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </details>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
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

/**
 * Applications de l'enseignant (pas sur le portail des élèves) : FicheStudio, copié hors de la racine web (prive/miroir/) et servi
 * par admin/appli.php à la seule session enseignant.
 */
function carte_outils_enseignant(): void
{
    $index = __DIR__ . '/../prive/miroir/fichestudio/index.html';
    $copie = is_file($index) ? (int) filemtime($index) : 0;
    ?>
    <section class="carte outils-enseignant" id="outils-enseignant">
        <h2>Applications de l’enseignant</h2>
        <p class="aide">Réservées à l’espace enseignant : elles s’ouvrent avec votre connexion et n’apparaissent pas sur le portail des élèves.</p>
        <div class="outil-enseignant">
            <span class="outil-icone" aria-hidden="true"><?= function_exists('dash_icone') ? dash_icone('fiche') : '📝' ?></span>
            <div class="outil-texte">
                <strong>FicheStudio</strong> <code>fichestudio</code>
                <p class="discret">Les ateliers sur papier : mise en page des fiches (compatible Affinity), puis présentation et correction en classe,
                    avec l’avancement de chaque classe. Lien enseignant : <code>admin/appli.php/fichestudio/</code>.</p>
                <p class="discret"><?= $copie ? 'Copie sur ce serveur : ' . h(date('d/m/Y H:i', $copie)) . '.'
                    : '<span class="alerte">Pas encore copié sur ce serveur (prive/miroir/fichestudio/).</span>' ?></p>
            </div>
            <div class="outil-actions">
                <a class="btn" href="appli.php/fichestudio/" target="_blank" rel="noopener" title="Ouvre FicheStudio (réservé à l’enseignant connecté)">Ouvrir ↗</a>
                <a class="btn secondaire" href="appli.php/fichestudio/#nouveau" target="_blank" rel="noopener" title="Ouvre FicheStudio sur un nouveau document, modèles de la charte dépliés">＋ Nouvel atelier</a>
            </div>
        </div>
    </section>
    <?php
}

/** Page Applications : les applications du portail des élèves (adresse, icône, classes, activation), le test à la place d'un élève. */
function page_applications(): void
{
    $pdo = db();
    $liste = $pdo->query('SELECT * FROM ateliers ORDER BY ordre, nom')->fetchAll();
    $activations = [];
    foreach ($pdo->query('SELECT * FROM atelier_activations ORDER BY id')->fetchAll() as $av) {
        $activations[$av['app']][] = $av;
    }
    $classes_liste = $pdo->query('SELECT id, nom FROM classes ORDER BY nom')->fetchAll(PDO::FETCH_KEY_PAIR);
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
                'icone' => '', 'classes' => '', 'ordre' => count($liste) + 1, 'actif' => 1, 'urls_secours' => '',
                'url_local' => '', 'url_github' => '', 'url_gitlab' => '', 'url_alwaysdata' => ''];
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

    entete('Applications');
    barre('applications');
    ?>
    <main>
        <div class="titre-section">
            <h1>Applications</h1>
            <div class="titre-actions">
                <?php if (!$afficher_form): ?><a class="btn" href="./?p=applications&nouveau=1" title="Ajouter une application au portail des élèves (son adresse, son icône, ses classes)">Brancher une application</a><?php endif; ?>
                <a class="btn secondaire" href="./?p=applications" title="Les ateliers de ces applications : propositions aux classes, parcours, imports">Voir leurs ateliers →</a>
            </div>
        </div>
        <p class="sous">Les applications du portail des élèves (<a href="../" target="_blank"><?= h(url_base()) ?>/</a>) :
            l'élève les ouvre d'un clic, déjà connecté, et revient au portail avec le bouton « ← Mes ateliers ».</p>
        <?php messages(); ?>
        <?php if (!$modifier && !$brouillon) { carte_outils_enseignant(); carte_brancher_passerelle(); } ?>

        <?php if ($suggestions && !$afficher_form): ?>
            <p class="carte">Applications déjà branchées mais pas encore sur le portail :
                <?php foreach ($suggestions as $s): ?>
                    <a class="btn petit secondaire" href="./?p=applications&app=<?= rawurlencode($s) ?>">+ <?= h($s) ?></a>
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
                <fieldset class="emplacements">
                    <legend>Emplacements de l'application</legend>
                    <p class="aide">Le portail ouvre le premier emplacement qui répond, dans l'ordre réglé plus bas
                        (« Ordre des emplacements »). Laissez vide un emplacement qui n'existe pas.</p>
                    <?php $exemples = ['local' => '/miroir/netsim/', 'github' => 'https://grandiosian.github.io/NetSim/',
                        'gitlab' => 'https://lionelcaruana.forge.apps.education.fr/netsim/', 'alwaysdata' => 'https://technoversailles.alwaysdata.net/miroir/netsim/',
                        'domaine' => 'vide = ' . (domaine_centrale() ?: 'https://technoversailles.fr') . '/miroir/netsim/ (automatique)'];
                    foreach (EMPLACEMENTS as $type => $libelle): ?>
                        <label><?= h($libelle) ?>
                            <?php if ($type === 'local'): ?><span class="aide">Copie servie par cette Centrale (<code>/miroir/…/</code>, voir miroirs.txt) ou adresse d'un dossier local (<code>http://localhost:8080/…</code>).</span><?php endif; ?>
                            <?php if ($type === 'domaine'): ?><span class="aide">Laissez vide : la copie <code>/miroir/…/</code> de cette Centrale, à son nom de domaine (réglé dans « Ordre des emplacements »).</span><?php endif; ?>
                            <input name="url_<?= $type ?>" class="mono" value="<?= h($courant['url_' . $type] ?? '') ?>" placeholder="<?= h($exemples[$type]) ?>"></label>
                    <?php endforeach; ?>
                    <label>Autres adresses (facultatives)
                        <span class="aide">Une par ligne : essayées après les emplacements ci-dessus.</span>
                        <textarea name="urls_secours" rows="2" class="mono"><?= h($courant['urls_secours']) ?></textarea>
                    </label>
                </fieldset>
                <label>Description (facultative)<input name="description" maxlength="300" value="<?= h($courant['description']) ?>" placeholder="Construire et tester des réseaux"></label>
                <div class="grille-atelier reglages-atelier">
                    <label>Classes<span class="aide">Ex. 5A, 5B. Vide = toutes.</span><input name="classes" value="<?= h($courant['classes']) ?>"></label>
                    <label>Ordre<span class="aide">Position sur le portail.</span><input type="number" name="ordre" value="<?= (int) $courant['ordre'] ?>"></label>
                    <label class="case"><input type="checkbox" name="actif" value="1" <?= $courant['actif'] ? 'checked' : '' ?>> Visible par les élèves</label>
                </div>
                <div class="ligne-actions">
                    <button>Enregistrer</button>
                    <?php if ($liste): ?><a href="./?p=applications">Annuler</a><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($modifier && $courant['app'] !== ''): $av_liste = $activations[$courant['app']] ?? []; ?>
            <section class="carte" id="activation">
                <h2>Disponibilité (calendrier)</h2>
                <p class="aide">Sans fenêtre ci-dessous, l'atelier est visible en permanence. Ajoute une fenêtre pour ne l'ouvrir
                    qu'à certaines dates, ou le jour où la classe a cours — pratique pour les applis hébergées ailleurs
                    (GitLab / GitHub Pages) : elles apparaissent sur le portail le jour J.</p>
                <?php if ($av_liste): ?>
                    <div class="defile"><table class="table-activations">
                        <thead><tr><th>Classe</th><th>Quand</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($av_liste as $av): ?>
                            <tr>
                                <td><?= h($classes_liste[$av['classe_id']] ?? ('#' . $av['classe_id'])) ?><?= $av['groupe_id'] ? ' · groupe ' . (int) $av['groupe_id'] : '' ?></td>
                                <td><?php if ($av['mode'] === 'cours'): ?>Le jour du cours<?= $av['pendant_cours'] ? ' (pendant le créneau)' : '' ?>
                                    <?php else: ?><?= $av['debut'] ? 'du ' . h($av['debut']) : '' ?> <?= $av['fin'] ? 'au ' . h($av['fin']) : ($av['debut'] ? '(sans fin)' : 'toujours (si active)') ?><?php endif; ?></td>
                                <td class="actions"><?= bouton('supprimer_activation', 'Retirer', ['activation_id' => (int) $av['id'], 'atelier_id' => (int) $modifier], 'danger') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php else: ?>
                    <p class="vide">Aucune fenêtre : atelier visible en permanence.</p>
                <?php endif; ?>
                <form method="post" class="form-activation">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="enregistrer_activation">
                    <input type="hidden" name="atelier_id" value="<?= (int) $modifier ?>">
                    <input type="hidden" name="app" value="<?= h($courant['app']) ?>">
                    <label>Classe<select name="classe_id" required><option value="">— choisir —</option><?= options_html($classes_liste, '') ?></select></label>
                    <label>Mode<select name="mode" id="mode-activation">
                        <option value="periode">Période de dates</option>
                        <option value="cours">Le jour du cours</option>
                    </select></label>
                    <label class="champ-periode">Du<input type="date" name="debut"></label>
                    <label class="champ-periode">au<input type="date" name="fin"></label>
                    <label class="case champ-cours"><input type="checkbox" name="pendant_cours" value="1"> Seulement pendant le créneau</label>
                    <button>Ajouter</button>
                </form>
                <script>
                (function () {
                    var m = document.getElementById('mode-activation'); if (!m) return;
                    function maj() {
                        var cours = m.value === 'cours';
                        document.querySelectorAll('.form-activation .champ-periode').forEach(function (e) { e.style.display = cours ? 'none' : ''; });
                        document.querySelectorAll('.form-activation .champ-cours').forEach(function (e) { e.style.display = cours ? '' : 'none'; });
                    }
                    m.addEventListener('change', maj); maj();
                })();
                </script>
            </section>
        <?php endif; ?>

        <?php $avec_test = $liste && !$afficher_form ? carte_tester('./?p=applications') : false; ?>
        <?php if ($liste && !$afficher_form) { carte_simulees('./?p=applications'); } ?>
        <?php if ($liste): ?>
            <div class="defile" style="margin-top:20px"><table>
                <thead><tr><th></th><th>Atelier</th><th>Passerelle</th><th>Activités</th><th>Ouvertures (7 j)</th><th>Classes</th><th>État</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($liste as $a): ?>
                    <tr class="<?= $a['actif'] ? '' : 'inactif' ?>">
                        <td class="icone-grande"><?= icone_app((string) $a['app'], $a['icone']) ?></td>
                        <td><strong><?= h($a['nom']) ?></strong> <code><?= h($a['app']) ?></code>
                            <?php $choisie = meilleure_adresse($a);
                            foreach (emplacements_atelier($a) as $e): $u = $e['url']; $repond = $e['utilise'] && adresse_repond($u); ?>
                                <br><?php if ($e['utilise']): ?><span class="<?= $repond ? 'ok' : 'alerte' ?>" title="<?= $repond ? 'Répond' : 'Ne répond pas' ?> (vérifié il y a moins de 5 min)"><?= $repond ? '●' : '○' ?></span><?php else: ?><span class="discret" title="Pas utilisé ici (voir « Ordre des emplacements »)">–</span><?php endif; ?>
                                <span class="discret"><?= h(EMPLACEMENTS[$e['type']] ?? 'Autre') ?> :</span>
                                <a href="<?= h($u) ?>" target="_blank" rel="noopener" class="discret"><?= h($u) ?></a><?= $u === $choisie ? ' <strong>← ouvert par le portail</strong>' : '' ?>
                            <?php endforeach; ?></td>
                        <td><?= cellule_passerelle($a) ?></td>
                        <td><?= count($catalogue[$a['app']] ?? []) ?: '<span class="discret" title="Les activités apparaissent quand l\'application les déclare (à la première connexion d\'un élève)">—</span>' ?></td>
                        <td class="discret"><?php
                            $o = $ouvertures[$a['app']] ?? [];
                            arsort($o);
                            echo $o ? implode('<br>', array_map(fn($k, $n) => h($k) . ' : ' . $n, array_keys($o), $o)) : '—';
                        ?></td>
                        <td><?= $a['classes'] !== '' ? h($a['classes']) : '<span class="discret">toutes</span>' ?>
                            <?php $nav = count($activations[$a['app']] ?? []); if ($nav): ?><br><a class="discret" href="./?p=applications&modifier=<?= (int) $a['id'] ?>#activation" title="Fenêtres d'activation par calendrier">📅 <?= $nav ?> activation<?= $nav > 1 ? 's' : '' ?></a><?php endif; ?></td>
                        <td><?= $a['actif'] ? 'Visible' : 'Masqué' ?></td>
                        <td class="actions">
                            <?= $avec_test ? bouton_tester('app:' . $a['app'], 'Ouvrir cet atelier connecté comme l’élève test choisi au-dessus') : '' ?>
                            <?php if ($a['app'] === 'technostudio'): ?>
                                <a class="btn petit" href="../miroir/technostudio/?mode=enseignant" target="_blank" rel="noopener" title="Ouvre TechnoStudio en mode enseignant : toute l’interface, sans verrou (vous êtes connecté ici)">Mode enseignant</a>
                                <a class="btn petit secondaire" href="../miroir/technostudio/?mode=eleve" target="_blank" rel="noopener" title="Ouvre TechnoStudio comme le voient les élèves : parcours guidé, questions débloquées une à une">Mode élève</a>
                            <?php endif; ?>
                            <a class="btn petit secondaire" href="./?p=suivi&vue=app:<?= rawurlencode($a['app']) ?>">Suivi</a>
                            <a class="btn petit secondaire" href="./?p=applications&modifier=<?= (int) $a['id'] ?>">Modifier</a>
                            <?= bouton('supprimer_atelier', 'Retirer', ['atelier_id' => $a['id']], 'danger', 'Retirer cet atelier du portail ?') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <?php if (!$afficher_form): $lieu_auto = reglage('lieu_centrale', 'auto'); ?>
            <form method="post" class="carte" id="emplacements" style="margin-top:20px">
                <?= csrf() ?>
                <input type="hidden" name="action" value="emplacements_apps">
                <h2>Ordre des emplacements</h2>
                <p class="aide">Pour chaque application, le portail essaie ses emplacements dans cet ordre et ouvre le premier qui répond.
                    Cette Centrale tourne <strong><?= lieu_centrale() === 'local' ? 'en local' : 'en ligne' ?></strong>
                    (<?= h($_SERVER['HTTP_HOST'] ?? '') ?>).</p>
                <label>Où tourne cette Centrale ?
                    <select name="lieu_centrale">
                        <option value="auto" <?= $lieu_auto === 'auto' ? 'selected' : '' ?>>Détection automatique (localhost, 192.168…, 10… = local)</option>
                        <option value="local" <?= $lieu_auto === 'local' ? 'selected' : '' ?>>En local (ordinateur de la classe)</option>
                        <option value="en_ligne" <?= $lieu_auto === 'en_ligne' ? 'selected' : '' ?>>En ligne (serveur alwaysdata…)</option>
                    </select></label>
                <label>Nom de domaine de cette Centrale
                    <span class="aide">L'adresse officielle du site (ex. <code>https://technoversailles.fr</code>), qui sert les mêmes pages que le serveur alwaysdata.
                        Emplacement « Nom de domaine » : sa copie <code>/miroir/…/</code>. Vide = aucun.</span>
                    <input name="domaine_centrale" class="mono" value="<?= h(domaine_centrale()) ?>" placeholder="https://technoversailles.fr"></label>
                <?php foreach (['local' => 'Centrale en local', 'en_ligne' => 'Centrale en ligne'] as $l => $titre): $ordre = ordre_emplacements($l); ?>
                    <p><?= h($titre) ?> :
                        <?php for ($k = 0; $k < count(EMPLACEMENTS); $k++): ?>
                            <?= $k ? '→' : '' ?>
                            <select name="ordre_<?= $l ?>[]" aria-label="<?= h($titre) ?>, choix <?= $k + 1 ?>">
                                <option value="">—</option>
                                <?php foreach (EMPLACEMENTS as $type => $libelle): ?>
                                    <option value="<?= $type ?>" <?= ($ordre[$k] ?? '') === $type ? 'selected' : '' ?>><?= h($libelle) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endfor; ?></p>
                <?php endforeach; ?>
                <p class="aide">Par défaut, « Local » ne sert que si la Centrale est en local : depuis un serveur en ligne, les élèves ne peuvent pas joindre l'ordinateur du professeur.</p>
                <div class="ligne-actions"><button>Enregistrer</button></div>
            </form>
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
            <thead><tr><th>Atelier</th><th>Activité</th><th>État</th><th>Résultat</th><th>Temps actif · essais</th><th>Commencée</th><th>Terminée</th><th>Détails</th></tr></thead>
            <tbody>
            <?php foreach ($lignes as $p): $etat = etat_etape($p['activite'], [$p['activite'] => $p], []); ?>
                <tr>
                    <td><code><?= h($p['app']) ?></code></td>
                    <td><?= h($p['titre'] !== '' ? $p['titre'] : $p['activite']) ?></td>
                    <td><?= $p['statut'] === 'termine' ? '<span class="ok">✓ terminée</span>' : 'en cours' ?></td>
                    <td><?= h($etat['texte']) ?></td>
                    <td><?= a_des_mesures($p) ? h(texte_mesures($p)) . ($p['delai_median'] !== null ? '<br><span class="discret">1re réponse après ' . duree_courte((int) $p['delai_median']) . ' (médiane)</span>' : '') : '<span class="discret">—</span>' ?></td>
                    <td><?= quand((int) $p['debut']) ?></td>
                    <td><?= quand($p['fin'] ? (int) $p['fin'] : null) ?></td>
                    <td><?php $dq = json_decode((string) ($p['mesures'] ?? ''), true) ?: []; ?>
                        <?php if ($dq): ?><details><summary>questions</summary><?= tableau_questions_mesures($dq) ?></details><?php endif; ?>
                        <?php if ($p['details']): ?><details><summary>réponses</summary><pre class="code"><?=
                        h((string) json_encode(json_decode($p['details']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}
