<?php
/**
 * Centrale élèves — espace enseignant : passerelles des ateliers (descripteurs centrale.json).
 * Fiche détaillée d'un atelier : activités, données enregistrées, fichiers, sites à autoriser.
 */
declare(strict_types=1);

/** Carte « Brancher une application » en haut de la page Ateliers. */
function carte_brancher_passerelle(): void
{
    ?>
    <form method="post" class="carte brancher">
        <?= csrf() ?>
        <input type="hidden" name="action" value="lire_passerelle">
        <div class="formulaire-ligne">
            <label style="flex:1">Brancher une application par sa passerelle
                <span class="aide">Adresse de l'application : la Centrale lit son fichier <code>centrale.json</code> (format
                    <a href="../passerelle/">passerelle.atelier</a>), remplit la fiche et connaît ses activités avant la première connexion d'un élève.</span>
                <input name="adresse" type="url" required placeholder="https://grandiosian.github.io/NetSim/"></label>
            <button>Lire la passerelle</button>
        </div>
    </form>
    <?php
}

/** Cellule « Passerelle » d'une ligne du tableau des ateliers. */
function cellule_passerelle(array $a): string
{
    if ($a['descripteur'] === '') {
        return '<span class="discret">—</span><br>' . bouton('lire_passerelle', 'Chercher', ['atelier_id' => $a['id']], 'petit secondaire', '',
            'Chercher centrale.json à l’adresse de l’application');
    }
    $d = json_decode($a['descripteur'], true) ?: [];
    $v = (string) ($d['atelier']['version'] ?? '');
    $titre = 'Lu ' . quand((int) $a['descripteur_lu']) . ' — ' . $a['descripteur_url'];
    return '<a href="./?p=passerelle&app=' . rawurlencode($a['app']) . '" title="' . h($titre) . '">'
        . (isset($d['_converti']) ? '◐ manifeste' : '● centrale.json') . '</a>'
        . ($v !== '' && $v !== '?' ? ' <span class="discret">v' . h($v) . '</span>' : '');
}

