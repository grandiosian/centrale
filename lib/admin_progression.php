<?php
/**
 * Centrale élèves — espace enseignant : progression des ateliers d'une classe (page ?p=calendrier).
 * Les ateliers dans l'ordre où la classe les fait, chacun avec sa date d'ouverture (règles : lib/progression.php).
 * Inclus par public/admin/index.php (utilise ses fonctions d'affichage).
 */
declare(strict_types=1);

/** Ateliers actifs du catalogue, rangés par « app|atelier ». */
function ateliers_catalogue_par_cle(): array
{
    $res = [];
    foreach (catalogue_ateliers(null, true) as $r) {
        $res[$r['app'] . '|' . $r['atelier']] = $r;
    }
    return $res;
}

/** Remplace la progression d'une classe par $lignes (dans l'ordre) : [ {app, atelier, seances, ouvre_le} ]. */
function ecrire_progression(int $classe_id, array $lignes): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM progression_ateliers WHERE classe_id = ?')->execute([$classe_id]);
    $ins = $pdo->prepare('INSERT OR IGNORE INTO progression_ateliers (classe_id, ordre, app, atelier, seances, ouvre_le) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (array_values($lignes) as $i => $l) {
        $d = (string) ($l['ouvre_le'] ?? '');
        $ins->execute([$classe_id, $i, (string) $l['app'], (string) $l['atelier'], max(1, min(30, (int) ($l['seances'] ?? 1))),
                       date_valide($d) ? $d : '']);
    }
    $pdo->commit();
}

/** Lignes envoyées par le formulaire de la page (dans l'ordre affiché). */
function lire_lignes_progression(): array
{
    $cles = (array) ($_POST['cle'] ?? []);
    $seances = (array) ($_POST['seances'] ?? []);
    $dates = (array) ($_POST['ouvre_le'] ?? []);
    $res = [];
    foreach ($cles as $i => $cle) {
        [$app, $atelier] = array_pad(explode('|', (string) $cle, 2), 2, '');
        if ($app === '' || $atelier === '') {
            continue;
        }
        $res[] = ['app' => $app, 'atelier' => $atelier, 'seances' => (int) ($seances[$i] ?? 1), 'ouvre_le' => trim((string) ($dates[$i] ?? ''))];
    }
    return $res;
}

