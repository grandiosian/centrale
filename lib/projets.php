<?php
/**
 * Centrale élèves — ateliers transversaux (parcours en plusieurs logiciels).
 *
 * Un parcours est « individuel » ou « en équipe ». Chaque élève (ou chaque équipe) a un DOSSIER DE PROJET
 * pour ce parcours, partagé par toutes les applications : l'étape 2 (établi3D) y dépose « chassis.glb »,
 * l'étape 3 (programmation3D) le relit. Les étapes déclarent ce qu'elles reçoivent (entrées) et produisent
 * (sorties) ; l'enseignant peut fournir des fichiers de départ, et choisir comment les étapes se débloquent.
 */
declare(strict_types=1);

const MODES_PARCOURS = ['individuel' => 'Individuel', 'equipe' => 'En équipe'];
const DEBLOCAGES = [
    'libre'      => 'Toutes les étapes sont ouvertes',
    'ordre'      => 'Dans l’ordre : une étape s’ouvre quand la précédente est terminée',
    'validation' => 'Après validation : une étape s’ouvre quand vous avez validé la précédente',
];

/** « cahier.json, chassis.glb » → ['cahier.json', 'chassis.glb'] */
function liste_noms(string $texte): array
{
    $noms = array_map(fn($n) => nom_fichier_propre(trim($n)), preg_split('/[,;]/', $texte) ?: []);
    return array_values(array_unique(array_filter($noms, fn($n) => $n !== '')));
}

function parcours_par_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM parcours WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM parcours_etapes WHERE parcours_id = ? ORDER BY ordre, id');
    $st->execute([$id]);
    $p['etapes'] = $st->fetchAll();
    return $p;
}

/**
 * À qui appartient le dossier de projet : l'élève (parcours individuel) ou son équipe (parcours en équipe).
 * Un élève sans équipe dans un parcours en équipe travaille seul en attendant (et on le signale).
 */
function proprietaire_projet(array $eleve, array $parcours): array
{
    if ($parcours['mode'] === 'equipe') {
        $st = db()->prepare('SELECT q.* FROM equipes q JOIN eleves e ON e.equipe_id = q.id WHERE e.id = ?');
        $st->execute([(int) $eleve['id']]);
        if ($q = $st->fetch()) {
            $st = db()->prepare('SELECT id FROM eleves WHERE equipe_id = ? AND actif = 1 ORDER BY etiquette');
            $st->execute([$q['id']]);
            return ['cle' => 'q' . $q['id'], 'type' => 'equipe', 'equipe' => $q,
                    'membres' => array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))];
        }
    }
    return ['cle' => 'e' . (int) $eleve['id'], 'type' => 'eleve', 'equipe' => null, 'membres' => [(int) $eleve['id']],
            'sans_equipe' => $parcours['mode'] === 'equipe'];
}

/** Fichiers du dossier de projet, indexés par nom. */
function fichiers_projet(int $parcours_id, string $proprietaire): array
{
    $st = db()->prepare('SELECT * FROM projet_fichiers WHERE parcours_id = ? AND proprietaire = ? ORDER BY nom');
    $st->execute([$parcours_id, $proprietaire]);
    $res = [];
    foreach ($st->fetchAll() as $f) {
        $res[$f['nom']] = $f;
    }
    return $res;
}

/** Fichiers fournis par l'enseignant, indexés par nom. */
function fichiers_fournis(int $parcours_id): array
{
    $st = db()->prepare('SELECT * FROM parcours_fournis WHERE parcours_id = ? ORDER BY nom');
    $st->execute([$parcours_id]);
    $res = [];
    foreach ($st->fetchAll() as $f) {
        $res[$f['nom']] = $f;
    }
    return $res;
}

function validations_projet(int $parcours_id, string $proprietaire): array
{
    $st = db()->prepare('SELECT ordre, valide_le FROM parcours_validations WHERE parcours_id = ? AND proprietaire = ?');
    $st->execute([$parcours_id, $proprietaire]);
    return array_map('intval', array_column($st->fetchAll(), 'valide_le', 'ordre'));
}