function page_passerelle(): void
{
    $app = normaliser_app((string) ($_GET['app'] ?? ''));
    $st = db()->prepare('SELECT * FROM ateliers WHERE app = ?');
    $st->execute([$app]);
    $a = $st->fetch();
    $d = $a && $a['descripteur'] !== '' ? json_decode($a['descripteur'], true) : null;
    if (!$a || !is_array($d)) {
        flash('Pas de passerelle lue pour cet atelier.', 'err');
        rediriger('./?p=ateliers');
    }
    [, $remarques] = verifier_descripteur($d);
    $at = $d['atelier'];
    $acts = (array) ($d['activites']['liste'] ?? []);
    $groupes = [];
    foreach ($acts as $x) {
        $groupes[(string) ($x['groupe'] ?? '')][] = $x;
    }
    $f = (array) ($d['fichiers'] ?? []);
    $sites = array_values(array_filter((array) ($d['centrale']['sites_a_autoriser'] ?? []), 'is_string'));
    $origines = origines_autorisees();
    $catalogue = catalogue_activites()[$app] ?? [];

    entete('Passerelle — ' . $at['nom']);
    barre('ateliers');
    ?>
    <main class="passerelle">
        <p class="fil"><a href="./?p=ateliers">← Ateliers</a></p>
        <div class="titre-section">
            <h1><?= h($a['icone'] ?: ($at['icone'] ?? '')) ?> <?= h($at['nom']) ?> <code><?= h($app) ?></code></h1>
            <div class="boutons">
                <?= bouton('lire_passerelle', 'Relire', ['atelier_id' => $a['id']], 'secondaire') ?>
                <a class="btn secondaire" href="./?p=ateliers&modifier=<?= (int) $a['id'] ?>">Modifier la fiche</a>
                <a class="btn secondaire" href="./?p=suivi&vue=app:<?= rawurlencode($app) ?>">Suivi</a>
            </div>
        </div>
        <p class="sous"><?= h((string) ($at['description'] ?? '')) ?></p>
        <?php messages(); ?>

        <div class="chiffres">
            <div><strong><?= h((string) ($at['version'] ?? '?')) ?></strong><span>version</span></div>
            <div><strong><?= count($acts) ?></strong><span>activités déclarées</span></div>
            <div><strong><?= count((array) ($d['donnees'] ?? [])) ?></strong><span>données enregistrées</span></div>
            <div><strong><?= !empty($f['utilises']) ? 'oui' : 'non' ?></strong><span>fichiers</span></div>
        </div>
        <p class="discret">Lu <?= quand((int) $a['descripteur_lu']) ?> à
            <a href="<?= h($a['descripteur_url']) ?>" target="_blank" rel="noopener"><?= h($a['descripteur_url']) ?></a>
            <?= !empty($at['depot']) ? ' · code source : <a href="' . h((string) $at['depot']) . '" target="_blank" rel="noopener">' . h((string) $at['depot']) . '</a>' : '' ?></p>

        <?php if (isset($d['_converti'])): ?>
            <div class="alerte-bloc">Cette application publie un <strong><?= h($d['_converti']) ?></strong>, pas encore de <code>centrale.json</code>.
                La Centrale en tire seulement la fiche (nom, adresse, description) : ses activités apparaîtront quand l'application les
                déclarera à la connexion d'un élève. Pour le reste, l'application doit publier un <code>centrale.json</code> au format
                <a href="../passerelle/">passerelle.atelier</a>.</div>
            <?php $m = $d['manifeste'] ?? []; if ($m): ?>
                <section class="carte"><h2>Ce que décrit le manifeste</h2>
                    <?php if (!empty($m['document'])): ?><p>Document : <code><?= h((string) ($m['document']['kind'] ?? '')) ?></code>
                        format <?= h((string) ($m['document']['formatVersion'] ?? '?')) ?>, fichiers <code><?= h((string) ($m['document']['extension'] ?? '')) ?></code>
                        <?= isset($m['document']['units']) ? '· unités : ' . h(implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($m['document']['units']), $m['document']['units']))) : '' ?></p><?php endif; ?>
                    <?php if (!empty($m['exports'])): ?><p>Exports : <?= h(implode(', ', array_map(fn($e) => (string) ($e['kind'] ?? '?'), (array) $m['exports']))) ?></p><?php endif; ?>
                    <?php if (!empty($m['requests'])): ?><details><summary><?= count((array) $m['requests']) ?> requêtes postMessage</summary><ul>
                        <?php foreach ((array) $m['requests'] as $k => $v): ?><li><code><?= h((string) $k) ?></code> — <?= h(is_string($v) ? $v : '') ?></li><?php endforeach; ?>
                    </ul></details><?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($remarques && !isset($d['_converti'])): ?>
            <details class="carte remarques"><summary><?= count($remarques) ?> remarque<?= count($remarques) > 1 ? 's' : '' ?> sur le descripteur</summary>
                <ul><?php foreach ($remarques as $r): ?><li><?= h($r) ?></li><?php endforeach; ?></ul>
                <p class="discret">Sans conséquence pour les élèves : à transmettre au développeur de l'application.</p></details>
        <?php endif; ?>

        <?php if ($sites): ?>
            <section class="carte">
                <h2>Sites à autoriser</h2>
                <p class="aide">L'application est servie depuis ces sites : ils doivent faire partie des origines autorisées de la Centrale
                    (Réglages), sinon les élèves ne pourront pas enregistrer.</p>
                <ul class="sites"><?php foreach ($sites as $s): $ok = !$origines || in_array(rtrim($s, '/'), array_map(fn($o) => rtrim($o, '/'), $origines), true); ?>
                    <li><span class="<?= $ok ? 'ok' : 'alerte' ?>"><?= $ok ? '✓' : '✗' ?></span> <code><?= h($s) ?></code>
                        <?= $ok ? '<span class="discret">autorisé' . ($origines ? '' : ' (la Centrale accepte tous les sites)') . '</span>'
                            : bouton('autoriser_site', 'Autoriser', ['site' => $s, 'app' => $app], 'petit') ?></li>
                <?php endforeach; ?></ul>
            </section>
        <?php endif; ?>

        <?php $nav = is_array($d['centrale']['parcours'] ?? null) ? $d['centrale']['parcours'] : null; if (!isset($d['_converti'])): ?>
            <section class="carte">
                <h2>Dans un parcours</h2>
                <?php $bs = $nav['bouton_suivant'] ?? null; ?>
                <p><?= match ($bs) {
                    'application' => 'L’application place elle-même son bouton « Étape suivante » (Centrale.allerEtapeSuivante).',
                    'aucun' => 'Pas de bouton « Étape suivante » : l’élève repasse par « ← Mes ateliers ».',
                    'module' => 'Bouton « Étape suivante → » du module, en bas à gauche, à côté de « ← Mes ateliers ».',
                    default => 'Non précisé par la passerelle : le module ajoute le bouton « Étape suivante → » en bas à gauche.',
                } ?></p>
                <?php if ($nav && ($nav['avant_de_quitter'] ?? '') !== ''): ?><p class="discret">Avant de changer d’étape : <?= h((string) $nav['avant_de_quitter']) ?></p><?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($acts): ?>
            <section class="carte">
                <h2>Activités (<?= count($acts) ?>)</h2>
                <p class="aide">Elles forment les colonnes du suivi et servent dans les étapes des parcours
                    (<code>Titre | <?= h($app) ?> | identifiant | adresse</code>).
                    <?= count($catalogue) > count($acts) ? count($catalogue) - count($acts) . ' autre(s) activité(s) déclarée(s) par l’application à la connexion.' : '' ?></p>
                <?php if (!empty($d['activites']['dynamiques']['motif'])): ?>
                    <p class="discret">Activités créées en cours d'année : <?= h((string) ($d['activites']['dynamiques']['description'] ?? '')) ?>
                        (identifiants <code><?= h((string) $d['activites']['dynamiques']['motif']) ?></code>).</p>
                <?php endif; ?>
                <div class="defile"><table>
                    <thead><tr><th>Groupe</th><th>Identifiant</th><th>Titre</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($groupes as $g => $liste): foreach ($liste as $i => $x): ?>
                        <tr><?php if ($i === 0): ?><td rowspan="<?= count($liste) ?>"><strong><?= h($g ?: '—') ?></strong></td><?php endif; ?>
                            <td><code><?= h((string) ($x['id'] ?? '')) ?></code></td>
                            <td><?= h((string) ($x['titre'] ?? '')) ?><?= !empty($x['description']) ? '<br><span class="discret">' . h((string) $x['description']) . '</span>' : '' ?></td>
                            <td><?= isset($x['total']) ? (int) $x['total'] : '' ?></td></tr>
                    <?php endforeach; endforeach; ?>
                    </tbody>
                </table></div>
            </section>
        <?php endif; ?>

        <?php if (!empty($d['progression']['champs'])): ?>
            <section class="carte">
                <h2>Progression envoyée</h2>
                <?php if (!empty($d['progression']['envoi'])): ?><p class="aide"><?= h(is_string($d['progression']['envoi']) ? $d['progression']['envoi'] : '') ?></p><?php endif; ?>
                <dl class="champs"><?php foreach ((array) $d['progression']['champs'] as $k => $v): ?>
                    <dt><code><?= h((string) $k) ?></code></dt><dd><?= h(is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)) ?></dd>
                <?php endforeach; ?></dl>
                <?php if (!empty($d['progression']['champs_non_utilises'])): ?>
                    <p class="discret">Non utilisés : <?= h(implode(', ', (array) $d['progression']['champs_non_utilises'])) ?></p><?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($d['donnees'])): ?>
            <section class="carte">
                <h2>Données enregistrées sur la Centrale</h2>
                <p class="aide">Travail de l'élève sauvegardé par l'application (autosave), consultable dans la page de chaque élève.</p>
                <div class="defile"><table>
                    <thead><tr><th>Clé</th><th>Contenu</th><th>Enregistrement</th><th>Taille</th></tr></thead>
                    <tbody><?php foreach ((array) $d['donnees'] as $k => $v): $v = is_array($v) ? $v : []; ?>
                        <tr><td><code><?= h((string) $k) ?></code></td><td><?= h((string) ($v['description'] ?? '')) ?></td>
                            <td class="discret"><?= h((string) ($v['sauvegarde'] ?? '')) ?></td>
                            <td class="discret"><?= h((string) ($v['taille_typique'] ?? '')) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </section>
        <?php endif; ?>

        <section class="carte">
            <h2>Fichiers et dossier de projet</h2>
            <?php if (empty($f['utilises'])): ?>
                <p>Cette application n'échange pas de fichiers avec la Centrale.</p>
            <?php else: ?>
                <p><?= ($f['dossier_projet'] ?? true) === false
                    ? 'Elle n’utilise pas encore le dossier de projet des ateliers transversaux : les lignes « reçoit / produit » d’un parcours sont sans effet pour elle.'
                    : 'Elle lit et écrit dans le dossier de projet des ateliers transversaux.' ?></p>
                <?php foreach (['ressources' => 'Ressources qu’elle lit (déposées dans Fichiers)', 'recoit' => 'Fichiers qu’elle sait recevoir', 'produit' => 'Fichiers qu’elle produit'] as $l => $titre):
                    $liste = fichiers_declares($d, $l); if (!$liste) continue; ?>
                    <h3><?= $titre ?></h3>
                    <ul><?php foreach ($liste as $x): ?>
                        <li><code><?= h((string) $x['nom']) ?></code><?= !empty($x['type']) ? ' <span class="discret">' . h((string) $x['type']) . '</span>' : '' ?>
                            <?= !empty($x['description']) ? ' — ' . h((string) $x['description']) : '' ?></li>
                    <?php endforeach; ?></ul>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($f['note'])): ?><p class="discret"><?= h((string) $f['note']) ?></p><?php endif; ?>
        </section>

        <details class="carte">
            <summary>Descripteur complet (JSON)</summary>
            <pre class="json"><?= h(json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </details>
        <p><?= bouton('oublier_passerelle', 'Oublier ce descripteur', ['atelier_id' => $a['id']], 'danger petit', 'Oublier le descripteur lu ?') ?></p>
    </main>
    <?php
    pied();
}

