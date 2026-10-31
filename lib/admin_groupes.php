<?php
/**
 * Centrale élèves — groupes d'une classe (demi-groupes GR1 / GR2…).
 * Un élève appartient à un groupe au plus. Les cours de l'emploi du temps et les plans de classe
 * peuvent viser un groupe ; le suivi peut être filtré par groupe.
 */
declare(strict_types=1);

function nom_groupe_valide(string $nom): bool
{
    return (bool) preg_match('/^[\p{L}0-9 _-]{1,20}$/u', $nom);
}

function action_groupes(string $action): bool
{
    if (in_array($action, ['equipes_former', 'equipe_ajouter', 'equipe_renommer', 'equipe_supprimer', 'equipes_supprimer', 'equipes_repartir'], true)) {
        action_equipes($action);
    }
    if (!in_array($action, ['groupes_creer', 'groupe_ajouter', 'groupe_renommer', 'groupe_supprimer', 'groupes_repartir'], true)) {
        return false;
    }
    $pdo = db();
    $classe_id = (int) ($_POST['classe_id'] ?? 0);
    $retour = './?p=classe&id=' . $classe_id . '#groupes';
    if (!classe_par_id($classe_id)) {
        rediriger('./?p=classes');
    }
    $groupes = groupes_de($classe_id);
    $groupe = null;
    foreach ($groupes as $g) {
        if ((int) $g['id'] === (int) ($_POST['groupe_id'] ?? 0)) {
            $groupe = $g;
        }
    }
    $ajouter = function (string $nom, int $ordre) use ($pdo, $classe_id): void {
        $pdo->prepare('INSERT INTO groupes (classe_id, nom, ordre) VALUES (?, ?, ?)')->execute([$classe_id, $nom, $ordre]);
    };

    switch ($action) {
        case 'groupes_creer':
            if (!$groupes) {
                $ajouter('GR1', 1);
                $ajouter('GR2', 2);
                flash('Classe divisée en deux groupes, GR1 et GR2. Répartissez maintenant les élèves.');
            }
            break;

        case 'groupe_ajouter':
            $noms = array_map(fn($g) => mb_strtoupper($g['nom']), $groupes);
            for ($n = count($groupes) + 1; in_array('GR' . $n, $noms, true); $n++);
            $ajouter('GR' . $n, count($groupes) + 1);
            flash("Groupe GR$n ajouté.");
            break;

        case 'groupe_renommer':
            $nom = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nom'] ?? '')) ?? '');
            if (!$groupe) {
                break;
            }
            if (!nom_groupe_valide($nom)) {
                flash('Nom de groupe invalide (1 à 20 lettres, chiffres, espaces, - ou _).', 'err');
                break;
            }
            foreach ($groupes as $g) {
                if ((int) $g['id'] !== (int) $groupe['id'] && mb_strtolower($g['nom']) === mb_strtolower($nom)) {
                    flash("La classe a déjà un groupe « $nom ».", 'err');
                    break 2;
                }
            }
            $pdo->prepare('UPDATE groupes SET nom = ? WHERE id = ?')->execute([$nom, $groupe['id']]);
            flash("Groupe renommé en « $nom ».");
            break;

        case 'groupe_supprimer':
            if ($groupe) {
                $pdo->prepare('DELETE FROM placements WHERE groupe_id = ?')->execute([$groupe['id']]);
                // activités proposées au groupe : elles passent à la classe entière
                $pdo->prepare('UPDATE OR IGNORE propositions SET groupe_id = 0 WHERE groupe_id = ?')->execute([$groupe['id']]);
                $pdo->prepare('DELETE FROM propositions WHERE groupe_id = ?')->execute([$groupe['id']]);
                $pdo->prepare('DELETE FROM groupes WHERE id = ?')->execute([$groupe['id']]);   // élèves et cours : groupe retiré
                flash("Groupe « {$groupe['nom']} » supprimé. Ses élèves n'ont plus de groupe ; ses cours sont maintenant ceux de la classe entière.");
            }
            break;

        case 'groupes_repartir':
            $ids = array_map(fn($g) => (int) $g['id'], $groupes);
            $st = $pdo->prepare('SELECT id FROM eleves WHERE classe_id = ?');
            $st->execute([$classe_id]);
            $maj = $pdo->prepare('UPDATE eleves SET groupe_id = ? WHERE id = ?');
            $pdo->beginTransaction();
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                $gid = (int) ($_POST['g-' . $eid] ?? 0);
                $maj->execute([in_array($gid, $ids, true) ? $gid : null, $eid]);
            }
            // Un élève qui change de groupe quitte le plan de classe de son ancien groupe
            $pdo->prepare('DELETE FROM placements WHERE classe_id = ? AND groupe_id <> 0
                           AND groupe_id <> COALESCE((SELECT groupe_id FROM eleves WHERE eleves.id = placements.eleve_id), -1)')->execute([$classe_id]);
            $pdo->commit();
            $resume = implode(', ', array_map(fn($g) => $g['nom'] . ' : ' . groupe_effectif((int) $g['id']), groupes_de($classe_id)));
            flash('Répartition enregistrée (' . $resume . ').');
            break;
    }
    rediriger($retour);
}