/** Traite les formulaires de la page Calendrier des ateliers. Renvoie false si l'action ne la concerne pas. */
function action_progression(string $action): bool
{
    if (!in_array($action, ['enregistrer_calendrier', 'ajouter_calendrier', 'copier_calendrier', 'vider_calendrier'], true)) {
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
            $lignes = lire_lignes_progression();
            if (isset($_POST['calculer'])) {
                $debut = trim((string) ($_POST['debut'] ?? ''));
                if (!date_valide($debut)) {
                    flash('Indiquez la date à partir de laquelle commence le premier atelier.', 'err');
                    ecrire_progression($cid, $lignes);
                    rediriger($retour);
                }
                $calc = calculer_dates_progression($cid, $lignes, $debut);
                if ($calc === null) {
                    ecrire_progression($cid, $lignes);
                    flash('Cette classe n’a pas d’emploi du temps (page Emploi du temps) : saisissez les dates à la main.', 'err');
                    rediriger($retour);
                }
                $lignes = $calc;
                $sans = count(array_filter($lignes, fn($l) => $l['ouvre_le'] === ''));
                flash('Dates calculées d’après l’emploi du temps de ' . $classe['nom'] . ' à partir du ' . rtrim(date_lisible($debut, false), '.')
                    . ($sans ? " ($sans atelier" . ($sans > 1 ? 's' : '') . ' au-delà de l’année : à dater à la main)' : '') . '.');
            } else {
                flash('Progression de ' . $classe['nom'] . ' enregistrée (' . count($lignes) . ' atelier' . (count($lignes) > 1 ? 's' : '') . ').');
            }
            ecrire_progression($cid, $lignes);
            rediriger($retour);

        case 'ajouter_calendrier':
            $cat = ateliers_catalogue_par_cle();
            $lignes = progression_classe($cid);
            $deja = array_flip(array_map(fn($l) => $l['app'] . '|' . $l['atelier'], $lignes));
            $ajoutes = 0;
            foreach ((array) ($_POST['ateliers'] ?? []) as $cle) {
                $cle = (string) $cle;
                if (!isset($cat[$cle]) || isset($deja[$cle])) {
                    continue;
                }
                $r = $cat[$cle];
                // date : la séance qui suit le dernier atelier daté, si l'emploi du temps le permet
                $ouvre = '';
                $der = end($lignes) ?: null;
                if ($der && $der['ouvre_le'] !== '') {
                    $d = seances_classe($cid, $der['ouvre_le'], max(1, (int) $der['seances']) + 1);
                    $ouvre = $d[max(1, (int) $der['seances'])] ?? '';
                }
                $lignes[] = ['app' => $r['app'], 'atelier' => $r['atelier'], 'seances' => seances_d_apres_duree((string) $r['duree']), 'ouvre_le' => $ouvre];
                $deja[$cle] = true;
                $ajoutes++;
            }
            ecrire_progression($cid, $lignes);
            flash($ajoutes ? "$ajoutes atelier" . ($ajoutes > 1 ? 's ajoutés' : ' ajouté') . ' à la progression de ' . $classe['nom'] . '.' : 'Rien à ajouter.', $ajoutes ? 'ok' : 'err');
            rediriger($retour . '#progression');

        case 'copier_calendrier':
            $lignes = progression_classe($cid);
            $recalculer = !empty($_POST['recalculer']);
            $debut = $lignes[0]['ouvre_le'] ?? '';
            $faites = [];
            foreach ((array) ($_POST['cibles'] ?? []) as $c) {
                $c = (int) $c;
                $cible = $c !== $cid ? classe_par_id_progression($c) : null;
                if (!$cible) {
                    continue;
                }
                $copie = $lignes;
                if ($recalculer && $debut !== '' && ($calc = calculer_dates_progression($c, $copie, $debut)) !== null) {
                    $copie = $calc;
                }
                ecrire_progression($c, $copie);
                $faites[] = $cible['nom'];
            }
            flash($faites ? 'Progression copiée vers ' . implode(', ', $faites) . ($recalculer ? ' (dates recalculées d’après leur emploi du temps)' : '') . '.'
                : 'Choisissez au moins une autre classe.', $faites ? 'ok' : 'err');
            rediriger($retour);

        case 'vider_calendrier':
            db()->prepare('DELETE FROM progression_ateliers WHERE classe_id = ?')->execute([$cid]);
            flash('Progression de ' . $classe['nom'] . ' supprimée : ses élèves retrouvent tous les ateliers ouverts.');
            rediriger($retour);
    }
    return true;
}

/** Page Calendrier des ateliers : choix de la classe, puis ses ateliers dans l'ordre avec leurs dates. */
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
        <p class="aide">Pour chaque classe, rangez les ateliers dans l’ordre où elle les fera et donnez à chacun sa date d’ouverture.
            Les élèves ne voient alors <strong>que les ateliers de la progression</strong> : ouverts à partir de leur date, « Prochainement » avant.
            Dans un atelier, les étapes s’ouvrent <strong>une à une</strong>. Une classe sans progression garde l’accès libre d’avant.
            Les parcours et ce que vous demandez à un élève restent toujours ouverts.</p>
        <?php messages(); ?>
        <nav class="progression-classes" aria-label="Classes">
            <?php foreach ($classes as $c): ?>
                <a class="pc-classe<?= (int) $c['id'] === $cid ? ' actif' : '' ?>" href="./?p=calendrier&amp;classe=<?= (int) $c['id'] ?>">
                    <?= h($c['nom']) ?><?php if ((int) $c['nb']): ?> <span class="pc-nb" title="ateliers dans la progression"><?= (int) $c['nb'] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if (!$classe): ?>
            <div class="carte"><p style="margin:0">Choisissez une classe ci-dessus.</p></div>
        <?php else: corps_progression($classe, $classes); endif; ?>
    </main>
    <?php
    pied();
}

