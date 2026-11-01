<?php
/**
 * Centrale élèves — espace enseignant : compétences.
 *  - onglet Compétences : référentiel (filtrable, codes à copier) et progression par séquence (5e, 4e, 3e) ;
 *  - Suivi « Compétences » : bilan élèves × compétences (moyenne haute de tous les constats) ;
 *  - page de l'élève : bilan et historique.
 * Couleurs : thème 1 (OST) vert, thème 2 (SFC) rose, thème 3 (CCRI) jaune orangé.
 * Inclus par public/admin/index.php (utilise ses fonctions d'affichage).
 */
declare(strict_types=1);

const NIVEAUX_COMPETENCES = ['5e', '4e', '3e'];

function action_competences(string $action): bool
{
    if ($action !== 'enregistrer_progression') {
        return false;
    }
    $niveau = in_array($_POST['niveau'] ?? '', NIVEAUX_COMPETENCES, true) ? $_POST['niveau'] : '5e';
    $codes = [];
    foreach ((array) ($_POST['c'] ?? []) as $code => $nums) {
        foreach ((array) $nums as $n) {
            $n = (int) $n;
            if ($n >= 1 && $n <= 8) {
                $codes[$n][] = (string) $code;
            }
        }
    }
    $pdo = db();
    $ins = $pdo->prepare('INSERT OR REPLACE INTO sequences (niveau, numero, theme, support, codes) VALUES (?, ?, ?, ?, ?)');
    for ($i = 1; $i <= 8; $i++) {
        $ins->execute([$niveau, $i, mb_substr(trim((string) ($_POST['theme'][$i] ?? '')), 0, 120),
            mb_substr(trim((string) ($_POST['support'][$i] ?? '')), 0, 120), implode(', ', codes_valides($codes[$i] ?? []))]);
    }
    flash("Progression de $niveau enregistrée.");
    rediriger('./?p=competences&vue=progression&niveau=' . $niveau);
}

