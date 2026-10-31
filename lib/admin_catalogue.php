<?php
/**
 * Centrale élèves — espace enseignant : onglet « Ateliers des applications » de la page Ateliers.
 * Import des ateliers publiés par chaque application (ateliers.json, à défaut centrale.json) ou d'un fichier exporté.
 */
declare(strict_types=1);

function action_catalogue(string $action): bool
{
    $retour = './?p=ateliers&vue=catalogue';
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

        case 'supprimer_atelier_catalogue':
            $app = normaliser_app((string) ($_POST['app'] ?? ''));
            db()->prepare('DELETE FROM catalogue_ateliers WHERE app = ? AND atelier = ?')->execute([$app, (string) ($_POST['atelier'] ?? '')]);
            flash('Atelier enlevé du catalogue (le travail et la progression des élèves sont conservés).');
            rediriger($retour . '&app=' . rawurlencode($app));
    }
    return false;
}

function onglets_ateliers(string $vue, int $n): void
{
    ?>
    <nav class="onglets-vue" aria-label="Ateliers">
        <a href="./?p=ateliers" class="<?= $vue === '' ? 'actif' : '' ?>">Applications du portail</a>
        <a href="./?p=ateliers&vue=catalogue" class="<?= $vue === 'catalogue' ? 'actif' : '' ?>">Ateliers des applications<?= $n ? " ($n)" : '' ?></a>
    </nav>
    <?php
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

function page_catalogue_ateliers(): void
{
    if (isset($_GET['telecharger'], $_GET['app'])) {
        telecharger_contenu_atelier(normaliser_app((string) $_GET['app']), (string) $_GET['telecharger']);
    }
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
    $niveaux = array_values(array_unique(array_filter(array_column($tous, 'niveau'))));
    usort($niveaux, fn($a, $b) => strcmp($b, $a));

    entete('Ateliers des applications');
    barre('ateliers');
    ?>
    <main class="large" id="catalogue-ateliers">
        <div class="titre-section">
            <h1>Ateliers</h1>
            <?= $apps ? bouton('importer_ateliers', 'Importer les ateliers de toutes les applications', ['app' => '*']) : '' ?>
        </div>
        <p class="sous">Chaque application crée et publie ses ateliers avec elle : ils marchent sans serveur. La Centrale les importe pour
            les ranger ici, avec leurs compétences, et les utiliser dans les parcours, la bibliothèque et le suivi.
            Procédure : <a href="../passerelle/?doc=donnees" target="_blank">données et bonnes pratiques</a> ·
            <a href="../passerelle/?doc=competences" target="_blank">compétences</a>.</p>
        <?php messages(); ?>
        <?php onglets_ateliers('catalogue', count(array_filter($tous, fn($r) => $r['etat'] === 'actif'))); ?>

        <?php if (!$apps): ?>
            <p class="vide">Aucune application sur le portail : ajoutez-en une dans l’onglet « Applications du portail ».</p>
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
                <thead><tr><th>Atelier</th><th>Application</th><th>Niveau</th><th>Compétences</th><th>Activités</th><th>Source</th><th></th></tr></thead>
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
                        <td class="discret"><?= h(SOURCES_CATALOGUE[$r['source']] ?? $r['source']) ?><?= $r['version'] !== '' ? '<br>v' . h($r['version']) : '' ?>
                            <?php if ($r['source'] === 'fichier'): ?><br><span class="alerte" title="Il ne restera disponible sans serveur qu’une fois publié avec l’application">pas encore dans l’application</span><?php endif; ?></td>
                        <td class="actions">
                            <?php if ($ouvrir !== ''): ?><a class="btn petit secondaire" href="<?= h($ouvrir) ?>" target="_blank" rel="noopener" title="Ouvrir l’atelier dans l’application, sans connexion">Ouvrir</a><?php endif; ?>
                            <?php if ($r['contenu'] !== ''): ?><a class="btn petit secondaire" href="./?p=ateliers&vue=catalogue&app=<?= rawurlencode($r['app']) ?>&telecharger=<?= rawurlencode($r['atelier']) ?>" title="Fichier à ajouter aux ateliers publiés par l’application">Fichier</a><?php endif; ?>
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
    </main>
    <?php
    pied();
}
