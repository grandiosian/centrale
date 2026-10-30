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
        SQL);
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