/** Où chaque code est évalué ou travaillé : code => [['lien', 'texte'], …] (étapes de parcours, activités des applications) */
function usages_competences(): array
{
    $res = [];
    $st = db()->query("SELECT e.competences, e.ordre, e.titre, p.id, p.nom FROM parcours_etapes e JOIN parcours p ON p.id = e.parcours_id
                       WHERE e.competences <> '' ORDER BY p.nom, e.ordre");
    foreach ($st->fetchAll() as $r) {
        foreach (liste_codes_competences($r['competences']) as $c) {
            $res[$c][] = ['lien' => './?p=parcours&modifier=' . (int) $r['id'], 'texte' => $r['nom'] . ' · étape ' . ((int) $r['ordre'] + 1)];
        }
    }
    foreach (db()->query("SELECT app, activite, titre, competences FROM activites WHERE competences <> '' ORDER BY app, ordre")->fetchAll() as $a) {
        foreach (liste_codes_competences($a['competences']) as $c) {
            $res[$c][] = ['lien' => './?p=bibliotheque', 'texte' => $a['app'] . ' · ' . ($a['titre'] ?: $a['activite']), 'app' => true];
        }
    }
    return $res;
}

/** Options « Compétences » du sélecteur du Suivi : par niveau, puis par séquence remplie. */
function options_vue_competences(): array
{
    $res = [];
    foreach (NIVEAUX_COMPETENCES as $n) {
        $res['comp:' . $n] = 'Compétences ' . $n;
        foreach (sequences_niveau($n) as $i => $s) {
            if ($s['codes']) {
                $res['comp:' . $n . ':' . $i] = "  $n · séquence $i" . ($s['theme'] !== '' ? ' : ' . $s['theme'] : '');
            }
        }
    }
    return $res;
}

function onglets_competences(string $vue, string $niveau): void
{
    ?>
    <nav class="onglets-vue" aria-label="Compétences">
        <a href="./?p=competences" class="<?= $vue === 'referentiel' ? 'actif' : '' ?>">Référentiel</a>
        <?php foreach (NIVEAUX_COMPETENCES as $n): ?>
            <a href="./?p=competences&vue=progression&niveau=<?= $n ?>" class="<?= $vue === 'progression' && $niveau === $n ? 'actif' : '' ?>">Progression <?= $n ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function page_competences(): void
{
    $ref = referentiel();
    $vue = ($_GET['vue'] ?? '') === 'progression' ? 'progression' : 'referentiel';
    $niveau = in_array($_GET['niveau'] ?? '', NIVEAUX_COMPETENCES, true) ? $_GET['niveau'] : '5e';
    entete('Compétences');
    barre('competences');
    ?>
    <main class="large" id="referentiel">
        <h1>Compétences</h1>
        <p class="sous"><?= h($ref['titre'] ?? 'Référentiel') ?> ·
            <?php foreach ($ref['themes'] ?? [] as $t): ?><span class="pastille-theme" style="--t:<?= h($t['couleur'] ?? '#888') ?>"><?= h($t['code']) ?></span> <?php endforeach; ?></p>
        <?php messages(); ?>
        <?php if (!$ref): ?>
            <p class="vide">Référentiel introuvable (public/passerelle/competences.json).</p></main><?php pied(); return; ?>
        <?php endif; ?>
        <?php onglets_competences($vue, $niveau); ?>
        <?php $vue === 'progression' ? page_progression($niveau) : page_referentiel(); ?>
    </main>
    <?php
    pied();
}

function page_referentiel(): void
{
    $ref = referentiel();
    $usages = usages_competences();
    $nb_notes = array_column(db()->query('SELECT code, COUNT(*) AS n FROM evaluations GROUP BY code')->fetchAll(), 'n', 'code');
    $total = count(array_filter(index_competences(), fn($k) => $k['type'] === 'capacite'));
    // Nombre d'activités d'ateliers (applications) qui travaillent une capacité, puis cumul par sous-compétence, compétence, thème
    $nb_cap = fn(string $code): int => count(array_filter($usages[$code] ?? [], fn($x) => !empty($x['app'])));
    $nb_sous = fn(array $s): int => array_sum(array_map(fn($k) => $nb_cap($k['code']), $s['capacites']));
    $nb_comp = fn(array $c): int => array_sum(array_map($nb_sous, $c['sous']));
    $nb_ateliers = fn(int $n): string => '<small class="discret nb-ateliers">' . ($n ? $n . ' atelier' . ($n > 1 ? 's' : '') : 'aucun atelier') . '</small>';
    ?>
    <div class="filtres filtres-ref">
        <div class="seg-niveaux" role="group" aria-label="Niveau">
            <?php foreach (['' => 'Tous', '5e' => '5e', '4e' => '4e', '3e' => '3e'] as $v => $t): ?>
                <button type="button" class="secondaire<?= $v === '' ? ' actif' : '' ?>" data-niveau="<?= $v ?>"><?= $t ?></button>
            <?php endforeach; ?>
        </div>
        <label>Rechercher<input type="search" id="ref-recherche" placeholder="réseau, matériau, SFC15…"></label>
        <label class="case"><input type="checkbox" id="ref-utilisees"> Seulement celles travaillées (parcours ou applications)</label>
        <label>Trier<select id="ref-tri">
            <option value="">Ordre du référentiel</option>
            <option value="plus">Le plus d’ateliers d’abord</option>
            <option value="moins">Le moins d’ateliers d’abord</option>
        </select></label>
    </div>
    <p class="aide"><?= $total ?> capacités. <?= h(referentiel()['codes'] ?? '') ?> Cliquez un code pour le copier, puis collez-le dans une étape de parcours :
        <code>compétences : SFC15.5.1, CCRI16.5.1</code>. Les applications lisent le même référentiel :
        <a href="../passerelle/competences.json" target="_blank"><?= h(url_base()) ?>/passerelle/competences.json</a>.
        Échelle : <?php foreach (echelle_maitrise() as $n => $m) echo pastille_maitrise($n) . ' ' . h($m['libelle']) . ' '; ?> ; bilan = moyenne haute de tous les constats.</p>

    <?php foreach ($ref['themes'] as $t): ?>
        <section class="carte theme-ref" data-theme="<?= h($t['code']) ?>" data-nb="<?= array_sum(array_map($nb_comp, $t['competences'])) ?>" style="--t:<?= h($t['couleur'] ?? '#888') ?>">
            <h2><span class="code-theme"><?= h($t['code']) ?></span> <?= h($t['titre']) ?>
                <?php if (!empty($t['technostudio'])): ?><small class="discret" title="Code utilisé par TechnoStudio">· TechnoStudio <?= h($t['technostudio']) ?></small><?php endif; ?></h2>
            <?php foreach ($t['competences'] as $c): ?>
                <div class="competence-ref" data-nb="<?= $nb_comp($c) ?>">
                    <h3><?= puce_competence($c['code'], 'copier') ?> <?= h($c['titre']) ?> <?= $nb_ateliers($nb_comp($c)) ?></h3>
                    <?php foreach ($c['sous'] as $s): ?>
                        <div class="sous-ref" data-nb="<?= $nb_sous($s) ?>" data-texte="<?= h(mb_strtolower($s['code'] . ' ' . $s['titre'] . ' ' . implode(' ', $s['connaissances']))) ?>">
                            <h4><?= puce_competence($s['code'], 'copier') ?> <?= h($s['titre']) ?> <?= $nb_ateliers($nb_sous($s)) ?></h4>
                            <table class="table-ref">
                                <tbody>
                                <?php foreach ($s['capacites'] as $k): $u = $usages[$k['code']] ?? []; ?>
                                    <tr data-niveau="<?= h($k['niveau']) ?>" data-nb="<?= $nb_cap($k['code']) ?>" data-utilisee="<?= $u ? '1' : '' ?>" data-texte="<?= h(mb_strtolower($k['code'] . ' ' . $k['texte'])) ?>">
                                        <td class="col-code"><?= puce_competence($k['code'], 'copier') ?></td>
                                        <td class="col-niv"><span class="niv-ref n<?= h($k['niveau'][0]) ?>"><?= h($k['niveau']) ?></span></td>
                                        <td><?= h($k['texte']) ?></td>
                                        <td class="col-usage"><?php foreach ($u as $x): ?><a class="usage<?= !empty($x['app']) ? ' app' : '' ?>" href="<?= h($x['lien']) ?>"><?= h($x['texte']) ?></a><?php endforeach; ?>
                                            <?php if (!empty($nb_notes[$k['code']])): ?><span class="discret"><?= (int) $nb_notes[$k['code']] ?> constat<?= $nb_notes[$k['code']] > 1 ? 's' : '' ?></span><?php endif; ?></td>
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
    <?php
}

/** Progression d'un niveau : 8 séquences (thème, support) × capacités du niveau, comme le tableau « Progression 5e ». */
function page_progression(string $niveau): void
{
    $seq = sequences_niveau($niveau);
    $places = [];
    foreach ($seq as $i => $s) {
        foreach ($s['codes'] as $c) {
            $places[$c][$i] = true;
        }
    }
    $nb = 0;
    $non_placees = 0;
    ?>
    <p class="aide" style="margin-top:12px">Cochez les capacités travaillées dans chaque séquence. Le Suivi « Compétences » peut ensuite se limiter
        à une séquence. Une capacité qu’aucune séquence ne travaille est signalée en orange.</p>
    <form method="post" class="progression">
        <?= csrf() ?>
        <input type="hidden" name="action" value="enregistrer_progression">
        <input type="hidden" name="niveau" value="<?= h($niveau) ?>">
        <div class="defile"><table class="table-progression">
            <thead>
                <tr><th class="fixe">Progression <?= h($niveau) ?></th><?php for ($i = 1; $i <= 8; $i++): ?><th class="col-seq">Séquence <?= $i ?></th><?php endfor; ?></tr>
                <tr><th class="fixe">thème</th><?php for ($i = 1; $i <= 8; $i++): ?><td><input name="theme[<?= $i ?>]" value="<?= h($seq[$i]['theme']) ?>" maxlength="120" aria-label="Thème de la séquence <?= $i ?>"></td><?php endfor; ?></tr>
                <tr><th class="fixe">support</th><?php for ($i = 1; $i <= 8; $i++): ?><td><input name="support[<?= $i ?>]" value="<?= h($seq[$i]['support']) ?>" maxlength="120" aria-label="Support de la séquence <?= $i ?>"></td><?php endfor; ?></tr>
            </thead>
            <tbody>
            <?php foreach (referentiel()['themes'] as $t): ?>
                <?php foreach ($t['competences'] as $c): ?>
                    <tr class="ligne-competence" style="--t:<?= h($t['couleur'] ?? '#888') ?>"><th class="fixe" colspan="9"><?= puce_competence($c['code']) ?> <?= h($c['titre']) ?></th></tr>
                    <?php foreach ($c['sous'] as $s):
                        $caps = array_values(array_filter($s['capacites'], fn($k) => $k['niveau'] === $niveau));
                        if (!$caps) continue; ?>
                        <tr class="ligne-sous"><th class="fixe" colspan="9"><?= puce_competence($s['code']) ?> <?= h($s['titre']) ?></th></tr>
                        <?php foreach ($caps as $k): $nb++; $nulle = empty($places[$k['code']]); $non_placees += $nulle ? 1 : 0; ?>
                            <tr class="<?= $nulle ? 'non-placee' : '' ?>">
                                <td class="fixe cap-progression"><?= puce_competence($k['code']) ?> <?= h($k['texte']) ?></td>
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <td class="case-seq"><input type="checkbox" name="c[<?= h($k['code']) ?>][]" value="<?= $i ?>" <?= !empty($places[$k['code']][$i]) ? 'checked' : '' ?>
                                        aria-label="<?= h($k['code']) ?> en séquence <?= $i ?>"></td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="ligne-actions"><button>Enregistrer la progression de <?= h($niveau) ?></button>
            <span class="discret"><?= $nb - $non_placees ?> / <?= $nb ?> capacités placées dans au moins une séquence.</span></div>
    </form>
    <?php
}

/**
 * Suivi « Compétences » d'une classe : élèves × sous-compétences (ou capacités, en détail) du niveau,
 * éventuellement limité aux capacités d'une séquence. Cellule = bilan (moyenne haute des constats).
 */
function tableau_suivi_competences(array $classe, int $groupe_id, string $vue, bool $detail): void
{
    [, $niveau, $num] = array_pad(explode(':', $vue), 3, '');
    $niveau = in_array($niveau, NIVEAUX_COMPETENCES, true) ? $niveau : (niveau_classe($classe['nom']) ? niveau_classe($classe['nom']) . 'e' : '5e');
    $limite = $num !== '' ? (sequences_niveau($niveau)[(int) $num]['codes'] ?? []) : null;
    $colonnes = [];
    $groupes = [];
    foreach (referentiel()['themes'] as $t) {
        foreach ($t['competences'] as $c) {
            foreach ($c['sous'] as $s) {
                $caps = array_values(array_filter($s['capacites'], fn($k) => $k['niveau'] === $niveau && ($limite === null || in_array($k['code'], $limite, true))));
                if (!$caps) {
                    continue;
                }
                if ($detail) {
                    foreach ($caps as $k) {
                        $colonnes[] = ['code' => $k['code'], 'titre' => $k['texte'], 'couleur' => $t['couleur'], 'theme' => $t['code']];
                    }
                } else {
                    $colonnes[] = ['code' => $s['code'], 'titre' => $s['titre'], 'couleur' => $t['couleur'], 'theme' => $t['code'], 'caps' => array_column($caps, 'code')];
                }
            }
        }
    }
    foreach ($colonnes as $c) {
        $dernier = array_key_last($groupes);
        if ($dernier !== null && $groupes[$dernier]['theme'] === $c['theme']) {
            $groupes[$dernier]['n']++;
        } else {
            $groupes[] = ['theme' => $c['theme'], 'couleur' => $c['couleur'], 'n' => 1];
        }
    }
    $st = db()->prepare('SELECT id, etiquette, actif FROM eleves WHERE classe_id = ?' . ($groupe_id ? ' AND groupe_id = ?' : '')
        . " ORDER BY CASE WHEN etiquette GLOB '[0-9]*' THEN CAST(etiquette AS INTEGER) ELSE 9999 END, etiquette");
    $st->execute($groupe_id ? [(int) $classe['id'], $groupe_id] : [(int) $classe['id']]);
    $eleves = $st->fetchAll();
    $bilans = bilans_eleves(array_map(fn($e) => (int) $e['id'], $eleves));
    // Bilan d'une colonne sous-compétence restreinte aux capacités du niveau (et de la séquence) : on recalcule à partir des capacités
    $cellule = function (array $b, array $col): ?array {
        if (empty($col['caps'])) {
            return $b[$col['code']] ?? null;
        }
        // Moyenne haute de tous les constats des capacités de la colonne (chaque constat compte pour un)
        $n = 0;
        $prof = 0;
        $somme = 0.0;
        foreach ($col['caps'] as $c) {
            if (isset($b[$c])) {
                $n += $b[$c]['n'];
                $prof += $b[$c]['prof'];
                $somme += $b[$c]['moyenne'] * $b[$c]['n'];
            }
        }
        return $n ? ['niveau' => (int) ceil($somme / $n - 1e-9), 'n' => $n, 'prof' => $prof] : null;
    };
    ?>
    <p class="aide" style="margin-top:12px">Bilan de chaque élève : <strong>moyenne haute</strong> de tous ses constats (vos notes et celles des applications),
        <?= $detail ? 'par capacité' : 'par sous-compétence (capacités de ' . h($niveau) . ')' ?><?= $limite !== null ? ', séquence ' . (int) $num : '' ?>.
        Survolez une case pour le nombre de constats. <?php foreach (echelle_maitrise() as $n => $m) echo pastille_maitrise($n) . ' '; ?></p>
    <?php if (!$colonnes): ?>
        <p class="vide">Aucune capacité <?= $limite !== null ? 'dans cette séquence (onglet Compétences → Progression)' : 'pour ce niveau' ?>.</p>
        <?php return; ?>
    <?php endif; ?>
    <div class="defile suivi"><table class="table-competences">
        <thead>
            <tr class="groupes"><th colspan="2"></th><?php foreach ($groupes as $g): ?><th colspan="<?= $g['n'] ?>" class="entete-theme" style="--t:<?= h($g['couleur']) ?>"><?= h($g['theme']) ?></th><?php endforeach; ?></tr>
            <tr><th class="fixe">N°</th><th class="fixe2">Élève</th>
                <?php foreach ($colonnes as $c): ?><th class="col-act col-comp" style="--t:<?= h($c['couleur']) ?>" title="<?= h($c['code'] . ' — ' . $c['titre']) ?>"><span><?= h($c['code']) ?></span></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($eleves as $e): $b = $bilans[(int) $e['id']] ?? []; ?>
            <tr class="<?= $e['actif'] ? '' : 'inactif' ?>">
                <td class="numero fixe"><?= h($e['etiquette']) ?></td>
                <td class="fixe2"><a href="./?p=eleve&id=<?= (int) $e['id'] ?>#competences"><?= nom_eleve($classe['nom'], $e['etiquette']) ?></a></td>
                <?php foreach ($colonnes as $c) echo cellule_bilan($detail ? ($b[$c['code']] ?? null) : $cellule($b, $c)); ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php
}

/** Section « Compétences » de la page d'un élève : bilan (moyenne haute) par capacité, constats et historique. */
function section_competences_eleve(int $eleve_id): void
{
    $evals = evaluations_eleve($eleve_id);
    if (!$evals) {
        return;
    }
    $index = index_competences();
    $bilans = bilans_eleves([$eleve_id])[$eleve_id] ?? [];
    uksort($evals, fn($a, $b) => strnatcmp($a, $b));
    ?>
    <section id="competences">
        <h2>Compétences</h2>
        <p class="aide">Bilan = moyenne haute de tous les constats (vos notes et celles des applications). Le détail de chaque constat est dans « voir ».</p>
        <div class="defile"><table>
            <thead><tr><th>Code</th><th>Capacité</th><th>Bilan</th><th>Constats</th><th>Dernier</th><th>Détail</th></tr></thead>
            <tbody>
            <?php foreach ($evals as $code => $liste): $d = $liste[0]; $k = $index[$code] ?? null; $b = $bilans[$code] ?? null; ?>
                <tr>
                    <td><?= puce_competence($code) ?></td>
                    <td><?= h($k ? $k['texte'] : 'Code inconnu') ?></td>
                    <td><?= $b ? pastille_maitrise($b['niveau']) : '' ?></td>
                    <td><?= count($liste) ?><?= $b && $b['prof'] ? ' <span class="discret">(dont ' . (int) $b['prof'] . ' de vous)</span>' : '' ?></td>
                    <td><?= quand((int) $d['cree_le']) ?></td>
                    <td><details><summary>voir</summary>
                        <?php foreach ($liste as $x): $det = json_decode((string) $x['details'], true) ?: []; ?>
                            <div><?= pastille_maitrise((int) $x['niveau']) ?> <span class="discret"><?= quand((int) $x['cree_le']) ?> ·
                                <?= $x['source'] === 'prof' ? 'vous' . ($x['parcours_nom'] ? ', ' . h($x['parcours_nom']) . ' étape ' . ((int) $x['etape'] + 1) : '')
                                    : h($x['source'] . (isset($det['activite']) ? ' · ' . $det['activite'] : '')
                                        . (isset($det['indices']) ? ' · ' . $det['indices'] . ' indice(s)' : '') . (isset($det['essais']) ? ' · ' . $det['essais'] . ' essai(s)' : '')) ?></span></div>
                        <?php endforeach; ?>
                    </details></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}
