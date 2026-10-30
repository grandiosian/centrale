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

        -- Avancement déclaré par les applications : une ligne par élève / atelier / activité
        CREATE TABLE IF NOT EXISTS progression (
            eleve_id  INTEGER NOT NULL REFERENCES eleves(id) ON DELETE CASCADE,
            app       TEXT NOT NULL,
            activite  TEXT NOT NULL,
            titre     TEXT NOT NULL DEFAULT '',
            statut    TEXT NOT NULL DEFAULT 'commence',   -- commence | termine
            faites    INTEGER,                             -- étapes faites (meilleur résultat)
            total     INTEGER,                             -- nombre d'étapes
            score     REAL,                                -- meilleur score
            score_max REAL,
            details   TEXT,                                -- JSON libre (réponses, erreurs…)
            debut     INTEGER NOT NULL,
            maj       INTEGER NOT NULL,
            fin       INTEGER,
            PRIMARY KEY (eleve_id, app, activite)
        );
        CREATE INDEX IF NOT EXISTS idx_progression_app ON progression (app, activite);

        -- Catalogue des activités d'un atelier (déclaré par l'application) : colonnes du suivi
        CREATE TABLE IF NOT EXISTS activites (
            app      TEXT NOT NULL,
            activite TEXT NOT NULL,
            titre    TEXT NOT NULL DEFAULT '',
            groupe   TEXT NOT NULL DEFAULT '',
            total    INTEGER,
            ordre    INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (app, activite)
        );

        -- Parcours : enchaînement d'étapes, éventuellement dans plusieurs logiciels
        CREATE TABLE IF NOT EXISTS parcours (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            nom         TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            classes     TEXT NOT NULL DEFAULT '',          -- "5A,5B" ; vide = toutes les classes
            cree_le     INTEGER NOT NULL
        );
        -- Ateliers proposés aux élèves sur le portail (registre des applications)
        CREATE TABLE IF NOT EXISTS ateliers (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            app         TEXT NOT NULL UNIQUE,
            nom         TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            url         TEXT NOT NULL,
            icone       TEXT NOT NULL DEFAULT '',
            classes     TEXT NOT NULL DEFAULT '',          -- vide = toutes les classes
            ordre       INTEGER NOT NULL DEFAULT 0,
            actif       INTEGER NOT NULL DEFAULT 1,
            urls_secours TEXT NOT NULL DEFAULT ''          -- autres adresses, une par ligne
        );

        -- Journal des ouvertures d'ateliers : d'où l'application a été chargée (pas d'adresse IP)
        CREATE TABLE IF NOT EXISTS connexions (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            eleve_id INTEGER NOT NULL REFERENCES eleves(id) ON DELETE CASCADE,
            app      TEXT NOT NULL,
            origine  TEXT NOT NULL,                          -- ex. https://grandiosian.github.io
            moyen    TEXT NOT NULL,                          -- code | portail
            ts       INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_connexions ON connexions (eleve_id, ts);

        -- ===== Organisation : établissements, grilles horaires, salles, emploi du temps =====
        CREATE TABLE IF NOT EXISTS etablissements (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            nom             TEXT NOT NULL,
            zone            TEXT NOT NULL DEFAULT '',        -- A, B, C ou vide (vacances scolaires)
            ab_actif        INTEGER NOT NULL DEFAULT 0,      -- semaines A/B
            ref_semaine_a   TEXT NOT NULL DEFAULT '',        -- un lundi de semaine A (AAAA-MM-JJ)
            ab_sans_vacances INTEGER NOT NULL DEFAULT 1,     -- les semaines de vacances ne comptent pas dans l'alternance
            samedi          INTEGER NOT NULL DEFAULT 0,      -- cours le samedi
            couleur         TEXT NOT NULL DEFAULT '',
            cree_le         INTEGER NOT NULL
        );
        -- Grille horaire : M1 08:00-08:55, M2…
        CREATE TABLE IF NOT EXISTS creneaux (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            etablissement_id INTEGER NOT NULL REFERENCES etablissements(id) ON DELETE CASCADE,
            ordre           INTEGER NOT NULL,
            libelle         TEXT NOT NULL,
            debut           TEXT NOT NULL,                   -- HH:MM
            fin             TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS vacances (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            etablissement_id INTEGER NOT NULL REFERENCES etablissements(id) ON DELETE CASCADE,
            libelle         TEXT NOT NULL,
            debut           TEXT NOT NULL,                   -- premier jour sans cours (AAAA-MM-JJ)
            fin             TEXT NOT NULL,                   -- jour de reprise (exclu)
            source          TEXT NOT NULL DEFAULT 'manuel'   -- officiel | manuel
        );
        -- Salles et leur plan (objets placés : tables, postes, places élèves…)
        CREATE TABLE IF NOT EXISTS salles (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            etablissement_id INTEGER NOT NULL REFERENCES etablissements(id) ON DELETE CASCADE,
            nom             TEXT NOT NULL,
            plan            TEXT NOT NULL DEFAULT ''         -- JSON { largeur, hauteur, objets: [...] }
        );
        -- Placement des élèves d'une classe dans une salle : place -> élève
        CREATE TABLE IF NOT EXISTS placements (
            classe_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
            salle_id  INTEGER NOT NULL REFERENCES salles(id) ON DELETE CASCADE,
            place     TEXT NOT NULL,
            eleve_id  INTEGER NOT NULL REFERENCES eleves(id) ON DELETE CASCADE,
            PRIMARY KEY (classe_id, salle_id, place)
        );
        -- Cours récurrents (emploi du temps de la semaine type)
        CREATE TABLE IF NOT EXISTS cours (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            classe_id       INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
            jour            INTEGER NOT NULL,                -- 1 = lundi … 6 = samedi
            creneau_id      INTEGER NOT NULL REFERENCES creneaux(id) ON DELETE CASCADE,
            duree           INTEGER NOT NULL DEFAULT 1,      -- en nombre de créneaux
            semaine         TEXT NOT NULL DEFAULT '',        -- '' = toutes, 'A' ou 'B'
            salle_id        INTEGER REFERENCES salles(id) ON DELETE SET NULL,
            intitule        TEXT NOT NULL DEFAULT '',
            debut           TEXT NOT NULL DEFAULT '',        -- date de début (vide = toujours)
            fin             TEXT NOT NULL DEFAULT ''         -- date de fin (vide = toujours)
        );
        -- Modifications ponctuelles : cours annulé, modifié (déplacé, autre salle, note) ou ajouté
        CREATE TABLE IF NOT EXISTS seances_modifiees (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            cours_id        INTEGER REFERENCES cours(id) ON DELETE CASCADE,  -- vide = cours ajouté
            classe_id       INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
            date_origine    TEXT NOT NULL DEFAULT '',        -- date prévue du cours récurrent
            statut          TEXT NOT NULL,                   -- annule | modifie | ajoute
            date            TEXT NOT NULL DEFAULT '',        -- date effective (modifie / ajoute)
            creneau_id      INTEGER REFERENCES creneaux(id) ON DELETE SET NULL,
            duree           INTEGER NOT NULL DEFAULT 1,
            salle_id        INTEGER REFERENCES salles(id) ON DELETE SET NULL,
            intitule        TEXT NOT NULL DEFAULT '',
            note            TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_seances_date ON seances_modifiees (date);
        CREATE INDEX IF NOT EXISTS idx_seances_origine ON seances_modifiees (cours_id, date_origine);
        CREATE TABLE IF NOT EXISTS parcours_etapes (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            parcours_id INTEGER NOT NULL REFERENCES parcours(id) ON DELETE CASCADE,
            ordre       INTEGER NOT NULL,
            titre       TEXT NOT NULL,
            app         TEXT NOT NULL,
            activite    TEXT NOT NULL DEFAULT '',          -- vide = tout l'atelier
            url         TEXT NOT NULL DEFAULT ''
        );
        SQL);
    ajouter_colonne($pdo, 'ateliers', 'urls_secours', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($pdo, 'classes', 'etablissement_id', 'INTEGER REFERENCES etablissements(id) ON DELETE SET NULL');
    ajouter_colonne($pdo, 'etablissements', 'code', "TEXT NOT NULL DEFAULT ''");   // 3 lettres, dans les codes élèves
    ajouter_colonne($pdo, 'eleves', 'surnom', "TEXT NOT NULL DEFAULT ''");          // identifiant : 4C-LHA-<surnom>
    // Groupes d'une classe (demi-groupes GR1 / GR2…) : un élève appartient à un groupe au plus
    $pdo->exec('CREATE TABLE IF NOT EXISTS groupes (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        classe_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
        nom       TEXT NOT NULL,
        ordre     INTEGER NOT NULL DEFAULT 0
    )');
    ajouter_colonne($pdo, 'eleves', 'groupe_id', 'INTEGER REFERENCES groupes(id) ON DELETE SET NULL');
    ajouter_colonne($pdo, 'cours', 'groupe_id', 'INTEGER REFERENCES groupes(id) ON DELETE SET NULL');
    ajouter_colonne($pdo, 'seances_modifiees', 'groupe_id', 'INTEGER REFERENCES groupes(id) ON DELETE SET NULL');
    // Plans de classe : un placement par classe ENTIÈRE (groupe 0) ou par groupe
    $cols = array_column($pdo->query('PRAGMA table_info(placements)')->fetchAll(), 'name');
    if (!in_array('groupe_id', $cols, true)) {
        $pdo->exec('BEGIN IMMEDIATE');   // une seule requête fait la migration
        $cols = array_column($pdo->query('PRAGMA table_info(placements)')->fetchAll(), 'name');
        if (in_array('groupe_id', $cols, true)) {
            $pdo->exec('COMMIT');
            return;
        }
        $pdo->exec('CREATE TABLE placements_v2 (
            classe_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
            groupe_id INTEGER NOT NULL DEFAULT 0,
            salle_id  INTEGER NOT NULL REFERENCES salles(id) ON DELETE CASCADE,
            place     TEXT NOT NULL,
            eleve_id  INTEGER NOT NULL REFERENCES eleves(id) ON DELETE CASCADE,
            PRIMARY KEY (classe_id, groupe_id, salle_id, place)
        )');
        $pdo->exec('INSERT INTO placements_v2 (classe_id, groupe_id, salle_id, place, eleve_id) SELECT classe_id, 0, salle_id, place, eleve_id FROM placements');
        $pdo->exec('DROP TABLE placements');
        $pdo->exec('ALTER TABLE placements_v2 RENAME TO placements');
        $pdo->exec('COMMIT');
    }
}

/** Ajoute une colonne à une table existante (mise à jour d'une base créée par une version précédente). */
function ajouter_colonne(PDO $pdo, string $table, string $colonne, string $definition): void
{
    $colonnes = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (!in_array($colonne, $colonnes, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $colonne $definition");
    }
}

/* ---------- Origine des connexions ---------- */

/** Nom lisible de l'hébergement d'une application, d'après son origine (https://hote). */
function nom_origine(string $origine): string
{
    $hote = strtolower((string) parse_url($origine, PHP_URL_HOST));
    $port = parse_url($origine, PHP_URL_PORT);
    $ici = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return match (true) {
        $hote === '' => 'inconnue',
        $hote . ($port ? ':' . $port : '') === $ici => 'Serveur (Centrale)',
        str_ends_with($hote, '.github.io') => 'GitHub',
        str_ends_with($hote, 'forge.apps.education.fr') || str_contains($hote, 'gitlab') => 'Forge / GitLab',
        str_ends_with($hote, '.alwaysdata.net') => 'alwaysdata',
        $hote === 'localhost' || $hote === '127.0.0.1' => 'Ordinateur local',
        default => $hote,
    };
}

/** Enregistre l'ouverture d'un atelier par un élève (origine = site qui héberge l'application). */
function noter_connexion(int $eleve_id, string $app, string $moyen): void
{
    $origine = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origine === '' || $origine === 'null') {
        // Même site que la Centrale : le navigateur n'envoie pas toujours l'en-tête Origin
        $origine = (est_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
    }
    db()->prepare('INSERT INTO connexions (eleve_id, app, origine, moyen, ts) VALUES (?, ?, ?, ?, ?)')
        ->execute([$eleve_id, $app, mb_substr($origine, 0, 200), $moyen, time()]);
    // On ne garde que 120 jours d'historique
    if (random_int(1, 50) === 1) {
        db()->prepare('DELETE FROM connexions WHERE ts < ?')->execute([time() - 120 * 86400]);
    }
}

/* ---------- Disponibilité des adresses (ateliers) ---------- */

/**
 * L'adresse répond-elle ? Vérifié par le serveur (pas par le navigateur de l'élève),
 * avec un cache de 5 minutes pour ne pas ralentir le portail.
 */
function adresse_repond(string $url): bool
{
    // Adresse sur ce serveur (ex. copie de secours /miroir/netsim/) : on regarde directement le disque
    $p = parse_url($url);
    if (strcasecmp((string) ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''), (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0) {
        if (preg_match('#^/miroir/([A-Za-z0-9_-]+)/#', (string) ($p['path'] ?? ''), $m)) {
            return is_file(__DIR__ . '/../public/miroir/' . $m[1] . '/index.html');
        }
        return true;
    }
    $cle = 'etat_url:' . md5($url);
    $cache = reglage($cle);
    if ($cache !== null) {
        [$ok, $quand] = explode('|', $cache) + [1 => 0];
        if (time() - (int) $quand < 300) {
            return $ok === '1';
        }
    }
    $ok = false;
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Centrale-eleves (verification)']);
        curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        $ok = $code >= 200 && $code < 400;
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 4, 'ignore_errors' => true]]);
        $h = @get_headers($url, false, $ctx);
        $ok = $h && preg_match('#\s[23]\d\d\s#', (string) $h[0]);
    }
    definir_reglage($cle, ($ok ? '1' : '0') . '|' . time());
    return $ok;
}

