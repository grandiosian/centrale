<?php
/**
 * Dossier de projet d'un atelier transversal (parcours en plusieurs logiciels). En-tête : X-Jeton.
 * Le dossier appartient à l'élève (parcours individuel) ou à son équipe (parcours en équipe) ;
 * toutes les applications du parcours y lisent et y déposent leurs fichiers.
 *
 * GET  ?parcours=3                 → { ok, parcours, proprietaire, etapes, fichiers, espace }
 * GET  ?parcours=3&nom=chassis.glb → le fichier (celui du dossier, sinon le fichier fourni par l'enseignant)
 *                                    en-tête X-Version : version lue (à renvoyer à l'enregistrement)
 * POST (multipart) parcours, nom, fichier [, etape] [, version]
 *      → { ok, fichier }  ;  409 si quelqu'un (un coéquipier) a enregistré une version plus récente
 * POST { parcours, nom, supprimer: true } → { ok }
 */
declare(strict_types=1);

require __DIR__ . '/../../lib/api.php';

appliquer_cors();
$eleve = eleve_connecte();
$pdo = db();

$entree = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : lire_entree();
$parcours = parcours_par_id((int) ($entree['parcours'] ?? 0));
if (!parcours_accessible($eleve, $parcours)) {
    envoyer_json(404, ['ok' => false, 'erreur' => 'Atelier introuvable pour ta classe.']);
}
$proprio = proprietaire_projet($eleve, $parcours);
$pid = (int) $parcours['id'];