function corps_progression(array $classe, array $classes): void
{
    $cid = (int) $classe['id'];
    $cat = ateliers_catalogue_par_cle();
    $lignes = progression_classe($cid);
    $aujourdhui = aujourd_hui();
    $edt = (bool) seances_classe($cid, $aujourdhui, 1);
    $niveau = niveau_classe((string) $classe['nom']);
    $prochaine = $edt ? (seances_classe($cid, $aujourdhui, 1)[0] ?? $aujourdhui) : $aujourdhui;
    $debut = $lignes[0]['ouvre_le'] ?? '' ?: $prochaine;
    ?>
    <section class="carte" id="progression">
        <div class="titre-section">
            <h2><?= h($classe['nom']) ?> <span class="discret">— <?= count($lignes) ?> atelier<?= count($lignes) > 1 ? 's' : '' ?><?= $lignes ? '' : ' : accès libre (pas de progression)' ?></span></h2>
        </div>
        <?php if ($lignes): ?>
        <form method="post" id="form-progression">
            <?= csrf() ?>
            <input type="hidden" name="action" value="enregistrer_calendrier">
            <input type="hidden" name="classe_id" value="<?= $cid ?>">
            <table class="table-progression">
                <thead><tr><th class="pg-n">#</th><th>Atelier</th><th class="pg-s">Séances</th><th class="pg-d">Ouvre le</th><th>Pour les élèves</th><th class="pg-a"></th></tr></thead>
                <tbody>
                <?php $avant = ''; foreach ($lignes as $i => $l):
                    $cle = $l['app'] . '|' . $l['atelier'];
                    $r = $cat[$cle] ?? null;
                    $d = (string) $l['ouvre_le'];
                    $desordre = $d !== '' && $avant !== '' && $d < $avant;
                    if ($d !== '') { $avant = $d; }
                    $etat = $d === '' ? ['ferme', 'Pas montré (sans date)'] : ($d <= $aujourdhui ? ['ouvert', 'Ouvert'] : ['bientot', 'Prochainement']);
                    $nb = $r ? count($r['activites']) : 0; ?>
                    <tr>
                        <td class="pg-n"><span class="pg-num"><?= $i + 1 ?></span></td>
                        <td>
                            <input type="hidden" name="cle[]" value="<?= h($cle) ?>">
                            <span class="pg-icone"><?= h($r['icone_app'] ?? '🧩') ?></span>
                            <strong><?= h($r['titre'] ?? $l['atelier']) ?></strong>
                            <span class="discret"><?= h($r['nom_app'] ?? $l['app']) ?><?= $nb ? ' · ' . $nb . ' étape' . ($nb > 1 ? 's' : '') : '' ?><?= $r && $r['niveau'] ? ' · ' . h($r['niveau']) : '' ?><?= !$r ? ' · plus au catalogue' : ($r['etat'] !== 'actif' ? ' · retiré par l’application' : '') ?></span>
                        </td>
                        <td class="pg-s"><input type="number" name="seances[]" min="1" max="30" value="<?= (int) $l['seances'] ?>" aria-label="Nombre de séances"></td>
                        <td class="pg-d"><input type="date" name="ouvre_le[]" value="<?= h($d) ?>" aria-label="Date d’ouverture">
                            <?php if ($desordre): ?><span class="pg-alerte" title="Cet atelier s’ouvre avant le précédent : la progression n’est plus pas à pas">⚠ avant le précédent</span><?php endif; ?></td>
                        <td><span class="pastille-dispo <?= $etat[0] ?>"><?= $etat[1] ?></span><?php if ($etat[0] === 'bientot'): ?> <span class="discret"><?= h(date_lisible($d, false)) ?></span><?php endif; ?></td>
                        <td class="pg-a">
                            <button type="button" class="petit secondaire" data-pg="haut" title="Monter" aria-label="Monter">↑</button>
                            <button type="button" class="petit secondaire" data-pg="bas" title="Descendre" aria-label="Descendre">↓</button>
                            <button type="button" class="petit secondaire" data-pg="retirer" title="Retirer de la progression" aria-label="Retirer">✕</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="pg-actions">
                <button>Enregistrer</button>
                <span class="pg-calcul">
                    <label>Premier atelier le <input type="date" name="debut" value="<?= h($debut) ?>"></label>
                    <button name="calculer" value="1" class="secondaire"<?= $edt ? '' : ' disabled title="Cette classe n’a pas d’emploi du temps : dates à saisir à la main"' ?>>Calculer les dates d’après l’emploi du temps</button>
                </span>
            </div>
            <p class="aide"><?= $edt
                ? 'Le calcul suit l’ordre du tableau : chaque atelier s’ouvre à la séance qui suit la fin du précédent (nombre de séances), en sautant vacances et cours annulés. Chaque date reste modifiable à la main.'
                : 'Cette classe n’a pas encore d’emploi du temps (page Emploi du temps) : saisissez les dates à la main, ou ajoutez ses cours pour les calculer.' ?></p>
        </form>
        <?php else: ?>
            <p>Ajoutez les ateliers de la classe ci-dessous, dans l’ordre où elle les fera. Dès le premier ajout, ses élèves ne verront plus que les ateliers de la progression.</p>
        <?php endif; ?>
    </section>

    <section class="carte">
        <h3>Ajouter des ateliers</h3>
        <form method="post" class="pg-ajout">
            <?= csrf() ?>
            <input type="hidden" name="action" value="ajouter_calendrier">
            <input type="hidden" name="classe_id" value="<?= $cid ?>">
            <?php
            $deja = array_flip(array_map(fn($l) => $l['app'] . '|' . $l['atelier'], $lignes));
            $groupes = [];
            foreach ($cat as $cle => $r) {
                if (isset($deja[$cle]) || $r['etat'] !== 'actif') {
                    continue;
                }
                $groupes[$r['nom_app'] ?: $r['app']][] = $r;
            }
            ?>
            <select name="ateliers[]" multiple size="10" aria-label="Ateliers à ajouter">
                <?php foreach ($groupes as $app => $liste): ?>
                    <optgroup label="<?= h($app) ?>">
                        <?php foreach ($liste as $r): $bon = $niveau === null || $r['niveau'] === '' || str_starts_with((string) $r['niveau'], $niveau); ?>
                            <option value="<?= h($r['app'] . '|' . $r['atelier']) ?>"<?= $bon ? '' : ' class="autre-niveau"' ?>><?= h($r['titre']) ?><?= $r['niveau'] ? ' (' . h($r['niveau']) . ')' : '' ?><?= $r['duree'] ? ' · ' . h($r['duree']) : '' ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <div><button>Ajouter à la fin</button> <span class="discret">Plusieurs à la fois : Ctrl (ou ⌘) + clic. Ils s’ajoutent dans l’ordre de la liste, datés à la suite si possible.</span></div>
        </form>
    </section>

    <?php if ($lignes): ?>
    <section class="carte">
        <h3>Copier vers d’autres classes</h3>
        <form method="post" class="pg-copie" data-confirmer="Remplacer la progression des classes cochées par celle-ci ?">
            <?= csrf() ?>
            <input type="hidden" name="action" value="copier_calendrier">
            <input type="hidden" name="classe_id" value="<?= $cid ?>">
            <div class="pg-cibles">
                <?php foreach ($classes as $c): if ((int) $c['id'] === $cid) continue; $meme = niveau_classe((string) $c['nom']) === $niveau; ?>
                    <label class="<?= $meme ? '' : 'autre-niveau' ?>"><input type="checkbox" name="cibles[]" value="<?= (int) $c['id'] ?>"> <?= h($c['nom']) ?><?= (int) $c['nb'] ? ' <span class="discret">(remplace ' . (int) $c['nb'] . ')</span>' : '' ?></label>
                <?php endforeach; ?>
            </div>
            <label><input type="checkbox" name="recalculer" value="1" checked> Recalculer les dates d’après l’emploi du temps de chaque classe (à partir du <?= h(date_lisible($lignes[0]['ouvre_le'] ?: $aujourdhui, false)) ?>)</label>
            <div><button class="secondaire">Copier</button></div>
        </form>
    </section>
    <div class="pg-vider"><?= bouton('vider_calendrier', 'Supprimer la progression de ' . $classe['nom'], ['classe_id' => $cid], 'danger',
        'Supprimer la progression ? Les élèves de ' . $classe['nom'] . ' retrouveront tous les ateliers ouverts.') ?></div>
    <?php endif; ?>
    <script>
    (function () {
      var f = document.getElementById('form-progression');
      if (!f) return;
      var corps = f.querySelector('tbody');
      var renum = function () { corps.querySelectorAll('.pg-num').forEach(function (n, i) { n.textContent = i + 1; }); };
      corps.addEventListener('click', function (e) {
        var b = e.target.closest('[data-pg]'); if (!b) return;
        var tr = b.closest('tr');
        if (b.dataset.pg === 'haut' && tr.previousElementSibling) corps.insertBefore(tr, tr.previousElementSibling);
        if (b.dataset.pg === 'bas' && tr.nextElementSibling) corps.insertBefore(tr.nextElementSibling, tr);
        if (b.dataset.pg === 'retirer') tr.remove();
        renum();
        f.classList.add('modifie');
      });
      f.addEventListener('input', function () { f.classList.add('modifie'); });
    })();
    </script>
    <?php
}