/** Adresses d'un atelier (principale puis secours). */
function adresses_atelier(array $atelier): array
{
    $secours = array_filter(array_map('trim', preg_split('/\R/', (string) ($atelier['urls_secours'] ?? '')) ?: []));
    return array_values(array_unique(array_merge([$atelier['url']], $secours)));
}

/** Première adresse qui répond (ou la principale si aucune ne répond). */
function meilleure_adresse(array $atelier): string
{
    $adresses = adresses_atelier($atelier);
    if (count($adresses) === 1) {
        return $adresses[0];
    }
    foreach ($adresses as $u) {
        if (adresse_repond($u)) {
            return $u;
        }
    }
    return $adresses[0];
}

/* ---------- Progression ---------- */

/** Identifiant d'activité : comme une clé (ex. "relier-salle", "niveau-2/defi-3"). */
function activite_propre(string $a): string
{
    return cle_propre($a);
}

/**
 * Taux d'avancement (0 à 1) d'une ligne de progression.
 * Terminé = 1 ; sinon étapes faites / total ; commencé sans détail = 0,1.
 */
function taux_ligne(?array $p): float
{
    if (!$p) {
        return 0.0;
    }
    if ($p['statut'] === 'termine') {
        return 1.0;
    }
    if ((int) ($p['total'] ?? 0) > 0) {
        return min(0.99, max(0.0, (int) $p['faites'] / (int) $p['total']));
    }
    if ((float) ($p['score_max'] ?? 0) > 0) {
        return min(0.99, max(0.0, (float) $p['score'] / (float) $p['score_max']));
    }
    return 0.1;
}

