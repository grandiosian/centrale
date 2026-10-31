<?php
/**
 * Centrale élèves — compétences.
 *
 * Le référentiel (public/passerelle/competences.json, produit par outils/referentiel_csv.py à partir des tableaux
 * du programme) est le même pour la Centrale et pour toutes les applications : thèmes OST / SFC / CCRI,
 * compétences (OST1), sous-compétences (OST11) et capacités par niveau (OST11.5.2 = OST11, 5e, capacité n° 2).
 *
 * Les évaluations sont des constats datés : l'enseignant (source « prof », en validant une étape de parcours)
 * ou, plus tard, une application. Un constat porte sur une capacité ou une sous-compétence, un niveau de maîtrise
 * de 1 à 4 (échelle du LSU) et son contexte (parcours, étape). Rien n'est effacé : on garde l'historique.
 */
declare(strict_types=1);

const FICHIER_REFERENTIEL = __DIR__ . '/../public/passerelle/competences.json';

function creer_tables_competences(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS evaluations (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        eleve_id    INTEGER NOT NULL REFERENCES eleves(id) ON DELETE CASCADE,
        code        TEXT NOT NULL,                       -- capacité (SFC15.4.2) ou sous-compétence (SFC15)
        niveau      INTEGER NOT NULL,                    -- 1 insuffisante · 2 fragile · 3 satisfaisante · 4 très bonne
        source      TEXT NOT NULL DEFAULT 'prof',        -- prof, ou identifiant d'application
        parcours_id INTEGER,
        etape       INTEGER,
        details     TEXT NOT NULL DEFAULT '',
        cree_le     INTEGER NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_evaluations_eleve ON evaluations (eleve_id, code)');
    ajouter_colonne($pdo, 'parcours_etapes', 'competences', "TEXT NOT NULL DEFAULT ''");   // « SFC15.5.1, CCRI16.5.1 »
}

/** Référentiel décodé (une seule lecture par requête). */
function referentiel(): array
{
    static $ref = null;
    if ($ref === null) {
        $ref = is_file(FICHIER_REFERENTIEL) ? (json_decode((string) file_get_contents(FICHIER_REFERENTIEL), true) ?: []) : [];
    }
    return $ref;
}

/**
 * Tous les codes connus, à plat : code => [code, type (capacite|sous|competence), niveau, texte, sous, sous_titre, competence, theme].
 */
function index_competences(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    $index = [];
    foreach (referentiel()['themes'] ?? [] as $t) {
        foreach ($t['competences'] as $c) {
            $index[$c['code']] = ['code' => $c['code'], 'type' => 'competence', 'niveau' => null, 'texte' => $c['titre'],
                                  'sous' => null, 'sous_titre' => null, 'competence' => $c['code'], 'theme' => $t['code']];
            foreach ($c['sous'] as $s) {
                $index[$s['code']] = ['code' => $s['code'], 'type' => 'sous', 'niveau' => null, 'texte' => $s['titre'],
                                      'sous' => $s['code'], 'sous_titre' => $s['titre'], 'competence' => $c['code'], 'theme' => $t['code']];
                foreach ($s['capacites'] as $k) {
                    $index[$k['code']] = ['code' => $k['code'], 'type' => 'capacite', 'niveau' => $k['niveau'], 'texte' => $k['texte'],
                                          'sous' => $s['code'], 'sous_titre' => $s['titre'], 'competence' => $c['code'], 'theme' => $t['code']];
                }
            }
        }
    }
    return $index;
}

/** « sfc15.4.2 », « SFC 15 », « CCRI16.3.1 » → code normalisé (sans le vérifier). */
function code_competence_propre(string $code): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
}

/** « SFC15.5.1, ccri16.5.1 ; OST11 » → [codes normalisés, sans doublon] */
function liste_codes_competences(string $texte): array
{
    $codes = array_map('code_competence_propre', preg_split('/[,;]/', $texte) ?: []);
    return array_values(array_unique(array_filter($codes, fn($c) => $c !== '')));
}

