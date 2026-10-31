<?php
/**
 * Centrale élèves — espace enseignant : dossiers de projet des ateliers transversaux.
 * Pour un parcours et une classe : chaque élève (parcours individuel) ou chaque équipe (parcours en équipe),
 * l'avancement à chaque étape, la validation des étapes, et les fichiers déposés.
 */
declare(strict_types=1);

function page_projets(): void
{
    $pdo = db();
    $tous = $pdo->query('SELECT id, nom, mode, classes FROM parcours ORDER BY nom')->fetchAll();
    $parcours = parcours_par_id((int) ($_GET['parcours'] ?? ($tous[0]['id'] ?? 0)));
    $classes = [];
    if ($parcours) {
        foreach ($pdo->query('SELECT id, nom FROM classes ORDER BY nom')->fetchAll() as $c) {
            if (classe_concernee($parcours['classes'], $c['nom'])) {
                $classes[(int) $c['id']] = $c['nom'];
            }
        }
    }
    $classe_id = (int) ($_GET['classe'] ?? 0);
    if (!isset($classes[$classe_id])) {
        $classe_id = (int) (array_key_first($classes) ?? 0);
    }
    entete('Dossiers de projet');
    barre('parcours');
    ?>
    <main class="large">
        <p class="fil"><a href="./?p=parcours">← Parcours</a></p>
        <h1>Dossiers de projet</h1>
        <?php messages(); ?>
        <?php if (!$parcours): ?>
            <p class="vide">Aucun parcours. Créez-en un dans l'onglet Parcours.</p></main><?php pied(); return; ?>
        <?php endif; ?>
        <form method="get" class="filtres">
            <input type="hidden" name="p" value="projets">
            <label>Parcours<select name="parcours" onchange="this.form.submit()">
                <?= options_html(array_column($tous, 'nom', 'id'), (string) $parcours['id']) ?></select></label>
            <label>Classe<select name="classe" onchange="this.form.submit()"><?= options_html($classes, (string) $classe_id) ?></select></label>
            <noscript><button>Afficher</button></noscript>
        </form>
        <p class="sous"><?= h(MODES_PARCOURS[$parcours['mode']]) ?> · <?= h(DEBLOCAGES[$parcours['deblocage']]) ?></p>
        <?php liste_locale(); ?>
        <?php
        if (!$classe_id) {
            echo '<p class="vide">Aucune classe concernée par ce parcours.</p></main>';
            pied();
            return;
        }
        $classe = classe_par_id($classe_id);
        $st = $pdo->prepare("SELECT id, etiquette, actif, equipe_id FROM eleves WHERE classe_id = ?
            ORDER BY CASE WHEN etiquette GLOB '[0-9]*' THEN CAST(etiquette AS INTEGER) ELSE 9999 END, etiquette");
        $st->execute([$classe_id]);
        $eleves = $st->fetchAll();
        $prog = progression_eleves(array_map(fn($e) => (int) $e['id'], $eleves));
        $catalogue = catalogue_activites();

        // Dossiers : une ligne par élève, ou par équipe (+ élèves sans équipe)
        $lignes = [];
        if ($parcours['mode'] === 'equipe') {
            foreach (equipes_de($classe_id) as $q) {
                $membres = array_values(array_filter($eleves, fn($e) => (int) $e['equipe_id'] === (int) $q['id']));
                $lignes[] = ['cle' => 'q' . $q['id'], 'titre' => $q['nom'], 'membres' => $membres,
                             'proprio' => ['cle' => 'q' . $q['id'], 'membres' => array_map(fn($e) => (int) $e['id'], $membres)]];
            }
            foreach ($eleves as $e) {
                if ($e['equipe_id'] === null) {
                    $lignes[] = ['cle' => 'e' . $e['id'], 'titre' => 'Sans équipe', 'membres' => [$e],
                                 'proprio' => ['cle' => 'e' . $e['id'], 'membres' => [(int) $e['id']]]];
                }
            }
        } else {
            foreach ($eleves as $e) {
                $lignes[] = ['cle' => 'e' . $e['id'], 'titre' => '', 'membres' => [$e],
                             'proprio' => ['cle' => 'e' . $e['id'], 'membres' => [(int) $e['id']]]];
            }
        }
        ?>
        <?php if ($parcours['mode'] === 'equipe' && !equipes_de($classe_id)): ?>
            <p class="alerte-bloc">Cette classe n'a pas encore d'équipes : chaque élève travaille seul en attendant.
                <a href="./?p=classe&id=<?= $classe_id ?>#equipes">Former les équipes</a></p>
        <?php endif; ?>
        <?php if (!$lignes): ?>
            <p class="vide">Aucun élève dans cette classe.</p>
        <?php else: ?>
            <div class="defile suivi"><table class="table-projets">
                <thead><tr><th><?= $parcours['mode'] === 'equipe' ? 'Équipe' : 'Élève' ?></th>
                    <?php foreach ($parcours['etapes'] as $i => $et): ?>
                        <th class="col-etape" title="<?= h($et['titre']) ?>"><span class="num-etape"><?= $i + 1 ?></span> <?= h($et['titre']) ?>
                            <?php if ($et['sorties'] !== ''): ?><br><small class="discret">→ <?= h($et['sorties']) ?></small><?php endif; ?></th>
                    <?php endforeach; ?>
                    <th>Fichiers du dossier</th></tr></thead>
                <tbody>
                <?php foreach ($lignes as $l):
                    $etats = etapes_projet($parcours, $l['proprio'], $prog, $catalogue);
                    $fichiers = fichiers_projet((int) $parcours['id'], $l['cle']); ?>
                    <tr id="<?= h($l['cle']) ?>">
                        <td class="dossier">
                            <?php if ($l['titre'] !== ''): ?><strong><?= h($l['titre']) ?></strong><br><?php endif; ?>
                            <?php foreach ($l['membres'] as $m): ?>
                                <span class="membre"><span class="numero"><?= h($m['etiquette']) ?></span> <?= nom_eleve($classe['nom'], $m['etiquette']) ?></span>
                            <?php endforeach; ?>
                            <?php if (!$l['membres']): ?><span class="discret">aucun membre</span><?php endif; ?>
                        </td>
                        <?php foreach ($etats as $i => $e): ?>
                            <td class="etape-projet st-<?= h($e['statut']) ?><?= $e['ouverte'] ? '' : ' verrouillee' ?>">
                                <span class="statut"><?= ['a_faire' => $e['ouverte'] ? 'À faire' : '🔒', 'commence' => 'En cours', 'termine' => '✓ Terminé'][$e['statut']] ?></span>
                                <?php if ($e['texte'] !== ''): ?><small><?= h($e['texte']) ?></small><?php endif; ?>
                                <?php if ($parcours['deblocage'] === 'validation' && $i < count($etats) - 1 && $e['ouverte']): ?>
                                    <?= bouton('valider_etape', $e['validee'] ? '✓ Validée' : 'Valider',
                                        ['parcours_id' => $parcours['id'], 'proprietaire' => $l['cle'], 'ordre' => $i, 'classe_id' => $classe_id]
                                        + ($e['validee'] ? ['annuler' => 1] : []),
                                        $e['validee'] ? 'secondaire valide' : 'secondaire', '', $e['validee'] ? 'Annuler la validation' : 'Ouvrir l’étape suivante') ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="fichiers-projet">
                            <?php foreach ($fichiers as $f): ?>
                                <a href="./?p=projet_fichier&id=<?= (int) $f['id'] ?>" title="Version <?= (int) $f['version'] ?> · <?= h(date('d/m H:i', (int) $f['maj'])) ?>"><?= h($f['nom']) ?></a>
                            <?php endforeach; ?>
                            <?php if (!$fichiers): ?><span class="discret">—</span><?php endif; ?>
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

/** Téléchargement par l'enseignant d'un fichier de dossier de projet, ou d'un fichier fourni. */
function telecharger_fichier_projet(string $table, int $id): never
{
    $st = db()->prepare($table === 'fournis' ? 'SELECT * FROM parcours_fournis WHERE id = ?' : 'SELECT * FROM projet_fichiers WHERE id = ?');
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f) {
        http_response_code(404);
        exit('Fichier introuvable.');
    }
    servir_fichier($f['chemin'], $f['nom'], $f['type']);
}
