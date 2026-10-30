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
<title>Technologie · Mes ateliers</title>
<style>
/* Papier millimétré, encre de dessin technique, surligneur : le monde de la techno au collège */
:root {
    --papier: #f5f7f4; --grille-fine: #e5eaee; --grille: #d3dce4;
    --surface: #ffffff; --texte: #22262c; --doux: #66707c; --bord: #cfd6dd;
    --encre: #1d4f91; --encre-texte: #ffffff; --surligneur: #f4c542; --surligneur-texte: #22262c;
    --ok: #1f6b3a; --ok-fond: #e3f1e7; --encours: #8a5300; --encours-fond: #fbefd6; --erreur: #b3261e;
    --code-fond: #eef1f4;
    color-scheme: light;
}
@media (prefers-color-scheme: dark) {
    :root {   /* cyanotype : le « bleu » des plans */
        --papier: #0e2135; --grille-fine: #142b44; --grille: #1c3b5c;
        --surface: #12283f; --texte: #e7eef5; --doux: #9db0c4; --bord: #2a4a6b;
        --encre: #8ec1ff; --encre-texte: #0b1a2a; --surligneur: #f4c542; --surligneur-texte: #1a1a1a;
        --ok: #8fd3a5; --ok-fond: #17362a; --encours: #f0b35a; --encours-fond: #3a2f1b; --erreur: #ff8a7a;
        --code-fond: #183350;
        color-scheme: dark;
    }
}
* { box-sizing: border-box; }
html { background: var(--papier); }
body {
    margin: 0; color: var(--texte); font: 17px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
    background-color: var(--papier);
    background-image:
        linear-gradient(var(--grille) 1px, transparent 1px), linear-gradient(90deg, var(--grille) 1px, transparent 1px),
        linear-gradient(var(--grille-fine) 1px, transparent 1px), linear-gradient(90deg, var(--grille-fine) 1px, transparent 1px);
    background-size: 40px 40px, 40px 40px, 8px 8px, 8px 8px;
    min-height: 100vh;
}
header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
         padding: 14px 20px; background: var(--surface); border-bottom: 1px solid var(--bord); }
.marque { font-weight: 800; font-size: 1.05rem; letter-spacing: -.01em; color: var(--encre); }
#qui { display: flex; align-items: center; gap: 10px; font-size: .95rem; }
#qui code { font: 600 .9rem ui-monospace, "Cascadia Mono", Menlo, Consolas, monospace; }
main { max-width: 900px; margin: 0 auto; padding: 28px 16px 64px; }
h1 { margin: 0 0 6px; font-size: 1.75rem; line-height: 1.2; letter-spacing: -.015em; }
h2.section { font-size: 1.25rem; margin: 32px 0 12px; }
.sous { color: var(--doux); margin: 0 0 20px; }
.carte { background: var(--surface); border: 1px solid var(--bord); border-radius: 12px; padding: 18px; }
button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 8px; cursor: pointer;
         border: 1px solid var(--encre); background: var(--encre); color: var(--encre-texte); white-space: nowrap; }
button.secondaire { background: transparent; color: var(--encre); }
button.petit { padding: 4px 10px; font-size: .88rem; }
button.lien { background: none; border: 0; padding: 0; color: var(--encre); font-weight: 500; font-size: .92rem; }
button[disabled] { opacity: .45; cursor: default; }
:focus-visible { outline: 3px solid var(--surligneur); outline-offset: 2px; }
.vide { color: var(--doux); font-style: italic; }

/* ---------- Connexion : la fiche ---------- */
#ecran-connexion { max-width: 620px; margin: 4vh auto 0; }
#ecran-connexion h1 { font-size: clamp(1.7rem, 4.5vw, 2.3rem); }
.fiche {
    margin-top: 22px; padding: 22px 24px 10px; background: var(--surface);
    border: 2px dashed var(--doux); border-radius: 4px;            /* comme la fiche découpée */
    box-shadow: 0 1px 0 var(--bord), 0 14px 30px -18px rgba(20, 40, 70, .35);
}
.fiche label { display: block; font-weight: 700; margin-bottom: 8px; }
.ligne-code { font: 600 clamp(1.05rem, 5vw, 1.55rem)/1 ui-monospace, "Cascadia Mono", Menlo, Consolas, monospace; }
#centrale-code {
    font: inherit; letter-spacing: 0; width: 100%; padding: 14px 14px; color: var(--texte);
    background: var(--code-fond); border: 2px solid var(--bord); border-radius: 8px;
}
#centrale-code::placeholder { color: var(--doux); opacity: .55; }
#centrale-code:focus { outline: none; border-color: var(--encre); background: var(--surface); }
#centrale-code[aria-invalid="true"] { border-color: var(--erreur); }

