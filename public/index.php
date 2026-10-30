<?php
/**
 * Centrale élèves — portail élève.
 * L'élève tape son code une fois, voit ses ateliers et ses parcours avec son avancement,
 * ouvre un atelier (déjà connecté) puis revient ici avec le bouton « ← Mes ateliers ».
 */
declare(strict_types=1);
header('X-Robots-Tag: noindex');
$v = @filemtime(__DIR__ . '/js/centrale-client.js') ?: 1;
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Mes ateliers · Technologie</title>
<style>
:root {
    --fond: #f6f5f2; --surface: #fff; --texte: #1d1f24; --doux: #62666f; --bord: #dedbd3;
    --accent: #1f5f8b; --accent-texte: #fff; --ok: #1f6b3a; --ok-fond: #e6f2ea; --encours: #9a5b00; --encours-fond: #fbf0dc;
    --code-fond: #efece6; color-scheme: light;
}
@media (prefers-color-scheme: dark) {
    :root {
        --fond: #16181c; --surface: #1f2228; --texte: #e8e6e1; --doux: #a2a5ad; --bord: #343841;
        --accent: #6aa9d6; --accent-texte: #0d1520; --ok: #8fd3a5; --ok-fond: #1b3324; --encours: #f0b35a; --encours-fond: #3a2f1b;
        --code-fond: #2a2e35; color-scheme: dark;
    }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--fond); color: var(--texte); font: 17px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
         padding: 12px 16px; background: var(--surface); border-bottom: 1px solid var(--bord); }
header strong { font-size: 1.05rem; }
#qui { display: flex; align-items: center; gap: 10px; font-size: .95rem; }
#qui code { font: 600 .9rem ui-monospace, Menlo, Consolas, monospace; }
.identite { margin: -6px 0 22px; padding: 12px 16px; }
main { max-width: 900px; margin: 0 auto; padding: 28px 16px 64px; }
h1 { margin: 0 0 6px; font-size: 1.7rem; letter-spacing: -.01em; }
h2.section { font-size: 1.25rem; margin: 32px 0 12px; }
.sous { color: var(--doux); margin: 0 0 20px; }
.carte { background: var(--surface); border: 1px solid var(--bord); border-radius: 14px; padding: 18px; }
button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 8px; cursor: pointer;
         border: 1px solid var(--accent); background: var(--accent); color: var(--accent-texte); white-space: nowrap; }
button.secondaire { background: transparent; color: var(--accent); }
button.petit { padding: 4px 10px; font-size: .88rem; }
button.lien { background: none; border: 0; padding: 0; color: var(--accent); font-weight: 500; font-size: .92rem; }
button[disabled] { opacity: .45; cursor: default; }
.barre-prog { height: 8px; border-radius: 4px; background: var(--code-fond); overflow: hidden; margin: 10px 0 4px; }
.barre-prog i { display: block; height: 100%; background: var(--accent); transition: width .4s; }
.vide { color: var(--doux); font-style: italic; }

/* Ateliers */
.grille { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.atelier { display: flex; flex-direction: column; gap: 4px; }
.atelier-tete { display: flex; gap: 12px; align-items: center; }
.icone { width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center; font-size: 1.6rem; flex: none;
         background: var(--code-fond); }
.atelier h3 { margin: 0; font-size: 1.1rem; }
.atelier .desc { color: var(--doux); font-size: .92rem; margin: 6px 0 0; }
.atelier .compte { font-size: .85rem; color: var(--doux); }
.atelier .pied { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: auto; padding-top: 10px; }
.activites { list-style: none; margin: 10px 0 0; padding: 0; max-height: 320px; overflow: auto; border-top: 1px solid var(--bord); }
.activites .groupe { font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; color: var(--doux); padding: 10px 0 2px; }
.activites li.act { display: grid; grid-template-columns: 22px 1fr auto; gap: 8px; align-items: center; padding: 6px 0;
                    border-bottom: 1px solid var(--bord); font-size: .93rem; }
.puce { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--bord); display: grid; place-items: center; font-size: .7rem; }
.act.termine .puce { background: var(--ok); border-color: var(--ok); color: var(--surface); }
.act.commence .puce { border-color: var(--encours); }
.act .etat { font-size: .8rem; color: var(--doux); }
.act.commence .etat { color: var(--encours); }

/* Parcours */
.parcours { margin-bottom: 14px; }
.parcours-tete { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; flex-wrap: wrap; }
.parcours-tete h3 { margin: 0; font-size: 1.15rem; }
.pct { font-weight: 700; color: var(--accent); }
.consigne { color: var(--doux); margin: 6px 0 0; font-size: .95rem; }
ol.etapes { list-style: none; margin: 14px 0 0; padding: 0; }
ol.etapes li { display: grid; grid-template-columns: 34px 1fr auto; gap: 12px; align-items: center; padding: 10px 6px;
               border-top: 1px solid var(--bord); }