function groupe_effectif(int $gid): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM eleves WHERE groupe_id = ?');
    $st->execute([$gid]);
    return (int) $st->fetchColumn();
}

/** Section « Groupes » de la page d'une classe. */
function section_groupes_classe(array $classe, array $eleves): void
{
    $id = (int) $classe['id'];
    $groupes = groupes_de($id);
    $sans = count(array_filter($eleves, fn($e) => $e['groupe_id'] === null));
    ?>
    <section id="groupes">
        <div class="titre-section">
            <h2>Groupes</h2>
            <?php if ($groupes): ?><?= bouton('groupe_ajouter', 'Ajouter un groupe', ['classe_id' => $id], 'secondaire') ?><?php endif; ?>
        </div>
        <?php if (!$groupes): ?>
            <p class="aide">Pour une classe en demi-groupes : chaque élève est placé dans un groupe. L'emploi du temps,
                les plans de classe et le suivi peuvent ensuite être faits par groupe.</p>
            <?= bouton('groupes_creer', 'Diviser la classe en deux groupes (GR1 et GR2)', ['classe_id' => $id]) ?>
        <?php else: ?>
            <div class="groupes-liste">
                <?php foreach ($groupes as $g): ?>
                    <div class="groupe-carte">
                        <form method="post" class="en-ligne">
                            <?= csrf() ?>
                            <input type="hidden" name="action" value="groupe_renommer">
                            <input type="hidden" name="classe_id" value="<?= $id ?>">
                            <input type="hidden" name="groupe_id" value="<?= (int) $g['id'] ?>">
                            <input name="nom" value="<?= h($g['nom']) ?>" maxlength="20" required aria-label="Nom du groupe">
                            <button class="petit secondaire">Renommer</button>
                        </form>
                        <span class="effectif"><?= (int) $g['nb'] ?> élève<?= $g['nb'] > 1 ? 's' : '' ?></span>
                        <?= bouton('groupe_supprimer', 'Supprimer', ['classe_id' => $id, 'groupe_id' => $g['id']], 'danger',
                            "Supprimer le groupe {$g['nom']} ? Ses élèves n'auront plus de groupe ; ses cours deviendront des cours de la classe entière.") ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($eleves): ?>
                <details class="carte repartition" <?= $sans ? 'open' : '' ?>>
                <summary><?= $sans ? 'Répartition des élèves' : 'Modifier la répartition des élèves' ?></summary>
                <form method="post" id="form-groupes">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="groupes_repartir">
                    <input type="hidden" name="classe_id" value="<?= $id ?>">
                    <div class="ligne-actions">
                        <button type="button" class="secondaire" id="groupes-auto">Répartir par ordre alphabétique</button>
                        <button>Enregistrer la répartition</button>
                        <span class="aide" id="groupes-info" role="status"><?= $sans ? $sans . ' élève' . ($sans > 1 ? 's' : '') . ' sans groupe.' : '' ?></span>
                    </div>
                    <div class="defile"><table class="table-groupes">
                        <thead><tr><th>N°</th><th>Nom (sur cet ordinateur)</th>
                            <?php foreach ($groupes as $g): ?><th class="col-groupe"><?= h($g['nom']) ?> <span class="compte-groupe" data-groupe="<?= (int) $g['id'] ?>"></span></th><?php endforeach; ?>
                            <th class="col-groupe">Aucun <span class="compte-groupe" data-groupe="0"></span></th></tr></thead>
                        <tbody>
                        <?php foreach ($eleves as $e): ?>
                            <tr data-numero="<?= h($e['etiquette']) ?>">
                                <td class="numero"><?= h($e['etiquette']) ?></td>
                                <td><?= nom_eleve($classe['nom'], $e['etiquette']) ?></td>
                                <?php foreach ([...array_map(fn($g) => (int) $g['id'], $groupes), 0] as $gid): ?>
                                    <td class="col-groupe"><input type="radio" name="g-<?= (int) $e['id'] ?>" value="<?= $gid ?>"
                                        aria-label="<?= h($e['etiquette']) ?> : <?= $gid ? h(array_column($groupes, 'nom', 'id')[$gid]) : 'aucun groupe' ?>"
                                        <?= (int) ($e['groupe_id'] ?? 0) === $gid ? 'checked' : '' ?>></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </form>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}


