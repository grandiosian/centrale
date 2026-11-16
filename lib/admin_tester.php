<?php
/**
 * Centrale élèves — espace enseignant : élèves test.
 *
 * Des classes de test (5TEST, 4TEST…) contiennent des élèves fictifs. Depuis les onglets Ateliers et Parcours,
 * l'enseignant choisit un élève et « teste à sa place » : un nouvel onglet s'ouvre, connecté comme cet élève
 * (session de 2 h, sans code, marquée « essai »), sur son portail ou directement dans un atelier. Tous les élèves
 * sont proposés : d'abord ceux des classes de test et simulées, puis ceux des vraies classes (le travail fait à leur
 * place s'enregistre dans leur dossier, la liste le signale). Dans une session d'essai, les applications proposent
 * un interrupteur « Élève | Enseignant » dans la barre du haut (TechnoStudio : Centrale.session().essai).
 * Inclus par public/admin/index.php (utilise ses fonctions d'affichage et jeton_session_eleve).
 */
declare(strict_types=1);

/** Classes de test créées par le bouton « Créer les classes de test » : nom => nombre d'élèves. Le chiffre donne le niveau. */
const CLASSES_TEST = ['5TEST' => 3, '4TEST' => 3];

/* est_classe_test() : dans lib/commun.php (servi aussi à api/essai.php). */

/**
 * Élèves actifs que l'enseignant peut tester, rangés par classe : classes de test et simulées d'abord, puis les vraies
 * classes. [ ['id', 'etiquette', 'classe', 'classe_id', 'identifiant', 'test' => bool], … ]
 */
