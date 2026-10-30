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
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
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