/* ======================================================================
   Équipes (pour les ateliers en équipe) : un élève est dans une équipe au plus.
   ====================================================================== */

function equipes_de(int $classe_id): array
{
    $st = db()->prepare('SELECT q.*, (SELECT COUNT(*) FROM eleves e WHERE e.equipe_id = q.id) AS nb FROM equipes q WHERE q.classe_id = ? ORDER BY q.ordre, q.id');
    $st->execute([$classe_id]);
    return $st->fetchAll();
}

function action_equipes(string $action): never
{
    $pdo = db();
    $classe_id = (int) ($_POST['classe_id'] ?? 0);
    $retour = './?p=classe&id=' . $classe_id . '#equipes';
    if (!classe_par_id($classe_id)) {
        rediriger('./?p=classes');
    }
    $equipes = equipes_de($classe_id);
    $equipe = null;
    foreach ($equipes as $q) {
        if ((int) $q['id'] === (int) ($_POST['equipe_id'] ?? 0)) {
            $equipe = $q;
        }
    }
    $nouvelle = function (int $n) use ($pdo, $classe_id): int {
        $pdo->prepare('INSERT INTO equipes (classe_id, nom, ordre) VALUES (?, ?, ?)')->execute([$classe_id, 'Équipe ' . $n, $n]);
        return (int) $pdo->lastInsertId();
    };

    switch ($action) {
        case 'equipes_former':
            // Équipes tirées au hasard, de N élèves, à l'intérieur de chaque groupe si demandé (remplace les équipes actuelles)
            $taille = max(2, min(8, (int) ($_POST['taille'] ?? 3)));
            $par_groupe = !empty($_POST['par_groupe']);
            $st = $pdo->prepare('SELECT id, groupe_id FROM eleves WHERE classe_id = ? AND actif = 1');
            $st->execute([$classe_id]);
            $paquets = [];
            foreach ($st->fetchAll() as $e) {
                $paquets[$par_groupe ? (int) $e['groupe_id'] : 0][] = (int) $e['id'];
            }
            ksort($paquets);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE eleves SET equipe_id = NULL WHERE classe_id = ?')->execute([$classe_id]);
            $pdo->prepare('DELETE FROM equipes WHERE classe_id = ?')->execute([$classe_id]);
            $n = 0;
            $maj = $pdo->prepare('UPDATE eleves SET equipe_id = ? WHERE id = ?');
            foreach ($paquets as $ids) {
                shuffle($ids);
                $nb = max(1, (int) round(count($ids) / $taille));   // tailles équilibrées (ex. 26 élèves → 9 équipes de 2 ou 3… selon N)
                $qs = [];
                for ($i = 0; $i < $nb; $i++) {
                    $qs[] = $nouvelle(++$n);
                }
                foreach ($ids as $i => $eid) {
                    $maj->execute([$qs[$i % $nb], $eid]);
                }
            }
            $pdo->commit();
            flash("$n équipes formées au hasard" . ($par_groupe ? ' dans chaque groupe' : '') . '. Ajustez si besoin, puis enregistrez.');
            break;

        case 'equipe_ajouter':
            $nouvelle(count($equipes) + 1);
            flash('Équipe ajoutée.');
            break;

        case 'equipe_renommer':
            $nom = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nom'] ?? '')) ?? ''), 0, 30);
            if ($equipe && $nom !== '') {
                $pdo->prepare('UPDATE equipes SET nom = ? WHERE id = ?')->execute([$nom, $equipe['id']]);
                flash("Équipe renommée en « $nom ».");
            }
            break;

        case 'equipe_supprimer':
            if ($equipe) {
                $pdo->prepare('DELETE FROM equipes WHERE id = ?')->execute([$equipe['id']]);
                flash("« {$equipe['nom']} » supprimée (son dossier de projet est conservé pour le suivi).");
            }
            break;

        case 'equipes_supprimer':
            $pdo->prepare('DELETE FROM equipes WHERE classe_id = ?')->execute([$classe_id]);
            flash('Toutes les équipes de la classe ont été supprimées.');
            break;

        case 'equipes_repartir':
            $ids = array_map(fn($q) => (int) $q['id'], $equipes);
            $st = $pdo->prepare('SELECT id FROM eleves WHERE classe_id = ?');
            $st->execute([$classe_id]);
            $maj = $pdo->prepare('UPDATE eleves SET equipe_id = ? WHERE id = ?');
            $pdo->beginTransaction();
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                $qid = (int) ($_POST['q-' . $eid] ?? 0);
                $maj->execute([in_array($qid, $ids, true) ? $qid : null, $eid]);
            }
            $pdo->commit();
            flash('Équipes enregistrées.');
            break;
    }
    rediriger($retour);
}

