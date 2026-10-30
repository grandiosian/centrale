<?php
/**
 * Centrale élèves — portail élève.
 * L'élève tape son code une fois, voit les parcours de sa classe et son avancement,
 * et ouvre chaque logiciel sans retaper son code.
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
main { max-width: 760px; margin: 0 auto; padding: 28px 16px 64px; }
h1 { margin: 0 0 6px; font-size: 1.7rem; letter-spacing: -.01em; }
.sous { color: var(--doux); margin: 0 0 24px; }
.carte { background: var(--surface); border: 1px solid var(--bord); border-radius: 14px; padding: 20px; margin-bottom: 18px; }
button, a.bouton { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 8px; cursor: pointer; text-decoration: none;
         border: 1px solid var(--accent); background: var(--accent); color: var(--accent-texte); display: inline-block; white-space: nowrap; }
button.secondaire, a.bouton.secondaire { background: transparent; color: var(--accent); }
button.petit { padding: 4px 10px; font-size: .88rem; }
button[disabled] { opacity: .45; cursor: default; }
.parcours-tete { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; flex-wrap: wrap; }
.parcours-tete h2 { margin: 0; font-size: 1.25rem; }
.pct { font-weight: 700; color: var(--accent); }
.barre-prog { height: 8px; border-radius: 4px; background: var(--code-fond); overflow: hidden; margin: 10px 0 4px; }
.barre-prog i { display: block; height: 100%; background: var(--accent); transition: width .4s; }
.consigne { color: var(--doux); margin: 6px 0 0; font-size: .95rem; }
ol.etapes { list-style: none; margin: 16px 0 0; padding: 0; counter-reset: etape; }
ol.etapes li { display: grid; grid-template-columns: 34px 1fr auto; gap: 12px; align-items: center; padding: 10px 0;
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
.vide { color: var(--doux); font-style: italic; }
.atelier { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-top: 1px solid var(--bord); }
.atelier:first-of-type { border-top: 0; }
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

    <section id="ecran-parcours" hidden>
        <h1>Mes parcours</h1>
        <p class="sous">Ton travail est enregistré automatiquement dans chaque atelier.</p>
        <div id="liste-parcours"></div>
        <div class="carte" id="bloc-ateliers" hidden>
            <h2 style="margin:0 0 8px;font-size:1.1rem">Autres ateliers commencés</h2>
            <div id="liste-ateliers"></div>
        </div>
    </section>

    <footer><a href="admin/">Espace enseignant</a></footer>
</main>

<script src="js/centrale-client.js?v=<?= $v ?>"></script>
<script>
  const $ = (id) => document.getElementById(id);
  Centrale.init({ api: 'api/', app: 'portail', surDeconnexion: () => location.reload() });
  Centrale.afficherConnexion($('connexion'), { onConnecte: demarrer });

  function demarrer(infos) {
    $('ecran-connexion').hidden = true;
    $('ecran-parcours').hidden = false;
    $('qui').hidden = false;
    $('qui').innerHTML = '';
    $('qui').append(`${infos.classe} · élève ${infos.eleve} `);
    const sortir = document.createElement('button');
    sortir.className = 'petit secondaire';
    sortir.textContent = 'Se déconnecter';
    sortir.onclick = () => { Centrale.deconnexion(); location.reload(); };
    $('qui').append(sortir);
    rafraichir();
    // Quand l'élève revient sur cet onglet après avoir travaillé, on met l'avancement à jour
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && Centrale.connecte()) rafraichir(); });
  }

  function el(tag, attrs, ...enfants) {
    const e = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => (k === 'class' ? (e.className = v) : e.setAttribute(k, v)));
    enfants.flat().forEach((c) => c != null && e.append(c));
    return e;
  }

  async function rafraichir() {
    let parcours = [], toutes = {};
    try {
      [parcours, toutes] = await Promise.all([Centrale.parcours(), Centrale.toutesMesProgressions()]);
    } catch (e) {
      $('liste-parcours').replaceChildren(el('p', { class: 'vide' }, e.message));
      return;
    }
    const liste = $('liste-parcours');
    liste.replaceChildren();
    if (!parcours.length) {
      liste.append(el('div', { class: 'carte' }, el('p', { class: 'vide', style: 'margin:0' },
        "Ton professeur n'a pas encore créé de parcours pour ta classe.")));
    }
    const appsDansParcours = new Set();
    parcours.forEach((p) => {
      p.etapes.forEach((e) => appsDansParcours.add(e.app));
      const pct = Math.round(p.taux * 100);
      const suivante = p.etapes.findIndex((e) => e.statut !== 'termine');
      const ol = el('ol', { class: 'etapes' });
      p.etapes.forEach((e, i) => {
        const info = e.statut === 'termine' ? 'Terminé ✓' + (e.texte && !/^(\d+)\/\1$/.test(e.texte) ? ' · ' + e.texte : '')
          : e.statut === 'commence' ? 'En cours' + (e.texte ? ' · ' + e.texte : '') : 'À faire';
        const libelle = e.statut === 'termine' ? 'Revoir' : e.statut === 'commence' ? 'Continuer' : 'Commencer';
        const bouton = el('button', { class: e.statut === 'termine' ? 'secondaire' : '' }, libelle);
        if (e.url) bouton.onclick = () => Centrale.ouvrir(e.url, true);
        else { bouton.disabled = true; bouton.title = "Pas d'adresse pour cette étape"; }
        ol.append(el('li', { class: e.statut + (i === suivante ? ' suivante' : '') },
          el('span', { class: 'num' }, e.statut === 'termine' ? '✓' : String(i + 1)),
          el('div', {}, el('div', { class: 'etape-titre' }, e.titre), el('div', { class: 'etape-info' }, info)),
          el('div', { class: 'action' }, bouton)));
      });
      liste.append(el('div', { class: 'carte' },
        el('div', { class: 'parcours-tete' }, el('h2', {}, p.nom), el('span', { class: 'pct' }, pct + ' %')),
        el('div', { class: 'barre-prog' }, Object.assign(el('i'), { style: `width:${pct}%` })),
        p.description ? el('p', { class: 'consigne' }, p.description) : null,
        ol));
    });
    // Ateliers commencés hors parcours
    const autres = Object.entries(toutes || {}).filter(([app]) => !appsDansParcours.has(app) && app !== 'portail');
    $('bloc-ateliers').hidden = !autres.length;
    $('liste-ateliers').replaceChildren(...autres.map(([app, lignes]) => {
      const finies = lignes.filter((l) => l.statut === 'termine').length;
      return el('div', { class: 'atelier' }, el('span', {}, app), el('span', { class: 'etape-info' }, `${finies} activité${finies > 1 ? 's' : ''} terminée${finies > 1 ? 's' : ''} sur ${lignes.length} commencée${lignes.length > 1 ? 's' : ''}`));
    }));
  }
</script>
</body>
</html>
