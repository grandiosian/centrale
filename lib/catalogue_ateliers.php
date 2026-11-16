<?php
/**
 * Centrale élèves — catalogue des ateliers : les ateliers créés dans chaque application, importés et rangés
 * dans la page Ateliers (onglet « Ateliers des applications »).
 *
 * Règle : un atelier vit d'abord dans son application (fichiers publiés avec elle, disponibles sans serveur).
 * La Centrale n'en garde qu'une copie de référence pour les ranger, les relier aux compétences, aux parcours
 * et au suivi. Voir public/passerelle/DONNEES-BONNES-PRATIQUES-COMPATIBILITE.md et SPECIFICATION.md § 2.8.
 *
 * Sources, de la meilleure à la plus pauvre :
 *  1. « catalogue » : le fichier ateliers.json publié par l'application (format catalogue.ateliers v1) ;
 *  2. « descripteur » : à défaut, les activités de son centrale.json, regroupées par atelier ;
 *  3. « fichier » : un fichier exporté d'une application et déposé par l'enseignant (catalogue, atelier seul,
 *     ou atelier TechnoStudio *.technostudio.json).
 * À côté : « centrale », les ateliers transférés par la Centrale (fichiers versionnés ateliers/<app>/<id>.json du dépôt
 * de la Centrale, lib/ateliers_centrale.php). Ils passent avant ce que l'application publie sous le même identifiant.
 */
declare(strict_types=1);

const FORMAT_CATALOGUE = 'catalogue.ateliers';
const TAILLE_MAX_FICHIER_ATELIERS = 2 * 1024 * 1024;
const SOURCES_CATALOGUE = ['catalogue' => 'ateliers.json de l’application', 'descripteur' => 'centrale.json (activités regroupées)',
                           'fichier' => 'fichier importé', 'centrale' => 'fichier de la Centrale (dossier ateliers/ de son dépôt)'];

