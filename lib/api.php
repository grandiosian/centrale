<?php
/**
 * Centrale élèves — outils pour les points d'entrée de l'API (réponses JSON, CORS).
 */
declare(strict_types=1);

require __DIR__ . '/commun.php';

function envoyer_json(int $statut, array $donnees): never
{
    http_response_code($statut);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Autorise (ou non) les applications hébergées ailleurs (GitHub Pages, Forge…)
 * à interroger l'API, selon la liste "Sites autorisés" de la page d'administration.
 */
function appliquer_cors(): void
{
    $origine = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origine !== '') {
        $autorisees = origines_autorisees();
        if ($autorisees && !in_array(rtrim($origine, '/'), $autorisees, true)) {
            envoyer_json(403, ['ok' => false, 'erreur' => "Ce site n'est pas autorisé à utiliser la Centrale."]);
        }
        header('Access-Control-Allow-Origin: ' . $origine);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Jeton');
        // X-Version : version lue d'un fichier du dossier de projet (projet.php), renvoyée à l'enregistrement.
        // Sans elle, une application hébergée ailleurs lit 0 et chaque nouveau dépôt du même fichier part en conflit (409).
        header('Access-Control-Expose-Headers: Content-Disposition, X-Maj, X-Version');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/* ---------- Sessions élèves ---------- */

const DUREE_SESSION = 12 * 3600; // une journée de cours

/** Crée un jeton de session pour un élève. Seule son empreinte est stockée. */
function creer_session(int $eleve_id): array
{
    db()->prepare('DELETE FROM sessions WHERE expire < ?')->execute([time()]);
    $jeton = bin2hex(random_bytes(32));
    $expire = time() + DUREE_SESSION;
    db()->prepare('INSERT INTO sessions (jeton_hash, eleve_id, expire) VALUES (?, ?, ?)')
        ->execute([hash('sha256', $jeton), $eleve_id, $expire]);
    return [$jeton, $expire];
}

/**
 * Élève correspondant au jeton envoyé dans l'en-tête X-Jeton.
 * (Un en-tête personnalisé plutôt qu'Authorization, que certains hébergements suppriment.)
 * Arrête la requête avec une erreur 401 si le jeton est absent, inconnu ou expiré.
 */
function eleve_connecte(): array
{
    $jeton = (string) ($_SERVER['HTTP_X_JETON'] ?? '');
    if ($jeton === '') {
        envoyer_json(401, ['ok' => false, 'erreur' => 'Non connecté.', 'session' => 'absente']);
    }
    $st = db()->prepare('SELECT e.id, e.etiquette, e.classe_id, e.groupe_id, c.nom AS classe
                         FROM sessions s
                         JOIN eleves e ON e.id = s.eleve_id
                         JOIN classes c ON c.id = e.classe_id
                         WHERE s.jeton_hash = ? AND s.expire >= ? AND e.actif = 1');
    $st->execute([hash('sha256', $jeton), time()]);
    $eleve = $st->fetch();
    if (!$eleve) {
        envoyer_json(401, ['ok' => false, 'erreur' => 'Session expirée : retape ton code.', 'session' => 'expiree']);
    }
    $eleve['id'] = (int) $eleve['id'];
    return $eleve;
}

/** Identifiant d'atelier obligatoire pour les données et fichiers. */
function app_requise(array $entree): string
{
    $app = normaliser_app((string) ($entree['app'] ?? $_GET['app'] ?? ''));
    if ($app === '*') {
        envoyer_json(400, ['ok' => false, 'erreur' => "Identifiant d'application (app) manquant."]);
    }
    return $app;
}

/** Lit les données envoyées, en JSON ou en formulaire classique. */
function lire_entree(): array
{
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($type, 'application/json')) {
        $d = json_decode((string) file_get_contents('php://input'), true);
        return is_array($d) ? $d : [];
    }
    return $_POST;
}
