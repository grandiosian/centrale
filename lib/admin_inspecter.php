<?php
/**
 * Centrale élèves — espace enseignant : « Inspecter » un atelier (page Ateliers, ?p=ateliers&app=…&inspecter=…).
 *
 * Montre comment l'atelier est rangé, couche par couche :
 *  1. la fiche du catalogue de la Centrale (table catalogue_ateliers, remplie à l'import) ;
 *  2. ses activités (identifiants « atelier/P1 » : le travail des élèves y est rattaché) et ce que les élèves en ont fait ;
 *  3. où il sert : calendrier des classes, périodes, parcours, activités demandées ;
 *  4. ce que l'application publie (son ateliers.json, et le fichier complet de l'atelier s'il en a un), lu dans le
 *     navigateur par admin/inspecter.js : feuilles, blocs, compétences, données brutes.
 * Rien n'est modifié ici. Voir la procédure : passerelle/?doc=creer-atelier.
 */
declare(strict_types=1);

/**
 * Où sont écrits les ateliers intégrés au code de chaque application (dépôt GitHub, fichiers ; regex : ids concernés).
 * Inspecter y cherche l'objet de l'atelier (« {k:'id' », « id: 'id' » ou la clé sans préfixe tuto-/exo-/ex-).
 */
const SOURCES_CODE_ATELIERS = [
    'netsim'       => ['depot' => 'grandiosian/NetSim', 'fichiers' => [['index.html', '']]],
    'technostudio' => ['depot' => 'grandiosian/TechnoStudio', 'fichiers' => [['js/demos.js', ''], ['js/demos2.js', '']]],
    'etabli3d'     => ['depot' => 'grandiosian/etabli3d', 'fichiers' => [['src/lessons.js', '^(tuto|exo)-'], ['src/examples.js', '^ex-']]],
];

/** Lien vers l'inspecteur d'un atelier du catalogue. */
function lien_inspecter_atelier(string $app, string $atelier): string
{
    return './?p=ateliers&app=' . rawurlencode($app) . '&inspecter=' . rawurlencode($atelier);
}

