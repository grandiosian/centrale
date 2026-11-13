<?php
/**
 * Centrale élèves — classes simulées (5SIM, 4SIM).
 *
 * Pour voir la Centrale « comme en vrai » sans attendre une année de cours : des élèves fictifs avec des profils
 * différents (rapides, réguliers, en difficulté, retardataires, absentéistes, observateurs, décrocheur, jamais connecté)
 * ont travaillé sur les VRAIS ateliers déclarés par les applications, pendant les 8 dernières semaines (une séance
 * par semaine, en demi-groupes GR1 / GR2). Tout passe par les mêmes tables que le travail réel :
 *   progression (avec les mesures : temps actif, essais, premier coup, délai de réponse, détail par question),
 *   constats de compétences envoyés « par l'application », connexions (portail + atelier), dernière connexion.
 * Le profil est rangé dans le surnom de l'élève (« Retardataire1 ») et affiché à la place du nom dans l'espace
 * enseignant. Ces classes sont des classes de test (est_classe_test) ; elles sont écartées des chiffres de
 * connexion du tableau de bord et de la bibliothèque, pour ne pas fausser le suivi des vraies classes.
 * Inclus par public/admin/index.php.
 */
declare(strict_types=1);

/** Classes simulées : nom => niveau (chiffre). */
const CLASSES_SIMULEES = ['5SIM' => 5, '4SIM' => 4];

/** Nombre de séances simulées (une par semaine) et part du programme que la classe a déjà vue. */
const SIM_SEANCES = 8;
const SIM_AVANCEMENT = 0.65;

/**
 * Profils : effectif dans la classe, libellé, et comportement.
 *   presence : probabilité d'être là à une séance · vitesse : activités faites par séance (1 = rythme de la classe)
 *   ok1 : réussite du premier coup (question moyenne) · delai : secondes avant la première réponse (médiane)
 *   revele : probabilité de demander la correction après un échec · retard : minutes perdues en début de séance
 *   inacheve : probabilité de passer à la suite sans finir · maison : probabilité de travailler entre deux séances
 */
const PROFILS_SIMULES = [
    'Rapide'       => ['n' => 3, 'libelle' => 'Rapide', 'pluriel' => 'rapides',          'presence' => .97, 'vitesse' => 1.45, 'ok1' => .86, 'delai' => 22,  'revele' => .02, 'retard' => 0,  'inacheve' => 0,   'maison' => .35],
    'Regulier'     => ['n' => 9, 'libelle' => 'Régulier', 'pluriel' => 'réguliers',        'presence' => .93, 'vitesse' => 1.0,  'ok1' => .64, 'delai' => 40,  'revele' => .06, 'retard' => 2,  'inacheve' => .05, 'maison' => .08],
    'Difficulte'   => ['n' => 4, 'libelle' => 'En difficulté', 'pluriel' => 'en difficulté',   'presence' => .9,  'vitesse' => .65, 'ok1' => .32, 'delai' => 85,  'revele' => .3,  'retard' => 3,  'inacheve' => .15, 'maison' => 0],
    'Retardataire' => ['n' => 2, 'libelle' => 'Retardataire', 'pluriel' => 'retardataires',    'presence' => .9,  'vitesse' => .9,  'ok1' => .58, 'delai' => 50,  'revele' => .08, 'retard' => 20, 'inacheve' => .1,  'maison' => 0],
    'Absenteiste'  => ['n' => 2, 'libelle' => 'Absentéiste', 'pluriel' => 'absentéistes',     'presence' => .38, 'vitesse' => .9,  'ok1' => .5,  'delai' => 55,  'revele' => .12, 'retard' => 5,  'inacheve' => .2,  'maison' => 0],
    'Observateur'  => ['n' => 2, 'libelle' => 'Observateur', 'pluriel' => 'observateurs',     'presence' => .95, 'vitesse' => .85, 'ok1' => .45, 'delai' => 140, 'revele' => .7,  'retard' => 2,  'inacheve' => .6,  'maison' => 0],
    'Decrocheur'   => ['n' => 1, 'libelle' => 'Décrocheur', 'pluriel' => 'décrocheur',      'presence' => .95, 'vitesse' => 1.0, 'ok1' => .6,  'delai' => 45,  'revele' => .1,  'retard' => 3,  'inacheve' => .1,  'maison' => 0],
    'Inactif'      => ['n' => 1, 'libelle' => 'Jamais connecté', 'pluriel' => 'jamais connecté', 'presence' => 0,   'vitesse' => 0,   'ok1' => 0,   'delai' => 0,   'revele' => 0,   'retard' => 0,  'inacheve' => 0,   'maison' => 0],
];

