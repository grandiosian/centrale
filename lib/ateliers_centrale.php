<?php
/**
 * Centrale élèves — ateliers transférés par la Centrale : fichiers versionnés dans ateliers/<app>/<id>.json
 * (dépôt de la Centrale, déployés avec elle, jamais dans data/).
 *
 * Règle : public/passerelle/ATELIERS-INTEGRES-ET-TRANSFERES.md. Les premiers pas restent intégrés à chaque
 * application ; les autres ateliers sont ici, un fichier par atelier, au format de l'application, avec son
 * « format », sa « version » (augmentée à chaque modification) et son « id » (= nom du fichier, jamais changé).
 *
 *  - Catalogue : ces fichiers rejoignent le catalogue de la Centrale comme un atelier importé d'une application,
 *    avec la source « centrale » (synchroniser_ateliers_centrale, appelé par catalogue_ateliers()).
 *  - API publique en lecture seule : api/atelier-fichier.php (liste et fichier), lue par les applications, qui en
 *    gardent une copie pour les pannes du serveur.
 *  - Inspecter (lib/admin_inspecter.php) montre le fichier dans sa partie « Code source ».
 *
 * TechnoStudio (format « Workshop » 2) : ses activités sont calculées ici exactement comme dans son js/passerelle.js
 * (une par feuille notée, id = <atelier>/<code feuille>), pour que le travail des élèves reste rattaché.
 */
declare(strict_types=1);

const DOSSIER_ATELIERS_CENTRALE = __DIR__ . '/../ateliers';
const TAILLE_MAX_ATELIER_CENTRALE = 16 * 1024 * 1024;
const DEPOT_ATELIERS_CENTRALE = 'grandiosian/centrale';
/** Types de champs notés de TechnoStudio (descripteur : graded), si son centrale.json n'est pas encore enregistré. */
const TYPES_NOTES_TECHNOSTUDIO = ['qcm', 'vraifaux', 'cloze', 'numeric', 'match', 'order', 'sort', 'table', 'boxes', 'chain', 'label',
    'bits', 'graph', 'draw', 'mindmap', 'procedure', 'questions', 'report', 'circuit', 'blocks', 'mblock', 'robot', 'code'];

function id_atelier_centrale_valide(string $id): bool
{
    return preg_match('/^[A-Za-z0-9-]{1,80}$/', $id) === 1;
}

function app_atelier_centrale_valide(string $app): bool
{
    return preg_match('/^[a-z0-9_-]{1,40}$/', $app) === 1;
}

