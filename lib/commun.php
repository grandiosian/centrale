<?php
/**
 * Centrale élèves — fonctions communes (base de données, codes, sécurité).
 *
 * Ce dossier "lib" et le dossier "data" sont EN DEHORS de la racine web :
 * personne ne peut les télécharger depuis internet.
 */
declare(strict_types=1);

const DOSSIER_DATA = __DIR__ . '/../data';
const VERSION_CENTRALE = '1.0';

/** Connexion à la base SQLite (créée automatiquement au premier appel). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    if (!is_dir(DOSSIER_DATA)) {
        mkdir(DOSSIER_DATA, 0700, true);
        // Protection supplémentaire si le dossier se retrouvait un jour dans la racine web.
        file_put_contents(DOSSIER_DATA . '/.htaccess', "Require all denied\n");
    }
    $pdo = new PDO('sqlite:' . DOSSIER_DATA . '/centrale.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    creer_tables($pdo);
    return $pdo;
}

function creer_tables(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS reglages (
            cle    TEXT PRIMARY KEY,
            valeur TEXT
        );
        CREATE TABLE IF NOT EXISTS classes (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            nom     TEXT NOT NULL UNIQUE COLLATE NOCASE,
            cree_le INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS depots (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            classe_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
            app       TEXT NOT NULL DEFAULT '*',
            url       TEXT NOT NULL,
            mdp       TEXT NOT NULL DEFAULT '',
            UNIQUE (classe_id, app)
        );
        CREATE TABLE IF NOT EXISTS eleves (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            classe_id          INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
            etiquette          TEXT NOT NULL,
            code_hash          TEXT NOT NULL UNIQUE,
            actif              INTEGER NOT NULL DEFAULT 1,
            cree_le            INTEGER NOT NULL,
            derniere_connexion INTEGER
        );
        CREATE TABLE IF NOT EXISTS echecs (
            cle TEXT NOT NULL,
            ts  INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_echecs ON echecs (cle, ts);

        -- Sessions des élèves (jeton donné après la saisie du code)
        CREATE TABLE IF NOT EXISTS sessions (
            jeton_hash TEXT PRIMARY KEY,
            eleve_id   INTEGER NOT NULL REFERENCES eleves(id) ON DELETE CASCADE,
            expire     INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_sessions_expire ON sessions (expire);

        -- Données sauvegardées par les applications (JSON), par élève / atelier / clé
        CREATE TABLE IF NOT EXISTS donnees (
            eleve_id INTEGER NOT NULL REFERENCES eleves(id) ON DELETE CASCADE,
            app      TEXT NOT NULL,
            cle      TEXT NOT NULL,
            valeur   TEXT NOT NULL,
            taille   INTEGER NOT NULL,
            maj      INTEGER NOT NULL,
            PRIMARY KEY (eleve_id, app, cle)
        );
        -- Anciennes versions (une toutes les 10 minutes au plus, 10 gardées par clé)
        CREATE TABLE IF NOT EXISTS donnees_versions (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            eleve_id INTEGER NOT NULL REFERENCES eleves(id) ON DELETE CASCADE,
            app      TEXT NOT NULL,
            cle      TEXT NOT NULL,
            valeur   TEXT NOT NULL,
            taille   INTEGER NOT NULL,
            maj      INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_versions ON donnees_versions (eleve_id, app, cle, maj);

        -- Fichiers des élèves (stockés dans data/fichiers, hors de la racine web)
        CREATE TABLE IF NOT EXISTS fichiers (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            eleve_id INTEGER NOT NULL REFERENCES eleves(id) ON DELETE CASCADE,
            app      TEXT NOT NULL,
            nom      TEXT NOT NULL,
            type     TEXT NOT NULL DEFAULT 'application/octet-stream',
            taille   INTEGER NOT NULL,
            chemin   TEXT NOT NULL,
            maj      INTEGER NOT NULL,
            UNIQUE (eleve_id, app, nom)
        );

        -- Ressources déposées par l'enseignant pour un atelier (lisibles par tous les élèves)
        CREATE TABLE IF NOT EXISTS ressources (
            id     INTEGER PRIMARY KEY AUTOINCREMENT,
            app    TEXT NOT NULL,
            nom    TEXT NOT NULL,
            type   TEXT NOT NULL DEFAULT 'application/octet-stream',
            taille INTEGER NOT NULL,
            chemin TEXT NOT NULL,
            maj    INTEGER NOT NULL,
            UNIQUE (app, nom)
        );
        SQL);
}

/* ---------- Stockage des fichiers ---------- */

const TAILLE_MAX_DONNEE = 2 * 1024 * 1024; // 2 Mo par clé JSON (au-delà : utiliser les fichiers)