function est_classe_simulee(string $nom): bool
{
    return (bool) preg_match('/^(?:[3-6][\s_-]?)?SIM$/i', trim($nom));
}

/** Identifiants des élèves des classes simulées (pour les écarter des chiffres globaux). */
function ids_eleves_simules(): array
{
    static $ids = null;
    if ($ids === null) {
        $ids = [];
        foreach (db()->query('SELECT e.id, c.nom FROM eleves e JOIN classes c ON c.id = e.classe_id')->fetchAll() as $r) {
            if (est_classe_simulee($r['nom'])) {
                $ids[] = (int) $r['id'];
            }
        }
    }
    return $ids;
}

/** Fragment SQL « AND <colonne> NOT IN (…) » qui écarte les élèves simulés (vide s'il n'y en a pas). */
function sql_hors_simules(string $colonne): string
{
    $ids = ids_eleves_simules();
    return $ids ? " AND $colonne NOT IN (" . implode(',', $ids) . ')' : '';
}

/** Libellé du profil d'un élève simulé (« Retardataire 1 · simulé »), ou null. */
function profil_simule(string $classe, string $etiquette): ?string
{
    static $profils = null;
    if (!est_classe_simulee($classe)) {
        return null;
    }
    if ($profils === null) {
        $profils = [];
        foreach (db()->query('SELECT e.etiquette, e.surnom, c.nom FROM eleves e JOIN classes c ON c.id = e.classe_id')->fetchAll() as $r) {
            if (est_classe_simulee($r['nom']) && preg_match('/^([A-Za-z]+)(\d+)$/', (string) $r['surnom'], $m) && isset(PROFILS_SIMULES[$m[1]])) {
                $profils[mb_strtoupper($r['nom'])][$r['etiquette']] = PROFILS_SIMULES[$m[1]]['libelle'] . ' ' . $m[2] . ' · simulé';
            }
        }
    }
    return $profils[mb_strtoupper($classe)][$etiquette] ?? null;
}

/* ---------- Tirages (reproductibles : même classe → mêmes élèves) ---------- */

function sim_alea(): float
{
    return mt_rand() / mt_getrandmax();
}

function sim_entre(float $a, float $b): float
{
    return $a + ($b - $a) * sim_alea();
}

/** Loi log-normale autour d'une médiane (dispersion sigma). */
function sim_lognormal(float $mediane, float $sigma = .55): float
{
    $u1 = max(1e-9, sim_alea());
    $u2 = sim_alea();
    $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    return $mediane * exp($sigma * $z);
}

/** Difficulté propre à une question (0 facile → 1 difficile), la même pour tous les élèves. */
function sim_difficulte(string $app, string $activite, int $q): float
{
    $h = hexdec(substr(md5($app . '|' . $activite . '|' . $q), 0, 8)) / 0xFFFFFFFF;   // md5 : les identifiants voisins ne se ressemblent pas
    return round($h ** 1.4, 3);   // plus de questions faciles que difficiles
}

/* ---------- Programme de la classe ---------- */

/**
 * Activités travaillées par une classe de ce niveau, dans l'ordre des séances : pour chaque application, les groupes
 * d'activités de ce niveau (« 5e · … », « 5ᵉ · Débutant »), ou, si l'application ne range pas par niveau, un groupe
 * choisi d'après le niveau. Les groupes des applications sont pris à tour de rôle (rotation entre îlots).
 */
