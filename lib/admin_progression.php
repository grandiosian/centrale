<?php
/**
 * Centrale élèves — espace enseignant : calendrier des ateliers d'une classe (page ?p=calendrier).
 * Les ateliers dans l'ordre où la classe les fait, chacun avec sa date d'ouverture (règles : lib/progression.php).
 * L'éditeur (catalogue, liste à ranger, dates calculées en direct) est dans public/admin/admin.js (« Calendrier »).
 * Inclus par public/admin/index.php (utilise ses fonctions d'affichage).
 */
declare(strict_types=1);

/** Ateliers du catalogue (actifs et retirés), rangés par « app|atelier ». */
function ateliers_catalogue_par_cle(): array
{
    $res = [];
    foreach (catalogue_ateliers(null, true) as $r) {
        $res[$r['app'] . '|' . $r['atelier']] = $r;
    }
    return $res;
}

/** Remplace le calendrier d'une classe par $lignes (dans l'ordre) : [ {app, atelier, seances, ouvre_le, date_fixe, revision} ]. */
function ecrire_progression(int $classe_id, array $lignes): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM progression_ateliers WHERE classe_id = ?')->execute([$classe_id]);
    $ins = $pdo->prepare('INSERT OR IGNORE INTO progression_ateliers (classe_id, ordre, app, atelier, seances, ouvre_le, revision, date_fixe)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (array_values($lignes) as $i => $l) {
        $d = (string) ($l['ouvre_le'] ?? '');
        $ins->execute([$classe_id, $i, (string) $l['app'], (string) $l['atelier'], max(0, min(30, (int) ($l['seances'] ?? 1))),
                       date_valide($d) ? $d : '', empty($l['revision']) ? 0 : 1, empty($l['date_fixe']) || !date_valide($d) ? 0 : 1]);
    }
    $pdo->commit();
}

/** Lignes envoyées par l'éditeur (champ « lignes », en JSON, dans l'ordre affiché). */
function lignes_calendrier_postees(): array
{
    $brut = json_decode((string) ($_POST['lignes'] ?? '[]'), true);
    $res = [];
    $vues = [];
    foreach (is_array($brut) ? $brut : [] as $l) {
        $app = trim((string) ($l['app'] ?? ''));
        $atelier = trim((string) ($l['atelier'] ?? ''));
        if ($app === '' || $atelier === '' || isset($vues["$app|$atelier"])) {
            continue;
        }
        $vues["$app|$atelier"] = true;
        $res[] = ['app' => $app, 'atelier' => $atelier, 'seances' => (int) ($l['seances'] ?? 1), 'ouvre_le' => trim((string) ($l['ouvre_le'] ?? '')),
                  'date_fixe' => !empty($l['date_fixe']), 'revision' => !empty($l['revision'])];
    }
    return $res;
}

/** Traite les formulaires de la page Calendrier des ateliers. Renvoie false si l'action ne la concerne pas. */
function action_progression(string $action): bool
{
    if (!in_array($action, ['enregistrer_calendrier', 'copier_calendrier', 'vider_calendrier'], true)) {
        return false;
    }
    $cid = (int) ($_POST['classe_id'] ?? 0);
    $classe = classe_par_id_progression($cid);
    if (!$classe) {
        flash('Classe introuvable.', 'err');
        rediriger('./?p=calendrier');
    }
    $retour = './?p=calendrier&classe=' . $cid;
    switch ($action) {
        case 'enregistrer_calendrier':
            $lignes = lignes_calendrier_postees();
            ecrire_progression($cid, $lignes);
            $n = count($lignes);
            $sans = count(array_filter($lignes, fn($l) => !date_valide($l['ouvre_le'])));
            flash($n
                ? 'Calendrier de ' . $classe['nom'] . " enregistré ($n atelier" . ($n > 1 ? 's' : '') . ').'
                  . ($sans ? " $sans sans date : pas montré" . ($sans > 1 ? 's' : '') . ' aux élèves.' : '')
                : 'Calendrier de ' . $classe['nom'] . ' vidé : ses élèves retrouvent tous les ateliers ouverts.', $sans ? 'err' : 'ok');
            rediriger($retour);

        case 'copier_calendrier':
            $lignes = progression_classe($cid);
            $recalculer = !empty($_POST['recalculer']);
            $debut = $lignes[0]['ouvre_le'] ?? '' ?: aujourd_hui();
            $faites = [];
            foreach ((array) ($_POST['cibles'] ?? []) as $c) {
                $c = (int) $c;
                $cible = $c !== $cid ? classe_par_id_progression($c) : null;
                if (!$cible) {
                    continue;
                }
                $copie = $lignes;
                if ($recalculer) {   // l'emploi du temps de l'autre classe : plus aucune date fixée à la main
                    $copie = calculer_dates_progression($c, array_map(fn($l) => ['date_fixe' => 0] + $l, $copie), $debut);
                }
                ecrire_progression($c, $copie);
                $faites[] = $cible['nom'];
            }
            flash($faites ? 'Calendrier copié vers ' . implode(', ', $faites) . ($recalculer ? ' (dates recalculées d’après leur emploi du temps)' : '') . '.'
                : 'Choisissez au moins une autre classe.', $faites ? 'ok' : 'err');
            rediriger($retour);

        case 'vider_calendrier':
            db()->prepare('DELETE FROM progression_ateliers WHERE classe_id = ?')->execute([$cid]);
            flash('Calendrier de ' . $classe['nom'] . ' supprimé : ses élèves retrouvent tous les ateliers ouverts.');
            rediriger($retour);
    }
    return true;
}

