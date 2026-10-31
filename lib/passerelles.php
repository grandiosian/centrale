<?php
/**
 * Centrale élèves — passerelles : lecture des descripteurs d'atelier « passerelle.atelier » v1
 * (fichier centrale.json publié à la racine de chaque application, voir public/passerelle/SPECIFICATION.md).
 *
 * La Centrale s'en sert pour : remplir la fiche d'un atelier, connaître ses activités avant la première
 * connexion d'un élève (colonnes du suivi), afficher ce qu'il enregistre, et vérifier l'enchaînement des
 * étapes d'un parcours (fichiers reçus / produits).
 */
declare(strict_types=1);

const TAILLE_MAX_DESCRIPTEUR = 2 * 1024 * 1024;

/** Adresse du descripteur à partir de l'adresse de l'application (ou directement de centrale.json). */
function adresse_descripteur(string $url): string
{
    $url = trim($url);
    if (preg_match('#\.json(\?.*)?$#i', $url)) {
        return $url;
    }
    $url = preg_replace('#[?\#].*$#', '', $url) ?? $url;
    $url = preg_replace('#/index\.html?$#i', '/', $url) ?? $url;
    return rtrim($url, '/') . '/centrale.json';
}

/** Télécharge et décode un descripteur. Renvoie [descripteur|null, message d'erreur|null]. */
function telecharger_descripteur(string $url): array
{
    if (!preg_match('#^https?://[^\s/]+#i', $url)) {
        return [null, 'Adresse invalide : elle doit commencer par https://'];
    }
    $corps = false;
    $code = 0;
    $p = parse_url($url);
    $ici = strcasecmp((string) ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''), (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0;
    if ($ici && preg_match('#^/miroir/([A-Za-z0-9_-]+)/((?:[A-Za-z0-9_-]+/)?[A-Za-z0-9_.-]+\.json)$#', (string) ($p['path'] ?? ''), $m)) {
        // Copie de secours sur ce serveur : lecture directe sur le disque
        $f = __DIR__ . '/../public/miroir/' . $m[1] . '/' . $m[2];
        [$corps, $code] = is_file($f) ? [(string) file_get_contents($f), 200] : ['', 404];
    } elseif (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10, CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => 'Centrale-eleves (passerelle)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_MAXFILESIZE => TAILLE_MAX_DESCRIPTEUR + 1,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $corps = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        // curl_close() est inutile depuis PHP 8.0 (handle libéré automatiquement) et déprécié en 8.5.
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
        $corps = @file_get_contents($url, false, $ctx, 0, TAILLE_MAX_DESCRIPTEUR + 1);
        // $http_response_header est déprécié en PHP 8.5 ; http_get_last_response_headers() le remplace (8.5+), sinon repli sur l'ancien.
        $entetes = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $code = isset($entetes[0]) && preg_match('#\s(\d{3})\s#', $entetes[0], $m) ? (int) $m[1] : 0;
    }
    if ($corps === false || $code === 0) {
        return [null, "Le serveur de l'application ne répond pas ($url)."];
    }
    if ($code === 404) {
        return [null, "Pas de centrale.json à cette adresse ($url) : l'application n'a pas encore de passerelle."];
    }
    if ($code >= 400) {
        return [null, "Lecture impossible ($url) : erreur $code."];
    }
    if (strlen((string) $corps) > TAILLE_MAX_DESCRIPTEUR) {
        return [null, 'Descripteur trop gros (2 Mo au plus).'];
    }
    $d = json_decode((string) $corps, true);
    if (!is_array($d)) {
        return [null, "Ce fichier n'est pas du JSON valide ($url)."];
    }
    return [$d, null];
}

/**
 * Lit l'adresse donnée par l'enseignant : centrale.json (passerelle.atelier), sinon le manifeste
 * postMessage passerelle/manifest.json (« protocol: passerelle ») converti en fiche minimale.
 * Renvoie [descripteur|null, adresse lue, erreur|null].
 */
function lire_passerelle_distante(string $adresse): array
{
    $url = adresse_descripteur($adresse);
    [$d, $err] = telecharger_descripteur($url);
    if (!$d && !preg_match('#\.json(\?.*)?$#i', trim($adresse))) {
        $url2 = preg_replace('#centrale\.json$#', 'passerelle/manifest.json', $url) ?? $url;
        [$m, $err2] = telecharger_descripteur($url2);
        if ($m && ($m['protocol'] ?? '') === 'passerelle') {
            return [descripteur_depuis_manifeste($m, $adresse), $url2, null];
        }
    } elseif ($d && ($d['protocol'] ?? '') === 'passerelle') {
        return [descripteur_depuis_manifeste($d, $adresse), $url, null];
    }
    return [$d, $url, $err];
}

/** Manifeste « protocol: passerelle » (échanges par postMessage) → descripteur passerelle.atelier minimal. */
function descripteur_depuis_manifeste(array $m, string $adresse): array
{
    $app = is_array($m['app'] ?? null) ? $m['app'] : [];
    $url = (string) ($app['url'] ?? '');
    if (!preg_match('#^https?://#', $url)) {
        $url = preg_replace('#(centrale\.json|passerelle/manifest\.json)$#', '', trim($adresse)) ?? $adresse;
    }
    $doc = is_array($m['document'] ?? null) ? $m['document'] : [];
    return [
        'format' => 'passerelle.atelier', 'version' => 1,
        '_converti' => 'manifeste postMessage (protocol « passerelle » v' . (int) ($m['protocolVersion'] ?? 1) . ')',
        'atelier' => ['app' => normaliser_app((string) ($app['id'] ?? '')), 'nom' => (string) ($app['name'] ?? ''),
                      'version' => (string) ($m['version'] ?? $app['version'] ?? '?'), 'description' => (string) ($app['description'] ?? ''),
                      'url' => $url, 'urls_secours' => [], 'icone' => ''],
        'activites' => ['liste' => []],
        'progression' => ['champs' => []],
        'donnees' => [],
        'fichiers' => ['utilises' => false, 'note' => 'Document de l’application : ' . ($doc['kind'] ?? '?')
            . (isset($doc['extension']) ? ' (' . $doc['extension'] . ')' : '') . ', échangé par postMessage, pas par le dossier de projet.'],
        'manifeste' => $m,
    ];
}

/** Contrôle d'un descripteur. Renvoie [erreurs bloquantes, avertissements]. */
function verifier_descripteur(array $d): array
{
    $err = [];
    $av = [];
    if (($d['format'] ?? '') !== 'passerelle.atelier') {
        $err[] = "« format » doit valoir « passerelle.atelier ».";
    }
    if (($d['version'] ?? null) !== 1) {
        $err[] = 'Version du format non prise en charge (la Centrale lit la version 1).';
    }
    foreach (['atelier', 'activites', 'progression', 'donnees', 'fichiers'] as $s) {
        if (!isset($d[$s]) || !is_array($d[$s])) {
            $err[] = "Section « $s » manquante.";
        }
    }
    $a = is_array($d['atelier'] ?? null) ? $d['atelier'] : [];
    foreach (['app', 'nom', 'url', 'version'] as $c) {
        if (!isset($a[$c]) || !is_string($a[$c]) || trim($a[$c]) === '') {
            $err[] = "« atelier.$c » manquant.";
        }
    }
    if (isset($a['app']) && is_string($a['app']) && normaliser_app($a['app']) !== $a['app']) {
        $err[] = "« atelier.app » : minuscules, chiffres, - et _ seulement (40 caractères au plus).";
    }
    if (isset($a['url']) && is_string($a['url']) && !preg_match('#^https?://\S+$#', $a['url'])) {
        $err[] = '« atelier.url » doit commencer par https://';
    }
    $liste = $d['activites']['liste'] ?? null;
    if (!is_array($liste)) {
        $err[] = '« activites.liste » manquante (une liste vide est permise).';
    } else {
        $vus = [];
        foreach ($liste as $i => $act) {
            $id = is_array($act) ? (string) ($act['id'] ?? '') : '';
            if ($id === '' || activite_propre($id) !== $id || strlen($id) > 120) {
                $err[] = 'Activité n° ' . ($i + 1) . ' : identifiant invalide (« ' . $id . ' » ; lettres, chiffres, _ . / - seulement).';
            } elseif (isset($vus[$id])) {
                $err[] = "Activité « $id » déclarée deux fois.";
            }
            $vus[$id] = true;
            foreach (['titre', 'groupe'] as $c) {
                if (is_array($act) && isset($act[$c]) && !is_string($act[$c])) {
                    $av[] = "Activité « $id » : « $c » devrait être un texte.";
                }
            }
            if (is_array($act) && isset($act['total']) && !is_int($act['total'])) {
                $av[] = "Activité « $id » : « total » devrait être un nombre entier.";
            }
        }
        if (count($liste) > 500) {
            $err[] = '500 activités au plus par atelier.';
        }
    }
    if (isset($d['activites']['dynamiques']['motif']) && @preg_match('#' . str_replace('#', '\#', (string) $d['activites']['dynamiques']['motif']) . '#', '') === false) {
        $av[] = '« activites.dynamiques.motif » n’est pas une expression régulière valide.';
    }
    $champs = $d['progression']['champs'] ?? [];
    foreach (['faites', 'total', 'statut'] as $c) {
        if (!is_array($champs) || !isset($champs[$c])) {
            $av[] = "« progression.champs.$c » n’est pas décrit.";
        }
    }
    foreach ((array) ($d['donnees'] ?? []) as $cle => $def) {
        $cle_reelle = preg_replace('/\{[^}]+\}/', 'x', (string) $cle) ?? '';
        if (cle_propre($cle_reelle) !== $cle_reelle) {
            $av[] = "Clé de données « $cle » : caractères non permis par la Centrale (lettres, chiffres, - _ . /).";
        }
        if (!is_array($def) || !isset($def['description'])) {
            $av[] = "Clé de données « $cle » : « description » manquante.";
        }
    }
    $f = is_array($d['fichiers'] ?? null) ? $d['fichiers'] : [];
    $nav = $d['centrale']['parcours'] ?? null;
    if ($nav !== null && !in_array($nav['bouton_suivant'] ?? 'module', ['module', 'application', 'aucun'], true)) {
        $av[] = '« centrale.parcours.bouton_suivant » : module, application ou aucun.';
    }
    if (!empty($f['dossier_projet']) && !isset($nav['avant_de_quitter'])) {
        $av[] = '« centrale.parcours » non décrit : l’application utilise le dossier de projet, dit-elle ce qu’elle dépose avant de passer à l’étape suivante (avantDeQuitter) ?';
    }
    if (!array_key_exists('utilises', $f)) {
        $av[] = '« fichiers.utilises » manquant (true ou false).';
    }
    foreach (['recoit', 'produit', 'ressources'] as $l) {
        foreach ((array) ($f[$l] ?? []) as $x) {
            if (!is_array($x) || !isset($x['nom'])) {
                $av[] = "« fichiers.$l » : chaque élément doit avoir un « nom ».";
                break;
            }
        }
    }
    return [$err, $av];
}

/** Catalogue des activités d'un atelier. $remplacer = false : ajoute ou met à jour sans retirer les autres. */
function ecrire_catalogue(string $app, array $liste, bool $remplacer = true): int
{
    $propres = [];
    foreach (array_values($liste) as $i => $a) {
        if (!is_array($a)) {
            continue;
        }
        $id = activite_propre((string) ($a['id'] ?? ''));
        if ($id === '' || isset($propres[$id])) {
            continue;
        }
        $propres[$id] = [
            'titre'  => mb_substr(trim((string) ($a['titre'] ?? $id)), 0, 120),
            'groupe' => mb_substr(trim((string) ($a['groupe'] ?? '')), 0, 80),
            'total'  => isset($a['total']) && is_numeric($a['total']) ? max(0, min(1000, (int) $a['total'])) : null,
            'competences' => implode(', ', codes_valides(is_array($a['competences'] ?? null) ? $a['competences'] : (string) ($a['competences'] ?? ''))),
            'ordre'  => $i,
        ];
    }
    $pdo = db();
    $pdo->beginTransaction();
    if ($remplacer) {
        $pdo->prepare('DELETE FROM activites WHERE app = ?')->execute([$app]);
    }
    $ins = $pdo->prepare('INSERT INTO activites (app, activite, titre, groupe, total, ordre, competences) VALUES (?, ?, ?, ?, ?, ?, ?)
                          ON CONFLICT (app, activite) DO UPDATE SET titre = excluded.titre, groupe = excluded.groupe,
                          total = excluded.total, ordre = excluded.ordre, competences = excluded.competences');
    foreach ($propres as $id => $a) {
        $ins->execute([$app, $id, $a['titre'], $a['groupe'], $a['total'], $a['ordre'], $a['competences']]);
    }
    $pdo->commit();
    return count($propres);
}

/**
 * Enregistre le descripteur : fiche de l'atelier (créée si besoin, complétée sinon), activités au catalogue.
 * Renvoie [app, créé ?, nombre d'activités].
 */
function enregistrer_descripteur(array $d, string $url_descripteur): array
{
    $pdo = db();
    $a = $d['atelier'];
    $app = $a['app'];
    $st = $pdo->prepare('SELECT * FROM ateliers WHERE app = ?');
    $st->execute([$app]);
    $existant = $st->fetch();
    $secours = implode("\n", array_values(array_filter(array_map('strval', (array) ($a['urls_secours'] ?? [])), fn($u) => preg_match('#^https?://\S+$#', $u))));
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // Adresses rangées par emplacement (local, GitHub, GitLab, alwaysdata) ; l'enseignant les règle ensuite dans l'onglet Ateliers
    $r = ranger_adresses(array_merge([$a['url']], explode("\n", $secours)), $app);
    $secours = implode("\n", array_diff($r['autres'], [$a['url']]));
    if (!$existant) {
        $ordre = (int) $pdo->query('SELECT COALESCE(MAX(ordre), 0) + 1 FROM ateliers')->fetchColumn();
        $pdo->prepare('INSERT INTO ateliers (app, nom, description, url, icone, classes, ordre, actif, urls_secours, descripteur, descripteur_url, descripteur_lu,
                       url_local, url_github, url_gitlab, url_alwaysdata)
                       VALUES (?, ?, ?, ?, ?, \'\', ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$app, mb_substr($a['nom'], 0, 80), mb_substr((string) ($a['description'] ?? ''), 0, 300), $a['url'],
                       mb_substr((string) ($a['icone'] ?? ''), 0, 8), $ordre, $secours, $json, $url_descripteur, time(),
                       $r['local'], $r['github'], $r['gitlab'], $r['alwaysdata']]);
    } else {
        // La fiche réglée par l'enseignant est gardée ; seuls les champs vides sont complétés
        $pdo->prepare("UPDATE ateliers SET descripteur = ?, descripteur_url = ?, descripteur_lu = ?,
                       description = CASE WHEN description = '' THEN ? ELSE description END,
                       icone = CASE WHEN icone = '' THEN ? ELSE icone END,
                       urls_secours = CASE WHEN urls_secours = '' THEN ? ELSE urls_secours END,
                       url_local = CASE WHEN url_local = '' THEN ? ELSE url_local END,
                       url_github = CASE WHEN url_github = '' THEN ? ELSE url_github END,
                       url_gitlab = CASE WHEN url_gitlab = '' THEN ? ELSE url_gitlab END,
                       url_alwaysdata = CASE WHEN url_alwaysdata = '' THEN ? ELSE url_alwaysdata END WHERE app = ?")
            ->execute([$json, $url_descripteur, time(), mb_substr((string) ($a['description'] ?? ''), 0, 300),
                       mb_substr((string) ($a['icone'] ?? ''), 0, 8), $secours,
                       $r['local'], $r['github'], $r['gitlab'], $r['alwaysdata'], $app]);
    }
    $n = ecrire_catalogue($app, (array) ($d['activites']['liste'] ?? []), false);
    return [$app, !$existant, $n];
}

function descripteur_de(string $app): ?array
{
    static $cache = [];
    if (!array_key_exists($app, $cache)) {
        $st = db()->prepare('SELECT descripteur FROM ateliers WHERE app = ?');
        $st->execute([$app]);
        $j = $st->fetchColumn();
        $d = $j ? json_decode((string) $j, true) : null;
        $cache[$app] = is_array($d) ? $d : null;
    }
    return $cache[$app];
}

/** Descripteur complet (centrale.json), pas une fiche tirée d'un manifeste postMessage. */
function descripteur_complet(string $app): ?array
{
    $d = descripteur_de($app);
    return $d && !isset($d['_converti']) ? $d : null;
}

/** Fichiers déclarés par un atelier : $liste = recoit | produit | ressources. */
function fichiers_declares(array $d, string $liste): array
{
    return array_values(array_filter((array) ($d['fichiers'][$liste] ?? []), fn($x) => is_array($x) && isset($x['nom'])));
}

/** « programme-*.ino » correspond-il à « programme-1.ino » ? */
function nom_correspond(string $motif, string $nom): bool
{
    $re = '#^' . str_replace('\*', '.*', preg_quote($motif, '#')) . '$#i';
    return (bool) preg_match($re, $nom);
}

/**
 * Vérifie l'enchaînement des étapes d'un parcours avec les descripteurs des ateliers :
 * fichiers qu'un atelier ne sait pas recevoir ou produire, fichiers reçus que rien ne produit.
 * Renvoie une liste de remarques (jamais bloquantes).
 */
function verifier_enchainement(array $etapes, array $fournis = []): array
{
    $rem = [];
    $produits = [];
    foreach ($etapes as $i => $et) {
        $n = $i + 1;
        $recoit = liste_noms((string) ($et['entrees'] ?? ''));
        $produit = liste_noms((string) ($et['sorties'] ?? ''));
        $d = descripteur_complet($et['app']);
        $nom = $d['atelier']['nom'] ?? $et['app'];
        if ($d && ($recoit || $produit)) {
            $f = $d['fichiers'] ?? [];
            if (($f['utilises'] ?? null) === false || (array_key_exists('dossier_projet', $f) && $f['dossier_projet'] === false)) {
                $rem[] = "Étape $n : selon sa passerelle, $nom n’utilise pas encore le dossier de projet (reçoit / produit sans effet pour l’instant).";
            } else {
                foreach ([['recoit', $recoit, 'recevoir'], ['produit', $produit, 'produire']] as [$l, $noms, $verbe]) {
                    $declares = fichiers_declares($d, $l);
                    if (!$declares) {
                        continue;
                    }
                    foreach ($noms as $x) {
                        if (!array_filter($declares, fn($f) => nom_correspond((string) $f['nom'], $x))) {
                            $rem[] = "Étape $n : $nom ne déclare pas $verbe « $x » (il sait $verbe : "
                                . implode(', ', array_map(fn($f) => $f['nom'], $declares)) . ').';
                        }
                    }
                }
            }
        }
        foreach ($recoit as $x) {
            if (!isset($produits[$x]) && !in_array($x, $fournis, true)) {
                $rem[] = "Étape $n : « $x » n’est produit par aucune étape précédente. Ajoutez-le aux fichiers fournis si c’est un fichier de départ.";
            }
        }
        foreach ($produit as $x) {
            $produits[$x] = true;
        }
    }
    return $rem;
}
