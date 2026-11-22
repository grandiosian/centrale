<?php
/**
 * Centrale élèves — travail hors ligne (ordinateurs portables sans internet, élèves avec PAP…)
 * et écriture commune de la progression (applications, rendus hors ligne, validation de l'enseignant).
 *
 * 1. Préparer : l'enseignant choisit un atelier et un ou plusieurs élèves. La Centrale prend le fichier autonome de
 *    l'application (TechnoStudio : miroir/technostudio/dist/technostudio-autonome.html, un seul fichier qui s'ouvre
 *    en double-clic sous Windows, macOS ou Linux, sans installation ni serveur) et y ajoute :
 *      - window.CENTRALE_HORS_LIGNE : l'élève (classe, numéro, identifiant — rien de nominatif), l'atelier, le fichier
 *        de l'atelier s'il est transféré par la Centrale, et une clé AES-256 propre à ce fichier ;
 *      - public/js/centrale-hors-ligne.js : un window.Centrale qui garde tout sur l'ordinateur.
 *    Une ligne de hors_ligne_exports garde la clé : elle ne sort jamais du serveur autrement que dans ce fichier.
 * 2. Rendre : à la fin, l'élève enregistre un fichier « .tvrendu » chiffré (AES-256-GCM, données associées = n° du
 *    fichier préparé), qu'il envoie à l'enseignant (ENT, clé USB).
 * 3. Importer : l'enseignant le dépose (Suivi, ou page « Travail hors ligne ») ; la Centrale le déchiffre, garde son
 *    contenu (hors_ligne_rendus) et l'enregistre comme si l'élève avait travaillé en ligne : progression (meilleur
 *    résultat gardé), données de l'application (ancienne valeur gardée dans l'historique), constats de compétences.
 *
 * Validation de l'enseignant (Suivi : clic sur une case) : progression.valide_prof (date), progression.avant_prof
 * (état de l'application avant la validation, JSON, '' = pas de ligne) pour pouvoir l'annuler.
 */
declare(strict_types=1);

const FORMAT_HORS_LIGNE = 'centrale.hors-ligne';
const TAILLE_MAX_RENDU = 40 * 1024 * 1024;   // fichiers joints compris (4 Mo chacun au plus côté élève)