/** Applications qui ont un dossier ateliers/<app>/. */
function apps_ateliers_centrale(): array
{
    $res = [];
    foreach (glob(DOSSIER_ATELIERS_CENTRALE . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (app_atelier_centrale_valide(basename($d))) {
            $res[] = basename($d);
        }
    }
    sort($res);
    return $res;
}

/** Fichiers des ateliers d'une application : [id => chemin], dans l'ordre des identifiants. */
function fichiers_ateliers_centrale(string $app): array
{
    if (!app_atelier_centrale_valide($app)) {
        return [];
    }
    $res = [];
    foreach (glob(DOSSIER_ATELIERS_CENTRALE . '/' . $app . '/*.json') ?: [] as $f) {
        $id = basename($f, '.json');
        if (id_atelier_centrale_valide($id) && is_file($f)) {
            $res[$id] = $f;
        }
    }
    ksort($res, SORT_STRING);
    return $res;
}

/** Chemin du fichier dans le dépôt de la Centrale (« ateliers/technostudio/x.json »). */
function chemin_depot_atelier_centrale(string $app, string $id): string
{
    return 'ateliers/' . $app . '/' . $id . '.json';
}

/** Adresse du fichier par l'API, relative à la racine de la Centrale. */
function adresse_api_atelier_centrale(string $app, string $id): string
{
    return 'api/atelier-fichier.php?app=' . rawurlencode($app) . '&id=' . rawurlencode($id);
}

/**
 * Lit et vérifie un fichier : [contenu décodé ou null, texte brut, erreur].
 * Exigé : un objet JSON avec « id » (= nom du fichier), « format » et « version » (entiers ≥ 1) ;
 * TechnoStudio : « title » et « steps » (liste) en plus.
 */
function lire_atelier_centrale(string $app, string $id): array
{
    $f = fichiers_ateliers_centrale($app)[$id] ?? null;
    if ($f === null) {
        return [null, '', 'Pas de fichier « ' . chemin_depot_atelier_centrale($app, $id) . ' ».'];
    }
    if (filesize($f) > TAILLE_MAX_ATELIER_CENTRALE) {
        return [null, '', $id . '.json : fichier trop gros (16 Mo au plus).'];
    }
    $brut = (string) file_get_contents($f);
    $j = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $brut) ?? $brut, true);
    if (!is_array($j) || array_is_list($j)) {
        return [null, $brut, $id . '.json : ce n’est pas un atelier (objet JSON attendu).'];
    }
    if ((string) ($j['id'] ?? '') !== $id) {
        return [null, $brut, $id . '.json : son « id » (« ' . (string) ($j['id'] ?? '') . ' ») doit être le nom du fichier.'];
    }
    foreach (['format', 'version'] as $k) {
        if (!is_int($j[$k] ?? null) || $j[$k] < 1) {
            return [null, $brut, $id . '.json : « ' . $k . ' » manquant (un entier, 1 ou plus).'];
        }
    }
    if ($app === 'technostudio' && (!is_string($j['title'] ?? null) || !is_array($j['steps'] ?? null) || !array_is_list($j['steps']))) {
        return [null, $brut, $id . '.json : un atelier TechnoStudio a un « title » et des feuilles (« steps »).'];
    }
    return [$j, $brut, ''];
}

/* ---------- TechnoStudio : comme js/passerelle.js ---------- */

/**
 * Types de champs notés : ceux connus ici, plus ceux que le descripteur enregistré de TechnoStudio déclare « noté »
 * (enums.types_champs). Ce descripteur peut être plus ancien que l'application : il ne retire donc aucun type.
 */
function types_notes_technostudio(): array
{
    static $types = null;
    if ($types === null) {
        $t = descripteur_de('technostudio')['enums']['types_champs'] ?? null;
        $d = is_array($t) ? array_keys(array_filter($t, fn($v) => is_string($v) && str_contains($v, 'noté'))) : [];
        $types = array_values(array_unique(array_merge(TYPES_NOTES_TECHNOSTUDIO, array_map('strval', $d))));
    }
    return $types;
}

/** Codes des feuilles : P1, P2, R1, S1, E1, S·P1 (SEGPA). */
function codes_feuilles_technostudio(array $w): array
{
    $kind = ['feuille' => 'P', 'ressource' => 'R', 'synthese' => 'S', 'evaluation' => 'E', 'fiche' => 'F'];
    $cnt = [];
    $res = [];
    foreach ($w['steps'] as $s) {
        $s = is_array($s) ? $s : [];
        $k = is_string($s['kind'] ?? null) && $s['kind'] !== '' ? $s['kind'] : 'feuille';
        $cle = $k . (!empty($s['segpa']) ? 's' : '');
        $cnt[$cle] = ($cnt[$cle] ?? 0) + 1;
        $n = $k === 'feuille' && !empty($s['feuille']) ? (string) $s['feuille'] : (string) $cnt[$cle];
        $res[] = (!empty($s['segpa']) ? 'S·' : '') . ($kind[$k] ?? 'P') . $n;
    }
    return $res;
}

/** Codes du référentiel commun (OST15, SFC11.5.2…) d'une liste. */
function liste_ref_technostudio(mixed $v): array
{
    $l = is_array($v) ? $v : ($v ? [$v] : []);
    return array_values(array_filter(array_map(fn($c) => is_scalar($c) ? (string) $c : '', $l),
        fn($c) => preg_match('/^(OST|SFC|CCRI)\d{2}(\.[3-6]\.\d+)?$/', $c) === 1));
}