/* Cotes : les trois parties du code, dessinées comme sur un plan */
.cotes { position: relative; display: flex; gap: 1ch; padding: 0 16px; margin-top: 12px; height: 2.4em; font-size: inherit; }
.cote { position: relative; height: 0; border-top: 1.5px solid var(--encre); transform-origin: left; }
.cote::before, .cote::after { content: ""; position: absolute; top: -6px; width: 0; height: 10px; border-left: 1.5px solid var(--encre); }
.cote::before { left: 0; } .cote::after { right: 0; }
.cote span {
    position: absolute; left: 50%; top: 8px; transform: translateX(-50%); white-space: nowrap;
    font: 600 .8rem/1.2 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--encre);
}
.cote.bas span { top: 30px; }
.cote.bas::before { height: 32px; }   /* ligne d'attache plus longue vers l'étiquette du dessous */
.cote.secret { border-top-color: var(--texte); }
.cote.secret span { background: var(--surligneur); color: var(--surligneur-texte); padding: 1px 6px; border-radius: 3px; }
.c-classe { width: 2ch; } .c-etab { width: 3ch; } .c-secret { width: 14ch; }
@media (prefers-reduced-motion: no-preference) {
    .cote { animation: tracer .5s cubic-bezier(.2, .7, .2, 1) both; }
    .cote span { animation: apparaitre .3s ease-out both; }
    .c-classe, .c-classe span { animation-delay: .15s; }
    .c-etab, .c-etab span { animation-delay: .35s; }
    .c-secret, .c-secret span { animation-delay: .55s; }
    .cote span { animation-delay: inherit; }
    @keyframes tracer { from { transform: scaleX(0); } }
    @keyframes apparaitre { from { opacity: 0; } }
}
.fiche-pied { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; margin-top: 6px; }
.fiche-pied button { padding: 11px 22px; font-size: 1.02rem; }
.fiche-pied .astuce { color: var(--doux); font-size: .88rem; }
#erreur-code { color: var(--erreur); font-weight: 600; min-height: 1.5em; margin: 8px 0 0; }
.aide-connexion { margin: 18px 4px 0; color: var(--doux); font-size: .93rem; max-width: 60ch; }
.aide-connexion strong { color: var(--texte); }

/* ---------- Mes ateliers ---------- */
.identite { margin: -6px 0 22px; padding: 12px 16px; }
.barre-prog { height: 8px; border-radius: 4px; background: var(--code-fond); overflow: hidden; margin: 10px 0 4px; }
.barre-prog i { display: block; height: 100%; background: var(--encre); transition: width .4s; }
.grille { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.atelier { display: flex; flex-direction: column; gap: 4px; }
.atelier-tete { display: flex; gap: 12px; align-items: center; }
.icone { width: 46px; height: 46px; border-radius: 10px; display: grid; place-items: center; font-size: 1.6rem; flex: none; background: var(--code-fond); }
.atelier h3 { margin: 0; font-size: 1.1rem; }
.atelier .desc { color: var(--doux); font-size: .92rem; margin: 6px 0 0; }
.atelier .compte { font-size: .85rem; color: var(--doux); }
.atelier .pied { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-top: auto; padding-top: 10px; }
.atelier .pied > button:last-child { margin-left: auto; }
.activites { list-style: none; margin: 10px 0 0; padding: 0; max-height: 320px; overflow: auto; border-top: 1px solid var(--bord); }
.activites .groupe { font-size: .82rem; font-weight: 700; color: var(--doux); padding: 10px 0 2px; }
.activites li.act { display: grid; grid-template-columns: 22px 1fr auto; gap: 8px; align-items: center; padding: 6px 0;
                    border-bottom: 1px solid var(--bord); font-size: .93rem; }
.puce { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--bord); display: grid; place-items: center; font-size: .7rem; }
.act.termine .puce { background: var(--ok); border-color: var(--ok); color: var(--surface); }
.act.commence .puce { border-color: var(--encours); }
.act .etat { font-size: .8rem; color: var(--doux); }
.act.commence .etat { color: var(--encours); }
.parcours { margin-bottom: 14px; }
.parcours-tete { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; flex-wrap: wrap; }
.parcours-tete h3 { margin: 0; font-size: 1.15rem; }
.pct { font-weight: 700; color: var(--encre); }
.consigne { color: var(--doux); margin: 6px 0 0; font-size: .95rem; }
ol.etapes { list-style: none; margin: 14px 0 0; padding: 0; }
ol.etapes li { display: grid; grid-template-columns: 34px 1fr auto; gap: 12px; align-items: center; padding: 10px 6px; border-top: 1px solid var(--bord); }
.num { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: .9rem;
       border: 2px solid var(--bord); color: var(--doux); }
