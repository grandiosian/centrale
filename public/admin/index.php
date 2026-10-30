<?php
/**
 * Centrale élèves — page d'administration (réservée à l'enseignant).
 *
 * Classes, codes élèves (pseudonymes : les noms restent sur l'ordinateur de l'enseignant),
 * liens de dépôt Nuage, travail sauvegardé des élèves, ressources des ateliers, réglages.
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/commun.php';
require __DIR__ . '/../../lib/admin_suivi.php';
require __DIR__ . '/../../lib/admin_organisation.php';
require __DIR__ . '/../../lib/admin_fichiers.php';
require __DIR__ . '/../../lib/admin_groupes.php';
require __DIR__ . '/../../lib/admin_import.php';

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
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

function id_post(string $champ): int
{
    return (int) ($_POST[$champ] ?? 0);
}

/** Petit formulaire POST d'une ligne (bouton d'action). */
function bouton(string $action, string $libelle, array $champs = [], string $classe = '', string $confirmer = '', string $titre = ''): string
{
    $html = '<form method="post"' . ($confirmer !== '' ? ' data-confirmer="' . h($confirmer) . '"' : '') . '>' . csrf()
        . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($champs as $n => $v) {
        $html .= '<input type="hidden" name="' . h($n) . '" value="' . h((string) $v) . '">';
    }
    return $html . '<button class="petit ' . h($classe) . '"' . ($titre !== '' ? ' title="' . h($titre) . '" aria-label="' . h($titre) . '"' : '')
        . '>' . h($libelle) . '</button></form>';
}

/** Affiche le nom d'un élève : le numéro vient du serveur, le nom est complété par le navigateur. */
function nom_eleve(string $classe, string $etiquette, string $vide = '—'): string
{
    if (!est_pseudonyme($etiquette)) {
        return '<span class="alerte" title="Nom enregistré sur le serveur (ancienne version) : utilisez « Anonymiser la classe »">'
            . h($etiquette) . '</span>';
    }
    return '<span data-nom-local data-classe="' . h($classe) . '" data-numero="' . h($etiquette) . '" data-vide="' . h($vide) . '">'
        . h($vide) . '</span>';
}

function jauge(int $utilise, int $quota): string
{
    $pct = $quota > 0 ? min(100, (int) round($utilise * 100 / $quota)) : 0;
    return '<div class="jauge' . ($pct >= 90 ? ' pleine' : '') . '" title="' . $pct . ' %"><i style="width:' . $pct . '%"></i></div>';
}

function classe_par_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM classes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function supprimer_eleves(string $condition, array $params): void
{
    $st = db()->prepare("SELECT id FROM eleves WHERE $condition");
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        effacer_fichiers_eleve((int) $id);
    }
}

/* ======================================================================
   Accès : installation et connexion
   ====================================================================== */

$action = $_POST['action'] ?? null;
if ($action !== null && !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('Jeton de sécurité invalide : rechargez la page et recommencez.');
}

$hash_admin = reglage('admin_hash');

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

/* ======================================================================
   Actions de l'enseignant connecté
   ====================================================================== */

if ($action === null) {
    attribuer_surnoms_manquants();   // nouveaux élèves (et élèves créés avant les surnoms)
}