function quota_eleve_octets(): int
{
    return max(1, (int) reglage('quota_eleve_mo', '100')) * 1024 * 1024;
}

function fichier_max_octets(): int
{
    return max(1, (int) reglage('fichier_max_mo', '20')) * 1024 * 1024;
}

/** Espace utilisé par un élève (données + fichiers), en octets. */
function usage_eleve(int $eleve_id): int
{
    $st = db()->prepare('SELECT
            COALESCE((SELECT SUM(taille) FROM donnees WHERE eleve_id = :e), 0)
          + COALESCE((SELECT SUM(taille) FROM donnees_versions WHERE eleve_id = :e), 0)
          + COALESCE((SELECT SUM(taille) FROM fichiers WHERE eleve_id = :e), 0)');
    $st->execute([':e' => $eleve_id]);
    return (int) $st->fetchColumn();
}

/** Chemin absolu d'un fichier stocké (chemin relatif enregistré en base). */
function chemin_stockage(string $relatif): string
{
    return DOSSIER_DATA . '/' . $relatif;
}

/** Range un fichier envoyé dans data/<sous_dossier>/ sous un nom aléatoire. Renvoie le chemin relatif. */
function ranger_fichier(string $source, string $sous_dossier, bool $deplacer_upload = true): string
{
    db(); // crée data/ si besoin
    $dossier = DOSSIER_DATA . '/' . $sous_dossier;
    if (!is_dir($dossier)) {
        mkdir($dossier, 0700, true);
    }
    $relatif = $sous_dossier . '/' . bin2hex(random_bytes(16)) . '.bin';
    $ok = $deplacer_upload ? move_uploaded_file($source, DOSSIER_DATA . '/' . $relatif)
                           : rename($source, DOSSIER_DATA . '/' . $relatif);
    if (!$ok) {
        throw new RuntimeException("Impossible d'enregistrer le fichier.");
    }
    return $relatif;
}

function effacer_fichier_stocke(?string $relatif): void
{
    if ($relatif && is_file(chemin_stockage($relatif))) {
        unlink(chemin_stockage($relatif));
    }
}

/** Supprime du disque tous les fichiers d'un élève (à appeler avant de le supprimer en base). */
function effacer_fichiers_eleve(int $eleve_id): void
{
    $st = db()->prepare('SELECT chemin FROM fichiers WHERE eleve_id = ?');
    $st->execute([$eleve_id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        effacer_fichier_stocke($c);
    }
    @rmdir(DOSSIER_DATA . '/fichiers/' . $eleve_id);
}

/** Nom de fichier sûr : pas de dossier, pas de caractères bizarres. */
function nom_fichier_propre(string $nom): string
{
    $nom = basename(str_replace('\\', '/', $nom));
    $nom = preg_replace('/[\x00-\x1f\x7f<>:"|?*]/u', '', $nom) ?? '';
    $nom = trim($nom, " .");
    return mb_substr($nom, 0, 120);
}

/** Clé de donnée : lettres, chiffres, - _ . / (ex. "projet", "niveau-2/score"). */
function cle_propre(string $cle): string
{
    $cle = preg_replace('#[^A-Za-z0-9_./-]#', '', trim($cle)) ?? '';
    // Pas de segments vides, "." ou ".." (évite les chemins piégés dans les exports ZIP)
    $segments = array_filter(explode('/', $cle), fn($s) => $s !== '' && $s !== '.' && $s !== '..');
    return substr(implode('/', $segments), 0, 80);
}

function format_taille(int $octets): string
{
    if ($octets < 1024) {
        return $octets . ' o';
    }
    if ($octets < 1024 * 1024) {
        return round($octets / 1024) . ' Ko';
    }
    if ($octets < 1024 ** 3) {
        return number_format($octets / 1024 / 1024, 1, ',', ' ') . ' Mo';
    }
    return number_format($octets / 1024 ** 3, 2, ',', ' ') . ' Go';
}

/** Convertit une taille de php.ini ("8M", "1G") en octets. */
function octets_ini(string $valeur): int
{
    $valeur = trim($valeur);
    $n = (int) $valeur;
    return match (strtolower(substr($valeur, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Taille totale du dossier data/ (base + fichiers). */
function taille_dossier_data(): int
{
    $total = 0;
    if (!is_dir(DOSSIER_DATA)) {
        return 0;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DOSSIER_DATA, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $total += $f->getSize();
    }
    return $total;
}

/** Envoie un fichier stocké au navigateur. */
function servir_fichier(string $relatif, string $nom, string $type, bool $piece_jointe = true): never
{
    $chemin = chemin_stockage($relatif);
    if (!is_file($chemin)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . (preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', $type) ? $type : 'application/octet-stream'));
    header('Content-Length: ' . filesize($chemin));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: private, no-store');
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $nom);
    header('Content-Disposition: ' . ($piece_jointe ? 'attachment' : 'inline')
        . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nom));
    readfile($chemin);
    exit;
}

/* ---------- Pseudonymes ---------- */

/** Prochain numéro libre dans une classe ("01", "02"…). */
function prochain_numero(int $classe_id): int
{
    $st = db()->prepare("SELECT MAX(CAST(etiquette AS INTEGER)) FROM eleves
                         WHERE classe_id = ? AND etiquette GLOB '[0-9]*'");
    $st->execute([$classe_id]);
    return ((int) $st->fetchColumn()) + 1;
}

function est_pseudonyme(string $etiquette): bool
{
    return (bool) preg_match('/^\d{2,3}$/', $etiquette);
}

/* ---------- Réglages ---------- */

function reglage(string $cle, ?string $defaut = null): ?string
{
    $st = db()->prepare('SELECT valeur FROM reglages WHERE cle = ?');
    $st->execute([$cle]);
    $v = $st->fetchColumn();
    return $v === false ? $defaut : (string) $v;
}

function definir_reglage(string $cle, string $valeur): void
{
    db()->prepare('INSERT INTO reglages (cle, valeur) VALUES (?, ?)
                   ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur')
        ->execute([$cle, $valeur]);
}

/** Sites autorisés à interroger l'API. Liste vide = tous autorisés. */
function origines_autorisees(): array
{
    $brut = reglage('origines', '') ?? '';
    return array_values(array_filter(array_map('trim', explode("\n", $brut))));
}

/* ---------- Codes élèves ---------- */

/**
 * Génère un code du type 5A-dfgdfkj-orei-flgdf.
 * Alphabet sans i, l, o (pour éviter les confusions avec 1 et 0) :
 * 16 lettres tirées au hasard = environ 72 bits, impossible à deviner.
 */
function generer_code(string $classe): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz';
    $max = strlen($alphabet) - 1;
    $segment = function (int $n) use ($alphabet, $max): string {
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $s .= $alphabet[random_int(0, $max)];
        }
        return $s;
    };
    return prefixe_classe($classe) . '-' . $segment(7) . '-' . $segment(4) . '-' . $segment(5);
}

function prefixe_classe(string $classe): string
{
    $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $classe) ?? '');
    return $p !== '' ? substr($p, 0, 10) : 'X';
}

/** Majuscules/minuscules et espaces ignorés : "5a-ABC…" = "5A-abc…". */
function normaliser_code(string $code): string
{
    return strtolower(preg_replace('/\s+/', '', $code) ?? '');
}

/** Seule l'empreinte du code est stockée, jamais le code lui-même. */
function hash_code(string $code): string
{
    return hash('sha256', normaliser_code($code));
}

/** Identifiant d'application : lettres, chiffres, - et _. Vide = "*" (toutes). */
function normaliser_app(string $app): string
{
    $app = strtolower(trim($app));
    if ($app === '' || $app === '*') {
        return '*';
    }
    $app = preg_replace('/[^a-z0-9_-]/', '', $app) ?? '';
    return $app !== '' ? substr($app, 0, 40) : '*';
}

/** Lien de dépôt Nuage pour une classe : celui de l'app s'il existe, sinon celui par défaut (*). */
function depot_pour(int $classe_id, string $app): ?array
{
    $st = db()->prepare("SELECT app, url, mdp FROM depots
                         WHERE classe_id = ? AND app IN (?, '*')
                         ORDER BY CASE WHEN app = '*' THEN 1 ELSE 0 END
                         LIMIT 1");
    $st->execute([$classe_id, $app]);
    $d = $st->fetch();
    return $d ?: null;
}

/* ---------- Anti-force brute ---------- */

function ip_client(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'inconnue');
}

function trop_d_echecs(string $cle, int $max, int $minutes): bool
{
    // Ménage : on oublie les échecs de plus de 24 h.
    db()->prepare('DELETE FROM echecs WHERE ts < ?')->execute([time() - 86400]);
    $st = db()->prepare('SELECT COUNT(*) FROM echecs WHERE cle = ? AND ts >= ?');
    $st->execute([$cle, time() - $minutes * 60]);
    return (int) $st->fetchColumn() >= $max;
}

function noter_echec(string $cle): void
{
    db()->prepare('INSERT INTO echecs (cle, ts) VALUES (?, ?)')->execute([$cle, time()]);
}

/* ---------- Divers ---------- */

/** Échappe un texte pour l'afficher dans une page HTML. */
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function est_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Adresse de base du site, ex. https://moncompte.alwaysdata.net */
function url_base(): string
{
    $hote = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Le script est dans /admin/ ou /api/ : on remonte d'un niveau.
    $chemin = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/x/y'))), '/');
    return (est_https() ? 'https' : 'http') . '://' . $hote . $chemin;
}