function creer_tables_catalogue(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS catalogue_ateliers (
        app         TEXT NOT NULL,
        atelier     TEXT NOT NULL,                   -- identifiant stable donné par l'application
        titre       TEXT NOT NULL DEFAULT '',
        niveau      TEXT NOT NULL DEFAULT '',        -- 6e, 5e, 4e, 3e, lycee ou vide (tous niveaux)
        theme       TEXT NOT NULL DEFAULT '',        -- OST, SFC, CCRI (d'après les compétences)
        resume      TEXT NOT NULL DEFAULT '',
        duree       TEXT NOT NULL DEFAULT '',
        competences TEXT NOT NULL DEFAULT '',        -- « SFC15.5.1, CCRI16.5.1 »
        activites   TEXT NOT NULL DEFAULT '[]',      -- [{id, titre, total, competences}]
        ouvrir      TEXT NOT NULL DEFAULT '',        -- activité à ouvrir (#activite=…)
        fichier     TEXT NOT NULL DEFAULT '',        -- adresse du contenu publié par l'application
        version     TEXT NOT NULL DEFAULT '',
        auteur      TEXT NOT NULL DEFAULT '',
        source      TEXT NOT NULL DEFAULT 'catalogue',
        etat        TEXT NOT NULL DEFAULT 'actif',   -- actif, retire (n'est plus publié par l'application)
        empreinte   TEXT NOT NULL DEFAULT '',
        contenu     TEXT NOT NULL DEFAULT '',        -- fichier importé à la main (gardé pour le rendre à l'application)
        ordre       INTEGER NOT NULL DEFAULT 0,
        importe_le  INTEGER NOT NULL,
        modifie_le  INTEGER NOT NULL,
        PRIMARY KEY (app, atelier)
    )");
    // Périodes de disponibilité d'un atelier (dates) : voir disponibilite_atelier().
    $pdo->exec("CREATE TABLE IF NOT EXISTS atelier_periodes (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        app       TEXT NOT NULL,
        atelier   TEXT NOT NULL,                     -- identifiant de l'atelier dans le catalogue
        classe_id INTEGER REFERENCES classes(id) ON DELETE CASCADE,   -- NULL = toutes les classes
        debut     TEXT NOT NULL DEFAULT '',          -- AAAA-MM-JJ inclus (vide = sans borne)
        fin       TEXT NOT NULL DEFAULT '',          -- AAAA-MM-JJ inclus (vide = sans borne)
        cree_le   INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_periodes_atelier ON atelier_periodes (app, atelier)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS imports_ateliers (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        app     TEXT NOT NULL,
        source  TEXT NOT NULL,
        adresse TEXT NOT NULL DEFAULT '',
        bilan   TEXT NOT NULL DEFAULT '',            -- JSON : ajoutes, modifies, retires, inchanges, remarques
        ts      INTEGER NOT NULL
    )");
}

/* ---------- Lecture et normalisation ---------- */

/** Niveau scolaire normalisé : « 5e », « 4e »… « lycee » ou vide. */
function niveau_atelier(mixed $v, string $groupe = '', string $titre = ''): string
{
    $t = is_scalar($v) ? trim((string) $v) : '';
    if ($t !== '' && !is_numeric($t) || preg_match('/^[3-6]$/', $t)) {
        if (preg_match('/^([3-6])\s*(?:e|è|ᵉ|ème|eme)?$/iu', $t, $m)) {
            return $m[1] . 'e';
        }
        if (preg_match('/^(sixi|cinqui|quatri|troisi)/iu', $t, $m)) {
            return ['sixi' => '6e', 'cinqui' => '5e', 'quatri' => '4e', 'troisi' => '3e'][mb_strtolower($m[1])];
        }
        if (preg_match('/lyc|sti2d|snt|nsi|pro|2nde|seconde|terminale/iu', $t)) {
            return 'lycee';
        }
    }
    // Sinon d'après le groupe (« 5ᵉ · Débutant », « SNT · … ») ; jamais d'après le titre (« Ma première esquisse »)
    if (preg_match('/(?<![\p{L}\d])([3-6])\s*(?:e|è|ᵉ|ème|eme)(?![\p{L}])/iu', $groupe, $m)) {
        return $m[1] . 'e';
    }
    return preg_match('/(?<![\p{L}])(STI2D|SNT|NSI|lycée|lycee|BTS|CAP|bac pro|2nde|seconde|1re|1ère|terminale|Tle)(?![\p{L}])/iu', $groupe) ? 'lycee' : '';
}

/** Codes TechnoStudio (T2C11) → codes du référentiel (SFC11), d'après referentiel()['correspondances']. */
function convertir_codes_technostudio(array $codes): array
{
    $corr = referentiel()['correspondances']['technostudio'] ?? ['T1' => 'OST', 'T2' => 'SFC', 'T3' => 'CCRI'];
    $res = [];
    foreach ($codes as $c) {
        $c = is_array($c) ? (string) ($c['code'] ?? '') : (string) $c;
        if (preg_match('/^(T\d)C(\d+(?:\.\d+\.\d+)?)$/i', trim($c), $m) && isset($corr[strtoupper($m[1])])) {
            $c = $corr[strtoupper($m[1])] . $m[2];
        }
        $res[] = $c;
    }
    return $res;
}

/** Codes de compétences d'un atelier ou d'une activité (codes du référentiel, ou codes TechnoStudio et leurs « sub »). */
function codes_atelier(mixed $v): array
{
    if (is_string($v)) {
        return codes_valides($v);
    }
    $brut = [];
    // Codes de la fiche TechnoStudio : le lien « ref » vers le référentiel passe avant la conversion approchée T2C11 → SFC11
    $code_ou_ref = fn(array $x) => isset($x['ref']) ? array_map('strval', (array) $x['ref']) : [(string) ($x['code'] ?? '')];
    foreach ((array) $v as $c) {
        if (is_array($c)) {
            array_push($brut, ...$code_ou_ref($c));
            foreach ((array) ($c['sub'] ?? []) as $s) {
                is_array($s) ? array_push($brut, ...$code_ou_ref($s)) : $brut[] = (string) $s;
            }
        } elseif (is_scalar($c)) {
            $brut[] = (string) $c;
        }
    }
    return codes_valides(convertir_codes_technostudio($brut));
}

/** Thème dominant (OST, SFC, CCRI) d'une liste de codes. */
function theme_codes(array $codes): string
{
    $n = [];
    foreach ($codes as $c) {
        $t = index_competences()[$c]['theme'] ?? '';
        if ($t !== '') {
            $n[$t] = ($n[$t] ?? 0) + 1;
        }
    }
    arsort($n);
    return (string) (array_key_first($n) ?? '');
}

/** Résout une adresse relative (« ateliers/e5-1.json ») par rapport à l'adresse d'un fichier. */
function resoudre_adresse(string $base, string $rel): string
{
    $rel = trim($rel);
    if ($rel === '' || preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    $p = parse_url($base);
    if (!$p || empty($p['host'])) {
        return $rel;
    }
    $racine = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $dossier = str_starts_with($rel, '/') ? '' : preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
    $segments = [];
    foreach (explode('/', ($dossier ?: '') . $rel) as $s) {
        if ($s === '..') {
            array_pop($segments);
        } elseif ($s !== '.' && $s !== '') {
            $segments[] = $s;
        }
    }
    return $racine . '/' . implode('/', $segments) . (str_ends_with($rel, '/') ? '/' : '');
}

/** Activités d'un atelier, normalisées. $connues : activités du descripteur (titres, totaux) par identifiant. */
function activites_atelier(mixed $v, array $connues = []): array
{
    $res = [];
    foreach ((array) $v as $a) {
        $a = is_array($a) ? $a : ['id' => (string) $a];
        $id = activite_propre((string) ($a['id'] ?? ''));
        if ($id === '' || isset($res[$id])) {
            continue;
        }
        $c = $connues[$id] ?? [];
        $total = $a['total'] ?? $c['total'] ?? null;
        $res[$id] = ['id' => $id, 'titre' => mb_substr(trim((string) ($a['titre'] ?? $c['titre'] ?? $id)), 0, 120),
                     'total' => is_numeric($total) ? max(0, min(1000, (int) $total)) : null,
                     'competences' => codes_atelier($a['competences'] ?? $c['competences'] ?? [])];
    }
    return array_values($res);
}

/**
 * Un atelier du catalogue, normalisé. Renvoie null si l'identifiant manque.
 * Champs reconnus : id, titre, niveau, resume, duree, competences, activites, ouvrir, fichier, version, auteur.
 */
function normaliser_atelier(array $a, array $connues = [], string $base = ''): ?array
{
    $id = activite_propre((string) ($a['id'] ?? ''));
    if ($id === '') {
        return null;
    }
    $acts = activites_atelier($a['activites'] ?? [], $connues);
    $codes = codes_atelier($a['competences'] ?? []);
    foreach ($acts as $x) {
        $codes = array_merge($codes, $x['competences']);
    }
    $codes = array_values(array_unique($codes));
    $titre = trim((string) ($a['titre'] ?? $a['title'] ?? ''));
    // « ouvrir » : une activité (e5-1) ou un lien propre à l'application, relatif à son adresse (#at-lampe, ?exo=e5-1)
    $ouvrir = trim((string) ($a['ouvrir'] ?? ''));
    $ouvrir = preg_match('/^[#?][A-Za-z0-9_.\/%=&+-]{1,200}$/', $ouvrir) ? $ouvrir : activite_propre($ouvrir);
    $duree = $a['duree'] ?? '';
    return [
        'atelier' => $id,
        'titre' => mb_substr($titre !== '' ? $titre : $id, 0, 120),
        'niveau' => niveau_atelier($a['niveau'] ?? '', (string) ($a['groupe'] ?? ''), $titre),
        'theme' => in_array($a['theme'] ?? '', ['OST', 'SFC', 'CCRI'], true) ? $a['theme'] : theme_codes($codes),
        'resume' => mb_substr(trim((string) ($a['resume'] ?? '')), 0, 600),
        'duree' => mb_substr(is_numeric($duree) ? $duree . ' min' : trim((string) $duree), 0, 40),
        'competences' => implode(', ', $codes),
        'activites' => $acts,
        'ouvrir' => $ouvrir !== '' ? $ouvrir : ($acts[0]['id'] ?? ''),
        'fichier' => mb_substr($base !== '' ? resoudre_adresse($base, (string) ($a['fichier'] ?? '')) : trim((string) ($a['fichier'] ?? '')), 0, 300),
        'version' => mb_substr(trim((string) ($a['version'] ?? '')), 0, 20),
        'auteur' => mb_substr(trim((string) ($a['auteur'] ?? '')), 0, 80),
    ];
}

/** Activités du descripteur indexées par identifiant. */
function activites_descripteur(?array $d): array
{
    $res = [];
    foreach ((array) ($d['activites']['liste'] ?? []) as $a) {
        if (is_array($a) && isset($a['id'])) {
            $res[activite_propre((string) $a['id'])] = $a;
        }
    }
    return $res;
}

/**
 * Lit un catalogue « catalogue.ateliers » v1. Renvoie [ateliers normalisés, remarques].
 * $base : adresse du fichier (pour les adresses relatives des contenus).
 */
function lire_catalogue_ateliers(array $c, string $app, array $connues = [], string $base = ''): array
{
    $rem = [];
    if (($c['format'] ?? '') !== FORMAT_CATALOGUE) {
        return [[], ['Ce fichier n’est pas un catalogue d’ateliers (format « ' . FORMAT_CATALOGUE . ' » attendu).']];
    }
    if ((int) ($c['version'] ?? 0) > 1) {
        $rem[] = 'Catalogue de version ' . (int) $c['version'] . ' : lu comme la version 1 (champs inconnus ignorés).';
    }
    if (isset($c['app']) && normaliser_app((string) $c['app']) !== $app) {
        $rem[] = 'Le catalogue annonce l’application « ' . $c['app'] . ' » au lieu de « ' . $app . ' ».';
    }
    $res = [];
    foreach ((array) ($c['ateliers'] ?? []) as $i => $a) {
        $n = is_array($a) ? normaliser_atelier($a, $connues, $base) : null;
        if (!$n) {
            $rem[] = 'Atelier n° ' . ($i + 1) . ' ignoré : il lui manque « id ».';
        } elseif (isset($res[$n['atelier']])) {
            $rem[] = 'Identifiant « ' . $n['atelier'] . ' » en double : seul le premier est gardé.';
        } else {
            $res[$n['atelier']] = $n;
        }
    }
    return [array_values($res), $rem];
}

/**
 * Pas encore de ateliers.json : ateliers tirés des activités du descripteur.
 * Regroupement : champ « atelier » de l'activité, sinon ce qui précède le dernier « / » (TechnoStudio :
 * charte-lampe-de-poche/P1 → charte-lampe-de-poche), sinon une activité = un atelier.
 */
function ateliers_depuis_descripteur(array $d): array
{
    $groupes = [];
    foreach ((array) ($d['activites']['liste'] ?? []) as $a) {
        if (!is_array($a) || !isset($a['id'])) {
            continue;
        }
        $id = activite_propre((string) $a['id']);
        $cle = isset($a['atelier']) ? activite_propre((string) $a['atelier']) : (str_contains($id, '/') ? substr($id, 0, strrpos($id, '/')) : $id);
        $groupes[$cle][] = $a;
    }
    $res = [];
    foreach ($groupes as $cle => $acts) {
        $p = $acts[0];
        $groupe = trim((string) ($p['groupe'] ?? ''));
        $titre = count($acts) === 1 && !str_contains((string) $p['id'], '/') ? (string) ($p['titre'] ?? $cle)
            : (preg_match('/·\s*(.+)$/u', $groupe, $m) ? $m[1] : ($groupe !== '' ? $groupe : $cle));
        $n = normaliser_atelier(['id' => $cle, 'titre' => $titre, 'niveau' => $p['classe'] ?? '', 'groupe' => $groupe,
                                 'resume' => $p['resume'] ?? '', 'duree' => $p['duree'] ?? '', 'activites' => $acts]);
        if ($n) {
            $res[] = $n;
        }
    }
    return $res;
}

/** Atelier TechnoStudio (Workshop : id, title, steps…) → atelier du catalogue. Les activités viennent de l'application. */
function atelier_depuis_technostudio(array $w): ?array
{
    return normaliser_atelier(['id' => $w['id'] ?? '', 'titre' => $w['title'] ?? '', 'niveau' => $w['niveau'] ?? $w['level'] ?? '',
        'resume' => $w['summary'] ?? $w['problematique'] ?? '', 'duree' => $w['duration'] ?? '', 'competences' => $w['competences'] ?? [],
        'version' => isset($w['version']) ? (string) $w['version'] : '', 'auteur' => $w['author'] ?? '']);
}

/**
 * Fichier déposé par l'enseignant → [ateliers normalisés, remarques, type reconnu].
 * Reconnaît : catalogue.ateliers, un atelier seul ({id, titre…}), un atelier TechnoStudio ou une liste d'ateliers TechnoStudio.
 */
function ateliers_depuis_fichier(mixed $j, string $app, array $connues = []): array
{
    if (is_array($j) && ($j['format'] ?? '') === FORMAT_CATALOGUE) {
        [$l, $r] = lire_catalogue_ateliers($j, $app, $connues);
        return [$l, $r, 'catalogue d’ateliers'];
    }
    $liste = is_array($j) && array_is_list($j) ? $j : [$j];
    $res = [];
    $type = '';
    foreach ($liste as $w) {
        if (!is_array($w)) {
            continue;
        }
        if (isset($w['steps']) && is_array($w['steps']) && isset($w['title'])) {
            $n = atelier_depuis_technostudio($w);
            $type = 'atelier TechnoStudio';
        } else {
            $n = normaliser_atelier($w, $connues);
            $type = $type ?: 'atelier';
        }
        if ($n) {
            $res[$n['atelier']] = $n;
        }
    }
    return [array_values($res), $res ? [] : ['Aucun atelier reconnu dans ce fichier (il faut au moins « id » et « titre »).'], $type];
}

/* ---------- Enregistrement ---------- */

function empreinte_atelier(array $n): string
{
    return sha1((string) json_encode($n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Range des ateliers normalisés dans le catalogue de la Centrale.
 * $complet = true (import depuis l'application) : ceux qui ne sont plus publiés passent à l'état « retiré »
 * (jamais effacés : du travail d'élève peut y être rattaché). Les activités rejoignent le catalogue des activités.
 * Renvoie le bilan : ajoutes, modifies, retires (listes de titres), inchanges (nombre).
 */
function ranger_ateliers(string $app, array $ateliers, string $source, bool $complet, array $contenus = []): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT atelier, titre, empreinte, etat, source FROM catalogue_ateliers WHERE app = ?');
    $st->execute([$app]);
    $avant = [];
    foreach ($st->fetchAll() as $r) {
        $avant[$r['atelier']] = $r;
    }
    $bilan = ['ajoutes' => [], 'modifies' => [], 'retires' => [], 'inchanges' => 0];
    $maintenant = time();
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO catalogue_ateliers (app, atelier, titre, niveau, theme, resume, duree, competences, activites, ouvrir,
                              fichier, version, auteur, source, etat, empreinte, contenu, ordre, importe_le, modifie_le)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif', ?, ?, ?, ?, ?)
                          ON CONFLICT (app, atelier) DO UPDATE SET titre = excluded.titre, niveau = excluded.niveau, theme = excluded.theme,
                              resume = excluded.resume, duree = excluded.duree, competences = excluded.competences, activites = excluded.activites,
                              ouvrir = excluded.ouvrir, fichier = excluded.fichier, version = excluded.version, auteur = excluded.auteur,
                              source = excluded.source, etat = 'actif', empreinte = excluded.empreinte,
                              contenu = CASE WHEN excluded.contenu <> '' THEN excluded.contenu ELSE catalogue_ateliers.contenu END,
                              ordre = excluded.ordre, importe_le = excluded.importe_le,
                              modifie_le = CASE WHEN catalogue_ateliers.empreinte = excluded.empreinte AND catalogue_ateliers.etat = 'actif'
                                                THEN catalogue_ateliers.modifie_le ELSE excluded.modifie_le END");
    $vus = [];
    $activites = [];
    foreach (array_values($ateliers) as $i => $n) {
        $e = empreinte_atelier($n);
        $vus[$n['atelier']] = true;
        $a = $avant[$n['atelier']] ?? null;
        if (!$a) {
            $bilan['ajoutes'][] = $n['titre'];
        } elseif ($a['empreinte'] !== $e || $a['etat'] !== 'actif') {
            $bilan['modifies'][] = $n['titre'];
        } else {
            $bilan['inchanges']++;
        }
        $ins->execute([$app, $n['atelier'], $n['titre'], $n['niveau'], $n['theme'], $n['resume'], $n['duree'], $n['competences'],
                       json_encode($n['activites'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $n['ouvrir'], $n['fichier'],
                       $n['version'], $n['auteur'], $source, $e, $contenus[$n['atelier']] ?? '', $i, $maintenant, $maintenant]);
        foreach ($n['activites'] as $x) {
            $activites[] = ['id' => $x['id'], 'titre' => $x['titre'], 'groupe' => $n['titre'], 'total' => $x['total'], 'competences' => $x['competences']];
        }
    }
    if ($complet && $ateliers) {
        $ret = $pdo->prepare("UPDATE catalogue_ateliers SET etat = 'retire', modifie_le = ? WHERE app = ? AND atelier = ?");
        foreach ($avant as $id => $a) {
            // Un fichier importé à la main n'est pas « retiré » parce que l'application ne le publie pas encore ;
            // un atelier transféré par la Centrale ne dépend pas de ce que publie l'application
            if (!isset($vus[$id]) && $a['etat'] === 'actif' && $a['source'] !== 'fichier' && $a['source'] !== 'centrale') {
                $ret->execute([$maintenant, $app, $id]);
                $bilan['retires'][] = $a['titre'];
            }
        }
    }
    $pdo->commit();
    if ($activites && $source !== 'descripteur') {
        // Les activités de centrale.json sont déjà au catalogue (enregistrer_descripteur)
        ecrire_catalogue($app, $activites, false);
    }
    return $bilan;
}

function journaliser_import(string $app, string $source, string $adresse, array $bilan): void
{
    db()->prepare('INSERT INTO imports_ateliers (app, source, adresse, bilan, ts) VALUES (?, ?, ?, ?, ?)')
        ->execute([$app, $source, $adresse, json_encode($bilan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
}

/** Adresses où chercher ateliers.json : champ « ateliers.catalogue » du descripteur, sinon à côté de centrale.json, puis copies de secours. */
function adresses_catalogue(array $atelier): array
{
    $d = descripteur_de($atelier['app']);
    $rel = is_array($d['ateliers'] ?? null) ? (string) ($d['ateliers']['catalogue'] ?? '') : '';
    $rel = $rel !== '' ? $rel : 'ateliers.json';
    $bases = [];
    if (!empty($atelier['descripteur_url'])) {
        $bases[] = (string) $atelier['descripteur_url'];
    }
    foreach (adresses_atelier($atelier) as $u) {
        $u = preg_replace('#[?\#].*$#', '', $u) ?? $u;
        // « …/dist/test.html » : le catalogue est à côté de la page, pas dessous
        $bases[] = preg_match('#/[^/]+\.[a-z0-9]+$#i', $u) ? resoudre_adresse($u, 'centrale.json') : rtrim($u, '/') . '/centrale.json';
    }
    $res = [];
    foreach ($bases as $b) {
        $res[] = resoudre_adresse($b, $rel);
    }
    return array_values(array_unique($res));
}

/**
 * Importe les ateliers d'une application depuis ce qu'elle publie. Renvoie
 * ['ok' => bool, 'source' => catalogue|descripteur, 'adresse' => …, 'bilan' => …, 'remarques' => [], 'n' => nombre].
 */
function importer_ateliers_application(string $app): array
{
    $st = db()->prepare('SELECT * FROM ateliers WHERE app = ?');
    $st->execute([$app]);
    $at = $st->fetch();
    if (!$at) {
        return ['ok' => false, 'remarques' => ["Atelier « $app » inconnu."]];
    }
    $erreurs = [];
    $connues = activites_descripteur(descripteur_de($app));
    $bilan_centrale = importer_ateliers_centrale($app);   // d'abord : ses ateliers ne seront pas « retirés » ci-dessous
    // Ateliers transférés par la Centrale (ateliers/<app>/) : leur fichier passe avant ce que publie l'application
    $centrale = fichiers_ateliers_centrale($app);
    $sans_centrale = fn(array $l) => array_values(array_filter($l, fn($n) => !isset($centrale[$n['atelier']])));
    foreach (adresses_catalogue($at) as $url) {
        [$c, $err] = telecharger_descripteur($url);
        if ($c) {
            [$liste, $rem] = lire_catalogue_ateliers($c, $app, $connues, $url);
            $liste = $sans_centrale($liste);
            if ($liste) {
                $bilan = ranger_ateliers($app, $liste, 'catalogue', true);
                $bilan['remarques'] = $rem;
                journaliser_import($app, 'catalogue', $url, $bilan);
                return ['ok' => true, 'source' => 'catalogue', 'adresse' => $url, 'bilan' => $bilan, 'remarques' => $rem, 'n' => count($liste),
                        'centrale' => $bilan_centrale];
            }
            $erreurs[] = "$url : " . ($rem ? implode(' ', $rem) : 'catalogue vide.');
            break;
        }
        if (str_contains((string) $err, 'Pas de centrale.json')) {
            break;   // le serveur répond mais n'a pas (encore) de ateliers.json : inutile de chercher sur les copies
        }
        $erreurs[] = (string) $err;   // injoignable : on essaie la copie suivante
    }
    // Pas de catalogue : activités du descripteur (relu en ligne s'il répond, sinon celui déjà enregistré)
    $d = null;
    $adresse = (string) ($at['descripteur_url'] ?: $at['url']);
    [$lu, $url_lue, $err] = lire_passerelle_distante($adresse);
    if ($lu && !isset($lu['_converti']) && !verifier_descripteur($lu)[0] && normaliser_app((string) ($lu['atelier']['app'] ?? '')) === $app) {
        enregistrer_descripteur($lu, $url_lue);
        $d = $lu;
        $adresse = $url_lue;
    } else {
        $d = descripteur_complet($app);
        if ($err) {
            $erreurs[] = $err;
        }
    }
    $liste = $d ? $sans_centrale(ateliers_depuis_descripteur($d)) : [];
    if (!$liste) {
        return ['ok' => false, 'centrale' => $bilan_centrale,
                'remarques' => array_merge($erreurs, ['Aucun atelier trouvé : l’application ne publie ni ateliers.json ni activités dans centrale.json.'])];
    }
    $bilan = ranger_ateliers($app, $liste, 'descripteur', true);
    $bilan['remarques'] = $erreurs;
    journaliser_import($app, 'descripteur', $adresse, $bilan);
    return ['ok' => true, 'source' => 'descripteur', 'adresse' => $adresse, 'bilan' => $bilan, 'remarques' => $erreurs, 'n' => count($liste),
            'centrale' => $bilan_centrale];
}

/** Bilan lisible : « 2 ajoutés, 1 modifié, 15 inchangés ». */
function texte_bilan(array $b): string
{
    $p = [];
    foreach (['ajoutes' => 'ajouté', 'modifies' => 'modifié', 'retires' => 'retiré'] as $k => $mot) {
        $n = count($b[$k] ?? []);
        if ($n) {
            $p[] = $n . ' ' . $mot . ($n > 1 ? 's' : '');
        }
    }
    if (!empty($b['inchanges'])) {
        $p[] = $b['inchanges'] . ' inchangé' . ($b['inchanges'] > 1 ? 's' : '');
    }
    return $p ? implode(', ', $p) : 'rien à changer';
}

/** Ateliers du catalogue (tous, ou d'une application), dans l'ordre des applications puis de l'application. */
function catalogue_ateliers(?string $app = null, bool $avec_retires = true): array
{
    synchroniser_ateliers_centrale();   // fichiers ateliers/<app>/ ajoutés, changés ou retirés depuis la dernière lecture
    $sql = 'SELECT c.*, a.nom AS nom_app, a.icone AS icone_app, a.url AS url_app FROM catalogue_ateliers c
            LEFT JOIN ateliers a ON a.app = c.app WHERE 1 = 1' . ($app !== null ? ' AND c.app = ?' : '')
        . ($avec_retires ? '' : " AND c.etat = 'actif'") . ' ORDER BY COALESCE(a.ordre, 999), c.app, c.etat, c.ordre, c.titre';
    $st = db()->prepare($sql);
    $st->execute($app !== null ? [$app] : []);
    $res = [];
    foreach ($st->fetchAll() as $r) {
        $r['activites'] = json_decode((string) $r['activites'], true) ?: [];
        $r['codes'] = array_values(array_filter(array_map('trim', explode(',', (string) $r['competences']))));
        $res[] = $r;
    }
    return $res;
}

/**
 * Adresse de l'application pour « Ouvrir » : sa copie sur cette Centrale (/miroir/<app>/, à l'adresse de la page en cours) si elle
 * existe, comme le portail des élèves ; sinon la première adresse qui répond dans l'ordre des emplacements ; sinon son adresse principale.
 */
function adresse_application_ouvrir(string $app, string $principale): string
{
    static $memo = [];
    if (isset($memo[$app])) {
        return $memo[$app];
    }
    if (preg_match('/^[A-Za-z0-9_-]+$/', $app) && is_file(__DIR__ . '/../public/miroir/' . $app . '/index.html')) {
        return $memo[$app] = adresse_complete('/miroir/' . $app . '/');
    }
    $st = db()->prepare('SELECT * FROM ateliers WHERE app = ?');
    $st->execute([$app]);
    $a = $st->fetch();
    return $memo[$app] = $a ? meilleure_adresse($a) : $principale;
}

/** Adresse pour ouvrir un atelier dans son application (sans connexion : l'application doit lire #activite=). */
function adresse_ouvrir_atelier(array $r): string
{
    $u = adresse_application_ouvrir((string) $r['app'], (string) ($r['url_app'] ?? ''));
    if ($u === '') {
        return '';
    }
    $o = (string) $r['ouvrir'];
    if ($o === '') {
        return $u;
    }
    $sans = preg_replace('/[?#].*$/', '', $u) ?? $u;
    return $o[0] === '#' || $o[0] === '?' ? $sans . $o : $sans . '#activite=' . rawurlencode($o);
}

/* ---------- Périodes de disponibilité des ateliers ----------
   Une période : du … au … (dates incluses ; vide = sans borne), pour une classe ou pour toutes.
   Aucune période pour une classe = atelier ouvert en permanence (comportement historique).
   Plusieurs périodes = ouvert pendant chacune. Hors période : « bientôt » (une période doit encore venir)
   ou « fermé » (toutes passées). Les applications restent ouvrables sans la Centrale : ces périodes règlent
   ce que le portail, les parcours et les propositions montrent aux élèves. */

/** Périodes, rangées [app][atelier] => [ {id, classe_id, classe, debut, fin} ]. */
function periodes_ateliers(?string $app = null): array
{
    $st = db()->prepare('SELECT p.*, c.nom AS classe FROM atelier_periodes p LEFT JOIN classes c ON c.id = p.classe_id'
        . ($app !== null ? ' WHERE p.app = ?' : '') . " ORDER BY p.app, p.atelier, p.debut = '', p.debut, p.id");
    $st->execute($app !== null ? [$app] : []);
    $res = [];
    foreach ($st->fetchAll() as $r) {
        $r['classe_id'] = $r['classe_id'] === null ? null : (int) $r['classe_id'];
        $res[$r['app']][$r['atelier']][] = $r;
    }
    return $res;
}

/**
 * État d'un atelier pour une classe (null = périodes « toutes les classes » seulement) à une date (aujourd'hui par défaut).
 * → ['etat' => ouvert|bientot|ferme, 'ouvre_le' => AAAA-MM-JJ|null, 'jusqu_au' => AAAA-MM-JJ|null, 'limite' => bool]
 *   limite : des périodes s'appliquent (sinon ouvert sans limite).
 */
function disponibilite_atelier(array $periodes, ?int $classe_id, ?string $date = null): array
{
    $date ??= aujourd_hui();
    $p = array_values(array_filter($periodes, fn($x) => $x['classe_id'] === null || $x['classe_id'] === $classe_id));
    if (!$p) {
        return ['etat' => 'ouvert', 'ouvre_le' => null, 'jusqu_au' => null, 'limite' => false];
    }
    $ouvert = false;
    $jusqu = null;     // dernière fin des périodes en cours ('' = sans fin)
    $prochain = null;
    foreach ($p as $x) {
        $d = (string) $x['debut'];
        $f = (string) $x['fin'];
        if (($d === '' || $d <= $date) && ($f === '' || $f >= $date)) {
            $ouvert = true;
            $jusqu = $jusqu === '' || $f === '' ? '' : max((string) $jusqu, $f);
        } elseif ($d !== '' && $d > $date) {
            $prochain = $prochain === null ? $d : min($prochain, $d);
        }
    }
    if ($ouvert) {
        return ['etat' => 'ouvert', 'ouvre_le' => null, 'jusqu_au' => $jusqu ?: null, 'limite' => true];
    }
    return ['etat' => $prochain !== null ? 'bientot' : 'ferme', 'ouvre_le' => $prochain, 'jusqu_au' => null, 'limite' => true];
}

/**
 * États des ateliers qui ont des périodes, pour une classe : [app][atelier] => disponibilite_atelier().
 * Si la classe a une progression (lib/progression.php), elle est rangée sous la clé '*progression' :
 * disponibilite_activite() s'en sert d'abord (un atelier hors progression y est fermé).
 */
function disponibilites_classe(?int $classe_id): array
{
    $res = [];
    foreach (periodes_ateliers() as $app => $liste) {
        foreach ($liste as $atelier => $periodes) {
            $res[$app][$atelier] = disponibilite_atelier($periodes, $classe_id);
        }
    }
    if ($classe_id !== null && ($p = disponibilites_progression($classe_id)) !== null) {
        $res['*progression'] = $p;
    }
    return $res;
}

/** Atelier du catalogue auquel appartient une activité (« atelier/P1 », ou l'atelier lui-même), ou null. */
function atelier_de_activite(string $app, string $activite): ?string
{
    $carte = carte_activites_ateliers()[0];
    if ($activite === '') {
        return null;
    }
    if (isset($carte[$app][$activite])) {
        return $carte[$app][$activite];
    }
    $avant = explode('/', $activite)[0];
    return isset($carte[$app][$avant]) ? $carte[$app][$avant] : null;
}

/** [atelier principal de chaque activité, tous les ateliers qui la contiennent] : [app][activite] => atelier / [ateliers]. */
function carte_activites_ateliers(): array
{
    static $res = null;
    if ($res === null) {
        $carte = [];
        $tous = [];
        foreach (db()->query('SELECT app, atelier, activites FROM catalogue_ateliers') as $r) {
            $carte[$r['app']][$r['atelier']] = $r['atelier'];
            foreach (json_decode((string) $r['activites'], true) ?: [] as $x) {
                if (isset($x['id']) && is_string($x['id'])) {
                    $carte[$r['app']][$x['id']] ??= $r['atelier'];
                    $tous[$r['app']][$x['id']][] = $r['atelier'];
                }
            }
        }
        $res = [$carte, $tous];
    }
    return $res;
}

/**
 * Atelier d'une activité pour une classe qui a un calendrier : une même activité peut être dans plusieurs ateliers
 * (un exercice seul, et un atelier qui en enchaîne plusieurs, comme « Premiers pas » de NetSim). On prend celui qui est
 * au calendrier de la classe (ouvert d'abord, puis bientôt), sinon l'atelier principal.
 */
function atelier_de_activite_classe(array $dispos, string $app, string $activite): ?string
{
    $principal = atelier_de_activite($app, $activite);
    $prog = $dispos['*progression'][$app] ?? null;
    if ($prog === null || ($principal !== null && ($prog[$principal]['etat'] ?? '') === 'ouvert')) {
        return $principal;
    }
    $choix = isset($prog[$principal ?? '']) ? $principal : null;
    $ordre = ['ouvert' => 0, 'bientot' => 1, 'ferme' => 2];
    foreach (carte_activites_ateliers()[1][$app][$activite] ?? [] as $at) {
        if (isset($prog[$at]) && ($choix === null || $ordre[$prog[$at]['etat']] < $ordre[$prog[$choix]['etat']])) {
            $choix = $at;
        }
    }
    return $choix ?? $principal;
}

/**
 * État de l'atelier d'une activité pour une classe (ouvert sans limite si l'activité n'est rattachée à aucun atelier).
 * Classe avec une progression : c'est elle qui décide (hors progression = fermé), sauf $avec_progression = false
 * (parcours et propositions : demandes explicites de l'enseignant, réglées par les seules périodes).
 */
function disponibilite_activite(array $dispos, string $app, string $activite, bool $avec_progression = true): array
{
    $at = $avec_progression ? atelier_de_activite_classe($dispos, $app, $activite) : atelier_de_activite($app, $activite);
    if ($avec_progression && isset($dispos['*progression'])) {
        return ($at !== null ? ($dispos['*progression'][$app][$at] ?? null) : null)
            ?? ['etat' => 'ferme', 'ouvre_le' => null, 'jusqu_au' => null, 'limite' => true, 'source' => 'progression'];
    }
    return ($at !== null ? ($dispos[$app][$at] ?? null) : null) ?? ['etat' => 'ouvert', 'ouvre_le' => null, 'jusqu_au' => null, 'limite' => false];
}

/** Texte court d'une période pour l'enseignant : « du 5 janv. au 20 févr. · 4A ». */
function texte_periode(array $p): string
{
    $f = fn(string $d) => $d === '' ? '' : (new DateTimeImmutable($d))->format('d/m/Y');
    $t = $p['debut'] !== '' && $p['fin'] !== '' ? 'du ' . $f($p['debut']) . ' au ' . $f($p['fin'])
        : ($p['debut'] !== '' ? 'à partir du ' . $f($p['debut']) : ($p['fin'] !== '' ? 'jusqu’au ' . $f($p['fin']) : 'toujours'));
    return $t . ' · ' . ($p['classe_id'] === null ? 'toutes les classes' : (string) ($p['classe'] ?? '?'));
}

