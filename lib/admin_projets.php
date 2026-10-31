<?php
/**
 * Centrale élèves — espace enseignant : dossiers de projet des ateliers transversaux.
 * Pour un parcours et une classe : chaque élève (parcours individuel) ou chaque équipe (parcours en équipe),
 * l'avancement à chaque étape, la validation des étapes, et les fichiers déposés.
 */
declare(strict_types=1);

/**
 * Dossiers d'un parcours pour une classe : une ligne par élève, ou par équipe (+ élèves sans équipe),
 * avec l'état de chaque étape et les fichiers déposés.
 */
function dossiers_classe(array $parcours, int $classe_id): array
{
    $st = db()->prepare("SELECT id, etiquette, actif, equipe_id FROM eleves WHERE classe_id = ?
        ORDER BY CASE WHEN etiquette GLOB '[0-9]*' THEN CAST(etiquette AS INTEGER) ELSE 9999 END, etiquette");
    $st->execute([$classe_id]);
    $eleves = $st->fetchAll();
    $prog = progression_eleves(array_map(fn($e) => (int) $e['id'], $eleves));
    $catalogue = catalogue_activites();
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
    foreach ($lignes as &$l) {
        $l['etats'] = etapes_projet($parcours, $l['proprio'], $prog, $catalogue);
        $l['fichiers'] = fichiers_projet((int) $parcours['id'], $l['cle']);
        $l['attentes'] = attentes_validation($parcours, $l['etats'], $l['fichiers'], $l['proprio']['membres']);
    }
    unset($l);
    return ['eleves' => $eleves, 'lignes' => $lignes];
}

/**
 * Étapes terminées d'un dossier qui attendent l'enseignant :
 * - à valider (mode « après validation ») : ouverte, pas encore validée, pas la dernière ;
 * - à évaluer : l'étape porte des compétences que l'enseignant n'a pas encore notées pour ce dossier.
 * [ordre => ['depuis' => terminée depuis (horodatage ou 0), 'valider' => bool, 'noter' => bool]]
 */
function attentes_validation(array $parcours, array $etats, array $fichiers, array $membres = []): array
{
    $res = [];
    foreach ($etats as $i => $e) {
        if ($e['statut'] !== 'termine' || !$e['ouverte']) {
            continue;
        }
        $valider = $parcours['deblocage'] === 'validation' && $i < count($etats) - 1 && $e['validee'] === null;
        $codes = liste_codes_competences((string) ($parcours['etapes'][$i]['competences'] ?? ''));
        $noter = $codes && $membres && !notes_etape((int) $parcours['id'], $i, $membres);
        if (!$valider && !$noter) {
            continue;
        }
        $depuis = (int) ($e['maj'] ?? 0);
        foreach ($e['deposees'] as $n) {
            $depuis = max($depuis, (int) ($fichiers[$n]['maj'] ?? 0));
        }
        $res[$i] = ['depuis' => $depuis, 'valider' => $valider, 'noter' => $noter];
    }
    return $res;
}

/** Le parcours a-t-il une file « À valider / À évaluer » ? (étapes après validation, ou compétences à noter) */
function parcours_a_traiter(array $parcours): bool
{
    return $parcours['deblocage'] === 'validation'
        || (bool) array_filter($parcours['etapes'] ?? [], fn($e) => ($e['competences'] ?? '') !== '');
}

/** Tout ce qui attend une validation dans un parcours, toutes classes confondues, le plus ancien d'abord. */
function a_valider_parcours(array $parcours): array
{
    $res = [];
    if (!parcours_a_traiter($parcours)) {
        return $res;
    }
    foreach (db()->query('SELECT id, nom, etablissement_id FROM classes ORDER BY nom')->fetchAll() as $c) {
        if (!classe_concernee($parcours['classes'], $c['nom'])) {
            continue;
        }
        foreach (dossiers_classe($parcours, (int) $c['id'])['lignes'] as $l) {
            foreach ($l['attentes'] as $i => $a) {
                $res[] = ['classe' => $c, 'ligne' => $l, 'ordre' => $i] + $a;
            }
        }
    }
    usort($res, fn($a, $b) => [$a['depuis'] ?: PHP_INT_MAX, $a['classe']['nom']] <=> [$b['depuis'] ?: PHP_INT_MAX, $b['classe']['nom']]);
    return $res;
}

/** « il y a 12 min », « il y a 3 h », « il y a 2 j » */
function depuis_texte(int $ts): string
{
    if (!$ts) {
        return '—';
    }
    $s = max(0, time() - $ts);
    return $s < 60 ? 'à l’instant' : ($s < 3600 ? 'il y a ' . intdiv($s, 60) . ' min'
        : ($s < 86400 ? 'il y a ' . intdiv($s, 3600) . ' h' : 'il y a ' . intdiv($s, 86400) . ' j'));
}

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
    $a_valider = $parcours ? a_valider_parcours($parcours) : [];
    $vue = ($_GET['vue'] ?? '') === 'a_valider' && $parcours && parcours_a_traiter($parcours) ? 'a_valider' : 'tous';
    $libelle_file = $parcours && $parcours['deblocage'] === 'validation' ? 'À valider' : 'À évaluer';
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
            <?php if ($vue === 'tous'): ?><label>Classe<select name="classe" onchange="this.form.submit()"><?= options_html($classes, (string) $classe_id) ?></select></label><?php endif; ?>
            <?php if ($vue === 'a_valider'): ?><input type="hidden" name="vue" value="a_valider"><?php endif; ?>
            <noscript><button>Afficher</button></noscript>
        </form>
        <p class="sous"><?= h(MODES_PARCOURS[$parcours['mode']]) ?> · <?= h(DEBLOCAGES[$parcours['deblocage']]) ?></p>
        <?php if (parcours_a_traiter($parcours)): $base = './?p=projets&parcours=' . (int) $parcours['id']; ?>
            <nav class="onglets-vue" aria-label="Affichage">
                <a href="<?= $base ?>&vue=a_valider" class="<?= $vue === 'a_valider' ? 'actif' : '' ?><?= $a_valider ? ' urgent' : '' ?>"><?= $libelle_file ?><?= $a_valider ? ' <span class="compte">' . count($a_valider) . '</span>' : '' ?></a>
                <a href="<?= $base ?>&classe=<?= $classe_id ?>" class="<?= $vue === 'tous' ? 'actif' : '' ?>">Tous les dossiers</a>
            </nav>
        <?php endif; ?>
        <?php liste_locale(); ?>
        <?php if ($vue === 'a_valider') { liste_a_valider($parcours, $a_valider); echo '</main>'; pied(); return; } ?>
        <?php
        if (!$classe_id) {
            echo '<p class="vide">Aucune classe concernée par ce parcours.</p></main>';
            pied();
            return;
        }
        $classe = classe_par_id($classe_id);
        $lignes = dossiers_classe($parcours, $classe_id)['lignes'];   // une ligne par élève, ou par équipe (+ élèves sans équipe)
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
                    $etats = $l['etats'];
                    $fichiers = $l['fichiers']; ?>
                    <tr id="<?= h($l['cle']) ?>">
                        <td class="dossier">
                            <?php if ($l['titre'] !== ''): ?><strong><?= h($l['titre']) ?></strong><br><?php endif; ?>
                            <?php foreach ($l['membres'] as $m): ?>
                                <span class="membre"><span class="numero"><?= h($m['etiquette']) ?></span> <?= nom_eleve($classe['nom'], $m['etiquette']) ?></span>
                            <?php endforeach; ?>
                            <?php if (!$l['membres']): ?><span class="discret">aucun membre</span><?php endif; ?>
                        </td>
                        <?php foreach ($etats as $i => $e): ?>
                            <td class="etape-projet st-<?= h($e['statut']) ?><?= $e['ouverte'] ? '' : ' verrouillee' ?><?= isset($l['attentes'][$i]) ? ($l['attentes'][$i]['valider'] ? ' a-valider' : ' a-noter') : '' ?>">
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

/**
 * Vue « À valider » / « À évaluer » : toutes classes confondues, le plus ancien d'abord, avec les fichiers de l'étape,
 * la grille des compétences de l'étape (MI · MF · MS · TBM) et le bouton Valider ou Enregistrer.
 */
function liste_a_valider(array $parcours, array $items): void
{
    if (!$items) {
        echo '<p class="message ok" style="margin-top:16px">Rien à traiter pour l’instant : aucun élève n’attend de validation ni d’évaluation.</p>';
        return;
    }
    $index = index_competences();
    $echelle = echelle_maitrise();
    ?>
    <p class="aide" style="margin-top:12px">Les élèves ou équipes qui ont terminé une étape, le plus ancien en premier. Ouvrez leurs fichiers,
        notez les compétences de l’étape si elle en a (un clic par compétence, facultatif), puis « Valider » (ouvre l’étape suivante) ou « Enregistrer ».
        En équipe, la note va à chaque membre.</p>
    <div class="defile"><table class="table-a-valider" id="a-valider">
        <thead><tr><th>Terminé</th><th>Classe</th><th><?= $parcours['mode'] === 'equipe' ? 'Équipe' : 'Élève' ?></th><th>Étape terminée</th><th>Fichiers</th><th>Compétences</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $n => $it):
            $l = $it['ligne']; $e = $l['etats'][$it['ordre']]; $et = $parcours['etapes'][$it['ordre']];
            $suivante = $parcours['etapes'][$it['ordre'] + 1] ?? null;
            $codes = liste_codes_competences((string) ($et['competences'] ?? ''));
            $notes = notes_etape((int) $parcours['id'], $it['ordre'], $l['proprio']['membres']);
            $form = 'f-av-' . $n; ?>
            <tr>
                <td class="discret" title="<?= h(quand($it['depuis'] ?: null)) ?>"><?= h(depuis_texte($it['depuis'])) ?></td>
                <td><?= pastille_classe($it['classe']) ?></td>
                <td><?php if ($l['titre'] !== ''): ?><strong><?= h($l['titre']) ?></strong><br><?php endif; ?>
                    <?php foreach ($l['membres'] as $m): ?><span class="membre"><span class="numero"><?= h($m['etiquette']) ?></span> <?= nom_eleve($it['classe']['nom'], $m['etiquette']) ?></span> <?php endforeach; ?></td>
                <td><span class="num-etape"><?= $it['ordre'] + 1 ?></span> <?= h($et['titre']) ?><?= $e['texte'] !== '' ? ' <small class="discret">' . h($e['texte']) . '</small>' : '' ?></td>
                <td class="fichiers-projet"><?php $vus = 0; foreach ($e['deposees'] as $nom): if (!isset($l['fichiers'][$nom])) continue; $vus++; ?>
                    <a href="./?p=projet_fichier&id=<?= (int) $l['fichiers'][$nom]['id'] ?>"><?= h($nom) ?></a>
                <?php endforeach; ?><?= $vus ? '' : '<span class="discret">—</span>' ?></td>
                <td class="grille-notes"><?php if (!$codes): ?><span class="discret">—</span><?php endif; ?>
                    <?php foreach ($codes as $code): $k = $index[$code] ?? null; ?>
                        <div class="note-ligne">
                            <span class="code-comp" title="<?= h($k ? $k['texte'] : 'Code inconnu du référentiel') ?>"><?= h($code) ?></span>
                            <span class="choix-maitrise" role="radiogroup" aria-label="<?= h($code) ?>">
                                <?php foreach ($echelle as $niv => $m): ?>
                                    <label title="<?= h($m['libelle']) ?>" style="--m:<?= h($m['couleur']) ?>"><input type="radio" form="<?= $form ?>" name="notes[<?= h($code) ?>]" value="<?= $niv ?>"
                                        <?= ($notes[$code] ?? 0) === $niv ? 'checked' : '' ?>><span><?= h($m['code']) ?></span></label>
                                <?php endforeach; ?>
                            </span>
                        </div>
                    <?php endforeach; ?></td>
                <td class="actions">
                    <form method="post" id="<?= $form ?>"><?= csrf() ?>
                        <input type="hidden" name="action" value="valider_etape">
                        <input type="hidden" name="parcours_id" value="<?= (int) $parcours['id'] ?>">
                        <input type="hidden" name="proprietaire" value="<?= h($l['cle']) ?>">
                        <input type="hidden" name="ordre" value="<?= (int) $it['ordre'] ?>">
                        <input type="hidden" name="classe_id" value="<?= (int) $it['classe']['id'] ?>">
                        <input type="hidden" name="retour" value="a_valider">
                        <?php if (!$it['valider']): ?><input type="hidden" name="noter_seulement" value="1"><?php endif; ?>
                        <button class="petit" title="<?= h($it['valider'] ? ($suivante ? 'Enregistrer les notes et ouvrir l’étape ' . ($it['ordre'] + 2) . ' : ' . $suivante['titre'] : '') : 'Enregistrer les notes de cette étape') ?>"><?= $it['valider'] ? 'Valider' : 'Enregistrer' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php
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
