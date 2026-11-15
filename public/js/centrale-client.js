/**
 * Centrale élèves — module à inclure dans chaque application élève.
 *
 *   <script src="https://VOTRE-COMPTE.alwaysdata.net/js/centrale-client.js"></script>
 *   <script>
 *     Centrale.init({ api: "https://VOTRE-COMPTE.alwaysdata.net/api/", app: "robot-mbot" });
 *
 *     Centrale.afficherConnexion(document.getElementById("connexion"), {
 *       onConnecte: async (infos) => {
 *         const projet = await Centrale.charger("projet", { blocs: [] });  // reprend le travail
 *         …
 *         Centrale.sauver("projet", projet);                               // à chaque modification
 *       }
 *     });
 *   </script>
 *
 * - La connexion dure le temps de l'onglet (sessionStorage) : fermer l'onglet déconnecte l'élève.
 * - Les sauvegardes sont regroupées (au plus une toutes les 3 s par clé).
 * - Sans réseau, le travail est gardé dans le navigateur et renvoyé dès que la connexion revient.
 */
(function (global) {
  'use strict';

  var CLE_SESSION = 'centrale-session';
  var PREFIXE_SECOURS = 'centrale-secours:';
  var DELAI_SAUVEGARDE = 3000;
  var DELAI_REESSAI = 10000;

  var PREFIXE_FILE = 'centrale-progression:';
  var config = { api: '', app: '*', activites: null, surEtat: null, surDeconnexion: null };
  var jetonTransmis = null;    // jeton reçu dans l'adresse (#centrale=…) depuis le portail ou une autre application
  var activiteTransmise = null; // activité à ouvrir directement (#activite=…)
  var CLE_RETOUR = 'centrale-retour';
  var CLE_ATELIER = 'centrale-atelier';   // { parcours, etape } : atelier transversal en cours dans cet onglet
  var memoire = null;          // session si sessionStorage est indisponible
  var attentes = {};           // cle -> { minuteur, promesses: [] }
  var bases = {};              // cle -> date (serveur) de la version sur laquelle l'élève travaille
  var etatCourant = { etat: 'pret', message: '' };
  var ecouteursEtat = [];

  /* ======================================================================
     Configuration et session
     ====================================================================== */

  function init(options) {
    config = Object.assign({}, config, options || {});
    // Compatibilité : on accepte aussi l'adresse de login.php
    config.api = String(config.api || '').replace(/login\.php$/, '');
    if (config.api && !/\/$/.test(config.api)) config.api += '/';
    if (typeof config.surEtat === 'function') ecouteursEtat.push(config.surEtat);
    lireJetonTransmis();
    if (config.boutonRetour !== false) {
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', afficherBoutonRetour);
      else afficherBoutonRetour();
    }
    if (config.boutonSuivant !== false && contexteAtelier()) {
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', demarrerBoutonSuivant);
      else demarrerBoutonSuivant();
    }
    return Centrale;
  }

  /* ---------- Retour au portail ---------- */

  function adresseRetour() {
    try { return sessionStorage.getItem(CLE_RETOUR); } catch (e) { return null; }
  }

  /** Revient au portail (« Mes ateliers ») en envoyant d'abord ce qui n'est pas enregistré. */
  function retour() {
    var r = adresseRetour();
    if (!r) return false;
    var b = document.getElementById('centrale-retour');
    if (b && typeof config.avantDeQuitter === 'function') { b.disabled = true; b.textContent = 'Enregistrement…'; }
    avantDeQuitter().then(function () {
      toutEnvoyer();
      return Promise.resolve(viderFileProgression()).catch(function () { /* renvoyée à la prochaine connexion */ });
    }).then(function () {
      location.href = r;
    });
    return true;
  }

  /**
   * Va sur le portail de la Centrale (« Mes ateliers ») : l'adresse de retour si l'application en vient, sinon la page
   * d'accueil du serveur de la Centrale. Comme retour(), ce qui n'est pas encore enregistré part d'abord.
   */
  function allerPortail() {
    if (retour()) return true;
    var racine = String(config.api || '').replace(/api\/?$/, '');
    if (!racine) return false;
    avantDeQuitter().then(function () {
      toutEnvoyer();
      return Promise.resolve(viderFileProgression()).catch(function () { /* renvoyée à la prochaine connexion */ });
    }).then(function () { location.href = racine; });
    return true;
  }

  /**
   * Option avantDeQuitter de Centrale.init : fonction (éventuellement asynchrone) appelée avant de quitter
   * l'application par « ← Mes ateliers » ou « Étape suivante », pour déposer ce qui attend encore
   * (ex. le projet dans le dossier de projet, un aperçu à calculer). 15 s au plus : au-delà, on part quand même.
   */
  var DELAI_AVANT_DE_QUITTER = 15000;
  function avantDeQuitter() {
    if (typeof config.avantDeQuitter !== 'function') return Promise.resolve();
    var fin = new Promise(function (ok) { setTimeout(ok, DELAI_AVANT_DE_QUITTER); });
    var travail = Promise.resolve().then(config.avantDeQuitter).catch(function () { /* on part quand même */ });
    return Promise.race([travail, fin]);
  }

  /** Petit bouton « ← Mes ateliers » en bas à gauche, quand l'application a été ouverte depuis le portail. */
  function afficherBoutonRetour() {
    if (!adresseRetour() || document.getElementById('centrale-retour')) return;
    var b = document.createElement('button');
    b.id = 'centrale-retour';
    b.type = 'button';
    b.textContent = '← Mes ateliers';
    b.title = 'Revenir à la liste de tes ateliers (ton travail est enregistré)';
    b.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:2147483000;font:600 14px/1 system-ui,-apple-system,"Segoe UI",sans-serif;'
      + 'padding:10px 14px;border-radius:999px;border:1px solid rgba(0,0,0,.15);background:#1f5f8b;color:#fff;cursor:pointer;'
      + 'box-shadow:0 4px 14px rgba(0,0,0,.2)';
    b.onclick = retour;
    document.body.appendChild(b);
  }

  /* ---------- Parcours : passer à l'étape suivante sans repasser par le portail ---------- */

  var suivant = { bouton: null, info: null, minuteur: 0, message: 0 };

  /**
   * Étape suivante du parcours ouvert dans cet onglet, ou null hors parcours :
   * { numero, etape: { titre, app, activite, url, ouverte, statut… } | null (fin du parcours),
   *   actuelle, fin, raison: texte si l'étape suivante n'est pas encore ouverte,
   *   portail: true si l'enseignant veut que le bouton du bas ramène à la Centrale (réglage « après » du parcours ou de l'étape) }.
   */
  async function etapeSuivante() {
    var d = await etape();
    if (!d) return null;
    var n = d.numero + 1;
    var e = d.etapes[n] || null;
    // Réglage de l'enseignant (parcours ou étape) : le bouton du bas ramène à la Centrale au lieu d'ouvrir l'étape suivante
    if (e && d.etape && d.etape.apres === 'portail') {
      return { numero: n, etape: e, actuelle: d.etape, fin: false, portail: true, raison: '', parcours: d.parcours };
    }
    var raison = '';
    if (e && !e.ouverte) {
      raison = d.parcours.deblocage === 'validation'
        ? 'Ton professeur doit d’abord valider cette étape.'
        : 'Termine d’abord cette étape.';
    } else if (e && !e.url) {
      raison = 'Pas d’adresse pour l’étape suivante : passe par « Mes ateliers ».';
    }
    return { numero: n, etape: e, actuelle: d.etape, fin: !e, raison: raison, parcours: d.parcours };
  }

  /**
   * Ouvre l'étape suivante (bonne application, bonne activité, même dossier de projet), après avoir
   * tout enregistré. À la fin du parcours, revient au portail. Renvoie false si l'étape est verrouillée.
   */
  async function allerEtapeSuivante() {
    var info = await etapeSuivante();
    if (!info) return false;
    var depose = false;
    if (info.raison && peutSeDebloquerEnPartant(info)) {
      // En mode « dans l'ordre », l'étape en cours se termine peut-être avec ce que l'application
      // dépose en partant (avantDeQuitter) : on dépose d'abord, puis on regarde de nouveau.
      await avantDeQuitter();
      toutEnvoyer();
      await viderFileProgression();
      depose = true;
      info = await etapeSuivante();
      if (!info) return false;
    }
    if (info.fin || info.portail) return retour();
    if (info.raison) return false;
    if (!depose) await avantDeQuitter();
    toutEnvoyer();
    await viderFileProgression();
    var e = info.etape;
    var cible = lien(e.url, { activite: e.activite || undefined, parcours: info.parcours.id, etape: info.numero });
    var sansHash = function (u) { return u.replace(/#.*$/, ''); };
    var memePage = sansHash(new URL(cible, location.href).href) === sansHash(location.href);
    location.href = cible;
    if (memePage) location.reload();   // même application : le changement de # ne recharge pas la page
    return true;
  }

  /* ---------- Revenir en arrière : l'étape précédente du parcours (n'importe quelle application), sinon le portail ---------- */

  /**
   * Où mène le bouton « ← » du widget de l'élève (barre du haut de chaque application) :
   *   { parcours: true, numero, etape, parcoursId, libelle, court, titre } — l'étape précédente du parcours ouvert ;
   *   { parcours: false, libelle, court, titre } — le portail (« Mes ateliers ») ;
   *   null — application ouverte hors de la Centrale (pas d'adresse de retour) : pas de bouton.
   */
  async function precedent() {
    var c = contexteAtelier();
    if (c && (c.etape || 0) > 0 && connecte()) {
      try {
        var d = await etape();
        var e = d && d.etapes[d.numero - 1];
        // réglage de l'enseignant (parcours ou étape) : « ← » ramène à la Centrale plutôt qu'à l'étape précédente
        if (d && d.etape && d.etape.avant === 'portail') e = null;
        if (e && e.url) {
          return { parcours: true, numero: d.numero - 1, etape: e, parcoursId: d.parcours.id,
                   libelle: 'Étape ' + d.numero + ' : ' + e.titre, court: 'Étape précédente',
                   titre: 'Revenir à l’étape ' + d.numero + ' du parcours : ' + e.titre + ' (ton travail est enregistré)' };
        }
      } catch (err) { /* hors ligne : le portail */ }
    }
    if (!adresseRetour()) return null;
    return { parcours: false, libelle: 'Mes ateliers', court: 'Mes ateliers', titre: 'Revenir à tous tes ateliers (ton travail est enregistré)' };
  }

  /** Va à l'étape précédente (après avoir tout enregistré), ou au portail. false s'il n'y a nulle part où revenir. */
  async function allerPrecedent() {
    var p = await precedent();
    if (!p) return false;
    if (!p.parcours) return retour();
    await avantDeQuitter();
    toutEnvoyer();
    await viderFileProgression();
    var e = p.etape;
    var cible = lien(e.url, { activite: e.activite || undefined, parcours: p.parcoursId, etape: p.numero });
    var sansHash = function (u) { return u.replace(/#.*$/, ''); };
    var memePage = sansHash(new URL(cible, location.href).href) === sansHash(location.href);
    location.href = cible;
    if (memePage) location.reload();   // même application : le changement de # ne recharge pas la page
    return true;
  }

  /**
   * Bouton « ← » prêt à poser dans le widget de l'élève (barre du haut) : « ← Étape précédente » dans un parcours,
   * « ← Mes ateliers » sinon, caché hors de la Centrale. Se met à jour tout seul (connexion, retour sur l'onglet).
   *   options.classe : classe CSS de l'application (défaut « centrale-precedent ») ; options.texte : false pour la flèche seule.
   * Renvoie { bouton, maj }. L'application le place où elle veut (en général avant le nom de l'élève).
   */
  function boutonPrecedent(options) {
    options = options || {};
    var b = document.createElement('button');
    b.type = 'button';
    b.className = options.classe || 'centrale-precedent';
    b.hidden = true;
    b.innerHTML = '<span aria-hidden="true">←</span>' + (options.texte === false ? '' : '<span class="centrale-precedent-txt"></span>');
    b.addEventListener('click', async function (ev) {
      ev.stopPropagation();
      b.disabled = true;
      var parti = await allerPrecedent().catch(function () { return false; });
      if (!parti) b.disabled = false;
    });
    var enCours = 0;
    async function maj() {
      var n = ++enCours, p = await precedent().catch(function () { return null; });
      if (n !== enCours) return;
      b.hidden = !p;
      if (!p) return;
      var t = b.querySelector('.centrale-precedent-txt');
      if (t) t.textContent = p.court;
      b.title = p.titre;
      b.setAttribute('aria-label', p.parcours ? p.libelle : 'Mes ateliers');
      b.dataset.cible = p.parcours ? 'etape' : 'portail';
    }
    ecouteursEtat.push(function () { maj(); });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') maj(); });
    maj();
    return { bouton: b, maj: maj };
  }

  /**
   * Étape suivante verrouillée seulement parce que l'étape en cours n'est pas finie (mode « dans l'ordre »),
   * alors que l'application dépose ses fichiers en partant (avantDeQuitter) et que l'étape attend des fichiers :
   * le clic peut la débloquer. (Mode « après validation » : seul le professeur débloque.)
   */
  function peutSeDebloquerEnPartant(info) {
    return !!(info && info.raison && info.etape && info.etape.url && info.parcours.deblocage === 'ordre'
      && typeof config.avantDeQuitter === 'function' && info.actuelle && (info.actuelle.sorties || []).length);
  }

  function demarrerBoutonSuivant() {
    majBoutonSuivant();
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') majBoutonSuivant(); });
    setInterval(function () { if (document.visibilityState === 'visible') majBoutonSuivant(); }, 60000);
  }

  /** Recalcule le bouton « Étape suivante » (après une progression, un dépôt, une connexion…). */
  function majBoutonSuivant(delai) {
    if (config.boutonSuivant === false || !contexteAtelier()) return;
    clearTimeout(suivant.minuteur);
    suivant.minuteur = setTimeout(async function () {
      if (!connecte()) { suivant.minuteur = setTimeout(function () { majBoutonSuivant(); }, 3000); return; }
      try { suivant.info = await etapeSuivante(); } catch (e) { return; }
      dessinerBoutonSuivant();
    }, delai == null ? 300 : delai);
  }

  function dessinerBoutonSuivant() {
    var info = suivant.info;
    var b = suivant.bouton;
    if (!info) { if (b) b.remove(); suivant.bouton = null; return; }
    if (!b) {
      b = suivant.bouton = document.createElement('button');
      b.id = 'centrale-suivant';
      b.type = 'button';
      b.onclick = async function () {
        var i = suivant.info;
        if (i && i.raison && !peutSeDebloquerEnPartant(i)) { montrerMessageSuivant(i.raison); return; }
        b.disabled = true;
        b.textContent = 'Enregistrement…';
        var parti = await allerEtapeSuivante().catch(function () { return false; });
        if (!parti) {
          b.disabled = false;
          try { suivant.info = await etapeSuivante(); } catch (e) { /* rien */ }
          dessinerBoutonSuivant();
          if (suivant.info && suivant.info.raison) montrerMessageSuivant(suivant.info.raison);
        }
      };
      document.body.appendChild(b);
    }
    var r = document.getElementById('centrale-retour');
    var gauche = r ? Math.round(r.getBoundingClientRect().right) + 8 : 12;
    // Verrou affiché seulement si le clic ne peut pas le lever (dépôt des fichiers en partant)
    var verrou = !!info.raison && !peutSeDebloquerEnPartant(info);
    b.textContent = info.fin ? 'Parcours terminé → Mes ateliers'
      : info.portail ? 'Mes ateliers →'
      : (verrou ? '🔒 ' : '') + 'Étape ' + (info.numero + 1) + ' : ' + info.etape.titre + (verrou ? '' : ' →');
    b.title = info.fin ? 'Dernière étape : revenir à la liste de tes ateliers'
      : info.portail ? 'Enregistrer et revenir à tes ateliers : la suite du parcours (« ' + info.etape.titre + ' ») y est'
      : verrou ? info.raison : 'Enregistrer et ouvrir l’étape suivante du parcours';
    b.style.cssText = 'position:fixed;bottom:12px;left:' + gauche + 'px;z-index:2147483000;'
      + 'max-width:min(420px,calc(100vw - ' + (gauche + 12) + 'px));overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
      + 'font:600 14px/1 system-ui,-apple-system,"Segoe UI",sans-serif;padding:10px 14px;border-radius:999px;cursor:pointer;'
      + 'box-shadow:0 4px 14px rgba(0,0,0,.2);border:1px solid ' + (verrou ? 'rgba(0,0,0,.18)' : '#1a7f4b') + ';'
      + 'background:' + (verrou ? '#eceae4' : '#1f8a52') + ';color:' + (verrou ? '#4a4d55' : '#fff');
  }

  function montrerMessageSuivant(texte) {
    var b = suivant.bouton;
    if (!b) return;
    var avant = b.textContent;
    b.textContent = texte;
    clearTimeout(suivant.message);
    suivant.message = setTimeout(function () { dessinerBoutonSuivant(); }, 3500);
    return avant;
  }

  /** Activité demandée par le portail (ex. "e5-1"), à ouvrir directement. Ne sert qu'une fois. */
  function activiteDemandee() {
    var a = activiteTransmise;
    activiteTransmise = null;
    return a;
  }

  /**
   * Lit ce que le portail (ou une autre application) a passé dans l'adresse, puis l'en retire :
   *   #centrale=<jeton>&retour=<adresse du portail>&activite=<activité à ouvrir>
   */
  function lireJetonTransmis() {
    var h = location.hash.replace(/^#/, '');
    if (!/(^|&)(centrale|retour|activite|parcours|etape)=/.test(h)) return;
    var atelier = {};
    var reste = [];
    h.split('&').forEach(function (morceau) {
      var v = function (n) { return decodeURIComponent(morceau.slice(n)); };
      if (morceau.indexOf('centrale=') === 0) jetonTransmis = v(9);
      else if (morceau.indexOf('retour=') === 0) { try { sessionStorage.setItem(CLE_RETOUR, v(7)); } catch (e) { /* rien */ } }
      else if (morceau.indexOf('activite=') === 0) activiteTransmise = v(9);
      else if (morceau.indexOf('parcours=') === 0) atelier.parcours = parseInt(v(9), 10);
      else if (morceau.indexOf('etape=') === 0) atelier.etape = parseInt(v(6), 10);
      else if (morceau) reste.push(morceau);
    });
    try {
      if (atelier.parcours) sessionStorage.setItem(CLE_ATELIER, JSON.stringify({ parcours: atelier.parcours, etape: atelier.etape || 0 }));
      else if (jetonTransmis) sessionStorage.removeItem(CLE_ATELIER);   // ouvert hors atelier : on oublie l'atelier précédent
    } catch (e) { /* rien */ }
    try {
      history.replaceState(null, '', location.pathname + location.search + (reste.length ? '#' + reste.join('&') : ''));
    } catch (e) { /* rien */ }
  }

  /** Reprend une session à partir d'un jeton (passage d'une application à l'autre). */
  async function reprendreSession(jeton) {
    var reponse, donnees = {};
    try {
      reponse = await fetch(url('session.php', { app: config.app }), { headers: { 'X-Jeton': jeton } });
      donnees = await reponse.json();
    } catch (e) {
      throw new Error('Serveur injoignable. Vérifie la connexion internet.');
    }
    if (!reponse.ok || !donnees.ok) throw new Error(donnees.erreur || 'Session expirée : retape ton code.');
    enregistrerSession(donnees);
    apresConnexion();
    return donnees;
  }

  /** Après chaque connexion : catalogue des activités et progression restée en attente. */
  function apresConnexion() {
    var s = session();
    if (!s) return;
    if (config.activites && !s['catalogue:' + config.app]) {
      declarerActivites(config.activites).then(function () {
        var s2 = session();
        if (s2) { s2['catalogue:' + config.app] = true; enregistrerSession(s2); }
      }).catch(function () { /* on réessaiera à la prochaine connexion */ });
    }
    viderFileProgression();
  }

  function url(fichier, params) {
    if (!config.api) throw new Error('Centrale.init({ api: "…" }) doit être appelé avant.');
    var u = new URL(config.api + fichier, location.href);
    Object.keys(params || {}).forEach(function (k) { u.searchParams.set(k, params[k]); });
    return u.toString();
  }

  function session() {
    try {
      var brut = sessionStorage.getItem(CLE_SESSION);
      if (brut) return JSON.parse(brut);
    } catch (e) { /* stockage bloqué */ }
    return memoire;
  }

  function enregistrerSession(s) {
    memoire = s;
    try {
      if (s) sessionStorage.setItem(CLE_SESSION, JSON.stringify(s));
      else sessionStorage.removeItem(CLE_SESSION);
    } catch (e) { /* stockage bloqué */ }
  }

  function connecte() {
    var s = session();
    return !!(s && s.jeton && (!s.expire || s.expire * 1000 > Date.now()));
  }

  /** Envoie le code au serveur. Renvoie { classe, eleve, app, espace, depot }. */
  async function connexion(code) {
    var reponse, donnees = {};
    try {
      reponse = await fetch(url('login.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: String(code || '').trim(), app: config.app }),
      });
    } catch (e) {
      throw new Error('Serveur injoignable. Vérifie la connexion internet.');
    }
    try { donnees = await reponse.json(); } catch (e) { /* réponse non JSON */ }
    if (!reponse.ok || !donnees.ok) {
      throw new Error(donnees.erreur || ('Erreur du serveur (' + reponse.status + ').'));
    }
    enregistrerSession(donnees);
    apresConnexion();
    return donnees;
  }

  /**
   * Adresse d'une autre application, avec le jeton pour que l'élève n'ait pas à retaper son code.
   * options : { activite: "e5-1" (à ouvrir directement), retour: adresse du bouton « ← Mes ateliers » }
   */
  function lien(adresse, options) {
    options = options || {};
    var s = session();
    var u = new URL(adresse, location.href);
    var h = u.hash.replace(/^#/, '');
    var morceaux = [];
    if (s && s.jeton) morceaux.push('centrale=' + encodeURIComponent(s.jeton));
    var r = options.retour || adresseRetour();
    if (r) morceaux.push('retour=' + encodeURIComponent(r));
    if (options.activite) morceaux.push('activite=' + encodeURIComponent(options.activite));
    if (options.parcours) morceaux.push('parcours=' + encodeURIComponent(options.parcours), 'etape=' + encodeURIComponent(options.etape || 0));
    if (h) morceaux.push(h);
    u.hash = morceaux.join('&');
    return u.toString();
  }

  /** Ouvre une autre application (même onglet par défaut) en gardant la connexion. */
  function ouvrir(adresse, options) {
    if (options === true) options = { nouvelOnglet: true };
    options = options || {};
    toutEnvoyer();
    var cible = lien(adresse, options);
    if (options.nouvelOnglet) window.open(cible, '_blank', 'noopener');
    else location.href = cible;
  }

  function deconnexion() {
    var s = session();
    envoiDeSortie(); // envoie d'abord ce qui n'est pas encore enregistré
    Object.keys(attentes).forEach(function (cle) { clearTimeout(attentes[cle].minuteur); });
    attentes = {};
    if (s && s.jeton) {
      try {
        fetch(url('deconnexion.php'), { method: 'POST', headers: { 'X-Jeton': s.jeton }, keepalive: true });
      } catch (e) { /* rien */ }
    }
    enregistrerSession(null);
    try { sessionStorage.removeItem(CLE_ATELIER); } catch (e) { /* rien */ }
  }

  /* ======================================================================
     Requêtes authentifiées
     ====================================================================== */

  function ErreurCentrale(message, statut, horsLigne) {
    var e = new Error(message);
    e.statut = statut || 0;
    e.horsLigne = !!horsLigne;
    return e;
  }

  async function requete(fichier, options, params) {
    var s = session();
    if (!s || !s.jeton) throw ErreurCentrale('Non connecté.', 401);
    options = Object.assign({}, options || {});
    options.headers = Object.assign({ 'X-Jeton': s.jeton }, options.headers || {});
    var reponse;
    try {
      reponse = await fetch(url(fichier, Object.assign({ app: config.app }, params || {})), options);
    } catch (e) {
      throw ErreurCentrale('Pas de connexion au serveur.', 0, true);
    }
    if (reponse.status === 401) {
      enregistrerSession(null);
      if (typeof config.surDeconnexion === 'function') config.surDeconnexion();
      throw ErreurCentrale('Session expirée : retape ton code.', 401);
    }
    return reponse;
  }

  async function requeteJSON(fichier, options, params) {
    var reponse = await requete(fichier, options, params);
    var donnees = {};
    try { donnees = await reponse.json(); } catch (e) { /* rien */ }
    if (!reponse.ok || donnees.ok === false) {
      throw ErreurCentrale(donnees.erreur || ('Erreur du serveur (' + reponse.status + ').'), reponse.status);
    }
    if (donnees.espace) majEspace(donnees.espace);
    return donnees;
  }

  function majEspace(espace) {
    var s = session();
    if (s) { s.espace = espace; enregistrerSession(s); }
  }

  /* ======================================================================
     État de la sauvegarde (pour afficher « Enregistré ✓ »)
     ====================================================================== */

  function changerEtat(etat, message) {
    etatCourant = { etat: etat, message: message || '', heure: new Date() };
    ecouteursEtat.forEach(function (f) { try { f(etatCourant); } catch (e) { /* rien */ } });
  }

  /** Affiche un petit indicateur de sauvegarde dans `element`. */
  function afficherEtat(element) {
    var maj = function (e) {
      var h = e.heure ? e.heure.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '';
      var textes = {
        pret: '',
        modifie: 'Modifications non enregistrées…',
        enregistrement: 'Enregistrement…',
        enregistre: '✓ Enregistré à ' + h,
        'hors-ligne': '⚠ Hors ligne : ton travail est gardé ici et sera envoyé au retour du réseau',
        erreur: '⚠ ' + e.message,
        conflit: '⚠ ' + e.message,
      };
      element.textContent = textes[e.etat] != null ? textes[e.etat] : e.message;
      element.dataset.etat = e.etat;
    };
    ecouteursEtat.push(maj);
    maj(etatCourant);
  }

  /* ======================================================================
     Copie de secours locale (travail non encore envoyé)
     ====================================================================== */

  function cleSecours(cle) {
    var s = session() || {};
    return PREFIXE_SECOURS + [s.classe, s.eleve, config.app, cle].join(':');
  }

  function lireSecours(cle) {
    try { return JSON.parse(localStorage.getItem(cleSecours(cle))); } catch (e) { return null; }
  }

  function ecrireSecours(cle, valeur) {
    try {
      localStorage.setItem(cleSecours(cle), JSON.stringify({ valeur: valeur, t: Date.now(), base: bases[cle] || 0 }));
      return true;
    } catch (e) { return false; } // stockage plein ou bloqué : on compte sur le serveur
  }

  function effacerSecours(cle, t) {
    try {
      var s = lireSecours(cle);
      if (s && (!t || s.t === t)) localStorage.removeItem(cleSecours(cle));
    } catch (e) { /* rien */ }
  }

  /** Renvoie au serveur le travail resté dans le navigateur (coupure réseau, onglet fermé…). */
  function envoyerSecoursEnAttente() {
    var s = session();
    if (!s) return;
    var debut = PREFIXE_SECOURS + [s.classe, s.eleve, config.app].join(':') + ':';
    try {
      for (var i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i);
        var cle = k && k.indexOf(debut) === 0 ? k.slice(debut.length) : null;
        // Seulement les données déjà chargées dans cette session (charger() a vérifié qu'elles sont à jour)
        if (cle !== null && cle in bases && !attentes[cle]) planifier(cle, 0);
      }
    } catch (e) { /* rien */ }
  }

  /* ======================================================================
     Données (JSON) : charger / sauver
     ====================================================================== */

  /**
   * Charge une donnée sauvegardée. Renvoie `defaut` si rien n'a encore été enregistré.
   * Si une modification n'avait pas pu être envoyée (réseau), c'est elle qui est reprise.
   */
  async function charger(cle, defaut) {
    var local = lireSecours(cle);
    try {
      var d = await requeteJSON('donnees.php', { method: 'GET' }, { cle: cle });
      bases[cle] = d.maj || 0;
      // Copie locale non envoyée : on la reprend, sauf si le travail a été modifié
      // depuis sur un autre poste (le serveur a alors une version plus récente).
      if (local && (!d.existe || (local.base || 0) >= (d.maj || 0))) {
        planifier(cle, 0);
        return local.valeur;
      }
      if (local) effacerSecours(cle);
      return d.existe ? d.valeur : (defaut === undefined ? null : defaut);
    } catch (e) {
      if (local) return local.valeur;
      if (e.horsLigne) changerEtat('hors-ligne');
      if (e.horsLigne && defaut !== undefined) return defaut;
      throw e;
    }
  }

  /**
   * Sauvegarde une donnée (objet, tableau, texte, nombre…). Appelez-la à chaque modification :
   * les envois sont regroupés. Renvoie une promesse résolue quand c'est enregistré sur le serveur.
   * Options : { immediat: true } pour envoyer sans attendre.
   */
  function sauver(cle, valeur, options) {
    ecrireSecours(cle, valeur);
    changerEtat('modifie');
    return new Promise(function (resoudre, rejeter) {
      var a = attentes[cle] || (attentes[cle] = { minuteur: null, promesses: [] });
      a.promesses.push({ ok: resoudre, ko: rejeter });
      planifier(cle, options && options.immediat ? 0 : DELAI_SAUVEGARDE);
    });
  }

  function planifier(cle, delai) {
    var a = attentes[cle] || (attentes[cle] = { minuteur: null, promesses: [] });
    clearTimeout(a.minuteur);
    a.minuteur = setTimeout(function () { envoyer(cle); }, delai);
  }

  /** Corps d'une sauvegarde. "base" permet au serveur de refuser d'écraser un travail plus récent. */
  function corpsSauvegarde(cle, local) {
    var corps = { app: config.app, cle: cle, valeur: local.valeur };
    if (cle in bases) corps.base = local.base || 0;
    return JSON.stringify(corps);
  }

  async function envoyer(cle) {
    var a = attentes[cle];
    var local = lireSecours(cle);
    if (!a || !local) { if (a) terminer(cle, null); return; }
    a.minuteur = null;
    changerEtat('enregistrement');
    try {
      var r = await requeteJSON('donnees.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: corpsSauvegarde(cle, local),
      });
      bases[cle] = r.maj;
      effacerSecours(cle, local.t);
      var encore = lireSecours(cle); // modifié pendant l'envoi ?
      if (encore) { encore.base = r.maj; try { localStorage.setItem(cleSecours(cle), JSON.stringify(encore)); } catch (e) { /* rien */ } }
      if (encore) { planifier(cle, DELAI_SAUVEGARDE); return; }
      changerEtat('enregistre');
      terminer(cle, null, r);
    } catch (e) {
      if (e.horsLigne) {
        changerEtat('hors-ligne');
        planifier(cle, DELAI_REESSAI);
      } else if (e.statut === 409) {
        changerEtat('conflit', e.message);
        terminer(cle, e);
      } else if (e.statut === 401) {
        changerEtat('erreur', 'Session expirée : retape ton code (ton travail est gardé ici).');
        clearTimeout(a.minuteur);
        terminer(cle, e);
      } else {
        changerEtat('erreur', e.message);
        terminer(cle, e);
      }
    }
  }

  function terminer(cle, erreur, resultat) {
    var a = attentes[cle];
    if (!a) return;
    delete attentes[cle];
    a.promesses.forEach(function (p) { erreur ? p.ko(erreur) : p.ok(resultat); });
  }

  /** Envoie tout de suite les sauvegardes en attente. */
  function toutEnvoyer() {
    Object.keys(attentes).forEach(function (cle) { if (attentes[cle].minuteur) planifier(cle, 0); });
  }

  /** Envoi de la dernière chance quand l'élève ferme l'onglet. */
  function envoiDeSortie() {
    var s = session();
    if (!s || !s.jeton) return;
    Object.keys(attentes).forEach(function (cle) {
      var local = lireSecours(cle);
      if (!local) return;
      var corps = corpsSauvegarde(cle, local);
      if (corps.length > 60000) return; // trop gros pour keepalive : la copie locale sera renvoyée au retour
      try {
        fetch(url('donnees.php', { app: config.app }), {
          method: 'POST', keepalive: true, body: corps,
          headers: { 'Content-Type': 'application/json', 'X-Jeton': s.jeton },
        });
      } catch (e) { /* rien */ }
    });
  }

  async function supprimer(cle) {
    effacerSecours(cle);
    await requeteJSON('donnees.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ app: config.app, cle: cle, supprimer: true }),
    });
  }

  async function listeDonnees() {
    return (await requeteJSON('donnees.php', { method: 'GET' })).cles;
  }

  function sauvegardesEnCours() {
    return Object.keys(attentes).length > 0;
  }

  /* ======================================================================
     Fichiers de l'élève et ressources de l'atelier
     ====================================================================== */

  /** Envoie un fichier (File, Blob ou texte). Remplace le fichier du même nom. */
  async function envoyerFichier(fichier, nom) {
    if (!(fichier instanceof Blob)) fichier = new Blob([fichier], { type: 'text/plain;charset=utf-8' });
    nom = nom || fichier.name || nomFichier('txt');
    var form = new FormData();
    form.append('app', config.app);
    form.append('nom', nom);
    form.append('fichier', fichier, nom);
    changerEtat('enregistrement');
    try {
      var r = await requeteJSON('fichiers.php', { method: 'POST', body: form });
      changerEtat('enregistre');
      return r.fichier;
    } catch (e) {
      changerEtat(e.horsLigne ? 'hors-ligne' : 'erreur', e.message);
      throw e;
    }
  }

  async function listeFichiers() {
    return (await requeteJSON('fichiers.php', { method: 'GET' })).fichiers;
  }

  /** Récupère un fichier de l'élève (Blob). Pour du texte : await (await Centrale.chargerFichier(nom)).text() */
  async function chargerFichier(nom) {
    var r = await requete('fichiers.php', { method: 'GET' }, { nom: nom });
    if (!r.ok) throw ErreurCentrale(r.status === 404 ? 'Fichier introuvable.' : 'Erreur du serveur.', r.status);
    return r.blob();
  }

  async function supprimerFichier(nom) {
    var form = new FormData();
    form.append('app', config.app);
    form.append('nom', nom);
    form.append('supprimer', '1');
    await requeteJSON('fichiers.php', { method: 'POST', body: form });
  }

  async function listeRessources() {
    return (await requeteJSON('ressources.php', { method: 'GET' })).ressources;
  }

  /** Récupère une ressource déposée par le professeur pour cet atelier (Blob). */
  async function chargerRessource(nom) {
    var r = await requete('ressources.php', { method: 'GET' }, { nom: nom });
    if (!r.ok) throw ErreurCentrale(r.status === 404 ? 'Ressource introuvable.' : 'Erreur du serveur.', r.status);
    return r.blob();
  }

  /* ======================================================================
     Progression : avancement dans les activités (suivi enseignant)
     ====================================================================== */

  function cleFile() {
    var s = session() || {};
    return PREFIXE_FILE + [s.classe, s.eleve, config.app].join(':');
  }

  function lireFile() {
    try { return JSON.parse(localStorage.getItem(cleFile())) || {}; } catch (e) { return {}; }
  }

  function ecrireFile(f) {
    try {
      if (Object.keys(f).length) localStorage.setItem(cleFile(), JSON.stringify(f));
      else localStorage.removeItem(cleFile());
    } catch (e) { /* rien */ }
  }

  /** Fusionne deux envois pour la même activité en gardant le meilleur (comme le serveur). */
  function fusionner(a, b) {
    if (!a) return b;
    var m = Object.assign({}, a, b);
    ['faites', 'score'].forEach(function (k) {
      if (a[k] != null && b[k] != null) m[k] = Math.max(a[k], b[k]);
    });
    if (a.statut === 'termine') m.statut = 'termine';
    // Constats de compétences : on les garde tous (chacun compte dans le bilan), 50 au plus en attente
    if (a.constats || b.constats) m.constats = (a.constats || []).concat(b.constats || []).slice(-50);
    return m;
  }

  /**
   * Signale l'avancement de l'élève dans une activité.
   *   Centrale.progression("relier-salle", { titre: "Brancher la salle", faites: 3, total: 5 })
   *   Centrale.progression("relier-salle", { statut: "termine" })
   *   Centrale.progression("quiz-1", { statut: "termine", score: 8, score_max: 10, details: { reponses } })
   * Hors ligne, l'envoi est mis en file et repart au retour du réseau.
   */
  function progression(activite, infos) {
    var envoi = Object.assign({ statut: 'commence' }, infos || {}, { activite: activite });
    var file = lireFile();
    file[activite] = fusionner(file[activite], envoi);
    ecrireFile(file);
    return viderFileProgression();
  }

  var envoiFileEnCours = null;
  function viderFileProgression() {
    if (envoiFileEnCours) return envoiFileEnCours.then(viderFileProgression);
    if (!connecte()) return Promise.resolve();
    var file = lireFile();
    var ids = Object.keys(file);
    if (!ids.length) return Promise.resolve();
    envoiFileEnCours = (async function () {
      for (var i = 0; i < ids.length; i++) {
        var envoye = file[ids[i]];
        try {
          await requeteJSON('progression.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ app: config.app }, envoye)),
          });
          var actuelle = lireFile();
          if (JSON.stringify(actuelle[ids[i]]) === JSON.stringify(envoye)) delete actuelle[ids[i]];
          else if (actuelle[ids[i]] && envoye.constats && actuelle[ids[i]].constats) {
            // Arrivés pendant l'envoi : on ne garde que les constats pas encore envoyés
            actuelle[ids[i]].constats = actuelle[ids[i]].constats.slice(envoye.constats.length);
            if (!actuelle[ids[i]].constats.length) delete actuelle[ids[i]].constats;
          }
          ecrireFile(actuelle);
        } catch (e) {
          if (e.horsLigne || e.statut === 401) { setTimeout(viderFileProgression, DELAI_REESSAI); break; }
          var f2 = lireFile(); delete f2[ids[i]]; ecrireFile(f2); // donnée refusée : on l'abandonne
        }
      }
    })().finally(function () { envoiFileEnCours = null; majBoutonSuivant(800); });
    return envoiFileEnCours;
  }

  /** Avancement de l'élève dans cet atelier : { activite: { statut, faites, total, score… } } */
  async function mesProgressions() {
    var liste = (await requeteJSON('progression.php', { method: 'GET' })).progression;
    var res = {};
    liste.forEach(function (p) { res[p.activite] = p; });
    var file = lireFile(); // ce qui n'est pas encore parti compte aussi
    Object.keys(file).forEach(function (k) { res[k] = fusionner(res[k], file[k]); });
    return res;
  }

  /**
   * Déclare la liste des activités de l'atelier (dans l'ordre), pour le tableau de suivi :
   *   Centrale.declarerActivites([{ id: "relier-salle", titre: "Brancher la salle", groupe: "5e", total: 5 }, …])
   * Le plus simple : passer cette liste à Centrale.init({ activites: [...] }).
   */
  async function declarerActivites(liste) {
    return requeteJSON('activites.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ app: config.app, activites: liste }),
    });
  }

  /** Avancement de l'élève dans TOUS les ateliers : { app: [ … ] } */
  async function toutesMesProgressions() {
    return (await requeteJSON('progression.php', { method: 'GET' }, { app: '*' })).progression;
  }

  /** Ateliers proposés à la classe, avec l'avancement de l'élève et la liste des activités. */
  async function ateliers() {
    return (await requeteJSON('ateliers.php', { method: 'GET' })).ateliers;
  }

  /** Activités proposées par le professeur à l'élève (classe ou groupe), avec son avancement. */
  async function propositions() {
    return (await requeteJSON('propositions.php', { method: 'GET' })).propositions;
  }

  /** Parcours de la classe avec l'avancement de l'élève à chaque étape. */
  async function parcours() {
    return (await requeteJSON('parcours.php', { method: 'GET' })).parcours;
  }

  /* ======================================================================
     Outils : nom de fichier, téléchargement, dépôt Nuage
     ====================================================================== */

  function propre(texte) {
    return String(texte || '')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^A-Za-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  /** Nom de fichier standard : 5A_07_robot-mbot_2026-10-30_suffixe.ext */
  function nomFichier(extension, suffixe) {
    var s = session() || {};
    var d = new Date();
    var jour = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    var morceaux = [propre(s.classe) || 'classe', propre(s.eleve) || 'eleve'];
    if (config.app && config.app !== '*') morceaux.push(propre(config.app));
    morceaux.push(jour);
    if (suffixe) morceaux.push(propre(suffixe));
    return morceaux.join('_') + '.' + String(extension || 'txt').replace(/^\./, '');
  }

  /** Fait enregistrer un fichier sur l'ordinateur de l'élève. */
  function telecharger(contenu, nom, type) {
    var blob = contenu instanceof Blob ? contenu : new Blob([contenu], { type: type || 'text/plain;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = nom || nomFichier('txt');
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
  }

  /** Ouvre le dossier de dépôt Nuage de la classe (s'il a été configuré). */
  function ouvrirDepot() {
    var s = session();
    if (!s || !s.depot || !s.depot.url) {
      throw new Error("Aucun dossier de dépôt Nuage n'est configuré pour ta classe.");
    }
    window.open(s.depot.url, '_blank', 'noopener');
  }

  /* ======================================================================
     Formulaire de connexion prêt à l'emploi
     ====================================================================== */

  var STYLE = [
    '.centrale-form{display:flex;flex-direction:column;gap:10px;max-width:420px}',
    '.centrale-form label{font-weight:600}',
    '.centrale-form input{font:500 1.05rem ui-monospace,Menlo,Consolas,monospace;padding:10px 12px;border:1px solid #b9b6ae;border-radius:8px;width:100%;box-sizing:border-box;background:inherit;color:inherit}',
    '.centrale-form button{font:inherit;font-weight:600;padding:10px 16px;border:0;border-radius:8px;background:#1f5f8b;color:#fff;cursor:pointer}',
    '.centrale-form button[disabled]{opacity:.6;cursor:wait}',
    '.centrale-erreur{color:#b3261e;min-height:1.4em;margin:0}',
    '.centrale-identite{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px}',
    '.centrale-identite code{font:600 1rem ui-monospace,Menlo,Consolas,monospace;padding:2px 8px;border-radius:6px;background:rgba(127,127,127,.14)}',
    '.centrale-identite button{font:inherit;font-size:.88rem;font-weight:600;padding:3px 10px;border-radius:6px;border:1px solid #1f5f8b;background:transparent;color:inherit;cursor:pointer}',
    '.centrale-identite form{display:flex;flex-wrap:wrap;align-items:center;gap:6px;flex-basis:100%}',
    '.centrale-identite input{font:500 1rem ui-monospace,Menlo,Consolas,monospace;padding:5px 8px;border:1px solid #b9b6ae;border-radius:6px;width:12em;background:inherit;color:inherit}',
    '.centrale-identite .centrale-aide{flex-basis:100%;font-size:.85rem;opacity:.75;margin:0}',
  ].join('');

  function ajouterStyle() {
    if (document.getElementById('centrale-style')) return;
    var st = document.createElement('style');
    st.id = 'centrale-style';
    st.textContent = STYLE;
    document.head.appendChild(st);
  }

  /**
   * Affiche un formulaire « Mon code » dans `element`.
   * Si l'élève est déjà connecté dans cet onglet, appelle directement onConnecte.
   */
  function afficherConnexion(element, options) {
    options = options || {};
    if (jetonTransmis) { // ouvert depuis le portail : on valide le jeton (et la Centrale note l'ouverture de l'atelier)
      var j = jetonTransmis;
      jetonTransmis = null;
      element.textContent = 'Connexion…';
      reprendreSession(j)
        .then(function (infos) { element.innerHTML = ''; if (options.onConnecte) options.onConnecte(infos); })
        .catch(function () { enregistrerSession(null); afficherConnexion(element, options); });
      return;
    }
    if (connecte()) {
      apresConnexion();
      if (options.onConnecte) options.onConnecte(session());
      return;
    }
    ajouterStyle();
    element.innerHTML = '';
    var form = document.createElement('form');
    form.className = 'centrale-form';
    form.innerHTML =
      '<label for="centrale-code">' + (options.titre || 'Mon code') + '</label>' +
      '<input id="centrale-code" name="code" autocomplete="off" autocapitalize="off" spellcheck="false" ' +
      'placeholder="ex. 4C-LHA-9943-kfmz-tqpr" required>' +
      '<button type="submit">Se connecter</button>' +
      '<p class="centrale-erreur" role="alert"></p>';
    element.appendChild(form);
    var champ = form.querySelector('input');
    var bouton = form.querySelector('button');
    var erreur = form.querySelector('.centrale-erreur');
    champ.focus();

    form.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      erreur.textContent = '';
      bouton.disabled = true;
      bouton.textContent = 'Connexion…';
      try {
        var infos = await connexion(champ.value);
        element.innerHTML = '';
        if (options.onConnecte) options.onConnecte(infos);
      } catch (e) {
        erreur.textContent = e.message;
        bouton.disabled = false;
        bouton.textContent = 'Se connecter';
        champ.select();
      }
    });
  }

  /* ======================================================================
     Ateliers transversaux : dossier de projet partagé par les applications d'un parcours
     ====================================================================== */

  var versionsProjet = {};   // nom -> version lue (pour ne pas écraser le travail d'un coéquipier)

  /** { parcours, etape } si l'application a été ouverte depuis une étape d'un atelier, sinon null. */
  function contexteAtelier() {
    try { return JSON.parse(sessionStorage.getItem(CLE_ATELIER)) || null; } catch (e) { return null; }
  }

  function parcoursCourant(parcours) {
    var c = contexteAtelier();
    var id = parcours || (c && c.parcours);
    if (!id) throw ErreurCentrale("Cette application n'a pas été ouverte depuis un atelier.", 0);
    return id;
  }

  /**
   * Étape en cours de l'atelier : { parcours, proprietaire, etapes, fichiers, numero, etape }.
   * etape = { titre, consigne, entrees: [noms], sorties: [noms], ouverte, statut… } ; null hors atelier.
   */
  async function etape() {
    var c = contexteAtelier();
    if (!c) return null;
    var d = await requeteJSON('projet.php', {}, { parcours: c.parcours });
    d.numero = c.etape || 0;
    d.etape = d.etapes[d.numero] || null;
    return d;
  }

  var projet = {
    /** Contenu du dossier de projet : { fichiers, etapes, proprietaire, parcours }. */
    infos: function (parcours) {
      return requeteJSON('projet.php', {}, { parcours: parcoursCourant(parcours) });
    },
    liste: async function (parcours) {
      return (await projet.infos(parcours)).fichiers;
    },
    /**
     * Lit un fichier du dossier (ou le fichier de départ fourni par l'enseignant).
     * format : 'auto' (JSON, texte ou Blob selon le type), 'json', 'texte' ou 'blob'. null s'il n'existe pas encore.
     */
    charger: async function (nom, options) {
      options = options || {};
      var reponse = await requete('projet.php', {}, { parcours: parcoursCourant(options.parcours), nom: nom });
      if (reponse.status === 404) return null;
      if (!reponse.ok) {
        var e = {};
        try { e = await reponse.json(); } catch (x) { /* rien */ }
        throw ErreurCentrale(e.erreur || ('Erreur du serveur (' + reponse.status + ').'), reponse.status);
      }
      // Version lue (en-tête exposé par la Centrale). Absente : pas de contrôle, plutôt qu'un faux conflit.
      var version = reponse.headers.get('X-Version');
      if (version !== null && version !== '') versionsProjet[nom] = parseInt(version, 10);
      else delete versionsProjet[nom];
      var type = (reponse.headers.get('Content-Type') || '').toLowerCase();
      var format = options.format || 'auto';
      if (format === 'json' || (format === 'auto' && (type.indexOf('json') >= 0 || /\.json$/i.test(nom)))) return reponse.json();
      if (format === 'texte' || (format === 'auto' && type.indexOf('text/') === 0)) return reponse.text();
      return reponse.blob();
    },
    /**
     * Dépose un fichier dans le dossier (objet → JSON, texte, Blob ou File).
     * En équipe : si un coéquipier l'a modifié depuis la lecture, une erreur « conflit » est levée
     * (e.conflit = true, e.auteur) ; recharger puis réessayer, ou passer { forcer: true }.
     */
    sauver: async function (nom, contenu, options) {
      options = options || {};
      var c = contexteAtelier();
      var blob = contenu instanceof Blob ? contenu
        : typeof contenu === 'string' ? new Blob([contenu], { type: options.type || 'text/plain' })
        : new Blob([JSON.stringify(contenu)], { type: 'application/json' });
      var f = new FormData();
      f.append('parcours', parcoursCourant(options.parcours));
      f.append('nom', nom);
      if (c && !options.parcours && options.etape !== null) f.append('etape', options.etape != null ? options.etape : c.etape);
      if (!options.forcer && versionsProjet[nom] !== undefined) f.append('version', versionsProjet[nom]);
      f.append('fichier', blob, nom);
      var reponse = await requete('projet.php', { method: 'POST', body: f });
      var d = {};
      try { d = await reponse.json(); } catch (x) { /* rien */ }
      if (!reponse.ok || !d.ok) {
        var err = ErreurCentrale(d.erreur || ('Erreur du serveur (' + reponse.status + ').'), reponse.status);
        if (d.conflit) { err.conflit = true; err.auteur = d.auteur; err.version = d.version; }
        throw err;
      }
      versionsProjet[nom] = d.fichier.version;
      majBoutonSuivant(800);   // une sortie déposée peut terminer l'étape
      return d.fichier;
    },
    supprimer: async function (nom, options) {
      options = options || {};
      var r = await requeteJSON('projet.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ parcours: parcoursCourant(options.parcours), nom: nom, supprimer: true }),
      });
      delete versionsProjet[nom];
      return r;
    },
  };

  /* ======================================================================
     Identifiant de l'élève : « 4C-LHA-LoutreAgile » (le surnom, à la fin, peut être changé)
     ====================================================================== */

  function identifiant() {
    var s = session();
    return s ? (s.identifiant || '') : '';
  }

  /**
   * Nom de l'élève tel que l'enseignant le connaît, en vue Enseignant d'un essai seulement (« Tester à la place d'un
   * élève ») : lu dans les noms que l'espace enseignant garde SUR CET ORDINATEUR (localStorage « centrale-noms », même
   * site que la Centrale). Jamais envoyé au serveur.
   * → « Léa D. » ; '' si l'élève n'est pas désanonymisé ici ; null hors vue Enseignant.
   */
  function nomEnseignant() {
    var s = session();
    if (!s || !s.essai || s.vue !== 'enseignant') return null;
    try {
      var noms = JSON.parse(localStorage.getItem('centrale-noms')) || {};
      return String(((noms[s.classe] || {})[s.eleve]) || '');
    } catch (e) { return ''; }
  }

  /** Ce que la barre du haut affiche pour l'élève : son identifiant ; en vue Enseignant d'un essai, son nom (ou « pas désanonymisé »). */
  function nomAffiche() {
    var n = nomEnseignant();
    if (n === null) return identifiant();
    return n || 'pas désanonymisé';
  }

  /** Change le surnom (fin de l'identifiant). Renvoie { identifiant, prefixe, surnom }. */
  async function changerSurnom(surnom) {
    var d = await requeteJSON('surnom.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ surnom: String(surnom || '').trim() }),
    });
    var s = session();
    if (s) {
      s.identifiant = d.identifiant; s.prefixe = d.prefixe; s.surnom = d.surnom; s.surnom_modifiable = d.surnom_modifiable;
      enregistrerSession(s);
    }
    return d;
  }

  /** Affiche « Ton identifiant : 4C-LHA-LoutreAgile » dans `element`, avec [Changer mon surnom] seulement si
   *  options.modifiable === true : le portail le demande, les applications non (le surnom se change sur le portail). */
  function afficherIdentifiant(element, options) {
    options = options || {};
    ajouterStyle();
    var dessiner = function () {
      var s = session();
      element.innerHTML = '';
      if (!s || !s.identifiant) return;
      element.className = (element.className.replace(/\bcentrale-identite\b/, '') + ' centrale-identite').trim();
      element.append(options.libelle != null ? options.libelle : 'Ton identifiant :');
      var code = document.createElement('code');
      code.textContent = s.identifiant;
      element.appendChild(code);
      if (!s.surnom_modifiable || options.modifiable !== true) return;
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = 'Changer mon surnom';
      element.appendChild(b);
      b.onclick = function () {
        b.remove();
        var f = document.createElement('form');
        f.innerHTML = '<span></span><input maxlength="20" autocomplete="off" spellcheck="false" aria-label="Nouveau surnom">'
          + '<button type="submit">Valider</button><button type="button">Annuler</button>'
          + '<p class="centrale-aide">3 à 20 lettres ou chiffres. Pas ton vrai nom, pas de gros mots : ton professeur voit les surnoms.</p>'
          + '<p class="centrale-erreur" role="alert"></p>';
        f.querySelector('span').textContent = s.prefixe + '-';
        var champ = f.querySelector('input');
        champ.value = s.surnom;
        f.querySelectorAll('button')[1].onclick = dessiner;
        f.onsubmit = async function (ev) {
          ev.preventDefault();
          try {
            await changerSurnom(champ.value);
            dessiner();
            if (options.onChange) options.onChange(identifiant());
          } catch (e) {
            f.querySelector('.centrale-erreur').textContent = e.message;
          }
        };
        element.appendChild(f);
        champ.focus(); champ.select();
      };
    };
    dessiner();
  }

  /* ---------- Menu de l'élève (clic sur son identifiant dans la barre du haut) ---------- */

  var STYLE_MENU = [
    '.centrale-menu-eleve{position:fixed;z-index:2147483000;width:min(290px,calc(100vw - 20px));box-sizing:border-box;padding:10px 12px;',
    'background:#fff;color:#1c2333;border:1px solid #d5dbe8;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.16);',
    'font:14px/1.35 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;text-align:left}',
    '.centrale-menu-eleve[hidden]{display:none}',
    '.centrale-menu-eleve .cme-titre{margin:0 0 4px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#2e8b57}',
    '.centrale-menu-eleve .cme-classe{margin:0 0 6px;font-size:.78rem;opacity:.7}',
    '.centrale-menu-eleve .centrale-identite{display:flex;flex-wrap:wrap;align-items:center;gap:4px 6px;font-size:.78rem;opacity:1}',
    '.centrale-menu-eleve .centrale-identite code{flex-basis:100%;box-sizing:border-box;font:700 .9rem ui-monospace,Menlo,Consolas,monospace;padding:3px 8px;border-radius:6px;',
    'background:rgba(127,127,127,.14);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.centrale-menu-eleve .centrale-identite>button{padding:0;border:0;background:none;color:#1f5f8b;font-size:.8rem;font-weight:600;text-decoration:underline;text-underline-offset:2px;cursor:pointer}',
    '.centrale-menu-eleve .centrale-identite form{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:5px;flex-basis:100%;margin-top:2px}',
    '.centrale-menu-eleve .centrale-identite form>span{font:600 .82rem ui-monospace,Menlo,Consolas,monospace;opacity:.7}',
    '.centrale-menu-eleve .centrale-identite form input{width:100%;min-width:0;box-sizing:border-box;font:600 .85rem ui-monospace,Menlo,Consolas,monospace;padding:4px 7px}',
    '.centrale-menu-eleve .centrale-identite form button{font-size:.78rem;padding:3px 10px;border-radius:999px;cursor:pointer}',
    '.centrale-menu-eleve .centrale-identite form button[type=submit]{grid-column:2;grid-row:2;justify-self:start;background:#1f5f8b;border:1px solid #1f5f8b;color:#fff}',
    '.centrale-menu-eleve .centrale-identite form button[type=button]{grid-column:2;grid-row:2;justify-self:end;border:0;background:none;color:inherit;opacity:.7;text-decoration:underline}',
    '.centrale-menu-eleve .centrale-identite .centrale-aide{grid-column:1/-1;font-size:.7rem;margin:0;opacity:.8}',
    '.centrale-menu-eleve .centrale-identite .centrale-erreur{grid-column:1/-1;font-size:.72rem;margin:0}',
    '.centrale-menu-eleve .centrale-identite .centrale-erreur:empty{display:none}',
    '.centrale-menu-eleve .cme-aide{margin:8px 0 0;padding-top:6px;border-top:1px solid rgba(127,127,127,.25);font-size:.7rem;opacity:.75}',
    '.centrale-menu-eleve .cme-actions{display:flex;flex-direction:column;gap:2px;margin-top:6px;padding-top:6px;border-top:1px solid rgba(127,127,127,.25)}',
    '.centrale-menu-eleve .cme-actions button{display:block;width:100%;text-align:left;padding:6px 8px;border:0;border-radius:6px;background:none;color:inherit;font:inherit;font-size:.86rem;cursor:pointer}',
    '.centrale-menu-eleve .cme-actions button:hover,.centrale-menu-eleve .cme-actions button:focus-visible{background:rgba(127,127,127,.14)}',
    '.centrale-menu-eleve .cme-actions .cme-sortir{color:#b3261e}',
    ':root[data-theme=dark] .centrale-menu-eleve,.centrale-menu-eleve.sombre{background:#2a2e37;color:#e8ebf2;border-color:#3a3f4b}',
    ':root[data-theme=dark] .centrale-menu-eleve .centrale-identite>button,.centrale-menu-eleve.sombre .centrale-identite>button{color:#8cb8e6}',
    '@media (prefers-color-scheme:dark){:root:not([data-theme=light]) .centrale-menu-eleve:not(.clair){background:#2a2e37;color:#e8ebf2;border-color:#3a3f4b}',
    ':root:not([data-theme=light]) .centrale-menu-eleve:not(.clair) .centrale-identite>button{color:#8cb8e6}}',
  ].join('');

  var menuOuvert = null;
  function fermerMenuEleve() {
    if (!menuOuvert) return;
    var m = menuOuvert; menuOuvert = null;
    m.el.remove();
    document.removeEventListener('pointerdown', m.dehors, true);
    document.removeEventListener('keydown', m.echap, true);
    global.removeEventListener('resize', m.placer);
    if (m.ancre.setAttribute) m.ancre.setAttribute('aria-expanded', 'false');
  }

  /**
   * Menu de l'élève connecté, ouvert sous `ancre` (son identifiant dans la barre du haut) ; un 2e appel le ferme.
   * Le même dans toutes les applications : « Connecté », classe et numéro, identifiant et « Changer mon surnom »,
   * une ligne d'aide, puis les liens propres à l'application et « Se déconnecter ».
   * options : { liens: [{ texte, action }], deconnexion: true, onDeconnexion(), onChange(identifiant), theme: 'clair'|'sombre' }
   * Sans connexion, ne fait rien et renvoie false (l'application propose alors sa connexion).
   */
  function menuEleve(ancre, options) {
    options = options || {};
    if (menuOuvert) { var meme = menuOuvert.ancre === ancre; fermerMenuEleve(); if (meme) return true; }
    var s = session();
    if (!s) return false;
    ajouterStyle();
    if (!document.getElementById('centrale-style-menu')) {
      var st = document.createElement('style');
      st.id = 'centrale-style-menu';
      st.textContent = STYLE_MENU;
      document.head.appendChild(st);
    }
    var m = document.createElement('div');
    m.className = 'centrale-menu-eleve' + (options.theme === 'sombre' ? ' sombre' : options.theme === 'clair' ? ' clair' : '');
    m.setAttribute('role', 'dialog');
    m.setAttribute('aria-label', 'Mon compte');
    var titre = document.createElement('p');
    titre.className = 'cme-titre';
    titre.textContent = 'Connecté';
    m.appendChild(titre);
    if (s.classe) {
      var cl = document.createElement('p');
      cl.className = 'cme-classe';
      cl.textContent = 'Classe ' + s.classe + (s.eleve ? ' · élève ' + s.eleve : '');
      m.appendChild(cl);
    }
    var id = document.createElement('div');
    m.appendChild(id);
    afficherIdentifiant(id, { libelle: 'Ton identifiant', onChange: options.onChange });
    var aide = document.createElement('p');
    aide.className = 'cme-aide';
    aide.textContent = 'Ton travail est enregistré automatiquement sur le serveur de la classe.';
    m.appendChild(aide);
    var liens = (options.liens || []).slice();
    // dans une application : un lien vers le portail de la Centrale (le surnom ne se change que là-bas)
    if (options.centrale !== false) liens.unshift({ texte: 'Aller sur la Centrale', action: allerPortail });
    if (options.deconnexion !== false) {
      liens.push({ texte: 'Se déconnecter', classe: 'cme-sortir', action: function () {
        deconnexion();
        if (options.onDeconnexion) options.onDeconnexion();
      } });
    }
    if (liens.length) {
      var act = document.createElement('div');
      act.className = 'cme-actions';
      liens.forEach(function (l) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = l.texte;
        if (l.classe) b.className = l.classe;
        b.onclick = function () { fermerMenuEleve(); l.action(); };
        act.appendChild(b);
      });
      m.appendChild(act);
    }
    document.body.appendChild(m);
    var placer = function () {
      var r = ancre.getBoundingClientRect(), l = Math.min(r.left, global.innerWidth - m.offsetWidth - 10);
      m.style.left = Math.max(10, l) + 'px';
      m.style.top = (r.bottom + 6) + 'px';
    };
    placer();
    var dehors = function (ev) {
      var chemin = ev.composedPath ? ev.composedPath() : [ev.target];
      if (chemin.indexOf(m) < 0 && chemin.indexOf(ancre) < 0) fermerMenuEleve();
    };
    var echap = function (ev) { if (ev.key === 'Escape') { fermerMenuEleve(); if (ancre.focus) ancre.focus(); } };
    document.addEventListener('pointerdown', dehors, true);
    document.addEventListener('keydown', echap, true);
    global.addEventListener('resize', placer);
    if (ancre.setAttribute) ancre.setAttribute('aria-expanded', 'true');
    menuOuvert = { el: m, ancre: ancre, dehors: dehors, echap: echap, placer: placer };
    return true;
  }

  /* ---------- Essai de l'enseignant : vue élève / vue enseignant ----------
   * « Tester à la place d'un élève » (espace enseignant) ouvre une session marquée essai. Dans ces sessions seulement,
   * un interrupteur « Élève | Enseignant » permet de passer de la vue de l'élève (règles de l'atelier, fenêtres et
   * fonctions de l'élève) à la vue enseignant (tous les modes). Le choix est gardé sur le serveur, avec la session :
   * le portail et chaque application le retrouvent. Une session d'élève normale répond toujours « eleve ».
   *   essai() : true dans une session d'essai ; vue() : 'eleve' | 'enseignant' ;
   *   changerVue(v) : change la vue (prévient surChangementVue, puis le serveur) ;
   *   boutonVue({ classe, onChange(v) }) : { bouton, maj } à poser juste après l'atelier, au centre de la barre du haut
   *   (caché hors essai). Les applications testent la fonction avant de l'appeler (module plus ancien).
   */
  var ecouteursVue = [];
  var STYLE_VUE = [
    '.centrale-vue{flex:none;display:inline-flex;align-items:center;gap:2px;height:34px;padding:2px;box-sizing:border-box;border-radius:999px;background:transparent;border:1px dashed #d9a400;font:600 12.5px/1 system-ui,-apple-system,"Segoe UI",sans-serif}',
    '.centrale-vue[hidden]{display:none!important}',
    '.centrale-vue button{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 11px;border:0;border-radius:999px;background:transparent;color:inherit;opacity:.72;font:inherit;white-space:nowrap;cursor:pointer}',
    '.centrale-vue button:hover{opacity:1}',
    '.centrale-vue button.on{background:#F5C400;color:#3a2d00;opacity:1;cursor:default}',
    '.centrale-vue button:focus-visible{outline:2px solid currentColor;outline-offset:-2px}',
    '.centrale-vue svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}',
    '.centrale-vue.court .centrale-vue-txt{display:none}.centrale-vue.court button{padding:0 8px}'
  ].join('\n');
  var ICONES_VUE = {
    eleve: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
    enseignant: '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20l4-4 4 4M7 9h6M7 12h9"/>'
  };

  function essai() {
    var s = session();
    return !!(s && s.essai);
  }

  function vue() {
    var s = session();
    return s && s.essai && s.vue === 'enseignant' ? 'enseignant' : 'eleve';
  }

  function surChangementVue(f) {
    if (typeof f === 'function') ecouteursVue.push(f);
  }

  function prevenirVue() {
    var v = vue();
    ecouteursVue.slice().forEach(function (f) { try { f(v); } catch (e) { /* rien */ } });
  }

  async function changerVue(v) {
    var s = session();
    if (!s || !s.essai) return false;
    v = v === 'enseignant' ? 'enseignant' : 'eleve';
    // La vue est d'abord enregistrée sur le serveur, PUIS les écouteurs sont prévenus : beaucoup relisent l'API
    // (le portail recharge api/ateliers.php) et liraient sinon l'ancienne vue (Élève et Enseignant inversés).
    var ok = false;
    try {
      var r = await fetch(url('session.php', { app: config.app }), { method: 'POST', headers: { 'X-Jeton': s.jeton, 'Content-Type': 'application/json' }, body: JSON.stringify({ vue: v }) });
      ok = r.ok;
    } catch (e) { /* hors ligne : la vue change quand même ici */ }
    var s2 = session();
    if (s2 && s2.jeton === s.jeton && s2.vue !== v) { s2.vue = v; enregistrerSession(s2); prevenirVue(); }
    return ok;
  }

  /** Relit la vue gardée sur le serveur (changée dans une autre application ou un autre onglet). */
  async function actualiserVue() {
    var s = session();
    if (!s || !s.essai || !config.api) return vue();
    try {
      var r = await fetch(url('session.php', { app: config.app }), { headers: { 'X-Jeton': s.jeton } });
      var d = await r.json();
      var s2 = session();
      if (r.ok && d.ok && s2 && s2.jeton === s.jeton) {
        var v = d.vue === 'enseignant' ? 'enseignant' : 'eleve';
        if ((s2.vue || 'eleve') !== v || !d.essai !== !s2.essai) { s2.vue = v; s2.essai = !!d.essai; enregistrerSession(s2); prevenirVue(); }
      }
    } catch (e) { /* hors ligne : on garde la vue connue */ }
    return vue();
  }

  function boutonVue(options) {
    options = options || {};
    if (!document.getElementById('centrale-style-vue')) {
      var st = document.createElement('style');
      st.id = 'centrale-style-vue';
      st.textContent = STYLE_VUE;
      document.head.appendChild(st);
    }
    var g = document.createElement('div');
    g.className = 'centrale-vue' + (options.classe ? ' ' + options.classe : '');
    g.setAttribute('role', 'group');
    g.setAttribute('aria-label', 'Essai à la place de l’élève : choix de la vue');
    var opts = [['eleve', 'Élève', 'Vue élève : comme le voit l’élève (fenêtres, fonctions et règles de l’atelier)'],
      ['enseignant', 'Enseignant', 'Vue enseignant : tous les modes, toujours connecté comme l’élève']];
    var boutons = opts.map(function (o) {
      var b = document.createElement('button');
      b.type = 'button';
      b.dataset.vue = o[0];
      b.title = o[2];
      b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + ICONES_VUE[o[0]] + '</svg><span class="centrale-vue-txt">' + o[1] + '</span>';
      b.addEventListener('click', function (ev) {
        ev.stopPropagation();
        if (vue() !== o[0]) changerVue(o[0]);
      });
      g.appendChild(b);
      return b;
    });
    function maj() {
      g.hidden = !essai();
      var v = vue();
      boutons.forEach(function (b) { var on = b.dataset.vue === v; b.classList.toggle('on', on); b.setAttribute('aria-pressed', String(on)); });
    }
    surChangementVue(function (v) { maj(); if (options.onChange) options.onChange(v); });
    ecouteursEtat.push(function () { maj(); });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && essai()) actualiserVue(); });
    maj();
    if (essai()) actualiserVue();
    return { bouton: g, maj: maj };
  }

  /* ---------- Événements du navigateur ---------- */

  global.addEventListener('online', function () {
    if (connecte()) { envoyerSecoursEnAttente(); toutEnvoyer(); viderFileProgression(); }
  });
  global.addEventListener('pagehide', envoiDeSortie);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') toutEnvoyer();
  });

  var Centrale = {
    init: init,
    connexion: connexion,
    connecte: connecte,
    session: session,
    deconnexion: deconnexion,
    charger: charger,
    sauver: sauver,
    supprimer: supprimer,
    listeDonnees: listeDonnees,
    sauvegardesEnCours: sauvegardesEnCours,
    envoyerFichier: envoyerFichier,
    listeFichiers: listeFichiers,
    chargerFichier: chargerFichier,
    supprimerFichier: supprimerFichier,
    listeRessources: listeRessources,
    chargerRessource: chargerRessource,
    afficherEtat: afficherEtat,
    progression: progression,
    mesProgressions: mesProgressions,
    toutesMesProgressions: toutesMesProgressions,
    declarerActivites: declarerActivites,
    parcours: parcours,
    ateliers: ateliers,
    propositions: propositions,
    lien: lien,
    ouvrir: ouvrir,
    retour: retour,
    allerPortail: allerPortail,
    activiteDemandee: activiteDemandee,
    etapeSuivante: etapeSuivante,
    allerEtapeSuivante: allerEtapeSuivante,
    precedent: precedent,
    allerPrecedent: allerPrecedent,
    boutonPrecedent: boutonPrecedent,
    reprendreSession: reprendreSession,
    afficherConnexion: afficherConnexion,
    identifiant: identifiant,
    nomAffiche: nomAffiche,
    nomEnseignant: nomEnseignant,
    etape: etape,
    contexteAtelier: contexteAtelier,
    projet: projet,
    changerSurnom: changerSurnom,
    afficherIdentifiant: afficherIdentifiant,
    menuEleve: menuEleve,
    fermerMenuEleve: fermerMenuEleve,
    nomFichier: nomFichier,
    telecharger: telecharger,
    ouvrirDepot: ouvrirDepot,
    essai: essai,
    vue: vue,
    changerVue: changerVue,
    actualiserVue: actualiserVue,
    surChangementVue: surChangementVue,
    boutonVue: boutonVue,
  };
  global.Centrale = Centrale;
})(window);
