<?php
/**
 * Centrale élèves — page d'administration (réservée à l'enseignant).
 *
 * Classes, liens de dépôt Nuage, génération et impression des codes élèves,
 * sites autorisés à utiliser l'API.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/commun.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

session_name('centrale_admin');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => est_https(),
]);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ======================================================================
   Petits outils
   ====================================================================== */

function csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function rediriger(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function quand(?int $ts): string
{
    if (!$ts) {
        return '—';
    }
    return date('d/m/Y H:i', $ts);
}

function id_post(string $champ): int
{
    return (int) ($_POST[$champ] ?? 0);
}

/* ======================================================================
   Actions (formulaires envoyés)
   ====================================================================== */

$action = $_POST['action'] ?? null;
if ($action !== null && !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('Jeton de sécurité invalide : rechargez la page et recommencez.');
}

$hash_admin = reglage('admin_hash');

// --- Première visite : création du mot de passe administrateur
if ($hash_admin === null) {
    if ($action === 'installer') {
        $mdp = (string) ($_POST['mdp'] ?? '');
        if (mb_strlen($mdp) < 10) {
            flash('Le mot de passe doit faire au moins 10 caractères.', 'err');
        } elseif ($mdp !== ($_POST['mdp2'] ?? '')) {
            flash('Les deux mots de passe ne sont pas identiques.', 'err');
        } else {
            definir_reglage('admin_hash', password_hash($mdp, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            flash('Bienvenue ! Commencez par créer une classe.');
        }
        rediriger('./');
    }
    page_installation();
    exit;
}

// --- Connexion
if (empty($_SESSION['admin'])) {
    if ($action === 'connexion') {
        $cle = 'admin:' . ip_client();
        if (trop_d_echecs($cle, 10, 15)) {
            flash('Trop de tentatives. Patientez 15 minutes.', 'err');
        } elseif (password_verify((string) ($_POST['mdp'] ?? ''), $hash_admin)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            rediriger('./');
        } else {
            noter_echec($cle);
            flash('Mot de passe incorrect.', 'err');
        }
        rediriger('./');
    }
    page_connexion();
    exit;
}

// --- Actions de l'enseignant connecté
if ($action !== null) {
    $pdo = db();
    $classe_id = id_post('classe_id');
    $retour_classe = './?p=classe&id=' . $classe_id;

    switch ($action) {
        case 'deconnexion':
            $_SESSION = [];
            session_destroy();
            rediriger('./');

        case 'creer_classe':
            $nom = trim((string) ($_POST['nom'] ?? ''));
            if (!preg_match('/^[\p{L}0-9 _-]{1,20}$/u', $nom)) {
                flash('Nom de classe invalide (1 à 20 caractères : lettres, chiffres, espaces, - ou _).', 'err');
                rediriger('./');
            }
            try {
                $pdo->prepare('INSERT INTO classes (nom, cree_le) VALUES (?, ?)')->execute([$nom, time()]);
            } catch (PDOException) {
                flash('Cette classe existe déjà.', 'err');
                rediriger('./');
            }
            flash("Classe « $nom » créée. Ajoutez son lien de dépôt Nuage puis générez les codes.");
            rediriger('./?p=classe&id=' . $pdo->lastInsertId());

        case 'supprimer_classe':
            $pdo->prepare('DELETE FROM classes WHERE id = ?')->execute([$classe_id]);
            flash('Classe supprimée, ainsi que ses codes élèves.');
            rediriger('./');

        case 'ajouter_depot':
            $app = normaliser_app((string) ($_POST['app'] ?? '*'));
            $url = trim((string) ($_POST['url'] ?? ''));
            $mdp = trim((string) ($_POST['mdp'] ?? ''));
            if (!preg_match('#^https://[^\s/]+/\S*$#', $url)) {
                flash('Le lien doit commencer par https:// (copiez le lien de partage depuis Nuage).', 'err');
                rediriger($retour_classe);
            }
            $pdo->prepare('INSERT INTO depots (classe_id, app, url, mdp) VALUES (?, ?, ?, ?)
                           ON CONFLICT (classe_id, app) DO UPDATE SET url = excluded.url, mdp = excluded.mdp')
                ->execute([$classe_id, $app, $url, $mdp]);
            flash($app === '*' ? 'Lien de dépôt par défaut enregistré.' : "Lien de dépôt pour l'application « $app » enregistré.");
            rediriger($retour_classe);

        case 'supprimer_depot':
            $pdo->prepare('DELETE FROM depots WHERE id = ? AND classe_id = ?')->execute([id_post('depot_id'), $classe_id]);
            flash('Lien de dépôt supprimé.');
            rediriger($retour_classe);

        case 'generer':
            $st = $pdo->prepare('SELECT nom FROM classes WHERE id = ?');
            $st->execute([$classe_id]);
            $nom_classe = $st->fetchColumn();
            if ($nom_classe === false) {
                rediriger('./');
            }
            $etiquettes = [];
            $lignes = preg_split('/\R/', (string) ($_POST['noms'] ?? '')) ?: [];
            foreach ($lignes as $l) {
                $l = trim($l);
                if ($l !== '') {
                    $etiquettes[] = mb_substr($l, 0, 60);
                }
            }
            if (!$etiquettes) {
                $n = max(0, min(60, (int) ($_POST['nombre'] ?? 0)));
                $deja = (int) $pdo->query('SELECT COUNT(*) FROM eleves WHERE classe_id = ' . (int) $classe_id)->fetchColumn();
                for ($i = 1; $i <= $n; $i++) {
                    $etiquettes[] = sprintf('Élève %02d', $deja + $i);
                }
            }
            if (!$etiquettes) {
                flash('Indiquez des noms (un par ligne) ou un nombre de codes.', 'err');
                rediriger($retour_classe);
            }
            if (count($etiquettes) > 60) {
                flash('60 codes maximum à la fois.', 'err');
                rediriger($retour_classe);
            }
            $nouveaux = [];
            $ins = $pdo->prepare('INSERT INTO eleves (classe_id, etiquette, code_hash, cree_le) VALUES (?, ?, ?, ?)');
            $pdo->beginTransaction();
            foreach ($etiquettes as $etiquette) {
                $code = generer_code($nom_classe);
                $ins->execute([$classe_id, $etiquette, hash_code($code), time()]);
                $nouveaux[] = ['eleve' => $etiquette, 'code' => $code];
            }
            $pdo->commit();
            $_SESSION['nouveaux'] = ['classe_id' => $classe_id, 'codes' => $nouveaux];
            rediriger($retour_classe . '#nouveaux');

        case 'regenerer':
            $st = $pdo->prepare('SELECT e.etiquette, c.nom FROM eleves e JOIN classes c ON c.id = e.classe_id
                                 WHERE e.id = ? AND e.classe_id = ?');
            $st->execute([id_post('eleve_id'), $classe_id]);
            $e = $st->fetch();
            if ($e) {
                $code = generer_code($e['nom']);
                $pdo->prepare('UPDATE eleves SET code_hash = ? WHERE id = ?')->execute([hash_code($code), id_post('eleve_id')]);
                $_SESSION['nouveaux'] = ['classe_id' => $classe_id, 'codes' => [['eleve' => $e['etiquette'], 'code' => $code]]];
                flash("Nouveau code pour « {$e['etiquette']} ». L'ancien ne fonctionne plus.");
            }
            rediriger($retour_classe . '#nouveaux');

        case 'basculer':
            $pdo->prepare('UPDATE eleves SET actif = 1 - actif WHERE id = ? AND classe_id = ?')
                ->execute([id_post('eleve_id'), $classe_id]);
            rediriger($retour_classe . '#eleves');

        case 'renommer':
            $etiquette = mb_substr(trim((string) ($_POST['etiquette'] ?? '')), 0, 60);
            if ($etiquette !== '') {
                $pdo->prepare('UPDATE eleves SET etiquette = ? WHERE id = ? AND classe_id = ?')
                    ->execute([$etiquette, id_post('eleve_id'), $classe_id]);
            }
            rediriger($retour_classe . '#eleves');

        case 'supprimer_eleve':
            $pdo->prepare('DELETE FROM eleves WHERE id = ? AND classe_id = ?')->execute([id_post('eleve_id'), $classe_id]);
            flash('Élève supprimé.');
            rediriger($retour_classe . '#eleves');

        case 'reglages':
            $propres = [];
            $erreurs = [];
            foreach (preg_split('/\R/', (string) ($_POST['origines'] ?? '')) ?: [] as $o) {
                $o = rtrim(trim($o), '/');
                if ($o === '') {
                    continue;
                }
                if (preg_match('#^https?://[^/\s]+$#', $o)) {
                    $propres[] = strtolower($o);
                } else {
                    $erreurs[] = $o;
                }
            }
            definir_reglage('origines', implode("\n", array_unique($propres)));
            definir_reglage('lien_eleves', trim((string) ($_POST['lien_eleves'] ?? '')));
            flash($erreurs
                ? 'Réglages enregistrés. Lignes ignorées (format attendu : https://site.exemple) : ' . implode(', ', $erreurs)
                : 'Réglages enregistrés.', $erreurs ? 'err' : 'ok');
            rediriger('./#reglages');

        case 'changer_mdp':
            $nouveau = (string) ($_POST['nouveau'] ?? '');
            if (!password_verify((string) ($_POST['ancien'] ?? ''), $hash_admin)) {
                flash('Mot de passe actuel incorrect.', 'err');
            } elseif (mb_strlen($nouveau) < 10) {
                flash('Le nouveau mot de passe doit faire au moins 10 caractères.', 'err');
            } else {
                definir_reglage('admin_hash', password_hash($nouveau, PASSWORD_DEFAULT));
                flash('Mot de passe modifié.');
            }
            rediriger('./#reglages');
    }
    rediriger('./');
}

/* ======================================================================
   Pages
   ====================================================================== */

if (($_GET['p'] ?? '') === 'classe') {
    page_classe((int) ($_GET['id'] ?? 0));
} else {
    page_accueil();
}

/* ---------------------------------------------------------------------- */

function page_installation(): void
{
    entete('Installation');
    ?>
    <main class="etroit">
        <h1>Centrale élèves</h1>
        <p class="sous">Première utilisation : choisissez le mot de passe administrateur.
            Il protège la gestion des classes et des codes. Au moins 10 caractères.</p>
        <?php messages(); ?>
        <form method="post" class="carte">
            <?= csrf() ?>
            <input type="hidden" name="action" value="installer">
            <label>Mot de passe<input type="password" name="mdp" required minlength="10" autocomplete="new-password"></label>
            <label>Confirmez<input type="password" name="mdp2" required minlength="10" autocomplete="new-password"></label>
            <button>Créer le compte administrateur</button>
        </form>
    </main>
    <?php
    pied();
}

function page_connexion(): void
{
    entete('Connexion');
    ?>
    <main class="etroit">
        <h1>Centrale élèves</h1>
        <p class="sous">Espace enseignant</p>
        <?php messages(); ?>
        <form method="post" class="carte">
            <?= csrf() ?>
            <input type="hidden" name="action" value="connexion">
            <label>Mot de passe<input type="password" name="mdp" required autofocus autocomplete="current-password"></label>
            <button>Se connecter</button>
        </form>
    </main>
    <?php
    pied();
}

function page_accueil(): void
{
    $classes = db()->query('SELECT c.id, c.nom,
            (SELECT COUNT(*) FROM eleves e WHERE e.classe_id = c.id) AS nb_eleves,
            (SELECT COUNT(*) FROM eleves e WHERE e.classe_id = c.id AND e.derniere_connexion IS NOT NULL) AS nb_venus,
            (SELECT COUNT(*) FROM depots d WHERE d.classe_id = c.id) AS nb_depots,
            (SELECT MAX(derniere_connexion) FROM eleves e WHERE e.classe_id = c.id) AS activite
        FROM classes c ORDER BY c.nom')->fetchAll();
    $api = url_base() . '/api/login.php';
    $client = url_base() . '/js/centrale-client.js';

    entete('Tableau de bord');
    barre();
    ?>
    <main>
        <?php messages(); ?>
        <section>
            <div class="titre-section">
                <h2>Classes</h2>
                <form method="post" class="en-ligne">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="creer_classe">
                    <input name="nom" placeholder="ex. 5A" required maxlength="20" aria-label="Nom de la nouvelle classe">
                    <button>Créer la classe</button>
                </form>
            </div>
            <?php if (!$classes): ?>
                <p class="vide">Aucune classe pour l'instant. Créez-en une ci-dessus.</p>
            <?php else: ?>
                <div class="grille-classes">
                    <?php foreach ($classes as $c): ?>
                        <a class="carte classe" href="./?p=classe&id=<?= (int) $c['id'] ?>">
                            <strong><?= h($c['nom']) ?></strong>
                            <span><?= (int) $c['nb_eleves'] ?> élève<?= $c['nb_eleves'] > 1 ? 's' : '' ?>
                                · <?= (int) $c['nb_venus'] ?> déjà connecté<?= $c['nb_venus'] > 1 ? 's' : '' ?></span>
                            <span class="<?= $c['nb_depots'] ? '' : 'alerte' ?>">
                                <?= $c['nb_depots'] ? (int) $c['nb_depots'] . ' lien' . ($c['nb_depots'] > 1 ? 's' : '') . ' de dépôt' : 'Aucun lien de dépôt' ?>
                            </span>
                            <span class="discret">Dernière activité : <?= quand($c['activite'] ? (int) $c['activite'] : null) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section>
            <h2>Brancher une application</h2>
            <p>Dans chaque application élève, ajoutez ces deux lignes (l'identifiant <code>app</code> est libre :
                il sert à donner un dossier de dépôt différent par application si vous le souhaitez).</p>
            <pre class="code"><?= h('<script src="' . $client . '"></script>
<script>
  Centrale.init({ api: "' . $api . '", app: "mon-app" });
</script>') ?></pre>
            <p class="discret">Adresse de l'API : <code><?= h($api) ?></code></p>
        </section>

        <section id="reglages">
            <h2>Réglages</h2>
            <div class="deux-colonnes">
                <form method="post" class="carte">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="reglages">
                    <label>Sites autorisés à utiliser l'API
                        <span class="aide">Un par ligne, ex. <code>https://monpseudo.github.io</code> ou
                            <code>https://monpseudo.forge.apps.education.fr</code>. Laissez vide pour autoriser tous les sites.</span>
                        <textarea name="origines" rows="4" placeholder="https://monpseudo.github.io"><?= h(reglage('origines', '')) ?></textarea>
                    </label>
                    <label>Adresse de vos applications (imprimée sur les fiches élèves)
                        <input name="lien_eleves" value="<?= h(reglage('lien_eleves', '')) ?>" placeholder="https://monpseudo.github.io/techno">
                    </label>
                    <button>Enregistrer</button>
                </form>
                <form method="post" class="carte">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="changer_mdp">
                    <h3>Mot de passe administrateur</h3>
                    <label>Actuel<input type="password" name="ancien" required autocomplete="current-password"></label>
                    <label>Nouveau (10 caractères minimum)<input type="password" name="nouveau" required minlength="10" autocomplete="new-password"></label>
                    <button class="secondaire">Changer</button>
                </form>
            </div>
        </section>
    </main>
    <?php
    pied();
}

function page_classe(int $id): void
{
    $st = db()->prepare('SELECT * FROM classes WHERE id = ?');
    $st->execute([$id]);
    $classe = $st->fetch();
    if (!$classe) {
        rediriger('./');
    }
    $st = db()->prepare("SELECT * FROM depots WHERE classe_id = ? ORDER BY CASE WHEN app = '*' THEN 0 ELSE 1 END, app");
    $st->execute([$id]);
    $depots = $st->fetchAll();
    $st = db()->prepare('SELECT * FROM eleves WHERE classe_id = ? ORDER BY etiquette COLLATE NOCASE');
    $st->execute([$id]);
    $eleves = $st->fetchAll();

    $nouveaux = null;
    if (!empty($_SESSION['nouveaux']) && (int) $_SESSION['nouveaux']['classe_id'] === $id) {
        $nouveaux = $_SESSION['nouveaux']['codes'];
    }
    unset($_SESSION['nouveaux']); // les codes ne s'affichent qu'une fois

    entete('Classe ' . $classe['nom']);
    barre();
    ?>
    <main>
        <p class="fil"><a href="./">← Toutes les classes</a></p>
        <h1>Classe <?= h($classe['nom']) ?></h1>
        <?php messages(); ?>

        <?php if ($nouveaux): ?>
            <section id="nouveaux" class="nouveaux">
                <div class="titre-section">
                    <h2>Codes générés</h2>
                    <div class="boutons">
                        <button type="button" onclick="window.print()">Imprimer les fiches</button>
                        <button type="button" class="secondaire" id="telecharger-csv">Télécharger (CSV)</button>
                    </div>
                </div>
                <p class="alerte-bloc">Ces codes ne seront <strong>plus jamais affichés</strong> (seule leur empreinte est enregistrée).
                    Imprimez-les ou téléchargez-les maintenant. En cas de perte, utilisez « Nouveau code » sur la ligne de l'élève.</p>
                <div class="defile"><table>
                    <thead><tr><th>Élève</th><th>Code</th></tr></thead>
                    <tbody>
                    <?php foreach ($nouveaux as $n): ?>
                        <tr><td><?= h($n['eleve']) ?></td><td><code class="code-eleve"><?= h($n['code']) ?></code></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div class="fiches" aria-hidden="true">
                    <?php foreach ($nouveaux as $n): ?>
                        <div class="fiche">
                            <div class="fiche-haut"><span>Technologie · <?= h($classe['nom']) ?></span><span><?= h($n['eleve']) ?></span></div>
                            <div class="fiche-code"><?= h($n['code']) ?></div>
                            <div class="fiche-bas">Mon code personnel, à ne pas partager.
                                <?php if (reglage('lien_eleves')): ?><br><?= h(reglage('lien_eleves')) ?><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <script>
                    document.getElementById('telecharger-csv').addEventListener('click', () => {
                        const lignes = <?= json_encode($nouveaux, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                        const csv = '﻿' + ['Classe;Élève;Code']
                            .concat(lignes.map(l => [<?= json_encode($classe['nom'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>, l.eleve, l.code]
                                .map(v => '"' + String(v).replace(/"/g, '""') + '"').join(';')))
                            .join('\r\n');
                        const a = document.createElement('a');
                        a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
                        a.download = 'codes_' + <?= json_encode(prefixe_classe($classe['nom'])) ?> + '_' + new Date().toISOString().slice(0, 10) + '.csv';
                        a.click();
                        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
                    });
                </script>
            </section>
        <?php endif; ?>

        <section id="depots">
            <h2>Liens de dépôt Nuage</h2>
            <p class="aide">Dans Nuage : créez un dossier, puis Partager → Lien de partage → « Dépôt de fichiers »,
                ajoutez un mot de passe et copiez le lien. L'application <code>*</code> sert pour toutes les applications
                qui n'ont pas leur propre lien.</p>
            <?php if ($depots): ?>
                <div class="defile"><table>
                    <thead><tr><th>Application</th><th>Lien</th><th>Mot de passe</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($depots as $d): ?>
                        <tr>
                            <td><code><?= h($d['app']) ?></code><?= $d['app'] === '*' ? ' <span class="discret">(par défaut)</span>' : '' ?></td>
                            <td class="lien"><a href="<?= h($d['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($d['url']) ?></a></td>
                            <td><?= $d['mdp'] !== '' ? '<code>' . h($d['mdp']) . '</code>' : '<span class="discret">aucun</span>' ?></td>
                            <td class="actions">
                                <form method="post" onsubmit="return confirm('Supprimer ce lien de dépôt ?')">
                                    <?= csrf() ?>
                                    <input type="hidden" name="action" value="supprimer_depot">
                                    <input type="hidden" name="classe_id" value="<?= $id ?>">
                                    <input type="hidden" name="depot_id" value="<?= (int) $d['id'] ?>">
                                    <button class="petit danger">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p class="vide alerte">Aucun lien : les élèves pourront se connecter mais n'auront pas d'adresse de dépôt.</p>
            <?php endif; ?>
            <form method="post" class="carte formulaire-depot">
                <?= csrf() ?>
                <input type="hidden" name="action" value="ajouter_depot">
                <input type="hidden" name="classe_id" value="<?= $id ?>">
                <label>Application<input name="app" value="*" maxlength="40" required>
                    <span class="aide">* = toutes</span></label>
                <label class="large">Lien de dépôt Nuage<input name="url" type="url" required placeholder="https://nuage03.apps.education.fr/s/…"></label>
                <label>Mot de passe du lien<input name="mdp" maxlength="100" autocomplete="off" placeholder="facultatif"></label>
                <button>Ajouter / remplacer</button>
            </form>
        </section>

        <section id="eleves">
            <h2>Élèves (<?= count($eleves) ?>)</h2>
            <?php if ($eleves): ?>
                <div class="defile"><table>
                    <thead><tr><th>Élève</th><th>Dernière connexion</th><th>État</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($eleves as $e): ?>
                        <tr class="<?= $e['actif'] ? '' : 'inactif' ?>">
                            <td>
                                <form method="post" class="renommer">
                                    <?= csrf() ?>
                                    <input type="hidden" name="action" value="renommer">
                                    <input type="hidden" name="classe_id" value="<?= $id ?>">
                                    <input type="hidden" name="eleve_id" value="<?= (int) $e['id'] ?>">
                                    <input name="etiquette" value="<?= h($e['etiquette']) ?>" maxlength="60" aria-label="Nom affiché"
                                           onchange="this.form.submit()">
                                </form>
                            </td>
                            <td><?= quand($e['derniere_connexion'] ? (int) $e['derniere_connexion'] : null) ?></td>
                            <td><?= $e['actif'] ? 'Actif' : 'Désactivé' ?></td>
                            <td class="actions">
                                <?php foreach ([
                                    ['regenerer', 'Nouveau code', '', "L'ancien code de cet élève ne fonctionnera plus. Continuer ?"],
                                    ['basculer', $e['actif'] ? 'Désactiver' : 'Réactiver', 'secondaire', ''],
                                    ['supprimer_eleve', 'Supprimer', 'danger', 'Supprimer définitivement cet élève ?'],
                                ] as [$act, $libelle, $classe_btn, $confirmation]): ?>
                                    <form method="post" <?= $confirmation ? 'onsubmit="return confirm(' . h(json_encode($confirmation)) . ')"' : '' ?>>
                                        <?= csrf() ?>
                                        <input type="hidden" name="action" value="<?= $act ?>">
                                        <input type="hidden" name="classe_id" value="<?= $id ?>">
                                        <input type="hidden" name="eleve_id" value="<?= (int) $e['id'] ?>">
                                        <button class="petit <?= $classe_btn ?>"><?= $libelle ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p class="vide">Aucun élève. Générez des codes ci-dessous.</p>
            <?php endif; ?>

            <form method="post" class="carte">
                <?= csrf() ?>
                <input type="hidden" name="action" value="generer">
                <input type="hidden" name="classe_id" value="<?= $id ?>">
                <h3>Générer des codes</h3>
                <div class="deux-colonnes">
                    <label>Noms des élèves, un par ligne
                        <span class="aide">Prénom et initiale suffisent (ex. « Léa D. »). Ce nom sert à nommer les fichiers déposés.</span>
                        <textarea name="noms" rows="6" placeholder="Léa D.&#10;Noah M.&#10;…"></textarea>
                    </label>
                    <label>… ou un nombre de codes anonymes
                        <span class="aide">Ils s'appelleront « Élève 01 », « Élève 02 »… Vous pourrez les renommer ensuite.</span>
                        <input type="number" name="nombre" min="1" max="60" placeholder="ex. 28">
                    </label>
                </div>
                <button>Générer les codes</button>
            </form>
        </section>

        <section class="zone-danger">
            <form method="post" onsubmit="return confirm('Supprimer la classe <?= h(addslashes($classe['nom'])) ?> et tous ses codes ? Cette action est définitive.')">
                <?= csrf() ?>
                <input type="hidden" name="action" value="supprimer_classe">
                <input type="hidden" name="classe_id" value="<?= $id ?>">
                <button class="danger">Supprimer la classe</button>
            </form>
        </section>
    </main>
    <?php
    pied();
}

/* ---------------------------------------------------------------------- */

function messages(): void
{
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<p class="message ' . ($type === 'err' ? 'erreur' : 'succes') . '" role="status">' . h($msg) . '</p>';
    }
    unset($_SESSION['flash']);
}

function barre(): void
{
    ?>
    <header class="barre">
        <a href="./" class="marque">Centrale élèves</a>
        <form method="post">
            <?= csrf() ?>
            <input type="hidden" name="action" value="deconnexion">
            <button class="lien-bouton">Se déconnecter</button>
        </form>
    </header>
    <?php
}

function entete(string $titre): void
{
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($titre) ?> · Centrale élèves</title>
<style>
:root {
    --fond: #f6f5f2; --surface: #fff; --texte: #1d1f24; --doux: #62666f; --bord: #dedbd3;
    --accent: #1f5f8b; --accent-texte: #fff; --danger: #a8321f; --ok-fond: #e6f2ea; --ok: #1f6b3a;
    --err-fond: #fbe9e6; --alerte: #9a5b00; --code-fond: #efece6;
    color-scheme: light;
}
@media (prefers-color-scheme: dark) {
    :root {
        --fond: #16181c; --surface: #1f2228; --texte: #e8e6e1; --doux: #a2a5ad; --bord: #343841;
        --accent: #6aa9d6; --accent-texte: #0d1520; --danger: #f08a77; --ok-fond: #1b3324; --ok: #8fd3a5;
        --err-fond: #3a1f1b; --alerte: #f0b35a; --code-fond: #2a2e35;
        color-scheme: dark;
    }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--fond); color: var(--texte);
       font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { max-width: 1040px; margin: 0 auto; padding: 24px 16px 64px; }
main.etroit { max-width: 420px; padding-top: 12vh; }
h1 { font-size: 1.8rem; margin: 0 0 4px; letter-spacing: -0.01em; }
h2 { font-size: 1.2rem; margin: 0 0 12px; }
h3 { font-size: 1rem; margin: 0 0 12px; }
section { margin-top: 36px; }
a { color: var(--accent); }
code { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-size: .9em;
       background: var(--code-fond); padding: 1px 5px; border-radius: 4px; }
pre.code { background: var(--code-fond); padding: 14px; border-radius: 8px; overflow-x: auto; font-size: .85rem; }
.sous, .discret, .aide { color: var(--doux); }
.aide { display: block; font-size: .85rem; font-weight: 400; margin: 2px 0 6px; }
.discret { font-size: .9rem; }
.barre { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px;
         background: var(--surface); border-bottom: 1px solid var(--bord); }
.marque { font-weight: 700; text-decoration: none; color: var(--texte); }
.carte { background: var(--surface); border: 1px solid var(--bord); border-radius: 10px; padding: 16px; margin-top: 12px; }
label { display: block; font-weight: 600; font-size: .92rem; margin-bottom: 12px; }
input, textarea { display: block; width: 100%; margin-top: 4px; padding: 9px 10px; font: inherit; color: var(--texte);
                  background: var(--fond); border: 1px solid var(--bord); border-radius: 6px; }
input:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
button { font: inherit; font-weight: 600; padding: 9px 16px; border-radius: 6px; border: 1px solid var(--accent);
         background: var(--accent); color: var(--accent-texte); cursor: pointer; }
button.secondaire { background: transparent; color: var(--accent); }
button.danger { background: transparent; color: var(--danger); border-color: var(--danger); }
button.petit { padding: 4px 10px; font-size: .85rem; }
button.lien-bouton { background: none; border: none; color: var(--accent); padding: 0; font-weight: 500; }
.titre-section { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.titre-section h2 { margin: 0; }
.en-ligne { display: flex; gap: 8px; }
.en-ligne input { margin: 0; width: 140px; }
.boutons { display: flex; gap: 8px; flex-wrap: wrap; }
.grille-classes { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.carte.classe { display: flex; flex-direction: column; gap: 2px; text-decoration: none; color: var(--texte); margin: 0;
                transition: border-color .15s; }
.carte.classe:hover { border-color: var(--accent); }
.carte.classe strong { font-size: 1.4rem; }
.alerte { color: var(--alerte); }
.deux-colonnes { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
.deux-colonnes .carte { margin-top: 0; }
.defile { overflow-x: auto; background: var(--surface); border: 1px solid var(--bord); border-radius: 10px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--bord); vertical-align: middle; }
th { font-size: .82rem; text-transform: uppercase; letter-spacing: .04em; color: var(--doux); font-weight: 600; }
tbody tr:last-child td { border-bottom: none; }
td.lien { max-width: 340px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
td.actions { white-space: nowrap; text-align: right; }
td.actions form { display: inline; }
tr.inactif td { opacity: .55; }
form.renommer input { margin: 0; padding: 4px 6px; background: transparent; border-color: transparent; font-weight: 500; }
form.renommer input:hover, form.renommer input:focus { border-color: var(--bord); background: var(--fond); }
.formulaire-depot { display: grid; grid-template-columns: 140px 1fr 180px auto; gap: 12px; align-items: end; }
.formulaire-depot label { margin: 0; }
@media (max-width: 720px) { .formulaire-depot { grid-template-columns: 1fr; } }
.message { padding: 10px 14px; border-radius: 8px; margin: 12px 0; }
.message.succes { background: var(--ok-fond); color: var(--ok); }
.message.erreur { background: var(--err-fond); color: var(--danger); }
.alerte-bloc { background: var(--err-fond); padding: 10px 14px; border-radius: 8px; }
.nouveaux { border: 2px solid var(--accent); border-radius: 12px; padding: 16px; background: var(--surface); }
.nouveaux .defile { border: none; }
.code-eleve { font-size: 1rem; letter-spacing: .03em; }
.fil { margin: 0 0 8px; }
.vide { color: var(--doux); font-style: italic; }
.zone-danger { border-top: 1px solid var(--bord); padding-top: 24px; }
.fiches { display: none; }

@media print {
    @page { margin: 10mm; }
    body { background: #fff; color: #000; }
    body * { visibility: hidden; }
    .fiches, .fiches * { visibility: visible; }
    .fiches { display: grid; grid-template-columns: 1fr 1fr; gap: 0; position: absolute; left: 0; top: 0; width: 100%; }
    .fiche { border: 1px dashed #888; padding: 5mm 6mm; break-inside: avoid; font-family: system-ui, sans-serif; }
    .fiche-haut { display: flex; justify-content: space-between; font-size: 10pt; color: #333; }
    .fiche-haut span:last-child { font-weight: 700; color: #000; }
    .fiche-code { font: 600 15pt ui-monospace, Menlo, Consolas, monospace; margin: 3mm 0; letter-spacing: .03em; }
    .fiche-bas { font-size: 8.5pt; color: #444; }
}
</style>
</head>
<body>
    <?php
}

function pied(): void
{
    echo "\n</body>\n</html>\n";
}
