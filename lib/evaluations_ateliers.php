<?php
/**
 * Évaluation d'un atelier (feuilles E de TechnoStudio, et la ressource d'évaluation) : fermée par défaut.
 * L'enseignant l'ouvre (page Ateliers) tout de suite ou à partir d'une date, pour une classe ou pour toutes.
 * Une ligne de ouverture_evaluations = une ouverture ; aucune ligne = fermée.
 * Effets : le portail ne montre pas les activités E fermées (api/ateliers.php, qui donne aussi la liste des ateliers
 * à l'évaluation ouverte : TechnoStudio s'en sert pour montrer les feuilles) ; l'export hors ligne ne contient jamais l'évaluation.
 */
declare(strict_types=1);

function creer_tables_evaluations_ateliers(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ouverture_evaluations (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        app       TEXT NOT NULL,
        atelier   TEXT NOT NULL,
        classe_id INTEGER,
        ouvre_le  TEXT NOT NULL DEFAULT '',
        cree_le   INTEGER NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ouverture_evaluations ON ouverture_evaluations (app, atelier)');
}

/** Activité d'évaluation : feuille E (ou S·E en SEGPA) d'un atelier TechnoStudio, id « atelier/E1 », « atelier/S-E1 ». */
function est_activite_evaluation(string $id): bool
{
    return preg_match('#/(S-)?E\d+$#', $id) === 1;
}

/** Feuille d'évaluation, ou ressource qui ne sert qu'à l'évaluation (« Ressource évaluation »). */
function est_feuille_evaluation(mixed $s): bool
{
    if (!is_array($s)) {
        return false;
    }
    $k = (string) ($s['kind'] ?? '');
    return $k === 'evaluation' || ($k === 'ressource' && preg_match('/[ée]valuation/iu', (string) ($s['title'] ?? '')) === 1);
}

/** L'atelier sans son évaluation (pour l'export hors ligne). */
function atelier_sans_evaluation(array $w): array
{
    if (is_array($w['steps'] ?? null)) {
        $w['steps'] = array_values(array_filter($w['steps'], fn($s) => !est_feuille_evaluation($s)));
    }
    return $w;
}

/** Ouvertures enregistrées : [app][atelier] => [ {id, classe_id, classe, ouvre_le}, … ]. */
function ouvertures_evaluations(?string $app = null): array
{
    $sql = 'SELECT o.id, o.app, o.atelier, o.classe_id, o.ouvre_le, c.nom AS classe FROM ouverture_evaluations o
            LEFT JOIN classes c ON c.id = o.classe_id' . ($app !== null ? ' WHERE o.app = ?' : '') . ' ORDER BY o.ouvre_le, o.id';
    $st = db()->prepare($sql);
    $st->execute($app !== null ? [$app] : []);
    $res = [];
    foreach ($st->fetchAll() as $r) {
        $res[$r['app']][$r['atelier']][] = ['id' => (int) $r['id'], 'classe_id' => $r['classe_id'] !== null ? (int) $r['classe_id'] : null,
                                           'classe' => $r['classe'], 'ouvre_le' => (string) $r['ouvre_le']];
    }
    return $res;
}

/**
 * État de l'évaluation pour une classe (null : sans tenir compte des ouvertures propres à une classe) :
 * ['ouverte' => bool, 'ouvre_le' => 'AAAA-MM-JJ' si elle s'ouvrira plus tard, sinon null].
 */
function etat_evaluation(array $lignes, ?int $classe_id, ?string $date = null): array
{
    $date ??= (new DateTimeImmutable('now', fuseau()))->format('Y-m-d');
    $prochaine = null;
    foreach ($lignes as $l) {
        if ($l['classe_id'] !== null && $l['classe_id'] !== $classe_id) {
            continue;
        }
        if ($l['ouvre_le'] === '' || $l['ouvre_le'] <= $date) {
            return ['ouverte' => true, 'ouvre_le' => null];
        }
        $prochaine = $prochaine === null ? $l['ouvre_le'] : min($prochaine, $l['ouvre_le']);
    }
    return ['ouverte' => false, 'ouvre_le' => $prochaine];
}

/** Pour une classe : [app][atelier] => état, seulement pour les ateliers qui ont au moins une ouverture. */
function evaluations_classe(int $classe_id): array
{
    $res = [];
    foreach (ouvertures_evaluations() as $app => $ats) {
        foreach ($ats as $at => $lignes) {
            $res[$app][$at] = etat_evaluation($lignes, $classe_id);
        }
    }
    return $res;
}

/** Texte court d'une ouverture pour l'enseignant : « à partir du 05/01/2027 · 4A ». */
function texte_ouverture_evaluation(array $o): string
{
    $quand = $o['ouvre_le'] === '' ? 'ouverte' : 'à partir du ' . (new DateTimeImmutable($o['ouvre_le']))->format('d/m/Y');
    return $quand . ' · ' . ($o['classe_id'] === null ? 'toutes les classes' : (string) ($o['classe'] ?? '?'));
}