/**
 * Avancement d'un élève sur une étape de parcours ou une colonne du suivi.
 * $lignes : progression de l'élève pour cet atelier, indexée par activité.
 * $catalogue : activités déclarées pour l'atelier (pour une étape « tout l'atelier »).
 * Renvoie ['statut' => a_faire|commence|termine, 'taux' => 0..1, 'texte' => '3/5', 'maj' => ts|null]
 */
function etat_etape(string $activite, array $lignes, array $catalogue): array
{
    if ($activite !== '') {
        $p = $lignes[$activite] ?? null;
        if (!$p) {
            return ['statut' => 'a_faire', 'taux' => 0.0, 'texte' => '', 'maj' => null];
        }
        $texte = '';
        if ((int) ($p['total'] ?? 0) > 0) {
            $texte = min((int) $p['faites'], (int) $p['total']) . '/' . (int) $p['total'];
        } elseif ($p['score'] !== null && $p['score_max'] !== null) {
            $texte = rtrim(rtrim(number_format((float) $p['score'], 1, ',', ''), '0'), ',') . '/' . rtrim(rtrim(number_format((float) $p['score_max'], 1, ',', ''), '0'), ',');
        }
        return ['statut' => $p['statut'] === 'termine' ? 'termine' : 'commence', 'taux' => taux_ligne($p),
                'texte' => $texte, 'maj' => (int) $p['maj']];
    }
    // Tout l'atelier : moyenne sur le catalogue (ou sur ce qui a été commencé si rien n'est déclaré)
    $ids = $catalogue ? array_keys($catalogue) : array_keys($lignes);
    if (!$ids || !$lignes) {
        return ['statut' => 'a_faire', 'taux' => 0.0, 'texte' => $ids ? '0/' . count($ids) : '', 'maj' => null];
    }
    $somme = 0.0;
    $finies = 0;
    $maj = 0;
    foreach ($ids as $id) {
        $somme += taux_ligne($lignes[$id] ?? null);
        if (($lignes[$id]['statut'] ?? '') === 'termine') {
            $finies++;
        }
        $maj = max($maj, (int) ($lignes[$id]['maj'] ?? 0));
    }
    $taux = $somme / count($ids);
    return ['statut' => $finies === count($ids) ? 'termine' : 'commence', 'taux' => $taux,
            'texte' => $finies . '/' . count($ids), 'maj' => $maj ?: null];
}

