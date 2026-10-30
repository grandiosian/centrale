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

  var config = { api: '', app: '*', surEtat: null, surDeconnexion: null };
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
    return Centrale;
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
    return donnees;
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
    if (connecte()) {
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
      'placeholder="ex. 5A-abcdefg-hjkm-npqrs" required>' +
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

  /* ---------- Événements du navigateur ---------- */

  global.addEventListener('online', function () { if (connecte()) { envoyerSecoursEnAttente(); toutEnvoyer(); } });
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
    afficherConnexion: afficherConnexion,
    nomFichier: nomFichier,
    telecharger: telecharger,
    ouvrirDepot: ouvrirDepot,
  };
  global.Centrale = Centrale;
})(window);
