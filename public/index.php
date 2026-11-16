<?php
/**
 * Centrale élèves — portail élève.
 * L'élève tape son code une fois, voit ses ateliers avec son avancement,
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
    --code-fond: #eef1f4; --violet: #6a3fb5;
    color-scheme: light;
}
@media (prefers-color-scheme: dark) {
    :root {   /* cyanotype : le « bleu » des plans */
        --papier: #0e2135; --grille-fine: #142b44; --grille: #1c3b5c;
        --surface: #12283f; --texte: #e7eef5; --doux: #9db0c4; --bord: #2a4a6b;
        --encre: #8ec1ff; --encre-texte: #0b1a2a; --surligneur: #f4c542; --surligneur-texte: #1a1a1a;
        --ok: #8fd3a5; --ok-fond: #17362a; --encours: #f0b35a; --encours-fond: #3a2f1b; --erreur: #ff8a7a;
        --code-fond: #183350; --violet: #c2a8ff;
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
    min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column;
}
footer.pied-portail { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 8px 24px; flex: none;
         padding: 12px 22px; margin-top: 24px; background: var(--surface); border-top: 1px solid var(--bord); font-size: .76rem; line-height: 1.4; color: var(--doux); }
footer.pied-portail strong { color: var(--encre); }
.pied-marque { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; }
.pied-marque img { display: block; opacity: .85; }
.pied-rgpd { display: flex; align-items: flex-start; gap: 8px; max-width: 720px; justify-self: center; }
.pied-rgpd svg { width: 15px; height: 15px; flex: none; margin-top: 1px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.pied-version { justify-self: end; padding: 2px 9px; border-radius: 999px; border: 1px solid var(--bord); font-variant-numeric: tabular-nums; white-space: nowrap; }
@media (max-width: 760px) {
  footer.pied-portail { grid-template-columns: 1fr auto; padding: 10px 14px; }
  .pied-rgpd { grid-column: 1 / -1; grid-row: 2; justify-self: start; }
}
header { position: relative; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
         padding: 24px 20px; min-height: 84px; background: var(--surface); border-bottom: 1px solid var(--bord); }
.marque { font-weight: 800; font-size: 1.05rem; letter-spacing: -.01em; color: var(--encre); }
/* Barre du haut commune aux applications élèves (référence : NetSim) : logo | widget de l'élève | atelier en cours | réglages */
/* mêmes mesures que les applications : 52 px de haut, logo 28 px et nom 19 px, pilules de 34 px, atelier au milieu de la page */
header.topbar { position: sticky; top: 0; z-index: 20; box-sizing: border-box; display: grid; grid-template-columns: minmax(max-content, 1fr) auto minmax(max-content, 1fr);
  align-items: center; gap: 12px; padding: 8px 16px; min-height: 52px; background: color-mix(in srgb, var(--surface) 92%, transparent); backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--bord); box-shadow: 0 1px 8px rgba(0, 0, 0, .04); }
.tb-gauche { grid-column: 1; justify-self: start; display: inline-flex; align-items: center; gap: 10px; min-width: 0; }
.brand { display: inline-flex; align-items: center; gap: 8px; font-weight: 800; font-size: 19px; letter-spacing: -.01em; color: var(--encre); text-decoration: none;
  margin-right: 6px; white-space: nowrap; }
.brand img { display: block; }
body:not(.connecte) .brand span { display: none; }
body.connecte .marque-bandeau { display: none; }
.tbid { display: inline-flex; align-items: center; box-sizing: border-box; height: 34px; border: 1px solid var(--bord); border-radius: 999px; background: var(--code-fond, var(--surface)); font-size: .9rem; }
.tbid[hidden] { display: none; }
.tbid-moi { display: inline-flex; align-items: center; gap: 7px; padding: 0 14px 0 11px; font-weight: 600; white-space: nowrap; color: var(--texte); }
.tbid-nom { font: 600 .86rem ui-monospace, "Cascadia Mono", Menlo, Consolas, monospace; }
.tbid-nom.tbid-prof { font-family: inherit; font-size: .9rem; }
.tbid-nom.tbid-sans-nom { font-style: italic; font-weight: 500; color: var(--doux); }
.tbid { position: relative; }
button.tbid-moi { height: 100%; border: 0; background: none; border-radius: 999px; cursor: pointer; font: inherit; font-weight: 600; }
button.tbid-moi:hover, button.tbid-moi[aria-expanded="true"] { color: var(--encre); }
.tbid-fleche { font-size: .7rem; color: var(--doux); margin-left: -2px; }
.menu-eleve { position: absolute; top: calc(100% + 6px); left: 0; z-index: 30; width: min(290px, calc(100vw - 20px)); padding: 10px 12px;
  background: var(--surface); border: 1px solid var(--bord); border-radius: 12px; box-shadow: 0 12px 32px rgba(0, 0, 0, .16); }