.num { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: .9rem;
       border: 2px solid var(--bord); color: var(--doux); }
li.termine .num { background: var(--ok); border-color: var(--ok); color: var(--surface); }
li.commence .num { border-color: var(--encours); color: var(--encours); }
li.suivante .num { border-color: var(--accent); color: var(--accent); }
.etape-titre { font-weight: 600; }
.etape-info { font-size: .85rem; color: var(--doux); }
li.termine .etape-info { color: var(--ok); }
li.commence .etape-info { color: var(--encours); }
li.suivante { background: color-mix(in srgb, var(--accent) 6%, transparent); border-radius: 8px; }

#chargement { position: fixed; inset: 0; display: grid; place-items: center; background: color-mix(in srgb, var(--fond) 85%, transparent);
              font-weight: 600; font-size: 1.1rem; z-index: 10; }
footer { text-align: center; color: var(--doux); font-size: .85rem; margin-top: 40px; }
footer a { color: var(--doux); }
[hidden] { display: none !important; }
@media (max-width: 520px) {
    ol.etapes li { grid-template-columns: 30px 1fr; }
    ol.etapes li .action { grid-column: 2; }
}
</style>
</head>
<body>
<header>
    <strong>Technologie · Mes ateliers</strong>
    <div id="qui" hidden></div>
</header>

<main>
    <section id="ecran-connexion">
        <h1>Bonjour !</h1>
        <p class="sous">Tape ton code personnel une seule fois : tu pourras ensuite ouvrir tous tes ateliers.</p>
        <div class="carte" id="connexion"></div>
    </section>

    <section id="ecran-eleve" hidden>
        <h1>Mes ateliers</h1>
        <p class="sous">Choisis un atelier. Ton travail y est enregistré automatiquement ; reviens ici avec « ← Mes ateliers ».</p>
        <div class="carte identite" id="identite"></div>
        <div id="bloc-parcours" hidden>
            <h2 class="section">Mes parcours</h2>
            <div id="liste-parcours"></div>
            <h2 class="section">Tous les ateliers</h2>
        </div>
        <div class="grille" id="liste-ateliers"></div>
    </section>

    <footer><a href="admin/">Espace enseignant</a></footer>
</main>
<div id="chargement" hidden>Ouverture de l'atelier…</div>

