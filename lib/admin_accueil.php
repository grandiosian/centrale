<?php
/**
 * Centrale élèves — accueil de l'espace enseignant :
 * la semaine de cours de tous les établissements (une couleur par établissement), les prochains cours,
 * et le récapitulatif des connexions des élèves.
 */
declare(strict_types=1);

/** Toutes les séances d'une semaine, tous établissements confondus, avec leurs heures réelles. */
function seances_semaine_tous(string $lundi): array
{
    $res = [];
    $vacances = [];
    $samedi = false;
    foreach (db()->query('SELECT * FROM etablissements ORDER BY nom')->fetchAll() as $e) {
        $samedi = $samedi || (bool) $e['samedi'];
        $creneaux = creneaux_de((int) $e['id']);
        $index = [];
        foreach ($creneaux as $i => $c) {
            $index[(int) $c['id']] = $i;
        }
        $semaine = semaine_emploi_du_temps($e, $lundi);
        foreach ($semaine['jours'] as $d => $vac) {
            if ($vac !== null) {
                $vacances[$d][(int) $e['id']] = $vac;
            }
        }
        foreach ($semaine['seances'] as $s) {
            if (!isset($index[$s['creneau_id']])) {
                continue;
            }
            $i = $index[$s['creneau_id']];
            $fin = $creneaux[min(count($creneaux) - 1, $i + max(1, $s['duree']) - 1)]['fin'];
            $res[] = $s + ['etablissement_id' => (int) $e['id'], 'etablissement' => $e['nom'], 'semaine_ab' => $semaine['semaine'],
                           'debut' => $creneaux[$i]['debut'], 'fin' => $fin, 'creneau' => $creneaux[$i]['libelle']];
        }
    }
    usort($res, fn($a, $b) => strcmp($a['date'] . $a['debut'], $b['date'] . $b['debut']));
    return ['seances' => $res, 'vacances' => $vacances, 'samedi' => $samedi];
}

function minutes(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm . ':0'));
    return $h * 60 + $m;
}

/** Libellé de groupe d'une séance (« 4C-HAUTIERS · GR1 » → « GR1 »). */
function groupe_seance(array $s): string
{
    return str_contains($s['classe'], ' · ') ? explode(' · ', $s['classe'], 2)[1] : '';
}

