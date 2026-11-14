<?php
/**
 * Centrale élèves — espace enseignant : onglet « Ateliers des applications » de la page Ateliers.
 * Import des ateliers publiés par chaque application (ateliers.json, à défaut centrale.json) ou d'un fichier exporté.
 */
declare(strict_types=1);

function action_catalogue(string $action): bool
{
    $retour = './?p=ateliers&gestion=1';
    switch ($action) {
        case 'importer_ateliers':
            $app = normaliser_app((string) ($_POST['app'] ?? '*'));
            $apps = $app === '*' ? db()->query('SELECT app FROM ateliers ORDER BY ordre, nom')->fetchAll(PDO::FETCH_COLUMN) : [$app];
            foreach ($apps as $a) {
                $r = importer_ateliers_application((string) $a);
                if ($r['ok']) {
                    flash("« $a » : {$r['n']} atelier" . ($r['n'] > 1 ? 's' : '') . ' lu' . ($r['n'] > 1 ? 's' : '') . ' dans '
                        . SOURCES_CATALOGUE[$r['source']] . ' — ' . texte_bilan($r['bilan']) . '.'
                        . ($r['source'] === 'descripteur' ? ' L’application ne publie pas encore ateliers.json.' : ''));
                    foreach (array_slice($r['remarques'], 0, 3) as $m) {
                        flash("« $a » : $m", 'info');
                    }
                } else {
                    flash("« $a » : " . implode(' ', $r['remarques']), 'err');
                }
            }
            rediriger($retour . ($app !== '*' ? '&app=' . rawurlencode($app) : ''));

        case 'importer_fichier_ateliers':
            $app = normaliser_app((string) ($_POST['app'] ?? ''));
            $envoi = $_FILES['fichier'] ?? null;
            if ($app === '*' || !$envoi || $envoi['error'] !== UPLOAD_ERR_OK) {
                flash('Choisissez l’application et le fichier à importer.', 'err');
                rediriger($retour);
            }
            if ($envoi['size'] > TAILLE_MAX_FICHIER_ATELIERS) {
                flash('Fichier trop gros (2 Mo au plus).', 'err');
                rediriger($retour);
            }
            $brut = (string) file_get_contents($envoi['tmp_name']);
            $j = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $brut) ?? $brut, true);
            if (!is_array($j)) {
                flash('Ce fichier n’est pas du JSON : exportez l’atelier depuis l’application (fichier .json).', 'err');
                rediriger($retour);
            }
            [$liste, $rem, $type] = ateliers_depuis_fichier($j, $app, activites_descripteur(descripteur_de($app)));
            if (!$liste) {
                flash(implode(' ', $rem), 'err');
                rediriger($retour);
            }
            // Un seul atelier : le fichier est gardé pour pouvoir le rendre à l'application (dossier ateliers/)
            $contenus = count($liste) === 1 && $type !== 'catalogue d’ateliers' ? [$liste[0]['atelier'] => $brut] : [];
            $bilan = ranger_ateliers($app, $liste, 'fichier', false, $contenus);
            $bilan['remarques'] = $rem;
            journaliser_import($app, 'fichier', (string) $envoi['name'], $bilan);
            flash("« $app » : $type importé (" . texte_bilan($bilan) . '). Pour qu’il reste disponible sans serveur, ajoutez-le aux ateliers publiés par l’application.');
            rediriger($retour . '&app=' . rawurlencode($app));

        case 'ajouter_periode_atelier':
            $app = normaliser_app((string) ($_POST['app'] ?? ''));
            $atelier = (string) ($_POST['atelier'] ?? '');
            $debut = trim((string) ($_POST['debut'] ?? ''));
            $fin = trim((string) ($_POST['fin'] ?? ''));
            $cid = (int) ($_POST['classe_id'] ?? 0);
            $ok_date = fn(string $d) => $d === '' || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
            $ici = $retour . '&app=' . rawurlencode($app) . '#dispo-' . rawurlencode($atelier);
            $existe = db()->prepare('SELECT 1 FROM catalogue_ateliers WHERE app = ? AND atelier = ?');
            $existe->execute([$app, $atelier]);
            if (!$existe->fetchColumn()) {
                flash('Atelier introuvable dans le catalogue.', 'err');
            } elseif (!$ok_date($debut) || !$ok_date($fin) || ($debut === '' && $fin === '')) {
                flash('Indiquez une date de début, une date de fin, ou les deux.', 'err');
            } elseif ($debut !== '' && $fin !== '' && $fin < $debut) {
                flash('La date de fin est avant la date de début.', 'err');
            } else {
                db()->prepare('INSERT INTO atelier_periodes (app, atelier, classe_id, debut, fin, cree_le) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$app, $atelier, $cid ?: null, $debut, $fin, time()]);
                flash('Période ajoutée : les élèves concernés ne voient l’atelier que pendant ses périodes.');
            }
            rediriger($ici);

        case 'supprimer_periode_atelier':
            $st = db()->prepare('SELECT app, atelier FROM atelier_periodes WHERE id = ?');
            $st->execute([(int) ($_POST['periode_id'] ?? 0)]);
            $r = $st->fetch();
            db()->prepare('DELETE FROM atelier_periodes WHERE id = ?')->execute([(int) ($_POST['periode_id'] ?? 0)]);
            flash('Période retirée.');
            rediriger($retour . ($r ? '&app=' . rawurlencode($r['app']) . '#dispo-' . rawurlencode($r['atelier']) : ''));

        case 'supprimer_atelier_catalogue':
            $app = normaliser_app((string) ($_POST['app'] ?? ''));
            db()->prepare('DELETE FROM catalogue_ateliers WHERE app = ? AND atelier = ?')->execute([$app, (string) ($_POST['atelier'] ?? '')]);
            db()->prepare('DELETE FROM atelier_periodes WHERE app = ? AND atelier = ?')->execute([$app, (string) ($_POST['atelier'] ?? '')]);
            flash('Atelier enlevé du catalogue (le travail et la progression des élèves sont conservés).');
            rediriger($retour . '&app=' . rawurlencode($app));
    }
    return false;
}

