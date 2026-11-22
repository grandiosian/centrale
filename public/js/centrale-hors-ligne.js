/* Centrale élèves — module « hors ligne » (atelier autonome pour un ordinateur portable sans internet).

   Ce fichier n'est PAS chargé par les applications en ligne : la Centrale le recopie dans le fichier autonome d'une
   application (TechnoStudio : dist/technostudio-autonome.html) quand l'enseignant prépare un atelier hors ligne
   (espace enseignant → Suivi → Travail hors ligne, lib/hors_ligne.php). Il est lu AVANT l'application.

   - Il prend la place de centrale-client.js : window.Centrale avec les mêmes fonctions (connecte, session, sauver,
     charger, progression, declarerActivites…). L'application croit être connectée ; tout reste sur l'ordinateur
     (localStorage, clé « centrale-hors-ligne:<export> »).
   - Les ateliers transférés par la Centrale sont dans le fichier : la demande api/atelier-fichier.php reçoit leur copie.
   - Bouton « Rendre mon travail » : fichier chiffré (AES-256-GCM, clé propre à ce fichier, connue seulement de la
     Centrale) à envoyer au professeur, qui l'importe dans le suivi. L'élève peut le refaire : le plus récent compte.

   window.CENTRALE_HORS_LIGNE = { format: 'centrale.hors-ligne', version: 1, export, cle (base64), app, atelier, titre,
     ouvrir, eleve: { classe, numero, identifiant }, prepare_le, centrale, ateliers: { id: fichier }, fichier (nom de ce fichier),
     parcours: { id, nom, etapes: [ { ordre, titre, app, nom_app, activite, fichier } ] } (parcours : un fichier par application) }
   Une étape s'ouvre avec « fichier.html#activite=<id> » (« * » : l'application sans activité précise).
   Fichiers envoyés au professeur (Centrale.envoyerFichier, ex. STL d'Établi 3D) : gardés et joints au rendu. */
