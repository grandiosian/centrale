<?php
/**
 * Centrale élèves — import des classes depuis un export Pronote (CSV ou copier-coller).
 *
 * Tout le fichier est lu DANS LE NAVIGATEUR (public/admin/import.js) : les noms des élèves
 * ne sont jamais envoyés au serveur. Pour chaque classe, le navigateur envoie seulement
 * son nom et le nombre d'élèves ; le serveur crée la classe si besoin et renvoie les codes
 * numérotés, que le navigateur associe aux noms.
 */
declare(strict_types=1);

/** Nom de classe Pronote → nom accepté par la Centrale (« 5°A » → « 5A », « 3EME 2 » reste « 3EME 2 »). */
function nom_classe_import(string $nom): string
{
    $nom = trim(preg_replace('/\s+/u', ' ', $nom) ?? '');
    $nom = str_replace(['°', 'ᵉ', 'è'], ['', 'e', 'e'], $nom);
    $nom = preg_replace('/[^\p{L}0-9 _-]/u', '', $nom) ?? '';
    return mb_substr(trim($nom), 0, 20);
}

function action_import(string $action): bool
{
    if ($action !== 'import_classe') {
        return false;
    }
    header('Content-Type: application/json; charset=utf-8');
    $pdo = db();
    $nom = nom_classe_import((string) ($_POST['classe'] ?? ''));
    $n = (int) ($_POST['nombre'] ?? 0);
    $eid = (int) ($_POST['etablissement_id'] ?? 0);
    if ($nom === '' || $n < 0 || $n > 60) {
        echo json_encode(['ok' => false, 'erreur' => "Classe « $nom » : nom invalide ou plus de 60 élèves."], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare('SELECT * FROM classes WHERE nom = ? COLLATE NOCASE');
    $st->execute([$nom]);
    $classe = $st->fetch();
    $creee = false;
    if (!$classe) {
        $pdo->prepare('INSERT INTO classes (nom, cree_le, etablissement_id) VALUES (?, ?, ?)')
            ->execute([$nom, time(), $eid ?: null]);
        $classe = classe_par_id((int) $pdo->lastInsertId());
        $creee = true;
        oublier_renommage($nom);
    } elseif ($eid && !$classe['etablissement_id']) {
        $pdo->prepare('UPDATE classes SET etablissement_id = ? WHERE id = ?')->execute([$eid, $classe['id']]);
    }
    $codes = [];
    if ($n > 0) {
        $classe = classe_par_id((int) $classe['id']);   // établissement éventuellement rattaché ci-dessus
        $prefixe = prefixe_codes($classe);
        $numero = prochain_numero((int) $classe['id']);
        $ins = $pdo->prepare('INSERT INTO eleves (classe_id, etiquette, code_hash, cree_le) VALUES (?, ?, ?, ?)');
        $pdo->beginTransaction();
        for ($i = 0; $i < $n; $i++) {
            $code = generer_code($prefixe);
            $etiquette = sprintf('%02d', $numero + $i);
            $ins->execute([$classe['id'], $etiquette, hash_code($code), time()]);
            $codes[] = ['numero' => $etiquette, 'code' => $code];
        }
        $pdo->commit();
    }
    echo json_encode(['ok' => true, 'classe_id' => (int) $classe['id'], 'classe' => $classe['nom'], 'creee' => $creee,
                      'prefixe' => prefixe_classe($classe['nom']), 'codes' => $codes], JSON_UNESCAPED_UNICODE);
    exit;
}

function page_import(): void
{
    $etabs = db()->query('SELECT id, nom FROM etablissements ORDER BY nom')->fetchAll();
    $classes = db()->query('SELECT c.nom, (SELECT COUNT(*) FROM eleves e WHERE e.classe_id = c.id) AS nb FROM classes c ORDER BY c.nom')->fetchAll();
    entete('Importer des classes');
    barre('classes');
    ?>
    <main>
        <p class="fil"><a href="./">← Classes</a></p>
        <h1>Importer des classes depuis Pronote</h1>
        <p class="sous">Le fichier est lu <strong>dans votre navigateur</strong> : les noms des élèves ne sont jamais envoyés au serveur.
            Le serveur reçoit seulement le nom de chaque classe et le nombre d'élèves, et renvoie les codes.</p>
        <?php messages(); ?>
        <?php liste_locale(); ?>

        <details class="carte aide-pronote">
            <summary>Comment exporter la liste depuis Pronote ?</summary>
            <ol>
                <li>Dans Pronote, affichez la liste des élèves (onglet <em>Élèves</em>, ou la liste d'une de vos classes).</li>
                <li>Sélectionnez les classes voulues (ou toutes), puis utilisez le bouton d'export en CSV de la liste
                    (icône d'export en haut à droite de la liste) et enregistrez le fichier.</li>
                <li>Ou plus simplement : sélectionnez toute la liste (Ctrl+A), copiez (Ctrl+C) et collez-la dans la zone ci-dessous.</li>
            </ol>
            <p class="discret">Colonnes reconnues : « Élèves » (NOM Prénom) ou « Nom » + « Prénom », « Classe de rattachement » (ou « Classe »)
                et « Sortie » : un élève dont la date de sortie est passée n'est pas créé.
                <br><strong>PAP, PPS, PAI, PPRE</strong> : ajoutez la colonne « Projets d'accompagnement » dans la liste Pronote avant l'export.
                Seul le type de projet est gardé (jamais le détail), uniquement sur cet ordinateur et dans votre fichier de noms : rien n'est envoyé au serveur.
                Réimporter le fichier met à jour les PAP / PPS / PAI des élèves déjà créés.
                Les autres colonnes (date de naissance, adresse e-mail, tuteur, options, régime…) sont ignorées et ne quittent pas votre ordinateur.</p>
        </details>

        <section class="carte">
            <div class="deux-colonnes">
                <label>Fichier exporté de Pronote (.csv ou .txt)
                    <input type="file" id="imp-fichier" accept=".csv,.txt,text/csv,text/plain">
                </label>
                <label>… ou coller la liste ici
                    <textarea id="imp-texte" rows="4" placeholder="Élève	Classe
DUPONT Léa	5A
MARTIN Noah	5A"></textarea>
                </label>
            </div>
            <div class="formulaire-ligne">
                <label>Forme des noms gardés sur cet ordinateur
                    <select id="imp-format">
                        <option value="prenom-initiale">Prénom + initiale (Léa D.)</option>
                        <option value="prenom-nom">Prénom NOM (Léa DUPONT)</option>
                        <option value="nom-prenom">NOM Prénom (DUPONT Léa)</option>
                    </select></label>
                <label>Classe, si le fichier n'a pas de colonne « Classe »
                    <input id="imp-classe-defaut" maxlength="20" placeholder="ex. 5A"></label>
                <label>Rattacher les nouvelles classes à
                    <select id="imp-etab"><option value="">Aucun établissement</option>
                        <?php foreach ($etabs as $e): ?><option value="<?= (int) $e['id'] ?>"><?= h($e['nom']) ?></option><?php endforeach; ?>
                    </select></label>
                <button type="button" id="imp-analyser">Analyser</button>
            </div>
            <p class="message erreur" id="imp-erreur" hidden></p>
        </section>

        <section id="imp-apercu" hidden>
            <h2>Aperçu</h2>
            <p class="aide" id="imp-resume"></p>
            <div class="defile"><table>
                <thead><tr><th><input type="checkbox" id="imp-tout" checked aria-label="Tout cocher"></th><th>Classe</th><th>Élèves dans le fichier</th><th>À créer</th><th>Déjà présents</th><th>PAP / PPS / PAI</th><th>Remarque</th></tr></thead>
                <tbody id="imp-classes"></tbody>
            </table></div>
            <div class="ligne-actions" style="margin-top:12px">
                <button type="button" id="imp-lancer">Créer les classes et les codes</button>
                <span class="discret">Les élèves déjà présents (même nom sur cet ordinateur) ne sont pas recréés.</span>
            </div>
        </section>

        <section id="imp-resultat" hidden class="nouveaux">
            <div class="titre-section">
                <h2>Import terminé</h2>
                <div class="boutons">
                    <button type="button" onclick="window.print()">Imprimer les fiches</button>
                    <button type="button" class="secondaire" id="imp-csv">Télécharger les codes (CSV)</button>
                </div>
            </div>
            <p class="alerte-bloc">Le fichier des codes a été téléchargé : il contient les noms, qui ne sont <strong>pas</strong> sur le serveur.
                Rangez-le dans un dossier privé de votre Nuage. Les codes ne seront plus jamais affichés.</p>
            <div id="imp-bilan"></div>
            <div class="fiches" id="imp-fiches" aria-hidden="true"></div>
        </section>

        <script type="application/json" id="imp-donnees"><?= json_encode([
            'classes' => array_map(fn($c) => ['nom' => $c['nom'], 'nb' => (int) $c['nb']], $classes),
            'lien_eleves' => reglage('lien_eleves', ''),
            'csrf' => $_SESSION['csrf'],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <script src="import.js?v=<?= @filemtime(__DIR__ . '/../public/admin/import.js') ?: 1 ?>" defer></script>
    </main>
    <?php
    pied();
}