/**
 * « Ajouter un atelier » : un atelier se crée d'abord sur papier, dans FicheStudio (ou Affinity), puis se convertit pour
 * TechnoStudio. Le bouton ouvre FicheStudio sur un nouveau document (lien #nouveau : les modèles de la charte s'ouvrent).
 */
function bouton_nouvel_atelier(): string
{
    return '<a class="btn" href="appli.php/fichestudio/#nouveau" target="_blank" rel="noopener"'
        . ' title="Ouvre FicheStudio sur un nouveau document : l’atelier se crée d’abord sur papier, puis se convertit pour TechnoStudio">'
        . '＋ Ajouter un atelier</a>';
}

/** Le chemin de création d'un atelier, rappelé en haut des deux onglets. */
function aide_creation_atelier(): void
{
    ?>
    <ol class="etapes-atelier" aria-label="Créer un atelier">
        <li><strong>Sur papier</strong> : fiches et présentation dans <a href="appli.php/fichestudio/#nouveau" target="_blank" rel="noopener">FicheStudio</a> (ou Affinity, puis ouvert dans FicheStudio).</li>
        <li><strong>Converti pour TechnoStudio</strong> : l’atelier numérique, avec ses activités et ses compétences.</li>
        <li><strong>Importé ici</strong> : « Importer les ateliers… », puis proposé aux classes, mis dans les parcours et suivi.</li>
    </ol>
    <?php
}

/**
 * Page Ateliers : les ateliers des applications, une seule page pour les mêmes données — en cartes (filtres, propositions aux
 * classes, parcours, essai) et, dans une section dépliable, les imports et la disponibilité (périodes, classes, retraits).
 * Les applications elles-mêmes (portail) sont dans la page Applications.
 */
function page_ateliers(): void
{
    if (isset($_GET['telecharger'], $_GET['app'])) {
        telecharger_contenu_atelier(normaliser_app((string) $_GET['app']), (string) $_GET['telecharger']);
    }
    $gestion = !empty($_GET['gestion']) || ($_GET['vue'] ?? '') === 'catalogue';   // ancien lien « Ateliers des applications »
    if (($_GET['vue'] ?? '') === 'catalogue') unset($_GET['vue']);
    $apps = db()->query('SELECT COUNT(*) FROM ateliers')->fetchColumn();
    entete('Ateliers');
    barre('ateliers');
    ?>
    <main class="biblio large">
        <div class="titre-section">
            <h1>Ateliers</h1>
            <div class="titre-actions">
                <?= bouton_nouvel_atelier() ?>
                <?= $apps ? bouton('importer_ateliers', 'Importer les ateliers de toutes les applications', ['app' => '*'], 'secondaire') : '' ?>
            </div>
        </div>
        <?php aide_creation_atelier(); ?>
        <?php messages(); ?>
        <details class="carte gestion-catalogue" id="gestion"<?= $gestion ? ' open' : '' ?>>
            <summary><strong>Imports et disponibilité</strong> <span class="discret">— <?= nombre_ateliers_catalogue() ?> ateliers importés ·
                mise à jour depuis les applications, périodes, classes, ateliers retirés</span></summary>
            <div id="catalogue-ateliers"><?php corps_catalogue_ateliers(); ?></div>
        </details>
        <?php corps_bibliotheque(); ?>
    </main>
    <?php
    pied();
}

