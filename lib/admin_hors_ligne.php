<?php
/**
 * Espace enseignant — « Travail hors ligne » (?p=hors_ligne) et validation des étapes dans le Suivi.
 * Mécanique (fichier autonome, chiffrement, enregistrement) : lib/hors_ligne.php.
 */
declare(strict_types=1);

function action_hors_ligne(string $action): void
{
    $pdo = db();
    switch ($action) {
        case 'hors_ligne_preparer':
            [$app, $atelier] = array_pad(explode('|', (string) ($_POST['atelier'] ?? ''), 2), 2, '');
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['eleves'] ?? [])))));
            $retour = './?p=hors_ligne&classe=' . (int) ($_POST['classe_id'] ?? 0);
            if (!$ids || $atelier === '') {
                flash('Choisissez un atelier et au moins un élève.', 'err');
                rediriger($retour);
            }
            try {
                $fichiers = array_map(fn($id) => construire_hors_ligne($id, normaliser_app($app), $atelier), $ids);
            } catch (RuntimeException $e) {
                flash($e->getMessage(), 'err');
                rediriger($retour);
            }
            if (count($fichiers) === 1) {
                [$nom, $html] = $fichiers[0];
                header('Content-Type: text/html; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $nom . '"');
                header('Content-Length: ' . strlen($html));
                echo $html;
                exit;
            }
            if (!class_exists('ZipArchive')) {
                flash('Plusieurs élèves : il faut l’extension ZIP de PHP. Préparez-les un par un.', 'err');
                rediriger($retour);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'hl');
            $zip = new ZipArchive();
            $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($fichiers as [$nom, $html]) {
                $zip->addFromString($nom, $html);
            }
            $zip->addFromString('LISEZ-MOI.txt', texte_lisez_moi_hors_ligne());
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="ateliers_hors_ligne_' . date('Y-m-d') . '.zip"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
            exit;

        case 'hors_ligne_parcours':
            $parcours_id = (int) ($_POST['parcours'] ?? 0);
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['eleves'] ?? [])))));
            $retour = './?p=hors_ligne&classe=' . (int) ($_POST['classe_id'] ?? 0) . '#parcours';
            $p = $parcours_id ? parcours_par_id($parcours_id) : null;
            if (!$ids || !$p) {
                flash('Choisissez un parcours et au moins un élève.', 'err');
                rediriger($retour);
            }
            if (!class_exists('ZipArchive')) {
                flash('Il faut l’extension ZIP de PHP pour préparer un parcours.', 'err');
                rediriger($retour);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'hl');
            $zip = new ZipArchive();
            $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            try {
                foreach ($ids as $id) {
                    $e = eleve_hors_ligne($id);
                    $dossier = nom_propre_hl($e['classe']) . '-' . nom_propre_hl($e['etiquette']) . '_' . substr(nom_propre_hl($p['nom']), 0, 40) . '/';
                    foreach (construire_parcours_hors_ligne($id, $parcours_id) as [$nom, $contenu]) {
                        $zip->addFromString($dossier . $nom, $contenu);
                    }
                }
            } catch (RuntimeException $x) {
                $zip->close();
                @unlink($tmp);
                flash($x->getMessage(), 'err');
                rediriger($retour);
            }
            $zip->addFromString('LISEZ-MOI.txt', texte_lisez_moi_hors_ligne(true));
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="parcours_hors_ligne_' . substr(nom_propre_hl($p['nom']), 0, 40) . '_' . date('Y-m-d') . '.zip"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
            exit;

        case 'hors_ligne_importer':
            $retour = (string) ($_POST['retour'] ?? '');
            $f = $_FILES['rendus'] ?? null;
            $n = 0;
            $classe_vue = 0;
            if ($f && is_array($f['name'])) {
                foreach ($f['name'] as $i => $nom) {
                    if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK) {
                        if (($f['error'][$i] ?? 0) !== UPLOAD_ERR_NO_FILE) {
                            flash("$nom : envoi refusé (fichier trop gros ?).", 'err');
                        }
                        continue;
                    }
                    $r = importer_rendu((string) file_get_contents($f['tmp_name'][$i]), (string) $nom);
                    flash($r['message'], $r['ok'] ? 'ok' : 'err');
                    $n++;
                    if (!empty($r['export'])) {
                        $st = $pdo->prepare('SELECT classe_id FROM eleves WHERE id = ?');
                        $st->execute([(int) $r['export']['eleve_id']]);
                        $classe_vue = (int) $st->fetchColumn();
                    }
                }
            }
            if (!$n) {
                flash('Aucun fichier reçu.', 'err');
            }
            rediriger($retour === 'suivi' ? './?p=suivi' . ($classe_vue ? '&classe=' . $classe_vue : '') : './?p=hors_ligne' . ($classe_vue ? '&classe=' . $classe_vue : '') . '#rendus');

        case 'hors_ligne_retirer':
            $pdo->prepare('DELETE FROM hors_ligne_exports WHERE id = ?')->execute([(string) ($_POST['export'] ?? '')]);
            flash('Fichier retiré : ses rendus ne pourront plus être ouverts (le travail déjà importé reste).');
            rediriger('./?p=hors_ligne&classe=' . (int) ($_POST['classe_id'] ?? 0) . '#prepares');

        case 'valider_activite_prof':
            $eleve = (int) ($_POST['eleve'] ?? 0);
            $app = normaliser_app((string) ($_POST['app'] ?? ''));
            $activite = activite_propre((string) ($_POST['activite'] ?? ''));
            $annuler = !empty($_POST['annuler']);
            $n = 0;
            $total = is_numeric($_POST['total'] ?? null) && (int) $_POST['total'] > 0 ? (int) $_POST['total'] : null;
            $parcours = (int) ($_POST['parcours'] ?? 0);
            $ordre = is_numeric($_POST['ordre'] ?? null) ? (int) $_POST['ordre'] : -1;
            if ($eleve && $parcours && $ordre >= 0 && ($p = parcours_par_id($parcours)) && isset($p['etapes'][$ordre])) {
                // Étape de parcours : validation du parcours (déblocage « après validation », étape « tout l'atelier »)
                // et, si l'étape est une activité précise, l'activité de chaque membre (élève ou équipe)
                $proprio = proprietaire_projet(['id' => $eleve], $p);
                if ($annuler) {
                    $st = $pdo->prepare('DELETE FROM parcours_validations WHERE parcours_id = ? AND proprietaire = ? AND ordre = ?');
                    $st->execute([$parcours, $proprio['cle'], $ordre]);
                    $n += $st->rowCount();
                } else {
                    $pdo->prepare('INSERT OR IGNORE INTO parcours_validations (parcours_id, proprietaire, ordre, valide_le) VALUES (?, ?, ?, ?)')
                        ->execute([$parcours, $proprio['cle'], $ordre, time()]);
                    $n++;
                }
                $et = $p['etapes'][$ordre];
                if ($et['activite'] !== '') {
                    $cat = catalogue_activites($et['app'])[$et['app']][$et['activite']] ?? [];
                    foreach ($proprio['membres'] as $m) {
                        $n += valider_activite_prof($m, $et['app'], $et['activite'], (string) ($cat['titre'] ?? $et['titre']),
                            isset($cat['total']) && (int) $cat['total'] > 0 ? (int) $cat['total'] : $total, $annuler) ? 1 : 0;
                    }
                }
            } elseif ($eleve && $app !== '*' && $activite !== '') {
                $n += valider_activite_prof($eleve, $app, $activite, (string) ($_POST['titre'] ?? ''), $total, $annuler) ? 1 : 0;
            }
            flash($n ? ($annuler ? 'Validation annulée.' : 'Étape validée : elle compte comme terminée (l’étape suivante est ouverte).')
                : 'Rien à changer.', $n ? 'ok' : 'err');
            $r = (string) ($_POST['retour'] ?? '');
            rediriger(str_starts_with($r, './?p=') ? $r : './?p=suivi');
    }
}

