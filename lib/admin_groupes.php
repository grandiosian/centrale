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
    if (!in_array($action, ['groupes_creer', 'groupe_ajouter', 'groupe_renommer', 'groupe_supprimer', 'groupes_repartir'], true)) {
        return false;
    }
    $pdo = db();
    $classe_id = (int) ($_POST['classe_id'] ?? 0);
    $retour = './?p=classe&id=' . $classe_id . '#groupes';
    if (!classe_par_id($classe_id)) {
        rediriger('./');
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