/** Nombre d'ateliers actifs au catalogue (pour l'onglet). */
function nombre_ateliers_catalogue(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM catalogue_ateliers WHERE etat = 'actif'")->fetchColumn();
}

/** Téléchargement d'un fichier importé à la main (pour le remettre dans l'application). */
function telecharger_contenu_atelier(string $app, string $atelier): never
{
    $st = db()->prepare('SELECT contenu FROM catalogue_ateliers WHERE app = ? AND atelier = ?');
    $st->execute([$app, $atelier]);
    $c = (string) $st->fetchColumn();
    if ($c === '') {
        http_response_code(404);
        exit('Fichier introuvable.');
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $atelier) . '.json"');
    echo $c;
    exit;
}

/** Imports et disponibilité des ateliers (ex-onglet « Ateliers des applications ») : dans la section dépliable de la page Ateliers. */
function corps_catalogue_ateliers(): void
{
    $apps = db()->query('SELECT * FROM ateliers ORDER BY ordre, nom')->fetchAll();
    $filtre = normaliser_app((string) ($_GET['app'] ?? ''));
    $tous = catalogue_ateliers();
    $par_app = [];
    foreach ($tous as $r) {
        $par_app[$r['app']][] = $r;
    }
    $derniers = [];
    foreach (db()->query('SELECT app, source, adresse, ts FROM imports_ateliers ORDER BY id') as $i) {
        $derniers[$i['app']] = $i;
    }
    $periodes = periodes_ateliers();
    $classes = db()->query('SELECT id, nom FROM classes ORDER BY nom')->fetchAll(PDO::FETCH_KEY_PAIR);
    $niveaux = array_values(array_unique(array_filter(array_column($tous, 'niveau'))));
    usort($niveaux, fn($a, $b) => strcmp($b, $a));

    ?>
        <p class="aide">Chaque application crée et publie ses ateliers avec elle : ils marchent sans serveur. La Centrale les importe pour
            les ranger ici, avec leurs compétences. Procédure : <a href="../passerelle/?doc=donnees" target="_blank">données et bonnes pratiques</a> ·
            <a href="../passerelle/?doc=competences" target="_blank">compétences</a>.</p>

        <?php if (!$apps): ?>
            <p class="vide">Aucune application sur le portail : ajoutez-en une dans <a href="./?p=applications">Applications</a>.</p>
        <?php else: ?>
        <div class="defile"><table class="table-imports">
            <thead><tr><th>Application</th><th>Ateliers</th><th>Dernier import</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($apps as $a): $l = $par_app[$a['app']] ?? []; $d = $derniers[$a['app']] ?? null;
                $actifs = count(array_filter($l, fn($r) => $r['etat'] === 'actif')); ?>
                <tr>
                    <td><?= h($a['icone']) ?> <strong><?= h($a['nom']) ?></strong> <code><?= h($a['app']) ?></code></td>
                    <td><?= $actifs ?: '<span class="discret">—</span>' ?><?= count($l) > $actifs ? ' <span class="discret">(+' . (count($l) - $actifs) . (count($l) - $actifs > 1 ? ' retirés' : ' retiré') . ')</span>' : '' ?></td>
                    <td class="discret"><?php if ($d): ?><?= quand((int) $d['ts']) ?> · <?= h(SOURCES_CATALOGUE[$d['source']] ?? $d['source']) ?>
                        <?php if ($d['source'] === 'descripteur'): ?><br><span class="alerte">pas encore de ateliers.json</span><?php endif; ?>
                        <?php else: ?>jamais<?php endif; ?></td>
                    <td class="actions"><?= bouton('importer_ateliers', $l ? 'Mettre à jour' : 'Importer', ['app' => $a['app']], $l ? 'secondaire' : '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <details class="carte" style="margin-top:14px">
            <summary>Importer un fichier exporté d’une application</summary>
            <p class="aide">Un atelier seul (<code>.json</code>), un catalogue <code>ateliers.json</code> ou un atelier TechnoStudio
                (<code>.technostudio.json</code>, codes T1/T2/T3 convertis en OST/SFC/CCRI). Le fichier est gardé ici en attendant
                d’être publié avec l’application : c’est seulement là qu’il restera disponible sans serveur.</p>
            <form method="post" enctype="multipart/form-data" class="formulaire-ligne">
                <?= csrf() ?>
                <input type="hidden" name="action" value="importer_fichier_ateliers">
                <label>Application<select name="app" required>
                    <?php foreach ($apps as $a): ?><option value="<?= h($a['app']) ?>" <?= $a['app'] === $filtre ? 'selected' : '' ?>><?= h($a['nom']) ?></option><?php endforeach; ?>
                </select></label>
                <label>Fichier<input type="file" name="fichier" accept=".json,application/json" required></label>
                <button>Importer</button>
            </form>
        </details>

        <?php if ($tous): ?>
            <div class="filtres filtres-ref filtres-catalogue">
                <label>Application<select id="cat-app">
                    <option value="">Toutes</option>
                    <?php foreach ($apps as $a): if (empty($par_app[$a['app']])) continue; ?>
                        <option value="<?= h($a['app']) ?>" <?= $a['app'] === $filtre ? 'selected' : '' ?>><?= h($a['nom']) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <div class="seg-niveaux" role="group" aria-label="Niveau">
                    <button type="button" data-niveau="" class="secondaire actif">Tous</button>
                    <?php foreach ($niveaux as $n): ?><button type="button" class="secondaire" data-niveau="<?= h($n) ?>"><?= h($n === 'lycee' ? 'Lycée' : $n) ?></button><?php endforeach; ?>
                </div>
                <label class="large">Rechercher<input type="search" id="cat-recherche" placeholder="titre, code de compétence (SFC15)…"></label>
                <label class="case"><input type="checkbox" id="cat-retires"> Montrer les ateliers retirés</label>
            </div>
            <div class="defile"><table class="table-catalogue">
                <thead><tr><th>Atelier</th><th>Application</th><th>Niveau</th><th>Compétences</th><th>Activités</th><th>Disponibilité</th><th>Source</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tous as $r): $ouvrir = adresse_ouvrir_atelier($r); $nb = count($r['activites']); ?>
                    <tr data-app="<?= h($r['app']) ?>" data-niveau="<?= h($r['niveau']) ?>" <?= $r['etat'] !== 'actif' ? 'data-retire="1" class="inactif" hidden' : '' ?>
                        data-texte="<?= h(mb_strtolower($r['titre'] . ' ' . $r['atelier'] . ' ' . $r['resume'] . ' ' . $r['competences'])) ?>">
                        <td><?php if ($r['theme']): ?><span class="pastille-theme" style="--t:<?= h(couleur_theme($r['theme'])) ?>"><?= h($r['theme']) ?></span> <?php endif; ?>
                            <strong><?= h($r['titre']) ?></strong> <code class="discret"><?= h($r['atelier']) ?></code>
                            <?php if ($r['resume'] !== ''): ?><br><span class="discret"><?= h(mb_strimwidth($r['resume'], 0, 160, '…')) ?></span><?php endif; ?>
                            <?php if ($r['etat'] !== 'actif'): ?><br><span class="alerte">Retiré de l’application <?= quand((int) $r['modifie_le']) ?></span><?php endif; ?></td>
                        <td><?= h($r['icone_app'] ?? '') ?> <?= h($r['nom_app'] ?? $r['app']) ?></td>
                        <td><?= $r['niveau'] !== '' ? h($r['niveau'] === 'lycee' ? 'Lycée' : $r['niveau']) : '<span class="discret">tous</span>' ?><?= $r['duree'] !== '' ? '<br><span class="discret">' . h($r['duree']) . '</span>' : '' ?></td>
                        <td><?= $r['codes'] ? implode(' ', array_map('puce_competence', $r['codes'])) : '<span class="discret" title="L’application ne déclare pas encore de compétences pour cet atelier">—</span>' ?></td>
                        <td><?php if ($nb > 1): ?><details><summary><?= $nb ?> activités</summary><ul class="liste-activites">
                                <?php foreach ($r['activites'] as $x): ?><li><?= h($x['titre']) ?> <code class="discret"><?= h($x['id']) ?></code></li><?php endforeach; ?>
                            </ul></details>
                            <?php else: ?><?= $nb ?: '<span class="discret" title="Les activités viennent de l’application">—</span>' ?><?php endif; ?></td>
                        <td class="cellule-dispo" id="dispo-<?= h($r['atelier']) ?>"><?php $pl = $periodes[$r['app']][$r['atelier']] ?? [];
                            $etat = $pl ? disponibilite_atelier(array_filter($pl, fn($x) => $x['classe_id'] === null), null) : null; ?>
                            <?php if (!$pl): ?><span class="discret" title="Aucune période : l’atelier est visible en permanence">toujours</span>
                            <?php else: ?>
                                <?php if ($etat['limite']): ?><span class="pastille-dispo <?= h($etat['etat']) ?>" title="Aujourd’hui, pour toutes les classes (une période propre à une classe s’y ajoute pour cette classe)"><?= ['ouvert' => 'Ouvert', 'bientot' => 'Bientôt', 'ferme' => 'Fermé'][$etat['etat']] ?></span>
                                <?php else: ?><span class="discret">selon la classe</span><?php endif; ?>
                                <ul class="liste-periodes"><?php foreach ($pl as $x): ?>
                                    <li><?= h(texte_periode($x)) ?> <?= bouton('supprimer_periode_atelier', '✕', ['periode_id' => (int) $x['id']], 'lien', '', 'Retirer cette période') ?></li>
                                <?php endforeach; ?></ul>
                            <?php endif; ?>
                            <details class="ajout-periode"><summary>+ Période</summary>
                                <form method="post" class="form-periode">
                                    <?= csrf() ?>
                                    <input type="hidden" name="action" value="ajouter_periode_atelier">
                                    <input type="hidden" name="app" value="<?= h($r['app']) ?>">
                                    <input type="hidden" name="atelier" value="<?= h($r['atelier']) ?>">
                                    <label>Classe<select name="classe_id"><option value="0">Toutes les classes</option><?php foreach ($classes as $cid => $cnom): ?><option value="<?= (int) $cid ?>"><?= h($cnom) ?></option><?php endforeach; ?></select></label>
                                    <label>Du<input type="date" name="debut"></label>
                                    <label>Au<input type="date" name="fin"></label>
                                    <button class="petit">Ajouter</button>
                                </form>
                            </details></td>
                        <td class="discret"><?= h(SOURCES_CATALOGUE[$r['source']] ?? $r['source']) ?><?= $r['version'] !== '' ? '<br>v' . h($r['version']) : '' ?>
                            <?php if ($r['source'] === 'fichier'): ?><br><span class="alerte" title="Il ne restera disponible sans serveur qu’une fois publié avec l’application">pas encore dans l’application</span><?php endif; ?></td>
                        <td class="actions">
                            <?php if ($ouvrir !== ''): ?><a class="btn petit secondaire" href="<?= h($ouvrir) ?>" target="_blank" rel="noopener" title="Ouvrir l’atelier dans l’application, sans connexion">Ouvrir</a><?php endif; ?>
                            <?php if ($r['contenu'] !== ''): ?><a class="btn petit secondaire" href="./?p=ateliers&gestion=1&app=<?= rawurlencode($r['app']) ?>&telecharger=<?= rawurlencode($r['atelier']) ?>" title="Fichier à ajouter aux ateliers publiés par l’application">Fichier</a><?php endif; ?>
                            <?php if ($r['etat'] !== 'actif' || $r['source'] === 'fichier'): ?>
                                <?= bouton('supprimer_atelier_catalogue', 'Enlever', ['app' => $r['app'], 'atelier' => $r['atelier']], 'danger', 'Enlever cet atelier du catalogue ?') ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="vide" id="cat-rien" hidden>Aucun atelier ne correspond.</p>
        <?php else: ?>
            <p class="vide">Pas encore d’atelier importé : cliquez sur « Importer » en face d’une application.</p>
        <?php endif; ?>
        <?php endif; ?>
    <?php
}
