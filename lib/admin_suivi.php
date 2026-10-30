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
                $pdo->prepare('UPDATE parcours SET nom = ?, description = ?, classes = ? WHERE id = ?')
                    ->execute([$nom, $description, $classes, $id]);
                $pdo->prepare('DELETE FROM parcours_etapes WHERE parcours_id = ?')->execute([$id]);
            } else {
                $pdo->prepare('INSERT INTO parcours (nom, description, classes, cree_le) VALUES (?, ?, ?, ?)')
                    ->execute([$nom, $description, $classes, time()]);
                $id = (int) $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO parcours_etapes (parcours_id, ordre, titre, app, activite, url) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($etapes as $i => $et) {
                $ins->execute([$id, $i, $et['titre'], $et['app'], $et['activite'], $et['url']]);
            }
            $pdo->commit();
            flash("Parcours « $nom » enregistré (" . count($etapes) . ' étapes).');
            rediriger('./?p=parcours');

        case 'supprimer_parcours':
            $pdo->prepare('DELETE FROM parcours WHERE id = ?')->execute([(int) ($_POST['parcours_id'] ?? 0)]);
            flash('Parcours supprimé (la progression des élèves est conservée).');
            rediriger('./?p=parcours');

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
        $etapes[] = ['titre' => $titre, 'app' => $app, 'activite' => $activite, 'url' => $url];
    }
    if (count($etapes) > 100) {
        $erreurs[] = '100 étapes maximum.';
    }
    return [$etapes, $erreurs];
}

function etapes_en_texte(array $etapes): string
{
    return implode("\n", array_map(fn($e) => rtrim(implode(' | ', [$e['titre'], $e['app'], $e['activite'], $e['url']]), ' |'), $etapes));
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
        if (str_starts_with($vue, 'parcours:')) {
            $st = $pdo->prepare('SELECT * FROM parcours_etapes WHERE parcours_id = ? ORDER BY ordre, id');
            $st->execute([(int) substr($vue, 9)]);
            foreach ($st->fetchAll() as $et) {
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

        $st = $pdo->prepare("SELECT id, etiquette, actif FROM eleves WHERE classe_id = ?
            ORDER BY CASE WHEN etiquette GLOB '[0-9]*' THEN CAST(etiquette AS INTEGER) ELSE 9999 END, etiquette");
        $st->execute([$classe_id]);
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
                <?php foreach ($eleves as $e): $somme = 0.0; $derniere = 0; ?>
                    <tr class="<?= $e['actif'] ? '' : 'inactif' ?>">
                        <td class="numero fixe"><?= h($e['etiquette']) ?></td>
                        <td class="fixe2"><a href="./?p=eleve&id=<?= (int) $e['id'] ?>"><?= nom_eleve($classe['nom'], $e['etiquette']) ?></a></td>
                        <?php foreach ($colonnes as $i => $c):
                            $etat = etat_etape($c['activite'], $prog[(int) $e['id']][$c['app']] ?? [], $catalogue[$c['app']] ?? []);
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

    $courant = ['id' => 0, 'nom' => '', 'description' => '', 'classes' => '', 'etapes' => ''];
    if ($modifier) {
        $st = $pdo->prepare('SELECT * FROM parcours WHERE id = ?');
        $st->execute([$modifier]);
        if ($p = $st->fetch()) {
            $se = $pdo->prepare('SELECT * FROM parcours_etapes WHERE parcours_id = ? ORDER BY ordre, id');
            $se->execute([$modifier]);
            $courant = ['id' => (int) $p['id'], 'nom' => $p['nom'], 'description' => $p['description'],
                        'classes' => $p['classes'], 'etapes' => etapes_en_texte($se->fetchAll())];
        }
    }
    if ($brouillon) {
        $courant = ['id' => (int) ($brouillon['parcours_id'] ?? 0), 'nom' => (string) ($brouillon['nom'] ?? ''),
                    'description' => (string) ($brouillon['description'] ?? ''), 'classes' => (string) ($brouillon['classes'] ?? ''),
                    'etapes' => (string) ($brouillon['etapes'] ?? '')];
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
                <label>Consigne générale (facultative)<input name="description" maxlength="500" value="<?= h($courant['description']) ?>"></label>
                <label>Étapes, une par ligne
                    <span class="aide"><code>Titre | atelier | activité | adresse</code> — l'activité est facultative (vide = tout l'atelier),
                        l'adresse est celle du logiciel à ouvrir.</span>
                    <textarea name="etapes" rows="8" class="mono" placeholder="Modéliser le châssis | etabli3d | chassis | https://grandiosian.github.io/etabli3d/
Programmer le suivi de ligne | blocs | suivi-ligne | https://grandiosian.github.io/blocs/
Questionnaire bilan | quiz | bilan-robot | https://technoversailles.alwaysdata.net/apps/quiz/"><?= h($courant['etapes']) ?></textarea>
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

        <?php if ($liste): ?>
            <div class="defile" style="margin-top:20px"><table>
                <thead><tr><th>Parcours</th><th>Classes</th><th>Étapes</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($liste as $p): ?>
                    <tr>
                        <td><strong><?= h($p['nom']) ?></strong><?= $p['description'] ? '<br><span class="discret">' . h($p['description']) . '</span>' : '' ?></td>
                        <td><?= $p['classes'] !== '' ? h($p['classes']) : '<span class="discret">toutes</span>' ?></td>
                        <td><?= (int) $p['nb'] ?></td>
                        <td class="actions">
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
