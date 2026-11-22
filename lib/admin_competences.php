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
            if ($n >= 1 && $n <= SEQUENCES_MAX) {
                $codes[$n][] = (string) $code;
            }
        }
    }
    $pdo = db();
    $nb = max(SEQUENCES_MIN, min(SEQUENCES_MAX, (int) ($_POST['nb_seq'] ?? SEQUENCES_MIN)));
    $ins = $pdo->prepare('INSERT OR REPLACE INTO sequences (niveau, numero, theme, support, codes) VALUES (?, ?, ?, ?, ?)');
    for ($i = 1; $i <= $nb; $i++) {
        $ins->execute([$niveau, $i, mb_substr(trim((string) ($_POST['theme'][$i] ?? '')), 0, 120),
            mb_substr(trim((string) ($_POST['support'][$i] ?? '')), 0, 120), implode(', ', codes_valides($codes[$i] ?? []))]);
    }
    $pdo->prepare('DELETE FROM sequences WHERE niveau = ? AND numero > ?')->execute([$niveau, $nb]);   // séquences retirées
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
            $res[$c][] = ['lien' => './?p=ateliers', 'texte' => $a['app'] . ' · ' . ($a['titre'] ?: $a['activite']), 'app' => true];
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
/**
 * Ateliers placés dans le Calendrier des classes d'un niveau (« 4e » : 4A, 4B…), dans l'ordre de l'année,
 * avec leurs codes (catalogue) : [ 'classes' => [noms], 'ateliers' => [ {app, atelier, titre, nom_app, seances, codes, caps} ] ].
 * caps : capacités du niveau (référentiel) travaillées par l'atelier.
 */
function scan_calendrier_niveau(string $niveau): array
{
    $chiffre = substr($niveau, 0, 1);
    $caps_niveau = capacites_niveau($niveau);
    $cat = [];
    foreach (catalogue_ateliers() as $r) {
        $cat[$r['app'] . '|' . $r['atelier']] = $r;
    }
    $classes = [];
    $places = [];   // clé => [rang moyen, première vue, ligne]
    $vu = 0;
    foreach (db()->query('SELECT * FROM classes ORDER BY nom')->fetchAll() as $c) {
        if (niveau_classe((string) $c['nom']) !== $chiffre) {
            continue;
        }
        $prog = progression_classe((int) $c['id']);
        if (!$prog) {
            continue;
        }
        $classes[] = nom_court_classe($c);
        $n = count($prog);
        foreach ($prog as $i => $p) {
            $k = $p['app'] . '|' . $p['atelier'];
            $places[$k] ??= ['rangs' => [], 'vu' => $vu++, 'p' => $p];
            $places[$k]['rangs'][] = $n > 1 ? $i / ($n - 1) : 0;   // place dans l'année de cette classe (0 → 1)
        }
    }
    uasort($places, fn($a, $b) => (array_sum($a['rangs']) / count($a['rangs'])) <=> (array_sum($b['rangs']) / count($b['rangs'])) ?: $a['vu'] <=> $b['vu']);
    $ateliers = [];
    foreach ($places as $k => $x) {
        $r = $cat[$k] ?? null;
        $codes = $r ? array_values(array_unique($r['codes'])) : [];
        $ateliers[] = ['app' => $x['p']['app'], 'atelier' => $x['p']['atelier'], 'titre' => (string) ($r['titre'] ?? $x['p']['atelier']),
                       'nom_app' => (string) ($r['nom_app'] ?? $x['p']['app']), 'seances' => (int) $x['p']['seances'], 'classes' => count($x['rangs']),
                       'codes' => $codes, 'caps' => array_values(array_filter($codes, fn($c) => isset($caps_niveau[$c])))];
    }
    return ['classes' => $classes, 'ateliers' => $ateliers];
}

/** Capacités d'un niveau dans le référentiel : code => texte. */
function capacites_niveau(string $niveau): array
{
    $res = [];
    foreach (referentiel()['themes'] ?? [] as $t) {
        foreach ($t['competences'] as $c) {
            foreach ($c['sous'] as $s) {
                foreach ($s['capacites'] as $k) {
                    if ($k['niveau'] === $niveau) {
                        $res[$k['code']] = $k['texte'];
                    }
                }
            }
        }
    }
    return $res;
}