(function () {
  'use strict';
  var C = window.CENTRALE_HORS_LIGNE;
  if (!C || !C.export || window.Centrale) return;

  var CLE = 'centrale-hors-ligne:' + C.export;
  var etat = lire() || { donnees: {}, progression: {}, activites: [], premiere: Date.now(), rendus: [] };
  etat.ouvertures = (etat.ouvertures || 0) + 1;
  etat.ouvert_le = Date.now();
  var stockageOk = ecrire();
  var config = {};
  var demandee = C.ouvrir || C.atelier || null;
  // Étape demandée par le sommaire du parcours ou un autre fichier : « #activite=<id> » (retiré de l'adresse avant l'application)
  var demande = /(?:^#|&)activite=([^&]+)/.exec(location.hash || '');
  if (demande) {
    demandee = decodeURIComponent(demande[1]) === '*' ? null : decodeURIComponent(demande[1]);
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) { /* rien */ }
  }
  // Comme une ouverture depuis le portail (« #centrale=…&activite=… ») : chaque application passe en mode atelier
  // (pas de menu du logo, pas de bibliothèque ni d'autre atelier, pas de niveau, pas de mode enseignant). init() retire
  // ensuite cette adresse, comme centrale-client.js.
  try {
    history.replaceState(null, '', location.pathname + location.search + '#centrale=hors-ligne' + (demandee ? '&activite=' + encodeURIComponent(demandee) : ''));
  } catch (e) { location.hash = 'centrale=hors-ligne' + (demandee ? '&activite=' + encodeURIComponent(demandee) : ''); }
  var CLE_FICHIERS = CLE + ':fichiers';
  var fichiers = (function () { try { return JSON.parse(localStorage.getItem(CLE_FICHIERS) || '{}') || {}; } catch (e) { return {}; } })();
  var fichiersGardes = true;
  var session = {
    ok: true, hors_ligne: true, jeton: 'hors-ligne-' + C.export, expire: 0,
    classe: C.eleve.classe, eleve: C.eleve.numero, identifiant: C.eleve.identifiant || (C.eleve.classe + ' · n° ' + C.eleve.numero),
    app: C.app, espace: { utilise: 0, quota: 0 }, depot: null,
  };

  function lire() { try { return JSON.parse(localStorage.getItem(CLE) || 'null'); } catch (e) { return null; } }
  function ecrire() { etat.maj = Date.now(); try { localStorage.setItem(CLE, JSON.stringify(etat)); return true; } catch (e) { return false; } }
  function copie(v) { return v === undefined ? null : JSON.parse(JSON.stringify(v)); }
  function signaler(e) { try { if (config.surEtat) config.surEtat(e); } catch (x) { /* rien */ } }

  /* ---------- Ateliers contenus dans le fichier (api/atelier-fichier.php) ---------- */
  var fetchNatif = window.fetch ? window.fetch.bind(window) : null;
  function reponse(statut, corps) {
    return new Response(JSON.stringify(corps), { status: statut, headers: { 'Content-Type': 'application/json; charset=utf-8' } });
  }
  window.fetch = function (entree, options) {
    var url = typeof entree === 'string' ? entree : (entree && entree.url) || '';
    if (/api\/atelier-fichier\.php/.test(url)) {
      var m = url.match(/[?&]id=([^&#]+)/);
      var id = m ? decodeURIComponent(m[1]) : '';
      var ateliers = C.ateliers || {};
      if (id) return Promise.resolve(ateliers[id] ? reponse(200, ateliers[id]) : reponse(404, { ok: false, erreur: 'Atelier absent de ce fichier.' }));
      return Promise.resolve(reponse(200, { ok: true, format: 'ateliers.centrale', version: 1, app: C.app,
        ateliers: Object.keys(ateliers).map(function (k) { var a = ateliers[k]; return { id: k, format: a.format, version: a.version, titre: a.title || a.titre || k }; }) }));
    }
    if (C.centrale && url.indexOf(C.centrale) === 0) {
      return Promise.reject(new TypeError('Hors ligne : la Centrale n’est pas utilisée par ce fichier.'));
    }
    return fetchNatif ? fetchNatif(entree, options) : Promise.reject(new TypeError('fetch indisponible'));
  };

  /* ---------- Avancement : on garde le meilleur, comme le serveur ---------- */
  function fusionner(a, b) {
    if (!a) return copie(b);
    var r = Object.assign({}, a, b);
    var max = function (x, y) { return x == null ? y : y == null ? x : Math.max(x, y); };
    r.statut = a.statut === 'termine' || b.statut === 'termine' ? 'termine' : 'commence';
    r.faites = max(a.faites, b.faites);
    r.score = max(a.score, b.score);
    if (a.constats && !b.constats) r.constats = a.constats;
    r.debut = a.debut || b.debut;
    return r;
  }

  var M = {
    horsLigne: true,
    init: function (options) {
      config = options || {};
      if (/(^|[#&])centrale=/.test(location.hash)) { try { history.replaceState(null, '', location.pathname + location.search); } catch (e) { /* rien */ } }
      if (config.activites && config.activites.length) { etat.activites = copie(config.activites); ecrire(); }
      setTimeout(function () { signaler({ etat: 'enregistre' }); }, 0);
      return M;
    },
    connecte: function () { return true; },
    session: function () { return session; },
    connexion: function () { return Promise.resolve(session); },
    deconnexion: function () { /* rien : le fichier appartient à cet élève */ },
    afficherConnexion: function (element, options) {
      if (element) element.textContent = '';
      if (options && options.onConnecte) setTimeout(function () { options.onConnecte(session); }, 0);
    },
    charger: function (cle, defaut) {
      var d = etat.donnees[cle];
      return Promise.resolve(d ? copie(d.valeur) : (defaut === undefined ? null : defaut));
    },
    sauver: function (cle, valeur) {
      etat.donnees[String(cle)] = { valeur: copie(valeur), maj: Date.now() };
      var ok = ecrire();
      signaler(ok ? { etat: 'enregistre' } : { etat: 'erreur', message: 'Stockage de l’ordinateur plein ou bloqué : rends ton travail maintenant.' });
      majBouton();
      return Promise.resolve({ ok: true, maj: Math.floor(Date.now() / 1000) });
    },
    supprimer: function (cle) { delete etat.donnees[cle]; ecrire(); return Promise.resolve({ ok: true }); },
    listeDonnees: function () {
      return Promise.resolve(Object.keys(etat.donnees).map(function (k) {
        return { cle: k, taille: JSON.stringify(etat.donnees[k].valeur).length, maj: Math.floor(etat.donnees[k].maj / 1000) };
      }));
    },
    sauvegardesEnCours: function () { return false; },
    listeRessources: function () { return Promise.resolve([]); },
    chargerRessource: function () { return Promise.reject(new Error('Pas de ressource hors ligne.')); },
    listeFichiers: function () {
      return Promise.resolve(Object.keys(fichiers).map(function (n) { var f = fichiers[n]; return { nom: n, taille: f.taille, type: f.type, maj: Math.floor(f.maj / 1000) }; }));
    },
    /** Fichier pour le professeur : gardé sur l'ordinateur, joint au prochain rendu. */
    envoyerFichier: function (fichier, nom) {
      if (!(fichier instanceof Blob)) fichier = new Blob([fichier == null ? '' : fichier], { type: 'text/plain;charset=utf-8' });
      nom = String(nom || fichier.name || M.nomFichier('txt')).replace(/[\\/:*?"<>|]+/g, '_').slice(0, 120);
      if (fichier.size > 4 * 1024 * 1024) return Promise.reject(new Error('Fichier trop gros pour un atelier hors ligne (4 Mo au plus).'));
      return new Promise(function (ok, ko) {
        var r = new FileReader();
        r.onerror = function () { ko(new Error('Lecture du fichier impossible.')); };
        r.onload = function () {
          var t = String(r.result);
          fichiers[nom] = { type: fichier.type || 'application/octet-stream', taille: fichier.size, maj: Date.now(), b64: t.slice(t.indexOf(',') + 1) };
          try { localStorage.setItem(CLE_FICHIERS, JSON.stringify(fichiers)); fichiersGardes = true; } catch (e) { fichiersGardes = false; }
          if (!fichiersGardes) signaler({ etat: 'erreur', message: 'Fichier gardé seulement tant que la page est ouverte : rends ton travail maintenant.' });
          majBouton();
          ok({ nom: nom, taille: fichier.size, type: fichiers[nom].type, maj: Math.floor(Date.now() / 1000) });
        };
        r.readAsDataURL(fichier);
      });
    },
    supprimerFichier: function (nom) { delete fichiers[nom]; try { localStorage.setItem(CLE_FICHIERS, JSON.stringify(fichiers)); } catch (e) { /* rien */ } return Promise.resolve({ ok: true }); },
    nomFichier: function (extension, suffixe) {
      var p = function (x) { return String(x || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^A-Za-z0-9-]+/g, '-').replace(/^-|-$/g, ''); };
      var d = new Date(), z = function (n) { return (n < 10 ? '0' : '') + n; };
      var m = [p(C.eleve.classe) || 'classe', p(C.eleve.numero) || 'eleve', p(C.app), d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate())];
      if (suffixe) m.push(p(suffixe));
      return m.join('_') + '.' + String(extension || 'txt').replace(/^\./, '');
    },
    afficherEtat: function () { /* rien */ },
    progression: function (activite, infos) {
      var envoi = Object.assign({ statut: 'commence' }, infos || {});
      envoi.maj = Date.now();
      envoi.debut = (etat.progression[activite] && etat.progression[activite].debut) || envoi.maj;
      etat.progression[activite] = fusionner(etat.progression[activite], envoi);
      ecrire();
      majBouton();
      majNavigation();
      return Promise.resolve();
    },
    mesProgressions: function () {
      var r = {};
      Object.keys(etat.progression).forEach(function (k) { r[k] = Object.assign({ activite: k }, copie(etat.progression[k])); });
      return Promise.resolve(r);
    },
    toutesMesProgressions: function () {
      return M.mesProgressions().then(function (r) { var o = {}; o[C.app] = Object.keys(r).map(function (k) { return r[k]; }); return o; });
    },
    declarerActivites: function (liste) { etat.activites = copie(liste || []); ecrire(); majBouton(); return Promise.resolve({ ok: true }); },
    activiteDemandee: function () { var a = demandee; demandee = null; return a; },
    parcours: function () { return Promise.resolve(null); },
    ateliers: function () { return Promise.resolve([]); },
    propositions: function () { return Promise.resolve([]); },
    retour: function () { ouvrirRendu(); },
    allerPortail: function () { ouvrirRendu(); return true; },
    nomAffiche: function () { return session.identifiant; },
    vue: function () { return 'eleve'; },
    rendre: function () { return rendre(); },
    navigation: function (rappel, options) {
      if (typeof rappel !== 'function') return null;
      var n = { rappel: rappel, options: options || {} };
      navs.push(n);
      if (bouton) bouton.hidden = true;   // « Rendre mon travail » est proposé à la fin, dans l'application
      majNavigation(0);
      return { maj: function () { majNavigation(0); }, arreter: function () { navs = navs.filter(function (x) { return x !== n; }); } };
    },
  };
  window.Centrale = M;

  /* ---------- Navigation intégrée (contrat Centrale.navigation) : la suite proposée au pied de la consigne ----------
     Parcours : l'étape suivante faite hors ligne (même fichier ou autre fichier), puis « Rendre mon travail ».
     Atelier : « Rendre mon travail ». TechnoStudio ne montre la suite qu'à la dernière feuille ; NetSim et Établi 3D la
     montrent tout le temps : elle n'apparaît donc pour eux qu'une fois l'activité terminée. */
  var navs = [], navMinuteur = 0;
  function activiteOuverte() {
    for (var i = 0; i < navs.length; i++) {
      var f = navs[i].options.activite;
      if (typeof f === 'function') { try { var a = f(); if (a) return String(a); } catch (e) { /* rien */ } }
    }
    return '';
  }
  function finie(a) { var p = etat.progression[a]; return !!(p && p.statut === 'termine'); }
  function etapeDe(a) {
    var et = (C.parcours && C.parcours.etapes) || [], i;
    for (i = 0; i < et.length; i++) if (et[i].app === C.app && et[i].activite && et[i].activite === a) return i;
    for (i = 0; i < et.length; i++) if (et[i].app === C.app && et[i].activite && a.indexOf(et[i].activite + '/') === 0) return i;
    for (i = 0; i < et.length; i++) if (et[i].app === C.app && !et[i].activite) return i;
    return -1;
  }
  var versRendu = {
    cible: 'portail', libelle: 'Rendre mon travail', court: 'Rendre mon travail', fin: true, verrou: false, raison: '',
    titre: 'Enregistrer le fichier chiffré à envoyer à ton professeur',
    aller: function () { ouvrirRendu(); return Promise.resolve(true); },
  };
  function calculerNavigation() {
    var a = activiteOuverte();
    var montrer = C.app === 'technostudio' || finie(a);
    if (!C.parcours) return { contexte: 'parcours', position: null, precedent: null, suivant: montrer ? versRendu : null };
    var et = C.parcours.etapes || [], i = etapeDe(a);
    if (i < 0) return { contexte: 'parcours', position: null, precedent: null, suivant: null };
    var suite = null;
    for (var j = i + 1; j < et.length && !suite; j++) if (et[j].fichier) suite = et[j];
    var suivant = !suite ? versRendu : {
      cible: 'etape', libelle: 'Étape ' + (et.indexOf(suite) + 1) + ' : ' + suite.titre, app: suite.app, activite: suite.activite || '',
      titre: suite.fichier === C.fichier ? 'Ouvrir l’étape suivante du parcours' : 'Ouvrir l’étape suivante (' + (suite.nom_app || suite.app) + ')',
      fin: false, verrou: false, raison: '',
      aller: function () { ouvrirEtape(suite); return Promise.resolve(true); },
    };
    // étape « toute l'application » (modéliser librement…) : rien à terminer, la suite est proposée tout de suite
    return { contexte: 'parcours', precedent: null, suivant: montrer || !et[i].activite ? suivant : null,
             position: { numero: i + 1, total: et.length, titre: et[i].titre, consigne: '' } };
  }
  function majNavigation(delai) {
    if (!navs.length) return;
    clearTimeout(navMinuteur);
    navMinuteur = setTimeout(function () {
      var nav = calculerNavigation();
      navs.slice().forEach(function (n) { try { n.rappel(nav); } catch (e) { /* l'application gère */ } });
    }, delai == null ? 300 : delai);
  }

  /* ---------- Fichier pour le professeur ---------- */
  function b64(octets) {
    var s = '', pas = 0x8000;
    for (var i = 0; i < octets.length; i += pas) s += String.fromCharCode.apply(null, octets.subarray(i, i + pas));
    return btoa(s);
  }
  function deb64(t) { var s = atob(t), o = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) o[i] = s.charCodeAt(i); return o; }
  async function gzip(octets) {
    if (typeof CompressionStream !== 'function') return null;
    try { return new Uint8Array(await new Response(new Blob([octets]).stream().pipeThrough(new CompressionStream('gzip'))).arrayBuffer()); } catch (e) { return null; }
  }
  function nomFichier() {
    var d = new Date(), z = function (n) { return (n < 10 ? '0' : '') + n; };
    var propre = function (s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); };
    return 'rendu_' + propre(C.eleve.classe) + '-' + propre(C.eleve.numero) + '_' + propre(C.titre || C.atelier) + '_'
      + d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()) + '_' + z(d.getHours()) + 'h' + z(d.getMinutes()) + '.tvrendu';
  }
  /** Derniers envois de l'application (TechnoStudio regroupe l'avancement 4 s après une réponse). */
  function avantRendu() {
    try {
      var Z = window.TL && window.TL.centrale;
      if (Z && Z.rapport && window.TL.app && window.TL.app.progress) window.TL.app.progress.forEach(function (_, wid) { Z.rapport(wid); });
    } catch (e) { /* rien */ }
  }
  async function rendre() {
    if (!(window.crypto && crypto.subtle)) throw new Error('Ce navigateur ne sait pas chiffrer le fichier. Ouvre l’atelier avec Firefox, Chrome ou Edge.');
    avantRendu();
    var maintenant = Date.now();
    var contenu = {
      format: 'centrale.rendu-contenu', version: 1, export: C.export, app: C.app, atelier: C.atelier, titre: C.titre,
      eleve: { classe: C.eleve.classe, numero: C.eleve.numero }, prepare_le: C.prepare_le, premiere_ouverture: etat.premiere,
      ouvertures: etat.ouvertures, rendu_le: maintenant, navigateur: navigator.userAgent.slice(0, 200),
      activites: etat.activites, progression: etat.progression, donnees: etat.donnees, fichiers: fichiers,
      parcours: C.parcours ? { id: C.parcours.id, nom: C.parcours.nom } : null,
    };
    var clair = new TextEncoder().encode(JSON.stringify(contenu));
    var serre = await gzip(clair);
    var cle = await crypto.subtle.importKey('raw', deb64(C.cle), { name: 'AES-GCM' }, false, ['encrypt']);
    var iv = crypto.getRandomValues(new Uint8Array(12));
    var chiffre = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: new TextEncoder().encode(C.export) }, cle, serre || clair));
    var fichier = {
      format: 'centrale.rendu', version: 1, export: C.export, app: C.app, atelier: C.atelier, titre: C.titre,
      eleve: C.eleve.classe + ' · n° ' + C.eleve.numero, rendu_le: new Date(maintenant).toISOString(),
      chiffrement: 'AES-256-GCM', gzip: !!serre, iv: b64(iv), donnees: b64(chiffre),
    };
    var nom = nomFichier();
    var url = URL.createObjectURL(new Blob([JSON.stringify(fichier)], { type: 'application/octet-stream' }));
    var a = document.createElement('a');
    a.href = url; a.download = nom; a.style.display = 'none';
    document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 4000);
    etat.rendus = (etat.rendus || []).concat([{ le: maintenant, nom: nom }]).slice(-20);
    ecrire();
    majBouton();
    return nom;
  }

  /* ---------- Interface : bouton et fenêtre « Rendre mon travail » ---------- */
  var bouton = null, fenetre = null;
  function bilan() {
    var liste = (etat.activites || []).filter(function (a) { return !C.atelier || String(a.id).split('/')[0] === C.atelier; });
    var ids = liste.length ? liste.map(function (a) { return a.id; }) : Object.keys(etat.progression);
    var finies = ids.filter(function (k) { return etat.progression[k] && etat.progression[k].statut === 'termine'; }).length;
    return { finies: finies, total: ids.length, liste: liste };
  }
  function quand(ts) { var d = new Date(ts); return d.toLocaleDateString('fr-FR') + ' à ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }); }
  function style() {
    if (document.getElementById('chl-style')) return;
    var s = document.createElement('style');
    s.id = 'chl-style';
    s.textContent =
      '.chl-barre{position:fixed;left:12px;bottom:12px;z-index:2147483000;display:flex;gap:8px;flex-wrap:wrap}' +
      '.chl-bouton{display:flex;align-items:center;gap:8px;padding:9px 14px;border:0;border-radius:999px;' +
      'background:#1f7a4d;color:#fff;font:600 14px/1.2 system-ui,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.25);cursor:pointer}' +
      '.chl-bouton[hidden]{display:none}.chl-bouton:hover{background:#18633e}.chl-bouton small{font-weight:500;opacity:.85}' +
      '.chl-bouton-parcours{background:#2340b8}.chl-bouton-parcours:hover{background:#1b3290}' +
      '.chl-etapes{margin:10px 0;padding-left:22px}.chl-etapes li{margin:6px 0}.chl-etapes button{border:0;border-radius:7px;padding:4px 10px;background:#e9edf3;color:#1b2230;cursor:pointer;font:600 13px system-ui,sans-serif}' +
      '.chl-voile{position:fixed;inset:0;z-index:2147483001;background:rgba(15,20,30,.45);display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.chl-fen{background:#fff;color:#1b2230;max-width:460px;width:100%;border-radius:14px;padding:20px 22px;font:15px/1.45 system-ui,sans-serif;box-shadow:0 10px 40px rgba(0,0,0,.3)}' +
      '.chl-fen h2{margin:0 0 6px;font-size:19px}.chl-fen p{margin:8px 0}.chl-fen .chl-discret{color:#5b6475;font-size:13px}' +
      '.chl-fen .chl-ok{background:#e7f6ee;border-radius:8px;padding:8px 10px;color:#14583a}.chl-fen .chl-err{background:#fdecec;border-radius:8px;padding:8px 10px;color:#8a1f1f}' +
      '.chl-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}' +
      '.chl-actions button{border:0;border-radius:9px;padding:9px 14px;font:600 14px system-ui,sans-serif;cursor:pointer;background:#e9edf3;color:#1b2230}' +
      '.chl-actions .chl-principal{background:#1f7a4d;color:#fff}' +
      '@media (prefers-color-scheme:dark){.chl-fen{background:#1d2330;color:#e8ecf3}.chl-fen .chl-discret{color:#a5adbd}.chl-actions button,.chl-etapes button{background:#2d3546;color:#e8ecf3}}';
    document.head.appendChild(s);
  }
  function majBouton() {
    if (!bouton) return;
    var b = bilan();
    bouton.innerHTML = '';
    bouton.append('📤 Rendre mon travail');
    if (b.finies) { var s = document.createElement('small'); s.textContent = b.finies + ' feuille' + (b.finies > 1 ? 's' : '') + ' finie' + (b.finies > 1 ? 's' : ''); bouton.append(s); }
  }
  function ouvrirRendu() {
    style();
    if (fenetre) fenetre.remove();
    var b = bilan();
    var dernier = (etat.rendus || []).slice(-1)[0];
    fenetre = document.createElement('div');
    fenetre.className = 'chl-voile';
    fenetre.innerHTML = '<div class="chl-fen" role="dialog" aria-modal="true" aria-labelledby="chl-titre">' +
      '<h2 id="chl-titre">Rendre mon travail</h2>' +
      '<p><strong></strong><br><span class="chl-discret"></span></p>' +
      '<p class="chl-bilan"></p>' +
      '<p class="chl-discret">Le fichier est chiffré : seul ton professeur peut l’ouvrir. Enregistre-le (clé USB, dossier « Téléchargements ») puis envoie-le à ton professeur (ENT, clé USB). Tu peux continuer à travailler et le rendre à nouveau : c’est le plus récent qui compte.</p>' +
      '<p class="chl-parcours chl-discret" hidden></p>' +
      '<p class="chl-msg" hidden></p>' +
      '<div class="chl-actions"><button type="button" class="chl-fermer">Continuer l’atelier</button><button type="button" class="chl-principal">Enregistrer le fichier pour mon professeur</button></div></div>';
    fenetre.querySelector('strong').textContent = C.titre || C.atelier || 'Atelier';
    fenetre.querySelector('.chl-discret').textContent = session.identifiant + ' · ' + C.eleve.classe + ' n° ' + C.eleve.numero;
    fenetre.querySelector('.chl-bilan').textContent = (b.finies + ' feuille' + (b.finies > 1 ? 's' : '') + ' terminée' + (b.finies > 1 ? 's' : '') + '. ')
      + (dernier ? 'Dernier fichier enregistré le ' + quand(dernier.le) + '.' : 'Pas encore de fichier enregistré.');
    var nf = Object.keys(fichiers).length;
    if (nf) fenetre.querySelector('.chl-bilan').textContent += ' ' + nf + ' fichier' + (nf > 1 ? 's' : '') + ' joint' + (nf > 1 ? 's' : '') + ' (' + Object.keys(fichiers).join(', ') + ').';
    var autres = autresFichiers();
    if (autres.length) {
      var np = fenetre.querySelector('.chl-parcours');
      np.hidden = false;
      np.textContent = 'Parcours « ' + C.parcours.nom + ' » : ce fichier ne rend que ton travail sur ' + (C.nom_app || C.app) + '. Rends aussi : ' + autres.join(', ') + '.';
    }
    var msg = fenetre.querySelector('.chl-msg');
    var fermer = function () { if (fenetre) { fenetre.remove(); fenetre = null; } majNavigation(0); };
    fenetre.querySelector('.chl-fermer').onclick = fermer;
    fenetre.addEventListener('click', function (e) { if (e.target === fenetre) fermer(); });
    var principal = fenetre.querySelector('.chl-principal');
    principal.onclick = function () {
      principal.disabled = true;
      msg.hidden = false; msg.className = 'chl-msg chl-discret'; msg.textContent = 'Préparation du fichier…';
      // laisse 1 s à l'application pour envoyer ses derniers changements
      setTimeout(function () {
        rendre().then(function (nom) {
          msg.className = 'chl-msg chl-ok';
          msg.textContent = 'Fichier enregistré : ' + nom + '. Vérifie qu’il est bien dans tes téléchargements, puis envoie-le à ton professeur.';
        }).catch(function (e) {
          msg.className = 'chl-msg chl-err';
          msg.textContent = e.message || String(e);
        }).finally(function () { principal.disabled = false; });
      }, 1000);
    };
    document.body.appendChild(fenetre);
    principal.focus();
  }
  /* ---------- Parcours : sommaire des étapes, d'un fichier à l'autre ---------- */
  function autresFichiers() {
    if (!C.parcours) return [];
    var vus = {};
    (C.parcours.etapes || []).forEach(function (e) { if (e.fichier && e.fichier !== C.fichier) vus[e.fichier] = 1; });
    return Object.keys(vus);
  }
  function ouvrirEtape(e) {
    var cible = '#activite=' + encodeURIComponent(e.activite || '*');
    if (e.fichier === C.fichier) { avantRendu(); setTimeout(function () { location.hash = cible.slice(1); location.reload(); }, 300); return; }
    location.href = encodeURI(e.fichier) + cible;
  }
  function ouvrirParcours() {
    style();
    if (fenetre) fenetre.remove();
    fenetre = document.createElement('div');
    fenetre.className = 'chl-voile';
    fenetre.innerHTML = '<div class="chl-fen" role="dialog" aria-modal="true" aria-labelledby="chl-titre"><h2 id="chl-titre"></h2>' +
      '<p class="chl-discret">Les étapes de ton parcours. Celles d’une autre application s’ouvrent dans un autre fichier du même dossier.</p>' +
      '<ol class="chl-etapes"></ol><div class="chl-actions"><button type="button" class="chl-rendre">Rendre mon travail</button><button type="button" class="chl-fermer chl-principal">Fermer</button></div></div>';
    fenetre.querySelector('h2').textContent = 'Parcours « ' + C.parcours.nom + ' »';
    var ol = fenetre.querySelector('.chl-etapes');
    (C.parcours.etapes || []).forEach(function (e) {
      var li = document.createElement('li');
      var p = e.app === C.app && e.activite ? etat.progression[e.activite] : null;
      var t = document.createElement('span');
      t.textContent = e.titre + ' — ' + (e.nom_app || e.app) + (p ? (p.statut === 'termine' ? ' ✓' : ' (en cours)') : '');
      li.append(t);
      if (e.fichier) {
        var b = document.createElement('button');
        b.type = 'button'; b.textContent = e.fichier === C.fichier ? 'Ouvrir' : 'Ouvrir (autre fichier)';
        b.onclick = function () { ouvrirEtape(e); };
        li.append(' ', b);
      } else {
        var c = document.createElement('em'); c.className = 'chl-discret'; c.textContent = ' à faire en classe'; li.append(c);
      }
      ol.append(li);
    });
    var fermer = function () { if (fenetre) { fenetre.remove(); fenetre = null; } };
    fenetre.querySelector('.chl-fermer').onclick = fermer;
    fenetre.querySelector('.chl-rendre').onclick = ouvrirRendu;
    fenetre.addEventListener('click', function (ev) { if (ev.target === fenetre) fermer(); });
    document.body.appendChild(fenetre);
  }

  function installer() {
    style();
    bouton = document.createElement('button');
    bouton.type = 'button';
    bouton.className = 'chl-bouton';
    bouton.title = 'Atelier hors ligne : enregistre un fichier chiffré à envoyer à ton professeur';
    bouton.onclick = ouvrirRendu;
    bouton.hidden = true;   // secours : seulement si l'application ne dessine pas la suite (navigation)
    setTimeout(function () { if (!navs.length) bouton.hidden = false; }, 4000);
    var barre = document.createElement('div');
    barre.className = 'chl-barre';
    barre.appendChild(bouton);
    document.body.appendChild(barre);
    if (C.parcours) {
      var bp = document.createElement('button');
      bp.type = 'button'; bp.className = 'chl-bouton chl-bouton-parcours'; bp.textContent = '🧭 Parcours';
      bp.title = 'Étapes du parcours « ' + C.parcours.nom + ' »';
      bp.onclick = ouvrirParcours;
      barre.appendChild(bp);
    }
    majBouton();
    if (!stockageOk) setTimeout(function () { alert('Attention : ce navigateur ne garde pas le travail (stockage bloqué). Rends ton travail avant de fermer la page.'); }, 1500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installer); else installer();
  // Fermer la page : rappel s'il y a du travail jamais rendu, ou modifié depuis le dernier fichier
  window.addEventListener('beforeunload', function (e) {
    var dernier = (etat.rendus || []).slice(-1)[0];
    var modifie = Object.keys(etat.donnees).concat(Object.keys(fichiers)).some(function (k) {
      var x = etat.donnees[k] || fichiers[k]; return !dernier || x.maj > dernier.le;
    });
    if (modifie) { e.preventDefault(); e.returnValue = ''; }
  });
})();