.menu-eleve[hidden] { display: none; }
.menu-eleve-titre { margin: 0 0 4px; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--ok, #2e8b57); }
/* identifiant et « Changer mon surnom » (rendus par le module), en version compacte */
.menu-eleve .menu-eleve-identite { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 6px; font-size: .78rem; color: var(--doux); }
.menu-eleve .menu-eleve-identite code { flex-basis: 100%; font: 700 .9rem ui-monospace, Menlo, Consolas, monospace; padding: 3px 8px; border-radius: 6px;
  background: var(--code-fond); color: var(--texte); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.menu-eleve .menu-eleve-identite > button { padding: 0; border: 0; background: none; color: var(--encre); font-size: .8rem; font-weight: 600; text-decoration: underline; text-underline-offset: 2px; cursor: pointer; }
.menu-eleve .menu-eleve-identite form { display: grid; grid-template-columns: auto 1fr; align-items: center; gap: 5px; flex-basis: 100%; margin-top: 2px; }
.menu-eleve .menu-eleve-identite form > span { font: 600 .82rem ui-monospace, Menlo, Consolas, monospace; color: var(--doux); }
.menu-eleve .menu-eleve-identite form input { width: 100%; min-width: 0; font: 600 .85rem ui-monospace, Menlo, Consolas, monospace; padding: 4px 7px; }
.menu-eleve .menu-eleve-identite form button { padding: 3px 10px; font-size: .78rem; border-radius: 999px; }
.menu-eleve .menu-eleve-identite form button[type="submit"] { grid-column: 2; grid-row: 2; justify-self: start; background: var(--encre); color: var(--encre-texte); border-color: var(--encre); }
.menu-eleve .menu-eleve-identite form button[type="button"] { grid-column: 2; grid-row: 2; justify-self: end; border: 0; background: none; color: var(--doux); text-decoration: underline; }
.menu-eleve .menu-eleve-identite .centrale-aide { grid-column: 1 / -1; font-size: .7rem; line-height: 1.35; opacity: .8; margin: 0; }
.menu-eleve .menu-eleve-identite .centrale-erreur { grid-column: 1 / -1; font-size: .72rem; margin: 0; }
.menu-eleve .menu-eleve-identite .centrale-erreur:empty { display: none; }
.menu-eleve-aide { margin: 8px 0 0; padding-top: 6px; border-top: 1px solid var(--bord); font-size: .7rem; color: var(--doux); }
body.barre-serree .brand span { display: none; }
body.barre-serree .brand { margin-right: 0; }
.topbar .i { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex: none; }
.tb-centre { grid-column: 2; justify-self: center; display: inline-flex; align-items: center; gap: 8px; box-sizing: border-box; height: 34px; max-width: min(560px, 100%); min-width: 0; padding: 0 4px 0 6px;
  border: 1px solid var(--bord); border-radius: 999px; background: var(--code-fond, var(--surface)); color: var(--texte);
  font-weight: 600; font-size: 13px; cursor: pointer; transition: border-color .15s; }
.tb-centre:hover { border-color: var(--encre); }
.tb-centre[hidden] { display: none; }
.tb-icone { display: inline-grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: var(--surface); font-size: .85rem; flex: none; }
/* Couleurs des applications : celles de leur charte graphique (CHARTE-GRAPHIQUE.md de chaque dépôt), en clair et en sombre */
.app-netsim { --app: #1c3268; --app-2: #178a4c; --app-3: #d98a00; --app-trait: #4a5b82; }
.app-etabli3d { --app: #1d7656; }
.app-technostudio { --app: #2F57A1; }
.app-parcours { --app: #6a3fb5; }   /* parcours placé dans le calendrier : plusieurs applications à la suite */
@media (prefers-color-scheme: dark) {
  .app-parcours { --app: #b49af0; }
  .app-netsim { --app: #9db4f2; --app-2: #4cc584; --app-3: #f0b03a; --app-trait: #6c7a99; }
  .app-etabli3d { --app: #4cb889; }
  .app-technostudio { --app: #8fa9ff; }
}
/* bande de repère des cartes d'atelier (charte de chaque application) : NetSim bleu marine, Établi 3D vert, TechnoStudio bleu clair */
.app-netsim { --app-bande: #1c3268; } .app-etabli3d { --app-bande: #1d7656; } .app-technostudio { --app-bande: #6f9bff; }
@media (prefers-color-scheme: dark) {
  .app-netsim { --app-bande: #3f62b8; } .app-etabli3d { --app-bande: #4cb889; } .app-technostudio { --app-bande: #8fb4ff; }
}
.logo-app { background: color-mix(in srgb, var(--app) 13%, var(--surface)) !important; }
.logo-app svg { width: 64%; height: 64%; display: block; }
.app-technostudio.logo-app svg { width: 74%; height: 74%; }
.tb-icone.logo-app { border-radius: 7px; }
.carte.atelier[class*="app-"] { border-left: 5px solid var(--app-bande, var(--app)); }
.tb-titre { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.tb-barres { display: inline-flex; gap: 2px; align-items: flex-end; flex: none; }
.tb-barres i { width: 5px; height: 14px; border-radius: 2px; background: var(--bord); }
.tb-barres i.encours { background: color-mix(in srgb, #2e8b57 45%, var(--bord)); }
.tb-barres i.fait { background: #2e8b57; }
.tb-compte { font: 600 12px ui-monospace, Menlo, Consolas, monospace; color: var(--doux); flex: none; }
.tb-reprendre { display: inline-flex; align-items: center; gap: 4px; height: 26px; padding: 0 12px; border-radius: 999px; background: var(--encre); color: var(--encre-texte); font-weight: 700; font-size: .85rem; flex: none; }
.tb-droite { grid-column: 3; justify-self: end; display: inline-flex; align-items: center; gap: 6px; }
.tb-droite[hidden] { display: none; }
/* Essai de l'enseignant : l'interrupteur Élève | Enseignant (module de la Centrale) se colle au centre de la barre */
.tb-droite:has(.tb-vue:not([hidden])) { justify-self: stretch; justify-content: flex-end; min-width: 0; }
.tb-droite .tb-vue { margin-right: auto; color: var(--texte); }
body.barre-serree .tb-vue .centrale-vue-txt { display: none; }
body.barre-serree .tb-vue button { padding: 0 8px; }
.tb-bouton { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border-radius: 999px; background: transparent; color: var(--doux); border: 1px solid transparent; font-size: .86rem; font-weight: 600; }
.tb-bouton:hover { color: var(--encre); border-color: var(--bord); }
@media (max-width: 760px) {
  header.topbar { padding: 6px 10px; gap: 6px; grid-template-columns: auto minmax(0, 1fr) auto; }
  .tb-gauche { gap: 6px; }
  .brand { margin-right: 0; }
  .tbid-txt, .brand span, .tb-barres, .tb-vue .centrale-vue-txt { display: none; }
  .tb-centre { justify-self: stretch; }
}
@media (max-width: 480px) {
  .tbid-nom, .tb-compte { display: none; }
  .tbid-moi { padding: 0 10px; }
}
main { flex: 1; width: 100%; max-width: 1080px; margin: 0 auto; padding: 12px 16px 12px; }
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
.marque-bandeau { position: absolute; left: 50%; transform: translateX(-50%); font-weight: 800; font-size: clamp(1.6rem, 3.6vw, 2.4rem); letter-spacing: -.01em; color: var(--encre); white-space: nowrap; }
#ecran-connexion h1 { font-size: clamp(1.4rem, 3.4vw, 1.8rem); font-weight: 700; letter-spacing: -.02em; margin: 0 0 6px; }
#ecran-connexion h1 { color: var(--encre); }
#ecran-connexion .sous { font-size: .95rem; line-height: 1.5; max-width: 52ch; margin: 0 0 16px; }
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
/* code et bouton centrés dans le cadre pointillé */
.fiche { display: flex; flex-direction: column; align-items: center; text-align: center; }
.fiche .ligne-code { display: flex; flex-direction: column; align-items: center; gap: 12px; width: 100%; }
.fiche .ligne-code input { text-align: center; width: 100%; max-width: 420px; }
.fiche-pied { flex-direction: column; justify-content: center; width: 100%; margin-top: 16px; }
.fiche-invite { margin: 0 0 14px; max-width: 46ch; color: var(--doux); font-size: .95rem; line-height: 1.5; }
.visuellement-cache { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
#erreur-code { color: var(--erreur); font-weight: 600; min-height: 1.5em; margin: 8px 0 0; }
.aide-connexion { margin: 18px 4px 0; color: var(--encre); font-size: .93rem; max-width: 60ch; }
.aide-connexion strong { color: var(--encre); }
/* Communiqué + RGPD, sous la fiche de connexion */
.presentation { margin: 22px 4px 0; }
.presentation h2 { font-size: 1.15rem; margin: 0 0 8px; letter-spacing: -.01em; color: var(--encre); }
.presentation p { margin: 0; color: var(--encre); }
.presentation .rgpd { margin-top: 14px; border-top: 1px solid var(--bord); padding-top: 12px; }
.presentation summary { cursor: pointer; font-weight: 700; color: var(--encre); }
.presentation summary:hover { text-decoration: underline; }
.presentation .rgpd ul { margin: 12px 0 0; padding-left: 20px; }
.presentation .rgpd li { margin: 6px 0; color: var(--texte); }
.presentation .rgpd-pied { color: var(--doux); font-size: .88rem; margin: 12px 0 0; }

/* ---------- Mes ateliers ---------- */
.identite { margin: -6px 0 22px; padding: 12px 16px; }
.barre-prog { height: 8px; border-radius: 4px; background: var(--code-fond); overflow: hidden; margin: 10px 0 4px; }
.barre-prog i { display: block; height: 100%; background: var(--encre); transition: width .4s; }
.grille { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.atelier { display: flex; flex-direction: column; gap: 4px; }
.atelier-tete { display: flex; gap: 12px; align-items: center; }
.icone { width: 46px; height: 46px; border-radius: 10px; display: grid; place-items: center; font-size: 1.6rem; flex: none; background: var(--code-fond); }
.atelier h3 { margin: 0; font-size: 1.1rem; }
.atelier .appli { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--doux); }
.atelier .desc { color: var(--doux); font-size: .92rem; margin: 6px 0 0; }
.atelier .compte { font-size: .85rem; color: var(--doux); }
.atelier .pied { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-top: auto; padding-top: 10px; }
.atelier .pied > button:last-child { margin-left: auto; }
.atelier.bientot { opacity: .62; filter: grayscale(1); background: color-mix(in srgb, var(--bord) 18%, var(--surface)); }
/* Mes ateliers : « Mon atelier en cours » en haut (un seul, en grand), puis quatre colonnes */
.colonnes { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; align-items: start; margin-top: 32px; }
@media (max-width: 1000px) { .colonnes { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .colonnes { grid-template-columns: 1fr; } }
.colonne { border: 1px solid var(--bord); border-top: 4px solid var(--col); border-radius: 14px; padding: 14px;
  background: color-mix(in srgb, var(--surface) 60%, transparent); }
.col-encours { --col: var(--surligneur); }
.col-encours h2.section .nb, .col-encours .col-ico { color: var(--surligneur-texte); background: var(--surligneur); } .col-complete { --col: var(--ok); } .col-bientot { --col: var(--doux); } .col-revision { --col: var(--violet); }
.colonne h2.section { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 1.05rem; }
.colonne h2.section .nb { margin-left: auto; background: color-mix(in srgb, var(--col) 16%, var(--surface)); color: var(--col); }
.col-ico { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; flex: none; font-size: .85rem;
  background: color-mix(in srgb, var(--col) 16%, var(--surface)); color: var(--col); }
.pile { display: flex; flex-direction: column; gap: 10px; }
.colonne .atelier { padding: 12px 14px; }
.colonne .atelier .icone { width: 36px; height: 36px; font-size: 1.2rem; border-radius: 8px; }
.colonne .atelier h3 { font-size: 1rem; }
.colonne .atelier .desc { display: none; }
.colonne .atelier .barre-prog { height: 6px; margin: 8px 0 2px; }
.col-complete .atelier .barre-prog i { background: var(--ok); }
.col-revision .atelier .barre-prog i { background: var(--violet); }
.col-revision .atelier .pied > button:last-child { background: var(--violet); border-color: var(--violet); }
.col-vide { margin: 0; padding: 8px 2px; color: var(--doux); font-size: .9rem; }
h2.section .nb { display: inline-grid; place-items: center; min-width: 24px; height: 22px; padding: 0 7px; border-radius: 999px; background: var(--code-fond); color: var(--doux); font-size: .78rem; vertical-align: middle; }
/* l'atelier en cours : liseré jaune tout autour, la bande de gauche garde la couleur de son application */
.atelier.courant { border: 2px solid var(--surligneur); box-shadow: 0 10px 30px color-mix(in srgb, var(--surligneur) 22%, transparent); padding: 20px 22px; }
.carte.atelier.courant[class*="app-"] { border-left: 7px solid var(--app-bande, var(--app)); }
.atelier.courant .icone { width: 60px; height: 60px; font-size: 2rem; }
.atelier.courant h3 { font-size: 1.35rem; }
.courant-suite { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; margin-top: 10px; padding: 10px 12px 10px 14px; border-radius: 12px;
  background: color-mix(in srgb, var(--encre) 8%, var(--surface)); }
/* 1re ligne : « Tu en es là » et jusqu'à quand (ou permanent) ; 2e : prochaine activité, applications du parcours, bouton */
.courant-etiquette { flex-basis: 100%; display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; }
.courant-etiquette > b { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--encre); }
.courant-suite strong { font-size: 1.05rem; flex: 1 1 180px; min-width: 0; }
.courant-go { font-size: 1rem; padding: 10px 20px; border-radius: 999px; }
.courant-validite { display: inline-flex; align-items: center; gap: 5px; padding: 1px 9px; border-radius: 999px; background: var(--surface); font-size: .8rem; color: var(--doux); white-space: nowrap; }
.courant-validite.limite { color: var(--encre); font-weight: 600; }
.courant-validite.proche { color: #b3261e; }
/* applications d'un parcours : grandes icônes, le nom en bulle */
.courant-apps { display: inline-flex; align-items: center; gap: 6px; flex: none; }
.courant-apps .icone-mini { width: 38px; height: 38px; border-radius: 10px; font-size: 1.2rem; display: grid; place-items: center; background: var(--code-fond);
  box-shadow: 0 0 0 2px var(--surface); }
@media (max-width: 520px) {
  .courant-suite strong { flex-basis: 100%; }
  .courant-apps .icone-mini { width: 32px; height: 32px; border-radius: 9px; }
  .courant-go { margin-left: auto; padding: 9px 14px; font-size: .95rem; }
}
.atelier.courant .pied > button:last-child { display: none; }   /* le grand bouton « Continuer » suffit */
details.bloc-ateliers > summary { list-style: none; cursor: pointer; }
details.bloc-ateliers > summary::-webkit-details-marker { display: none; }
details.bloc-ateliers > summary h2::before { content: '▸ '; color: var(--doux); }
details.bloc-ateliers[open] > summary h2::before { content: '▾ '; }
details.fait .atelier { opacity: .85; }
.bloc-ateliers { margin: 0; padding: 0; border: 0; background: none; }
@media (max-width: 980px) { .tb-bouton .tbid-txt { display: none; } .tb-bouton { padding: 0 10px; } }
.atelier.bientot .pied { justify-content: space-between; align-items: center; }
.rebours-bientot { font-weight: 700; color: var(--doux); font-variant-numeric: tabular-nums; }
.activites { list-style: none; margin: 10px 0 0; padding: 0; max-height: 320px; overflow: auto; border-top: 1px solid var(--bord); }
.activites .groupe { font-size: .82rem; font-weight: 700; color: var(--doux); padding: 10px 0 2px; }
.activites li.act { display: grid; grid-template-columns: 22px 1fr auto; gap: 8px; align-items: center; padding: 6px 0;
                    border-bottom: 1px solid var(--bord); font-size: .93rem; }
.puce { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--bord); display: grid; place-items: center; font-size: .7rem; }
.act.termine .puce { background: var(--ok); border-color: var(--ok); color: var(--surface); }
.act.commence .puce { border-color: var(--encours); }
.act .etat { font-size: .8rem; color: var(--doux); }
.act.commence .etat { color: var(--encours); }
.act.bloquee { opacity: .5; filter: grayscale(1); }   /* étapes pas encore débloquées : grisées, avec un cadenas */
.act.bloquee .puce { background: none; border: 0; font-size: .8rem; }
.afaire { padding: 4px 16px; margin-bottom: 14px; }
.afaire ol { list-style: none; margin: 0; padding: 0; }
.afaire li { display: grid; grid-template-columns: 46px 1fr auto; gap: 12px; align-items: center; padding: 12px 0; border-top: 1px solid var(--bord); }
.afaire li:first-child { border-top: 0; }
.afaire .icone { width: 40px; height: 40px; font-size: 1.3rem; }
.afaire .titre-act { font-weight: 600; }
.afaire .de { font-size: .85rem; color: var(--doux); }
.afaire .pour { font-size: .85rem; font-weight: 600; color: var(--encre); }
.afaire .pour.retard { color: var(--erreur); }
.afaire .consigne-prof { font-size: .92rem; margin: 2px 0 0; white-space: pre-line; }
.afaire li.termine { opacity: .7; }
.afaire li.termine .pour { color: var(--ok); }
.afaire li.commence .de::after { content: ' · en cours'; color: var(--encours); }
#chargement { position: fixed; inset: 0; display: grid; place-items: center; background: color-mix(in srgb, var(--papier) 85%, transparent);
              font-weight: 600; font-size: 1.1rem; z-index: 10; }
[hidden] { display: none !important; }
@media (max-width: 520px) {
    main { padding-top: 18px; }
    .fiche { padding: 18px 14px 16px; }
    .cotes { padding: 0 12px; }
    ol.etapes li { grid-template-columns: 30px 1fr; }
    ol.etapes li .action { grid-column: 2; }
    .afaire li { grid-template-columns: 40px 1fr; }
    .afaire li > button { grid-column: 2; justify-self: start; }
}
</style>
</head>
<body>
<header class="topbar">
    <div class="tb-gauche">
      <a class="brand" href="./" title="Mes ateliers"><img src="logo.svg" alt="" width="28" height="28"><span>TechnoVersailles</span></a>
      <div class="tbid" id="qui" hidden></div>
    </div>
    <span class="marque-bandeau">TechnoVersailles</span>
    <button type="button" class="tb-centre" id="en-cours" hidden></button>
    <div class="tb-droite" id="tb-droite" hidden></div>
</header>

<main>
    <section id="ecran-connexion" hidden>

        <form class="fiche" id="form-code" autocomplete="off" novalidate>
            <h1>Ton code est sur ta fiche</h1>
            <label for="centrale-code" class="visuellement-cache">Mon code</label>
            <p class="fiche-invite">Tape-le une seule fois : tu retrouves ensuite tous tes ateliers et ton travail.</p>
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

        <p class="aide-connexion"><strong>Code refusé ?</strong> Vérifie chaque lettre. Sinon, demande un nouveau code à ton professeur.
            Ne prête jamais ton code.</p>

        <section class="carte presentation" aria-labelledby="presentation-titre">
            <h2 id="presentation-titre">Un seul endroit pour tout ton travail de techno</h2>
            <p>Tu te connectes <strong>une fois</strong>, ton travail est <strong>enregistré tout seul</strong>
                et tu le retrouves sur n'importe quel ordinateur.</p>
            <details class="rgpd">
                <summary>Tes données sont protégées (RGPD)</summary>
                <ul>
                    <li><strong>Pas de nom sur le serveur.</strong> Il ne connaît que « ta classe · élève 07 »
                        et une empreinte de ton code. La liste des noms reste sur l'ordinateur de ton professeur.</li>
                    <li><strong>Hébergé en France.</strong> Aucune publicité, aucune revente, aucun pistage.</li>
                    <li><strong>Ton travail t'appartient.</strong> Il est gardé sur ton poste et, en sauvegarde,
                        sur le serveur ; tu peux l'exporter en fichier quand tu veux.</li>
                    <li><strong>Ton code te protège.</strong> C'est lui, et lui seul, qui garde ton travail à toi.</li>
                </ul>
                <p class="rgpd-pied">Conforme au RGPD : données minimales et pseudonymisées, pour l'usage
                    pédagogique de la classe uniquement.</p>
            </details>
        </section>
    </section>

    <section id="ecran-eleve" hidden>
        <h1>Mes ateliers</h1>
        <p class="sous">Choisis un atelier. Ton travail y est enregistré automatiquement ; reviens ici avec « ← Mes ateliers ».</p>
        <div id="bloc-courant" hidden></div>
        <div id="bloc-afaire" hidden>
            <h2 class="section">Demandé par ton professeur</h2>
            <div class="carte afaire"><ol id="liste-afaire"></ol></div>
        </div>
        <div id="liste-ateliers"></div>
    </section>
</main>

<footer class="pied-portail">
    <span class="pied-marque"><img src="logo.svg" alt="" width="18" height="18"><span><strong>Technologie</strong> · site éducatif</span></span>
    <?php
    // version de la Centrale, lue dans lib/commun.php (sans charger la bibliothèque)
    $version_centrale = '';
    if (is_file(__DIR__ . '/../lib/commun.php') && preg_match("/VERSION_CENTRALE = '([^']+)'/", (string) file_get_contents(__DIR__ . '/../lib/commun.php'), $m)) {
        $version_centrale = $m[1];
    }
    ?>
    <span class="pied-rgpd"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        <span><strong>RGPD</strong> : aucune donnée nominative n’est envoyée au serveur — les noms restent sur le poste de l’élève et de l’enseignant.
        Accès réservé aux élèves et enseignants de l’établissement.</span></span>
    <?php if ($version_centrale !== ''): ?><span class="pied-version">Version <?= htmlspecialchars($version_centrale) ?></span><?php endif; ?>
</footer>
<div id="chargement" hidden>Ouverture de l'atelier…</div>

<script src="js/centrale-client.js?v=<?= $v ?>"></script>
<script>
  const $ = (id) => document.getElementById(id);
  const PORTAIL = location.origin + location.pathname;   // adresse du bouton « ← Mes ateliers »
  // « Voir comme un élève » depuis l'espace enseignant : jeton transmis dans l'adresse (#centrale=…)
  const jetonApercu = decodeURIComponent((location.hash.match(/(?:^#|&)centrale=([^&]+)/) || [])[1] || '');
  Centrale.init({ api: 'api/', app: 'portail', boutonRetour: false, boutonSuivant: false, surDeconnexion: () => location.reload() });
  if (jetonApercu) {
    Centrale.reprendreSession(jetonApercu).then(demarrer).catch(() => { $('ecran-connexion').hidden = false; });
  } else if (Centrale.connecte()) {
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
    document.body.classList.add('connecte');
    // Widget de l'élève (comme dans NetSim, TechnoStudio, Établi 3D) : son identifiant
    const nomEleve = el('span', { class: 'tbid-nom' });
    // Vue Enseignant d'un essai : « Enseignant » ; un clic ouvre le choix de l'élève (Centrale.menuEssai, module récent)
    const majNom = () => {
      const id = Centrale.identifiant() || `${infos.classe} · ${infos.eleve}`;
      const prof = Centrale.nomEnseignant ? Centrale.nomEnseignant() : null;
      nomEleve.textContent = prof === null ? id : 'Enseignant';
      nomEleve.classList.toggle('tbid-prof', prof !== null);
      nomEleve.classList.remove('tbid-sans-nom');
      nomEleve.title = prof === null ? '' : `À la place de ${prof || id} · ${infos.classe} n° ${infos.eleve} — clic : changer d’élève`;
    };
    majNom();
    const moi = el('button', { type: 'button', class: 'tbid-moi', 'aria-haspopup': 'true', 'aria-expanded': 'false',
      title: 'Ton compte : ton identifiant, changer ton surnom' }, iconeEleve(), nomEleve, el('span', { class: 'tbid-fleche', 'aria-hidden': 'true' }, '▾'));
    // Menu du widget : l'identifiant et « Changer mon surnom » (reprendre : c'est le widget du centre)
    const identite = el('div', { class: 'menu-eleve-identite' });
    const menu = el('div', { class: 'menu-eleve', role: 'dialog', 'aria-label': 'Ton compte' },
      el('p', { class: 'menu-eleve-titre' }, 'Connecté'), identite,
      el('p', { class: 'menu-eleve-aide' }, 'Ton travail est enregistré automatiquement sur le serveur de la classe.'));
    menu.hidden = true;
    const ouvrirMenu = (oui) => { menu.hidden = !oui; moi.setAttribute('aria-expanded', String(oui)); };
    moi.onclick = (ev) => {
      ev.stopPropagation();
      if (Centrale.menuEssai && Centrale.vue && Centrale.vue() === 'enseignant') { ouvrirMenu(false); Centrale.menuEssai(moi); return; }
      ouvrirMenu(menu.hidden);
    };
    document.addEventListener('click', (ev) => {   // chemin du clic : un bouton du menu retiré au clic (« Changer mon surnom ») compte encore
      const chemin = ev.composedPath();
      if (!menu.hidden && !chemin.includes(menu) && !chemin.includes(moi)) ouvrirMenu(false);
    });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && !menu.hidden) { ouvrirMenu(false); moi.focus(); } });
    $('qui').hidden = false;
    $('qui').replaceChildren(moi, menu);
    Centrale.afficherIdentifiant(identite, {
      libelle: 'Ton identifiant',
      modifiable: true,   // le surnom ne se change que sur le portail, pas dans les applications
      onChange: () => { majNom(); ajusterBarre(); },
    });
    const sortir = el('button', { type: 'button', class: 'tb-bouton', title: 'Se déconnecter de la Centrale' },
      el('span', { 'aria-hidden': 'true' }, '⎋'), el('span', { class: 'tbid-txt' }, 'Se déconnecter'));
    sortir.onclick = () => { Centrale.deconnexion(); location.reload(); };
    $('tb-droite').hidden = false;
    // Essai de l'enseignant (« Tester à la place d'un élève ») : interrupteur Élève | Enseignant, juste après le centre.
    // Vue enseignant : tous les ateliers de la classe, ouverts, sans calendrier ni étapes verrouillées (api/ateliers.php).
    const vue = Centrale.boutonVue ? Centrale.boutonVue({ classe: 'tb-vue', onChange: () => { majNom(); ajusterBarre(); rafraichir(); } }) : null;
    $('tb-droite').replaceChildren(...(vue ? [vue.bouton] : []), sortir);
    rafraichir();
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && Centrale.connecte()) rafraichir(); });
  }

  function el(tag, attrs, ...enfants) {
    const e = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => (k === 'class' ? (e.className = v) : e.setAttribute(k, v)));
    enfants.flat().forEach((c) => c != null && e.append(c));
    return e;
  }

  /*
   * Logo de chaque application, repris de sa charte graphique (NetSim/CHARTE-GRAPHIQUE.md, etabli3d/…, TechnoStudio/…),
   * comme LOGOS_APPS (lib/commun.php) pour l'espace enseignant : modifier les deux ensemble ;
   * la couleur de l'application est dans la feuille de style (.app-netsim…, variable --app). Une application inconnue
   * garde l'icône déclarée dans son centrale.json.
   */
  const LOGOS = {
    netsim: '<svg viewBox="0 0 28 28" aria-hidden="true"><path d="M7 20L14 7l7 13z" fill="none" stroke="var(--app-trait)" stroke-width="2"/>'
      + '<circle cx="14" cy="7" r="4" fill="var(--app)"/><circle cx="7" cy="20" r="4" fill="var(--app-2)"/><circle cx="21" cy="20" r="4" fill="var(--app-3)"/></svg>',
    etabli3d: '<svg viewBox="0 0 32 32" aria-hidden="true" fill="none" stroke="var(--app)" stroke-width="2.4" stroke-linejoin="round">'
      + '<path d="M16 3 28 9.5v13L16 29 4 22.5v-13z"/><path d="M4 9.5 16 16l12-6.5M16 16v13"/></svg>',
    technostudio: '<svg viewBox="0 0 512 512" aria-hidden="true"><rect width="512" height="512" rx="112" fill="#2F57A1"/>'
      + '<path d="M120 176h272M256 176v208" stroke="#fff" stroke-width="28" stroke-linecap="round"/><circle cx="120" cy="176" r="30" fill="#F5C400"/>'
      + '<circle cx="392" cy="176" r="30" fill="#fff"/><circle cx="256" cy="384" r="34" fill="#fff"/></svg>',
  };
  function iconeApp(app, secours, classe) {
    const d = el('div', { class: (classe || 'icone') + (LOGOS[app] ? ' logo-app app-' + app : '') });
    if (LOGOS[app]) d.innerHTML = LOGOS[app]; else d.textContent = secours || '🧩';
    return d;
  }

  /* Ce que l'élève a ouvert en dernier depuis ce portail : le bouton « ← Reprendre » du widget le rouvre. */
  // (déclarations de fonctions : utilisables dès le démarrage, avant cette ligne)
  function lireDernier() { try { return JSON.parse(localStorage.getItem('portail-dernier') || 'null'); } catch (e) { return null; } }
  function ouvrir(url, activite, parcours, etape, titre, atelier) {
    try { localStorage.setItem('portail-dernier', JSON.stringify({ url, activite: activite || '', parcours: parcours || 0, etape, titre: titre || '' })); } catch (e) { /* rien */ }
    $('chargement').hidden = false;
    // atelier : carte d'un atelier du calendrier (Centrale.navigation, dans l'application, propose l'activité suivante de l'atelier)
    Centrale.ouvrir(url, { activite: activite || undefined, retour: PORTAIL, parcours: parcours || undefined, etape: etape, atelier: atelier || undefined });
  }

  async function rafraichir() {
    // pas de section « parcours » : un parcours placé dans le calendrier de la classe arrive ici comme une carte d'atelier
    let ateliers = [], propositions = [];
    try {
      [ateliers, propositions] = await Promise.all([Centrale.ateliers(),
        Centrale.propositions().catch(() => [])]);   // serveur plus ancien : pas de propositions
    } catch (e) {
      $('liste-ateliers').replaceChildren(el('p', { class: 'vide' }, e.message));
      return;
    }
    ateliers = parAtelier(ateliers);
    afficherAFaire(propositions);
    afficherEnCours(ateliers);
    ajusterBarre();
    afficherAteliers(ateliers);
  }

  /**
   * Classe avec un calendrier : les activités portent leur atelier → une carte par atelier (et non par application),
   * pour qu'un atelier fait aille dans « Déjà fait » et le suivant dans « Prochainement », même dans la même application.
   */
  function parAtelier(ateliers) {
    const res = [];
    ateliers.forEach((a) => {
      if (!(a.activites || []).some((x) => x.atelier)) { res.push(a); return; }
      const groupes = new Map();
      a.activites.forEach((x) => {
        const k = x.atelier || '';
        if (!groupes.has(k)) groupes.set(k, []);
        groupes.get(k).push(x);
      });
      groupes.forEach((liste, k) => {
        const compte = liste.filter((x) => x.disponible !== false || x.verrou);
        const faites = compte.filter((x) => x.statut === 'termine').length;
        const avant = liste.every((x) => x.disponible === false && !x.verrou);
        const dates = liste.map((x) => x.ouvre_le).filter(Boolean).sort();
        // atelier qui a son propre lien dans l'application (ex. NetSim « Premiers pas » : #atelier=premiers-pas)
        const o = liste[0].atelier_ouvrir, url = o && a.url ? String(a.url).split('#')[0] + o : a.url;
        res.push(Object.assign({}, a, {
          cle: a.app + '|' + k, nom: (liste[0].atelier_titre || a.nom), appli: a.nom, activites: liste, url,
          total: compte.length, terminees: faites, taux: compte.length ? faites / compte.length : 0,
          revision: liste.some((x) => x.revision),   // remis en avant par le professeur (Calendrier) : section « Révisions »
          disponible: !(avant && dates.length), disponible_le: avant && dates.length ? Math.floor(new Date(dates[0] + 'T00:00').getTime() / 1000) : null,
          description: '',
          a_completer: liste[0].a_completer || null,   // jusqu'à quand compléter l'atelier (calendrier), null = sans date
        }));
      });
    });
    return res;
  }

  /* ---------- Barre du haut, au centre : l'atelier en cours (ou la dernière activité ouverte) ---------- */
  function iconeEleve() {
    const ns = 'http://www.w3.org/2000/svg', svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('class', 'i'); svg.setAttribute('aria-hidden', 'true');
    svg.innerHTML = '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>';
    return svg;
  }
  /**
   * L'atelier courant : celui de la dernière activité ouverte depuis le portail (s'il n'est pas fini), sinon le plus avancé
   * des ateliers commencés, sinon le premier à faire. null s'il n'y a rien d'ouvert à faire.
   */
  function atelierCourant(ateliers) {
    const ouverts = ateliers.filter((a) => !enRevision(a) && ['encours', 'afaire'].includes(statutAtelier(a)));
    const dernier = lireDernier();
    const meme = dernier && (ouverts.find((a) => dernier.parcours && (a.activites || []).some((x) => x.parcours === dernier.parcours))
      || ouverts.find((a) => dernier.activite && (a.activites || []).some((x) => x.id === dernier.activite && x.disponible !== false))
      || ouverts.find((a) => a.url && dernier.url && a.url.split('#')[0] === String(dernier.url).split('#')[0]));
    if (meme) return meme;
    const commences = ouverts.filter((a) => statutAtelier(a) === 'encours').sort((u, v) => (v.taux || 0) - (u.taux || 0));
    return commences[0] || ouverts[0] || null;
  }
  /** Prochaine activité d'un atelier : la première pas encore terminée et ouverte (null s'il n'y en a pas). */
  function prochaineActivite(a) {
    return (a.activites || []).find((x) => x.statut === 'commence' && x.disponible !== false)
      || (a.activites || []).find((x) => x.statut !== 'termine' && x.disponible !== false) || null;
  }
  /**
   * Ouvre une activité d'une carte. Étape d'un parcours placé dans le calendrier (x.parcours) : sa propre application,
   * son activité, le parcours et le rang de l'étape (les applications ajoutent alors « Étape suivante »).
   */
  function ouvrirActivite(a, x) {
    if (x.parcours) ouvrir(x.url, x.activite || undefined, x.parcours, x.etape, (x.titre || x.id) + ' · ' + a.nom);
    else ouvrir(a.url, x.id, undefined, undefined, (x.titre || x.id) + ' · ' + a.nom, x.atelier);
  }
  /** Reprendre l'atelier courant : sa prochaine activité, ou l'atelier lui-même. */
  function reprendreAtelier(a) {
    // carte d'un atelier (calendrier) tout terminé : « Revoir » rouvre sa première étape, pas toute l'application
    const x = prochaineActivite(a) || (a.appli ? (a.activites || []).find((y) => y.disponible !== false) : null);
    if (x) ouvrirActivite(a, x);
    else ouvrir(a.url, undefined, undefined, undefined, a.nom);
  }

  /* ---------- Barre du haut trop étroite : le logo seul, sans « TechnoVersailles » ---------- */
  function ajusterBarre() {
    const h = document.querySelector('header.topbar'), t = document.querySelector('.tb-titre');
    if (!h) return;
    document.body.classList.remove('barre-serree');
    const deborde = h.scrollWidth > h.clientWidth + 1 || (t && t.offsetParent && t.scrollWidth > t.clientWidth + 1);
    document.body.classList.toggle('barre-serree', deborde);
  }
  window.addEventListener('resize', () => { clearTimeout(ajusterBarre.t); ajusterBarre.t = setTimeout(ajusterBarre, 80); });

  /* ---------- Barre du haut, au centre : l'atelier en cours — un clic le reprend ---------- */
  function afficherEnCours(ateliers) {
    const w = $('en-cours');
    w.replaceChildren();
    const a = atelierCourant(ateliers || []);
    const dernier = lireDernier();
    if (a) {
      const x = prochaineActivite(a), av = avancement(a), n = av.total, faites = av.faites;
      const barres = n && n <= 12 ? el('span', { class: 'tb-barres', 'aria-hidden': 'true' },
        ...((a.activites || []).length === n ? a.activites.map((y) => el('i', { class: y.statut === 'termine' ? 'fait' : y.statut === 'commence' ? 'encours' : '' }))
          : Array.from({ length: n }, (_, i) => el('i', { class: i < faites ? 'fait' : '' })))) : null;
      w.append(...[iconeApp(a.app, a.icone, 'tb-icone'), el('strong', { class: 'tb-titre' }, a.nom),
        barres, n ? el('span', { class: 'tb-compte' }, faites + '/' + n) : null,
        el('span', { class: 'tb-reprendre' }, el('span', { class: 'tbid-txt' }, av.commence ? 'Continuer' : 'Commencer'), ' →')].filter(Boolean));
      w.title = (av.commence ? 'Continuer ' : 'Commencer ') + a.nom + (x ? ' : ' + (x.titre || x.id) : '');
      w.onclick = () => reprendreAtelier(a);
      w.hidden = false;
    } else if (dernier && dernier.titre) {
      w.append(el('span', { class: 'tb-icone' }, '🧩'), el('strong', { class: 'tb-titre' }, dernier.titre),
        el('span', { class: 'tb-reprendre' }, el('span', { class: 'tbid-txt' }, 'Reprendre'), ' →'));
      w.title = 'Reprendre : ' + dernier.titre;
      w.onclick = () => ouvrir(dernier.url, dernier.activite, undefined, undefined, dernier.titre);
      w.hidden = false;
    } else w.hidden = true;
  }

  /* ---------- À faire : activités proposées par le professeur ---------- */
  function dateMoyenne(iso) {   // « jeu. 26 nov. »
    return new Date(iso + 'T12:00:00').toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' });
  }
  function dateCourte(iso) {
    return new Date(iso + 'T12:00:00').toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
  }
  function afficherAFaire(liste) {
    $('bloc-afaire').hidden = !liste.length;
    const aujourdhui = new Date().toISOString().slice(0, 10);
    // ce qui reste à faire d'abord, ce qui est terminé à la fin
    const tri = [...liste.filter((p) => p.statut !== 'termine'), ...liste.filter((p) => p.statut === 'termine')];
    $('liste-afaire').replaceChildren(...tri.map((p) => {
      const fini = p.statut === 'termine';
      const b = el('button', { class: fini ? 'secondaire' : '' }, fini ? 'Revoir' : p.statut === 'commence' ? 'Continuer' : 'Commencer');
      b.onclick = () => ouvrir(p.url, p.activite, undefined, undefined, p.titre || p.activite || '');
      const retard = !fini && p.pour_le && p.pour_le < aujourdhui;
      return el('li', { class: p.statut },
        iconeApp(p.app, p.icone),
        el('div', {},
          el('div', { class: 'titre-act' }, p.titre),
          el('div', { class: 'de' }, p.atelier + (p.groupe ? ' · ' + p.groupe : '') + (p.texte && !fini ? ' · ' + p.texte : '')),
          p.pour_le || fini ? el('div', { class: 'pour' + (retard ? ' retard' : '') },
            fini ? 'Terminé ✓' : (retard ? 'À rendre (en retard) : ' : 'Pour ') + dateCourte(p.pour_le)) : null,
          p.consigne ? el('div', { class: 'consigne-prof' }, p.consigne) : null),
        b);
    }));
  }

  /* ---------- Ateliers (En cours · Révisions · À venir · Déjà fait) ---------- */
  let minuterieBientot = null;

  /**
   * Avancement d'un atelier tel que l'élève le voit. Un atelier d'une seule activité (un tuto d'Établi 3D, un exercice
   * de NetSim) compte les étapes faites dans l'activité (« 2/6 », texte envoyé par l'application) ; sinon, les activités
   * terminées de l'atelier. commence : l'élève y a déjà travaillé (« Continuer » plutôt que « Commencer »).
   */
  function avancement(a) {
    const acts = a.activites || [], commence = acts.some((x) => x.statut !== 'a_faire');
    if (acts.length === 1 && acts[0].statut !== 'termine') {
      const m = /(\d+)\s*\/\s*(\d+)/.exec(acts[0].texte || '');
      if (m && +m[2] > 0) return { faites: +m[1], total: +m[2], taux: Math.min(1, +m[1] / +m[2]), unite: 'étape', commence };
    }
    return { faites: a.terminees || 0, total: a.total || 0, taux: a.taux || 0, unite: a.appli ? 'étape' : 'activité', commence };
  }

  /** Atelier remis en avant par le professeur (Calendrier → Révision), déjà ouvert : il va dans « Révisions ». */
  function enRevision(a) { return !!a.revision && a.disponible !== false; }

  function statutAtelier(a) {
    if (a.disponible === false) return 'bientot';                      // calendrier : pas encore ouvert
    if (a.total && a.terminees === a.total) return 'complete';         // tout terminé
    const commence = a.terminees > 0 || (a.activites || []).some((x) => x.statut !== 'a_faire');
    return commence ? 'encours' : 'afaire';
  }

  function texteRebours(ts) {
    let s = Math.max(0, Math.round(ts - Date.now() / 1000));
    if (s <= 0) return 'disponible — actualise la page';
    const j = Math.floor(s / 86400); s -= j * 86400;
    const h = Math.floor(s / 3600); s -= h * 3600;
    const m = Math.floor(s / 60); const x = s - m * 60;
    if (j >= 1) return 'dans ' + j + ' j ' + h + ' h';
    if (h >= 1) return 'dans ' + h + ' h ' + m + ' min';
    return 'dans ' + m + ' min ' + x + ' s';
  }
  function lancerReboursBientot() {
    if (minuterieBientot) { clearInterval(minuterieBientot); minuterieBientot = null; }
    const maj = () => document.querySelectorAll('.rebours-bientot[data-ts]').forEach((e) => {
      if (!e.closest('.atelier').hidden) e.textContent = texteRebours(parseInt(e.dataset.ts, 10));
    });
    maj();
    minuterieBientot = setInterval(maj, 1000);
  }

  function carteAtelier(a, ouverts) {
    const statut = statutAtelier(a);
    if (statut === 'bientot') {
      const node = el('div', { class: 'carte atelier bientot app-' + a.app },
        el('div', { class: 'atelier-tete' }, iconeApp(a.app, a.icone),
          el('div', {}, a.appli ? el('div', { class: 'appli' }, a.appli) : null, el('h3', {}, a.nom), el('div', { class: 'compte' }, 'Pas encore ouvert'))),
        a.description ? el('p', { class: 'desc' }, a.description) : null,
        el('div', { class: 'pied' },
          el('span', { class: 'rebours-bientot', 'data-ts': String(a.disponible_le || 0) }, '…'),
          el('button', { disabled: 'disabled', title: "Cet atelier s'ouvrira automatiquement le jour venu" }, 'Bientôt')));
      return { statut, node };
    }
    const av = avancement(a), pct = Math.round(av.taux * 100);
    const libelle = enRevision(a) ? 'Réviser' : a.terminees === a.total && a.total ? 'Revoir' : av.commence ? 'Continuer' : 'Ouvrir';
    const bouton = el('button', {}, libelle);
    // progression de la classe (étapes une à une) : le bouton ouvre la prochaine étape, pas toute l'application
    const pasAPas = (a.activites || []).some((x) => x.verrou);
    bouton.onclick = () => (pasAPas || a.appli ? reprendreAtelier(a) : ouvrir(a.url, undefined, undefined, undefined, a.nom));
    const liste = el('ul', { class: 'activites', 'data-app': a.cle || a.app });
    liste.hidden = !ouverts.has(a.cle || a.app);
    let groupe = null;
    // « Voir les étapes » : ce qui est débloqué, puis les suivantes grisées avec un cadenas (sans bouton)
    a.activites.forEach((x) => {
      if (x.groupe && x.groupe !== groupe) { groupe = x.groupe; liste.append(el('li', { class: 'groupe' }, groupe)); }
      // Périodes de disponibilité de l'atelier : pas encore ouvert (date) ou fermé
      // étape verrouillée (progression de la classe) : elle s'ouvre quand la précédente est terminée
      const bloquee = !!x.verrou || x.disponible === false;
      let b = el('span');
      if (!bloquee) {
        b = el('button', { class: 'petit secondaire' }, x.statut === 'termine' ? 'Revoir' : x.statut === 'commence' ? 'Continuer' : 'Ouvrir');
        b.onclick = () => ouvrirActivite(a, x);
      }
      const etat = x.verrou ? 'Après « ' + (x.apres || 'l’étape précédente') + ' »'
        : x.disponible === false ? (x.ouvre_le ? 'S’ouvre le ' + dateCourte(x.ouvre_le) : 'Pas encore ouvert')
        : (x.statut === 'termine' ? 'Terminé' : x.statut === 'commence' ? 'En cours' + (x.texte ? ' · ' + x.texte : '') : 'À faire')
          + (x.jusqu_au ? ' · ouvert jusqu’au ' + dateCourte(x.jusqu_au) : '');
      liste.append(el('li', { class: 'act ' + x.statut + (bloquee ? ' bloquee' : ''), title: bloquee ? 'Verrouillé : ' + etat : '' },
        el('span', { class: 'puce' }, bloquee ? '🔒' : x.statut === 'termine' ? '✓' : ''),
        el('div', {}, el('div', {}, x.titre), el('div', { class: 'etat' }, etat)),
        b));
    });
    const mot = a.appli ? 'les étapes' : 'les activités';
    const nv = a.activites.length;
    const voir = el('button', { class: 'lien' }, liste.hidden ? `Voir ${mot} (${nv})` : `Masquer ${mot}`);
    voir.onclick = () => { liste.hidden = !liste.hidden; voir.textContent = liste.hidden ? `Voir ${mot} (${nv})` : `Masquer ${mot}`; };
    const node = el('div', { class: 'carte atelier app-' + a.app },
      el('div', { class: 'atelier-tete' }, iconeApp(a.app, a.icone),
        el('div', {}, a.appli ? el('div', { class: 'appli' }, a.appli) : null, el('h3', {}, a.nom),
          av.total ? el('div', { class: 'compte' }, `${av.faites}/${av.total} ${av.unite}${av.total > 1 ? 's faites' : ' faite'}`) : null)),
      a.description ? el('p', { class: 'desc' }, a.description) : null,
      a.total ? el('div', { class: 'barre-prog' }, el('i', { style: `width:${pct}%` })) : null,
      nv ? liste : null,
      el('div', { class: 'pied' }, nv ? voir : el('span'), bouton));
    return { statut, node };
  }

  /** Nom affiché d'une application (icônes d'un parcours) : celui du portail, sinon un nom connu, sinon l'identifiant. */
  const NOMS_APPS = { netsim: 'NetSim', etabli3d: 'Établi 3D', technostudio: 'TechnoStudio', fichestudio: 'FicheStudio' };
  /**
   * Infos de « Tu en es là » : jusqu'à quand compléter l'atelier ou le parcours (date du calendrier, ou fin de son
   * ouverture), sinon « permanent » ; pour un parcours, les icônes de ses applications, dans l'ordre des étapes.
   */
  function infosCourant(a, ateliers) {
    const acts = a.activites || [];
    const fins = acts.map((x) => x.jusqu_au).filter(Boolean).sort();
    const date = a.a_completer || acts.map((x) => x.a_completer).find(Boolean) || fins[fins.length - 1] || null;
    const parcours = a.app === 'parcours';
    let validite;
    if (date) {
      const jours = Math.round((new Date(date + 'T12:00:00') - new Date(new Date().toLocaleDateString('sv') + 'T12:00:00')) / 864e5);
      validite = el('span', { class: 'courant-validite limite' + (jours <= 2 ? ' proche' : ''), title: 'Ton professeur a prévu cet atelier jusqu’au ' + dateCourte(date) },
        el('span', { 'aria-hidden': 'true' }, '📅'), jours < 0 ? 'À compléter : c’était jusqu’au ' + dateCourte(date) : jours > 1 ? 'À compléter jusqu’au ' + dateMoyenne(date) : 'À compléter ' + (jours ? 'd’ici demain' : 'aujourd’hui'));
    } else {
      validite = el('span', { class: 'courant-validite', title: 'Pas de date limite : tu peux le faire à ton rythme' },
        el('span', { 'aria-hidden': 'true' }, '♾'), parcours ? 'Parcours permanent' : 'Atelier permanent');
    }
    const apps = parcours ? (a.apps || [...new Set(acts.map((x) => x.app).filter(Boolean))]) : [];
    let icones = null;
    if (apps.length) {
      const noms = {};
      ateliers.forEach((b) => { if (b.app !== 'parcours') noms[b.app] = b.appli || b.nom; });
      const nom = (k) => NOMS_APPS[k] || noms[k] || k;
      icones = el('span', { class: 'courant-apps', role: 'img', 'aria-label': 'Applications du parcours : ' + apps.map(nom).join(', ') },
        ...apps.map((k) => { const i = iconeApp(k, '🧩', 'icone-mini'); i.title = nom(k); return i; }));
    }
    return { validite, icones };
  }

  /**
   * Mes ateliers : « Mon atelier en cours » en haut (un seul, en grand), puis quatre colonnes : En cours (les autres
   * ateliers ouverts), Déjà fait, À venir (en gris, avec le compte à rebours) et Révisions (remis en avant par le professeur).
   */
  function afficherAteliers(ateliers) {
    const zone = $('liste-ateliers'), haut = $('bloc-courant');
    if (!ateliers.length) {
      haut.hidden = true;
      zone.replaceChildren(el('h2', { class: 'section' }, 'Mes ateliers'), el('div', { class: 'carte' }, el('p', { class: 'vide', style: 'margin:0' },
        "Ton professeur n'a pas encore ouvert d'atelier pour ta classe.")));
      return;
    }
    const ouverts = new Set([...document.querySelectorAll('.activites:not([hidden])')].map((x) => x.dataset.app));
    const courant = atelierCourant(ateliers);
    // l'atelier courant, en grand
    if (courant) {
      const c = carteAtelier(courant, ouverts).node, x = prochaineActivite(courant);
      c.classList.add('courant');
      const infos = infosCourant(courant, ateliers);
      const go = el('button', { class: 'courant-go' }, (avancement(courant).commence ? 'Continuer' : 'Commencer') + ' →');
      go.onclick = () => reprendreAtelier(courant);
      const suite = el('div', { class: 'courant-suite' },
        el('div', { class: 'courant-etiquette' }, el('b', {}, statutAtelier(courant) === 'encours' ? 'Tu en es là' : 'Pour commencer'), infos.validite),
        el('strong', {}, x ? (x.titre || x.id) : 'Toutes les activités'), infos.icones, go);
      c.querySelector('.atelier-tete').after(suite);
      haut.replaceChildren(el('h2', { class: 'section' }, 'Mon atelier en cours'), c);   // un seul, en grand
      haut.hidden = false;
    } else haut.hidden = true;
    // les autres, rangés
    const reste = ateliers.filter((a) => a !== courant && !['encours', 'afaire'].includes(statutAtelier(a)) || enRevision(a));
    // dessous, quatre colonnes côte à côte (deux sous 1000 px, une seule sous 640 px) : En cours (les autres ateliers
    // ouverts, commencés d'abord), Déjà fait, À venir, Révisions
    const colonnes = [
      ['encours', 'En cours', '▶', ateliers.filter((a) => a !== courant && !enRevision(a) && ['encours', 'afaire'].includes(statutAtelier(a)))
        .sort((u, v) => (statutAtelier(u) === 'encours' ? 0 : 1) - (statutAtelier(v) === 'encours' ? 0 : 1) || (v.taux || 0) - (u.taux || 0)),
        'Pas d’autre atelier ouvert.'],
      ['complete', 'Déjà fait', '✓', reste.filter((a) => !enRevision(a) && statutAtelier(a) === 'complete'), 'Rien de terminé pour l’instant.'],
      ['bientot', 'À venir', '⏳', reste.filter((a) => !enRevision(a) && statutAtelier(a) === 'bientot')
        .sort((u, v) => (u.disponible_le || 0) - (v.disponible_le || 0)), 'Rien de prévu pour l’instant.'],
      ['revision', 'Révisions', '↺', reste.filter(enRevision), 'Pas de révision demandée.'],
    ];
    zone.replaceChildren(el('div', { class: 'colonnes' }, ...colonnes.map(([k, titre, ico, liste, vide]) =>
      el('section', { class: 'colonne col-' + k, 'aria-label': titre },
        el('h2', { class: 'section' }, el('span', { class: 'col-ico', 'aria-hidden': 'true' }, ico), titre, el('small', { class: 'nb' }, String(liste.length))),
        liste.length ? el('div', { class: 'pile' }, ...liste.map((a) => carteAtelier(a, ouverts).node)) : el('p', { class: 'col-vide' }, vide)))));
    lancerReboursBientot();
  }
</script>
</body>
</html>