/**
 * État de chaque étape pour un dossier de projet : avancement (progression des membres + sorties déposées),
 * étape ouverte ou verrouillée selon le mode de déblocage, validation par l'enseignant.
 */
function etapes_projet(array $parcours, array $proprio, ?array $prog_membres = null, ?array $catalogue = null): array
{
    $catalogue ??= catalogue_activites();
    $prog_membres ??= progression_eleves($proprio['membres']);
    $fichiers = fichiers_projet((int) $parcours['id'], $proprio['cle']);
    $validations = validations_projet((int) $parcours['id'], $proprio['cle']);
    $rang = ['a_faire' => 0, 'commence' => 1, 'termine' => 2];
    $res = [];
    foreach ($parcours['etapes'] as $i => $et) {
        // Le meilleur avancement parmi les membres (en équipe, l'étape avance pour tous)
        $etat = ['statut' => 'a_faire', 'taux' => 0.0, 'texte' => '', 'maj' => null];
        foreach ($proprio['membres'] as $m) {
            $e = etat_etape($et['activite'], $prog_membres[$m][$et['app']] ?? [], $catalogue[$et['app']] ?? []);
            if ($rang[$e['statut']] > $rang[$etat['statut']] || ($e['statut'] === $etat['statut'] && $e['taux'] > $etat['taux'])) {
                $etat = $e;
            }
        }
        // Les fichiers attendus (sorties) déposés comptent aussi
        $sorties = liste_noms($et['sorties']);
        $deposees = array_values(array_filter($sorties, fn($n) => isset($fichiers[$n])));
        if ($sorties && count($deposees) === count($sorties)) {
            $etat['statut'] = 'termine';
            $etat['taux'] = 1.0;
        } elseif ($deposees && $etat['statut'] === 'a_faire') {
            $etat['statut'] = 'commence';
            $etat['taux'] = max($etat['taux'], count($deposees) / count($sorties) / 2);
        }
        if ($sorties && $etat['texte'] === '') {
            $etat['texte'] = count($deposees) . '/' . count($sorties) . ' fichier' . (count($sorties) > 1 ? 's' : '');
        }
        $ouverte = match ($parcours['deblocage']) {
            'ordre'      => $i === 0 || $res[$i - 1]['statut'] === 'termine',
            'validation' => $i === 0 || isset($validations[$i - 1]),
            default      => true,
        };
        $res[] = $etat + [
            'ordre' => $i, 'ouverte' => $ouverte, 'validee' => $validations[$i] ?? null,
            'entrees' => liste_noms($et['entrees']), 'sorties' => $sorties, 'deposees' => $deposees,
        ];
    }
    return $res;
}

/** L'élève peut-il voir ce parcours (sa classe est concernée) ? */
function parcours_accessible(array $eleve, ?array $parcours): bool
{
    return $parcours !== null && classe_concernee($parcours['classes'], $eleve['classe']);
}

/** Identifiants (4C-LHA-surnom) des élèves, pour les montrer aux coéquipiers sans aucun nom. */
function identifiants_eleves(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $st = db()->prepare('SELECT e.id, e.surnom, c.nom, c.etablissement_id FROM eleves e JOIN classes c ON c.id = e.classe_id
                         WHERE e.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute(array_values($ids));
    $res = [];
    foreach ($st->fetchAll() as $e) {
        $res[(int) $e['id']] = identifiant_eleve(['nom' => $e['nom'], 'etablissement_id' => $e['etablissement_id']], $e['surnom']);
    }
    return $res;
}

function supprimer_fichiers_projet(string $where, array $params): void
{
    $st = db()->prepare("SELECT chemin FROM projet_fichiers WHERE $where");
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        effacer_fichier_stocke($c);
    }
    db()->prepare("DELETE FROM projet_fichiers WHERE $where")->execute($params);
}