function texte_lisez_moi_hors_ligne(bool $parcours = false): string
{
    if ($parcours) {
        return "Parcours hors ligne — un dossier par élève\r\n\r\n"
            . "1. Copier TOUT le dossier de l'élève sur son ordinateur (les fichiers doivent rester ensemble).\r\n"
            . "2. Ouvrir « 00-Parcours-….html » en double-clic : la liste des étapes, chacune avec son bouton « Ouvrir ».\r\n"
            . "   Chaque application est un fichier du dossier (01-…, 02-…), qui s'ouvre dans le navigateur sans internet ni installation\r\n"
            . "   (Windows, macOS, Linux). Dans une application, le bouton bleu « 🧭 Parcours » montre aussi les étapes.\r\n"
            . "3. À la fin : dans CHAQUE application, bouton vert « Rendre mon travail » → un fichier .tvrendu chiffré par application.\r\n"
            . "4. L'élève envoie tous ses fichiers .tvrendu au professeur, qui les importe ensemble : Suivi → Importer un rendu hors ligne.\r\n";
    }
    return "Ateliers hors ligne — un fichier par élève\r\n\r\n"
        . "1. Copier le fichier de l'élève sur son ordinateur (clé USB, ENT…).\r\n"
        . "2. L'ouvrir en double-clic : il s'ouvre dans le navigateur (Firefox, Chrome, Edge ou Safari), sans internet ni installation,\r\n"
        . "   sous Windows, macOS ou Linux. Le travail est gardé sur l'ordinateur : on peut fermer et rouvrir le fichier.\r\n"
        . "3. À la fin : bouton vert « Rendre mon travail » → un fichier .tvrendu chiffré est enregistré (Téléchargements).\r\n"
        . "4. L'élève l'envoie au professeur, qui l'importe dans la Centrale : Suivi → Importer un rendu hors ligne.\r\n\r\n"
        . "Chaque fichier ne sert qu'à l'élève indiqué dans son nom. Ne pas ouvrir le fichier d'un autre élève sur le même ordinateur.\r\n";
}

