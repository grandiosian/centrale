<?php
/**
 * Centrale élèves — espace enseignant : élèves test.
 *
 * Des classes de test (5TEST, 4TEST…) contiennent des élèves fictifs. Depuis les onglets Ateliers et Parcours,
 * l'enseignant choisit l'un d'eux et « teste à sa place » : un nouvel onglet s'ouvre, connecté comme cet élève
 * (session de 2 h, sans code), sur son portail ou directement dans un atelier. Seuls les élèves des classes de test
 * sont proposés : on ne travaille jamais par erreur dans le dossier d'un vrai élève.
 * Inclus par public/admin/index.php (utilise ses fonctions d'affichage et jeton_session_eleve).
 */
declare(strict_types=1);

/** Classes de test créées par le bouton « Créer les classes de test » : nom => nombre d'élèves. Le chiffre donne le niveau. */
const CLASSES_TEST = ['5TEST' => 3, '4TEST' => 3];

/** « TEST », « 5TEST », « 4 TEST », « 3-TEST » : classes réservées aux essais de l'enseignant. */
function est_classe_test(string $nom): bool
{
    return (bool) preg_match('/^(?:[3-6][\s_-]?)?TEST$/i', trim($nom));
}

/** Élèves actifs des classes de test, rangés par classe : [ ['id', 'etiquette', 'classe', 'classe_id', 'identifiant'], … ] */
function eleves_test(): array
{
    $lignes = db()->query('SELECT e.id, e.etiquette, e.surnom, c.id AS classe_id, c.nom, c.etablissement_id
                           FROM eleves e JOIN classes c ON c.id = e.classe_id WHERE e.actif = 1
                           ORDER BY c.nom DESC, e.etiquette')->fetchAll();
    $res = [];
    foreach ($lignes as $l) {
        if (!est_classe_test($l['nom'])) {
            continue;
        }
        $res[] = ['id' => (int) $l['id'], 'etiquette' => $l['etiquette'], 'classe' => $l['nom'], 'classe_id' => (int) $l['classe_id'],
                  'identifiant' => $l['surnom'] ? identifiant_eleve(['nom' => $l['nom'], 'etablissement_id' => $l['etablissement_id']], $l['surnom']) : ''];
    }
    return $res;
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
                flash('Choisissez un élève d’une classe de test.', 'err');
                rediriger('./?p=ateliers#tester');
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
                    rediriger('./?p=ateliers');
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
            rediriger((string) ($_POST['retour'] ?? './?p=ateliers') . '#tester');

        case 'reinitialiser_test':
            $n = 0;
            foreach ($pdo->query('SELECT id, nom FROM classes')->fetchAll() as $c) {
                if (est_classe_test($c['nom'])) {
                    effacer_travail_classe((int) $c['id']);
                    $n++;
                }
            }
            flash("Travail des élèves test effacé ($n classe" . ($n > 1 ? 's' : '') . ') : ils repartent de zéro dans les ateliers et les parcours.'
                . ' Dans Établi 3D, le projet gardé dans le navigateur reste là : Fichier → Nouveau pour repartir à vide.');
            rediriger((string) ($_POST['retour'] ?? './?p=ateliers') . '#tester');
    }
    return false;
}

/**
 * Carte « Tester à la place d'un élève » (onglets Ateliers et Parcours).
 * Le formulaire porte l'id « form-tester » : les boutons « Tester » des lignes s'y rattachent (attribut form)
 * et envoient leur cible (app:…, parcours:…). Renvoie true s'il y a des élèves test (boutons à afficher).
 */
function carte_tester(string $retour): bool
{
    $eleves = eleves_test();
    $choisi = (int) ($_SESSION['eleve_teste'] ?? 0);
    $codes = $_SESSION['codes_test'] ?? [];
    unset($_SESSION['codes_test']);
    ?>
    <section class="carte" id="tester">
        <h2>Tester à la place d’un élève</h2>
        <?php if (!$eleves): ?>
            <p>Créez des élèves fictifs pour essayer les ateliers et les parcours comme vos élèves, sans toucher à leur travail :
                classes <?= h(implode(', ', array_keys(CLASSES_TEST))) ?>, <?= (int) max(CLASSES_TEST) ?> élèves chacune.</p>
            <?= bouton('creer_classes_test', 'Créer les classes de test', ['retour' => $retour]) ?>
        <?php else: ?>
            <form method="post" target="_blank" id="form-tester" class="formulaire-ligne">
                <?= csrf() ?>
                <input type="hidden" name="action" value="tester_comme">
                <label>Élève test
                    <select name="eleve_id" required>
                        <?php $classe = null; foreach ($eleves as $e):
                            if ($e['classe'] !== $classe): ?><?= $classe !== null ? '</optgroup>' : '' ?><optgroup label="<?= h($e['classe']) ?>"><?php $classe = $e['classe']; endif; ?>
                            <option value="<?= $e['id'] ?>" <?= $e['id'] === $choisi ? 'selected' : '' ?>><?= h($e['classe'] . ' · élève ' . $e['etiquette'] . ($e['identifiant'] ? ' · ' . $e['identifiant'] : '')) ?></option>
                        <?php endforeach; ?></optgroup>
                    </select></label>
                <button name="cible" value="portail">Ouvrir son portail ↗</button>
            </form>
            <p class="aide">Un nouvel onglet s’ouvre, connecté comme cet élève (2 h, sans code) : son portail, ses ateliers, ses parcours,
                « Étape suivante » et dossier de projet, comme en classe. Les boutons <strong>Tester</strong> du tableau ouvrent directement
                l’atelier, ou le portail pour un parcours, avec l’élève choisi ici. Seuls les élèves des classes de test sont proposés.
                Si votre navigateur n’ouvre pas de nouvel onglet, la page s’ouvre ici : « Précédent » pour revenir.</p>
            <div class="ligne-actions">
                <?= bouton('reinitialiser_test', 'Remettre les élèves test à zéro', ['retour' => $retour], 'secondaire',
                    'Effacer le travail, l’avancement et les dossiers de projet de tous les élèves test ?') ?>
                <?= bouton('creer_classes_test', 'Compléter les classes de test', ['retour' => $retour], 'secondaire') ?>
            </div>
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