function programme_simule(int $niveau): array
{
    $ordre_apps = db()->query('SELECT app FROM ateliers ORDER BY ordre, nom')->fetchAll(PDO::FETCH_COLUMN);
    $catalogue = catalogue_activites();
    uksort($catalogue, fn($a, $b) => (array_search($a, $ordre_apps, true) === false ? 99 : array_search($a, $ordre_apps, true))
        <=> (array_search($b, $ordre_apps, true) === false ? 99 : array_search($b, $ordre_apps, true)));
    $files = [];
    foreach ($catalogue as $app => $acts) {
        if ($app === 'portail') {
            continue;
        }
        $groupes = [];
        foreach ($acts as $a) {
            $groupes[(string) $a['groupe']][] = $a;
        }
        $niveau_de = fn(string $g) => preg_match('/^\s*([3-6])\s*(?:e|ᵉ|è)/u', $g, $m) ? (int) $m[1] : null;
        $par_niveau = array_filter($groupes, fn($g) => $niveau_de((string) $g) !== null, ARRAY_FILTER_USE_KEY);
        $choisis = [];
        if ($par_niveau) {
            foreach ($par_niveau as $g => $liste) {
                if ($niveau_de((string) $g) === $niveau) {
                    $choisis[] = $liste;
                }
            }
        } elseif ($groupes) {
            $cles = array_keys($groupes);
            $choisis[] = $groupes[$cles[min(count($cles) - 1, max(0, 5 - $niveau))]];
        }
        if ($choisis) {
            $files[$app] = $choisis;
        }
    }
    // À tour de rôle : 1er groupe de chaque application, puis 2e groupe…
    $programme = [];
    for ($rang = 0; $files; $rang++) {
        foreach ($files as $app => $liste) {
            if (!isset($liste[$rang])) {
                unset($files[$app]);
                continue;
            }
            foreach ($liste[$rang] as $a) {
                $programme[] = ['app' => $app, 'activite' => $a['activite'], 'titre' => $a['titre'] ?: $a['activite'],
                                'total' => max(1, min(30, (int) ($a['total'] ?: 4))), 'competences' => (string) ($a['competences'] ?? '')];
            }
        }
    }
    return $programme;
}

/* ---------- Génération ---------- */

