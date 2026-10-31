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
    toutEnvoyer();
    viderFileProgression();
    location.href = r;
    return true;
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
          ecrireFile(actuelle);
        } catch (e) {
          if (e.horsLigne || e.statut === 401) { setTimeout(viderFileProgression, DELAI_REESSAI); break; }
          var f2 = lireFile(); delete f2[ids[i]]; ecrireFile(f2); // donnée refusée : on l'abandonne
        }
      }
    })().finally(function () { envoiFileEnCours = null; });
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
      versionsProjet[nom] = parseInt(reponse.headers.get('X-Version') || '0', 10);
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

  /** Affiche « Ton identifiant : 4C-LHA-LoutreAgile [Changer mon surnom] » dans `element`. */
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
      if (!s.surnom_modifiable) return;
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
    activiteDemandee: activiteDemandee,
    reprendreSession: reprendreSession,
    afficherConnexion: afficherConnexion,
    identifiant: identifiant,
    etape: etape,
    contexteAtelier: contexteAtelier,
    projet: projet,
    changerSurnom: changerSurnom,
    afficherIdentifiant: afficherIdentifiant,
    nomFichier: nomFichier,
    telecharger: telecharger,
    ouvrirDepot: ouvrirDepot,
  };
  global.Centrale = Centrale;
})(window);