function description_fichier(array $f, array $auteurs, bool $fourni = false): array
{
    return ['nom' => $f['nom'], 'taille' => (int) $f['taille'], 'type' => $f['type'], 'maj' => (int) $f['maj'],
            'version' => $fourni ? 0 : (int) $f['version'], 'fourni' => $fourni,
            'auteur' => !$fourni && $f['auteur_id'] ? ($auteurs[(int) $f['auteur_id']] ?? null) : null,
            'etape' => !$fourni && $f['etape'] !== null ? (int) $f['etape'] : null];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $nom = nom_fichier_propre((string) ($_GET['nom'] ?? ''));
    $fichiers = fichiers_projet($pid, $proprio['cle']);
    $fournis = fichiers_fournis($pid);

    if ($nom !== '') {
        if (isset($fichiers[$nom])) {
            header('X-Version: ' . (int) $fichiers[$nom]['version']);
            servir_fichier($fichiers[$nom]['chemin'], $nom, $fichiers[$nom]['type']);
        }
        if (isset($fournis[$nom])) {
            header('X-Version: 0');   // fichier de départ : pas encore de version de l'élève
            servir_fichier($fournis[$nom]['chemin'], $nom, $fournis[$nom]['type']);
        }
        envoyer_json(404, ['ok' => false, 'erreur' => "Le fichier « $nom » n'est pas encore dans ton dossier de projet."]);
    }

    $auteurs = identifiants_eleves(array_unique(array_merge($proprio['membres'],
        array_filter(array_map(fn($f) => (int) $f['auteur_id'], $fichiers)))));
    $etats = etapes_projet($parcours, $proprio);
    $etapes = [];
    foreach ($parcours['etapes'] as $i => $et) {
        $e = $etats[$i];
        $etapes[] = [
            'ordre' => $i, 'titre' => $et['titre'], 'app' => $et['app'], 'activite' => $et['activite'], 'url' => $et['url'],
            'consigne' => $et['consigne'], 'entrees' => $e['entrees'], 'sorties' => $e['sorties'], 'deposees' => $e['deposees'],
            'statut' => $e['statut'], 'taux' => round($e['taux'], 3), 'texte' => $e['texte'],
            'ouverte' => $e['ouverte'], 'validee' => $e['validee'] !== null,
        ];
    }
    $liste = array_map(fn($f) => description_fichier($f, $auteurs), array_values($fichiers));
    foreach ($fournis as $n => $f) {
        if (!isset($fichiers[$n])) {
            $liste[] = description_fichier($f, [], true);
        }
    }
    envoyer_json(200, [
        'ok' => true,
        'parcours' => ['id' => $pid, 'nom' => $parcours['nom'], 'description' => $parcours['description'],
                       'mode' => $parcours['mode'], 'deblocage' => $parcours['deblocage']],
        'proprietaire' => [
            'type' => $proprio['type'],
            'equipe' => $proprio['equipe']['nom'] ?? null,
            'membres' => array_values(array_map(fn($id) => $auteurs[$id] ?? '', $proprio['membres'])),
            'sans_equipe' => !empty($proprio['sans_equipe']),
        ],
        'etapes' => $etapes,
        'fichiers' => $liste,
        'espace' => ['utilise' => usage_eleve($eleve['id']), 'quota' => quota_eleve_octets()],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    envoyer_json(405, ['ok' => false, 'erreur' => 'Utilisez GET ou POST.']);
}

$nom = nom_fichier_propre((string) ($entree['nom'] ?? ''));
if ($nom === '') {
    envoyer_json(400, ['ok' => false, 'erreur' => 'Nom de fichier manquant.']);
}

// Étape verrouillée : on n'enregistre pas (l'application transmet l'étape en cours)
if (isset($entree['etape']) && $entree['etape'] !== '') {
    $etape = (int) $entree['etape'];
    $etats = etapes_projet($parcours, $proprio);
    if (!isset($etats[$etape])) {
        envoyer_json(400, ['ok' => false, 'erreur' => 'Étape inconnue.']);
    }
    if (!$etats[$etape]['ouverte']) {
        envoyer_json(403, ['ok' => false, 'erreur' => $parcours['deblocage'] === 'validation'
            ? 'Cette étape n’est pas encore ouverte : ton professeur doit d’abord valider l’étape précédente.'
            : 'Cette étape n’est pas encore ouverte : termine d’abord l’étape précédente.']);
    }
} else {
    $etape = null;
}

$st = $pdo->prepare('SELECT * FROM projet_fichiers WHERE parcours_id = ? AND proprietaire = ? AND nom = ?');
$st->execute([$pid, $proprio['cle'], $nom]);
$ancien = $st->fetch();

if (!empty($entree['supprimer'])) {
    if ($ancien) {
        $pdo->prepare('DELETE FROM projet_fichiers WHERE id = ?')->execute([$ancien['id']]);
        effacer_fichier_stocke($ancien['chemin']);
    }
    envoyer_json(200, ['ok' => true]);
}

// Travail en équipe : un coéquipier a enregistré entre-temps → on prévient au lieu d'écraser
$version_lue = isset($entree['version']) && $entree['version'] !== '' ? (int) $entree['version'] : null;
if ($ancien && $version_lue !== null && $version_lue !== (int) $ancien['version']) {
    $qui = $ancien['auteur_id'] ? (identifiants_eleves([(int) $ancien['auteur_id']])[(int) $ancien['auteur_id']] ?? '') : '';
    envoyer_json(409, ['ok' => false, 'conflit' => true, 'version' => (int) $ancien['version'], 'auteur' => $qui, 'maj' => (int) $ancien['maj'],
        'erreur' => "« $nom » a été modifié" . ($qui && (int) $ancien['auteur_id'] !== $eleve['id'] ? " par $qui" : '') . ' depuis que tu l’as ouvert.']);
}

$limite_php = octets_ini((string) ini_get('post_max_size'));
if ($limite_php > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limite_php) {
    envoyer_json(413, ['ok' => false, 'erreur' => 'Fichier trop gros pour le serveur.']);
}
$envoi = $_FILES['fichier'] ?? null;
if (!$envoi || is_array($envoi['error']) || $envoi['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($envoi['tmp_name'])) {
    envoyer_json(400, ['ok' => false, 'erreur' => "L'envoi du fichier a échoué. Réessaie."]);
}
$taille = (int) filesize($envoi['tmp_name']);
if ($taille > fichier_max_octets()) {
    envoyer_json(413, ['ok' => false, 'erreur' => 'Fichier trop gros (' . format_taille(fichier_max_octets()) . ' maximum).']);
}
$usage = usage_eleve($eleve['id']) - ($ancien && (int) $ancien['auteur_id'] === $eleve['id'] ? (int) $ancien['taille'] : 0) + $taille;
if ($usage > quota_eleve_octets()) {
    envoyer_json(507, ['ok' => false, 'erreur' => 'Ton espace est plein. Préviens ton professeur.']);
}
$type = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string) $envoi['type']) ? (string) $envoi['type'] : 'application/octet-stream';

$chemin = ranger_fichier($envoi['tmp_name'], 'projets/' . $pid);
$version = $ancien ? (int) $ancien['version'] + 1 : 1;
$maintenant = time();
$pdo->prepare('INSERT INTO projet_fichiers (parcours_id, proprietaire, nom, chemin, taille, type, maj, version, auteur_id, etape)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT (parcours_id, proprietaire, nom) DO UPDATE SET chemin = excluded.chemin, taille = excluded.taille,
               type = excluded.type, maj = excluded.maj, version = excluded.version, auteur_id = excluded.auteur_id, etape = excluded.etape')
    ->execute([$pid, $proprio['cle'], $nom, $chemin, $taille, $type, $maintenant, $version, $eleve['id'], $etape]);
if ($ancien) {
    effacer_fichier_stocke($ancien['chemin']);
}
envoyer_json(200, ['ok' => true, 'fichier' => ['nom' => $nom, 'taille' => $taille, 'type' => $type, 'maj' => $maintenant,
    'version' => $version, 'etape' => $etape]]);