function page_progression(string $niveau): void
{
    $seq = sequences_niveau($niveau);
    $scan = scan_calendrier_niveau($niveau);
    $ats = $scan['ateliers'];
    $proposition = !empty($_GET['scan']) && $ats;
    if ($proposition) {   // une séquence par atelier du calendrier, dans l'ordre (plus de 8 colonnes s'il le faut) : rien n'est enregistré
        $seq = [];
        for ($i = 1; $i <= max(SEQUENCES_MIN, min(SEQUENCES_MAX, count($ats))); $i++) {
            $a = $ats[$i - 1] ?? null;
            $seq[$i] = ['theme' => $a ? mb_substr($a['titre'], 0, 120) : '', 'support' => $a ? $a['nom_app'] : '', 'codes' => $a ? $a['caps'] : []];
        }
    } elseif (!empty($_GET['plus'])) {   // « + Séquence »
        $n = min(SEQUENCES_MAX, count($seq) + 1);
        $seq[$n] ??= ['theme' => '', 'support' => '', 'codes' => []];
    }
    $nbs = count($seq);
    $places = [];
    foreach ($seq as $i => $s) {
        foreach ($s['codes'] as $c) {
            $places[$c][$i] = true;
        }
    }
    $par_cap = [];   // capacité => numéros des ateliers du calendrier qui la travaillent
    foreach ($ats as $i => $a) {
        foreach ($a['caps'] as $c) {
            $par_cap[$c][] = $i + 1;
        }
    }
    $caps_niveau = capacites_niveau($niveau);
    $jamais = array_diff_key($caps_niveau, $par_cap);
    $nb = 0;
    $non_placees = 0;
    $lien = './?p=competences&vue=progression&niveau=' . rawurlencode($niveau);
    ?>
    <details class="scan-calendrier" <?= $proposition || !array_filter($seq, fn($s) => $s['codes']) ? 'open' : '' ?>>
        <summary><strong>Scan du calendrier <?= h($niveau) ?></strong>
            <span class="discret"><?= $ats ? count($ats) . ' atelier(s) · ' . count($par_cap) . ' / ' . count($caps_niveau) . ' capacités de ' . h($niveau) . ' abordées dans l’année' : 'aucun atelier dans le calendrier' ?></span></summary>
        <?php if (!$ats): ?>
            <p class="vide">Aucune classe de <?= h($niveau) ?> n’a d’atelier dans son <a href="./?p=calendrier">Calendrier</a>.</p>
        <?php else: ?>
            <p class="aide">Ateliers placés dans le Calendrier de <?= h(implode(', ', $scan['classes'])) ?>, dans l’ordre de l’année,
                avec les capacités de <?= h($niveau) ?> qu’ils déclarent (catalogue des applications).</p>
            <div class="defile"><table class="table-scan">
                <thead><tr><th>#</th><th>Atelier</th><th>Séances</th><th>Capacités de <?= h($niveau) ?></th><th>Autres codes</th></tr></thead>
                <tbody>
                <?php foreach ($ats as $i => $a): $autres = array_values(array_diff($a['codes'], $a['caps'])); ?>
                    <tr><td><?= $i + 1 ?></td>
                        <td><?= icone_app($a['app']) ?> <strong><?= h($a['titre']) ?></strong><?= $a['classes'] < count($scan['classes']) ? ' <span class="discret">(' . $a['classes'] . ' classe' . ($a['classes'] > 1 ? 's' : '') . ')</span>' : '' ?></td>
                        <td><?= $a['seances'] ?></td>
                        <td><?= $a['caps'] ? implode(' ', array_map('puce_competence', $a['caps'])) : '<span class="alerte" title="L’atelier ne déclare aucune capacité de ce niveau">aucune</span>' ?></td>
                        <td><?= $autres ? implode(' ', array_map('puce_competence', $autres)) : '' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="bilan-scan"><strong>Compétences de l’année :</strong> <?= count($par_cap) ?> capacités de <?= h($niveau) ?> sur <?= count($caps_niveau) ?> sont abordées par ces ateliers.</p>
            <?php if ($jamais): ?>
                <details><summary><?= count($jamais) ?> capacité(s) qu’aucun atelier du calendrier n’aborde</summary>
                    <ul class="liste-jamais"><?php foreach ($jamais as $c => $t): ?><li><?= puce_competence($c) ?> <?= h($t) ?></li><?php endforeach; ?></ul></details>
            <?php endif; ?>
            <p class="ligne-actions">
                <a class="btn" href="<?= h($lien . '&scan=1') ?>">Proposer la progression d’après le calendrier</a>
                <span class="discret">une séquence par atelier<?= count($ats) > SEQUENCES_MIN ? ' : ' . min(SEQUENCES_MAX, count($ats)) . ' séquences (' . (min(SEQUENCES_MAX, count($ats)) - SEQUENCES_MIN) . ' ajoutée(s) au-delà de 8)' : '' ?> ; rien n’est enregistré avant « Enregistrer ».</span>
            </p>
        <?php endif; ?>
    </details>
    <?php if ($proposition): ?>
        <p class="flash ok">Proposition d’après le calendrier : vérifiez les cases, puis « Enregistrer ». <a href="<?= h($lien) ?>">Annuler</a></p>
    <?php endif; ?>
    <p class="aide" style="margin-top:12px">Cochez les capacités travaillées dans chaque séquence. Le Suivi « Compétences » peut ensuite se limiter
        à une séquence. Une capacité qu’aucune séquence ne travaille est signalée en orange ; « 📅 » : ateliers du calendrier qui l’abordent.</p>
    <form method="post" class="progression">
        <?= csrf() ?>
        <input type="hidden" name="action" value="enregistrer_progression">
        <input type="hidden" name="niveau" value="<?= h($niveau) ?>">
        <input type="hidden" name="nb_seq" value="<?= $nbs ?>">
        <div class="defile"><table class="table-progression">
            <thead>
                <tr><th class="fixe">Progression <?= h($niveau) ?><?php if ($nbs < SEQUENCES_MAX && !$proposition): ?> <a class="discret" href="<?= h($lien . '&plus=1') ?>" title="Ajouter une colonne">+ Séquence</a><?php endif; ?></th><?php foreach ($seq as $i => $s): ?><th class="col-seq">Séquence <?= $i ?></th><?php endforeach; ?></tr>
                <tr><th class="fixe">thème</th><?php foreach ($seq as $i => $s): ?><td><input name="theme[<?= $i ?>]" value="<?= h($s['theme']) ?>" maxlength="120" aria-label="Thème de la séquence <?= $i ?>"></td><?php endforeach; ?></tr>
                <tr><th class="fixe">support</th><?php foreach ($seq as $i => $s): ?><td><input name="support[<?= $i ?>]" value="<?= h($s['support']) ?>" maxlength="120" aria-label="Support de la séquence <?= $i ?>"></td><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php foreach (referentiel()['themes'] as $t): ?>
                <?php foreach ($t['competences'] as $c): ?>
                    <tr class="ligne-competence" style="--t:<?= h($t['couleur'] ?? '#888') ?>"><th class="fixe" colspan="<?= $nbs + 1 ?>"><?= puce_competence($c['code']) ?> <?= h($c['titre']) ?></th></tr>
                    <?php foreach ($c['sous'] as $s):
                        $caps = array_values(array_filter($s['capacites'], fn($k) => $k['niveau'] === $niveau));
                        if (!$caps) continue; ?>
                        <tr class="ligne-sous"><th class="fixe" colspan="<?= $nbs + 1 ?>"><?= puce_competence($s['code']) ?> <?= h($s['titre']) ?></th></tr>
                        <?php foreach ($caps as $k): $nb++; $nulle = empty($places[$k['code']]); $non_placees += $nulle ? 1 : 0; ?>
                            <tr class="<?= $nulle ? 'non-placee' : '' ?>">
                                <td class="fixe cap-progression"><?= puce_competence($k['code']) ?> <?= h($k['texte']) ?>
                                    <?php if (!empty($par_cap[$k['code']])): ?><span class="au-calendrier" title="Ateliers du calendrier (numéros du scan)">📅 <?= h(implode(', ', $par_cap[$k['code']])) ?></span><?php endif; ?></td>
                                <?php foreach ($seq as $i => $sq): ?>
                                    <td class="case-seq"><input type="checkbox" name="c[<?= h($k['code']) ?>][]" value="<?= $i ?>" <?= !empty($places[$k['code']][$i]) ? 'checked' : '' ?>
                                        aria-label="<?= h($k['code']) ?> en séquence <?= $i ?>"></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="ligne-actions"><button>Enregistrer la progression de <?= h($niveau) ?></button>
            <span class="discret"><?= $nb - $non_placees ?> / <?= $nb ?> capacités placées dans au moins une séquence<?= $nbs > SEQUENCES_MIN ? ' · ' . $nbs . ' séquences' : '' ?>.</span></div>
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
