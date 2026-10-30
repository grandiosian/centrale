<?php
/**
 * Centrale élèves — espace enseignant : fichiers enregistrés sur le serveur.
 * Inclus par public/admin/index.php.
 */
declare(strict_types=1);

function action_fichiers(string $action): bool
{
    if ($action !== 'supprimer_fichiers') {
        return false;
    }
    $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
    $n = 0;
    $st = db()->prepare('SELECT id, chemin FROM fichiers WHERE id = ?');
    foreach ($ids as $id) {
        $st->execute([$id]);
        if ($f = $st->fetch()) {
            db()->prepare('DELETE FROM fichiers WHERE id = ?')->execute([$id]);
            effacer_fichier_stocke($f['chemin']);
            $n++;
        }
    }
    flash("$n fichier(s) supprimé(s).");
    $retour = (string) ($_POST['retour'] ?? '');
    rediriger(preg_match('#^\./\?p=fichiers[A-Za-z0-9_=&%.-]*$#', $retour) ? $retour : './?p=fichiers');
}

function page_fichiers(): void
{
    $pdo = db();
    $f_classe = (int) ($_GET['classe'] ?? 0);
    $f_app = (string) ($_GET['app'] ?? '');
    $f_q = trim((string) ($_GET['q'] ?? ''));
    $tri = in_array($_GET['tri'] ?? '', ['taille', 'nom', 'date'], true) ? $_GET['tri'] : 'date';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $par_page = 100;

    $where = ['1 = 1'];
    $params = [];
    if ($f_classe) {
        $where[] = 'e.classe_id = ?';
        $params[] = $f_classe;
    }
    if ($f_app !== '') {
        $where[] = 'f.app = ?';
        $params[] = $f_app;
    }
    if ($f_q !== '') {
        $where[] = 'f.nom LIKE ?';
        $params[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $f_q) . '%';
    }
    $ordre = ['date' => 'f.maj DESC', 'taille' => 'f.taille DESC', 'nom' => 'f.nom COLLATE NOCASE'][$tri];
    $sql_where = implode(' AND ', $where);
    // ESCAPE ne concerne que le LIKE : on l'ajoute juste après lui
    $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(f.taille), 0) FROM fichiers f JOIN eleves e ON e.id = f.eleve_id WHERE "
        . str_replace('f.nom LIKE ?', "f.nom LIKE ? ESCAPE '\\'", $sql_where));
    $st->execute($params);
    [$total, $taille_totale] = $st->fetch(PDO::FETCH_NUM);
    $st = $pdo->prepare("SELECT f.*, e.etiquette, e.id AS eleve_id, c.nom AS classe, c.id AS classe_id
                         FROM fichiers f JOIN eleves e ON e.id = f.eleve_id JOIN classes c ON c.id = e.classe_id
                         WHERE " . str_replace('f.nom LIKE ?', "f.nom LIKE ? ESCAPE '\\'", $sql_where) . "
                         ORDER BY $ordre LIMIT $par_page OFFSET " . (($page - 1) * $par_page));
    $st->execute($params);
    $fichiers = $st->fetchAll();

    $classes = $pdo->query('SELECT id, nom FROM classes ORDER BY nom')->fetchAll();
    $apps = $pdo->query('SELECT DISTINCT app FROM fichiers ORDER BY app')->fetchAll(PDO::FETCH_COLUMN);
    // Espace par classe (données + versions + fichiers)
    $espace = $pdo->query('SELECT c.id, c.nom,
            (SELECT COALESCE(SUM(f.taille), 0) FROM fichiers f JOIN eleves e ON e.id = f.eleve_id WHERE e.classe_id = c.id) AS fichiers,
            (SELECT COUNT(*) FROM fichiers f JOIN eleves e ON e.id = f.eleve_id WHERE e.classe_id = c.id) AS nb,
            (SELECT COALESCE(SUM(d.taille), 0) FROM donnees d JOIN eleves e ON e.id = d.eleve_id WHERE e.classe_id = c.id)
          + (SELECT COALESCE(SUM(d.taille), 0) FROM donnees_versions d JOIN eleves e ON e.id = d.eleve_id WHERE e.classe_id = c.id) AS donnees
        FROM classes c ORDER BY c.nom')->fetchAll();
    $ressources = $pdo->query('SELECT COUNT(*), COALESCE(SUM(taille), 0) FROM ressources')->fetch(PDO::FETCH_NUM);
    $disque = taille_dossier_data();

    $filtres = array_filter(['classe' => $f_classe ?: null, 'app' => $f_app ?: null, 'q' => $f_q ?: null, 'tri' => $tri]);
    $url = fn(array $plus) => './?p=fichiers&' . http_build_query(array_filter(array_merge($filtres, $plus)));
    $retour = $url(['page' => $page > 1 ? $page : null]);

    entete('Fichiers');
    barre('fichiers');
    ?>
    <main class="large">
        <h1>Fichiers sur le serveur</h1>
        <?php messages(); ?>
        <div class="stats">
            <div class="stat"><strong><?= format_taille($disque) ?></strong><span>espace total utilisé (base + fichiers)</span></div>
            <div class="stat"><strong><?= (int) $total ?></strong><span>fichier<?= $total > 1 ? 's' : '' ?> d'élèves<?= $filtres !== ['tri' => $tri] ? ' (filtrés)' : '' ?> · <?= format_taille((int) $taille_totale) ?></span></div>
            <div class="stat"><strong><?= (int) $ressources[0] ?></strong><span>ressource<?= $ressources[0] > 1 ? 's' : '' ?> d'ateliers · <?= format_taille((int) $ressources[1]) ?></span></div>
        </div>
        <?php liste_locale(); ?>

        <?php if ($espace): ?>
            <section>
                <h2>Espace par classe</h2>
                <div class="defile"><table>
                    <thead><tr><th>Classe</th><th>Fichiers</th><th>Données des applications</th><th>Total</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($espace as $c): ?>
                        <tr>
                            <td><strong><?= h($c['nom']) ?></strong></td>
                            <td><?= (int) $c['nb'] ?> · <?= format_taille((int) $c['fichiers']) ?></td>
                            <td><?= format_taille((int) $c['donnees']) ?></td>
                            <td><?= format_taille((int) $c['fichiers'] + (int) $c['donnees']) ?></td>
                            <td class="actions">
                                <a class="btn petit secondaire" href="./?p=fichiers&classe=<?= (int) $c['id'] ?>">Voir les fichiers</a>
                                <a class="btn petit secondaire" href="./?p=export&id=<?= (int) $c['id'] ?>">ZIP</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
        <?php endif; ?>

        <section>
            <h2>Fichiers des élèves</h2>
            <form method="get" class="filtres">
                <input type="hidden" name="p" value="fichiers">
                <label>Classe<select name="classe"><option value="">Toutes</option>
                    <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $c['id'] == $f_classe ? 'selected' : '' ?>><?= h($c['nom']) ?></option><?php endforeach; ?></select></label>
                <label>Atelier<select name="app"><option value="">Tous</option>
                    <?php foreach ($apps as $a): ?><option <?= $a === $f_app ? 'selected' : '' ?>><?= h($a) ?></option><?php endforeach; ?></select></label>
                <label>Nom contient<input name="q" value="<?= h($f_q) ?>" placeholder="ex. .sb3"></label>
                <label>Trier par<select name="tri">
                    <?php foreach (['date' => 'Plus récents', 'taille' => 'Plus gros', 'nom' => 'Nom'] as $k => $l): ?><option value="<?= $k ?>" <?= $k === $tri ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
                <button>Filtrer</button>
                <?php if (count($filtres) > 1): ?><a href="./?p=fichiers">Tout afficher</a><?php endif; ?>
            </form>
            <?php if (!$fichiers): ?>
                <p class="vide" style="margin-top:16px">Aucun fichier<?= count($filtres) > 1 ? ' pour ces filtres' : ' pour l’instant' ?>.</p>
            <?php else: ?>
                <form method="post" data-confirmer="Supprimer définitivement les fichiers cochés ?">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="supprimer_fichiers">
                    <input type="hidden" name="retour" value="<?= h($retour) ?>">
                    <div class="defile" style="margin-top:12px"><table>
                        <thead><tr><th><input type="checkbox" aria-label="Tout cocher" onclick="this.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(c => c.checked = this.checked)"></th>
                            <th>Fichier</th><th>Élève</th><th>Atelier</th><th>Taille</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($fichiers as $f): ?>
                            <tr>
                                <td><input type="checkbox" name="ids[]" value="<?= (int) $f['id'] ?>" aria-label="Cocher <?= h($f['nom']) ?>"></td>
                                <td><a href="./?p=telecharger&quoi=fichier&id=<?= (int) $f['id'] ?>"><?= h($f['nom']) ?></a> <span class="discret"><?= h($f['type']) ?></span></td>
                                <td><a href="./?p=eleve&id=<?= (int) $f['eleve_id'] ?>"><?= h($f['classe']) ?> · <?= h($f['etiquette']) ?></a>
                                    <?= est_pseudonyme($f['etiquette']) ? nom_eleve($f['classe'], $f['etiquette'], '') : '' ?></td>
                                <td><code><?= h($f['app']) ?></code></td>
                                <td><?= format_taille((int) $f['taille']) ?></td>
                                <td><?= quand((int) $f['maj']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <div class="ligne-actions" style="margin-top:10px">
                        <button class="danger petit">Supprimer les fichiers cochés</button>
                        <?php $pages = (int) ceil($total / $par_page); if ($pages > 1): ?>
                            <span class="discret">Page <?= $page ?> / <?= $pages ?></span>
                            <?php if ($page > 1): ?><a class="btn petit secondaire" href="<?= h($url(['page' => $page - 1])) ?>">← Précédents</a><?php endif; ?>
                            <?php if ($page < $pages): ?><a class="btn petit secondaire" href="<?= h($url(['page' => $page + 1])) ?>">Suivants →</a><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>
    <?php
    pied();
}