/** Supprime une classe simulée et tout ce qui s'y rattache. */
function supprimer_classe_simulee(int $classe_id): void
{
    $pdo = db();
    supprimer_eleves('classe_id = ?', [$classe_id]);
    foreach (['progression', 'connexions', 'evaluations', 'donnees', 'donnees_versions', 'sessions'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE eleve_id IN (SELECT id FROM eleves WHERE classe_id = ?)")->execute([$classe_id]);
    }
    $pdo->prepare('DELETE FROM classes WHERE id = ?')->execute([$classe_id]);
}

/**
 * (Re)crée la classe simulée $nom : élèves, groupes, progression avec mesures, constats, connexions.
 * Renvoie [nb élèves, nb lignes de progression, nb activités du programme].
 */
function simuler_classe(string $nom, int $niveau): array
{
    $pdo = db();
    $programme = programme_simule($niveau);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM classes WHERE nom = ? COLLATE NOCASE');
        $st->execute([$nom]);
        if ($ancien = $st->fetchColumn()) {
            supprimer_classe_simulee((int) $ancien);
        }
        $pdo->prepare('INSERT INTO classes (nom, cree_le) VALUES (?, ?)')->execute([$nom, time()]);
        $classe_id = (int) $pdo->lastInsertId();
        oublier_renommage($nom);
        $classe = classe_par_id($classe_id);
        $groupes = [];
        foreach (['GR1', 'GR2'] as $i => $g) {
            $pdo->prepare('INSERT INTO groupes (classe_id, nom, ordre) VALUES (?, ?, ?)')->execute([$classe_id, $g, $i + 1]);
            $groupes[] = (int) $pdo->lastInsertId();
        }

        mt_srand(crc32($nom));
        // Élèves : profils mélangés (l'ordre alphabétique d'une vraie liste ne suit pas les profils)
        $liste = [];
        foreach (PROFILS_SIMULES as $cle => $p) {
            for ($i = 1; $i <= $p['n']; $i++) {
                $liste[] = [$cle, $i];
            }
        }
        for ($i = count($liste) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$liste[$i], $liste[$j]] = [$liste[$j], $liste[$i]];
        }

        // Séances : une par semaine, la dernière il y a 2 jours ; GR1 à 10 h, GR2 à 11 h
        $base = (new DateTimeImmutable('today', fuseau()))->modify('-2 days')->setTime(10, 0);
        $seances = [];
        for ($s = 0; $s < SIM_SEANCES; $s++) {
            $seances[] = $base->modify('-' . (7 * (SIM_SEANCES - 1 - $s)) . ' days')->getTimestamp();
        }
        $n_prog = count($programme);
        $prevu = max(1, (int) round($n_prog * SIM_AVANCEMENT));
        $par_seance = max(1, (int) ceil($prevu / SIM_SEANCES));
        $origine = (est_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'technoversailles.alwaysdata.net');

        $ins_eleve = $pdo->prepare('INSERT INTO eleves (classe_id, etiquette, code_hash, cree_le, surnom, groupe_id) VALUES (?, ?, ?, ?, ?, ?)');
        $ins_cnx = $pdo->prepare('INSERT INTO connexions (eleve_id, app, origine, moyen, ts) VALUES (?, ?, ?, ?, ?)');
        $ins_prog = $pdo->prepare('INSERT INTO progression (eleve_id, app, activite, titre, statut, faites, total, score, score_max, details,
                debut, maj, fin, temps_actif, essais, questions, premier_coup, revelees, delai_median, mesures)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins_eval = $pdo->prepare('INSERT INTO evaluations (eleve_id, code, niveau, source, details, cree_le) VALUES (?, ?, ?, ?, ?, ?)');
        $n_lignes = 0;

        foreach ($liste as $rang => [$cle, $num]) {
            $p = PROFILS_SIMULES[$cle];
            $ins_eleve->execute([$classe_id, sprintf('%02d', $rang + 1), hash_code(generer_code(prefixe_codes($classe))), $seances[0] - 14 * 86400,
                                 $cle . $num, $groupes[$rang % 2]]);
            $eid = (int) $pdo->lastInsertId();
            $decalage = ($rang % 2) * 3600;   // GR2 une heure plus tard
            $pos = 0;
            $partiel = [];   // index d'activité => part faite (0..1)
            $quand = [];     // index => [debut, maj]
            $abandon = [];   // index => true : laissée inachevée
            $passe = [];     // index => secondes passées dessus
            $derniere = null;

            foreach ($seances as $s => $t_seance) {
                $presence = $p['presence'];
                $vitesse = $p['vitesse'];
                if ($cle === 'Decrocheur' && $s >= 4) {
                    $presence = $s >= 6 ? 0 : .3;
                    $vitesse = .35;
                }
                $travaux = [];
                if (sim_alea() < $presence) {
                    $travaux[] = [$t_seance + $decalage + (int) (60 * ($p['retard'] + sim_entre(0, 4))), $vitesse * sim_entre(.8, 1.2), 3000 - 60 * $p['retard']];
                }
                if ($s < SIM_SEANCES - 1 && sim_alea() < $p['maison']) {   // à la maison, en soirée, quelques jours après
                    $travaux[] = [$t_seance + (int) sim_entre(2, 5) * 86400 + 8 * 3600 + mt_rand(0, 7200), $vitesse * .5, 1500];
                }
                // [début, vitesse, secondes de travail] : la séance (moins le retard) et, parfois, une soirée à la maison
                foreach ($travaux as [$t, $capacite, $duree]) {
                    $capacite *= $par_seance * $duree / 3000;
                    $par_unite = $duree / max(.05, $capacite);   // secondes pour une activité entière
                    // Trop en retard sur la classe : on rejoint la séance du jour (les activités sautées restent à faire)
                    if ($pos < ($s - 2) * $par_seance) {
                        $pos = ($s - 1) * $par_seance;
                    }
                    $ins_cnx->execute([$eid, 'portail', $origine, 'portail', $t]);
                    $apps_vues = [];
                    while ($capacite > 0.05 && $pos < $n_prog) {
                        $a = $programme[$pos];
                        if (!isset($apps_vues[$a['app']])) {
                            $apps_vues[$a['app']] = true;
                            $ins_cnx->execute([$eid, $a['app'], $origine, 'portail', $t + 30]);
                        }
                        $reste = 1 - ($partiel[$pos] ?? 0);
                        $fait = min($reste, $capacite);
                        $quand[$pos][0] ??= $t + 40;
                        $t += (int) ($fait * $par_unite);
                        $passe[$pos] = ($passe[$pos] ?? 0) + $fait * $par_unite;
                        $quand[$pos][1] = $t;
                        $partiel[$pos] = ($partiel[$pos] ?? 0) + $fait;
                        $capacite -= $fait;
                        if ($partiel[$pos] >= .999) {
                            // observateur, élève en difficulté : parcourt l'activité mais en laisse une partie
                            if (sim_alea() < $p['inacheve'] * .5) {
                                $partiel[$pos] = sim_entre(.4, .85);
                                $abandon[$pos] = true;
                            }
                            $pos++;
                        } elseif ($capacite <= 0.05 && sim_alea() < $p['inacheve']) {
                            $abandon[$pos] = true;   // la prochaine fois, il passe à la suite
                            $pos++;
                        }
                    }
                    $derniere = $t;
                }
            }

            // Résultat de chaque activité touchée : questions, essais, délais
            foreach ($partiel as $i => $part) {
                $a = $programme[$i];
                $total = $a['total'];
                $vues = max(1, (int) round($part * $total));
                $detail = [];
                $correctes = 0;
                $da = sim_difficulte($a['app'], $a['activite'], 0);   // certaines activités sont plus dures que d'autres
                foreach (range(1, $vues) as $q) {
                    $d = sim_difficulte($a['app'], $a['activite'], $q);
                    $delai = (int) max(4, min(1800, round(sim_lognormal($p['delai'] * (.6 + 1.1 * $d)))));
                    $x = ['q' => 'Question ' . $q, 'essais' => 0];
                    if ($cle === 'Observateur' && sim_alea() < .35) {
                        $x['revele'] = true;   // regarde la correction sans essayer
                        $detail[] = $x;
                        continue;
                    }
                    $p1 = max(.03, min(.98, $p['ok1'] - .55 * ($d - .4) - .5 * ($da - .4)));
                    $x['essais'] = 1;
                    $x['delai'] = $delai;
                    if (sim_alea() < $p1) {
                        $x['ok1'] = true;
                        $x['okA'] = 1;
                    } else {
                        $x['ok1'] = false;
                        while ($x['essais'] < 9) {
                            if (sim_alea() < $p['revele'] * ($x['essais'] >= 2 ? 1 : .4)) {
                                $x['revele'] = true;
                                break;
                            }
                            $x['essais']++;
                            if (sim_alea() < max(.15, .55 - .35 * $d - .2 * $da + .3 * ($p['ok1'] - .5))) {
                                $x['okA'] = $x['essais'];
                                break;
                            }
                        }
                        if (!isset($x['okA']) && !isset($x['revele'])) {
                            $x['revele'] = true;
                        }
                    }
                    $correctes += isset($x['okA']) ? 1 : 0;
                    $detail[] = $x;
                }
                $temps = (int) round(($passe[$i] ?? 0) * sim_entre(.72, .95));   // temps actif : sans les moments d'inattention
                $faites = count($detail);
                $termine = $faites >= $total && empty($abandon[$i]);
                if (!$termine && $faites >= $total) {
                    $faites = $total - 1;
                    array_pop($detail);
                }
                $essayees = array_filter($detail, fn($x) => $x['essais'] > 0);
                $delais = array_values(array_map(fn($x) => $x['delai'], array_filter($detail, fn($x) => isset($x['delai']))));
                sort($delais);
                $mediane = $delais ? (int) round(count($delais) % 2 ? $delais[intdiv(count($delais), 2)]
                    : ($delais[count($delais) / 2 - 1] + $delais[count($delais) / 2]) / 2) : null;
                [$debut, $maj] = $quand[$i];
                $ins_prog->execute([$eid, $a['app'], $a['activite'], $a['titre'], $termine ? 'termine' : 'commence', $faites, $total,
                    $correctes, $total, $debut, $maj, $termine ? $maj : null,
                    $temps, array_sum(array_column($detail, 'essais')), count($essayees),
                    count(array_filter($detail, fn($x) => !empty($x['ok1']))), count(array_filter($detail, fn($x) => !empty($x['revele']))),
                    $mediane, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
                $n_lignes++;

                // Constats de compétences, comme ceux qu'envoie l'application en fin d'activité
                if ($termine && $a['competences'] !== '') {
                    $taux = $essayees ? count(array_filter($detail, fn($x) => !empty($x['ok1']))) / count($essayees) : 0;
                    $niv = $taux >= .8 ? 4 : ($taux >= .55 ? 3 : ($taux >= .3 ? 2 : 1));
                    foreach (array_slice(codes_valides($a['competences']), 0, 3) as $code) {
                        $ins_eval->execute([$eid, $code, $niv, $a['app'], json_encode(['activite' => $a['activite'], 'reussi' => $niv >= 2,
                            'essais' => (int) round(array_sum(array_column($detail, 'essais')) / max(1, count($detail))), 'simulation' => true],
                            JSON_UNESCAPED_UNICODE), $maj]);
                    }
                }
            }
            if ($derniere) {
                $pdo->prepare('UPDATE eleves SET derniere_connexion = ? WHERE id = ?')->execute([$derniere, $eid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    mt_srand();
    return [count($liste), $n_lignes, $n_prog];
}