/**
 * Activités d'un atelier TechnoStudio : une par feuille qui contient au moins un champ noté.
 * [{id, titre, total, competences}] ; competences : pastilles « comp » reliées au référentiel par « ref »,
 * ou codes du référentiel ; sans pastille, les capacités de l'atelier (« referentiel »).
 */
function activites_atelier_technostudio(array $w): array
{
    $notes = types_notes_technostudio();
    $refs = [];
    $ajouter = function (mixed $code, mixed $ref) use (&$refs): void {
        $l = liste_ref_technostudio($ref);
        if (is_scalar($code) && (string) $code !== '' && $l) {
            $refs[(string) $code] = array_merge($refs[(string) $code] ?? [], $l);
        }
    };
    foreach ((array) ($w['competences'] ?? []) as $c) {
        if (is_array($c)) {
            $ajouter($c['code'] ?? '', $c['ref'] ?? null);
            foreach ((array) ($c['sub'] ?? []) as $s) {
                if (is_array($s)) {
                    $ajouter($s['code'] ?? '', $s['ref'] ?? null);
                }
            }
        }
    }
    $codes = codes_feuilles_technostudio($w);
    $res = [];
    foreach ($w['steps'] as $i => $s) {
        $blocs = is_array($s) && is_array($s['blocks'] ?? null) ? $s['blocks'] : [];
        $notes_feuille = array_filter($blocs, fn($b) => is_array($b) && in_array($b['type'] ?? '', $notes, true) && empty($b['demo']));
        if (!$notes_feuille) {
            continue;
        }
        $comp = [];
        foreach ($notes_feuille as $b) {
            $pastilles = is_array($b['comp'] ?? null) ? $b['comp'] : [];
            if (!$pastilles) {
                array_push($comp, ...liste_ref_technostudio($w['referentiel'] ?? null));
                continue;
            }
            foreach ($pastilles as $p) {
                $p = is_scalar($p) ? (string) $p : '';
                if (liste_ref_technostudio($p)) {
                    $comp[] = $p;
                } else {
                    array_push($comp, ...($refs[$p] ?? []));
                }
            }
        }
        $res[] = ['id' => preg_replace('#[^A-Za-z0-9_\-./]#u', '-', $w['id'] . '/' . $codes[$i]),
                  'titre' => mb_substr($codes[$i] . ' · ' . (string) ($s['title'] ?? ''), 0, 120),
                  'total' => count($notes_feuille), 'competences' => array_values(array_unique($comp))];
    }
    return $res;
}

/** Fiche du catalogue d'un atelier TechnoStudio (comme atelierCatalogue de js/passerelle.js). */
function atelier_centrale_technostudio(array $w): ?array
{
    $acts = activites_atelier_technostudio($w);
    $niveau = preg_match('/^\s*([3-6])\s*e/i', (string) ($w['niveau'] ?? ''), $m) ? $m[1] . 'e'
        : (['college' => '', 'sti2d' => 'lycee', 'snt' => 'lycee', 'pro' => 'lycee'][(string) ($w['level'] ?? 'college')] ?? '');
    $comp = liste_ref_technostudio($w['referentiel'] ?? null);
    foreach ($acts as $a) {
        array_push($comp, ...$a['competences']);
    }
    $resume = (string) (($w['summary'] ?? '') !== '' ? $w['summary'] : ($w['problematique'] ?? ''));
    return normaliser_atelier(['id' => $w['id'], 'titre' => (string) $w['title'], 'niveau' => $niveau, 'resume' => mb_substr($resume, 0, 600),
        'duree' => !empty($w['duration']) ? $w['duration'] . ' min' : '', 'competences' => array_values(array_unique($comp)),
        'activites' => $acts, 'ouvrir' => $w['id'], 'fichier' => adresse_api_atelier_centrale('technostudio', $w['id']),
        'version' => (string) $w['version'], 'auteur' => mb_substr((string) ($w['author'] ?? ''), 0, 80)]);
}