<script src="js/centrale-client.js?v=<?= $v ?>"></script>
<script>
  const $ = (id) => document.getElementById(id);
  const PORTAIL = location.origin + location.pathname;   // adresse du bouton « ← Mes ateliers »
  Centrale.init({ api: 'api/', app: 'portail', boutonRetour: false, surDeconnexion: () => location.reload() });
  Centrale.afficherConnexion($('connexion'), { onConnecte: demarrer });

  // Revenir sur cette page avec le bouton « Précédent » : on remet l'avancement à jour
  window.addEventListener('pageshow', (e) => { if (e.persisted && Centrale.connecte()) { $('chargement').hidden = true; rafraichir(); } });

  function demarrer(infos) {
    $('ecran-connexion').hidden = true;
    $('ecran-eleve').hidden = false;
    $('qui').hidden = false;
    $('qui').replaceChildren(el('code', {}, Centrale.identifiant() || `${infos.classe} · ${infos.eleve}`), ' ');
    Centrale.afficherIdentifiant($('identite'), {
      onChange: (id) => { $('qui').firstChild.textContent = id; },
    });
    const sortir = el('button', { class: 'petit secondaire' }, 'Se déconnecter');
    sortir.onclick = () => { Centrale.deconnexion(); location.reload(); };
    $('qui').append(sortir);
    rafraichir();
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && Centrale.connecte()) rafraichir(); });
  }

  function el(tag, attrs, ...enfants) {
    const e = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => (k === 'class' ? (e.className = v) : e.setAttribute(k, v)));
    enfants.flat().forEach((c) => c != null && e.append(c));
    return e;
  }

  function ouvrir(url, activite) {
    $('chargement').hidden = false;
    Centrale.ouvrir(url, { activite: activite || undefined, retour: PORTAIL });
  }

  async function rafraichir() {
    let ateliers = [], parcours = [];
    try {
      [ateliers, parcours] = await Promise.all([Centrale.ateliers(), Centrale.parcours()]);
    } catch (e) {
      $('liste-ateliers').replaceChildren(el('p', { class: 'vide' }, e.message));
      return;
    }
    afficherParcours(parcours, ateliers);
    afficherAteliers(ateliers);
  }

  /* ---------- Ateliers ---------- */
  function afficherAteliers(ateliers) {
    const grille = $('liste-ateliers');
    if (!ateliers.length) {
      grille.replaceChildren(el('div', { class: 'carte' }, el('p', { class: 'vide', style: 'margin:0' },
        "Ton professeur n'a pas encore ouvert d'atelier pour ta classe.")));
      return;
    }
    const ouverts = new Set([...grille.querySelectorAll('.activites:not([hidden])')].map((x) => x.dataset.app));
    grille.replaceChildren(...ateliers.map((a) => {
      const pct = Math.round(a.taux * 100);
      const libelle = a.terminees === a.total && a.total ? 'Revoir' : pct > 0 ? 'Continuer' : 'Ouvrir';
      const bouton = el('button', {}, libelle);
      bouton.onclick = () => ouvrir(a.url);
      const liste = el('ul', { class: 'activites', 'data-app': a.app });
      liste.hidden = !ouverts.has(a.app);
      let groupe = null;
      a.activites.forEach((x) => {
        if (x.groupe && x.groupe !== groupe) { groupe = x.groupe; liste.append(el('li', { class: 'groupe' }, groupe)); }
        const b = el('button', { class: 'petit secondaire' }, x.statut === 'termine' ? 'Revoir' : x.statut === 'commence' ? 'Continuer' : 'Ouvrir');
        b.onclick = () => ouvrir(a.url, x.id);
        liste.append(el('li', { class: 'act ' + x.statut },
          el('span', { class: 'puce' }, x.statut === 'termine' ? '✓' : ''),
          el('div', {}, el('div', {}, x.titre), el('div', { class: 'etat' },
            x.statut === 'termine' ? 'Terminé' : x.statut === 'commence' ? 'En cours' + (x.texte ? ' · ' + x.texte : '') : 'À faire')),
          b));
      });
      const voir = el('button', { class: 'lien' }, liste.hidden ? `Voir les activités (${a.total})` : 'Masquer les activités');
      voir.onclick = () => { liste.hidden = !liste.hidden; voir.textContent = liste.hidden ? `Voir les activités (${a.total})` : 'Masquer les activités'; };
      return el('div', { class: 'carte atelier' },
        el('div', { class: 'atelier-tete' }, el('div', { class: 'icone' }, a.icone || '🧩'),
          el('div', {}, el('h3', {}, a.nom), a.total ? el('div', { class: 'compte' }, `${a.terminees}/${a.total} ${a.total > 1 ? 'activités terminées' : 'activité terminée'}`) : null)),
        a.description ? el('p', { class: 'desc' }, a.description) : null,
        a.total ? el('div', { class: 'barre-prog' }, el('i', { style: `width:${pct}%` })) : null,
        a.total ? liste : null,
        el('div', { class: 'pied' }, a.total ? voir : el('span'), bouton));
    }));
  }

  /* ---------- Parcours ---------- */
  function afficherParcours(parcours) {
    $('bloc-parcours').hidden = !parcours.length;
    $('liste-parcours').replaceChildren(...parcours.map((p) => {
      const pct = Math.round(p.taux * 100);
      const suivante = p.etapes.findIndex((e) => e.statut !== 'termine');
      const ol = el('ol', { class: 'etapes' });
      p.etapes.forEach((e, i) => {
        const info = e.statut === 'termine' ? 'Terminé ✓' : e.statut === 'commence' ? 'En cours' + (e.texte ? ' · ' + e.texte : '') : 'À faire';
        const b = el('button', { class: e.statut === 'termine' ? 'secondaire' : '' },
          e.statut === 'termine' ? 'Revoir' : e.statut === 'commence' ? 'Continuer' : 'Commencer');
        if (e.url) b.onclick = () => ouvrir(e.url, e.activite);
        else { b.disabled = true; b.title = "Pas d'adresse pour cette étape"; }
        ol.append(el('li', { class: e.statut + (i === suivante ? ' suivante' : '') },
          el('span', { class: 'num' }, e.statut === 'termine' ? '✓' : String(i + 1)),
          el('div', {}, el('div', { class: 'etape-titre' }, e.titre), el('div', { class: 'etape-info' }, info)),
          el('div', { class: 'action' }, b)));
      });
      return el('div', { class: 'carte parcours' },
        el('div', { class: 'parcours-tete' }, el('h3', {}, p.nom), el('span', { class: 'pct' }, pct + ' %')),
        el('div', { class: 'barre-prog' }, el('i', { style: `width:${pct}%` })),
        p.description ? el('p', { class: 'consigne' }, p.description) : null,
        ol);
    }));
  }
</script>
</body>
</html>