/** Progression d'un ensemble d'élèves : [eleve_id][app][activite] => ligne */
function progression_eleves(array $eleve_ids): array
{
    if (!$eleve_ids) {
        return [];
    }
    $marques = implode(',', array_fill(0, count($eleve_ids), '?'));
    $st = db()->prepare("SELECT * FROM progression WHERE eleve_id IN ($marques)");
    $st->execute(array_values($eleve_ids));
    $res = [];
    foreach ($st->fetchAll() as $p) {
        $res[(int) $p['eleve_id']][$p['app']][$p['activite']] = $p;
    }
    return $res;
}

/** Catalogue des activités : [app][activite] => ligne, dans l'ordre déclaré */
function catalogue_activites(?string $app = null): array
{
    $st = $app === null
        ? db()->query('SELECT * FROM activites ORDER BY app, ordre, activite')
        : db()->prepare('SELECT * FROM activites WHERE app = ? ORDER BY ordre, activite');
    if ($app !== null) {
        $st->execute([$app]);
    }
    $res = [];
    foreach ($st->fetchAll() as $a) {
        $res[$a['app']][$a['activite']] = $a;
    }
    return $res;
}

/** Une classe fait-elle partie de la liste "5A,5B" ? (liste vide = toutes) */
function classe_concernee(string $liste, ?string $classe): bool
{
    $classes = array_filter(array_map('trim', explode(',', $liste)));
    return $classe === null || !$classes
        || in_array(mb_strtoupper($classe), array_map('mb_strtoupper', $classes), true);
}

