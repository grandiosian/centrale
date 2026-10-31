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
    ajouter_colonne($pdo, 'activites', 'competences', "TEXT NOT NULL DEFAULT ''");         // capacités travaillées, déclarées par l'application
    // Progression de l'enseignant : thème et support de chaque séquence, capacités travaillées
    $pdo->exec("CREATE TABLE IF NOT EXISTS sequences (
        niveau  TEXT NOT NULL,                  -- 5e, 4e, 3e
        numero  INTEGER NOT NULL,               -- 1 à 8
        theme   TEXT NOT NULL DEFAULT '',
        support TEXT NOT NULL DEFAULT '',
        codes   TEXT NOT NULL DEFAULT '',       -- « SFC15.5.1, CCRI16.5.1 »
        PRIMARY KEY (niveau, numero)
    )");
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

/* ---------- Couleurs des thèmes : thème 1 (OST) vert, thème 2 (SFC) rose, thème 3 (CCRI) jaune orangé ---------- */

function couleur_theme(?string $theme): string
{
    foreach (referentiel()['themes'] ?? [] as $t) {
        if ($t['code'] === $theme) {
            return (string) ($t['couleur'] ?? '#888888');
        }
    }
    return '#888888';
}

/** Puce d'un code, à la couleur de son thème, avec l'intitulé en bulle. $classes : « copier »… */
function puce_competence(string $code, string $classes = ''): string
{
    $k = index_competences()[$code] ?? null;
    return '<span class="code-comp th ' . h($classes) . '" style="--t:' . h(couleur_theme($k['theme'] ?? null)) . '" title="'
        . h($k ? $k['texte'] : 'Code inconnu du référentiel') . '">' . h($code) . '</span>';
}

/** Ne garde que les codes du référentiel (capacités ou sous-compétences), normalisés. */
function codes_valides(array|string $codes): array
{
    $liste = is_array($codes) ? array_map(fn($c) => code_competence_propre((string) $c), $codes) : liste_codes_competences($codes);
    $index = index_competences();
    return array_values(array_unique(array_filter($liste, fn($c) => isset($index[$c]) && $index[$c]['type'] !== 'competence')));
}

/* ---------- Constats envoyés par les applications ---------- */

/**
 * Niveau de maîtrise d'un constat d'application :
 * - « niveau » donné (1 à 4) : gardé tel quel ;
 * - pas réussi : 1 (insuffisante) ;
 * - réussi du premier coup sans indice : 4 ; avec au plus 1 indice et 3 essais : 3 ; avec plus d'aide : 2.
 */
function niveau_constat(array $c): int
{
    if (isset($c['niveau']) && is_numeric($c['niveau'])) {
        return max(1, min(4, (int) $c['niveau']));
    }
    if (empty($c['reussi'])) {
        return 1;
    }
    $indices = max(0, (int) ($c['indices'] ?? 0));
    $essais = max(1, (int) ($c['essais'] ?? 1));
    return $indices === 0 && $essais <= 1 ? 4 : ($indices <= 1 && $essais <= 3 ? 3 : 2);
}

/** Enregistre les constats joints à une progression (50 au plus). Renvoie le nombre gardé. */
function enregistrer_constats(int $eleve_id, string $app, string $activite, array $constats): int
{
    $ins = db()->prepare('INSERT INTO evaluations (eleve_id, code, niveau, source, details, cree_le) VALUES (?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach (array_slice($constats, 0, 50) as $c) {
        if (!is_array($c)) {
            continue;
        }
        $code = codes_valides([(string) ($c['competence'] ?? $c['code'] ?? '')])[0] ?? null;
        if (!$code) {
            continue;
        }
        $details = json_encode(array_filter([
            'activite' => $activite, 'reussi' => isset($c['reussi']) ? (bool) $c['reussi'] : null,
            'indices' => isset($c['indices']) ? (int) $c['indices'] : null, 'essais' => isset($c['essais']) ? (int) $c['essais'] : null,
            'preuve' => isset($c['preuve']) ? mb_substr((string) $c['preuve'], 0, 200) : null,
        ], fn($v) => $v !== null), JSON_UNESCAPED_UNICODE);
        $ins->execute([$eleve_id, $code, niveau_constat($c), $app, $details, time()]);
        $n++;
    }
    return $n;
}