function page_inspecter_atelier(string $app, string $atelier): void
{
    $r = null;
    foreach (catalogue_ateliers($app) as $x) {
        if ($x['atelier'] === $atelier) {
            $r = $x;
        }
    }
    entete('Inspecter un atelier');
    barre('ateliers');
    echo '<main class="biblio large inspecteur">';
    echo '<p><a href="./?p=ateliers&gestion=1&app=' . rawurlencode($app) . '#gestion">← Ateliers</a></p>';
    if (!$r) {
        echo '<h1>Atelier introuvable</h1><p class="vide">« ' . h($app . ' / ' . $atelier) . ' » n’est pas dans le catalogue de la Centrale. '
            . 'Importez les ateliers de l’application, puis revenez.</p></main>';
        pied();
        return;
    }
    $acts = $r['activites'];
    $ids = array_column($acts, 'id');

    // ce que les élèves ont fait de chaque activité (commencée / terminée, toutes classes)
    $suivi = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT activite, COUNT(*) AS n, SUM(statut = 'termine') AS t FROM progression
                             WHERE app = ? AND activite IN ($in) GROUP BY activite");
        $st->execute(array_merge([$app], $ids));
        foreach ($st->fetchAll() as $l) {
            $suivi[$l['activite']] = [(int) $l['n'], (int) $l['t']];
        }
    }
    // calendrier des classes
    $st = db()->prepare('SELECT p.*, c.nom AS classe FROM progression_ateliers p JOIN classes c ON c.id = p.classe_id
                         WHERE p.app = ? AND p.atelier = ? ORDER BY c.nom');
    $st->execute([$app, $atelier]);
    $calendrier = $st->fetchAll();
    $periodes = periodes_ateliers($app)[$app][$atelier] ?? [];
    // parcours dont une étape ouvre cet atelier ou l'une de ses activités
    $st = db()->prepare('SELECT e.*, p.nom AS parcours FROM parcours_etapes e JOIN parcours p ON p.id = e.parcours_id
                         WHERE e.app = ? ORDER BY p.nom, e.ordre');
    $st->execute([$app]);
    $etapes = array_values(array_filter($st->fetchAll(), fn($e) => in_array((string) $e['activite'], $ids, true)
        || (string) $e['activite'] === $atelier));
    // activités demandées aux classes
    $props = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT p.activite, p.pour_le, c.nom AS classe FROM propositions p JOIN classes c ON c.id = p.classe_id
                             WHERE p.app = ? AND p.activite IN ($in) ORDER BY c.nom, p.activite");
        $st->execute(array_merge([$app], $ids));
        $props = $st->fetchAll();
    }
    $derniers = db()->prepare('SELECT source, adresse, ts FROM imports_ateliers WHERE app = ? ORDER BY id DESC LIMIT 1');
    $derniers->execute([$app]);
    $import = $derniers->fetch() ?: null;
    $ouvrir = adresse_ouvrir_atelier($r);
    $ligne = array_diff_key($r, ['contenu' => 1, 'codes' => 1, 'nom_app' => 1, 'icone_app' => 1, 'url_app' => 1]);
    $donnees = ['app' => $app, 'atelier' => $atelier, 'catalogue' => $import['adresse'] ?? '',
                'fichier' => (string) $r['fichier'], 'url_app' => (string) ($r['url_app'] ?? ''),
                'code' => SOURCES_CODE_ATELIERS[$app] ?? null];
    ?>
    <div class="titre-section">
        <h1><?= icone_app($app, $r['icone_app'] ?? '') ?> <?= h($r['titre']) ?></h1>
        <div class="titre-actions">
            <?php if ($ouvrir !== ''): ?><a class="btn secondaire" href="<?= h($ouvrir) ?>" target="_blank" rel="noopener">Ouvrir dans l’application ↗</a><?php endif; ?>
            <a class="btn secondaire" href="../passerelle/?doc=creer-atelier" target="_blank">Procédure de création</a>
        </div>
    </div>
    <p class="aide">Un atelier vit d’abord dans son application, qui le publie avec elle. La Centrale en garde une fiche (ci-dessous),
        relue à chaque « Mettre à jour ». Les <strong>identifiants</strong> (atelier, activités) ne doivent jamais changer : le travail des élèves y est rattaché.</p>

    <section class="carte">
        <h2>1. Fiche du catalogue de la Centrale</h2>
        <dl class="inspect-dl">
            <dt>Identifiant</dt><dd><code><?= h($app) ?></code> / <code><?= h($atelier) ?></code></dd>
            <dt>Titre</dt><dd><?= h($r['titre']) ?></dd>
            <dt>Niveau · durée</dt><dd><?= $r['niveau'] !== '' ? h($r['niveau']) : '<span class="discret">tous niveaux</span>' ?><?= $r['duree'] !== '' ? ' · ' . h($r['duree']) : '' ?></dd>
            <dt>Thème</dt><dd><?= $r['theme'] !== '' ? '<span class="pastille-theme" style="--t:' . h(couleur_theme($r['theme'])) . '">' . h($r['theme']) . '</span>' : '<span class="discret">—</span>' ?></dd>
            <dt>Résumé</dt><dd><?= $r['resume'] !== '' ? h($r['resume']) : '<span class="discret">—</span>' ?></dd>
            <dt>Compétences</dt><dd><?= $r['codes'] ? implode(' ', array_map('puce_competence', $r['codes'])) : '<span class="alerte">aucune déclarée</span>' ?></dd>
            <dt>Version · auteur</dt><dd><?= $r['version'] !== '' ? 'v' . h($r['version']) : '<span class="discret">—</span>' ?><?= $r['auteur'] !== '' ? ' · ' . h($r['auteur']) : '' ?></dd>
            <dt>Ouverture</dt><dd><code><?= h($r['ouvrir'] !== '' ? $r['ouvrir'] : '—') ?></code><?= $ouvrir !== '' ? ' → <a href="' . h($ouvrir) . '" target="_blank" rel="noopener">' . h(mb_strimwidth($ouvrir, 0, 90, '…')) . '</a>' : '' ?></dd>
            <dt>Source</dt><dd><?= h(SOURCES_CATALOGUE[$r['source']] ?? $r['source']) ?>
                <?php if ($import): ?><br><span class="discret">dernier import <?= quand((int) $import['ts']) ?> :</span> <a href="<?= h($import['adresse']) ?>" target="_blank" rel="noopener"><?= h($import['adresse']) ?></a><?php endif; ?></dd>
            <dt>Fichier complet</dt><dd><?= $r['fichier'] !== '' ? '<a href="' . h($r['fichier']) . '" target="_blank" rel="noopener">' . h($r['fichier']) . '</a>'
                : '<span class="discret">aucun : l’atelier est écrit dans le code de l’application</span>' ?></dd>
            <dt>État</dt><dd><?= $r['etat'] === 'actif' ? 'publié' : '<span class="alerte">retiré de l’application</span>' ?> ·
                importé <?= quand((int) $r['importe_le']) ?> · modifié <?= quand((int) $r['modifie_le']) ?> · empreinte <code><?= h(substr((string) $r['empreinte'], 0, 12)) ?></code></dd>
        </dl>
    </section>

    <section class="carte">
        <h2>2. Activités (<?= count($acts) ?>)</h2>
        <p class="aide">Une activité = une partie de l’atelier que l’application note et envoie à la Centrale. Son identifiant sert au suivi,
            aux étapes une à une (dans cet ordre), aux parcours et aux activités demandées.</p>
        <div class="defile"><table>
            <thead><tr><th>#</th><th>Identifiant</th><th>Titre</th><th>Étapes</th><th>Compétences</th><th>Élèves</th></tr></thead>
            <tbody>
            <?php foreach ($acts as $i => $x): $s = $suivi[$x['id']] ?? [0, 0]; ?>
                <tr><td class="discret"><?= $i + 1 ?></td><td><code><?= h($x['id']) ?></code></td><td><?= h($x['titre']) ?></td>
                    <td><?= (int) ($x['total'] ?? 0) ?: '<span class="discret">—</span>' ?></td>
                    <td><?= !empty($x['competences']) ? implode(' ', array_map('puce_competence', $x['competences'])) : '<span class="discret">—</span>' ?></td>
                    <td><?= $s[0] ? $s[1] . ' terminé' . ($s[1] > 1 ? 's' : '') . ' / ' . $s[0] : '<span class="discret">—</span>' ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$acts): ?><tr><td colspan="6" class="vide">L’application ne déclare pas d’activité : l’atelier s’ouvre en entier.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>

    <section class="carte">
        <h2>3. Où il sert</h2>
        <dl class="inspect-dl">
            <dt>Calendrier</dt><dd><?php if ($calendrier): ?><ul class="inspect-liste"><?php foreach ($calendrier as $c): ?>
                <li><strong><?= h($c['classe']) ?></strong> — <?= $c['ouvre_le'] !== '' ? 'ouvre le ' . h($c['ouvre_le']) : 'pas encore daté' ?>, <?= (int) $c['seances'] ?> séance<?= $c['seances'] > 1 ? 's' : '' ?>
                    <?php $e = echeances_progression((int) $c['classe_id'])[$app][$atelier] ?? null; ?><?= $e ? ' · à compléter jusqu’au ' . h($e) : '' ?><?= !empty($c['revision']) ? ' · en révision' : '' ?></li>
                <?php endforeach; ?></ul><?php else: ?><span class="discret">dans aucun calendrier</span><?php endif; ?></dd>
            <dt>Périodes</dt><dd><?php if ($periodes): ?><ul class="inspect-liste"><?php foreach ($periodes as $p): ?><li><?= h(texte_periode($p)) ?></li><?php endforeach; ?></ul>
                <?php else: ?><span class="discret">aucune : visible en permanence (hors calendrier)</span><?php endif; ?></dd>
            <dt>Parcours</dt><dd><?php if ($etapes): ?><ul class="inspect-liste"><?php foreach ($etapes as $e): ?>
                <li><a href="./?p=parcours&modifier=<?= (int) $e['parcours_id'] ?>"><?= h($e['parcours']) ?></a> — étape <?= (int) $e['ordre'] + 1 ?> « <?= h($e['titre']) ?> » <code class="discret"><?= h($e['activite'] !== '' ? $e['activite'] : 'tout l’atelier') ?></code></li>
                <?php endforeach; ?></ul><?php else: ?><span class="discret">dans aucun parcours</span><?php endif; ?></dd>
            <dt>Demandé à</dt><dd><?php if ($props): ?><ul class="inspect-liste"><?php foreach ($props as $p): ?>
                <li><strong><?= h($p['classe']) ?></strong> — <code><?= h($p['activite']) ?></code><?= $p['pour_le'] !== '' ? ' pour le ' . h($p['pour_le']) : '' ?></li>
                <?php endforeach; ?></ul><?php else: ?><span class="discret">aucune activité demandée</span><?php endif; ?></dd>
        </dl>
    </section>

    <section class="carte" id="inspect-publie">
        <h2>4. Ce que l’application publie</h2>
        <p class="aide">Lu à l’instant chez l’application (pas dans la Centrale) : sa fiche dans <code>ateliers.json</code> et, s’il existe,
            le fichier complet de l’atelier (feuilles, blocs, images).</p>
        <div id="inspect-contenu"><p class="discret">Lecture…</p></div>
    </section>

    <section class="carte" id="inspect-source">
        <h2>5. Code source</h2>
        <p class="aide">Ce qui définit vraiment l’atelier : son fichier complet, ou, pour un atelier intégré à l’application,
            l’extrait de son code (lu sur GitHub, branche <code>main</code>).</p>
        <div id="inspect-code"><p class="discret">Lecture…</p></div>
    </section>

    <details class="carte">
        <summary><strong>Ligne brute du catalogue de la Centrale</strong> <span class="discret">— table <code>catalogue_ateliers</code></span></summary>
        <pre class="inspect-json"><?= h(json_encode($ligne, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </details>
    <script type="application/json" id="json-inspecter"><?= json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script src="inspecter.js?v=<?= (int) @filemtime(__DIR__ . '/../public/admin/inspecter.js') ?>"></script>
    </main>
    <?php
    pied();
}
