<?php
/**
 * Centrale élèves — espace enseignant : bibliothèque de toutes les activités de toutes les applications.
 * L'enseignant voit tout, essaie une activité avec le compte test, la propose à des classes ou des groupes
 * (avec une date et une consigne facultatives) ou l'ajoute à un parcours.
 */
declare(strict_types=1);

function action_bibliotheque(string $action): bool
{
    $pdo = db();
    switch ($action) {
        case 'proposer':
            $sel = selection_bibliotheque();
            $cibles = array_values(array_filter(array_map(fn($v) => lire_classe_groupe((string) $v), (array) ($_POST['cibles'] ?? [])),
                fn($c) => $c[0] > 0 && classe_par_id($c[0])));
            if (!$sel || !$cibles) {
                flash(!$sel ? 'Cochez au moins une activité.' : 'Choisissez au moins une classe ou un groupe.', 'err');
                rediriger('./?p=bibliotheque');
            }
            $pour = (string) ($_POST['pour_le'] ?? '');
            $pour = date_valide($pour) ? $pour : '';
            $consigne = mb_substr(trim((string) ($_POST['consigne'] ?? '')), 0, 300);
            $ins = $pdo->prepare('INSERT INTO propositions (app, activite, classe_id, groupe_id, pour_le, consigne, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?)
                                  ON CONFLICT (app, activite, classe_id, groupe_id) DO UPDATE SET pour_le = excluded.pour_le, consigne = excluded.consigne');
            $pdo->beginTransaction();
            foreach ($sel as [$app, $act]) {
                foreach ($cibles as [$cid, $gid]) {
                    $ins->execute([$app, $act, $cid, (int) $gid, $pour, $consigne, time()]);
                }
            }
            $pdo->commit();
            $n = count($sel);
            flash($n . ' activité' . ($n > 1 ? 's proposées' : ' proposée') . ' à ' . implode(', ', array_map(fn($c) => nom_cible($c[0], $c[1]), $cibles))
                . ($pour ? ' pour le ' . rtrim(date_fr($pour), '.') : '') . '. Les élèves ' . ($n > 1 ? 'les' : 'la') . ' voient en haut de leur portail.');
            rediriger('./?p=bibliotheque' . filtres_retour());

        case 'retirer_proposition':
            $pdo->prepare('DELETE FROM propositions WHERE id = ?')->execute([(int) ($_POST['proposition_id'] ?? 0)]);
            flash('Proposition retirée (le travail déjà fait par les élèves est conservé).');
            rediriger('./?p=bibliotheque' . filtres_retour());

        case 'ajouter_au_parcours':
            $sel = selection_bibliotheque();
            if (!$sel) {
                flash('Cochez au moins une activité.', 'err');
                rediriger('./?p=bibliotheque');
            }
            $cat = catalogue_activites();
            $ateliers = [];
            foreach ($pdo->query('SELECT * FROM ateliers')->fetchAll() as $a) {
                $ateliers[$a['app']] = $a;
            }
            $etapes = array_map(fn($x) => ['titre' => mb_substr((string) ($cat[$x[0]][$x[1]]['titre'] ?? $x[1]), 0, 80) ?: $x[1],
                'app' => $x[0], 'activite' => $x[1], 'url' => (string) ($ateliers[$x[0]]['url'] ?? ''),
                'consigne' => '', 'entrees' => '', 'sorties' => ''], $sel);
            $pid = (int) ($_POST['parcours_id'] ?? 0);
            if (!$pid) {   // nouveau parcours : formulaire prérempli, à nommer et enregistrer
                $_SESSION['brouillon_parcours'] = ['parcours_id' => 0, 'nom' => '', 'description' => '', 'classes' => '',
                    'etapes' => etapes_en_texte($etapes), 'mode' => 'individuel', 'deblocage' => 'libre'];
                flash(count($etapes) . ' étape(s) préparée(s) : donnez un nom au parcours puis enregistrez-le.');
                rediriger('./?p=parcours&nouveau=1');
            }
            if (!parcours_par_id($pid)) {
                rediriger('./?p=bibliotheque');
            }
            $st = $pdo->prepare('SELECT COALESCE(MAX(ordre), -1) + 1 FROM parcours_etapes WHERE parcours_id = ?');
            $st->execute([$pid]);
            $ordre = (int) $st->fetchColumn();
            $ins = $pdo->prepare('INSERT INTO parcours_etapes (parcours_id, ordre, titre, app, activite, url, consigne, entrees, sorties) VALUES (?, ?, ?, ?, ?, ?, \'\', \'\', \'\')');
            foreach ($etapes as $i => $e) {
                $ins->execute([$pid, $ordre + $i, $e['titre'], $e['app'], $e['activite'], $e['url']]);
            }
            flash(count($etapes) . ' étape(s) ajoutée(s) à la fin du parcours.');
            rediriger('./?p=parcours&modifier=' . $pid);

        case 'essayer_activite':
            $app = normaliser_app((string) ($_POST['app'] ?? ''));
            $st = $pdo->prepare('SELECT * FROM ateliers WHERE app = ?');
            $st->execute([$app]);
            $a = $st->fetch();
            if (!$a) {
                flash("Cet atelier n'est pas sur le portail : ajoutez-le dans l'onglet Ateliers pour l'essayer.", 'err');
                rediriger('./?p=bibliotheque');
            }
            $morceaux = ['centrale=' . rawurlencode(jeton_compte_test()), 'retour=' . rawurlencode(url_base() . '/')];
            $act = activite_propre((string) ($_POST['activite'] ?? ''));
            if ($act !== '') {
                $morceaux[] = 'activite=' . rawurlencode($act);
            }
            rediriger(preg_replace('/#.*$/', '', meilleure_adresse($a)) . '#' . implode('&', $morceaux));

        case 'portail_activites':
            definir_reglage('portail_activites', ($_POST['valeur'] ?? '') === 'toutes' ? 'toutes' : 'proposees');
            flash(($_POST['valeur'] ?? '') === 'toutes'
                ? 'Le portail montre toutes les activités de chaque atelier.'
                : 'Le portail ne montre que les activités proposées (et celles que l’élève a déjà commencées).');
            rediriger('./?p=bibliotheque');
    }
    return false;
}

/** Activités cochées : [[app, activite], …] (seulement celles du catalogue). */
function selection_bibliotheque(): array
{
    $cat = catalogue_activites();
    $res = [];
    foreach ((array) ($_POST['sel'] ?? []) as $v) {
        [$app, $act] = explode('|', (string) $v, 2) + [1 => ''];
        if (isset($cat[$app][$act])) {
            $res[$app . '|' . $act] = [$app, $act];
        }
    }
    return array_values($res);
}

/** Filtres de la page gardés après une action (?niveau=…&app=…). */
function filtres_retour(): string
{
    $q = [];
    foreach (['niveau', 'app', 'vue', 'competence'] as $k) {
        $v = (string) ($_POST['f_' . $k] ?? '');
        if ($v !== '' && preg_match('/^[A-Za-z0-9.:_-]{1,40}$/', $v)) {
            $q[] = $k . '=' . rawurlencode($v);
        }
    }
    return $q ? '&' . implode('&', $q) : '';
}

function nom_cible(int $cid, ?int $gid): string
{
    $c = classe_par_id($cid);
    $g = $gid ? groupe_par_id($gid) : null;
    return ($c['nom'] ?? '?') . ($g ? ' · ' . $g['nom'] : '');
}

function date_fr(string $date): string
{
    $t = strtotime($date);
    return $t ? ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'][(int) date('w', $t)] . ' ' . date('j', $t) . ' '
        . ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'][(int) date('n', $t)] : $date;
}

/**
 * Ateliers de la bibliothèque (catalogue des ateliers, sinon un atelier par groupe d'activités), avec tout ce que la
 * Centrale en connaît : résumé, compétences, activités (détail de centrale.json, suivi, propositions), ressources.
 * Renvoie [ateliers par clé « app|atelier », clé de l'atelier par « app|activite »].
 */
function ateliers_bibliotheque(array $items, array $props): array
{
    $par_act = [];
    foreach ($items as $x) {
        $par_act[$x['app'] . '|' . $x['activite']] = $x;
    }
    $ressources = [];
    foreach (db()->query('SELECT app, nom, taille FROM ressources ORDER BY nom') as $r) {
        $ressources[$r['app']][] = ['nom' => $r['nom'], 'taille' => format_taille((int) $r['taille'])];
    }
    $details = [];   // détail des activités dans centrale.json, par application
    $detail = function (string $app, string $id) use (&$details): array {
        if (!isset($details[$app])) {
            $details[$app] = activites_descripteur(descripteur_de($app));
        }
        return $details[$app][$id] ?? [];
    };
    $activite = function (string $app, array $a) use ($par_act, $props, $detail): array {
        $cle = $app . '|' . $a['id'];
        $x = $par_act[$cle] ?? null;
        $d = $detail($app, $a['id']);
        $texte = fn($v) => is_string($v) ? mb_substr(trim($v), 0, 600) : '';
        return [
            'id' => $a['id'], 'titre' => (string) ($a['titre'] ?? $a['id']),
            'total' => isset($a['total']) && is_numeric($a['total']) ? (int) $a['total'] : null,
            'competences' => liste_codes_competences(is_array($a['competences'] ?? null) ? implode(',', $a['competences']) : (string) ($a['competences'] ?? '')),
            'resume' => $texte($d['resume'] ?? $d['description'] ?? ''),
            'categorie' => $texte($d['categorie_titre'] ?? ''), 'type' => $texte($d['type'] ?? ''), 'duree' => $texte($d['duree'] ?? ''),
            'questions' => array_values(array_filter(array_map($texte, (array) ($d['questions'] ?? [])))),
            'dans_bibliotheque' => $x !== null, 'essayer' => $x !== null && $x['atelier'] !== null,
            'terminees' => $x['terminees'] ?? 0, 'commencees' => $x['commencees'] ?? 0,
            'propositions' => array_map(fn($q) => ['cible' => $q['classe'] . ($q['groupe'] ? ' · ' . $q['groupe'] : ''),
                'pour_le' => $q['pour_le'] ? date_fr($q['pour_le']) : '', 'consigne' => (string) $q['consigne']], $props[$cle] ?? []),
        ];
    };
    $niveaux = ['6e' => '6', '5e' => '5', '4e' => '4', '3e' => '3', 'lycee' => 'lycee'];
    $res = [];
    $atelier_de = [];
    foreach (catalogue_ateliers(null, false) as $r) {
        $cle = $r['app'] . '|' . $r['atelier'];
        $acts = array_map(fn($a) => $activite($r['app'], $a), $r['activites']);
        foreach ($acts as $a) {
            $atelier_de[$r['app'] . '|' . $a['id']] ??= $cle;
        }
        $res[$cle] = ['cle' => $cle, 'app' => $r['app'], 'nom_app' => $r['nom_app'] ?? $r['app'], 'icone' => $r['icone_app'] ?? '',
            'atelier' => $r['atelier'], 'titre' => $r['titre'], 'niveau' => $niveaux[$r['niveau']] ?? '', 'theme' => $r['theme'],
            'resume' => $r['resume'], 'duree' => $r['duree'], 'version' => $r['version'], 'auteur' => $r['auteur'],
            'source' => SOURCES_CATALOGUE[$r['source']] ?? $r['source'], 'ouvrir' => adresse_ouvrir_atelier($r), 'fichier' => $r['fichier'],
            'competences' => $r['codes'], 'activites' => $acts, 'ressources' => $ressources[$r['app']] ?? []];
    }
    // Activités hors catalogue (application sans ateliers importés) : un atelier par groupe
    foreach ($items as $x) {
        $k = $x['app'] . '|' . $x['activite'];
        if (isset($atelier_de[$k])) {
            continue;
        }
        $cle = $x['app'] . '|groupe:' . ($x['groupe'] !== '' ? $x['groupe'] : $x['activite']);
        $atelier_de[$k] = $cle;
        $res[$cle] ??= ['cle' => $cle, 'app' => $x['app'], 'nom_app' => $x['atelier']['nom'] ?? $x['app'], 'icone' => $x['atelier']['icone'] ?? '',
            'atelier' => '', 'titre' => $x['groupe'] !== '' ? $x['groupe'] : $x['titre'], 'niveau' => $x['niveau'], 'theme' => '',
            'resume' => '', 'duree' => '', 'version' => '', 'auteur' => '', 'source' => 'activités de la bibliothèque (pas d’atelier importé)',
            'ouvrir' => '', 'fichier' => '', 'competences' => [], 'activites' => [], 'ressources' => $ressources[$x['app']] ?? []];
        $res[$cle]['activites'][] = $activite($x['app'], ['id' => $x['activite'], 'titre' => $x['titre'], 'total' => $x['total'], 'competences' => $x['competences']]);
        $res[$cle]['competences'] = array_values(array_unique(array_merge($res[$cle]['competences'], liste_codes_competences($x['competences']))));
    }
    return [$res, $atelier_de];
}

function page_bibliotheque(): void
{
    $pdo = db();
    $items = bibliotheque();
    $props = propositions_par_activite();
    $classes = [];
    foreach ($pdo->query("SELECT c.*, et.nom AS etab FROM classes c LEFT JOIN etablissements et ON et.id = c.etablissement_id
                          WHERE c.nom <> 'TEST' ORDER BY et.nom IS NULL, et.nom, c.nom")->fetchAll() as $c) {
        $classes[] = $c;
    }
    $parcours = $pdo->query('SELECT id, nom FROM parcours ORDER BY nom')->fetchAll();
    $apps = [];
    foreach ($items as $x) {
        $apps[$x['app']] = $x['atelier']['nom'] ?? $x['app'];
    }
    $niveaux = array_count_values(array_column($items, 'niveau'));
    // Compétences présentes dans la bibliothèque, groupées par thème (pour le filtre)
    $index_comp = index_competences();
    $codes_par_theme = [];
    foreach ($items as $x) {
        foreach (liste_codes_competences($x['competences'] ?? '') as $code) {
            $theme = $index_comp[$code]['theme'] ?? substr($code, 0, 3);
            $codes_par_theme[$theme][$code] = true;
        }
    }
    ksort($codes_par_theme);
    foreach ($codes_par_theme as &$cs) {
        $cs = array_keys($cs);
        sort($cs);
    }
    unset($cs);
    $f_niveau = (string) ($_GET['niveau'] ?? '');
    $f_app = (string) ($_GET['app'] ?? '');
    $f_vue = (string) ($_GET['vue'] ?? '');
    $f_comp = (string) ($_GET['competence'] ?? '');
    $toutes = !portail_seulement_proposees();
    [$ateliers_b, $atelier_de] = ateliers_bibliotheque($items, $props);
    // Groupe d'activités qui est un seul atelier (TechnoStudio) : le bouton « Voir l'atelier » va dans son titre
    $ateliers_groupe = [];
    foreach ($items as $x) {
        $ateliers_groupe[$x['app'] . '|' . $x['groupe']][$atelier_de[$x['app'] . '|' . $x['activite']]] = true;
    }
    $libelles = [];
    foreach ($ateliers_b as $at) {
        foreach (array_merge($at['competences'], ...array_column($at['activites'], 'competences')) as $code) {
            $k = $index_comp[$code] ?? null;
            $libelles[$code] ??= ['texte' => $k['texte'] ?? 'Code inconnu du référentiel', 'theme' => $k['theme'] ?? '', 'couleur' => couleur_theme($k['theme'] ?? null)];
        }
    }
    $par_app_b = [];
    foreach ($ateliers_b as $at) {
        $par_app_b[$at['app']][] = $at;
    }

    entete('Bibliothèque');
    barre('bibliotheque');
    ?>
    <main class="biblio">
        <div class="titre-section">
            <h1>Bibliothèque</h1>
            <a class="btn secondaire" href="./?p=ateliers">Brancher une application</a>
        </div>
        <p class="sous">Toutes les activités de vos applications, déclarées par leurs passerelles. Cochez-en puis proposez-les à une classe
            ou à un groupe : les élèves les retrouvent en haut de leur portail, avec la date et votre consigne.</p>
        <?php messages(); ?>

        <?php if (!$items): ?>
            <div class="carte"><p>Aucune activité connue pour l'instant. Branchez vos applications dans l'onglet
                <a href="./?p=ateliers">Ateliers</a> (« Lire la passerelle ») : leurs activités apparaîtront ici.</p></div>
        <?php else: ?>
        <div class="carte filtres-biblio" id="filtres-biblio">
            <input type="search" id="b-texte" placeholder="Chercher une activité…" aria-label="Chercher une activité">
            <div class="puces-niveaux" role="group" aria-label="Niveau">
                <button type="button" data-niveau="" class="<?= $f_niveau === '' ? 'actif' : '' ?>">Tous</button>
                <?php foreach (NIVEAUX_BIBLIOTHEQUE as $k => $lib): if (!isset($niveaux[$k])) continue; ?>
                    <button type="button" data-niveau="<?= h((string) $k) ?>" class="<?= $f_niveau === (string) $k ? 'actif' : '' ?><?= in_array((string) $k, ['3', '4', '5', '6'], true) ? ' niv' : '' ?>"
                        <?= isset(COULEURS_NIVEAUX[(string) $k]) ? 'style="--niv:' . COULEURS_NIVEAUX[(string) $k] . '"' : '' ?>><?= h($lib) ?> <small><?= $niveaux[$k] ?></small></button>
                <?php endforeach; ?>
            </div>
            <select id="b-app" aria-label="Application">
                <option value="">Toutes les applications</option>
                <?php foreach ($apps as $k => $n): ?><option value="<?= h($k) ?>"<?= $f_app === $k ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?>
            </select>
            <select id="b-vue" aria-label="Propositions">
                <option value="">Toutes les activités</option>
                <option value="proposees"<?= $f_vue === 'proposees' ? ' selected' : '' ?>>Déjà proposées</option>
                <?php foreach ($classes as $c): ?><option value="c:<?= (int) $c['id'] ?>"<?= $f_vue === 'c:' . $c['id'] ? ' selected' : '' ?>>Proposées à <?= h($c['nom']) ?></option><?php endforeach; ?>
            </select>
            <?php if ($codes_par_theme): ?>
            <select id="b-competence" aria-label="Compétence">
                <option value="">Toutes les compétences</option>
                <?php foreach ($codes_par_theme as $theme => $codes): ?>
                    <optgroup label="<?= h($theme) ?>">
                        <?php foreach ($codes as $code): $lib = $index_comp[$code]['texte'] ?? ''; ?>
                            <option value="<?= h($code) ?>"<?= $f_comp === $code ? ' selected' : '' ?>><?= h($code) ?><?= $lib ? ' — ' . h(mb_strimwidth($lib, 0, 40, '…')) : '' ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button type="button" id="b-condense" class="btn petit secondaire" aria-pressed="false" title="Affichage condensé : une ligne par activité">Condensé</button>
            <button type="button" id="b-voir-ateliers" class="btn petit secondaire" aria-expanded="false" aria-controls="b-panneau-ateliers"
                title="Choisir un ou plusieurs ateliers pour n'afficher que leurs activités, voir leur détail ou les comparer">Ateliers…</button>
            <span class="discret" id="b-compte"></span>
            <div class="sel-ateliers" id="b-sel-ateliers" hidden>
                <strong id="b-sel-ateliers-n"></strong>
                <span class="puces-sel" id="b-puces-ateliers"></span>
                <button type="button" class="petit" id="b-comparer">Comparer</button>
                <button type="button" class="lien-bouton" id="b-effacer-ateliers">Tout afficher</button>
            </div>
        </div>

        <div class="carte panneau-ateliers" id="b-panneau-ateliers" hidden>
            <p class="aide">Cochez des ateliers : la bibliothèque n'affiche plus que leurs activités. « Détail » montre tout ce que
                la Centrale sait d'un atelier ; « Comparer » met les ateliers cochés côte à côte.</p>
            <div class="panneau-ateliers-apps">
            <?php foreach ($par_app_b as $app => $liste): ?>
                <section data-app="<?= h($app) ?>">
                    <h3><?= h($liste[0]['icone'] ?: '🧩') ?> <?= h($liste[0]['nom_app']) ?></h3>
                    <ul>
                    <?php foreach ($liste as $at): $nb = count($at['activites']); ?>
                        <li class="ligne-atelier" data-cle="<?= h($at['cle']) ?>">
                            <label><input type="checkbox" value="<?= h($at['cle']) ?>" aria-label="Choisir l'atelier <?= h($at['titre']) ?>">
                                <span><?php if (isset(COULEURS_NIVEAUX[$at['niveau']])): ?><span class="puce-niveau" style="--niv:<?= COULEURS_NIVEAUX[$at['niveau']] ?>"><?= h(NIVEAUX_BIBLIOTHEQUE[$at['niveau']]) ?></span><?php endif; ?>
                                <span><?= h($at['titre']) ?></span>
                                <small class="discret"><?= $nb ?> activité<?= $nb > 1 ? 's' : '' ?></small></span></label>
                            <button type="button" class="petit secondaire voir-atelier" data-cle="<?= h($at['cle']) ?>">Détail</button>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
            </div>
        </div>

        <form method="post" id="form-retirer"><?= csrf() ?><input type="hidden" name="action" value="retirer_proposition">
            <input type="hidden" name="f_niveau" value="<?= h($f_niveau) ?>"><input type="hidden" name="f_app" value="<?= h($f_app) ?>"><input type="hidden" name="f_vue" value="<?= h($f_vue) ?>"></form>
        <form method="post" target="_blank" id="form-essayer"><?= csrf() ?><input type="hidden" name="action" value="essayer_activite">
            <input type="hidden" name="app"><input type="hidden" name="activite"></form>

        <form method="post" id="form-biblio">
            <?= csrf() ?>
            <input type="hidden" name="f_niveau" value="<?= h($f_niveau) ?>"><input type="hidden" name="f_app" value="<?= h($f_app) ?>"><input type="hidden" name="f_vue" value="<?= h($f_vue) ?>"><input type="hidden" name="f_competence" value="<?= h($f_comp) ?>">
            <div class="biblio-colonnes">
            <?php
            $app_courante = null;
            $groupe_courant = null;
            foreach ($items as $x):
                $cle = $x['app'] . '|' . $x['activite'];
                $p = $props[$cle] ?? [];
                if ($x['app'] !== $app_courante):
                    if ($app_courante !== null) echo '</ul></section>';
                    $app_courante = $x['app'];
                    $groupe_courant = null;
                    $a = $x['atelier']; ?>
                    <section class="biblio-app" data-app="<?= h($x['app']) ?>">
                        <h2><span class="icone-app"><?= h($a['icone'] ?? '') ?: '🧩' ?></span> <?= h($a['nom'] ?? $x['app']) ?>
                            <?php if (!$a): ?><span class="badge-alerte" title="Les élèves ne peuvent pas l'ouvrir depuis le portail">pas sur le portail</span>
                            <?php elseif (!$a['actif']): ?><span class="badge-alerte">masqué aux élèves</span><?php endif; ?>
                            <?php if (descripteur_de($x['app'])): ?><a class="discret petit-lien" href="./?p=passerelle&app=<?= rawurlencode($x['app']) ?>">passerelle</a><?php endif; ?></h2>
                        <ul class="biblio-liste">
                <?php endif;
                if ($x['groupe'] !== $groupe_courant):
                    $groupe_courant = $x['groupe'];
                    $un_atelier = count($ateliers_groupe[$x['app'] . '|' . $groupe_courant]) === 1 ? array_key_first($ateliers_groupe[$x['app'] . '|' . $groupe_courant]) : null;
                    if ($groupe_courant !== ''): ?><li class="biblio-groupe"><?= h($groupe_courant) ?>
                        <?php if ($un_atelier): ?><button type="button" class="lien-bouton voir-atelier" data-cle="<?= h($un_atelier) ?>">Voir l’atelier</button><?php endif; ?></li><?php endif;
                endif;
                $cle_atelier = $atelier_de[$cle]; ?>
                <li class="biblio-item" data-niveau="<?= h($x['niveau']) ?>" data-app="<?= h($x['app']) ?>" data-atelier="<?= h($cle_atelier) ?>"
                    data-classes="<?= h(implode(' ', array_unique(array_map(fn($q) => 'c:' . $q['classe_id'], $p)))) ?>"
                    data-texte="<?= h(mb_strtolower($x['titre'] . ' ' . $x['groupe'] . ' ' . $x['activite'] . ' ' . $x['description'])) ?>"
                    data-competences="<?= h(implode(' ', liste_codes_competences($x['competences'] ?? ''))) ?>">
                    <label class="coche"><input type="checkbox" name="sel[]" value="<?= h($cle) ?>" aria-label="Choisir <?= h($x['titre']) ?>"></label>
                    <div class="biblio-corps">
                        <div class="biblio-titre">
                            <?php if (isset(COULEURS_NIVEAUX[$x['niveau']])): ?><span class="puce-niveau" style="--niv:<?= COULEURS_NIVEAUX[$x['niveau']] ?>"><?= h(NIVEAUX_BIBLIOTHEQUE[$x['niveau']]) ?></span><?php endif; ?>
                            <strong><?= h($x['titre']) ?></strong>
                            <code class="discret"><?= h($x['activite']) ?></code>
                        </div>
                        <?php if ($x['description'] !== ''): ?><div class="discret"><?= h($x['description']) ?></div><?php endif; ?>
                        <div class="biblio-infos">
                            <?php if ($x['total']): ?><span><?= (int) $x['total'] ?> étape<?= $x['total'] > 1 ? 's' : '' ?></span><?php endif; ?>
                            <?php if (!$un_atelier || $groupe_courant === ''): ?><button type="button" class="lien-bouton voir-atelier" data-cle="<?= h($cle_atelier) ?>">Voir l’atelier</button><?php endif; ?>
                            <?php if ($x['commencees']): ?><span title="Élèves qui l'ont terminée / commencée">✓ <?= $x['terminees'] ?> / <?= $x['commencees'] ?> élève<?= $x['commencees'] > 1 ? 's' : '' ?></span><?php endif; ?>
                            <?php foreach ($p as $q): ?>
                                <span class="proposee" title="<?= h(trim(($q['pour_le'] ? 'Pour le ' . date_fr($q['pour_le']) . '. ' : '') . $q['consigne'])) ?>">
                                    <?= pastille_classe((int) $q['classe_id'], $q['groupe'] ?? '') ?><?= $q['pour_le'] ? '<small>' . h(date_fr($q['pour_le'])) . '</small>' : '' ?>
                                    <button form="form-retirer" name="proposition_id" value="<?= (int) $q['id'] ?>" class="retirer" title="Retirer la proposition à <?= h($q['classe'] . ($q['groupe'] ? ' · ' . $q['groupe'] : '')) ?>" aria-label="Retirer">×</button>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php $comps = liste_codes_competences($x['competences'] ?? ''); if ($comps): ?>
                            <div class="biblio-comps"><?php foreach ($comps as $code) echo puce_competence($code) . ' '; ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($x['atelier']): ?>
                        <button type="button" class="petit secondaire essayer" data-app="<?= h($x['app']) ?>" data-activite="<?= h($x['activite']) ?>"
                            title="Ouvrir cette activité avec le compte test (classe TEST), dans un nouvel onglet">Essayer</button>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul></section>
            </div>
            <p class="vide" id="b-rien" hidden>Aucune activité ne correspond à ces filtres.</p>

            <div class="barre-selection" id="barre-selection" hidden>
                <strong id="b-sel">0 activité choisie</strong>
                <details class="choix-cibles">
                    <summary>Proposer à…</summary>
                    <div class="cibles">
                        <?php $etab = false; foreach ($classes as $c):
                            if ($c['etab'] !== $etab): $etab = $c['etab']; ?><div class="cibles-etab"><?= h($etab ?? 'Sans établissement') ?></div><?php endif; ?>
                            <label class="cible"><input type="checkbox" name="cibles[]" value="<?= (int) $c['id'] ?>"> <?= pastille_classe($c) ?></label>
                            <?php foreach (groupes_de((int) $c['id']) as $g): ?>
                                <label class="cible"><input type="checkbox" name="cibles[]" value="<?= (int) $c['id'] . ':' . (int) $g['id'] ?>"> <?= pastille_classe($c, $g['nom']) ?></label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <p class="message erreur" id="b-err" hidden>Cochez au moins une classe ou un groupe.</p>
                    <div class="formulaire-ligne">
                        <label>Pour le (facultatif)<input type="date" name="pour_le"></label>
                        <label style="flex:1">Consigne (facultative)<input name="consigne" maxlength="300" placeholder="Fais au moins les 3 premières étapes."></label>
                        <button name="action" value="proposer">Proposer</button>
                    </div>
                </details>
                <div class="en-ligne">
                    <select name="parcours_id" aria-label="Parcours">
                        <option value="0">Nouveau parcours</option>
                        <?php foreach ($parcours as $pa): ?><option value="<?= (int) $pa['id'] ?>"><?= h($pa['nom']) ?></option><?php endforeach; ?>
                    </select>
                    <button name="action" value="ajouter_au_parcours" class="secondaire">Ajouter au parcours</button>
                </div>
                <button type="button" class="lien" id="b-aucune">Tout décocher</button>
            </div>
        </form>

        <dialog class="dialogue-atelier" id="b-dialogue" aria-labelledby="b-dialogue-titre">
            <div class="dialogue-tete">
                <h2 id="b-dialogue-titre"></h2>
                <button type="button" class="petit secondaire" id="b-fermer" aria-label="Fermer">Fermer</button>
            </div>
            <div id="b-dialogue-corps"></div>
        </dialog>
        <script type="application/json" id="b-donnees-ateliers"><?= json_encode(['ateliers' => $ateliers_b, 'competences' => $libelles,
            'niveaux' => NIVEAUX_BIBLIOTHEQUE, 'couleurs' => COULEURS_NIVEAUX,
            'themes' => array_combine(['OST', 'SFC', 'CCRI'], array_map('couleur_theme', ['OST', 'SFC', 'CCRI']))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

        <section class="carte reglage-portail">
            <h2>Ce que voient les élèves</h2>
            <p>En haut de leur portail : les activités proposées à leur classe ou à leur groupe (« À faire »), avec la date et la consigne.</p>
            <p>Dans la carte de chaque atelier :
                <strong><?= $toutes ? 'toutes les activités de l’atelier' : 'seulement les activités proposées et celles qu’ils ont déjà commencées' ?></strong>.
                Dans l’application elle-même, ils peuvent toujours tout ouvrir.</p>
            <?= bouton('portail_activites', $toutes ? 'Ne montrer que les activités proposées' : 'Montrer toutes les activités', ['valeur' => $toutes ? 'proposees' : 'toutes'], 'secondaire') ?>
        </section>
        <?php endif; ?>
    </main>
    <script src="bibliotheque.js?v=<?= @filemtime(__DIR__ . '/../public/admin/bibliotheque.js') ?: 1 ?>" defer></script>
    <?php
    pied();
}