/** Ateliers d'une application, prêts pour le catalogue : [ateliers normalisés, remarques]. */
function ateliers_centrale_normalises(string $app): array
{
    $liste = [];
    $rem = [];
    foreach (array_keys(fichiers_ateliers_centrale($app)) as $id) {
        [$j, , $err] = lire_atelier_centrale($app, $id);
        if (!$j) {
            $rem[] = $err;
            continue;
        }
        $n = $app === 'technostudio' ? atelier_centrale_technostudio($j)
            : normaliser_atelier(['id' => $id, 'titre' => $j['titre'] ?? $j['title'] ?? $id, 'niveau' => $j['niveau'] ?? '',
                'resume' => $j['resume'] ?? '', 'duree' => $j['duree'] ?? '', 'competences' => $j['competences'] ?? [],
                'activites' => $j['activites'] ?? [], 'ouvrir' => $j['ouvrir'] ?? $id,
                'fichier' => adresse_api_atelier_centrale($app, $id), 'version' => (string) $j['version'], 'auteur' => $j['auteur'] ?? '']);
        if ($n) {
            $liste[] = $n;
        }
    }
    return [$liste, $rem];
}

/**
 * Range les ateliers de ateliers/<app>/ dans le catalogue (source « centrale »). Un fichier supprimé passe à l'état
 * « retiré » (jamais effacé : du travail d'élève y est rattaché). Renvoie le bilan (comme ranger_ateliers).
 */
function importer_ateliers_centrale(string $app): array
{
    [$liste, $rem] = ateliers_centrale_normalises($app);
    $bilan = $liste ? ranger_ateliers($app, $liste, 'centrale', false) : ['ajoutes' => [], 'modifies' => [], 'retires' => [], 'inchanges' => 0];
    $vus = array_flip(array_column($liste, 'atelier'));
    $st = db()->prepare("SELECT atelier, titre FROM catalogue_ateliers WHERE app = ? AND source = 'centrale' AND etat = 'actif'");
    $st->execute([$app]);
    $ret = db()->prepare("UPDATE catalogue_ateliers SET etat = 'retire', modifie_le = ? WHERE app = ? AND atelier = ?");
    foreach ($st->fetchAll() as $r) {
        // un fichier illisible n'est pas « retiré » : on garde la fiche précédente jusqu'à sa correction
        if (!isset($vus[$r['atelier']]) && !isset(fichiers_ateliers_centrale($app)[$r['atelier']])) {
            $ret->execute([time(), $app, $r['atelier']]);
            $bilan['retires'][] = $r['titre'];
        }
    }
    $bilan['remarques'] = $rem;
    if ($bilan['ajoutes'] || $bilan['modifies'] || $bilan['retires'] || $rem) {
        journaliser_import($app, 'centrale', 'ateliers/' . $app . '/', $bilan);
    }
    return $bilan;
}

/** Empreinte du dossier ateliers/ (noms, tailles, dates) : dit si un fichier a été ajouté, changé ou retiré. */
function signature_ateliers_centrale(): string
{
    $l = [];
    foreach (apps_ateliers_centrale() as $app) {
        foreach (fichiers_ateliers_centrale($app) as $id => $f) {
            $l[] = $app . '/' . $id . ':' . filesize($f) . ':' . filemtime($f);
        }
    }
    return sha1(implode("\n", $l));
}

/**
 * Met le catalogue à jour quand le dossier ateliers/ a changé (déploiement). Appelé par catalogue_ateliers() :
 * une fois par requête au plus, et seulement un stat() par fichier quand rien n'a changé.
 */
function synchroniser_ateliers_centrale(bool $forcer = false): void
{
    static $fait = false;
    if ($fait && !$forcer) {
        return;
    }
    $fait = true;
    try {
        $sig = signature_ateliers_centrale();
        if (!$forcer && reglage('ateliers_centrale_signature', '') === $sig) {
            return;
        }
        $st = db()->query("SELECT DISTINCT app FROM catalogue_ateliers WHERE source = 'centrale'");
        foreach (array_unique(array_merge(apps_ateliers_centrale(), $st->fetchAll(PDO::FETCH_COLUMN))) as $app) {
            importer_ateliers_centrale((string) $app);
        }
        definir_reglage('ateliers_centrale_signature', $sig);
    } catch (Throwable $e) {
        error_log('ateliers de la Centrale : ' . $e->getMessage());
    }
}