/** Page Calendrier des ateliers : choix de la classe, puis son éditeur. */
function page_calendrier_ateliers(): void
{
    $pdo = db();
    $classes = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM progression_ateliers p WHERE p.classe_id = c.id) AS nb
                            FROM classes c ORDER BY c.nom')->fetchAll();
    $cid = (int) ($_GET['classe'] ?? 0);
    $classe = null;
    foreach ($classes as $c) {
        if ((int) $c['id'] === $cid) {
            $classe = $c;
        }
    }
    entete('Calendrier des ateliers');
    barre('calendrier');
    ?>
    <main class="large">
        <div class="titre-section">
            <h1>Calendrier des ateliers</h1>
            <div class="titre-actions"><a class="btn petit secondaire" href="./?p=ateliers">Ateliers →</a></div>
        </div>
        <p class="aide">Pour chaque classe : choisissez ses ateliers, rangez-les dans l’ordre, et chacun reçoit sa date d’ouverture d’après l’emploi du temps.
            Une classe qui a un calendrier ne voit <strong>que ces ateliers</strong>, à partir de leur date (« À venir » avant).
            Sans calendrier, elle voit tous ses ateliers. Dans tous les cas, les étapes d’un atelier s’ouvrent une à une.</p>
        <?php messages(); ?>
        <nav class="progression-classes" aria-label="Classes">
            <?php foreach ($classes as $c): ?>
                <a class="pc-classe<?= (int) $c['id'] === $cid ? ' actif' : '' ?><?= (int) $c['nb'] ? '' : ' sans-calendrier' ?>" href="./?p=calendrier&amp;classe=<?= (int) $c['id'] ?>"
                   title="<?= (int) $c['nb'] ? (int) $c['nb'] . ' atelier' . ((int) $c['nb'] > 1 ? 's' : '') . ' au calendrier' : 'Pas de calendrier' ?>">
                    <?= h($c['nom']) ?> <span class="pc-nb"><?= (int) $c['nb'] ?: '–' ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if (!$classe): ?>
            <div class="carte"><p style="margin:0">Choisissez une classe ci-dessus. Le nombre indique ses ateliers au calendrier (– : pas de calendrier).</p></div>
        <?php else: corps_calendrier($classe, $classes); endif; ?>
    </main>
    <?php
    pied();
}