function eleves_test(): array
{
    $lignes = db()->query('SELECT e.id, e.etiquette, e.surnom, c.id AS classe_id, c.nom, c.etablissement_id
                           FROM eleves e JOIN classes c ON c.id = e.classe_id WHERE e.actif = 1
                           ORDER BY c.nom DESC, e.etiquette')->fetchAll();
    $tests = $vrais = [];
    foreach ($lignes as $l) {
        $test = est_classe_test($l['nom']);
        $e = ['id' => (int) $l['id'], 'etiquette' => $l['etiquette'], 'classe' => $l['nom'], 'classe_id' => (int) $l['classe_id'], 'test' => $test,
              'identifiant' => $l['surnom'] ? identifiant_eleve(['nom' => $l['nom'], 'etablissement_id' => $l['etablissement_id']], $l['surnom']) : ''];
        if ($test) {
            $tests[] = $e;
        } else {
            $vrais[] = $e;
        }
    }
    return array_merge($tests, $vrais);
}

function action_tester(string $action): bool
{
    $pdo = db();
    switch ($action) {
        case 'tester_comme':
            // Formulaire ouvert dans un nouvel onglet (target="_blank") : on y redirige vers le portail ou l'atelier.
            $eid = (int) ($_POST['eleve_id'] ?? 0);
            $eleve = null;
            foreach (eleves_test() as $e) {
                if ($e['id'] === $eid) {
                    $eleve = $e;
                }
            }
            if (!$eleve) {
                flash('Choisissez un élève dans la liste.', 'err');
                rediriger('./?p=applications#tester');
            }
            $_SESSION['eleve_teste'] = $eid;   // gardé pour les prochains essais
            $cible = (string) ($_POST['cible'] ?? 'portail');
            $retour = url_base() . '/';

            if (str_starts_with($cible, 'app:')) {
                $st = $pdo->prepare('SELECT * FROM ateliers WHERE app = ?');
                $st->execute([normaliser_app(substr($cible, 4))]);
                $a = $st->fetch();
                if (!$a) {
                    flash('Atelier introuvable.', 'err');
                    rediriger('./?p=applications');
                }
                $h = 'centrale=' . rawurlencode(jeton_session_eleve($eid)) . '&retour=' . rawurlencode($retour);
                rediriger(preg_replace('/#.*$/', '', meilleure_adresse($a)) . '#' . $h);
            }
            if (str_starts_with($cible, 'parcours:')) {
                $p = parcours_par_id((int) substr($cible, 9));
                if (!$p) {
                    flash('Parcours introuvable.', 'err');
                    rediriger('./?p=parcours');
                }
                if (!classe_concernee($p['classes'], $eleve['classe'])) {
                    flash("« {$p['nom']} » n’est pas proposé à la classe {$eleve['classe']} (classes : " . ($p['classes'] ?: 'toutes')
                        . '). Choisissez un élève d’une classe concernée, ou ajoutez sa classe au parcours.', 'err');
                    rediriger('./?p=parcours#tester');
                }
            }
            rediriger('../#centrale=' . rawurlencode(jeton_session_eleve($eid)));

        case 'creer_classes_test':
            $codes = [];
            foreach (CLASSES_TEST as $nom => $nombre) {
                $st = $pdo->prepare('SELECT * FROM classes WHERE nom = ? COLLATE NOCASE');
                $st->execute([$nom]);
                $classe = $st->fetch();
                if (!$classe) {
                    $pdo->prepare('INSERT INTO classes (nom, cree_le) VALUES (?, ?)')->execute([$nom, time()]);
                    $classe = classe_par_id((int) $pdo->lastInsertId());
                    oublier_renommage($nom);
                }
                $st = $pdo->prepare('SELECT COUNT(*) FROM eleves WHERE classe_id = ? AND actif = 1');
                $st->execute([$classe['id']]);
                $manque = $nombre - (int) $st->fetchColumn();
                $numero = prochain_numero((int) $classe['id']);
                for ($i = 0; $i < $manque; $i++) {
                    $code = generer_code(prefixe_codes($classe));
                    $etiquette = sprintf('%02d', $numero + $i);
                    $pdo->prepare('INSERT INTO eleves (classe_id, etiquette, code_hash, cree_le) VALUES (?, ?, ?, ?)')
                        ->execute([$classe['id'], $etiquette, hash_code($code), time()]);
                    attribuer_surnom((int) $pdo->lastInsertId(), (int) $classe['id']);
                    $codes[] = ['classe' => $classe['nom'], 'numero' => $etiquette, 'code' => $code];
                }
            }
            $_SESSION['codes_test'] = $codes;
            flash($codes ? count($codes) . ' élèves test créés (' . implode(', ', array_keys(CLASSES_TEST)) . ').' : 'Les classes de test existent déjà.');
            rediriger((string) ($_POST['retour'] ?? './?p=applications') . '#tester');

        case 'reinitialiser_test':
            $n = 0;
            foreach ($pdo->query('SELECT id, nom FROM classes')->fetchAll() as $c) {
                if (est_classe_test($c['nom']) && !est_classe_simulee($c['nom'])) {   // les classes simulées ont leurs boutons
                    effacer_travail_classe((int) $c['id']);
                    $n++;
                }
            }
            flash("Travail des élèves test effacé ($n classe" . ($n > 1 ? 's' : '') . ') : ils repartent de zéro dans les ateliers et les parcours.'
                . ' Dans Établi 3D, le projet gardé dans le navigateur reste là : Fichier → Nouveau pour repartir à vide.');
            rediriger((string) ($_POST['retour'] ?? './?p=applications') . '#tester');

        case 'simuler_classes':
            $bilan = [];
            foreach (CLASSES_SIMULEES as $nom => $niveau) {
                [$n, $lignes, $prog] = simuler_classe($nom, $niveau);
                $bilan[] = "$nom : $n élèves, $lignes activités commencées sur un programme de $prog";
            }
            flash('Classes simulées prêtes (8 semaines de travail, jusqu’à avant-hier). ' . implode(' · ', $bilan) . '.');
            rediriger((string) ($_POST['retour'] ?? './?p=applications') . '#simulees');

        case 'supprimer_simulees':
            $n = 0;
            foreach (db()->query('SELECT id, nom FROM classes')->fetchAll() as $c) {
                if (est_classe_simulee($c['nom'])) {
                    supprimer_classe_simulee((int) $c['id']);
                    $n++;
                }
            }
            foreach (db()->query('SELECT id, nom FROM etablissements')->fetchAll() as $e) {   // avec sa grille, sa salle et ses cours
                if (est_etablissement_simule($e['nom'])) {
                    db()->prepare('DELETE FROM etablissements WHERE id = ?')->execute([$e['id']]);
                }
            }
            flash($n ? "Classes simulées supprimées ($n), avec l’établissement " . ETABLISSEMENT_SIMULE . '.' : 'Aucune classe simulée.');
            rediriger((string) ($_POST['retour'] ?? './?p=applications') . '#simulees');
    }
    return false;
}

/** Carte « Classes simulées » : des élèves fictifs aux profils variés, pour voir le suivi comme en situation réelle. */
function carte_simulees(string $retour): void
{
    completer_classes_simulees();
    $classes = array_values(array_filter(db()->query('SELECT c.id, c.nom, (SELECT COUNT(*) FROM eleves e WHERE e.classe_id = c.id) AS n
                                                      FROM classes c ORDER BY c.nom DESC')->fetchAll(), fn($c) => est_classe_simulee($c['nom'])));
    $app = programme_simule(5)[0]['app'] ?? '';
    $vue = $app !== '' ? '&vue=app:' . rawurlencode($app) : '';
    ?>
    <section class="carte" id="simulees">
        <h2>Classes simulées</h2>
        <p>Pour voir le suivi comme avec une vraie classe : <?= h(implode(', ', array_keys(CLASSES_SIMULEES))) ?>,
            <?= array_sum(array_column(PROFILS_SIMULES, 'n')) ?> élèves fictifs chacune, qui ont travaillé 8 semaines sur les ateliers
            de leur niveau (une séance par semaine, en GR1 / GR2) :
            <?php $l = []; foreach (PROFILS_SIMULES as $p) { $l[] = $p['n'] . ' ' . $p['pluriel']; } echo h(implode(', ', $l)); ?>.
            Avancement, mesures (temps, essais, premier coup, délai de réponse), constats de compétences et connexions sont rangés
            comme le vrai travail ; le profil s’affiche à la place du nom. Ces classes ne comptent pas dans les connexions du tableau de bord.</p>
        <?php if ($classes): ?>
            <p class="ligne-actions">
                <?php foreach ($classes as $c): ?>
                    <a class="btn petit secondaire" href="./?p=suivi&classe=<?= (int) $c['id'] ?><?= $vue ?>">Suivi <?= h($c['nom']) ?> (<?= (int) $c['n'] ?>)</a>
                    <a class="btn petit secondaire" href="./?p=classe&id=<?= (int) $c['id'] ?>">Classe <?= h($c['nom']) ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <div class="ligne-actions">
            <?= bouton('simuler_classes', $classes ? 'Régénérer les classes simulées' : 'Créer les classes simulées', ['retour' => $retour], $classes ? 'secondaire' : '',
                $classes ? 'Effacer les classes simulées et les recréer (le travail fait en testant à la place de leurs élèves sera perdu) ?' : '') ?>
            <?php if ($classes): ?>
                <?= bouton('supprimer_simulees', 'Supprimer les classes simulées', ['retour' => $retour], 'secondaire',
                    'Supprimer les classes simulées et tout leur travail fictif ?') ?>
            <?php endif; ?>
        </div>
    </section>
    <?php
}

/**
 * Carte « Tester à la place d'un élève » (onglets Ateliers et Parcours).
 * Le formulaire porte l'id « form-tester » : les boutons « Tester » des lignes s'y rattachent (attribut form)
 * et envoient leur cible (app:…, parcours:…). Renvoie true s'il y a des élèves test (boutons à afficher).
 */
function carte_tester(string $retour): bool
{
    $eleves = eleves_test();
    $avec_tests = (bool) array_filter($eleves, fn($e) => $e['test']);
    $choisi = (int) ($_SESSION['eleve_teste'] ?? 0);
    $codes = $_SESSION['codes_test'] ?? [];
    unset($_SESSION['codes_test']);
    ?>
    <section class="carte" id="tester">
        <h2>Tester à la place d’un élève</h2>
        <?php if (!$avec_tests): ?>
            <p>Créez des élèves fictifs pour essayer les ateliers et les parcours comme vos élèves, sans toucher à leur travail :
                classes <?= h(implode(', ', array_keys(CLASSES_TEST))) ?>, <?= (int) max(CLASSES_TEST) ?> élèves chacune.</p>
            <?= bouton('creer_classes_test', 'Créer les classes de test', ['retour' => $retour]) ?>
        <?php endif; ?>
        <?php if ($eleves): ?>
            <form method="post" target="_blank" id="form-tester" class="formulaire-ligne">
                <?= csrf() ?>
                <input type="hidden" name="action" value="tester_comme">
                <label>Élève
                    <select name="eleve_id" required>
                        <?php $classe = null; foreach ($eleves as $e):
                            if ($e['classe'] !== $classe): ?><?= $classe !== null ? '</optgroup>' : '' ?><optgroup label="<?= h($e['classe'] . ($e['test'] ? ' · test' : ' · vrais élèves : leur travail est modifié')) ?>"><?php $classe = $e['classe']; endif; ?>
                            <option value="<?= $e['id'] ?>" <?= $e['id'] === $choisi ? 'selected' : '' ?>><?= h($e['classe'] . ' · élève ' . $e['etiquette'] . ($e['identifiant'] ? ' · ' . $e['identifiant'] : '')) ?></option>
                        <?php endforeach; ?></optgroup>
                    </select></label>
                <button name="cible" value="portail">Ouvrir son portail ↗</button>
            </form>
            <p class="aide">Un nouvel onglet s’ouvre, connecté comme cet élève (2 h, sans code) : son portail, ses ateliers, ses parcours,
                « Étape suivante » et dossier de projet, comme en classe. Les boutons <strong>Tester</strong> du tableau ouvrent directement
                l’atelier, ou le portail pour un parcours, avec l’élève choisi ici. Dans l’application, l’interrupteur
                <strong>Élève | Enseignant</strong> de la barre du haut passe de la vue de l’élève (règles de l’atelier appliquées)
                à la vue enseignant (tous les modes). Les classes de test viennent en premier ; avec un vrai élève, ce que vous faites
                s’enregistre dans son dossier (ces essais ne comptent pas dans ses connexions).
                Si votre navigateur n’ouvre pas de nouvel onglet, la page s’ouvre ici : « Précédent » pour revenir.</p>
            <?php if ($avec_tests): ?>
            <div class="ligne-actions">
                <?= bouton('reinitialiser_test', 'Remettre les élèves test à zéro', ['retour' => $retour], 'secondaire',
                    'Effacer le travail, l’avancement et les dossiers de projet de tous les élèves test ?') ?>
                <?= bouton('creer_classes_test', 'Compléter les classes de test', ['retour' => $retour], 'secondaire') ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($codes): ?>
            <div class="message ok" style="margin-top:12px">Codes des élèves test (affichés une seule fois, pour essayer aussi la connexion par code) :
                <ul><?php foreach ($codes as $c): ?><li><?= h($c['classe'] . ' · élève ' . $c['numero']) ?> : <code><?= h($c['code']) ?></code></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
    </section>
    <?php
    return (bool) $eleves;
}

/** Bouton « Tester » d'une ligne du tableau, rattaché au formulaire de la carte. */
function bouton_tester(string $cible, string $titre): string
{
    return '<button class="petit" form="form-tester" name="cible" value="' . h($cible) . '" title="' . h($titre) . '">Tester ↗</button>';
}

/* ---------- « Ouvrir » un atelier (pages Ateliers, Détail, Inspecter) : en session d'essai, comme « Tester » ---------- */

/**
 * Élève de l'essai pour « Ouvrir » : toujours un élève des classes de test (jamais un vrai élève, dont le travail serait modifié) :
 * celui choisi en dernier dans « Tester à la place d'un élève » s'il est de test, sinon le premier ; null s'il n'y en a pas.
 */
function eleve_essai_choisi(): ?array
{
    $eleves = eleves_test();
    $choisi = (int) ($_SESSION['eleve_teste'] ?? 0);
    foreach ($eleves as $e) {
        if ($e['id'] === $choisi && $e['test']) {
            return $e;
        }
    }
    foreach ($eleves as $e) {
        if ($e['test']) {
            return $e;
        }
    }
    return null;
}

/** Lien « Ouvrir » d'un atelier : passe par ./?p=essayer (session d'essai), avec le jeton de sécurité de la page. */
function lien_essayer_atelier(string $app, string $atelier): string
{
    return './?' . http_build_query(['p' => 'essayer', 'app' => $app, 'atelier' => $atelier, 't' => $_SESSION['csrf'] ?? '']);
}

/** Libellé de l'infobulle du lien « Ouvrir ». */
function titre_essayer_atelier(): string
{
    $e = eleve_essai_choisi();
    return $e ? 'Ouvrir l’atelier comme l’élève ' . $e['classe'] . ' · ' . $e['etiquette'] . ' (session d’essai de 2 h : interrupteur Élève | Enseignant en haut ; élève test choisi dans Applications → Tester)'
              : 'Ouvrir l’atelier dans l’application, en visiteur (créez des élèves test dans Applications → Tester pour l’ouvrir comme un élève)';
}

/**
 * ./?p=essayer&app=…&atelier=…&t=… : ouvre l'atelier dans son application (copie de la Centrale d'abord), connecté comme
 * l'élève de l'essai (session « essai » : interrupteur Élève | Enseignant), comme une carte d'atelier du portail
 * (activite, centrale_atelier : « ← » et « Suivant » de la Centrale). Sans élève test : l'adresse sans connexion.
 */
function page_essayer_atelier(): never
{
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_GET['t'] ?? ''))) {
        http_response_code(400);
        exit('Lien périmé : rechargez la page des ateliers et recommencez.');
    }
    $app = (string) ($_GET['app'] ?? '');
    $atelier = (string) ($_GET['atelier'] ?? '');
    $r = null;
    foreach (catalogue_ateliers($app) as $x) {
        if ($x['atelier'] === $atelier) {
            $r = $x;
        }
    }
    $adresse = $r ? adresse_ouvrir_atelier($r) : '';
    if ($adresse === '') {
        flash('Atelier introuvable, ou application sans adresse.', 'err');
        rediriger('./?p=ateliers');
    }
    $e = eleve_essai_choisi();
    if (!$e) {
        rediriger($adresse);   // pas d'élève test : en visiteur, comme avant
    }
    $morceaux = ['centrale=' . rawurlencode(jeton_session_eleve($e['id'])), 'retour=' . rawurlencode(url_base() . '/'),
                 'centrale_atelier=' . rawurlencode($atelier)];
    [$base, $hash] = array_pad(explode('#', $adresse, 2), 2, '');
    rediriger($base . '#' . implode('&', array_merge($morceaux, $hash !== '' ? [$hash] : [])));
}