function page_tableau_de_bord(): void
{
    $pdo = db();
    $aujourdhui = aujourd_hui();
    $lundi = lundi_de(date_valide((string) ($_GET['semaine'] ?? '')) ? (string) $_GET['semaine'] : $aujourdhui);
    $etabs = $pdo->query('SELECT * FROM etablissements ORDER BY nom')->fetchAll();
    $sem = seances_semaine_tous($lundi);
    $maintenant = new DateTimeImmutable('now', fuseau());
    $heure = $maintenant->format('H:i');

    // Prochains cours : à partir de maintenant, sur trois semaines
    $prochains = [];
    foreach ([$lundi === lundi_de($aujourdhui) ? $sem : seances_semaine_tous(lundi_de($aujourdhui)),
              seances_semaine_tous(ajouter_jours(lundi_de($aujourdhui), 7)),
              seances_semaine_tous(ajouter_jours(lundi_de($aujourdhui), 14))] as $bloc) {
        foreach ($bloc['seances'] as $s) {
            if ($s['date'] > $aujourdhui || ($s['date'] === $aujourdhui && $s['fin'] > $heure)) {
                $prochains[$s['cle'] . $s['etablissement_id']] = $s;
            }
        }
    }
    $prochains = array_slice(array_values($prochains), 0, 8);

    entete('Accueil');
    barre('accueil');
    ?>
    <main class="large">
        <div class="titre-section">
            <h1>Accueil</h1>
            <form method="post" target="_blank" id="form-apercu" class="en-ligne">
                <?= csrf() ?>
                <input type="hidden" name="action" value="apercu_eleve">
                <button class="secondaire" title="Ouvre le portail élève avec le compte test de la classe TEST, sans taper de code">Voir comme un élève</button>
            </form>
        </div>
        <?php messages(); ?>
        <?php liste_locale(); ?>

        <?php if (!$etabs): ?>
            <section class="carte" style="margin-top:16px">
                <p>Ajoutez vos établissements (grille horaire, vacances, salles) pour voir ici votre semaine de cours.</p>
                <a class="btn" href="./?p=etablissements&nouveau=1">Ajouter un établissement</a>
            </section>
        <?php else: ?>
            <?php section_calendrier($lundi, $sem, $etabs, $aujourdhui, $heure); ?>
        <?php endif; ?>

        <div class="accueil-colonnes">
            <section class="carte" id="prochains">
                <h2>Prochains cours</h2>
                <?php if (!$prochains): ?>
                    <p class="vide">Aucun cours prévu dans les trois prochaines semaines.</p>
                <?php else: ?>
                    <ol class="prochains">
                        <?php foreach ($prochains as $s):
                            $en_cours = $s['date'] === $aujourdhui && $s['debut'] <= $heure; ?>
                            <li class="<?= $s['statut'] === 'annule' ? 'annule' : '' ?><?= $en_cours ? ' en-cours' : '' ?>" style="--etab:<?= h(couleur_etablissement($s['etablissement_id'])) ?>">
                                <div class="quand">
                                    <strong><?= h(quand_seance($s['date'], $aujourdhui)) ?></strong>
                                    <span><?= h($s['debut']) ?>–<?= h($s['fin']) ?></span>
                                </div>
                                <div class="quoi">
                                    <?= pastille_classe($s['classe_id'], groupe_seance($s)) ?>
                                    <span class="intitule"><?= h($s['intitule']) ?></span>
                                    <span class="ou"><span class="puce-etab"></span><?= h($s['etablissement']) ?><?= $s['salle'] ? ' · ' . h($s['salle']) : '' ?><?= $s['semaine_ab'] ? ' · sem. ' . h($s['semaine_ab']) : '' ?></span>
                                    <?php if ($en_cours): ?><span class="etiquette-en-cours">En cours</span><?php endif; ?>
                                    <?php if ($s['statut'] === 'annule'): ?><span class="alerte">Annulé<?= $s['note'] ? ' : ' . h($s['note']) : '' ?></span>
                                    <?php elseif ($s['note']): ?><span class="discret">📝 <?= h($s['note']) ?></span><?php endif; ?>
                                </div>
                                <div class="actions">
                                    <?php if ($s['salle_id'] && $s['statut'] !== 'annule'): ?>
                                        <a class="btn petit secondaire" href="./?p=placement&classe=<?= (int) $s['classe_id'] ?>&salle=<?= (int) $s['salle_id'] ?><?= !empty($s['groupe_id']) ? '&groupe=' . (int) $s['groupe_id'] : '' ?>">Plan</a>
                                    <?php endif; ?>
                                    <a class="btn petit secondaire" href="./?p=edt&etab=<?= (int) $s['etablissement_id'] ?>&semaine=<?= h(lundi_de($s['date'])) ?>&seance=<?= h($s['cle']) ?>#panneau">Modifier</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
            <?php section_connexions_accueil(); ?>
        </div>
    </main>
    <?php
    pied();
}

/** « Aujourd'hui », « Demain », « Jeudi 5 nov. » */
function quand_seance(string $date, string $aujourdhui): string
{
    if ($date === $aujourdhui) {
        return 'Aujourd’hui';
    }
    if ($date === ajouter_jours($aujourdhui, 1)) {
        return 'Demain';
    }
    return date_lisible($date);
}