/** Éditeur du calendrier d'une classe : les données pour admin.js, le squelette de la page, la copie et la suppression. */
function corps_calendrier(array $classe, array $classes): void
{
    $cid = (int) $classe['id'];
    $cat = ateliers_catalogue_par_cle();
    $lignes = progression_classe($cid);
    $aujourdhui = aujourd_hui();
    $seances = seances_annee_classe($cid, $aujourdhui);
    $prochaine = $seances[premiere_seance_depuis($seances, $aujourdhui)] ?? $aujourdhui;
    $niveau = niveau_classe((string) $classe['nom']);

    $catalogue = [];
    foreach ($cat as $cle => $r) {
        $catalogue[] = ['cle' => $cle, 'app' => $r['app'], 'atelier' => $r['atelier'], 'nom_app' => $r['nom_app'] ?: $r['app'],
                        'icone' => $r['icone_app'] ?: '🧩', 'titre' => $r['titre'] ?: $r['atelier'], 'niveau' => (string) $r['niveau'],
                        'duree' => (string) $r['duree'], 'seances' => seances_d_apres_duree((string) $r['duree']),
                        'etapes' => count($r['activites']), 'actif' => $r['etat'] === 'actif'];
    }
    $autres = [];   // calendriers des autres classes, pour « Reprendre le calendrier de… »
    foreach ($classes as $c) {
        if ((int) $c['id'] !== $cid && (int) $c['nb']) {
            $autres[] = ['id' => (int) $c['id'], 'nom' => $c['nom'], 'meme_niveau' => niveau_classe((string) $c['nom']) === $niveau,
                         'lignes' => array_map(fn($l) => ['app' => $l['app'], 'atelier' => $l['atelier'], 'seances' => $l['seances'], 'revision' => $l['revision']],
                                               progression_classe((int) $c['id']))];
        }
    }
    $donnees = [
        'classe' => ['id' => $cid, 'nom' => $classe['nom'], 'niveau' => $niveau],
        'aujourdhui' => $aujourdhui,
        'prochaine' => $prochaine,
        'seances' => $seances,
        'cours' => cours_comptes_calendrier($cid, $aujourdhui),
        'lignes' => array_map(fn($l) => ['app' => $l['app'], 'atelier' => $l['atelier'], 'seances' => $l['seances'], 'ouvre_le' => $l['ouvre_le'],
                                         'date_fixe' => $l['date_fixe'], 'revision' => $l['revision']], $lignes),
        'catalogue' => $catalogue,
        'autres' => $autres,
        'sans_calendrier' => $lignes ? null : vue_classe_sans_calendrier($cid, (string) $classe['nom']),   // ce que la classe voit aujourd'hui
    ];
    ?>
    <script type="application/json" id="calat-donnees"><?= json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <form method="post" id="calat-form" class="calat">
        <?= csrf() ?>
        <input type="hidden" name="action" value="enregistrer_calendrier">
        <input type="hidden" name="classe_id" value="<?= $cid ?>">
        <input type="hidden" name="lignes" value="">

        <section class="carte calat-tete">
            <div class="calat-tete-ligne">
                <h2><?= h($classe['nom']) ?> <span class="discret" id="calat-resume"></span></h2>
                <span class="calat-enregistrer"><span class="calat-etat" id="calat-etat" aria-live="polite"></span> <button id="calat-bouton">Enregistrer</button></span>
            </div>
            <div class="calat-debut">
                <label class="calat-en-ligne">Premier atelier le <input type="date" id="calat-debut" value="<?= h($lignes[0]['ouvre_le'] ?? '' ?: $prochaine) ?>"></label>
                <span class="discret" id="calat-cours"></span>
            </div>
            <div id="calat-avis" class="calat-avis" hidden></div>
            <div id="calat-reprendre" class="calat-reprendre" hidden>
                <span id="calat-reprendre-texte"></span>
                <select id="calat-reprendre-classe" aria-label="Classe dont reprendre le calendrier"></select>
                <button type="button" class="petit secondaire" id="calat-reprendre-bouton">Reprendre</button>
            </div>
        </section>

        <div class="calat-grille">
            <section class="carte calat-catalogue" aria-label="Catalogue des ateliers">
                <h3>Ajouter des ateliers</h3>
                <input type="search" id="calat-cherche" placeholder="Chercher un atelier…" aria-label="Chercher un atelier">
                <div class="calat-filtres" id="calat-niveaux" role="group" aria-label="Niveau"></div>
                <div class="calat-filtres" id="calat-apps" role="group" aria-label="Application"></div>
                <ul class="calat-cat-liste" id="calat-cat-liste"></ul>
            </section>
            <section class="carte calat-classe" aria-label="Ateliers de la classe">
                <h3>Ateliers de <?= h($classe['nom']) ?>, dans l’ordre</h3>
                <p class="aide">Glissez une ligne (⠿) ou utilisez ↑ ↓ pour la ranger. Les dates suivent l’ordre et le nombre de séances.
                    Changez une date à la main : elle est fixée (📌) et les suivantes repartent de là.</p>
                <ol class="calat-liste" id="calat-liste"></ol>
                <p class="calat-vide" id="calat-vide" hidden>Aucun atelier pour l’instant : ajoutez-en avec « + » à gauche<span id="calat-vide-reprendre">, ou reprenez le calendrier d’une autre classe</span>.</p>
                <div class="calat-actuel" id="calat-actuel" hidden></div>
            </section>
        </div>
    </form>
    <noscript><p class="carte">Cette page a besoin de JavaScript.</p></noscript>

    <?php if ($lignes): ?>
    <section class="carte">
        <h3>Copier ce calendrier vers d’autres classes</h3>
        <form method="post" class="calat-copie" id="calat-copie" data-confirmer="Remplacer le calendrier des classes cochées par celui de <?= h($classe['nom']) ?> ?">
            <?= csrf() ?>
            <input type="hidden" name="action" value="copier_calendrier">
            <input type="hidden" name="classe_id" value="<?= $cid ?>">
            <div class="calat-cibles">
                <?php foreach ($classes as $c): if ((int) $c['id'] === $cid) continue; $meme = niveau_classe((string) $c['nom']) === $niveau; ?>
                    <label class="calat-case<?= $meme ? '' : ' autre-niveau' ?>"><input type="checkbox" name="cibles[]" value="<?= (int) $c['id'] ?>"> <?= h($c['nom']) ?><?= (int) $c['nb'] ? ' <span class="discret">(remplace ' . (int) $c['nb'] . ')</span>' : '' ?></label>
                <?php endforeach; ?>
            </div>
            <label class="calat-case"><input type="checkbox" name="recalculer" value="1" checked> Recalculer les dates d’après l’emploi du temps de chaque classe, à partir du <?= h(date_lisible($lignes[0]['ouvre_le'] ?: $aujourdhui, false)) ?></label>
            <div><button class="secondaire">Copier</button> <span class="discret">Le calendrier enregistré est copié (enregistrez d’abord vos modifications).</span></div>
        </form>
    </section>
    <div class="calat-supprimer"><?= bouton('vider_calendrier', 'Supprimer le calendrier de ' . $classe['nom'], ['classe_id' => $cid], 'danger petit',
        'Supprimer le calendrier ? Les élèves de ' . $classe['nom'] . ' retrouveront tous les ateliers ouverts.') ?></div>
    <?php endif;
}