/* ---------- Bilan : « moyenne haute » ---------- */

/** Moyenne des niveaux, arrondie au niveau supérieur (2 et 3 → 3 ; 3, 3 et 4 → 4). */
function moyenne_haute(array $niveaux): int
{
    return $niveaux ? (int) ceil(array_sum($niveaux) / count($niveaux) - 1e-9) : 0;
}

/**
 * Bilan d'un ensemble d'élèves : [eleve_id][code] => ['niveau' (moyenne haute), 'n' (constats), 'prof' (dont notes du prof), 'maj'].
 * Les codes sont les capacités notées ET leurs sous-compétences (moyenne haute de tous les constats de la sous-compétence).
 */
function bilans_eleves(array $eleve_ids): array
{
    if (!$eleve_ids) {
        return [];
    }
    $st = db()->prepare('SELECT eleve_id, code, niveau, source, cree_le FROM evaluations WHERE eleve_id IN ('
        . implode(',', array_fill(0, count($eleve_ids), '?')) . ')');
    $st->execute(array_values(array_map('intval', $eleve_ids)));
    $index = index_competences();
    $brut = [];
    foreach ($st->fetchAll() as $r) {
        $k = $index[$r['code']] ?? null;
        if (!$k) {
            continue;
        }
        foreach (array_unique([$r['code'], (string) $k['sous']]) as $c) {
            if ($c === '') {
                continue;
            }
            $b = &$brut[(int) $r['eleve_id']][$c];
            $b['niveaux'][] = (int) $r['niveau'];
            $b['prof'] = ($b['prof'] ?? 0) + ($r['source'] === 'prof' ? 1 : 0);
            $b['maj'] = max($b['maj'] ?? 0, (int) $r['cree_le']);
            unset($b);
        }
    }
    $res = [];
    foreach ($brut as $e => $codes) {
        foreach ($codes as $c => $b) {
            $res[$e][$c] = ['niveau' => moyenne_haute($b['niveaux']), 'moyenne' => array_sum($b['niveaux']) / count($b['niveaux']),
                            'n' => count($b['niveaux']), 'prof' => $b['prof'], 'maj' => $b['maj']];
        }
    }
    return $res;
}

/** Cellule de bilan : pastille MI/MF/MS/TBM + nombre de constats en bulle. */
function cellule_bilan(?array $b): string
{
    if (!$b) {
        return '<td class="cellule"></td>';
    }
    $titre = echelle_maitrise()[$b['niveau']]['libelle'] . ' — moyenne haute de ' . $b['n'] . ' constat' . ($b['n'] > 1 ? 's' : '')
        . ($b['prof'] ? ' (dont ' . $b['prof'] . ' de vous)' : '');
    return '<td class="cellule">' . pastille_maitrise($b['niveau'], $titre) . '</td>';
}

/** Progression d'un niveau : numéro => [theme, support, codes[]] (8 séquences). */
function sequences_niveau(string $niveau): array
{
    $res = [];
    for ($i = 1; $i <= 8; $i++) {
        $res[$i] = ['theme' => '', 'support' => '', 'codes' => []];
    }
    $st = db()->prepare('SELECT * FROM sequences WHERE niveau = ?');
    $st->execute([$niveau]);
    foreach ($st->fetchAll() as $r) {
        $res[(int) $r['numero']] = ['theme' => $r['theme'], 'support' => $r['support'], 'codes' => liste_codes_competences($r['codes'])];
    }
    return $res;
}