/** Section « Équipes » de la page d'une classe. */
function section_equipes_classe(array $classe, array $eleves): void
{
    $id = (int) $classe['id'];
    $equipes = equipes_de($id);
    $groupes = groupes_de($id);
    $noms_groupes = array_column($groupes, 'nom', 'id');
    $sans = count(array_filter($eleves, fn($e) => $e['equipe_id'] === null && $e['actif']));
    ?>
    <section id="equipes">
        <div class="titre-section">
            <h2>Équipes</h2>
            <?php if ($equipes): ?>
                <div class="boutons">
                    <?= bouton('equipe_ajouter', 'Ajouter une équipe', ['classe_id' => $id], 'secondaire') ?>
                    <?= bouton('equipes_supprimer', 'Supprimer les équipes', ['classe_id' => $id], 'danger', 'Supprimer toutes les équipes de cette classe ? Leurs dossiers de projet sont conservés.') ?>
                </div>
            <?php endif; ?>
        </div>
        <p class="aide">Pour les ateliers <strong>en équipe</strong> : chaque équipe partage un dossier de projet. Les élèves voient leurs coéquipiers
            par leur identifiant (jamais par leur nom).</p>
        <?php if ($eleves): ?>
            <form method="post" class="formulaire-ligne carte" <?= $equipes ? 'data-confirmer="Les équipes actuelles seront remplacées par de nouvelles équipes tirées au hasard."' : '' ?>>
                <?= csrf() ?>
                <input type="hidden" name="action" value="equipes_former">
                <input type="hidden" name="classe_id" value="<?= $id ?>">
                <label>Élèves par équipe<select name="taille"><?= options_html([2 => '2', 3 => '3', 4 => '4', 5 => '5'], 3) ?></select></label>
                <?php if ($groupes): ?><label class="case"><input type="checkbox" name="par_groupe" value="1" checked> Dans chaque groupe (<?= h(implode(', ', $noms_groupes)) ?>)</label><?php endif; ?>
                <button class="<?= $equipes ? 'secondaire' : '' ?>"><?= $equipes ? 'Refaire les équipes au hasard' : 'Former les équipes au hasard' ?></button>
            </form>
        <?php endif; ?>
        <?php if ($equipes && $eleves): ?>
            <div class="groupes-liste">
                <?php foreach ($equipes as $q): ?>
                    <div class="groupe-carte">
                        <form method="post" class="en-ligne">
                            <?= csrf() ?>
                            <input type="hidden" name="action" value="equipe_renommer">
                            <input type="hidden" name="classe_id" value="<?= $id ?>">
                            <input type="hidden" name="equipe_id" value="<?= (int) $q['id'] ?>">
                            <input name="nom" value="<?= h($q['nom']) ?>" maxlength="30" required aria-label="Nom de l'équipe" class="nom-equipe">
                            <button class="petit secondaire">Renommer</button>
                        </form>
                        <span class="effectif"><?= (int) $q['nb'] ?></span>
                        <?= bouton('equipe_supprimer', '×', ['classe_id' => $id, 'equipe_id' => $q['id']], 'lien-bouton', "Supprimer « {$q['nom']} » ?", 'Supprimer l’équipe') ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <details class="carte repartition" <?= $sans ? 'open' : '' ?>>
                <summary><?= $sans ? 'Composition des équipes (' . $sans . ' élève' . ($sans > 1 ? 's' : '') . ' sans équipe)' : 'Modifier la composition des équipes' ?></summary>
                <form method="post" id="form-equipes">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="equipes_repartir">
                    <input type="hidden" name="classe_id" value="<?= $id ?>">
                    <div class="defile"><table>
                        <thead><tr><th>N°</th><th>Nom (sur cet ordinateur)</th><?php if ($groupes): ?><th>Groupe</th><?php endif; ?><th>Équipe</th></tr></thead>
                        <tbody>
                        <?php foreach ($eleves as $e): ?>
                            <tr class="<?= $e['actif'] ? '' : 'inactif' ?>">
                                <td class="numero"><?= h($e['etiquette']) ?></td>
                                <td><?= nom_eleve($classe['nom'], $e['etiquette']) ?></td>
                                <?php if ($groupes): ?><td><?= h($noms_groupes[$e['groupe_id']] ?? '—') ?></td><?php endif; ?>
                                <td><select name="q-<?= (int) $e['id'] ?>" aria-label="Équipe de l'élève <?= h($e['etiquette']) ?>">
                                    <?= options_html(array_column($equipes, 'nom', 'id'), (string) ($e['equipe_id'] ?? ''), 'Aucune') ?></select></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <div class="ligne-actions" style="margin-top:10px"><button>Enregistrer les équipes</button></div>
                </form>
            </details>
        <?php endif; ?>
    </section>
    <?php
}