/** Ateliers actifs proposés à une classe, dans l'ordre. */
function ateliers_pour_classe(?string $classe): array
{
    $tous = db()->query('SELECT * FROM ateliers WHERE actif = 1 ORDER BY ordre, nom')->fetchAll();
    return array_values(array_filter($tous, fn($a) => classe_concernee($a['classes'], $classe)));
}

/** Parcours visibles par une classe, avec leurs étapes. */
function parcours_pour_classe(?string $classe): array
{
    $tous = db()->query('SELECT * FROM parcours ORDER BY nom')->fetchAll();
    $st = db()->prepare('SELECT * FROM parcours_etapes WHERE parcours_id = ? ORDER BY ordre, id');
    $res = [];
    foreach ($tous as $p) {
        $classes = array_filter(array_map('trim', explode(',', $p['classes'])));
        if ($classe !== null && $classes && !in_array(mb_strtoupper($classe), array_map('mb_strtoupper', $classes), true)) {
            continue;
        }
        $st->execute([$p['id']]);
        $p['etapes'] = $st->fetchAll();
        $res[] = $p;
    }
    return $res;
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

/**
 * Renommages de classes : les noms des élèves sont rangés PAR NOM DE CLASSE dans le navigateur de l'enseignant
 * (et dans ses fichiers CSV). La liste des renommages est envoyée à chaque page pour que chaque ordinateur
 * déplace lui-même ses noms locaux de l'ancien nom vers le nouveau.
 */
function renommages_classes(): array
{
    $l = json_decode(reglage('renommages_classes', '[]') ?? '[]', true);
    return is_array($l) ? $l : [];
}

/** Un nom de classe réutilisé (nouvelle classe « 5A ») ne doit plus être déplacé vers l'ancien renommage. */
function oublier_renommage(string $nom): void
{
    $l = array_values(array_filter(renommages_classes(), fn($r) => mb_strtolower($r['ancien']) !== mb_strtolower($nom)));
    definir_reglage('renommages_classes', json_encode($l, JSON_UNESCAPED_UNICODE));
}

function renommer_classe(int $id, string $nouveau): ?string
{
    $pdo = db();
    $classe = classe_par_id($id);
    if (!$classe) {
        return 'Classe introuvable.';
    }
    $ancien = $classe['nom'];
    if ($ancien === $nouveau) {
        return null;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE nom = ? COLLATE NOCASE AND id <> ?');
    $st->execute([$nouveau, $id]);
    if ($st->fetchColumn()) {
        return "Une autre classe s'appelle déjà « $nouveau ».";
    }
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE classes SET nom = ? WHERE id = ?')->execute([$nouveau, $id]);
    // Ateliers et parcours réservés à certaines classes : « 5A,5B » → « 5EME A,5B »
    foreach (['ateliers', 'parcours'] as $table) {
        foreach ($pdo->query("SELECT id, classes FROM $table WHERE classes <> ''")->fetchAll() as $r) {
            $liste = array_map('trim', explode(',', $r['classes']));
            $change = false;
            foreach ($liste as $i => $c) {
                if (mb_strtolower($c) === mb_strtolower($ancien)) {
                    $liste[$i] = $nouveau;
                    $change = true;
                }
            }
            if ($change) {
                $pdo->prepare("UPDATE $table SET classes = ? WHERE id = ?")->execute([implode(',', $liste), $r['id']]);
            }
        }
    }
    $l = array_values(array_filter(renommages_classes(), fn($r) => mb_strtolower($r['ancien']) !== mb_strtolower($nouveau)));
    $l[] = ['ancien' => $ancien, 'nouveau' => $nouveau, 'le' => time()];
    definir_reglage('renommages_classes', json_encode(array_slice($l, -300), JSON_UNESCAPED_UNICODE));
    $pdo->commit();
    return null;
}

/** Nouveaux codes pour plusieurs élèves d'une classe (les anciens ne fonctionnent plus). */
function regenerer_codes(int $classe_id, ?array $ids): array
{
    $pdo = db();
    $classe = classe_par_id($classe_id);
    if (!$classe) {
        return [];
    }
    $st = $pdo->prepare('SELECT id, etiquette FROM eleves WHERE classe_id = ?
        ORDER BY CASE WHEN etiquette GLOB \'[0-9]*\' THEN CAST(etiquette AS INTEGER) ELSE 9999 END, etiquette');
    $st->execute([$classe_id]);
    $codes = [];
    $prefixe = prefixe_codes($classe);
    $maj = $pdo->prepare('UPDATE eleves SET code_hash = ? WHERE id = ?');
    $sessions = $pdo->prepare('DELETE FROM sessions WHERE eleve_id = ?');
    $pdo->beginTransaction();
    foreach ($st->fetchAll() as $e) {
        if ($ids !== null && !in_array((int) $e['id'], $ids, true)) {
            continue;
        }
        $code = generer_code($prefixe);
        $maj->execute([hash_code($code), $e['id']]);
        $sessions->execute([$e['id']]);
        $codes[] = ['numero' => $e['etiquette'], 'code' => $code];
    }
    $pdo->commit();
    return $codes;
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
/**
 * Code élève : CLASSE-ÉTB-chiffres-lettres-lettres, ex. « 4C-LHA-9943-kfmz-tqpr ».
 * Pas de 0/1/i/l/o (confusions à la lecture). Environ 48 bits de hasard : impossible à deviner.
 */
function generer_code(string $prefixe): string
{
    $lettres = 'abcdefghjkmnpqrstuvwxyz';
    $chiffres = '23456789';
    $tirer = function (string $alphabet, int $n): string {
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $s;
    };
    return $prefixe . '-' . $tirer($chiffres, 4) . '-' . $tirer($lettres, 4) . '-' . $tirer($lettres, 4);
}

function sans_accents_maj(string $s): string
{
    $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return strtoupper($t === false ? $s : $t);
}

/** « Collège les hautiers » → LHA ; « Collège Albert Camus » → ACA ; « Lycée Hoche » → HOC. */
function code_etablissement_suggere(string $nom): string
{
    $mots = preg_split('/[^A-Z0-9]+/', sans_accents_maj($nom), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $generiques = ['COLLEGE', 'LYCEE', 'ECOLE', 'CLG', 'LGT', 'LP', 'EREA', 'CITE', 'SCOLAIRE', 'ENSEMBLE', 'GROUPE', 'PRIVE', 'PRIVEE', 'PUBLIC', 'PUBLIQUE'];
    $mots = array_values(array_filter($mots, fn($m) => !in_array($m, $generiques, true)));
    if (!$mots) {
        return 'ETB';
    }
    $code = count($mots) >= 2 ? $mots[0][0] . substr(end($mots), 0, 2) : substr($mots[0], 0, 3);
    if (in_array($code, ['SEX', 'CUL', 'CON', 'PUE', 'PET', 'PDF', 'FDP', 'NUL', 'BIT', 'ZOB'], true)) {
        $code = substr(end($mots), 0, 3);   // ex. « Saint-Exupéry » → EXU
    }
    return str_pad(preg_replace('/[^A-Z]/', 'X', $code), 3, 'X');
}

/** Code à 3 lettres de l'établissement ; s'il n'a pas encore été choisi, on en propose un (unique) et on l'enregistre. */
function code_etablissement(int $eid): string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT nom, code FROM etablissements WHERE id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) {
        return '';
    }
    if ($e['code'] !== '') {
        return $e['code'];
    }
    $pris = $pdo->query("SELECT code FROM etablissements WHERE code <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $code = code_etablissement_suggere($e['nom']);
    for ($i = 0; in_array($code, $pris, true) && $i < 26; $i++) {
        $code = substr($code, 0, 2) . chr(65 + $i);
    }
    $pdo->prepare('UPDATE etablissements SET code = ? WHERE id = ?')->execute([$code, $eid]);
    return $code;
}

/**
 * Début des codes d'une classe : « 4C-LHA ». Si le nom de la classe contient déjà l'établissement
 * (« 4C-HAUTIERS », « 4A-ALBERTCAMUS »), on ne garde que la classe.
 */
function prefixe_codes(array $classe): string
{
    $eid = (int) ($classe['etablissement_id'] ?? 0);
    $code_etab = $eid ? code_etablissement($eid) : '';
    $segments = preg_split('/[^A-Z0-9]+/', sans_accents_maj($classe['nom']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($eid && count($segments) > 1) {
        $e = etablissement_par_id($eid);
        $mots = preg_split('/[^A-Z0-9]+/', sans_accents_maj($e['nom'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $mots = array_values(array_filter($mots, fn($m) => strlen($m) > 2 && !in_array($m, ['COLLEGE', 'LYCEE', 'ECOLE', 'LES'], true)));
        $reconnus = array_merge([$code_etab, implode('', $mots)], $mots);
        while (count($segments) > 1 && in_array(end($segments), $reconnus, true)) {
            array_pop($segments);
        }
    }
    $classe_part = substr(implode('', $segments), 0, 10) ?: 'X';
    return $classe_part . ($code_etab !== '' ? '-' . $code_etab : '');
}

/* ---------- Surnoms : identifiant visible de l'élève, « 4C-LHA-LoutreAgile » ---------- */

const SURNOM_ANIMAUX = ['Loutre', 'Panda', 'Renard', 'Hibou', 'Koala', 'Lynx', 'Castor', 'Dauphin', 'Faucon', 'Gecko', 'Lama',
    'Manchot', 'Orque', 'Puma', 'Suricate', 'Tigre', 'Zebre', 'Yack', 'Bison', 'Chamois', 'Coyote', 'Ecureuil', 'Flamant',
    'Gazelle', 'Herisson', 'Ibis', 'Jaguar', 'Kiwi', 'Lemurien', 'Marmotte', 'Narval', 'Ocelot', 'Pelican', 'Quokka',
    'Raton', 'Salamandre', 'Tapir', 'Toucan', 'Varan', 'Wombat', 'Alpaga', 'Baleine', 'Caribou', 'Dingo', 'Elan', 'Fennec',
    'Gorille', 'Hamster', 'Impala', 'Lievre', 'Mouflon', 'Oryx', 'Paon', 'Requin', 'Tatou', 'Vautour', 'Zebu', 'Phoque'];
const SURNOM_ADJECTIFS = ['Agile', 'Rapide', 'Calme', 'Brave', 'Habile', 'Sage', 'Fidele', 'Magique', 'Cosmique', 'Tranquille',
    'Intrepide', 'Solaire', 'Lunaire', 'Polaire', 'Electrique', 'Atomique', 'Logique', 'Pratique', 'Robuste', 'Souple',
    'Vaillant', 'Lucide', 'Stellaire', 'Sonique', 'Mecanique', 'Numerique', 'Optique', 'Magnetique', 'Dynamique', 'Unique'];
// Refusés dans un surnom choisi par l'élève (comparaison sans accents ni majuscules)
const SURNOM_INTERDITS = ['merd', 'putain', 'pute', 'salope', 'salaud', 'connard', 'connasse', 'encule', 'batard', 'couille',
    'niquer', 'niktamer', 'enfoire', 'bordel', 'branl', 'abruti', 'debile', 'cretin', 'gogol', 'negro', 'nazi', 'hitler', 'pedo', 'zizi',
    'teub', 'chatte', 'sexe', 'porno', 'penis', 'vagin', 'bouffon', 'tapette', 'pd', 'fdp', 'ntm', 'tg', 'con', 'cul', 'nul',
    'bite', 'boloss', 'pétasse', 'petasse', 'grognasse', 'clochard', 'gueule', 'tamere', 'mort', 'tuer', 'suicide'];

function surnom_aleatoire(): string
{
    return SURNOM_ANIMAUX[random_int(0, count(SURNOM_ANIMAUX) - 1)] . SURNOM_ADJECTIFS[random_int(0, count(SURNOM_ADJECTIFS) - 1)];
}

/** Message d'erreur (pour l'élève) si le surnom n'est pas acceptable, sinon null. */
function surnom_refuse(string $surnom): ?string
{
    if (!preg_match('/^[\p{L}0-9]{3,20}$/u', $surnom)) {
        return 'Ton surnom doit faire de 3 à 20 lettres ou chiffres, sans espace ni tiret.';
    }
    $t = strtolower(sans_accents_maj($surnom));
    foreach (SURNOM_INTERDITS as $mot) {
        $m = strtolower(sans_accents_maj($mot));
        if (strlen($m) <= 3 ? $t === $m : str_contains($t, $m)) {
            return 'Ce surnom n’est pas accepté. Choisis-en un autre.';
        }
    }
    return null;
}

function surnom_libre(int $classe_id, string $surnom, int $sauf_eleve = 0): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM eleves WHERE classe_id = ? AND surnom = ? COLLATE NOCASE AND id <> ?');
    $st->execute([$classe_id, $surnom, $sauf_eleve]);
    return (int) $st->fetchColumn() === 0;
}

/** Surnom aléatoire, unique dans la classe. */
function attribuer_surnom(int $eleve_id, int $classe_id): string
{
    for ($i = 0; $i < 50; $i++) {
        $s = surnom_aleatoire() . ($i >= 20 ? (string) random_int(2, 99) : '');
        if (surnom_libre($classe_id, $s, $eleve_id)) {
            break;
        }
    }
    db()->prepare('UPDATE eleves SET surnom = ? WHERE id = ?')->execute([$s, $eleve_id]);
    return $s;
}

/** Élèves créés avant les surnoms : on leur en donne un. */
function attribuer_surnoms_manquants(): void
{
    foreach (db()->query("SELECT id, classe_id FROM eleves WHERE surnom = ''")->fetchAll() as $e) {
        attribuer_surnom((int) $e['id'], (int) $e['classe_id']);
    }
}

/** « 4C-LHA-LoutreAgile » : le début suit la classe et l'établissement, l'élève peut changer la fin. */
function identifiant_eleve(array $classe, string $surnom): string
{
    return prefixe_codes($classe) . '-' . $surnom;
}

/** Infos d'identité renvoyées à l'élève par l'API (connexion, session, surnom). */
function identite_eleve(int $eleve_id): array
{
    $st = db()->prepare('SELECT e.surnom, e.classe_id, c.nom, c.etablissement_id FROM eleves e JOIN classes c ON c.id = e.classe_id WHERE e.id = ?');
    $st->execute([$eleve_id]);
    $r = $st->fetch();
    $surnom = $r['surnom'] !== '' ? $r['surnom'] : attribuer_surnom($eleve_id, (int) $r['classe_id']);
    $classe = ['nom' => $r['nom'], 'etablissement_id' => $r['etablissement_id']];
    return [
        'identifiant'       => identifiant_eleve($classe, $surnom),
        'prefixe'           => prefixe_codes($classe),
        'surnom'            => $surnom,
        'surnom_modifiable' => reglage('surnom_modifiable', '1') === '1',
    ];
}

/* ---------- Groupes ---------- */

function groupes_de(int $classe_id): array
{
    $st = db()->prepare('SELECT g.*, (SELECT COUNT(*) FROM eleves e WHERE e.groupe_id = g.id) AS nb FROM groupes g WHERE g.classe_id = ? ORDER BY g.ordre, g.id');
    $st->execute([$classe_id]);
    return $st->fetchAll();
}

function groupe_par_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM groupes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Choix « classe ou groupe » des formulaires : « 12 » (classe entière) ou « 12:5 » (groupe 5 de la classe 12). */
function options_classes_groupes(array $classes, string $choisi = ''): string
{
    $html = '<option value="">—</option>';
    foreach ($classes as $cid => $nom) {
        $html .= '<option value="' . (int) $cid . '"' . ($choisi === (string) $cid ? ' selected' : '') . '>' . h($nom) . '</option>';
        foreach (groupes_de((int) $cid) as $g) {
            $v = $cid . ':' . $g['id'];
            $html .= '<option value="' . h($v) . '"' . ($choisi === $v ? ' selected' : '') . '>' . h($nom . ' · ' . $g['nom']) . '</option>';
        }
    }
    return $html;
}

/** « 12:5 » → [12, 5] ; « 12 » → [12, null]. Le groupe doit appartenir à la classe. */
function lire_classe_groupe(string $valeur): array
{
    [$cid, $gid] = array_map('intval', explode(':', $valeur . ':'));
    if ($gid) {
        $g = groupe_par_id($gid);
        if (!$g || (int) $g['classe_id'] !== $cid) {
            $gid = 0;
        }
    }
    return [$cid, $gid ?: null];
}

function etablissement_par_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM etablissements WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
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