function creer_tables_hors_ligne(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS hors_ligne_exports (
        id         TEXT PRIMARY KEY,                    -- 16 caractères hexadécimaux, écrit en clair dans les rendus
        eleve_id   INTEGER NOT NULL REFERENCES eleves(id) ON DELETE CASCADE,
        app        TEXT NOT NULL,
        atelier    TEXT NOT NULL,
        titre      TEXT NOT NULL DEFAULT '',
        cle        TEXT NOT NULL,                       -- clé AES-256 (hexadécimal)
        cree_le    INTEGER NOT NULL,
        constats   TEXT NOT NULL DEFAULT '{}'           -- constats déjà enregistrés : { activite: signature }
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hl_exports_eleve ON hors_ligne_exports (eleve_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS hors_ligne_rendus (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        export_id  TEXT NOT NULL REFERENCES hors_ligne_exports(id) ON DELETE CASCADE,
        rendu_le   INTEGER NOT NULL,                    -- heure de l'ordinateur de l'élève
        recu_le    INTEGER NOT NULL,
        empreinte  TEXT NOT NULL,                       -- sha1 du fichier : le même fichier n'est importé qu'une fois
        contenu    TEXT NOT NULL,                       -- JSON déchiffré (classe + numéro seulement)
        bilan      TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_hl_rendus_empreinte ON hors_ligne_rendus (empreinte)');
    ajouter_colonne($pdo, 'hors_ligne_exports', 'parcours_id', 'INTEGER NOT NULL DEFAULT 0');   // fichier d'un parcours (un par application)
    ajouter_colonne($pdo, 'progression', 'valide_prof', 'INTEGER');     // validée par l'enseignant (date)
    ajouter_colonne($pdo, 'progression', 'avant_prof', 'TEXT');         // état avant la validation (JSON) ; '' = pas de ligne
    ajouter_colonne($pdo, 'progression', 'hors_ligne', 'INTEGER');      // dernier rendu hors ligne enregistré (date)
}

/* ======================================================================
   Écriture de la progression (api/progression.php, rendus hors ligne)
   ====================================================================== */

/**
 * Enregistre un envoi d'avancement { titre?, statut?, faites?, total?, score?, score_max?, details?, mesures?, constats? }
 * en gardant le MEILLEUR résultat. $origine : 'app' (en ligne) ou 'hors-ligne'. $constats : false pour ne pas les
 * enregistrer. Lève InvalidArgumentException (code HTTP en code) si l'envoi est refusé.
 * Renvoie ['ligne' => ligne finale, 'constats' => nombre enregistré].
 */
function ecrire_avancement_eleve(int $eleve_id, string $app, string $activite, array $e, string $origine = 'app', bool $constats = true, ?int $quand = null): array
{
    $pdo = db();
    $statut = (string) ($e['statut'] ?? 'commence');
    if (!in_array($statut, ['commence', 'en-cours', 'termine'], true)) {
        throw new InvalidArgumentException('Statut attendu : commence ou termine.', 400);
    }
    $statut = $statut === 'termine' ? 'termine' : 'commence';

    $entier = fn($k) => isset($e[$k]) && is_numeric($e[$k]) ? max(0, min(100000, (int) $e[$k])) : null;
    $reel = fn($k) => isset($e[$k]) && is_numeric($e[$k]) ? max(-1e9, min(1e9, (float) $e[$k])) : null;
    $faites = $entier('faites');
    $total = $entier('total');
    $score = $reel('score');
    $score_max = $reel('score_max');
    $titre = mb_substr(trim((string) ($e['titre'] ?? '')), 0, 120);
    $details = null;
    if (array_key_exists('details', $e) && $e['details'] !== null) {
        $details = json_encode($e['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($details) > 20000) {
            throw new InvalidArgumentException('Détails trop longs (20 Ko maximum).', 413);
        }
    }

    // Mesures (monitoring) : entiers positifs bornés, détail par question limité
    $mesures = null;
    if (is_array($e['mesures'] ?? null)) {
        $m = $e['mesures'];
        $n = fn($k, $max = 1000000) => isset($m[$k]) && is_numeric($m[$k]) ? max(0, min($max, (int) $m[$k])) : null;
        $detail = [];
        foreach (array_slice(is_array($m['detail'] ?? null) ? $m['detail'] : [], 0, 200) as $q) {
            if (!is_array($q)) {
                continue;
            }
            $x = ['q' => mb_substr(trim((string) ($q['q'] ?? '')), 0, 120), 'essais' => max(0, min(10000, (int) ($q['essais'] ?? 0)))];
            foreach (['okA' => 10000, 'delai' => 86400] as $k => $max) {
                if (isset($q[$k]) && is_numeric($q[$k])) {
                    $x[$k] = max(0, min($max, (int) $q[$k]));
                }
            }
            foreach (['ok1', 'revele'] as $k) {
                if (isset($q[$k])) {
                    $x[$k] = (bool) $q[$k];
                }
            }
            $detail[] = $x;
        }
        $mesures = ['temps_actif' => $n('temps_actif'), 'essais' => $n('essais'), 'questions' => $n('questions', 10000),
                    'premier_coup' => $n('premier_coup', 10000), 'revelees' => $n('revelees', 10000), 'delai_median' => $n('delai_median', 86400),
                    'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null];
    }

    $st = $pdo->prepare('SELECT * FROM progression WHERE eleve_id = ? AND app = ? AND activite = ?');
    $st->execute([$eleve_id, $app, $activite]);
    $ancien = $st->fetch() ?: null;

    $max = fn($a, $b) => $a === null ? $b : ($b === null ? $a : max($a, $b));
    $maintenant = time();
    $quand = $quand !== null ? min($quand, $maintenant) : $maintenant;
    $final = [
        'titre'     => $titre !== '' ? $titre : ($ancien['titre'] ?? ''),
        'statut'    => ($statut === 'termine' || ($ancien['statut'] ?? '') === 'termine') ? 'termine' : 'commence',
        'faites'    => $max($ancien ? ($ancien['faites'] === null ? null : (int) $ancien['faites']) : null, $faites),
        'total'     => $total ?? ($ancien && $ancien['total'] !== null ? (int) $ancien['total'] : null),
        'score'     => $max($ancien ? ($ancien['score'] === null ? null : (float) $ancien['score']) : null, $score),
        'score_max' => $score_max ?? ($ancien && $ancien['score_max'] !== null ? (float) $ancien['score_max'] : null),
        'details'   => $details ?? ($ancien['details'] ?? null),
        'debut'     => $ancien ? min((int) $ancien['debut'], $quand) : $quand,
        'fin'       => $ancien && $ancien['fin'] !== null ? (int) $ancien['fin'] : null,
    ];
    if ($final['statut'] === 'termine' && $final['fin'] === null) {
        $final['fin'] = $quand;
    }
    // Étape validée par l'enseignant : elle reste terminée, mais on suit ce que fait l'application (pour une annulation)
    $avant_prof = $ancien['avant_prof'] ?? null;
    if ($ancien && $ancien['valide_prof'] !== null) {
        $a = json_decode((string) $avant_prof, true) ?: ['statut' => 'commence', 'faites' => null, 'fin' => null];
        $a['statut'] = ($statut === 'termine' || ($a['statut'] ?? '') === 'termine') ? 'termine' : 'commence';
        $a['faites'] = $max(isset($a['faites']) ? (int) $a['faites'] : null, $faites);
        if ($a['statut'] === 'termine' && empty($a['fin'])) {
            $a['fin'] = $quand;
        }
        $avant_prof = json_encode($a);
    }

    $pdo->prepare('INSERT INTO progression (eleve_id, app, activite, titre, statut, faites, total, score, score_max, details, debut, maj, fin, avant_prof, hors_ligne)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON CONFLICT (eleve_id, app, activite) DO UPDATE SET titre = excluded.titre, statut = excluded.statut,
                   faites = excluded.faites, total = excluded.total, score = excluded.score, score_max = excluded.score_max,
                   details = excluded.details, maj = excluded.maj, fin = excluded.fin, avant_prof = excluded.avant_prof,
                   hors_ligne = COALESCE(excluded.hors_ligne, progression.hors_ligne)')
        ->execute([$eleve_id, $app, $activite, $final['titre'], $final['statut'], $final['faites'], $final['total'],
            $final['score'], $final['score_max'], $final['details'], $final['debut'], $maintenant, $final['fin'], $avant_prof,
            $origine === 'hors-ligne' ? $maintenant : null]);

    if ($mesures !== null && array_filter($mesures, fn($x) => $x !== null)) {
        // Un champ absent ou invalide garde sa valeur précédente
        $pdo->prepare('UPDATE progression SET temps_actif = COALESCE(?, temps_actif), essais = COALESCE(?, essais), questions = COALESCE(?, questions),
                       premier_coup = COALESCE(?, premier_coup), revelees = COALESCE(?, revelees), delai_median = COALESCE(?, delai_median),
                       mesures = COALESCE(?, mesures) WHERE eleve_id = ? AND app = ? AND activite = ?')
            ->execute([$mesures['temps_actif'], $mesures['essais'], $mesures['questions'], $mesures['premier_coup'], $mesures['revelees'],
                $mesures['delai_median'], $mesures['detail'], $eleve_id, $app, $activite]);
    }

    // Constats de compétences joints par l'application : [{ competence, reussi, indices, essais, preuve } | { competence, niveau }]
    $n = $constats && is_array($e['constats'] ?? null) ? enregistrer_constats($eleve_id, $app, $activite, $e['constats']) : 0;
    return ['ligne' => $final + ['activite' => $activite, 'maj' => $maintenant], 'constats' => $n];
}

/**
 * L'enseignant valide une activité (étape terminée, même sans trace de l'application), ou annule sa validation.
 * Renvoie true si quelque chose a changé.
 */
function valider_activite_prof(int $eleve_id, string $app, string $activite, string $titre = '', ?int $total = null, bool $annuler = false): bool
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM progression WHERE eleve_id = ? AND app = ? AND activite = ?');
    $st->execute([$eleve_id, $app, $activite]);
    $p = $st->fetch() ?: null;
    $maintenant = time();
    if ($annuler) {
        if (!$p || $p['valide_prof'] === null) {
            return false;
        }
        $a = json_decode((string) $p['avant_prof'], true);
        if (!is_array($a)) {   // la ligne n'existait que par la validation
            $pdo->prepare('DELETE FROM progression WHERE eleve_id = ? AND app = ? AND activite = ?')->execute([$eleve_id, $app, $activite]);
            return true;
        }
        $statut = ($a['statut'] ?? '') === 'termine' ? 'termine' : 'commence';
        $pdo->prepare('UPDATE progression SET statut = ?, faites = ?, fin = ?, valide_prof = NULL, avant_prof = NULL, maj = ?
                       WHERE eleve_id = ? AND app = ? AND activite = ?')
            ->execute([$statut, isset($a['faites']) ? (int) $a['faites'] : null, $statut === 'termine' ? ((int) ($a['fin'] ?? 0) ?: $maintenant) : null,
                $maintenant, $eleve_id, $app, $activite]);
        return true;
    }
    if ($p && $p['valide_prof'] !== null) {
        return false;
    }
    if ($p) {
        $avant = json_encode(['statut' => $p['statut'], 'faites' => $p['faites'] === null ? null : (int) $p['faites'], 'fin' => $p['fin'] === null ? null : (int) $p['fin']]);
        $tot = $p['total'] !== null ? (int) $p['total'] : $total;
        $pdo->prepare('UPDATE progression SET statut = ?, faites = ?, total = ?, fin = COALESCE(fin, ?), valide_prof = ?, avant_prof = ?, maj = ?
                       WHERE eleve_id = ? AND app = ? AND activite = ?')
            ->execute(['termine', $tot !== null ? max((int) $p['faites'], $tot) : $p['faites'], $tot, $maintenant, $maintenant, $avant, $maintenant,
                $eleve_id, $app, $activite]);
    } else {
        $pdo->prepare("INSERT INTO progression (eleve_id, app, activite, titre, statut, faites, total, debut, maj, fin, valide_prof, avant_prof)
                       VALUES (?, ?, ?, ?, 'termine', ?, ?, ?, ?, ?, ?, '')")
            ->execute([$eleve_id, $app, $activite, mb_substr($titre, 0, 120), $total, $total, $maintenant, $maintenant, $maintenant, $maintenant]);
    }
    return true;
}

/* ======================================================================
   Fichiers autonomes des applications
   ====================================================================== */

/** Applications qui ont un fichier autonome (une page qui marche ouverte en double-clic) : app => chemin relatif à public/.
    Chaque application utilise le window.Centrale déjà présent (Centrale.horsLigne) au lieu de charger centrale-client.js. */
function fichiers_autonomes(): array
{
    return [
        'technostudio' => 'miroir/technostudio/dist/technostudio-autonome.html',
        'netsim'       => 'miroir/netsim/index.html',
        'etabli3d'     => 'miroir/etabli3d/index.html',
    ];
}

function chemin_fichier_autonome(string $app): ?string
{
    $rel = fichiers_autonomes()[$app] ?? null;
    $f = $rel !== null ? __DIR__ . '/../public/' . $rel : null;
    return $f !== null && is_file($f) ? $f : null;
}

/** Ateliers qu'on peut préparer hors ligne : [app => [atelier => ligne du catalogue]]. */
function ateliers_hors_ligne(): array
{
    $res = [];
    foreach (array_keys(fichiers_autonomes()) as $app) {
        if (chemin_fichier_autonome($app) === null) {
            continue;
        }
        foreach (catalogue_ateliers($app, false) as $a) {
            $res[$app][$a['atelier']] = $a;
        }
    }
    return $res;
}

/** Nom de l'application pour l'élève (« TechnoStudio »), d'après le registre des ateliers. */
function nom_application_hl(string $app): string
{
    $st = db()->prepare('SELECT nom FROM ateliers WHERE app = ?');
    $st->execute([$app]);
    $n = (string) ($st->fetchColumn() ?: '');
    return $n !== '' ? $n : ['technostudio' => 'TechnoStudio', 'netsim' => 'NetSim', 'etabli3d' => 'Établi 3D'][$app] ?? $app;
}

function nom_propre_hl(string $s): string
{
    $s = class_exists('Normalizer') ? (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($s, Normalizer::FORM_D))
        : strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c',
                     'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Ç' => 'C', 'œ' => 'oe']);
    return trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');
}

function eleve_hors_ligne(int $eleve_id): array
{
    $st = db()->prepare('SELECT e.id, e.etiquette, c.nom AS classe FROM eleves e JOIN classes c ON c.id = e.classe_id WHERE e.id = ?');
    $st->execute([$eleve_id]);
    $eleve = $st->fetch();
    if (!$eleve) {
        throw new RuntimeException('Élève introuvable.');
    }
    return $eleve;
}

/** Fichiers des ateliers transférés par la Centrale (à mettre dans la page : pas d'internet pour les demander). */
function ateliers_transferes_hl(string $app, array $ids): array
{
    $res = [];
    $cat = ateliers_hors_ligne()[$app] ?? [];
    foreach (array_unique($ids) as $id) {
        if (($cat[$id]['source'] ?? '') !== 'centrale') {
            continue;
        }
        [$j, , $err] = lire_atelier_centrale($app, $id);
        if ($j === null) {
            throw new RuntimeException($err);
        }
        $res[$id] = atelier_sans_evaluation($j);   // jamais l'évaluation dans un fichier emporté
    }
    return $res;
}

/** Nouvelle ligne hors_ligne_exports : → [id, clé brute]. */
function creer_export_hl(int $eleve_id, string $app, string $atelier, string $titre, int $parcours_id = 0): array
{
    $id = bin2hex(random_bytes(8));
    $cle = random_bytes(32);
    db()->prepare('INSERT INTO hors_ligne_exports (id, eleve_id, app, atelier, titre, cle, cree_le, parcours_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $eleve_id, $app, $atelier, $titre, bin2hex($cle), time(), $parcours_id]);
    return [$id, $cle];
}

/** Page autonome de l'application avec la configuration hors ligne et le module local (avant tout script de l'application). */
function page_autonome_hl(string $app, array $config, string $titre_page): string
{
    $source = chemin_fichier_autonome($app);
    if ($source === null) {
        throw new RuntimeException('Le fichier autonome de « ' . nom_application_hl($app) . ' » n’est pas sur ce serveur (copie de l’application à reconstruire).');
    }
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    $module = str_replace('</script', '<\/script', (string) file_get_contents(__DIR__ . '/../public/js/centrale-hors-ligne.js'));
    $ajout = "<script>window.CENTRALE_HORS_LIGNE = $json;</script>\n<script>\n$module\n</script>\n";
    $html = (string) file_get_contents($source);
    $html = preg_replace('#<title>(.*?)</title>#s', '<title>' . htmlspecialchars($titre_page) . '</title>', $html, 1) ?? $html;
    // avant les scripts de l'application (même ceux de l'en-tête), mais après <meta charset> : sans serveur, le navigateur
    // ne lit l'encodage que dans les premiers octets du fichier
    if (preg_match('#<meta\s+charset[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE) || preg_match('#<head[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        return substr($html, 0, $pos) . "\n" . $ajout . substr($html, $pos);
    }
    return $ajout . $html;
}

function config_hors_ligne(array $eleve, string $export, string $cle, string $app, array $reste): array
{
    return [
        'format' => FORMAT_HORS_LIGNE, 'version' => 1, 'export' => $export, 'cle' => base64_encode($cle), 'app' => $app,
        'nom_app' => nom_application_hl($app),
        'eleve' => ['classe' => $eleve['classe'], 'numero' => $eleve['etiquette'], 'identifiant' => identite_eleve((int) $eleve['id'])['identifiant']],
        'prepare_le' => time() * 1000, 'centrale' => url_base(),
    ] + $reste;
}

/** Fichier HTML autonome d'un atelier pour un élève. Crée la ligne hors_ligne_exports. → [nom de fichier, html] */
function construire_hors_ligne(int $eleve_id, string $app, string $atelier): array
{
    $cat = ateliers_hors_ligne()[$app][$atelier] ?? null;
    if ($cat === null) {
        throw new RuntimeException("Atelier « $atelier » inconnu (ou application sans fichier autonome sur ce serveur).");
    }
    $eleve = eleve_hors_ligne($eleve_id);
    $ateliers = ateliers_transferes_hl($app, [$atelier]);
    $titre = (string) ($cat['titre'] ?: $atelier);
    [$id, $cle] = creer_export_hl($eleve_id, $app, $atelier, $titre);
    $nom = 'atelier_' . nom_propre_hl($eleve['classe']) . '-' . nom_propre_hl($eleve['etiquette']) . '_' . substr(nom_propre_hl($titre), 0, 40) . '.html';
    $config = config_hors_ligne($eleve, $id, $cle, $app, ['atelier' => $atelier, 'titre' => $titre,
        'ouvrir' => (string) ($cat['ouvrir'] ?: $atelier), 'fichier' => $nom, 'ateliers' => (object) $ateliers]);
    return [$nom, page_autonome_hl($app, $config, $titre . ' — ' . $eleve['classe'] . ' n° ' . $eleve['etiquette'] . ' (hors ligne)')];
}

/**
 * Parcours hors ligne d'un élève : un sommaire (00-Parcours-….html) et un fichier par application du parcours, chacun avec
 * la liste des étapes (bouton « 🧭 Parcours ») et son propre rendu. Étapes d'une application sans fichier autonome : « en classe ».
 * → [ [nom, contenu], … ] (noms relatifs au dossier de l'élève)
 */
function construire_parcours_hors_ligne(int $eleve_id, int $parcours_id): array
{
    $p = parcours_par_id($parcours_id);
    if (!$p || !$p['etapes']) {
        throw new RuntimeException('Parcours introuvable ou sans étape.');
    }
    $eleve = eleve_hors_ligne($eleve_id);
    $fichiers_app = [];   // app => nom du fichier
    foreach ($p['etapes'] as $et) {
        if (!isset($fichiers_app[$et['app']]) && chemin_fichier_autonome($et['app']) !== null) {
            $fichiers_app[$et['app']] = sprintf('%02d-%s.html', count($fichiers_app) + 1, nom_propre_hl(explode(' — ', nom_application_hl($et['app']))[0]));
        }
    }
    if (!$fichiers_app) {
        throw new RuntimeException('Aucune étape de ce parcours ne se fait dans une application disponible hors ligne.');
    }
    $etapes = [];
    foreach ($p['etapes'] as $i => $et) {
        $etapes[] = ['ordre' => $i + 1, 'titre' => (string) $et['titre'], 'app' => (string) $et['app'], 'nom_app' => nom_application_hl($et['app']),
                     'activite' => (string) $et['activite'], 'fichier' => $fichiers_app[$et['app']] ?? ''];
    }
    $info_parcours = ['id' => (int) $p['id'], 'nom' => (string) $p['nom'], 'etapes' => $etapes];
    $res = [];
    foreach ($fichiers_app as $app => $nom) {
        $mes = array_values(array_filter($etapes, fn($e) => $e['app'] === $app));
        $ids = $app === 'technostudio' ? array_filter(array_map(fn($e) => explode('/', $e['activite'])[0], $mes)) : [];
        $titre = $p['nom'] . ' · ' . nom_application_hl($app);
        [$id, $cle] = creer_export_hl($eleve_id, $app, '', $titre, (int) $p['id']);
        $config = config_hors_ligne($eleve, $id, $cle, $app, ['atelier' => '', 'titre' => $titre,
            'ouvrir' => $mes[0]['activite'] !== '' ? $mes[0]['activite'] : null, 'fichier' => $nom,
            'ateliers' => (object) ateliers_transferes_hl($app, $ids), 'parcours' => $info_parcours]);
        $res[] = [$nom, page_autonome_hl($app, $config, $titre . ' — ' . $eleve['classe'] . ' n° ' . $eleve['etiquette'] . ' (hors ligne)')];
    }
    array_unshift($res, ['00-Parcours-' . substr(nom_propre_hl($p['nom']), 0, 40) . '.html', sommaire_parcours_hl($p, $eleve, $etapes, $fichiers_app)]);
    return $res;
}

/** Page d'accueil du parcours hors ligne (HTML simple, sans script). */
function sommaire_parcours_hl(array $p, array $eleve, array $etapes, array $fichiers_app): string
{
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $lignes = '';
    foreach ($etapes as $et) {
        $lien = $et['fichier'] !== ''
            ? '<a class="ouvrir" href="' . $e(rawurlencode($et['fichier'])) . '#activite=' . $e(rawurlencode($et['activite'] !== '' ? $et['activite'] : '*')) . '">Ouvrir</a>'
            : '<span class="classe">à faire en classe</span>';
        $lignes .= '<li><span class="n">' . $et['ordre'] . '</span><span class="t"><strong>' . $e($et['titre']) . '</strong><small>' . $e($et['nom_app']) . '</small></span>' . $lien . '</li>';
    }
    $rendre = implode('', array_map(fn($n) => '<li><code>' . $e($n) . '</code></li>', array_values($fichiers_app)));
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Parcours ' . $e($p['nom']) . ' — ' . $e($eleve['classe']) . ' n° ' . $e($eleve['etiquette']) . '</title><style>'
        . ':root{--f:#f6f5f2;--s:#fff;--t:#1d1f24;--d:#62666f;--b:#dedbd3;--a:#1f5f8b;--ok:#1f7a4d}'
        . '@media (prefers-color-scheme:dark){:root{--f:#16181c;--s:#1f2228;--t:#e8e6e1;--d:#a2a5ad;--b:#343841;--a:#6aa9d6}}'
        . 'body{margin:0;background:var(--f);color:var(--t);font:16px/1.5 system-ui,sans-serif}main{max-width:720px;margin:0 auto;padding:24px 16px}'
        . 'h1{margin:0 0 4px;font-size:1.6rem}.sous{color:var(--d);margin:0 0 18px}ol{list-style:none;padding:0;margin:0}'
        . 'ol>li{display:flex;align-items:center;gap:12px;background:var(--s);border:1px solid var(--b);border-radius:10px;padding:10px 14px;margin:8px 0}'
        . '.n{width:30px;height:30px;border-radius:50%;background:var(--a);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex:none}'
        . '.t{flex:1;display:flex;flex-direction:column}.t small{color:var(--d)}.ouvrir{background:var(--ok);color:#fff;text-decoration:none;font-weight:600;padding:7px 14px;border-radius:8px}'
        . '.classe{color:var(--d);font-style:italic}.aide{background:var(--s);border:1px solid var(--b);border-radius:10px;padding:12px 16px;margin-top:20px}code{font-size:.9em}'
        . '</style></head><body><main><h1>' . $e($p['nom']) . '</h1>'
        . '<p class="sous">Parcours hors ligne · ' . $e($eleve['classe']) . ' n° ' . $e($eleve['etiquette']) . '</p>'
        . (($p['description'] ?? '') !== '' ? '<p>' . nl2br($e($p['description'])) . '</p>' : '')
        . '<ol>' . $lignes . '</ol>'
        . '<div class="aide"><strong>À la fin</strong> : dans chaque application, bouton vert « Rendre mon travail ». '
        . 'Il faut rendre un fichier pour chacune :<ul>' . $rendre . '</ul>Garde tous ces fichiers dans le même dossier. '
        . 'Dans une application, le bouton bleu « 🧭 Parcours » montre aussi les étapes.</div></main></body></html>';
}

/* ======================================================================
   Rendus : déchiffrement et enregistrement
   ====================================================================== */

/** Déchiffre un fichier rendu. → [export (ligne), contenu (tableau)] ; lève RuntimeException avec un message simple. */
function dechiffrer_rendu(string $brut): array
{
    $f = json_decode($brut, true);
    if (!is_array($f) || ($f['format'] ?? '') !== 'centrale.rendu') {
        throw new RuntimeException('ce n’est pas un fichier rendu par un atelier hors ligne.');
    }
    if ((int) ($f['version'] ?? 0) !== 1) {
        throw new RuntimeException('version de fichier inconnue (Centrale à mettre à jour ?).');
    }
    $st = db()->prepare('SELECT * FROM hors_ligne_exports WHERE id = ?');
    $st->execute([(string) ($f['export'] ?? '')]);
    $export = $st->fetch();
    if (!$export) {
        throw new RuntimeException('fichier préparé inconnu ou retiré sur cette Centrale (' . ($f['eleve'] ?? '?') . ').');
    }
    $iv = base64_decode((string) ($f['iv'] ?? ''), true);
    $donnees = base64_decode((string) ($f['donnees'] ?? ''), true);
    if ($iv === false || strlen($iv) !== 12 || $donnees === false || strlen($donnees) < 17) {
        throw new RuntimeException('fichier abîmé (chiffrement illisible).');
    }
    $clair = openssl_decrypt(substr($donnees, 0, -16), 'aes-256-gcm', (string) hex2bin($export['cle']), OPENSSL_RAW_DATA, $iv,
        substr($donnees, -16), (string) $export['id']);
    if ($clair === false) {
        throw new RuntimeException('fichier modifié ou abîmé : il ne peut pas être ouvert.');
    }
    if (!empty($f['gzip'])) {
        $clair = @gzdecode($clair);
        if ($clair === false) {
            throw new RuntimeException('fichier abîmé (compression).');
        }
    }
    $contenu = json_decode($clair, true);
    if (!is_array($contenu) || ($contenu['export'] ?? '') !== $export['id']) {
        throw new RuntimeException('contenu illisible.');
    }
    return [$export, $contenu];
}

/**
 * Enregistre le contenu d'un rendu dans le suivi (progression, données, constats). Renvoie un bilan :
 * ['activites' => n, 'terminees' => n, 'donnees' => n, 'gardees' => n (valeurs du serveur plus récentes gardées), 'constats' => n]
 */
function appliquer_rendu(array $export, array $contenu): array
{
    $pdo = db();
    $eleve_id = (int) $export['eleve_id'];
    $app = (string) $export['app'];
    $rendu_le = (int) floor(((float) ($contenu['rendu_le'] ?? 0)) / 1000) ?: time();
    $bilan = ['activites' => 0, 'terminees' => 0, 'donnees' => 0, 'gardees' => 0, 'constats' => 0, 'fichiers' => 0];
    $deja = json_decode((string) $export['constats'], true) ?: [];
    $titres = [];
    foreach ((array) ($contenu['activites'] ?? []) as $a) {
        if (is_array($a) && isset($a['id'])) {
            $titres[(string) $a['id']] = $a;
        }
    }
    $pdo->beginTransaction();
    try {
        foreach ((array) ($contenu['progression'] ?? []) as $act => $e) {
            $activite = activite_propre((string) $act);
            if ($activite === '' || !is_array($e)) {
                continue;
            }
            $e += ['titre' => (string) ($titres[$act]['titre'] ?? ''), 'total' => $titres[$act]['total'] ?? null];
            // constats : une seule fois par activité et par contenu (les rendus successifs les renvoient)
            $sig = is_array($e['constats'] ?? null) ? sha1(json_encode(array_map(fn($c) => [$c['competence'] ?? $c['code'] ?? '', $c['niveau'] ?? $c['reussi'] ?? null], $e['constats']))) : '';
            $avec = $sig !== '' && ($deja[$activite] ?? '') !== $sig;
            try {
                $r = ecrire_avancement_eleve($eleve_id, $app, $activite, $e, 'hors-ligne', $avec, isset($e['debut']) ? (int) floor($e['debut'] / 1000) : $rendu_le);
            } catch (InvalidArgumentException $x) {
                continue;
            }
            if ($avec) {
                $deja[$activite] = $sig;
            }
            $bilan['activites']++;
            $bilan['terminees'] += $r['ligne']['statut'] === 'termine' ? 1 : 0;
            $bilan['constats'] += $r['constats'];
        }
        foreach ((array) ($contenu['donnees'] ?? []) as $k => $d) {
            $cle = cle_propre((string) $k);
            if ($cle === '' || !is_array($d) || !array_key_exists('valeur', $d)) {
                continue;
            }
            $json = json_encode($d['valeur'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($json) > TAILLE_MAX_DONNEE) {
                continue;
            }
            $maj = min(time(), (int) floor(((float) ($d['maj'] ?? 0)) / 1000) ?: $rendu_le);
            $st = $pdo->prepare('SELECT valeur, taille, maj FROM donnees WHERE eleve_id = ? AND app = ? AND cle = ?');
            $st->execute([$eleve_id, $app, $cle]);
            $ancien = $st->fetch();
            if ($ancien && $ancien['valeur'] === $json) {
                continue;
            }
            $ins_version = $pdo->prepare('INSERT INTO donnees_versions (eleve_id, app, cle, valeur, taille, maj) VALUES (?, ?, ?, ?, ?, ?)');
            if ($ancien && (int) $ancien['maj'] > $maj) {
                // le serveur a plus récent (travail en ligne après le rendu) : il reste, le rendu va dans l'historique
                $ins_version->execute([$eleve_id, $app, $cle, $json, strlen($json), $maj]);
                $bilan['gardees']++;
                continue;
            }
            if ($ancien) {
                $ins_version->execute([$eleve_id, $app, $cle, $ancien['valeur'], $ancien['taille'], $ancien['maj']]);
            }
            $pdo->prepare('INSERT INTO donnees (eleve_id, app, cle, valeur, taille, maj) VALUES (?, ?, ?, ?, ?, ?)
                           ON CONFLICT (eleve_id, app, cle) DO UPDATE SET valeur = excluded.valeur, taille = excluded.taille, maj = excluded.maj')
                ->execute([$eleve_id, $app, $cle, $json, strlen($json), $maj]);
            $bilan['donnees']++;
        }
        // Fichiers envoyés au professeur depuis l'application (ex. STL d'Établi 3D) : fichiers de l'élève, comme en ligne
        foreach ((array) ($contenu['fichiers'] ?? []) as $n => $f) {
            $nom = nom_fichier_propre((string) $n);
            $octets = is_array($f) ? base64_decode((string) ($f['b64'] ?? ''), true) : false;
            if ($nom === '' || $octets === false || strlen($octets) > fichier_max_octets()) {
                continue;
            }
            $type = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string) ($f['type'] ?? '')) ? (string) $f['type'] : 'application/octet-stream';
            $st = $pdo->prepare('SELECT chemin, taille FROM fichiers WHERE eleve_id = ? AND app = ? AND nom = ?');
            $st->execute([$eleve_id, $app, $nom]);
            $ancien = $st->fetch();
            if ($ancien && (int) $ancien['taille'] === strlen($octets) && sha1_file(DOSSIER_DATA . '/' . $ancien['chemin']) === sha1($octets)) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'hlf');
            file_put_contents($tmp, $octets);
            $chemin = ranger_fichier($tmp, 'fichiers/' . $eleve_id, false);
            $pdo->prepare('INSERT INTO fichiers (eleve_id, app, nom, type, taille, chemin, maj) VALUES (?, ?, ?, ?, ?, ?, ?)
                           ON CONFLICT (eleve_id, app, nom) DO UPDATE SET type = excluded.type, taille = excluded.taille,
                           chemin = excluded.chemin, maj = excluded.maj')
                ->execute([$eleve_id, $app, $nom, $type, strlen($octets), $chemin, min(time(), (int) floor(((float) ($f['maj'] ?? 0)) / 1000) ?: $rendu_le)]);
            if ($ancien) {
                effacer_fichier_stocke($ancien['chemin']);
            }
            $bilan['fichiers']++;
        }
        // historique borné à 10 versions par clé, comme api/donnees.php
        $pdo->prepare('DELETE FROM donnees_versions WHERE eleve_id = ? AND app = ? AND id NOT IN (
                           SELECT id FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY cle ORDER BY maj DESC, id DESC) AS n
                           FROM donnees_versions WHERE eleve_id = ? AND app = ?) WHERE n <= 10)')
            ->execute([$eleve_id, $app, $eleve_id, $app]);
        $pdo->prepare('UPDATE hors_ligne_exports SET constats = ? WHERE id = ?')->execute([json_encode((object) $deja), $export['id']]);
        $pdo->commit();
    } catch (Throwable $x) {
        $pdo->rollBack();
        throw $x;
    }
    return $bilan;
}

/** Importe un fichier rendu (contenu brut). → ['ok', 'message', 'export'?] */
function importer_rendu(string $brut, string $nom): array
{
    if (strlen($brut) > TAILLE_MAX_RENDU) {
        return ['ok' => false, 'message' => "$nom : fichier trop gros."];
    }
    try {
        [$export, $contenu] = dechiffrer_rendu($brut);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => "$nom : " . $e->getMessage()];
    }
    $pdo = db();
    $empreinte = sha1($brut);
    $st = $pdo->prepare('SELECT recu_le FROM hors_ligne_rendus WHERE empreinte = ?');
    $st->execute([$empreinte]);
    if ($deja = $st->fetchColumn()) {
        return ['ok' => true, 'message' => "$nom : déjà importé le " . date('d/m à H:i', (int) $deja) . '.', 'export' => $export];
    }
    $bilan = appliquer_rendu($export, $contenu);
    $pdo->prepare('INSERT INTO hors_ligne_rendus (export_id, rendu_le, recu_le, empreinte, contenu, bilan) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$export['id'], (int) floor(((float) ($contenu['rendu_le'] ?? 0)) / 1000), time(), $empreinte,
            json_encode($contenu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($bilan)]);
    $eleve = ($contenu['eleve']['classe'] ?? '') . ' n° ' . ($contenu['eleve']['numero'] ?? '');
    $msg = "$eleve · {$export['titre']} : {$bilan['terminees']}/{$bilan['activites']} feuille(s) terminée(s)"
        . ($bilan['donnees'] ? ", travail enregistré" : '')
        . ($bilan['fichiers'] ? ", {$bilan['fichiers']} fichier(s) joint(s)" : '')
        . ($bilan['gardees'] ? " ({$bilan['gardees']} donnée(s) plus récente(s) sur le serveur gardée(s), le rendu est dans l’historique)" : '')
        . ($bilan['constats'] ? ", {$bilan['constats']} constat(s) de compétences" : '') . '.';
    return ['ok' => true, 'message' => $msg, 'export' => $export];
}