/** Carte de remarques sur l'enchaînement des étapes d'un parcours (fichiers reçus / produits). */
function carte_enchainement(int $parcours_id): void
{
    $st = db()->prepare('SELECT * FROM parcours_etapes WHERE parcours_id = ? ORDER BY ordre, id');
    $st->execute([$parcours_id]);
    $etapes = $st->fetchAll();
    $rem = verifier_enchainement($etapes, array_keys(fichiers_fournis($parcours_id)));
    $sans = array_values(array_unique(array_filter(array_map(fn($e) => descripteur_complet($e['app']) ? null : $e['app'], $etapes))));
    if (!$rem && !$sans) {
        if (array_filter($etapes, fn($e) => $e['entrees'] !== '' || $e['sorties'] !== '')) {
            echo '<p class="message ok" style="margin-top:12px">Enchaînement des fichiers vérifié avec les passerelles des ateliers : rien à signaler.</p>';
        }
        return;
    }
    ?>
    <section class="carte enchainement">
        <h3>Enchaînement des étapes</h3>
        <?php if ($rem): ?><ul><?php foreach ($rem as $r): ?><li><?= h($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($sans): ?><p class="discret">Sans centrale.json lu (vérification impossible) : <?= h(implode(', ', $sans)) ?>.
            <a href="./?p=ateliers">Lire leur centrale.json</a></p><?php endif; ?>
    </section>
    <?php
}