if ($action !== null) {
    $pdo = db();
    $classe_id = id_post('classe_id');
    $eleve_id = id_post('eleve_id');
    $retour_classe = './?p=classe&id=' . $classe_id;
    $retour_eleve = './?p=eleve&id=' . $eleve_id;

    action_suivi($action);        // parcours, ateliers, suivi
    action_organisation($action); // établissements, emploi du temps, salles, plans
    action_fichiers($action);     // fichiers du serveur
    action_import($action);       // import Pronote (réponse JSON)
    action_groupes($action);      // groupes d'une classe (GR1, GR2…)

    switch ($action) {
        case 'deconnexion':
            $_SESSION = [];
            session_destroy();
            rediriger('./');

        /* ----- Classes ----- */

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
            $nouvelle = (int) $pdo->lastInsertId();
            oublier_renommage($nom);
            flash("Classe « $nom » créée. Générez maintenant les codes des élèves.");
            rediriger('./?p=classe&id=' . $nouvelle);

        case 'renommer_classe':
            $nom = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nom'] ?? '')) ?? '');
            if (!preg_match('/^[\p{L}0-9 _-]{1,20}$/u', $nom)) {
                flash('Nom de classe invalide (1 à 20 caractères : lettres, chiffres, espaces, - ou _).', 'err');
                rediriger($retour_classe);
            }
            $ancien = classe_par_id($classe_id)['nom'] ?? '';
            $erreur = renommer_classe($classe_id, $nom);
            if ($erreur) {
                flash($erreur, 'err');
            } elseif ($ancien !== $nom) {
                flash("Classe « $ancien » renommée en « $nom ». Les codes déjà distribués fonctionnent toujours ; les noms gardés sur cet ordinateur suivent.");
            }
            rediriger($retour_classe);

        case 'regenerer_classe':
            $choix = (string) ($_POST['eleves'] ?? '');
            $ids = $choix === 'tous' ? null : array_values(array_filter(array_map('intval', explode(',', $choix))));
            if ($ids === []) {
                rediriger($retour_classe);
            }
            $codes = regenerer_codes($classe_id, $ids);
            $_SESSION['nouveaux'] = ['classe_id' => $classe_id, 'codes' => $codes];
            flash(count($codes) . ' nouveau(x) code(s). Les anciens codes de ces élèves ne fonctionnent plus.');
            rediriger($retour_classe . '#nouveaux');

        case 'apercu_eleve':
            // Compte « élève test » (classe TEST, élève 01) : l'enseignant ouvre le portail tel qu'un élève le voit,
            // sans taper de code. Rien n'est envoyé d'autre qu'un jeton de session, dans l'adresse.
            $st = $pdo->prepare('SELECT * FROM classes WHERE nom = ? COLLATE NOCASE');
            $st->execute(['TEST']);
            $classe = $st->fetch();
            if (!$classe) {
                $pdo->prepare('INSERT INTO classes (nom, cree_le) VALUES (?, ?)')->execute(['TEST', time()]);
                $classe = classe_par_id((int) $pdo->lastInsertId());
                oublier_renommage('TEST');
            }
            $st = $pdo->prepare('SELECT id FROM eleves WHERE classe_id = ? ORDER BY id LIMIT 1');
            $st->execute([$classe['id']]);
            $eid = (int) $st->fetchColumn();
            if (!$eid) {
                $pdo->prepare('INSERT INTO eleves (classe_id, etiquette, code_hash, cree_le) VALUES (?, ?, ?, ?)')
                    ->execute([$classe['id'], '01', hash_code(generer_code(prefixe_codes($classe))), time()]);
                $eid = (int) $pdo->lastInsertId();
                attribuer_surnom($eid, (int) $classe['id']);
            }
            $pdo->prepare('UPDATE eleves SET actif = 1 WHERE id = ?')->execute([$eid]);
            $jeton = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO sessions (jeton_hash, eleve_id, expire) VALUES (?, ?, ?)')
                ->execute([hash('sha256', $jeton), $eid, time() + 2 * 3600]);
            rediriger('../#centrale=' . $jeton);

        case 'reinitialiser_tous_codes':
            // Tous les codes de toutes les classes. Réponse : numéros et codes seulement (les noms sont dans le navigateur).
            header('Content-Type: application/json; charset=utf-8');
            $resultat = [];
            $liste = $pdo->query('SELECT c.id, c.nom, COALESCE(e.nom, \'\') AS etablissement FROM classes c
                                  LEFT JOIN etablissements e ON e.id = c.etablissement_id ORDER BY e.nom, c.nom')->fetchAll();
            foreach ($liste as $c) {
                $resultat[] = ['classe' => $c['nom'], 'etablissement' => $c['etablissement'], 'codes' => regenerer_codes((int) $c['id'], null)];
            }
            echo json_encode(['ok' => true, 'classes' => $resultat], JSON_UNESCAPED_UNICODE);
            exit;

        case 'verifier_codes':
            // Le navigateur envoie l'EMPREINTE des codes qu'il connaît (jamais les codes) ; on répond lesquels sont à jour.
            header('Content-Type: application/json; charset=utf-8');
            $st = $pdo->prepare('SELECT etiquette, code_hash FROM eleves WHERE classe_id = ?');
            $st->execute([$classe_id]);
            $hashes = array_column($st->fetchAll(), 'code_hash', 'etiquette');
            $recus = json_decode((string) ($_POST['empreintes'] ?? '{}'), true) ?: [];
            $valides = [];
            foreach ($recus as $num => $empreinte) {
                if (isset($hashes[$num]) && is_string($empreinte) && hash_equals($hashes[$num], $empreinte)) {
                    $valides[] = (string) $num;
                }
            }
            echo json_encode(['ok' => true, 'valides' => $valides]);
            exit;

        case 'supprimer_classe':
            supprimer_eleves('classe_id = ?', [$classe_id]);
            $pdo->prepare('DELETE FROM classes WHERE id = ?')->execute([$classe_id]);
            flash('Classe supprimée, avec ses codes et tout le travail de ses élèves.');
            rediriger('./');

        case 'vider_travail':
            supprimer_eleves('classe_id = ?', [$classe_id]);
            foreach (['donnees', 'donnees_versions', 'fichiers', 'progression', 'connexions'] as $table) {
                $pdo->prepare("DELETE FROM $table WHERE eleve_id IN (SELECT id FROM eleves WHERE classe_id = ?)")->execute([$classe_id]);
            }
            flash('Travail et avancement de la classe effacés. Les codes des élèves fonctionnent toujours.');
            rediriger($retour_classe);

        case 'anonymiser':
            $classe = classe_par_id($classe_id);
            if (!$classe) {
                rediriger('./');
            }
            $st = $pdo->prepare('SELECT id, etiquette FROM eleves WHERE classe_id = ? ORDER BY etiquette COLLATE NOCASE');
            $st->execute([$classe_id]);
            $numero = prochain_numero($classe_id);
            $paires = [];
            $maj = $pdo->prepare('UPDATE eleves SET etiquette = ? WHERE id = ?');
            foreach ($st->fetchAll() as $e) {
                if (est_pseudonyme($e['etiquette'])) {
                    continue;
                }
                $n = sprintf('%02d', $numero++);
                $maj->execute([$n, $e['id']]);
                $paires[] = ['numero' => $n, 'nom' => $e['etiquette']];
            }
            $_SESSION['anonymisation'] = ['classe_id' => $classe_id, 'paires' => $paires];
            flash(count($paires) . ' nom(s) retiré(s) du serveur. La correspondance a été téléchargée et gardée sur cet ordinateur.');
            rediriger($retour_classe);

        /* ----- Dépôts Nuage ----- */

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
            flash($app === '*' ? 'Lien de dépôt par défaut enregistré.' : "Lien de dépôt pour l'atelier « $app » enregistré.");
            rediriger($retour_classe . '#depots');

        case 'supprimer_depot':
            $pdo->prepare('DELETE FROM depots WHERE id = ? AND classe_id = ?')->execute([id_post('depot_id'), $classe_id]);
            flash('Lien de dépôt supprimé.');
            rediriger($retour_classe . '#depots');

        /* ----- Élèves et codes ----- */

        case 'generer':
            $classe = classe_par_id($classe_id);
            if (!$classe) {
                rediriger('./');
            }
            // Seul un NOMBRE arrive ici : les noms sont gardés par le navigateur de l'enseignant.
            $n = (int) ($_POST['nombre'] ?? 0);
            if ($n < 1 || $n > 60) {
                flash('Indiquez entre 1 et 60 élèves.', 'err');
                rediriger($retour_classe);
            }
            $numero = prochain_numero($classe_id);
            $nouveaux = [];
            $ins = $pdo->prepare('INSERT INTO eleves (classe_id, etiquette, code_hash, cree_le) VALUES (?, ?, ?, ?)');
            $prefixe = prefixe_codes($classe);
            $pdo->beginTransaction();
            for ($i = 0; $i < $n; $i++) {
                $code = generer_code($prefixe);
                $etiquette = sprintf('%02d', $numero + $i);
                $ins->execute([$classe_id, $etiquette, hash_code($code), time()]);
                $nouveaux[] = ['numero' => $etiquette, 'code' => $code];
            }
            $pdo->commit();
            $_SESSION['nouveaux'] = ['classe_id' => $classe_id, 'codes' => $nouveaux];
            rediriger($retour_classe . '#nouveaux');

        case 'regenerer':
            $st = $pdo->prepare('SELECT e.etiquette FROM eleves e WHERE e.id = ? AND e.classe_id = ?');
            $st->execute([$eleve_id, $classe_id]);
            if ($e = $st->fetch()) {
                $code = generer_code(prefixe_codes(classe_par_id($classe_id)));
                $pdo->prepare('UPDATE eleves SET code_hash = ? WHERE id = ?')->execute([hash_code($code), $eleve_id]);
                $pdo->prepare('DELETE FROM sessions WHERE eleve_id = ?')->execute([$eleve_id]);
                $_SESSION['nouveaux'] = ['classe_id' => $classe_id, 'codes' => [['numero' => $e['etiquette'], 'code' => $code]]];
                flash("Nouveau code pour l'élève {$e['etiquette']}. L'ancien ne fonctionne plus.");
            }
            rediriger($retour_classe . '#nouveaux');

        case 'nouveau_surnom':
            $st = $pdo->prepare('SELECT etiquette FROM eleves WHERE id = ? AND classe_id = ?');
            $st->execute([$eleve_id, $classe_id]);
            if ($n = $st->fetchColumn()) {
                $s = attribuer_surnom($eleve_id, $classe_id);
                flash("Élève $n : nouveau surnom « $s ».");
            }
            rediriger($retour_classe . '#eleves');

        case 'basculer':
            $pdo->prepare('UPDATE eleves SET actif = 1 - actif WHERE id = ? AND classe_id = ?')->execute([$eleve_id, $classe_id]);
            $pdo->prepare('DELETE FROM sessions WHERE eleve_id = ?')->execute([$eleve_id]);
            rediriger($retour_classe . '#eleves');

        case 'supprimer_eleve':
            supprimer_eleves('id = ? AND classe_id = ?', [$eleve_id, $classe_id]);
            $pdo->prepare('DELETE FROM eleves WHERE id = ? AND classe_id = ?')->execute([$eleve_id, $classe_id]);
            flash('Élève supprimé, avec son travail.');
            rediriger($retour_classe . '#eleves');

        /* ----- Travail d'un élève ----- */

        case 'supprimer_donnee':
            foreach (['donnees', 'donnees_versions'] as $table) {
                $pdo->prepare("DELETE FROM $table WHERE eleve_id = ? AND app = ? AND cle = ?")
                    ->execute([$eleve_id, (string) $_POST['app'], (string) $_POST['cle']]);
            }
            flash('Donnée supprimée.');
            rediriger($retour_eleve);

        case 'restaurer_version':
            $st = $pdo->prepare('SELECT * FROM donnees_versions WHERE id = ? AND eleve_id = ?');
            $st->execute([id_post('version_id'), $eleve_id]);
            if ($v = $st->fetch()) {
                $pdo->beginTransaction();
                // La version actuelle est d'abord mise de côté : rien n'est perdu.
                $pdo->prepare('INSERT INTO donnees_versions (eleve_id, app, cle, valeur, taille, maj)
                               SELECT eleve_id, app, cle, valeur, taille, maj FROM donnees WHERE eleve_id = ? AND app = ? AND cle = ?')
                    ->execute([$eleve_id, $v['app'], $v['cle']]);
                $pdo->prepare('INSERT INTO donnees (eleve_id, app, cle, valeur, taille, maj) VALUES (?, ?, ?, ?, ?, ?)
                               ON CONFLICT (eleve_id, app, cle) DO UPDATE SET valeur = excluded.valeur,
                               taille = excluded.taille, maj = excluded.maj')
                    ->execute([$eleve_id, $v['app'], $v['cle'], $v['valeur'], $v['taille'], time()]);
                $pdo->commit();
                flash('Version du ' . quand((int) $v['maj']) . ' restaurée. L\'élève la retrouvera à sa prochaine connexion.');
            }
            rediriger($retour_eleve);

        case 'supprimer_fichier':
            $st = $pdo->prepare('SELECT id, chemin FROM fichiers WHERE id = ? AND eleve_id = ?');
            $st->execute([id_post('fichier_id'), $eleve_id]);
            if ($f = $st->fetch()) {
                $pdo->prepare('DELETE FROM fichiers WHERE id = ?')->execute([$f['id']]);
                effacer_fichier_stocke($f['chemin']);
                flash('Fichier supprimé.');
            }
            rediriger($retour_eleve);

        /* ----- Ressources des ateliers ----- */

        case 'ajouter_ressource':
            $app = normaliser_app((string) ($_POST['app'] ?? ''));
            $envoi = $_FILES['fichier'] ?? null;
            if ($app === '*') {
                flash("Indiquez l'identifiant de l'atelier (ex. robot-mbot).", 'err');
            } elseif (!$envoi || is_array($envoi['error']) || $envoi['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($envoi['tmp_name'])) {
                flash("L'envoi a échoué (fichier trop gros ? limite du serveur : " . ini_get('upload_max_filesize') . ').', 'err');
            } else {
                $nom = nom_fichier_propre((string) $envoi['name']);
                $type = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string) $envoi['type']) ? (string) $envoi['type'] : 'application/octet-stream';
                $st = $pdo->prepare('SELECT chemin FROM ressources WHERE app = ? AND nom = ?');
                $st->execute([$app, $nom]);
                $ancien = $st->fetchColumn();
                $chemin = ranger_fichier($envoi['tmp_name'], 'ressources');
                $pdo->prepare('INSERT INTO ressources (app, nom, type, taille, chemin, maj) VALUES (?, ?, ?, ?, ?, ?)
                               ON CONFLICT (app, nom) DO UPDATE SET type = excluded.type, taille = excluded.taille,
                               chemin = excluded.chemin, maj = excluded.maj')
                    ->execute([$app, $nom, $type, (int) filesize(chemin_stockage($chemin)), $chemin, time()]);
                effacer_fichier_stocke($ancien ?: null);
                flash("Ressource « $nom » ajoutée à l'atelier « $app ».");
            }
            rediriger('./#ressources');

        case 'supprimer_ressource':
            $st = $pdo->prepare('SELECT id, chemin FROM ressources WHERE id = ?');
            $st->execute([id_post('ressource_id')]);
            if ($r = $st->fetch()) {
                $pdo->prepare('DELETE FROM ressources WHERE id = ?')->execute([$r['id']]);
                effacer_fichier_stocke($r['chemin']);
                flash('Ressource supprimée.');
            }
            rediriger('./#ressources');

        /* ----- Réglages ----- */

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
            definir_reglage('quota_eleve_mo', (string) max(1, min(10000, (int) ($_POST['quota_eleve_mo'] ?? 100))));
            definir_reglage('fichier_max_mo', (string) max(1, min(1000, (int) ($_POST['fichier_max_mo'] ?? 20))));
            definir_reglage('surnom_modifiable', empty($_POST['surnom_modifiable']) ? '0' : '1');
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
   Téléchargements (enseignant connecté)
   ====================================================================== */

$page = (string) ($_GET['p'] ?? '');

if ($page === 'telecharger') {
    $pdo = db();
    $id = (int) ($_GET['id'] ?? 0);
    switch ($_GET['quoi'] ?? '') {
        case 'fichier':
            $st = $pdo->prepare('SELECT * FROM fichiers WHERE id = ?');
            $st->execute([$id]);
            $f = $st->fetch() ?: null;
            break;
        case 'ressource':
            $st = $pdo->prepare('SELECT * FROM ressources WHERE id = ?');
            $st->execute([$id]);
            $f = $st->fetch() ?: null;
            break;
        case 'donnee':
        case 'version':
            $table = $_GET['quoi'] === 'donnee' ? 'donnees' : 'donnees_versions';
            $st = $_GET['quoi'] === 'donnee'
                ? $pdo->prepare('SELECT * FROM donnees WHERE eleve_id = ? AND app = ? AND cle = ?')
                : $pdo->prepare('SELECT * FROM donnees_versions WHERE id = ?');
            $st->execute($_GET['quoi'] === 'donnee' ? [$id, (string) ($_GET['app'] ?? ''), (string) ($_GET['cle'] ?? '')] : [$id]);
            if ($d = $st->fetch()) {
                $nom = str_replace('/', '_', $d['app'] . '_' . $d['cle']) . '_' . date('Y-m-d_H-i', (int) $d['maj']) . '.json';
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $nom) . '"');
                echo json_encode(json_decode($d['valeur']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $f = null;
            break;
        default:
            $f = null;
    }
    if (!$f) {
        http_response_code(404);
        exit('Introuvable.');
    }
    servir_fichier($f['chemin'], $f['nom'], $f['type']);
}

if ($page === 'export') {
    exporter_classe((int) ($_GET['id'] ?? 0));
}

/* ======================================================================
   Pages
   ====================================================================== */

match ($page) {
    'classe'   => page_classe((int) ($_GET['id'] ?? 0)),
    'eleve'    => page_eleve((int) ($_GET['id'] ?? 0)),
    'suivi'    => page_suivi(),
    'parcours' => page_parcours(),
    'ateliers' => page_ateliers(),
    'etablissements' => page_etablissements(),
    'edt'      => page_edt(),
    'salle'    => page_salle((int) ($_GET['id'] ?? 0)),
    'placement' => page_placement((int) ($_GET['classe'] ?? 0), (int) ($_GET['salle'] ?? 0), (int) ($_GET['groupe'] ?? 0)),
    'fichiers' => page_fichiers(),
    'import'   => page_import(),
    default  => page_accueil(),
};

/* ---------------------------------------------------------------------- */

function page_installation(): void
{
    entete('Installation');
    ?>
    <main class="etroit">
        <h1>Centrale élèves</h1>
        <p class="sous">Première utilisation : choisissez le mot de passe administrateur.
            Il protège la gestion des classes, des codes et du travail des élèves. Au moins 10 caractères.</p>
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
    $pdo = db();
    $classes = $pdo->query('SELECT c.id, c.nom,
            (SELECT COUNT(*) FROM eleves e WHERE e.classe_id = c.id) AS nb_eleves,
            (SELECT COUNT(*) FROM eleves e WHERE e.classe_id = c.id AND e.derniere_connexion IS NOT NULL) AS nb_venus,
            (SELECT COUNT(*) FROM eleves e WHERE e.classe_id = c.id AND e.etiquette NOT GLOB \'[0-9][0-9]*\') AS nb_noms,
            (SELECT MAX(derniere_connexion) FROM eleves e WHERE e.classe_id = c.id) AS activite,
            (SELECT COALESCE(SUM(taille), 0) FROM donnees d JOIN eleves e ON e.id = d.eleve_id WHERE e.classe_id = c.id)
          + (SELECT COALESCE(SUM(taille), 0) FROM donnees_versions d JOIN eleves e ON e.id = d.eleve_id WHERE e.classe_id = c.id)
          + (SELECT COALESCE(SUM(taille), 0) FROM fichiers f JOIN eleves e ON e.id = f.eleve_id WHERE e.classe_id = c.id) AS espace
        FROM classes c ORDER BY c.nom')->fetchAll();
    $nb_eleves = array_sum(array_column($classes, 'nb_eleves'));
    $disque = taille_dossier_data();
    $ressources = $pdo->query('SELECT * FROM ressources ORDER BY app, nom')->fetchAll();
    $apps = $pdo->query("SELECT app FROM donnees UNION SELECT app FROM fichiers UNION SELECT app FROM ressources
                         UNION SELECT app FROM depots WHERE app <> '*' ORDER BY app")->fetchAll(PDO::FETCH_COLUMN);
    $api = url_base() . '/api/';
    $client = url_base() . '/js/centrale-client.js';

    entete('Tableau de bord');
    barre();
    ?>
    <main>
        <?php messages(); ?>
        <?php if ($nb_eleves): section_reinitialiser_codes(count($classes), $nb_eleves); endif; ?>
        <div class="stats">
            <div class="stat"><strong><?= count($classes) ?></strong><span>classe<?= count($classes) > 1 ? 's' : '' ?></span></div>
            <div class="stat"><strong><?= $nb_eleves ?></strong><span>élève<?= $nb_eleves > 1 ? 's' : '' ?></span></div>
            <div class="stat"><strong><?= format_taille($disque) ?></strong><span>espace utilisé sur le serveur</span></div>
            <div class="stat"><strong><?= count($apps) ?></strong><span>atelier<?= count($apps) > 1 ? 's' : '' ?> actif<?= count($apps) > 1 ? 's' : '' ?></span></div>
        </div>
        <?php liste_locale(); ?>
        <form method="post" target="_blank" class="apercu-eleve" id="form-apercu">
            <?= csrf() ?>
            <input type="hidden" name="action" value="apercu_eleve">
            <div>
                <strong>Voir comme un élève</strong>
                <span class="aide">Ouvre le portail élève avec le compte test de la classe « TEST », sans taper de code.
                    Il voit les ateliers et parcours ouverts à toutes les classes, ou à la classe TEST.</span>
            </div>
            <button class="secondaire">Ouvrir le portail élève</button>
        </form>
        <?php section_aujourdhui(); ?>

        <section>
            <div class="titre-section">
                <h2>Classes</h2>
                <form method="post" class="en-ligne">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="creer_classe">
                    <input name="nom" placeholder="ex. 5A" required maxlength="20" aria-label="Nom de la nouvelle classe">
                    <button>Créer la classe</button>
                    <a class="btn secondaire" href="./?p=import">Importer depuis Pronote</a>
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
                                · <?= (int) $c['nb_venus'] ?> connecté<?= $c['nb_venus'] > 1 ? 's' : '' ?></span>
                            <span><?= format_taille((int) $c['espace']) ?> de travail</span>
                            <?php if ($c['nb_noms']): ?>
                                <span class="alerte"><?= (int) $c['nb_noms'] ?> nom<?= $c['nb_noms'] > 1 ? 's' : '' ?> sur le serveur</span>
                            <?php endif; ?>
                            <span class="discret">Dernière activité : <?= quand($c['activite'] ? (int) $c['activite'] : null) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section id="ressources">
            <h2>Ressources des ateliers</h2>
            <p class="aide">Fichiers que vous mettez à disposition des élèves d'un atelier (modèles, consignes, images…).
                Ils les chargent avec <code>Centrale.chargerRessource("nom.ext")</code>.</p>
            <?php if ($ressources): ?>
                <div class="defile"><table>
                    <thead><tr><th>Atelier</th><th>Fichier</th><th>Taille</th><th>Mis à jour</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($ressources as $r): ?>
                        <tr>
                            <td><code><?= h($r['app']) ?></code></td>
                            <td><a href="./?p=telecharger&quoi=ressource&id=<?= (int) $r['id'] ?>"><?= h($r['nom']) ?></a></td>
                            <td><?= format_taille((int) $r['taille']) ?></td>
                            <td><?= quand((int) $r['maj']) ?></td>
                            <td class="actions"><?= bouton('supprimer_ressource', 'Supprimer', ['ressource_id' => $r['id']], 'danger', 'Supprimer cette ressource ?') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="carte formulaire-ressource">
                <?= csrf() ?>
                <input type="hidden" name="action" value="ajouter_ressource">
                <label>Atelier<input name="app" required maxlength="40" placeholder="robot-mbot" list="liste-apps"></label>
                <label>Fichier<input type="file" name="fichier" required></label>
                <button>Ajouter</button>
                <datalist id="liste-apps"><?php foreach ($apps as $a): ?><option value="<?= h($a) ?>"><?php endforeach; ?></datalist>
            </form>
        </section>

        <section>
            <h2>Brancher une application</h2>
            <p>Dans chaque application, ajoutez ces lignes. L'identifiant <code>app</code> désigne l'atelier :
                il range séparément les données de chaque application.</p>
            <pre class="code"><?= h('<script src="' . $client . '"></script>
<script>
  Centrale.init({ api: "' . $api . '", app: "robot-mbot" });

  Centrale.afficherConnexion(document.getElementById("connexion"), {
    onConnecte: async () => {
      const projet = await Centrale.charger("projet", { blocs: [] });   // reprend le travail
      // … puis à chaque modification :
      Centrale.sauver("projet", projet);                                // sauvegarde auto
      // … et quand l’élève avance :
      Centrale.progression("defi-1", { faites: 2, total: 5 });          // suivi enseignant
      Centrale.progression("defi-1", { statut: "termine" });
    }
  });
</script>') ?></pre>
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
                    <div class="deux-colonnes">
                        <label>Espace par élève (Mo)
                            <span class="aide"><?= $nb_eleves ?> élèves × ce quota ≤ espace de l'hébergement</span>
                            <input type="number" name="quota_eleve_mo" min="1" max="10000" value="<?= (int) reglage('quota_eleve_mo', '100') ?>">
                        </label>
                        <label class="case"><input type="checkbox" name="surnom_modifiable" value="1" <?= reglage('surnom_modifiable', '1') === '1' ? 'checked' : '' ?>>
                            Les élèves peuvent changer leur surnom (fin de l'identifiant, ex. 4C-LHA-<em>LoutreAgile</em>)</label>
                        <label>Taille max. d'un fichier (Mo)
                            <span class="aide">Plafond du serveur : <?= h((string) ini_get('upload_max_filesize')) ?></span>
                            <input type="number" name="fichier_max_mo" min="1" max="1000" value="<?= (int) reglage('fichier_max_mo', '20') ?>">
                        </label>
                    </div>
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
    $classe = classe_par_id($id);
    if (!$classe) {
        rediriger('./');
    }
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM depots WHERE classe_id = ? ORDER BY CASE WHEN app = '*' THEN 0 ELSE 1 END, app");
    $st->execute([$id]);
    $depots = $st->fetchAll();
    $st = $pdo->prepare('SELECT e.*,
            (SELECT COALESCE(SUM(taille), 0) FROM donnees WHERE eleve_id = e.id)
          + (SELECT COALESCE(SUM(taille), 0) FROM donnees_versions WHERE eleve_id = e.id)
          + (SELECT COALESCE(SUM(taille), 0) FROM fichiers WHERE eleve_id = e.id) AS espace
        FROM eleves e WHERE e.classe_id = ?
        ORDER BY CASE WHEN e.etiquette GLOB \'[0-9]*\' THEN CAST(e.etiquette AS INTEGER) ELSE 9999 END, e.etiquette');
    $st->execute([$id]);
    $eleves = $st->fetchAll();
    $avec_noms = array_filter($eleves, fn($e) => !est_pseudonyme($e['etiquette']));
    $quota = quota_eleve_octets();
    $nom = $classe['nom'];
    $prefixe_codes = prefixe_codes($classe);
    $noms_groupes = array_column(groupes_de($id), 'nom', 'id');

    $nouveaux = null;
    if (!empty($_SESSION['nouveaux']) && (int) $_SESSION['nouveaux']['classe_id'] === $id) {
        $nouveaux = $_SESSION['nouveaux']['codes'];
    }
    unset($_SESSION['nouveaux']); // les codes ne s'affichent qu'une fois
    $anonymisation = null;
    if (!empty($_SESSION['anonymisation']) && (int) $_SESSION['anonymisation']['classe_id'] === $id) {
        $anonymisation = $_SESSION['anonymisation']['paires'];
    }
    unset($_SESSION['anonymisation']);

    $json = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

    entete('Classe ' . $nom);
    barre();
    ?>
    <main>
        <p class="fil"><a href="./">← Toutes les classes</a></p>
        <div class="titre-classe">
            <h1>Classe <?= h($nom) ?></h1>
            <details class="renommer">
                <summary class="btn petit secondaire">Renommer</summary>
                <form method="post" class="en-ligne">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="renommer_classe">
                    <input type="hidden" name="classe_id" value="<?= $id ?>">
                    <input name="nom" value="<?= h($nom) ?>" maxlength="20" required pattern="[\p{L}0-9 _\-]{1,20}" aria-label="Nouveau nom de la classe">
                    <button>Enregistrer</button>
                </form>
                <p class="aide">Les codes déjà distribués restent valables (ils gardent l'ancien préfixe). Les nouveaux codes prendront le nouveau nom.</p>
            </details>
        </div>
        <?php messages(); ?>
        <?php liste_locale(); ?>

        <?php if ($anonymisation): ?>
            <script type="application/json" id="json-anonymisation"><?= json_encode(['classe' => $nom, 'prefixe' => prefixe_classe($nom), 'paires' => $anonymisation], $json) ?></script>
            <p class="alerte-bloc">Le fichier de correspondance (numéro ↔ nom) vient d'être téléchargé.
                Rangez-le dans un dossier privé de votre Nuage : c'est la seule copie hors de ce navigateur.
                <button type="button" class="petit secondaire" id="telecharger-anonymisation">Le télécharger à nouveau</button></p>
        <?php endif; ?>

        <?php if ($nouveaux): ?>
            <script type="application/json" id="json-nouveaux"><?= json_encode(['classe' => $nom, 'prefixe' => prefixe_classe($nom), 'codes' => $nouveaux], $json) ?></script>
            <section id="nouveaux" class="nouveaux">
                <div class="titre-section">
                    <h2>Codes générés</h2>
                    <div class="boutons">
                        <button type="button" onclick="window.print()">Imprimer les fiches</button>
                        <button type="button" class="secondaire" id="telecharger-codes">Télécharger (CSV)</button>
                    </div>
                </div>
                <p class="alerte-bloc">Ces codes ne seront <strong>plus jamais affichés</strong>. Téléchargez le CSV maintenant :
                    il contient aussi les noms, qui ne sont <strong>pas</strong> sur le serveur. Rangez-le dans un dossier privé de votre Nuage.</p>
                <div class="defile"><table>
                    <thead><tr><th>N°</th><th>Nom (sur cet ordinateur)</th><th>Code</th></tr></thead>
                    <tbody>
                    <?php foreach ($nouveaux as $n): ?>
                        <tr>
                            <td class="numero"><?= h($n['numero']) ?></td>
                            <td><?= nom_eleve($nom, $n['numero']) ?></td>
                            <td><code class="code-eleve"><?= h($n['code']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div class="fiches" aria-hidden="true">
                    <?php foreach ($nouveaux as $n): ?>
                        <div class="fiche">
                            <div class="fiche-haut"><span>Technologie · <?= h($nom) ?> · n° <?= h($n['numero']) ?></span><?= nom_eleve($nom, $n['numero'], '') ?></div>
                            <div class="fiche-code"><?= h($n['code']) ?></div>
                            <div class="fiche-bas">Mon code personnel, à ne pas partager.
                                <?php if (reglage('lien_eleves')): ?><br><?= h(reglage('lien_eleves')) ?><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($avec_noms): ?>
            <section class="carte">
                <h3>Des noms d'élèves sont encore enregistrés sur le serveur</h3>
                <p><?= count($avec_noms) ?> élève(s) de cette classe ont un nom enregistré en ligne. « Anonymiser » les remplace par
                    des numéros et télécharge la correspondance sur votre ordinateur. Les codes ne changent pas.</p>
                <?= bouton('anonymiser', 'Anonymiser la classe', ['classe_id' => $id], '', 'Remplacer les noms par des numéros ? Le fichier de correspondance va être téléchargé.') ?>
            </section>
        <?php endif; ?>

        <?php section_organisation_classe($classe); ?>
        <?php section_groupes_classe($classe, $eleves); ?>

        <section id="eleves">
            <div class="titre-section">
                <h2>Élèves (<?= count($eleves) ?>)</h2>
                <?php if ($eleves): ?>
                    <a class="btn secondaire" href="./?p=export&id=<?= $id ?>">Exporter tout le travail (ZIP)</a>
                <?php endif; ?>
            </div>
            <?php if ($eleves): ?>
                <div class="defile"><table>
                    <thead><tr><th>N°</th><th>Nom (sur cet ordinateur)</th><th>Identifiant</th><th>Dernière connexion</th><th>Espace</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($eleves as $e): $ch = ['classe_id' => $id, 'eleve_id' => $e['id']]; ?>
                        <tr class="<?= $e['actif'] ? '' : 'inactif' ?>">
                            <td class="numero"><?= est_pseudonyme($e['etiquette']) ? h($e['etiquette']) : '?' ?></td>
                            <td><?= nom_eleve($nom, $e['etiquette']) ?><?= $e['groupe_id'] && isset($noms_groupes[$e['groupe_id']]) ? ' <span class="badge-groupe">' . h($noms_groupes[$e['groupe_id']]) . '</span>' : '' ?><?= $e['actif'] ? '' : ' <span class="discret">(désactivé)</span>' ?></td>
                            <td class="identifiant"><span class="surnom" data-surnom="<?= h($e['surnom']) ?>" data-classe="<?= h($nom) ?>" data-numero="<?= h($e['etiquette']) ?>"><span class="discret"><?= h($prefixe_codes) ?>-</span><?= h($e['surnom']) ?></span>
                                <?= bouton('nouveau_surnom', '↻', $ch, 'lien-bouton', '', 'Donner un nouveau surnom au hasard') ?></td>
                            <td><?= quand($e['derniere_connexion'] ? (int) $e['derniere_connexion'] : null) ?></td>
                            <td><?= format_taille((int) $e['espace']) ?><?= jauge((int) $e['espace'], $quota) ?></td>
                            <td class="actions">
                                <a class="btn petit" href="./?p=eleve&id=<?= (int) $e['id'] ?>">Travail</a>
                                <?= bouton('regenerer', 'Nouveau code', $ch, 'secondaire', "L'ancien code de cet élève ne fonctionnera plus. Continuer ?") ?>
                                <?= bouton('basculer', $e['actif'] ? 'Désactiver' : 'Réactiver', $ch, 'secondaire') ?>
                                <?= bouton('supprimer_eleve', 'Supprimer', $ch, 'danger', 'Supprimer définitivement cet élève et tout son travail ?') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p class="vide">Aucun élève. Générez des codes ci-dessous.</p>
            <?php endif; ?>

            <?php if ($eleves): ?>
                <section class="carte" id="codes-classe">
                    <h3>Codes de connexion</h3>
                    <p class="aide">Le serveur ne garde pas les codes (seulement une empreinte) : il ne peut pas les réafficher.
                        Cet ordinateur en garde une copie quand ils sont créés ici, ou quand vous chargez votre fichier de codes (CSV).</p>
                    <p class="etat-codes" id="etat-codes">Codes connus sur cet ordinateur : vérification…</p>
                    <div class="ligne-actions">
                        <button type="button" id="codes-imprimer" disabled>Imprimer les fiches</button>
                        <button type="button" class="secondaire" id="codes-csv" disabled>Télécharger les codes (CSV)</button>
                        <form method="post" id="form-codes-manquants" hidden data-confirmer="Les élèves concernés devront utiliser leur nouveau code.">
                            <?= csrf() ?>
                            <input type="hidden" name="action" value="regenerer_classe">
                            <input type="hidden" name="classe_id" value="<?= $id ?>">
                            <input type="hidden" name="eleves" value="">
                            <button class="secondaire">Nouveaux codes pour les élèves sans code ici</button>
                        </form>
                        <form method="post" data-confirmer="Tous les codes de la classe vont changer : les anciens ne fonctionneront plus. Le travail des élèves est conservé.">
                            <?= csrf() ?>
                            <input type="hidden" name="action" value="regenerer_classe">
                            <input type="hidden" name="classe_id" value="<?= $id ?>">
                            <input type="hidden" name="eleves" value="tous">
                            <button class="danger">Nouveaux codes pour toute la classe</button>
                        </form>
                    </div>
                    <div class="fiches" id="fiches-classe" aria-hidden="true"></div>
                    <script type="application/json" id="json-codes-classe"><?= json_encode([
                        'classe' => $nom, 'classe_id' => $id, 'prefixe' => prefixe_classe($nom), 'csrf' => $_SESSION['csrf'],
                        'etablissement' => $classe['etablissement_id'] ? (etablissement_par_id((int) $classe['etablissement_id'])['nom'] ?? '') : '',
                        'lien' => reglage('lien_eleves', ''),
                        'eleves' => array_map(fn($e) => ['id' => (int) $e['id'], 'numero' => $e['etiquette'], 'actif' => (bool) $e['actif']], $eleves),
                    ], $json) ?></script>
                </section>
            <?php endif; ?>

            <form method="post" class="carte" id="form-generer" data-classe="<?= h($nom) ?>">
                <?= csrf() ?>
                <input type="hidden" name="action" value="generer">
                <input type="hidden" name="classe_id" value="<?= $id ?>">
                <h3>Générer des codes</h3>
                <div class="deux-colonnes">
                    <label>Noms des élèves, un par ligne
                        <span class="aide">Ils restent sur cet ordinateur : le serveur ne reçoit que leur nombre et attribue
                            des numéros (01, 02…). Ils apparaîtront sur les fiches et dans le CSV.</span>
                        <textarea id="noms-locaux" rows="6" placeholder="Léa D.&#10;Noah M.&#10;…"></textarea>
                    </label>
                    <label>… ou seulement un nombre d'élèves
                        <span class="aide">Les fiches porteront juste le numéro.</span>
                        <input type="number" name="nombre" min="1" max="60" placeholder="ex. 28">
                    </label>
                </div>
                <button>Générer les codes</button>
            </form>
        </section>

        <section id="depots">
            <h2>Dépôts Nuage (facultatif)</h2>
            <p class="aide">Le travail est sauvegardé sur ce serveur. Un lien de dépôt Nuage reste utile pour faire rendre
                un fichier en dehors des applications. Dans Nuage : Partager → Lien de partage → « Dépôt de fichiers ».</p>
            <?php if ($depots): ?>
                <div class="defile"><table>
                    <thead><tr><th>Atelier</th><th>Lien</th><th>Mot de passe</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($depots as $d): ?>
                        <tr>
                            <td><code><?= h($d['app']) ?></code><?= $d['app'] === '*' ? ' <span class="discret">(tous)</span>' : '' ?></td>
                            <td class="lien"><a href="<?= h($d['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($d['url']) ?></a></td>
                            <td><?= $d['mdp'] !== '' ? '<code>' . h($d['mdp']) . '</code>' : '<span class="discret">aucun</span>' ?></td>
                            <td class="actions"><?= bouton('supprimer_depot', 'Supprimer', ['classe_id' => $id, 'depot_id' => $d['id']], 'danger', 'Supprimer ce lien de dépôt ?') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
            <form method="post" class="carte formulaire-depot">
                <?= csrf() ?>
                <input type="hidden" name="action" value="ajouter_depot">
                <input type="hidden" name="classe_id" value="<?= $id ?>">
                <label>Atelier<input name="app" value="*" maxlength="40" required><span class="aide">* = tous</span></label>
                <label class="large">Lien de dépôt Nuage<input name="url" type="url" required placeholder="https://nuage03.apps.education.fr/s/…"></label>
                <label>Mot de passe du lien<input name="mdp" maxlength="100" autocomplete="off" placeholder="facultatif"></label>
                <button>Ajouter / remplacer</button>
            </form>
        </section>

        <section class="zone-danger ligne-actions">
            <?= bouton('vider_travail', 'Effacer le travail de la classe', ['classe_id' => $id], 'danger',
                "Effacer toutes les données et tous les fichiers des élèves de {$nom} ? Pensez à exporter le ZIP avant. Les codes restent valables.") ?>
            <?= bouton('supprimer_classe', 'Supprimer la classe', ['classe_id' => $id], 'danger',
                "Supprimer la classe {$nom}, ses codes et tout le travail de ses élèves ? Cette action est définitive.") ?>
        </section>
    </main>
    <?php
    pied();
}

function page_eleve(int $id): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT e.*, c.nom AS classe FROM eleves e JOIN classes c ON c.id = e.classe_id WHERE e.id = ?');
    $st->execute([$id]);
    $eleve = $st->fetch();
    if (!$eleve) {
        rediriger('./');
    }
    $st = $pdo->prepare('SELECT app, cle, taille, maj FROM donnees WHERE eleve_id = ? ORDER BY app, cle');
    $st->execute([$id]);
    $donnees = $st->fetchAll();
    $st = $pdo->prepare('SELECT id, app, cle, taille, maj FROM donnees_versions WHERE eleve_id = ? ORDER BY maj DESC');
    $st->execute([$id]);
    $versions = [];
    foreach ($st->fetchAll() as $v) {
        $versions[$v['app'] . "\0" . $v['cle']][] = $v;
    }
    $st = $pdo->prepare('SELECT * FROM fichiers WHERE eleve_id = ? ORDER BY app, nom');
    $st->execute([$id]);
    $fichiers = $st->fetchAll();

    $par_app = [];
    foreach ($donnees as $d) {
        $par_app[$d['app']]['donnees'][] = $d;
    }
    foreach ($fichiers as $f) {
        $par_app[$f['app']]['fichiers'][] = $f;
    }
    ksort($par_app);
    $usage = usage_eleve($id);
    $quota = quota_eleve_octets();
    $titre = $eleve['classe'] . ' · ' . $eleve['etiquette'];

    entete('Élève ' . $titre);
    barre();
    ?>
    <main>
        <p class="fil"><a href="./?p=classe&id=<?= (int) $eleve['classe_id'] ?>">← Classe <?= h($eleve['classe']) ?></a></p>
        <h1><?= h($eleve['classe']) ?> · n° <?= h($eleve['etiquette']) ?> — <?= nom_eleve($eleve['classe'], $eleve['etiquette'], 'nom non chargé') ?></h1>
        <p class="sous">Dernière connexion : <?= quand($eleve['derniere_connexion'] ? (int) $eleve['derniere_connexion'] : null) ?>
            · Espace : <?= format_taille($usage) ?> sur <?= format_taille($quota) ?></p>
        <?= jauge($usage, $quota) ?>
        <?php messages(); ?>
        <?php section_avancement_eleve($id); ?>
        <?php section_connexions_eleve($id); ?>

        <?php if (!$par_app): ?>
            <p class="vide" style="margin-top:24px">Aucun travail enregistré pour l'instant.</p>
        <?php endif; ?>

        <?php foreach ($par_app as $app => $contenu): ?>
            <section class="groupe-app">
                <h2>Atelier <code><?= h($app) ?></code></h2>
                <?php if (!empty($contenu['donnees'])): ?>
                    <div class="defile"><table>
                        <thead><tr><th>Donnée</th><th>Taille</th><th>Sauvegardée le</th><th>Historique</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($contenu['donnees'] as $d): $vs = $versions[$d['app'] . "\0" . $d['cle']] ?? []; ?>
                            <tr>
                                <td><a href="./?p=telecharger&quoi=donnee&id=<?= $id ?>&app=<?= rawurlencode($d['app']) ?>&cle=<?= rawurlencode($d['cle']) ?>"><code><?= h($d['cle']) ?></code></a></td>
                                <td><?= format_taille((int) $d['taille']) ?></td>
                                <td><?= quand((int) $d['maj']) ?></td>
                                <td>
                                    <?php if ($vs): ?>
                                        <details class="versions">
                                            <summary><?= count($vs) ?> version<?= count($vs) > 1 ? 's' : '' ?> précédente<?= count($vs) > 1 ? 's' : '' ?></summary>
                                            <ul>
                                            <?php foreach ($vs as $v): ?>
                                                <li><a href="./?p=telecharger&quoi=version&id=<?= (int) $v['id'] ?>"><?= quand((int) $v['maj']) ?></a>
                                                    (<?= format_taille((int) $v['taille']) ?>)
                                                    <?= bouton('restaurer_version', 'Restaurer', ['eleve_id' => $id, 'version_id' => $v['id']], 'secondaire',
                                                        'Remplacer la version actuelle par celle du ' . quand((int) $v['maj']) . ' ? (La version actuelle sera gardée dans l\'historique.)') ?></li>
                                            <?php endforeach; ?>
                                            </ul>
                                        </details>
                                    <?php else: ?><span class="discret">—</span><?php endif; ?>
                                </td>
                                <td class="actions"><?= bouton('supprimer_donnee', 'Supprimer', ['eleve_id' => $id, 'app' => $d['app'], 'cle' => $d['cle']], 'danger', 'Supprimer cette donnée et son historique ?') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
                <?php if (!empty($contenu['fichiers'])): ?>
                    <div class="defile" style="margin-top:12px"><table>
                        <thead><tr><th>Fichier</th><th>Taille</th><th>Envoyé le</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($contenu['fichiers'] as $f): ?>
                            <tr>
                                <td><a href="./?p=telecharger&quoi=fichier&id=<?= (int) $f['id'] ?>"><?= h($f['nom']) ?></a></td>
                                <td><?= format_taille((int) $f['taille']) ?></td>
                                <td><?= quand((int) $f['maj']) ?></td>
                                <td class="actions"><?= bouton('supprimer_fichier', 'Supprimer', ['eleve_id' => $id, 'fichier_id' => $f['id']], 'danger', 'Supprimer ce fichier ?') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </main>
    <?php
    pied();
}

/** ZIP de tout le travail d'une classe : <classe>-<numéro>/<atelier>/donnees|fichiers/… */
function exporter_classe(int $id): never
{
    $classe = classe_par_id($id);
    if (!$classe || !class_exists('ZipArchive')) {
        http_response_code(404);
        exit('Export impossible.');
    }
    $pdo = db();
    $dossier_tmp = DOSSIER_DATA . '/tmp';
    if (!is_dir($dossier_tmp)) {
        mkdir($dossier_tmp, 0700, true);
    }
    $chemin_zip = $dossier_tmp . '/export_' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($chemin_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $st = $pdo->prepare('SELECT id, etiquette FROM eleves WHERE classe_id = ?');
    $st->execute([$id]);
    $prefixe = prefixe_classe($classe['nom']);
    foreach ($st->fetchAll() as $e) {
        $dossier = $prefixe . '-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $e['etiquette']);
        $sd = $pdo->prepare('SELECT app, cle, valeur FROM donnees WHERE eleve_id = ?');
        $sd->execute([$e['id']]);
        foreach ($sd->fetchAll() as $d) {
            $zip->addFromString("$dossier/{$d['app']}/donnees/{$d['cle']}.json",
                (string) json_encode(json_decode($d['valeur']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $sf = $pdo->prepare('SELECT app, nom, chemin FROM fichiers WHERE eleve_id = ?');
        $sf->execute([$e['id']]);
        foreach ($sf->fetchAll() as $f) {
            if (is_file(chemin_stockage($f['chemin']))) {
                $zip->addFile(chemin_stockage($f['chemin']), "$dossier/{$f['app']}/fichiers/{$f['nom']}");
            }
        }
    }
    if ($zip->numFiles === 0) {
        $zip->addFromString('LISEZMOI.txt', "Aucun travail enregistré pour la classe {$classe['nom']}.\n");
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($chemin_zip));
    header('Content-Disposition: attachment; filename="travail_' . $prefixe . '_' . date('Y-m-d') . '.zip"');
    readfile($chemin_zip);
    unlink($chemin_zip);
    exit;
}

/* ---------------------------------------------------------------------- */

/** Tout en haut du tableau de bord : nouveaux codes pour TOUS les élèves + liste globale (tous établissements). */
function section_reinitialiser_codes(int $nb_classes, int $nb_eleves): void
{
    $nb_etabs = (int) db()->query('SELECT COUNT(DISTINCT etablissement_id) FROM classes WHERE etablissement_id IS NOT NULL')->fetchColumn();
    ?>
    <section class="reinit" id="reinit">
        <button type="button" class="danger" id="reinit-ouvrir">Réinitialiser tous les codes et télécharger la liste</button>
        <div class="reinit-confirmation" id="reinit-confirmation" hidden>
            <p><strong>Attention : les codes de connexion des <?= $nb_eleves ?> élèves vont tous être remplacés</strong>
                (<?= $nb_classes ?> classe<?= $nb_classes > 1 ? 's' : '' ?><?= $nb_etabs ? ', ' . $nb_etabs . ' établissement' . ($nb_etabs > 1 ? 's' : '') : '' ?>).</p>
            <ul>
                <li>Les anciens codes ne fonctionneront plus, et les élèves connectés seront déconnectés.</li>
                <li>Il faudra redistribuer les nouvelles fiches à tous les élèves.</li>
                <li>Le travail et l'avancement des élèves sont conservés.</li>
                <li>Nouveau format : classe, code de l'établissement, puis le code personnel (ex. 4C-LHA-9943-kfmz-tqpr).</li>
                <li id="reinit-noms"></li>
            </ul>
            <p>Un seul fichier sera téléchargé : tous les établissements, toutes les classes, avec noms, codes et PAP / PPS / PAI.
                Rangez-le dans un dossier privé de votre Nuage.</p>
            <div class="ligne-actions">
                <button type="button" class="danger" id="reinit-confirmer">Oui, remplacer les <?= $nb_eleves ?> codes</button>
                <button type="button" class="secondaire" id="reinit-annuler">Annuler</button>
            </div>
        </div>
        <div class="nouveaux" id="reinit-resultat" hidden>
            <div class="titre-section">
                <h2 id="reinit-titre">Nouveaux codes</h2>
                <div class="boutons">
                    <button type="button" id="reinit-imprimer">Imprimer toutes les fiches</button>
                    <button type="button" class="secondaire" id="reinit-telecharger">Télécharger la liste à nouveau</button>
                </div>
            </div>
            <p class="alerte-bloc">La liste a été téléchargée : elle contient les noms et les codes. Rangez-la dans un dossier privé de votre Nuage.</p>
            <div class="fiches" id="fiches-toutes" aria-hidden="true"></div>
        </div>
        <script type="application/json" id="json-reinit"><?= json_encode(['csrf' => $_SESSION['csrf'], 'nb_eleves' => $nb_eleves,
            'lien' => reglage('lien_eleves', '')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </section>
    <?php
}

function liste_locale(): void
{
    ?>
    <div class="liste-locale">
        <span class="etat-liste">Noms non chargés sur cet ordinateur</span>
        <label class="bouton-fichier">Charger mon fichier de noms (CSV)<input type="file" accept=".csv,text/csv" class="charger-liste"></label>
        <button type="button" class="lien-bouton exporter-liste">Télécharger la liste</button>
        <button type="button" class="lien-bouton oublier-liste" hidden>Oublier sur cet ordinateur</button>
        <span class="discret">Les noms ne sont jamais envoyés au serveur.</span>
    </div>
    <?php
}

function messages(): void
{
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<p class="message ' . ($type === 'err' ? 'erreur' : 'succes') . '" role="status">' . h($msg) . '</p>';
    }
    unset($_SESSION['flash']);
}

function barre(string $actif = 'classes'): void
{
    $liens = ['classes' => ['./', 'Classes'], 'edt' => ['./?p=edt', 'Emploi du temps'], 'etablissements' => ['./?p=etablissements', 'Établissements'],
              'ateliers' => ['./?p=ateliers', 'Ateliers'], 'parcours' => ['./?p=parcours', 'Parcours'], 'suivi' => ['./?p=suivi', 'Suivi'],
              'fichiers' => ['./?p=fichiers', 'Fichiers']];
    ?>
    <header class="barre">
        <nav>
            <a href="./" class="marque">Centrale élèves</a>
            <?php foreach ($liens as $cle => [$url, $libelle]): ?>
                <a href="<?= $url ?>" class="onglet<?= $cle === $actif ? ' actif' : '' ?>"><?= $libelle ?></a>
            <?php endforeach; ?>
        </nav>
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
    $v = @filemtime(__DIR__ . '/admin.css') ?: 1;
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($titre) ?> · Centrale élèves</title>
<link rel="stylesheet" href="admin.css?v=<?= $v ?>">
</head>
<body>
    <?php
}

function pied(): void
{
    $v = @filemtime(__DIR__ . '/admin.js') ?: 1;
    // Renommages de classes : chaque ordinateur déplace lui-même ses noms locaux (voir admin.js)
    if (!empty($_SESSION['admin'])) {
        echo "\n<script type=\"application/json\" id=\"json-renommages\">"
            . json_encode(renommages_classes(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        // Classes du serveur (établissement + numéros) : pour la liste globale « Télécharger la liste »
        $classes = [];
        foreach (db()->query('SELECT c.nom, COALESCE(e.nom, \'\') AS etablissement,
                c.etablissement_id, c.id FROM classes c LEFT JOIN etablissements e ON e.id = c.etablissement_id') as $c) {
            $st = db()->prepare('SELECT e.etiquette, e.surnom, COALESCE(g.nom, \'\') AS groupe FROM eleves e LEFT JOIN groupes g ON g.id = e.groupe_id WHERE e.classe_id = ?');
            $st->execute([$c['id']]);
            $prefixe = prefixe_codes($c);
            $classes[] = ['nom' => $c['nom'], 'etablissement' => $c['etablissement'],
                          'eleves' => array_map(fn($e) => [$e['etiquette'], $prefixe . '-' . $e['surnom'], $e['groupe']], $st->fetchAll())];
        }
        echo "\n<script type=\"application/json\" id=\"json-classes\">"
            . json_encode($classes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    }
    echo "\n<script src=\"admin.js?v=$v\"></script>\n</body>\n</html>\n";
}