/** Semaine de cours, tous établissements : une colonne par jour, les cours placés à leur heure réelle. */
function section_calendrier(string $lundi, array $sem, array $etabs, string $aujourdhui, string $heure): void
{
    $nb = $sem['samedi'] ? 6 : 5;
    $jours = array_map(fn($i) => ajouter_jours($lundi, $i), range(0, $nb - 1));
    // Plage horaire : du premier au dernier créneau de tous les établissements, à l'heure pleine
    $bornes = db()->query('SELECT MIN(debut), MAX(fin) FROM creneaux')->fetch(PDO::FETCH_NUM);
    $debut = (int) floor(minutes($bornes[0] ?: '08:00') / 60) * 60;
    $fin = (int) ceil(minutes($bornes[1] ?: '17:30') / 60) * 60;
    $duree = max(60, $fin - $debut);
    $px = 1.05;   // pixels par minute
    $par_jour = [];
    foreach ($sem['seances'] as $s) {
        $par_jour[$s['date']][] = $s;
    }
    $ab = [];
    foreach ($etabs as $e) {
        if ($e['ab_actif'] && ($x = semaine_ab($e, $lundi)) !== '') {
            $ab[] = h($e['nom']) . ' : semaine ' . h($x);
        }
    }
    ?>
    <section class="carte calendrier" id="calendrier">
        <div class="titre-section">
            <h2>Semaine du <?= h(date_lisible($lundi, false)) ?></h2>
            <div class="boutons">
                <a class="btn petit secondaire" href="./?semaine=<?= h(ajouter_jours($lundi, -7)) ?>#calendrier" aria-label="Semaine précédente">◀</a>
                <?php if ($lundi !== lundi_de($aujourdhui)): ?><a class="btn petit secondaire" href="./#calendrier">Cette semaine</a><?php endif; ?>
                <a class="btn petit secondaire" href="./?semaine=<?= h(ajouter_jours($lundi, 7)) ?>#calendrier" aria-label="Semaine suivante">▶</a>
                <a class="btn petit secondaire" href="./?p=edt">Modifier l’emploi du temps</a>
            </div>
        </div>
        <p class="legende-etabs">
            <?php foreach ($etabs as $e): ?>
                <span><span class="puce-etab" style="--etab:<?= h(couleur_etablissement((int) $e['id'])) ?>"></span><?= h($e['nom']) ?></span>
            <?php endforeach; ?>
            <?php if ($ab): ?><span class="discret"><?= implode(' · ', $ab) ?></span><?php endif; ?>
        </p>
        <div class="cal" style="--jours:<?= $nb ?>;--hauteur:<?= (int) round($duree * $px) ?>px">
            <div class="cal-heures">
                <?php for ($m = $debut; $m <= $fin; $m += 60): ?>
                    <span style="top:<?= round(($m - $debut) * $px) ?>px"><?= sprintf('%02dh', intdiv($m, 60)) ?></span>
                <?php endfor; ?>
            </div>
            <?php foreach ($jours as $d):
                $vac = $sem['vacances'][$d] ?? [];
                $tout_vac = $vac && count($vac) === count($etabs); ?>
                <div class="cal-jour<?= $d === $aujourdhui ? ' aujourdhui' : '' ?><?= $tout_vac ? ' vacances' : '' ?>">
                    <div class="cal-tete"><?= h(JOURS_COURTS[jour_semaine($d)]) ?> <strong><?= (int) substr($d, 8, 2) ?></strong></div>
                    <div class="cal-corps">
                        <?php for ($m = $debut; $m <= $fin; $m += 60): ?><i class="ligne" style="top:<?= round(($m - $debut) * $px) ?>px"></i><?php endfor; ?>
                        <?php if ($vac): ?>
                            <div class="cal-vacances"><?= h(implode(' · ', array_unique($vac))) ?><?= !$tout_vac ? '<br><small>' . h(implode(', ', array_map(fn($id) => etablissement_par_id($id)['nom'] ?? '', array_keys($vac)))) . '</small>' : '' ?></div>
                        <?php endif; ?>
                        <?php if ($d === $aujourdhui && minutes($heure) >= $debut && minutes($heure) <= $fin): ?>
                            <i class="maintenant" style="top:<?= round((minutes($heure) - $debut) * $px) ?>px"></i>
                        <?php endif; ?>
                        <?php
                        // Cours qui se chevauchent (deux établissements, ou une classe et un groupe) : côte à côte
                        $liste = $par_jour[$d] ?? [];
                        $voies = [];
                        foreach ($liste as $k => $s) {
                            $v = 0;
                            while (isset($voies[$v]) && $voies[$v] > minutes($s['debut'])) {
                                $v++;
                            }
                            $voies[$v] = minutes($s['fin']);
                            $liste[$k]['voie'] = $v;
                        }
                        $nb_voies = max(1, count($voies));
                        foreach ($liste as $s):
                            $haut = (minutes($s['debut']) - $debut) * $px;
                            $h = max(22, (minutes($s['fin']) - minutes($s['debut'])) * $px - 2); ?>
                            <a class="cal-cours st-<?= h($s['statut']) ?>" style="top:<?= round($haut) ?>px;height:<?= round($h) ?>px;--etab:<?= h(couleur_etablissement($s['etablissement_id'])) ?>;left:calc(<?= $s['voie'] ?> * 100% / <?= $nb_voies ?>);width:calc(100% / <?= $nb_voies ?> - 3px)"
                               href="./?p=edt&etab=<?= (int) $s['etablissement_id'] ?>&semaine=<?= h($lundi) ?>&seance=<?= h($s['cle']) ?>#panneau"
                               title="<?= h($s['classe'] . ' · ' . $s['debut'] . '–' . $s['fin'] . ($s['salle'] ? ' · ' . $s['salle'] : '') . ' · ' . $s['etablissement'] . ($s['statut'] === 'annule' ? ' · ANNULÉ' : '') . ($s['note'] ? ' — ' . $s['note'] : '')) ?>">
                                <?= pastille_classe($s['classe_id'], groupe_seance($s)) ?>
                                <span class="heure"><?= h($s['debut']) ?></span>
                                <?php if ($s['salle']): ?><span class="salle"><?= h($s['salle']) ?></span><?php endif; ?>
                                <?php if ($s['statut'] === 'annule'): ?><em>Annulé</em><?php elseif ($s['statut'] !== 'normal'): ?><em><?= $s['statut'] === 'ajoute' ? 'Ajouté' : 'Modifié' ?></em><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

/** Récapitulatif des connexions : maintenant, aujourd'hui, sur 7 jours, par classe, par atelier, les dernières. */
function section_connexions_accueil(): void
{
    $pdo = db();
    $minuit = (new DateTimeImmutable('today', fuseau()))->getTimestamp();
    $q = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $st = $pdo->prepare($sql); $st->execute($p); return $st; })();
    $heure = (int) $q('SELECT COUNT(DISTINCT eleve_id) FROM connexions WHERE ts >= ?', [time() - 3600])->fetchColumn();
    $jour = (int) $q('SELECT COUNT(DISTINCT eleve_id) FROM connexions WHERE ts >= ?', [$minuit])->fetchColumn();
    $sessions = (int) $q('SELECT COUNT(*) FROM sessions WHERE expire > ?', [time()])->fetchColumn();
    $semaine = (int) $q('SELECT COUNT(DISTINCT eleve_id) FROM connexions WHERE ts >= ?', [$minuit - 6 * 86400])->fetchColumn();
    $total = (int) $pdo->query('SELECT COUNT(*) FROM eleves WHERE actif = 1')->fetchColumn();
    // 7 derniers jours : élèves différents par jour
    $jours = [];
    for ($i = 6; $i >= 0; $i--) {
        $a = $minuit - $i * 86400;
        $jours[] = ['date' => date('Y-m-d', $a), 'n' => (int) $q('SELECT COUNT(DISTINCT eleve_id) FROM connexions WHERE ts >= ? AND ts < ?', [$a, $a + 86400])->fetchColumn()];
    }
    $max = max(1, ...array_column($jours, 'n'));
    $classes = $q('SELECT c.id, c.nom, c.etablissement_id, COUNT(DISTINCT x.eleve_id) AS n,
                       (SELECT COUNT(*) FROM eleves e2 WHERE e2.classe_id = c.id AND e2.actif = 1) AS total
                   FROM connexions x JOIN eleves e ON e.id = x.eleve_id JOIN classes c ON c.id = e.classe_id
                   WHERE x.ts >= ? GROUP BY c.id ORDER BY n DESC, c.nom', [$minuit - 6 * 86400])->fetchAll();
    $apps = $q('SELECT x.app, COUNT(DISTINCT x.eleve_id) AS n, COUNT(*) AS ouvertures, a.nom, a.icone
                FROM connexions x LEFT JOIN ateliers a ON a.app = x.app WHERE x.ts >= ? GROUP BY x.app ORDER BY n DESC', [$minuit - 6 * 86400])->fetchAll();
    $dernieres = $pdo->query('SELECT x.ts, x.app, x.moyen, x.origine, e.etiquette, e.surnom, c.id AS classe_id, c.nom AS classe
                              FROM connexions x JOIN eleves e ON e.id = x.eleve_id JOIN classes c ON c.id = e.classe_id
                              ORDER BY x.ts DESC LIMIT 10')->fetchAll();
    ?>
    <section class="carte" id="connexions">
        <h2>Connexions des élèves</h2>
        <div class="chiffres">
            <div><strong><?= $heure ?></strong><span>depuis une heure</span></div>
            <div><strong><?= $jour ?></strong><span>aujourd’hui</span></div>
            <div><strong><?= $semaine ?><small> / <?= $total ?></small></strong><span>sur 7 jours</span></div>
            <div><strong><?= $sessions ?></strong><span>session<?= $sessions > 1 ? 's' : '' ?> ouverte<?= $sessions > 1 ? 's' : '' ?></span></div>
        </div>
        <div class="histo" role="img" aria-label="Élèves connectés chaque jour sur 7 jours">
            <?php foreach ($jours as $j): ?>
                <div class="histo-barre" title="<?= h(date_lisible($j['date'])) ?> : <?= $j['n'] ?> élève<?= $j['n'] > 1 ? 's' : '' ?>">
                    <span class="valeur"><?= $j['n'] ?: '' ?></span>
                    <i style="height:<?= round($j['n'] / $max * 100) ?>%"></i>
                    <span class="jour"><?= h(JOURS_COURTS[jour_semaine($j['date'])] ?? 'Dim') ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($classes): ?>
            <h3>Par classe (7 jours)</h3>
            <ul class="par-classe">
                <?php foreach ($classes as $c): $pct = $c['total'] ? round($c['n'] / $c['total'] * 100) : 0; ?>
                    <li><a href="./?p=classe&id=<?= (int) $c['id'] ?>"><?= pastille_classe($c) ?></a>
                        <span class="jauge-classe"><i style="width:<?= $pct ?>%"></i></span>
                        <span class="discret"><?= (int) $c['n'] ?> / <?= (int) $c['total'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($apps): ?>
            <h3>Par atelier (7 jours)</h3>
            <p class="par-atelier">
                <?php foreach ($apps as $a): ?>
                    <span><?= h($a['icone'] ?: '🧩') ?> <?= h($a['nom'] ?: $a['app']) ?> <strong><?= (int) $a['n'] ?></strong> élève<?= $a['n'] > 1 ? 's' : '' ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <h3>Dernières connexions</h3>
        <?php if (!$dernieres): ?>
            <p class="vide">Aucune connexion pour l’instant.</p>
        <?php else: ?>
            <table class="dernieres">
                <tbody>
                <?php foreach ($dernieres as $x): ?>
                    <tr>
                        <td class="discret"><?= h(quand((int) $x['ts'])) ?></td>
                        <td><?= pastille_classe((int) $x['classe_id']) ?> <span class="numero"><?= h($x['etiquette']) ?></span> <?= nom_eleve($x['classe'], $x['etiquette']) ?></td>
                        <td><?= h($x['app'] === 'portail' ? 'Portail' : $x['app']) ?> <span class="discret"><?= h($x['moyen'] === 'code' ? 'avec son code' : 'depuis le portail') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
}