/** Échelle de maîtrise : niveau => [code, libelle, couleur] */
function echelle_maitrise(): array
{
    $e = [];
    foreach (referentiel()['echelle'] ?? [] as $n) {
        $e[(int) $n['niveau']] = $n;
    }
    return $e ?: [1 => ['code' => 'MI', 'libelle' => 'Maîtrise insuffisante', 'couleur' => '#d64035'],
                  2 => ['code' => 'MF', 'libelle' => 'Maîtrise fragile', 'couleur' => '#f0a030'],
                  3 => ['code' => 'MS', 'libelle' => 'Maîtrise satisfaisante', 'couleur' => '#7cb342'],
                  4 => ['code' => 'TBM', 'libelle' => 'Très bonne maîtrise', 'couleur' => '#2e7d32']];
}

/** Pastille de niveau de maîtrise (MI, MF, MS, TBM). */
function pastille_maitrise(int $niveau, string $titre = ''): string
{
    $e = echelle_maitrise()[$niveau] ?? null;
    if (!$e) {
        return '';
    }
    return '<span class="maitrise m' . $niveau . '" style="--m:' . h($e['couleur']) . '" title="' . h($titre !== '' ? $titre : $e['libelle']) . '">' . h($e['code']) . '</span>';
}

/** Enregistre un constat de l'enseignant (un par élève). */
function noter_competence(array $eleve_ids, string $code, int $niveau, ?int $parcours_id = null, ?int $etape = null, string $source = 'prof'): void
{
    if ($niveau < 1 || $niveau > 4 || !isset(index_competences()[$code])) {
        return;
    }
    $ins = db()->prepare('INSERT INTO evaluations (eleve_id, code, niveau, source, parcours_id, etape, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach (array_unique($eleve_ids) as $e) {
        $ins->execute([(int) $e, $code, $niveau, $source, $parcours_id, $etape, time()]);
    }
}

/** Dernière note de l'enseignant pour une étape d'un dossier : code => niveau (pour pré-cocher la grille). */
function notes_etape(int $parcours_id, int $etape, array $eleve_ids): array
{
    if (!$eleve_ids) {
        return [];
    }
    $st = db()->prepare("SELECT code, niveau FROM evaluations WHERE source = 'prof' AND parcours_id = ? AND etape = ?
                         AND eleve_id IN (" . implode(',', array_fill(0, count($eleve_ids), '?')) . ') ORDER BY cree_le, id');
    $st->execute([$parcours_id, $etape, ...array_values($eleve_ids)]);
    $res = [];
    foreach ($st->fetchAll() as $r) {
        $res[$r['code']] = (int) $r['niveau'];
    }
    return $res;
}

/** Constats d'un élève, du plus récent au plus ancien, regroupés par code. */
function evaluations_eleve(int $eleve_id): array
{
    $st = db()->prepare('SELECT ev.*, p.nom AS parcours_nom FROM evaluations ev LEFT JOIN parcours p ON p.id = ev.parcours_id
                         WHERE ev.eleve_id = ? ORDER BY ev.cree_le DESC, ev.id DESC');
    $st->execute([$eleve_id]);
    $res = [];
    foreach ($st->fetchAll() as $r) {
        $res[$r['code']][] = $r;
    }
    return $res;
}

/**
 * Remarques sur les compétences des étapes d'un parcours : code inconnu, capacité d'un niveau qu'aucune classe
 * du parcours n'a (d'après le chiffre du nom de la classe : 5A → 5e). Jamais bloquant.
 */
function verifier_competences_etapes(array $etapes, string $classes): array
{
    $index = index_competences();
    $niveaux = [];
    foreach (array_filter(array_map('trim', explode(',', $classes))) as $c) {
        if ($n = niveau_classe($c)) {
            $niveaux[$n . 'e'] = true;
        }
    }
    $rem = [];
    foreach ($etapes as $i => $et) {
        foreach (liste_codes_competences((string) ($et['competences'] ?? '')) as $code) {
            $k = $index[$code] ?? null;
            if (!$k) {
                $rem[] = 'Étape ' . ($i + 1) . " : compétence « $code » inconnue du référentiel (voir l’onglet Compétences).";
            } elseif ($k['niveau'] && $niveaux && !isset($niveaux[$k['niveau']])) {
                $rem[] = 'Étape ' . ($i + 1) . " : « $code » est une capacité de {$k['niveau']}, alors que le parcours est pour " . implode(', ', array_keys($niveaux)) . '.';
            }
        }
    }
    return $rem;
}
