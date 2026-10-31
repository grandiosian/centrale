<?php
/**
 * Centrale élèves — espace enseignant : référentiel des compétences et compétences d'un élève.
 * Inclus par public/admin/index.php (utilise ses fonctions d'affichage).
 */
declare(strict_types=1);

/** Où chaque code est évalué : code => [« Parcours · étape N », …] */
function usages_competences(): array
{
    $res = [];
    $st = db()->query("SELECT e.competences, e.ordre, e.titre, p.id, p.nom FROM parcours_etapes e JOIN parcours p ON p.id = e.parcours_id
                       WHERE e.competences <> '' ORDER BY p.nom, e.ordre");
    foreach ($st->fetchAll() as $r) {
        foreach (liste_codes_competences($r['competences']) as $c) {
            $res[$c][] = ['parcours' => (int) $r['id'], 'texte' => $r['nom'] . ' · étape ' . ((int) $r['ordre'] + 1) . ' : ' . $r['titre']];
        }
    }
    return $res;
}

function page_competences(): void
{
    $ref = referentiel();
    $usages = usages_competences();
    $nb_notes = array_column(db()->query('SELECT code, COUNT(*) AS n FROM evaluations GROUP BY code')->fetchAll(), 'n', 'code');
    $total = count(array_filter(index_competences(), fn($k) => $k['type'] === 'capacite'));
    entete('Compétences');
    barre('competences');
    ?>
    <main class="large" id="referentiel">
        <h1>Compétences</h1>
        <p class="sous"><?= h($ref['titre'] ?? 'Référentiel') ?> · <?= $total ?> capacités. <?= h($ref['codes'] ?? '') ?></p>
        <?php messages(); ?>
        <?php if (!$ref): ?>
            <p class="vide">Référentiel introuvable (public/passerelle/competences.json).</p></main><?php pied(); return; ?>
        <?php endif; ?>
        <div class="filtres filtres-ref">
            <div class="seg-niveaux" role="group" aria-label="Niveau">
                <?php foreach (['' => 'Tous', '5e' => '5e', '4e' => '4e', '3e' => '3e'] as $v => $t): ?>
                    <button type="button" class="secondaire<?= $v === '' ? ' actif' : '' ?>" data-niveau="<?= $v ?>"><?= $t ?></button>
                <?php endforeach; ?>
            </div>
            <label>Rechercher<input type="search" id="ref-recherche" placeholder="réseau, matériau, SFC15…"></label>
            <label class="case"><input type="checkbox" id="ref-utilisees"> Seulement celles évaluées dans un parcours</label>
        </div>
        <p class="aide">Cliquez un code pour le copier, puis collez-le dans une étape de parcours : <code>compétences : SFC15.5.1, CCRI16.5.1</code>.
            Les applications lisent le même référentiel à l’adresse <a href="../passerelle/competences.json" target="_blank"><?= h(url_base()) ?>/passerelle/competences.json</a>.
            Échelle : <?php foreach (echelle_maitrise() as $n => $m) echo pastille_maitrise($n) . ' ' . h($m['libelle']) . ' '; ?></p>

        <?php foreach ($ref['themes'] as $t): ?>
            <section class="carte theme-ref" data-theme="<?= h($t['code']) ?>">
                <h2><span class="code-theme"><?= h($t['code']) ?></span> <?= h($t['titre']) ?>
                    <?php if (!empty($t['technostudio'])): ?><small class="discret" title="Code utilisé par TechnoStudio">· TechnoStudio <?= h($t['technostudio']) ?></small><?php endif; ?></h2>
                <?php foreach ($t['competences'] as $c): ?>
                    <div class="competence-ref">
                        <h3><span class="code-comp copier" title="Copier le code"><?= h($c['code']) ?></span> <?= h($c['titre']) ?></h3>
                        <?php foreach ($c['sous'] as $s): ?>
                            <div class="sous-ref" data-texte="<?= h(mb_strtolower($s['code'] . ' ' . $s['titre'] . ' ' . implode(' ', $s['connaissances']))) ?>">
                                <h4><span class="code-comp copier" title="Copier le code"><?= h($s['code']) ?></span> <?= h($s['titre']) ?></h4>
                                <table class="table-ref">
                                    <tbody>
                                    <?php foreach ($s['capacites'] as $k): $u = $usages[$k['code']] ?? []; ?>
                                        <tr data-niveau="<?= h($k['niveau']) ?>" data-utilisee="<?= $u ? '1' : '' ?>" data-texte="<?= h(mb_strtolower($k['code'] . ' ' . $k['texte'])) ?>">
                                            <td class="col-code"><span class="code-comp copier" title="Copier le code"><?= h($k['code']) ?></span></td>
                                            <td class="col-niv"><span class="niv-ref n<?= h($k['niveau'][0]) ?>"><?= h($k['niveau']) ?></span></td>
                                            <td><?= h($k['texte']) ?></td>
                                            <td class="col-usage"><?php foreach ($u as $x): ?><a class="usage" href="./?p=parcours&modifier=<?= $x['parcours'] ?>"><?= h($x['texte']) ?></a><?php endforeach; ?>
                                                <?php if (!empty($nb_notes[$k['code']])): ?><span class="discret"><?= (int) $nb_notes[$k['code']] ?> note<?= $nb_notes[$k['code']] > 1 ? 's' : '' ?></span><?php endif; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php if ($s['connaissances'] || $s['remobiliser']): ?>
                                    <details class="connaissances"><summary>Connaissances</summary>
                                        <?php if ($s['connaissances']): ?><ul><?php foreach ($s['connaissances'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul><?php endif; ?>
                                        <?php if ($s['remobiliser']): ?><p class="discret">À remobiliser (deux autres thèmes) :</p>
                                            <ul><?php foreach ($s['remobiliser'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul><?php endif; ?>
                                    </details>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
        <p class="vide" id="ref-rien" hidden>Aucune capacité ne correspond.</p>
    </main>
    <?php
    pied();
}

/** Section « Compétences » de la page d'un élève : dernière note par capacité, avec l'historique. */
function section_competences_eleve(int $eleve_id): void
{
    $evals = evaluations_eleve($eleve_id);
    if (!$evals) {
        return;
    }
    $index = index_competences();
    uksort($evals, fn($a, $b) => strnatcmp($a, $b));
    ?>
    <section>
        <h2>Compétences</h2>
        <p class="aide">Dernière note de chaque capacité ; l’historique est dans « voir ». Les notes viennent de la validation des étapes de parcours.</p>
        <div class="defile"><table>
            <thead><tr><th>Code</th><th>Capacité</th><th>Maîtrise</th><th>Quand</th><th>Où</th><th>Historique</th></tr></thead>
            <tbody>
            <?php foreach ($evals as $code => $liste): $d = $liste[0]; $k = $index[$code] ?? null; ?>
                <tr>
                    <td><span class="code-comp"><?= h($code) ?></span></td>
                    <td><?= h($k ? $k['texte'] : 'Code inconnu') ?></td>
                    <td><?= pastille_maitrise((int) $d['niveau']) ?></td>
                    <td><?= quand((int) $d['cree_le']) ?></td>
                    <td class="discret"><?= $d['parcours_nom'] ? h($d['parcours_nom']) . ' · étape ' . ((int) $d['etape'] + 1) : h($d['source']) ?></td>
                    <td><?php if (count($liste) > 1): ?><details><summary>voir (<?= count($liste) ?>)</summary>
                        <?php foreach ($liste as $x): ?><div><?= pastille_maitrise((int) $x['niveau']) ?> <span class="discret"><?= quand((int) $x['cree_le']) ?></span></div><?php endforeach; ?>
                    </details><?php else: ?><span class="discret">—</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}