function page_hors_ligne(): void
{
    $pdo = db();
    $classes = $pdo->query('SELECT id, nom FROM classes ORDER BY nom')->fetchAll();
    $classe_id = (int) ($_GET['classe'] ?? ($classes[0]['id'] ?? 0));
    $classe = $classe_id ? classe_par_id($classe_id) : null;
    $ateliers = ateliers_hors_ligne();
    $st = $pdo->prepare("SELECT id, etiquette FROM eleves WHERE classe_id = ? AND actif = 1
        ORDER BY CASE WHEN etiquette GLOB '[0-9]*' THEN CAST(etiquette AS INTEGER) ELSE 9999 END, etiquette");
    $st->execute([$classe_id]);
    $eleves_classe = $st->fetchAll();
    $manquantes = array_keys(array_filter(array_map(fn($app) => chemin_fichier_autonome($app) === null, array_combine(array_keys(fichiers_autonomes()), array_keys(fichiers_autonomes())))));

    entete('Travail hors ligne');
    barre('suivi');
    ?>
    <main class="large">
        <p class="fil"><a href="./?p=suivi&classe=<?= $classe_id ?>">← Suivi</a></p>
        <h1>Travail hors ligne</h1>
        <p class="sous">Pour un élève qui travaille sur un ordinateur portable sans internet (PAP…) : un atelier dans un seul fichier,
            qui s’ouvre en double-clic sous Windows, macOS ou Linux. À la fin, l’élève enregistre un fichier chiffré que vous importez ici :
            son avancement arrive dans le suivi comme s’il avait travaillé en ligne.</p>
        <?php messages(); ?>

        <form method="get" class="filtres">
            <input type="hidden" name="p" value="hors_ligne">
            <label>Classe
                <select name="classe" onchange="this.form.submit()">
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $classe_id ? 'selected' : '' ?>><?= h($c['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button>Afficher</button></noscript>
        </form>

        <section>
            <h2>1. Préparer l’atelier</h2>
            <?php if ($manquantes): ?>
                <p class="aide">Fichier autonome absent sur ce serveur pour : <?= h(implode(', ', $manquantes)) ?> (copie de l’application à reconstruire, <code>miroirs.txt</code>).</p>
            <?php endif; ?>
            <?php if (!$classe): ?>
                <p class="vide">Aucune classe.</p>
            <?php elseif (!array_filter($ateliers)): ?>
                <p class="vide">Aucun atelier disponible hors ligne (copies des applications absentes de ce serveur).</p>
            <?php else:
                $eleves = $eleves_classe; ?>
                <form method="post" class="carte">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="hors_ligne_preparer">
                    <input type="hidden" name="classe_id" value="<?= $classe_id ?>">
                    <label>Atelier
                        <select name="atelier" required>
                            <option value="">— choisir —</option>
                            <?php foreach ($ateliers as $app => $liste): ?>
                                <optgroup label="<?= h(nom_application_hl($app)) ?>">
                                    <?php foreach ($liste as $id => $a): ?>
                                        <option value="<?= h($app . '|' . $id) ?>"><?= h(($a['niveau'] ? $a['niveau'] . ' · ' : '') . ($a['titre'] ?: $id)) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <fieldset class="hl-eleves">
                        <legend>Élèves de <?= h($classe['nom']) ?> (un fichier chacun)</legend>
                        <?php foreach ($eleves as $e): ?>
                            <label class="case"><input type="checkbox" name="eleves[]" value="<?= (int) $e['id'] ?>">
                                n° <?= h($e['etiquette']) ?> — <?= nom_eleve($classe['nom'], $e['etiquette']) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="aide">Un élève : un fichier .html à copier sur son ordinateur. Plusieurs : un ZIP (un fichier par élève, avec une notice).
                        Chaque fichier porte une clé à lui : seul le fichier rendu par cet élève sera accepté pour lui.</p>
                    <button>Télécharger</button>
                </form>
            <?php endif; ?>
        </section>

        <section id="parcours">
            <h2>2. Préparer un parcours</h2>
            <?php
            $parcours = array_values(array_filter($pdo->query('SELECT id, nom, classes FROM parcours ORDER BY nom')->fetchAll(),
                fn($p) => $classe && classe_concernee((string) $p['classes'], $classe['nom'])));
            if (!$classe): ?>
                <p class="vide">Aucune classe.</p>
            <?php elseif (!$parcours): ?>
                <p class="vide">Aucun parcours pour cette classe.</p>
            <?php else: ?>
                <form method="post" class="carte">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="hors_ligne_parcours">
                    <input type="hidden" name="classe_id" value="<?= $classe_id ?>">
                    <label>Parcours
                        <select name="parcours" required>
                            <option value="">— choisir —</option>
                            <?php foreach ($parcours as $p):
                                $pp = parcours_par_id((int) $p['id']);
                                $apps = array_unique(array_column($pp['etapes'], 'app'));
                                $hors = array_filter($apps, fn($a) => chemin_fichier_autonome($a) === null); ?>
                                <option value="<?= (int) $p['id'] ?>"><?= h($p['nom']) ?> — <?= count($pp['etapes']) ?> étape(s)<?= $hors ? ' (en classe seulement : ' . h(implode(', ', $hors)) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <fieldset class="hl-eleves">
                        <legend>Élèves de <?= h($classe['nom']) ?> (un dossier chacun)</legend>
                        <?php foreach ($eleves_classe as $e): ?>
                            <label class="case"><input type="checkbox" name="eleves[]" value="<?= (int) $e['id'] ?>">
                                n° <?= h($e['etiquette']) ?> — <?= nom_eleve($classe['nom'], $e['etiquette']) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="aide">Un ZIP : pour chaque élève, un dossier avec le sommaire du parcours et un fichier par application
                        (NetSim, TechnoStudio, Établi 3D). L’élève rend un fichier par application ; importez-les tous ensemble.
                        Le dépôt de fichiers d’un dossier de projet ne se fait pas hors ligne : les fichiers envoyés au professeur arrivent dans ses fichiers.</p>
                    <button>Télécharger (ZIP)</button>
                </form>
            <?php endif; ?>
        </section>

        <section id="rendus">
            <h2>3. Importer les fichiers rendus</h2>
            <?php formulaire_import_rendus('hors_ligne'); ?>
            <?php tableau_rendus_hors_ligne($classe_id); ?>
        </section>

        <section id="prepares">
            <h2>4. Fichiers préparés</h2>
            <?php
            $st = $pdo->prepare('SELECT x.*, e.etiquette, (SELECT COUNT(*) FROM hors_ligne_rendus r WHERE r.export_id = x.id) AS n,
                                        (SELECT MAX(recu_le) FROM hors_ligne_rendus r WHERE r.export_id = x.id) AS dernier
                                 FROM hors_ligne_exports x JOIN eleves e ON e.id = x.eleve_id WHERE e.classe_id = ? ORDER BY x.cree_le DESC');
            $st->execute([$classe_id]);
            $exports = $st->fetchAll(); ?>
            <?php if (!$exports): ?>
                <p class="vide">Aucun fichier préparé pour cette classe.</p>
            <?php else: ?>
                <div class="defile"><table>
                    <thead><tr><th>Préparé le</th><th>Élève</th><th>Atelier</th><th>Rendus reçus</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($exports as $x): ?>
                        <tr>
                            <td><?= quand((int) $x['cree_le']) ?></td>
                            <td>n° <?= h($x['etiquette']) ?> — <?= nom_eleve($classe['nom'], $x['etiquette']) ?></td>
                            <td><?= h($x['titre']) ?> <span class="discret"><?= h($x['app']) ?></span></td>
                            <td><?= (int) $x['n'] ?><?= $x['dernier'] ? ' <span class="discret">(dernier le ' . quand((int) $x['dernier']) . ')</span>' : '' ?></td>
                            <td><?= bouton('hors_ligne_retirer', 'Retirer', ['export' => $x['id'], 'classe_id' => $classe_id], 'secondaire',
                                    'Retirer ce fichier ? Les rendus qui en viennent ne pourront plus être ouverts (le travail déjà importé reste dans le suivi).') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    </main>
    <?php
    pied();
}

/** Formulaire d'import des fichiers .tvrendu (page hors ligne et Suivi). */
function formulaire_import_rendus(string $retour): void
{
    ?>
    <form method="post" enctype="multipart/form-data" class="hl-import">
        <?= csrf() ?>
        <input type="hidden" name="action" value="hors_ligne_importer">
        <input type="hidden" name="retour" value="<?= h($retour) ?>">
        <label>Fichiers rendus par les élèves (.tvrendu)
            <input type="file" name="rendus[]" multiple accept=".tvrendu,.json,application/octet-stream" required></label>
        <button>Importer dans le suivi</button>
    </form>
    <?php
}

/** Rendus reçus pour une classe, avec le détail de chaque feuille. */
function tableau_rendus_hors_ligne(int $classe_id): void
{
    $classe = $classe_id ? classe_par_id($classe_id) : null;
    $st = db()->prepare('SELECT r.*, x.titre, x.app, x.atelier, x.eleve_id, e.etiquette FROM hors_ligne_rendus r
                         JOIN hors_ligne_exports x ON x.id = r.export_id JOIN eleves e ON e.id = x.eleve_id
                         WHERE e.classe_id = ? ORDER BY r.recu_le DESC LIMIT 100');
    $st->execute([$classe_id]);
    $rendus = $st->fetchAll();
    if (!$classe || !$rendus) {
        echo '<p class="vide">Aucun rendu reçu pour cette classe.</p>';
        return;
    }
    ?>
    <div class="defile"><table>
        <thead><tr><th>Reçu le</th><th>Élève</th><th>Atelier</th><th>Rendu par l’élève le</th><th>Feuilles</th><th>Détail</th></tr></thead>
        <tbody>
        <?php foreach ($rendus as $r):
            $c = json_decode((string) $r['contenu'], true) ?: [];
            $b = json_decode((string) $r['bilan'], true) ?: [];
            $prog = (array) ($c['progression'] ?? []);
            $liste = $r['atelier'] === '' ? [] : array_values(array_filter((array) ($c['activites'] ?? []),
                fn($a) => is_array($a) && (($a['id'] ?? '') === $r['atelier'] || str_starts_with((string) ($a['id'] ?? ''), $r['atelier'] . '/'))));
            if (!$liste) {   // parcours, ou application sans liste par atelier : les activités travaillées
                $titres = array_column(array_filter((array) ($c['activites'] ?? []), 'is_array'), 'titre', 'id');
                $liste = array_map(fn($k) => ['id' => $k, 'titre' => $prog[$k]['titre'] ?? $titres[$k] ?? $k], array_keys($prog));
            }
            $finies = count(array_filter($liste, fn($a) => ($prog[$a['id']]['statut'] ?? '') === 'termine')); ?>
            <tr>
                <td><?= quand((int) $r['recu_le']) ?></td>
                <td><a href="./?p=eleve&id=<?= (int) $r['eleve_id'] ?>">n° <?= h($r['etiquette']) ?> — <?= nom_eleve($classe['nom'], $r['etiquette']) ?></a></td>
                <td><?= h($r['titre']) ?></td>
                <td><?= quand((int) $r['rendu_le']) ?><?= !empty($c['ouvertures']) ? ' <span class="discret">(' . (int) $c['ouvertures'] . ' ouverture' . ((int) $c['ouvertures'] > 1 ? 's' : '') . ')</span>' : '' ?></td>
                <td><?= $finies ?>/<?= count($liste) ?> terminée<?= $finies > 1 ? 's' : '' ?><?= jauge($finies, max(1, count($liste))) ?></td>
                <td><details><summary>voir</summary>
                    <table class="hl-detail">
                        <?php foreach ($liste as $a): $p = $prog[$a['id']] ?? null; ?>
                            <tr><td><?= h((string) ($a['titre'] ?? $a['id'])) ?></td>
                                <td><?= !$p ? '<span class="discret">pas commencée</span>' : ($p['statut'] === 'termine' ? '<span class="ok">✓ terminée</span>' : 'en cours') ?></td>
                                <td><?= $p && isset($p['total']) && $p['total'] ? (int) ($p['faites'] ?? 0) . '/' . (int) $p['total'] : '' ?>
                                    <?= $p && isset($p['score'], $p['score_max']) ? ' · ' . h((string) $p['score']) . '/' . h((string) $p['score_max']) : '' ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                    <p class="discret"><?= (int) ($b['donnees'] ?? 0) ?> donnée(s) enregistrée(s)<?= !empty($b['fichiers']) ? ', ' . (int) $b['fichiers'] . ' fichier(s) joint(s)' : '' ?><?= !empty($b['gardees']) ? ', ' . (int) $b['gardees'] . ' gardée(s) dans l’historique (plus récent sur le serveur)' : '' ?>,
                        <?= (int) ($b['constats'] ?? 0) ?> constat(s) de compétences. Réponses : page de l’élève.</p>
                </details></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php
}