li.termine .num { background: var(--ok); border-color: var(--ok); color: var(--surface); }
li.commence .num { border-color: var(--encours); color: var(--encours); }
li.suivante .num { border-color: var(--encre); color: var(--encre); }
.etape-titre { font-weight: 600; }
.etape-info { font-size: .85rem; color: var(--doux); }
li.termine .etape-info { color: var(--ok); }
li.commence .etape-info { color: var(--encours); }
li.suivante { background: color-mix(in srgb, var(--encre) 7%, transparent); border-radius: 8px; }
#chargement { position: fixed; inset: 0; display: grid; place-items: center; background: color-mix(in srgb, var(--papier) 85%, transparent);
              font-weight: 600; font-size: 1.1rem; z-index: 10; }
[hidden] { display: none !important; }
@media (max-width: 520px) {
    main { padding-top: 18px; }
    .fiche { padding: 18px 14px 16px; }
    .cotes { padding: 0 12px; }
    ol.etapes li { grid-template-columns: 30px 1fr; }
    ol.etapes li .action { grid-column: 2; }
}
</style>
</head>
<body>
<header>
    <span class="marque">Technologie</span>
    <div id="qui" hidden></div>
</header>

<main>
    <section id="ecran-connexion" hidden>
        <h1>Ton code est sur ta fiche</h1>
        <p class="sous">Tape-le une seule fois : tu retrouves ensuite tous tes ateliers et ton travail.</p>

        <form class="fiche" id="form-code" autocomplete="off" novalidate>
            <label for="centrale-code">Mon code</label>
            <div class="ligne-code">
                <input id="centrale-code" name="code" required spellcheck="false" autocapitalize="off" autocorrect="off"
                       inputmode="text" placeholder="4C-LHA-9943-kfmz-tqpr" aria-describedby="cotes-aide erreur-code">
                <div class="cotes" id="cotes-aide" role="img"
                     aria-label="Exemple : 4C est ta classe, LHA ton collège, puis ta partie secrète.">
                    <div class="cote c-classe"><span>ta classe</span></div>
                    <div class="cote c-etab bas"><span>ton collège</span></div>
                    <div class="cote c-secret secret"><span>ta partie secrète</span></div>
                </div>
            </div>
            <div class="fiche-pied">
                <button type="submit" id="bouton-code">Se connecter</button>
                <span class="astuce">Majuscules et espaces : pas d'importance.</span>
            </div>
            <p id="erreur-code" role="alert"></p>
        </form>

        <p class="aide-connexion"><strong>Code refusé ?</strong> Vérifie chaque lettre de ta fiche.
            Si ça ne marche toujours pas, ton professeur peut t'en donner un nouveau.
            Ne prête jamais ton code : c'est lui qui protège ton travail.</p>
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
</main>
<div id="chargement" hidden>Ouverture de l'atelier…</div>

<script src="js/centrale-client.js?v=<?= $v ?>"></script>
<script>
  const $ = (id) => document.getElementById(id);
  const PORTAIL = location.origin + location.pathname;   // adresse du bouton « ← Mes ateliers »
  Centrale.init({ api: 'api/', app: 'portail', boutonRetour: false, surDeconnexion: () => location.reload() });
  if (Centrale.connecte()) {
    Centrale.afficherConnexion(document.createElement('div'), { onConnecte: demarrer });   // session déjà ouverte
  } else {
    $('ecran-connexion').hidden = false;
    $('centrale-code').focus();
  }

  $('form-code').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const champ = $('centrale-code'), bouton = $('bouton-code'), erreur = $('erreur-code');
    erreur.textContent = '';
    champ.removeAttribute('aria-invalid');
    if (!champ.value.trim()) { erreur.textContent = 'Tape le code écrit sur ta fiche.'; champ.focus(); return; }
    bouton.disabled = true; bouton.textContent = 'Connexion…';
    try {
      demarrer(await Centrale.connexion(champ.value));
    } catch (e) {
      erreur.textContent = e.message;
      champ.setAttribute('aria-invalid', 'true');
      champ.select();
    }
    bouton.disabled = false; bouton.textContent = 'Se connecter';
  });

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
