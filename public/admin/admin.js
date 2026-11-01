/**
 * Centrale élèves — noms des élèves conservés UNIQUEMENT sur l'ordinateur de l'enseignant.
 *
 * Le serveur ne connaît que des numéros (« 5A · 07 »). La correspondance numéro → nom
 * est gardée dans le navigateur (localStorage) et dans le fichier CSV que vous téléchargez.
 * Rien de ce qui est ici n'est envoyé au serveur.
 */
(function () {
  'use strict';

  var CLE_NOMS = 'centrale-noms';           // { "5A": { "07": "Léa D.", … }, … }
  var CLE_ATTENTE = 'centrale-noms-attente'; // noms tapés juste avant une génération de codes
  var CLE_STATUTS = 'centrale-statuts';     // { "4F": { "07": { exclu: "2026-10-15", changement: "2026-10-20" } } } — la sortie reste dans centrale-sorties
  var CLE_CARTONS = 'centrale-cartons';     // { "4F": { "07": [ { type: "observation" | "recompense", texte, date } ] } } — gardés ici, comme les noms
  var CLE_BESOINS = 'centrale-besoins';     // { "5A": { "07": ["PAP", "PAI"], … } } — PPS, PAP, PAI, PPRE
  var TYPES_BESOINS = ['PPS', 'PAP', 'PAI', 'PPRE'];
  var CLE_CODES = 'centrale-codes';         // { "5A": { "07": "5A-abcdefg-hjkm-npqrs" } } — copie locale pour réimprimer
  var CLE_FEUILLES = 'centrale-feuilles';   // { "Hautier": { classes, largeur, hauteur, x0, y0, pasX, pasY, photoL, photoH, faceX, faceY } } — jamais envoyé ; les images sont dans IndexedDB

  /* ---------- Stockage local ---------- */

  function lire() {
    try { return JSON.parse(localStorage.getItem(CLE_NOMS)) || {}; } catch (e) { return {}; }
  }
  function ecrire(carte) {
    try { localStorage.setItem(CLE_NOMS, JSON.stringify(carte)); } catch (e) { /* stockage bloqué */ }
  }
  function ajouter(classe, paires) {
    var carte = lire();
    carte[classe] = Object.assign(carte[classe] || {}, paires);
    ecrire(carte);
  }
  function nomLocal(classe, numero) {
    return (lire()[classe] || {})[numero] || '';
  }
  function lireBesoins() {
    try { return JSON.parse(localStorage.getItem(CLE_BESOINS)) || {}; } catch (e) { return {}; }
  }
  function ecrireBesoins(carte) {
    try { localStorage.setItem(CLE_BESOINS, JSON.stringify(carte)); } catch (e) { /* stockage bloqué */ }
  }
  /** Garde seulement le TYPE de projet (jamais le détail, ex. la nature d'une allergie) : « PAI (allergie), PAP » → ["PAP", "PAI"]. */
  function typesBesoins(texte) {
    var t = Array.isArray(texte) ? texte.join(' ') : String(texte || '');
    return TYPES_BESOINS.filter(function (type) {
      return new RegExp('(^|[^A-Za-z0-9@.])' + type + '(?=$|[^A-Za-z0-9@])', 'i').test(t);
    });
  }
  function ajouterBesoins(classe, paires) {       // paires : { numero: ["PAP"] } ; [] = aucun
    var carte = lireBesoins();
    carte[classe] = carte[classe] || {};
    Object.keys(paires).forEach(function (num) {
      var b = typesBesoins(paires[num]);
      if (b.length) carte[classe][num] = b; else delete carte[classe][num];
    });
    if (!Object.keys(carte[classe]).length) delete carte[classe];
    ecrireBesoins(carte);
  }
  function besoinsLocaux(classe, numero) {
    return (lireBesoins()[classe] || {})[numero] || [];
  }
  /** Pastilles « PAP », « PAI »… (masquées à l'impression : les fiches et plans peuvent être affichés en classe). */
  function pastilles(liste) {
    var span = document.createElement('span');
    span.className = 'besoins-local';
    liste.forEach(function (b) {
      var x = document.createElement('span');
      x.className = 'badge-besoin besoin-' + b.toLowerCase();
      x.textContent = b;
      span.appendChild(x);
    });
    return span;
  }
  function lireCle(cle) {
    try { return JSON.parse(localStorage.getItem(cle)) || {}; } catch (e) { return {}; }
  }
  function ecrireCle(cle, carte) {
    try { localStorage.setItem(cle, JSON.stringify(carte)); } catch (e) { /* stockage bloqué */ }
  }
  function ajouterCodes(classe, paires) {
    var carte = lireCle(CLE_CODES);
    carte[classe] = Object.assign(carte[classe] || {}, paires);
    ecrireCle(CLE_CODES, carte);
  }
  function codesLocaux(classe) { return Object.assign({}, lireCle(CLE_CODES)[classe] || {}); }

  /* Sorties définitives : date par élève, gardée ici (l'élève reste dans la liste, grisé) */
  var CLE_SORTIES = 'centrale-sorties';   // { "4F": { "07": "2026-10-15" } }
  function lireSorties() { return lireCle(CLE_SORTIES); }
  function sortieLocale(classe, numero) { return (lireSorties()[classe] || {})[numero] || ''; }
  function setSortieIn(carte, classe, numero, date) {
    carte[classe] = carte[classe] || {};
    if (date) carte[classe][numero] = date; else delete carte[classe][numero];
    if (!Object.keys(carte[classe]).length) delete carte[classe];
  }
  function setSortie(classe, numero, date) {
    var carte = lireSorties();
    setSortieIn(carte, classe, numero, date);
    ecrireCle(CLE_SORTIES, carte);
  }

  /* Statuts de l'élève (plusieurs possibles) : sortie (vert), exclu (rouge), changement de classe (bleu). Chacun a sa date. */
  var STATUTS = { sortie: 'Sortie', exclu: 'Exclu', changement: 'Changement de classe' };
  function statutsLocaux(classe, numero) {
    var autres = (lireCle(CLE_STATUTS)[classe] || {})[numero] || {};
    return { sortie: sortieLocale(classe, numero), exclu: autres.exclu || '', changement: autres.changement || '' };
  }
  function setStatut(classe, numero, statut, date) {
    if (statut === 'sortie') { setSortie(classe, numero, date); return; }
    var carte = lireCle(CLE_STATUTS);
    carte[classe] = carte[classe] || {};
    carte[classe][numero] = carte[classe][numero] || {};
    if (date) carte[classe][numero][statut] = date; else delete carte[classe][numero][statut];
    if (!Object.keys(carte[classe][numero]).length) delete carte[classe][numero];
    if (!Object.keys(carte[classe]).length) delete carte[classe];
    ecrireCle(CLE_STATUTS, carte);
  }

  /* Trombinoscope : une feuille (une image) par établissement. Les classes se suivent de haut en bas dans l'image. */
  var PRESET_FEUILLES = { 'hautier': '5C, 5D, 5F, 4B, 4D, 4F', 'camus': '4A, 4B, 4C, 4D, 4E, 3F' };   // repéré dans le nom de la feuille
  var DEFAUTS_FEUILLE = { x0: 6, y0: 5, pasX: 245, pasY: 298, photoL: 156, photoH: 200, faceX: 50, faceY: 40 };
  var COLONNES = 10, RANGEES = 3, MAX_ELEVES = 30;   // par classe : 3 rangées de 10 photos
  var CLE_ATTENTE_ELEVE = 'centrale-ajout-eleve';   // le nouvel élève en attente de son code (formulaire « Ajouter un élève »)
  var urlsFeuilles = {};                              // nom de la feuille -> adresse locale de son image

  function lireFeuilles() { try { return JSON.parse(localStorage.getItem(CLE_FEUILLES)) || {}; } catch (e) { return {}; } }
  function ecrireFeuilles(f) { localStorage.setItem(CLE_FEUILLES, JSON.stringify(f)); }   // lève une erreur si le stockage est plein
  function listeClasses(texte) { return (texte || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean); }
  function echapper(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  /* Images des feuilles : IndexedDB (plusieurs Mo, contrairement au stockage texte) */
  function transactionImages(mode, faire) {
    return new Promise(function (resoudre, rejeter) {
      var demande = indexedDB.open('centrale-trombi', 1);
      demande.onupgradeneeded = function () { demande.result.createObjectStore('images'); };
      demande.onerror = function () { rejeter(demande.error); };
      demande.onsuccess = function () {
        var base = demande.result, t = base.transaction('images', mode), r = faire(t.objectStore('images'));
        t.oncomplete = function () { base.close(); resoudre(r ? r.result : undefined); };
        t.onerror = function () { base.close(); rejeter(t.error); };
      };
    });
  }
  function ecrireImage(nom, fichier) { return transactionImages('readwrite', function (s) { return s.put(fichier, nom); }); }
  function lireImage(nom) { return transactionImages('readonly', function (s) { return s.get(nom); }); }
  function supprimerImage(nom) { return transactionImages('readwrite', function (s) { return s.delete(nom); }); }

  /** Charge au démarrage les images de toutes les feuilles connues. */
  function chargerImages() {
    return Promise.all(Object.keys(lireFeuilles()).map(function (nom) {
      return lireImage(nom).then(function (fichier) { if (fichier) urlsFeuilles[nom] = URL.createObjectURL(fichier); });
    })).then(function () { afficherNoms(); }, function () {
      afficherMessage('Les images du trombinoscope ne sont pas disponibles dans ce navigateur.', true);
    });
  }

  /** Élèves d'une classe dans l'ordre alphabétique des noms : c'est l'ordre des photos dans la feuille. */
  function rangeClasse(classe) {
    var noms = lire()[classe] || {};
    return Object.keys(noms).map(function (k) { return { cle: k, nom: noms[k] || '' }; })
      .sort(function (a, b) { return a.nom.localeCompare(b.nom, 'fr', { numeric: true }) || a.cle.localeCompare(b.cle); });
  }

  /** Feuille qui contient cette classe (les noms de classes sont uniques entre établissements). */
  function feuilleDe(classe) {
    var f = lireFeuilles();
    return Object.keys(f).find(function (nom) { return listeClasses(f[nom].classes).indexOf(classe) >= 0; }) || null;
  }

  /**
   * Case de l'élève dans la feuille, ou null. Dans chaque classe, les élèves sont classés par nom
   * (ordre alphabétique du désanonymiseur), 10 par rangée, 3 rangées par classe.
   */
  /** Réglages d'une feuille : les valeurs jamais saisies prennent celles par défaut (comme dans le formulaire). */
  function reglagesFeuille(f) { return Object.assign({}, DEFAUTS_FEUILLE, f); }

  function caseVisage(classe, numero) {
    var nom = feuilleDe(classe);
    if (!nom || !urlsFeuilles[nom]) return null;
    var f = reglagesFeuille(lireFeuilles()[nom]);
    if (!f.photoL || !f.photoH || !f.largeur) return null;
    var k = listeClasses(f.classes).indexOf(classe);
    var rang = rangeClasse(classe).map(function (c) { return c.cle; }).indexOf(numero);
    if (k < 0 || rang < 0 || rang >= MAX_ELEVES) return null;
    return {
      image: urlsFeuilles[nom], sheetW: f.largeur, sheetH: f.hauteur,
      x: (f.x0 || 0) + (rang % COLONNES) * (f.pasX || 0),
      y: (f.y0 || 0) + (k * RANGEES + Math.floor(rang / COLONNES)) * (f.pasY || 0),
      l: f.photoL, h: f.photoH,
      fx: (f.faceX != null ? f.faceX : DEFAUTS_FEUILLE.faceX) / 100,
      fy: (f.faceY != null ? f.faceY : DEFAUTS_FEUILLE.faceY) / 100,
    };
  }

  /** Pourquoi cet élève n'a pas de photo (affiché au survol), ou '' s'il en a une. */
  function raisonSansPhoto(classe, numero) {
    if (caseVisage(classe, numero)) return '';
    var nom = feuilleDe(classe);
    if (!nom) return 'Classe absente de l’ordre de la feuille';
    if (!urlsFeuilles[nom]) return 'Image de la feuille « ' + nom + ' » non chargée';
    if (!(lire()[classe] || {})[numero]) return 'Nom non chargé pour cet élève (fichier des noms)';
    return 'Position hors de la feuille (plus de 30 élèves dans la classe ?)';
  }

  /** Affiche une case dans un cadre L × H px, à l'échelle s : le visage tombe au centre du cadre (bulles, vignettes). */
  function styleVisage(el, p, s, L, H) {
    el.style.width = L + 'px';
    el.style.height = H + 'px';
    el.style.backgroundImage = 'url("' + p.image + '")';
    el.style.backgroundRepeat = 'no-repeat';
    el.style.backgroundSize = (p.sheetW * s) + 'px ' + (p.sheetH * s) + 'px';
    el.style.backgroundPosition = (L / 2 - (p.x + p.fx * p.l) * s) + 'px ' + (H / 2 - (p.y + p.fy * p.h) * s) + 'px';
  }

  /** Classes renommées sur le serveur : on déplace les noms, PAP… et codes gardés ici vers le nouveau nom. */
  function appliquerRenommages() {
    var bloc = document.getElementById('json-renommages');
    if (!bloc) return;
    var liste = [];
    try { liste = JSON.parse(bloc.textContent) || []; } catch (e) { return; }
    [CLE_NOMS, CLE_BESOINS, CLE_CODES, CLE_SORTIES, CLE_STATUTS, CLE_CARTONS].forEach(function (cle) {
      var carte = lireCle(cle), change = false;
      liste.forEach(function (r) {
        if (!carte[r.ancien] || r.ancien === r.nouveau) return;
        carte[r.nouveau] = Object.assign({}, carte[r.ancien], carte[r.nouveau] || {});
        delete carte[r.ancien];
        change = true;
      });
      if (change) ecrireCle(cle, carte);
    });
  }

  function compter(carte) {
    return Object.keys(carte).reduce(function (n, c) { return n + Object.keys(carte[c]).length; }, 0);
  }

  /* Cartons : observations et récompenses de l'enseignant, gardés sur cet ordinateur */
  var TYPES_CARTONS = { observation: 'Observation', recompense: 'Récompense' };   // observation (jaune), récompense (vert)
  var AUTRE_MOTIF = 'Autre (précisez)';
  var MOTIFS_RECOMPENSES = ['Travail sérieux', 'Calme et sérieux', 'Fait des efforts sur son atelier', AUTRE_MOTIF];
  var MOTIFS_CARTONS = [   // motifs proposés ; le texte saisi est ajouté après le motif
    'Bavardage à voix haute', 'Bavardage en dehors de son groupe', 'Parle à voix haute sans demander la parole',
    'Crie en classe sans raison', 'Ne travaille pas sur son atelier', 'Se lève sans permission', AUTRE_MOTIF,
  ];
  function cartonsLocaux(classe, numero) {
    // les anciens avertissements s'affichent comme des observations
    return ((lireCle(CLE_CARTONS)[classe] || {})[numero] || []).slice()
      .map(function (c) { return c.type === 'avertissement' ? Object.assign({}, c, { type: 'observation' }) : c; });
  }
  function ecrireCartons(classe, numero, liste) {
    var carte = lireCle(CLE_CARTONS);
    carte[classe] = carte[classe] || {};
    if (liste.length) carte[classe][numero] = liste; else delete carte[classe][numero];
    ecrireCle(CLE_CARTONS, carte);
  }
  function ajouterCarton(classe, numero, type, texte) {
    var liste = cartonsLocaux(classe, numero);
    liste.push({ type: type, texte: texte, date: aujourdhui() });
    ecrireCartons(classe, numero, liste);
  }
  function supprimerCarton(classe, numero, index) {
    var liste = cartonsLocaux(classe, numero);
    liste.splice(index, 1);
    ecrireCartons(classe, numero, liste);
  }

  /* ---------- Affichage ---------- */

  function afficherNoms() {
    document.querySelectorAll('[data-nom-local]').forEach(function (el) {
      var nom = nomLocal(el.dataset.classe, el.dataset.numero);
      el.textContent = nom || (el.dataset.vide || '—');
      el.classList.toggle('inconnu', !nom);
      var suivant = el.nextElementSibling;
      if (suivant && suivant.classList.contains('besoins-local')) suivant.remove();
      var b = besoinsLocaux(el.dataset.classe, el.dataset.numero);
      if (b.length) el.after(pastilles(b));
    });
    var n = compter(lire());
    document.querySelectorAll('.etat-liste').forEach(function (el) {
      el.textContent = n
        ? 'Noms affichés depuis cet ordinateur (' + n + ' élève' + (n > 1 ? 's' : '') + ')'
        : 'Noms non chargés sur cet ordinateur';
    });
    document.querySelectorAll('.oublier-liste').forEach(function (el) { el.hidden = !n; });
    // Surnom choisi par l'élève qui ressemble à son vrai nom : on prévient l'enseignant (comparaison faite ici, en local)
    document.querySelectorAll('.surnom[data-surnom]').forEach(function (el) {
      var nom = nomLocal(el.dataset.classe, el.dataset.numero);
      var s = sansAccents(el.dataset.surnom).replace(/[^a-z0-9]/g, '');
      var morceaux = sansAccents(nom).split(/[^a-z0-9]+/).filter(function (m) { return m.length >= 3; });
      var ressemble = morceaux.some(function (m) { return s.indexOf(m) >= 0; });
      el.classList.toggle('ressemble-nom', ressemble);
      el.title = ressemble ? 'Ce surnom ressemble au nom de l’élève : utilisez ↻ pour lui en donner un autre.' : '';
    });
    afficherPhotos();
    afficherSorties();
    afficherCodes();
    trierEleves();
    trierTrombiAccueil();
    verifierCorrespondance();
    document.dispatchEvent(new Event('centrale-noms')); // ex. plan de classe : redessiner avec les noms
  }

  /** Fiche de l'élève en fenêtre : photo, nom, identifiant, état, mot de passe (masqué jusqu'à « Voir »). */
  var dialogueFiche = null;
  function ouvrirFiche(classe, numero, montrer) {
    if (!dialogueFiche) {
      dialogueFiche = document.createElement('dialog');
      dialogueFiche.className = 'dialogue-code dialogue-fiche';
      dialogueFiche.innerHTML = '<div class="fiche-haut"><span class="trombi-photo fiche-photo"></span>'
        + '<div><p class="dc-titre"></p><p class="dc-identifiant"></p><p class="fiche-etat"></p></div></div>'
        + '<p class="discret fiche-mdp-titre">Mot de passe</p>'
        + '<p class="dc-code"><span class="fiche-mdp"></span></p>'
        + '<p><button type="button" class="secondaire petit fiche-voir"></button></p>'
        + '<p class="discret">Donnez le mot de passe à l’élève, puis fermez cette fenêtre.</p>'
        + '<form method="dialog"><button class="petit">Fermer</button></form>';
      document.body.appendChild(dialogueFiche);
      dialogueFiche.querySelector('.fiche-voir').addEventListener('click', function () {
        afficherMdp(dialogueFiche.querySelector('.fiche-voir').textContent === 'Voir');
      });
    }
    var f = dialogueFiche;
    var surnom = Array.prototype.find.call(document.querySelectorAll('.surnom'), function (x) {
      return x.dataset.numero === numero && x.dataset.classe === classe;
    });
    var ligne = surnom ? surnom.closest('tr') : null;
    var statuts = statutsLocaux(classe, numero), desactive = !!(ligne && ligne.classList.contains('inactif'));
    var p = caseVisage(classe, numero), photo = f.querySelector('.fiche-photo');
    photo.classList.toggle('sans-photo', !p);
    if (p) styleVisage(photo, p, 120 / p.l, 120, 120); else photo.removeAttribute('style');
    f.querySelector('.dc-titre').textContent = nomLocal(classe, numero) || ('n° ' + numero);
    f.querySelector('.dc-identifiant').textContent = 'Classe ' + classe + ' · n° ' + numero + (surnom ? ' · identifiant ' + surnom.textContent.trim() : '');
    var etats = Object.keys(STATUTS).filter(function (k) { return statuts[k]; }).map(function (k) { return STATUTS[k] + ' depuis le ' + statuts[k]; });
    f.querySelector('.fiche-etat').textContent = (etats.length ? etats.join(' · ') : 'Présent') + (desactive ? ' · compte désactivé' : '');
    f.dataset.code = codesLocaux(classe)[numero] || '';
    afficherMdp(montrer);
    if (f.open) f.close();
    f.showModal();
  }

  /** Mot de passe de la fiche : masqué, ou affiché si le code est gardé sur cet ordinateur. */
  function afficherMdp(on) {
    var f = dialogueFiche, code = f.dataset.code, m = f.querySelector('.fiche-mdp'), bouton = f.querySelector('.fiche-voir');
    if (!code) {
      m.textContent = 'non enregistré sur cet ordinateur';
      bouton.hidden = true;
      return;
    }
    bouton.hidden = false;
    m.textContent = on ? code : '•••••••••';
    bouton.textContent = on ? 'Masquer' : 'Voir';
  }

  /** Mot de passe (code de connexion) : seulement la copie gardée sur cet ordinateur, masquée jusqu'au clic sur « Voir ». */
  function afficherCodes() {
    document.querySelectorAll('.code-local').forEach(function (span) {
      var code = codesLocaux(span.dataset.classe)[span.dataset.numero] || '';
      var bouton = span.nextElementSibling;
      span.dataset.code = code;
      if (code) {
        span.textContent = '•••••••••';
        span.title = '';
        if (bouton) bouton.hidden = false;
      } else {
        span.textContent = 'non enregistré ici';
        span.title = 'Le code n’est pas gardé sur cet ordinateur : utilisez « Nouveau code » sur la fiche de l’élève.';
        if (bouton) bouton.hidden = true;
      }
    });
  }

  /** Dates de sortie dans la liste des élèves ; les élèves sortis sont grisés. */
  function afficherSorties() {
    document.querySelectorAll('button.statut-pastille').forEach(function (bouton) {
      var statuts = statutsLocaux(bouton.dataset.classe, bouton.dataset.numero), date = statuts[bouton.dataset.statut];
      bouton.classList.toggle('actif', !!date);
      bouton.setAttribute('aria-pressed', date ? 'true' : 'false');
      bouton.title = date ? STATUTS[bouton.dataset.statut] + ' depuis le ' + date + ' : cliquer pour retirer' : STATUTS[bouton.dataset.statut] + ' : cliquer pour marquer';
      var ligne = bouton.closest('tr');
      if (ligne) ligne.classList.toggle('sorti', !!(statuts.sortie || statuts.exclu));
    });
  }

  /** Bulles du trombinoscope : le visage de chaque élève, centré. */
  function afficherPhotos() {
    // bulles du trombinoscope (72 px) et vignettes de la liste des élèves (40 px)
    document.querySelectorAll('.trombi-photo[data-numero]').forEach(function (cadre) {
      var p = caseVisage(cadre.dataset.classe, cadre.dataset.numero);
      var t = cadre.classList.contains('photo-eleve') ? 40 : cadre.classList.contains('photo-accueil') ? 56 : 72;
      cadre.classList.toggle('sans-photo', !p);
      if (p) styleVisage(cadre, p, t / p.l, t, t); else cadre.removeAttribute('style');
      cadre.title = p ? '' : raisonSansPhoto(cadre.dataset.classe, cadre.dataset.numero);
    });
  }

  // Accès aux noms locaux pour les autres scripts de l'espace enseignant (plan de classe)
  window.CentraleNoms = {
    nomLocal: nomLocal,
    sortie: sortieLocale,
    statuts: statutsLocaux,
    libellesStatuts: STATUTS,
    ajouter: function (classe, paires) { ajouter(classe, paires); afficherNoms(); },
    carte: function (classe) { return Object.assign({}, lire()[classe] || {}); },
    besoins: besoinsLocaux,
    typesBesoins: typesBesoins,
    pastilles: pastilles,
    ajouterBesoins: function (classe, paires) { ajouterBesoins(classe, paires); afficherNoms(); },
    ajouterCodes: ajouterCodes,
    codes: codesLocaux,
    visage: caseVisage,
    cartons: cartonsLocaux,
    typesCartons: TYPES_CARTONS,
    motifsCartons: MOTIFS_CARTONS,
    motifsRecompenses: MOTIFS_RECOMPENSES,
    autreMotif: AUTRE_MOTIF,
    ajouterCarton: function (c, n, t, x) { ajouterCarton(c, n, t, x); afficherNoms(); },
    supprimerCarton: function (c, n, i) { supprimerCarton(c, n, i); afficherNoms(); },
    raison: raisonSansPhoto,
    styleVisage: styleVisage,
    lireCSV: function (texte) { return lireCSV(texte); },
    telechargerCSV: function (nom, entete, lignes) { telechargerCSV(nom, entete, lignes); },
  };

  /** Message affiché dans la page (à la place d'alert(), que certains navigateurs intégrés n'affichent pas). */
  function afficherMessage(texte, erreur) {
    var m = document.createElement('p');
    m.className = 'message ' + (erreur ? 'erreur' : 'succes');
    m.setAttribute('role', 'status');
    m.textContent = texte;
    var main = document.querySelector('main');
    var ref = main && (main.querySelector('.liste-locale') || main.firstElementChild);
    if (ref) ref.parentNode.insertBefore(m, ref.nextSibling); else document.body.prepend(m);
    m.scrollIntoView({ block: 'nearest' });
    setTimeout(function () { m.remove(); }, 8000);
  }

  /* ---------- CSV ---------- */

  function sansAccents(s) {
    return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  }

  function lireCSV(texte) {
    texte = texte.replace(/^﻿/, '');
    var premiere = texte.split(/\r?\n/)[0] || '';
    var sep = premiere.indexOf(';') >= 0 ? ';' : (premiere.indexOf('\t') >= 0 ? '\t' : ',');
    var lignes = [], champ = '', ligne = [], guillemets = false;
    for (var i = 0; i < texte.length; i++) {
      var c = texte[i];
      if (guillemets) {
        if (c === '"' && texte[i + 1] === '"') { champ += '"'; i++; }
        else if (c === '"') { guillemets = false; }
        else { champ += c; }
      } else if (c === '"') { guillemets = true; }
      else if (c === sep) { ligne.push(champ); champ = ''; }
      else if (c === '\n' || c === '\r') {
        if (c === '\r' && texte[i + 1] === '\n') i++;
        ligne.push(champ); lignes.push(ligne); ligne = []; champ = '';
      } else { champ += c; }
    }
    if (champ !== '' || ligne.length) { ligne.push(champ); lignes.push(ligne); }
    return lignes.filter(function (l) { return l.some(function (v) { return v.trim() !== ''; }); });
  }

  /** Clé de comparaison d'un nom : sans accents, casse ni ordre (« Léa DUPONT » = « DUPONT Léa »). */
  function cleNom(s) {
    return sansAccents(s).replace(/[^a-z0-9]+/g, ' ').trim().split(' ').filter(Boolean).sort().join(' ');
  }

  /**
   * Compare les noms actuels de l'appli avec un export Pronote (colonnes « Élèves » et « Classe de rattachement »).
   * classeCible : si donnée, seule cette classe est examinée (la colonne de classe est alors ignorée).
   * Ne modifie rien : renvoie les propositions « avant → après ».
   */
  function analyserPronote(lignes, iEleves, iRatt, classeCible) {
    var serveur = [];
    try { serveur = JSON.parse(document.getElementById('json-classes').textContent) || []; } catch (e) { serveur = []; }
    var carte = lire(), propositions = [], ambigus = [], inconnus = [], sansNom = [], lues = 0, ignorees = 0, vues = {}, dejaFait = {};
    var parClasse = {};   // classe -> { clé du nom actuel -> [numéros] }
    serveur.forEach(function (c) {
      if (classeCible && c.nom !== classeCible) return;
      var idx = {};
      c.eleves.forEach(function (e) {
        var actuel = (carte[c.nom] || {})[e[0]];
        if (!actuel) return;
        (idx[cleNom(actuel)] = idx[cleNom(actuel)] || []).push(e[0]);
      });
      parClasse[c.nom] = idx;
    });
    lignes.slice(1).forEach(function (l) {
      var classe = classeCible || (iRatt >= 0 ? (l[iRatt] || '').trim() : ''), nomP = (l[iEleves] || '').trim();
      lues++;
      if (!nomP) { ignorees++; return; }
      if (classe) vues[classe] = true;
      // La classe du fichier sert à départager ; sans elle (ou classe inconnue), on cherche le nom dans toutes les classes
      var cle = cleNom(nomP), restreint = classe && parClasse[classe] ? classe : null, trouves = [];
      Object.keys(parClasse).forEach(function (c) {
        if (restreint && c !== restreint) return;
        (parClasse[c][cle] || []).forEach(function (num) { trouves.push({ classe: c, numero: num }); });
      });
      if (trouves.length === 1) {
        var t = trouves[0], cle2 = t.classe + '|' + t.numero;
        if (dejaFait[cle2]) return;
        dejaFait[cle2] = true;
        var avant = (carte[t.classe] || {})[t.numero] || '';
        if (avant !== nomP) propositions.push({ classe: t.classe, numero: t.numero, avant: avant, apres: nomP });
      } else if (trouves.length > 1) ambigus.push((classe || '?') + ' : ' + nomP);
      else inconnus.push((classe || '?') + ' : ' + nomP);
    });
    serveur.forEach(function (c) {
      if (!parClasse[c.nom]) return;
      c.eleves.forEach(function (e) {
        if (!(carte[c.nom] || {})[e[0]] && !dejaFait[c.nom + '|' + e[0]]) sansNom.push(c.nom + ' n° ' + e[0]);
      });
    });
    return { propositions: propositions, ambigus: ambigus, inconnus: inconnus, sansNom: sansNom, lues: lues, ignorees: ignorees, vues: vues };
  }

  /** Applique les propositions : le nom prend la forme du fichier Pronote. Numéros, codes et besoins ne changent pas. */
  function appliquerPropositions(propositions) {
    var carte = lire();
    propositions.forEach(function (p) {
      carte[p.classe] = carte[p.classe] || {};
      carte[p.classe][p.numero] = p.apres;
    });
    ecrire(carte);
  }

  /** Import global (liste de la classe) : applique directement les noms reconnus, puis affiche le bilan. */
  function importerPronote(lignes, iEleves, iRatt) {
    var a = analyserPronote(lignes, iEleves, iRatt, null);
    appliquerPropositions(a.propositions);
    var bilan = [a.lues + ' ligne(s) lue(s), ' + a.ignorees + ' ignorée(s) · classes du fichier : ' + (Object.keys(a.vues).join(', ') || 'aucune')];
    if (a.ambigus.length) bilan.push('Plusieurs élèves portent ce nom (à corriger à la main) : ' + a.ambigus.join(', '));
    if (a.inconnus.length) bilan.push('Non reconnus dans l’appli : ' + a.inconnus.join(', '));
    if (a.sansNom.length) bilan.push('Élèves de l’appli sans nom : ' + a.sansNom.join(', '));
    afficherMessage(bilan.join(' · '), true);
    return a.propositions.length;
  }

  function importerCSV(texte) {
    var lignes = lireCSV(texte);
    if (lignes.length < 2) throw new Error('Fichier vide ou illisible.');
    var entete = lignes[0].map(sansAccents);
    var iEleves = entete.findIndex(function (h) { return h === 'eleves'; });
    var iRatt = entete.findIndex(function (h) { return h === 'classe de rattachement'; });
    if (iEleves >= 0 && iRatt >= 0) return importerPronote(lignes, iEleves, iRatt);
    var iClasse = entete.findIndex(function (h) { return h === 'classe'; });
    var iNum = entete.findIndex(function (h) { return h === 'numero' || h === 'n°' || h === 'no' || h === 'num'; });
    var iNom = entete.findIndex(function (h) { return h === 'nom' || h === 'eleve' || h === 'nom prenom' || h === 'prenom nom'; });
    var iCode = entete.findIndex(function (h) { return h === 'code' || h === 'code eleve' || h === 'code de connexion'; });
    var iBesoins = entete.findIndex(function (h) { return /^besoins|accompagnement|^pap|^amenagements?$/.test(h); });
    var iSortie = entete.findIndex(function (h) { return h === 'sortie definitive'; });
    if (iClasse < 0 || iNum < 0 || iNom < 0) {
      throw new Error('Colonnes attendues : Classe ; Numéro ; Nom (le fichier téléchargé lors de la création des codes convient).');
    }
    var carte = lire(), n = 0, besoins = {}, codes = {}, sorties = {};
    lignes.slice(1).forEach(function (l) {
      var classe = (l[iClasse] || '').trim(), num = (l[iNum] || '').trim(), nom = (l[iNom] || '').trim();
      if (!classe || !num || !nom) return;
      if (/^\d$/.test(num)) num = '0' + num;
      carte[classe] = carte[classe] || {};
      carte[classe][num] = nom;
      if (iBesoins >= 0) (besoins[classe] = besoins[classe] || {})[num] = typesBesoins(l[iBesoins]);
      if (iCode >= 0 && /^[A-Za-z0-9]+(-[A-Za-z0-9]+){2,4}$/.test((l[iCode] || '').trim())) (codes[classe] = codes[classe] || {})[num] = l[iCode].trim();
      if (iSortie >= 0) (sorties[classe] = sorties[classe] || {})[num] = (l[iSortie] || '').trim();
      n++;
    });
    ecrire(carte);
    Object.keys(besoins).forEach(function (c) { ajouterBesoins(c, besoins[c]); });
    Object.keys(codes).forEach(function (c) { ajouterCodes(c, codes[c]); });
    if (iSortie >= 0) {
      var st = lireSorties();
      Object.keys(sorties).forEach(function (c) {
        Object.keys(sorties[c]).forEach(function (num) { setSortieIn(st, c, num, sorties[c][num]); });
      });
      ecrireCle(CLE_SORTIES, st);
    }
    appliquerRenommages();   // fichier ancien : classes renommées depuis
    return n;
  }

  function telechargerCSV(nomFichier, entete, lignes) {
    var csv = '﻿' + [entete].concat(lignes).map(function (l) {
      return l.map(function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; }).join(';');
    }).join('\r\n');
    telechargerFichier(nomFichier, csv, 'text/csv;charset=utf-8');
  }

  function telechargerFichier(nomFichier, contenu, type) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([contenu], { type: type }));
    a.download = nomFichier;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
  }

  function aujourdhui() { return new Date().toISOString().slice(0, 10); }

  /* ---------- Barre « liste locale » ---------- */

  appliquerRenommages();   // avant tout le reste : classes renommées depuis la dernière visite

  document.querySelectorAll('.charger-liste').forEach(function (input) {
    input.addEventListener('change', function () {
      var f = input.files[0];
      if (!f) return;
      f.text().then(function (texte) {
        try {
          var n = importerCSV(texte);
          afficherNoms();
          document.dispatchEvent(new Event('centrale-codes'));
          afficherMessage(n + ' nom' + (n > 1 ? 's' : '') + ' chargé' + (n > 1 ? 's' : '') + ' sur cet ordinateur.');
        } catch (e) { afficherMessage(e.message, true); }
        input.value = '';
      });
    });
  });

  document.querySelectorAll('.oublier-liste').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!confirmerEnLigne(b, "Effacer les noms, PAP… , codes et photos de ce navigateur ? Gardez bien votre fichier CSV (et celui des photos) : ce sont les seules copies.", b.parentNode)) return;
      try { [CLE_NOMS, CLE_BESOINS, CLE_CODES, CLE_SORTIES, CLE_STATUTS, CLE_CARTONS, CLE_FEUILLES, 'centrale-trombi', 'centrale-photos'].forEach(function (k) { localStorage.removeItem(k); }); } catch (e) { /* rien */ }
      try { indexedDB.deleteDatabase('centrale-trombi'); } catch (e) { /* rien */ }
      afficherNoms();
    });
  });

  /**
   * Liste globale : tous les établissements, toutes les classes, tous les élèves du serveur,
   * avec ce que cet ordinateur sait d'eux (nom, code, PAP / PPS / PAI). Un seul fichier « désanonymiseur ».
   */
  var ENTETE_LISTE = ['Sortie définitive', 'Établissement', 'Classe', 'Groupe', 'Numéro', 'Identifiant', 'Nom', 'Code', 'Besoins (PAP, PPS, PAI, PPRE)'];
  function lignesListeGlobale() {
    var serveur = [];
    try { serveur = JSON.parse(document.getElementById('json-classes').textContent) || []; } catch (e) { serveur = []; }
    var noms = lire(), codes = lireCle(CLE_CODES);
    if (!serveur.length) {   // secours : seulement ce qui est connu ici
      serveur = Object.keys(noms).map(function (c) { return { nom: c, etablissement: '', eleves: Object.keys(noms[c]).map(function (n) { return [n, '', '']; }) }; });
    }
    var tri = function (a, b) { return a.localeCompare(b, 'fr', { numeric: true }); };
    serveur.sort(function (a, b) {
      return (a.etablissement ? 0 : 1) - (b.etablissement ? 0 : 1) || tri(a.etablissement, b.etablissement) || tri(a.nom, b.nom);
    });
    var lignes = [];
    serveur.forEach(function (c) {
      c.eleves.slice().sort(function (a, b) { return tri(a[0], b[0]); }).forEach(function (e) {
        var num = e[0];
        lignes.push([sortieLocale(c.nom, num), c.etablissement, c.nom, e[2] || '', num, e[1], (noms[c.nom] || {})[num] || '', (codes[c.nom] || {})[num] || '',
          besoinsLocaux(c.nom, num).join(', ')]);
      });
    });
    return lignes;
  }
  function telechargerListeGlobale() {
    var lignes = lignesListeGlobale();
    if (!lignes.length) { afficherMessage('Aucun élève.', true); return false; }
    telechargerCSV('liste_eleves_codes_' + aujourdhui() + '.csv', ENTETE_LISTE, lignes);
    return true;
  }

  document.querySelectorAll('.exporter-liste').forEach(function (b) {
    b.addEventListener('click', telechargerListeGlobale);
  });

  /* ---------- Génération de codes : les noms restent ici ---------- */

  var formGen = document.getElementById('form-generer');
  if (formGen) {
    formGen.addEventListener('submit', function (ev) {
      var zone = document.getElementById('noms-locaux');
      var noms = zone.value.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
      if (noms.length) {
        if (noms.length > 60) { afficherMessage('60 élèves maximum à la fois.', true); ev.preventDefault(); return; }
        formGen.querySelector('[name=nombre]').value = noms.length;
        try {
          sessionStorage.setItem(CLE_ATTENTE, JSON.stringify({ classe: formGen.dataset.classe, noms: noms }));
        } catch (e) { /* rien */ }
      } else {
        try { sessionStorage.removeItem(CLE_ATTENTE); } catch (e) { /* rien */ }
      }
      // La zone des noms n'a pas d'attribut "name" : elle n'est jamais envoyée au serveur.
    });
  }

  /* ---------- Codes tout juste générés ---------- */

  var blocNouveaux = document.getElementById('json-nouveaux');
  if (blocNouveaux) {
    var info = JSON.parse(blocNouveaux.textContent);
    var attente = null;
    try { attente = JSON.parse(sessionStorage.getItem(CLE_ATTENTE)); sessionStorage.removeItem(CLE_ATTENTE); } catch (e) { /* rien */ }
    if (attente && attente.classe === info.classe && attente.noms.length === info.codes.length) {
      var paires = {};
      info.codes.forEach(function (c, i) { paires[c.numero] = attente.noms[i]; });
      ajouter(info.classe, paires);
    }
    var copie = {};
    info.codes.forEach(function (c) { copie[c.numero] = c.code; });
    ajouterCodes(info.classe, copie);   // copie locale : pour réimprimer les fiches plus tard
    // Nouvel élève (formulaire « Ajouter un élève ») : nom, besoins et sortie gardés ici
    var ajout = null;
    try { ajout = JSON.parse(sessionStorage.getItem(CLE_ATTENTE_ELEVE)); sessionStorage.removeItem(CLE_ATTENTE_ELEVE); } catch (e) { /* rien */ }
    if (ajout && ajout.classe === info.classe && info.codes.length === 1) {
      var nouveau = info.codes[0].numero, paire = {};
      paire[nouveau] = ajout.nom;
      ajouter(info.classe, paire);
      var besoins = {};
      besoins[nouveau] = ajout.besoins || [];
      ajouterBesoins(info.classe, besoins);
      if (ajout.sortie) setSortie(info.classe, nouveau, aujourdhui());
      afficherNoms();
    }
    var boutonCSV = document.getElementById('telecharger-codes');
    if (boutonCSV) {
      boutonCSV.addEventListener('click', function () {
        telechargerCSV('codes_' + info.prefixe + '_' + aujourdhui() + '.csv', ['Classe', 'Numéro', 'Nom', 'Code'],
          info.codes.map(function (c) { return [info.classe, c.numero, nomLocal(info.classe, c.numero), c.code]; }));
      });
    }
  }

  /* ---------- Classe anonymisée : correspondance ancien nom → numéro ---------- */

  var blocAnonym = document.getElementById('json-anonymisation');
  if (blocAnonym) {
    var an = JSON.parse(blocAnonym.textContent);
    var p = {};
    an.paires.forEach(function (x) { p[x.numero] = x.nom; });
    ajouter(an.classe, p);
    var b = document.getElementById('telecharger-anonymisation');
    var dl = function () {
      telechargerCSV('correspondance_' + an.prefixe + '_' + aujourdhui() + '.csv', ['Classe', 'Numéro', 'Nom'],
        an.paires.map(function (x) { return [an.classe, x.numero, x.nom]; }));
    };
    if (b) b.addEventListener('click', dl);
    dl();
  }

  /* ---------- Trombinoscope : feuilles (une image par établissement), gardées sur cet ordinateur ---------- */

  var CHAMPS_FEUILLE = [
    ['x0', 'Position de la 1re photo : X (px)'], ['y0', 'Position de la 1re photo : Y (px)'],
    ['pasX', 'Écart horizontal entre deux photos (px)'], ['pasY', 'Écart vertical entre deux rangées (px)'],
    ['photoL', 'Taille de la photo : largeur (px)'], ['photoH', 'Taille de la photo : hauteur (px)'],
    ['faceX', 'Centre du visage : X (%)'], ['faceY', 'Centre du visage : Y (%)'],
  ];
  // Les images des feuilles servent aussi au plan de classe et à l'accueil : elles se chargent sur toutes les pages.
  // Quand elles arrivent, afficherNoms() prévient les plans (événement centrale-noms) qui se redessinent avec les photos.
  if (!document.getElementById('feuilles')) chargerImages();
  var zoneFeuilles = document.getElementById('feuilles');
  if (zoneFeuilles) {
    var erreurFeuille = function (e) { afficherMessage('Enregistrement impossible (' + ((e && e.message) || 'stockage plein ?') + ').', true); };

    /** Pour chaque classe de la feuille : combien d'élèves ont un nom chargé (sans noms, pas de photo). */
    function etatFeuille(c) {
      var lignes = listeClasses(c.classes).map(function (cl) {
        var n = Object.keys(lire()[cl] || {}).length;
        return cl + ' : ' + (n ? n + ' élève(s) avec nom' : 'noms non chargés (ou nom de classe différent)');
      });
      return '<p class="aide etat-feuille">' + (lignes.length ? lignes.map(echapper).join(' · ') : 'Aucune classe saisie pour cette feuille.') + '</p>';
    }

    function dessinerFeuilles() {
      var f = lireFeuilles();
      zoneFeuilles.innerHTML = Object.keys(f).map(function (nom) {
        var c = f[nom];
        return '<div class="feuille carte" data-feuille="' + echapper(nom) + '">'
          + '<div class="ligne-actions"><h3>Feuille « ' + echapper(nom) + ' »</h3>'
          + '<span class="discret">' + (urlsFeuilles[nom] ? 'Image : ' + c.largeur + ' × ' + c.hauteur + ' px' : 'Aucune image chargée') + '</span>'
          + '<button type="button" class="lien-bouton ouvrir-image">Charger l’image (JPG ou PNG)</button>'
          + '<input type="file" accept="image/*" class="charger-feuille" hidden>'
          + '<button type="button" class="lien-bouton supprimer-feuille">Supprimer la feuille</button></div>'
          + '<label class="champ-large">Ordre des classes (de haut en bas dans l’image, séparées par des virgules)'
          + '<input type="text" data-champ="classes" value="' + echapper(c.classes || '') + '"></label>'
          + '<div class="trombi-champs">' + CHAMPS_FEUILLE.map(function (ch) {
              var v = c[ch[0]] != null ? c[ch[0]] : DEFAUTS_FEUILLE[ch[0]];
              return '<label>' + ch[1] + ' <input type="number" step="1" data-champ="' + ch[0] + '" value="' + v + '"></label>';
            }).join('') + '</div>'
          + etatFeuille(c)
          + '</div>';
      }).join('') || '<p class="aide">Aucune feuille. Ajoutez une feuille par établissement.</p>';
    }

    function modifierFeuille(nom, modif) {
      try { var f = lireFeuilles(); modif(f[nom]); ecrireFeuilles(f); } catch (e) { erreurFeuille(e); }
      afficherNoms();
      dessinerFeuilles();
    }

    function chargerImage(nom, fichier) {
      var adresse = URL.createObjectURL(fichier), img = new Image();
      img.onload = function () {
        ecrireImage(nom, fichier).then(function () {
          if (urlsFeuilles[nom]) URL.revokeObjectURL(urlsFeuilles[nom]);
          urlsFeuilles[nom] = adresse;
          modifierFeuille(nom, function (c) { c.largeur = img.naturalWidth; c.hauteur = img.naturalHeight; });
          afficherMessage('Image de la feuille « ' + nom + ' » enregistrée (' + img.naturalWidth + ' × ' + img.naturalHeight + ' px).');
        }).catch(erreurFeuille);
      };
      img.onerror = function () { afficherMessage('Image illisible.', true); };
      img.src = adresse;
    }

    zoneFeuilles.addEventListener('change', function (ev) {
      var cible = ev.target, bloc = cible.closest('[data-feuille]'), nom = bloc && bloc.dataset.feuille;
      if (!nom) return;
      if (cible.classList.contains('charger-feuille')) {
        var fichier = cible.files[0];
        cible.value = '';
        if (fichier) chargerImage(nom, fichier);
      } else if (cible.dataset.champ) {
        modifierFeuille(nom, function (c) {
          var k = cible.dataset.champ;
          c[k] = k === 'classes' ? cible.value.trim() : (parseFloat(cible.value) || 0);
        });
      }
    });
    zoneFeuilles.addEventListener('click', function (ev) {
      var ouvrir = ev.target.closest('.ouvrir-image');
      if (ouvrir) { ouvrir.closest('[data-feuille]').querySelector('.charger-feuille').click(); return; }
      var b = ev.target.closest('.supprimer-feuille');
      if (!b) return;
      if (!confirmerEnLigne(b, 'Supprimer cette feuille et son image sur cet ordinateur ?', b.parentNode)) return;
      var nom = b.closest('[data-feuille]').dataset.feuille;
      try { var f = lireFeuilles(); delete f[nom]; ecrireFeuilles(f); } catch (e) { erreurFeuille(e); }
      supprimerImage(nom).catch(erreurFeuille);
      if (urlsFeuilles[nom]) { URL.revokeObjectURL(urlsFeuilles[nom]); delete urlsFeuilles[nom]; }
      afficherNoms();
      dessinerFeuilles();
    });

    document.getElementById('ajouter-feuille').addEventListener('click', function () {
      var champ = document.getElementById('nouvelle-feuille'), nom = champ.value.trim();
      if (!nom) { afficherMessage('Donnez un nom à la feuille (par exemple « Hautier »).', true); return; }
      var f = lireFeuilles();
      if (f[nom]) { afficherMessage('Cette feuille existe déjà.', true); return; }
      var cle = Object.keys(PRESET_FEUILLES).find(function (k) { return nom.toLowerCase().indexOf(k) >= 0; });
      f[nom] = Object.assign({ classes: cle ? PRESET_FEUILLES[cle] : '' }, DEFAUTS_FEUILLE);
      try { ecrireFeuilles(f); } catch (e) { erreurFeuille(e); return; }
      champ.value = '';
      dessinerFeuilles();
    });

    dessinerFeuilles();
    chargerImages().then(dessinerFeuilles);
    // Quand le fichier des noms change (chargé après l'ouverture de la page), l'état des classes est mis à jour
    document.addEventListener('centrale-noms', dessinerFeuilles);
  }

  /* ---------- Trombinoscope : ordre des élèves dans la feuille (pour vérifier une position) ---------- */

  function dessinerOrdre() {
    var zone = document.getElementById('ordre-photos');
    if (!zone) return;
    var liste = rangeClasse(zone.dataset.classe);
    zone.querySelector('.ordre-liste').innerHTML = liste.map(function (c, i) {
      return '<li' + (i >= MAX_ELEVES ? ' class="hors"' : '') + '>' + echapper(c.nom || '(sans nom)') + ' · n° ' + echapper(c.cle) + '</li>';
    }).join('') || '<li>Aucun nom chargé pour cette classe.</li>';
  }
  try { localStorage.removeItem('centrale-absents'); } catch (e) { /* rien */ }   // ancienne fonction retirée
  document.addEventListener('centrale-noms', dessinerOrdre);
  dessinerOrdre();

  /* ---------- Sorties définitives : date saisie dans la liste des élèves ---------- */

  document.addEventListener('click', function (ev) {
    var mdp = ev.target.closest && ev.target.closest('button.voir-mdp');
    if (mdp) { ouvrirFiche(mdp.dataset.classe, mdp.dataset.numero, true); return; }
    var voir = ev.target.closest && ev.target.closest('button.voir-code');
    if (voir) { var code = voir.previousElementSibling; ouvrirFiche(code.dataset.classe, code.dataset.numero, true); return; }
    var photoEleve = ev.target.closest && ev.target.closest('.trombi-photo[data-numero]');
    if (photoEleve) { ouvrirFiche(photoEleve.dataset.classe, photoEleve.dataset.numero, false); return; }
    var bouton = ev.target.closest && ev.target.closest('button.statut-pastille');
    if (!bouton) return;
    var statut = bouton.dataset.statut, actuel = statutsLocaux(bouton.dataset.classe, bouton.dataset.numero)[statut];
    setStatut(bouton.dataset.classe, bouton.dataset.numero, statut, actuel ? '' : aujourdhui());   // la date du jour est gardée automatiquement
    afficherNoms();   // met à jour les pastilles, les plans et le trombinoscope
  });

  /* ---------- Formulaire « Ajouter un élève » : le nom et les besoins sont attendus au retour avec le code ---------- */

  var formAjout = document.getElementById('form-ajout-eleve');
  if (formAjout) {
    formAjout.addEventListener('submit', function (ev) {
      var nom = document.getElementById('ae-nom').value.trim(), prenom = document.getElementById('ae-prenom').value.trim();
      if (!nom) { ev.preventDefault(); afficherMessage('Indiquez le nom de l’élève.', true); return; }
      var attente = {
        classe: formAjout.dataset.classe,
        nom: nom.toUpperCase() + (prenom ? ' ' + prenom : ''),
        besoins: ['PPS', 'PAP', 'PAI', 'PPRE'].filter(function (t) { return document.getElementById('ae-' + t.toLowerCase()).checked; }),
        sortie: document.getElementById('ae-sortie').checked,
      };
      try { sessionStorage.setItem(CLE_ATTENTE_ELEVE, JSON.stringify(attente)); } catch (e) { /* stockage bloqué */ }
    });
  }

  /* ---------- Liste des élèves : ordre alphabétique des noms (le numéro ne change pas) ---------- */

  function trierEleves() {
    var corps = document.querySelector('#eleves tbody');
    if (!corps) return;
    var lignes = Array.prototype.filter.call(corps.querySelectorAll('tr'), function (tr) { return tr.querySelector('[data-numero]'); });
    if (!lignes.length) return;
    var infos = lignes.map(function (tr) {
      var d = tr.querySelector('[data-numero]');
      return { tr: tr, numero: d.dataset.numero, nom: nomLocal(d.dataset.classe, d.dataset.numero) };
    });
    infos.sort(function (a, b) {
      if (!!a.nom !== !!b.nom) return a.nom ? -1 : 1;   // les élèves sans nom restent à la fin
      return (a.nom.localeCompare(b.nom, 'fr', { numeric: true }) || a.numero.localeCompare(b.numero, 'fr', { numeric: true }));
    });
    infos.forEach(function (i) { corps.appendChild(i.tr); });
  }

  /* Trombinoscope de l'accueil : élèves dans l'ordre alphabétique, comme la feuille */
  function trierTrombiAccueil() {
    document.querySelectorAll('ul.trombi-accueil').forEach(function (liste) {
      var items = Array.prototype.slice.call(liste.querySelectorAll('li[data-numero]'));
      items.sort(function (a, b) {
        var na = nomLocal(a.querySelector('.trombi-photo').dataset.classe, a.dataset.numero), nb = nomLocal(b.querySelector('.trombi-photo').dataset.classe, b.dataset.numero);
        return (na || '\uffff').localeCompare(nb || '\uffff', 'fr', { numeric: true }) || a.dataset.numero.localeCompare(b.dataset.numero);
      });
      items.forEach(function (li) { liste.appendChild(li); });
    });
  }

  /* ---------- Vérification : noms, codes et identifiants élève par élève ---------- */

  function verifierCorrespondance() {
    var zone = document.getElementById('verif-resultat');
    if (!zone) return;
    var classe = zone.dataset.classe, prefixe = zone.dataset.prefixe;
    var lignes = Array.prototype.filter.call(document.querySelectorAll('#eleves tbody tr'), function (tr) { return tr.querySelector('[data-numero]'); });
    var noms = lire()[classe] || {}, codes = codesLocaux(classe);
    var numeros = lignes.map(function (tr) { return tr.querySelector('[data-numero]').dataset.numero; });
    var problemes = [], vues = {};
    var tableau = lignes.map(function (tr) {
      var numero = tr.querySelector('[data-numero]').dataset.numero;
      var surnom = tr.querySelector('.surnom'), identifiant = surnom ? surnom.textContent.trim() : '';
      var nom = noms[numero] || '', code = codes[numero] || '';
      if (!nom) problemes.push('n° ' + numero + ' : pas de nom sur cet ordinateur');
      else if (vues[nom.toLowerCase()]) problemes.push('n° ' + numero + ' : même nom que n° ' + vues[nom.toLowerCase()]);
      else vues[nom.toLowerCase()] = numero;
      if (!code) problemes.push('n° ' + numero + ' : code non gardé sur cet ordinateur');
      else if (code.indexOf(prefixe + '-') !== 0) problemes.push('n° ' + numero + ' : le code ne commence pas par ' + prefixe + '-');
      if (!identifiant) problemes.push('n° ' + numero + ' : identifiant introuvable dans la liste');
      return '<tr><td>' + echapper(numero) + '</td><td>' + echapper(identifiant) + '</td><td>' + echapper(nom || '—')
        + '</td><td>' + (code ? '✓' : '✗') + '</td></tr>';
    }).join('');
    Object.keys(noms).forEach(function (n) { if (numeros.indexOf(n) < 0) problemes.push('n° ' + n + ' : nom sur cet ordinateur, aucun élève à ce numéro sur le serveur'); });
    Object.keys(codes).forEach(function (n) { if (numeros.indexOf(n) < 0) problemes.push('n° ' + n + ' : code gardé ici, aucun élève à ce numéro sur le serveur'); });
    zone.innerHTML = '<p class="' + (problemes.length ? 'alerte' : 'ok') + '">'
      + (problemes.length ? problemes.length + ' point(s) à vérifier' : 'Tout correspond : noms, codes et identifiants') + '</p>'
      + (problemes.length ? '<ul>' + problemes.map(function (p) { return '<li>' + echapper(p) + '</li>'; }).join('') + '</ul>' : '')
      + '<div class="defile"><table><thead><tr><th>N°</th><th>Identifiant</th><th>Nom (ici)</th><th>Code ici</th></tr></thead><tbody>' + tableau + '</tbody></table></div>';
  }

  /* ---------- Réinitialiser la classe : suppression locale + serveur, puis recréation depuis le fichier Pronote ---------- */

  var zoneReinit = document.getElementById('reinit-apercu');
  if (zoneReinit) {
    var classeR = zoneReinit.dataset.classe, formR = document.getElementById('form-reinit'), listeR = [];

    // Au retour de la réinitialisation : les noms sont prêts dans « Générer des codes »
    try {
      var attente = JSON.parse(sessionStorage.getItem('centrale-reinit'));
      if (attente && attente.classe === classeR) {
        sessionStorage.removeItem('centrale-reinit');
        var zoneNoms = document.getElementById('noms-locaux');
        if (zoneNoms) zoneNoms.value = attente.noms.join('\n');
        afficherMessage(attente.noms.length + ' nom(s) prêts : cliquez sur « Générer les codes » pour recréer les élèves dans l’ordre alphabétique.');
      }
    } catch (e) { /* rien */ }

    document.getElementById('reinit-fichier').addEventListener('change', function (ev) {
      var input = ev.target, f = input.files[0];
      input.value = '';
      if (!f) return;
      f.text().then(function (texte) {
        var lignes = lireCSV(texte);
        if (lignes.length < 2) throw new Error('Fichier vide ou illisible.');
        var entete = lignes[0].map(sansAccents);
        var iEleves = entete.findIndex(function (h) { return h === 'eleves'; });
        var iRatt = entete.findIndex(function (h) { return h === 'classe de rattachement'; });
        if (iEleves < 0) throw new Error('Colonne « Élèves » introuvable : il faut l’export Pronote.');
        listeR = [];
        lignes.slice(1).forEach(function (l) {
          var nom = (l[iEleves] || '').trim(), cl = iRatt >= 0 ? (l[iRatt] || '').trim() : '';
          if (!nom || (cl && cl !== classeR)) return;   // les autres classes du fichier sont ignorées
          listeR.push(nom);
        });
        if (!listeR.length) throw new Error('Aucun élève de la classe ' + classeR + ' dans ce fichier.');
        listeR.sort(function (a, b) { return a.localeCompare(b, 'fr', { numeric: true }); });
        zoneReinit.innerHTML = '<p><strong>' + listeR.length + ' élève(s)</strong> seront recréés pour la classe ' + echapper(classeR) + ', dans cet ordre :</p>'
          + '<ol>' + listeR.map(function (n) { return '<li>' + echapper(n) + '</li>'; }).join('') + '</ol>';
        formR.hidden = false;
      }).catch(function (e) { afficherMessage(e.message, true); });
    });

    formR.addEventListener('submit', function (ev) {
      if (formR.confirmation.value.trim() !== classeR) {
        ev.preventDefault();
        afficherMessage('Tapez exactement « ' + classeR + ' » pour confirmer.', true);
        return;
      }
      // Effacement sur cet ordinateur : noms, besoins, codes et sorties de la classe
      var carte = lire(); delete carte[classeR]; ecrire(carte);
      var besoins = lireBesoins(); delete besoins[classeR]; ecrireBesoins(besoins);
      var codes = lireCle(CLE_CODES); delete codes[classeR]; ecrireCle(CLE_CODES, codes);
      var sorties = lireSorties(); delete sorties[classeR]; ecrireCle(CLE_SORTIES, sorties);
      var cartons = lireCle(CLE_CARTONS); delete cartons[classeR]; ecrireCle(CLE_CARTONS, cartons);
      var statuts = lireCle(CLE_STATUTS); delete statuts[classeR]; ecrireCle(CLE_STATUTS, statuts);
      try { sessionStorage.setItem('centrale-reinit', JSON.stringify({ classe: classeR, noms: listeR })); } catch (e) { /* stockage bloqué */ }
    });
  }

  /* ---------- Corriger les noms de la classe depuis un fichier Pronote (aperçu avant application) ---------- */

  var zoneCorriger = document.getElementById('corriger-resultat');
  if (zoneCorriger) {
    var classeCorriger = zoneCorriger.dataset.classe, enAttente = [];

    function montrerPropositions(a) {
      enAttente = a.propositions;
      var sansNomClasse = a.sansNom.filter(function (x) { return x.indexOf(classeCorriger + ' n° ') === 0; });
      var html = '<p>' + a.lues + ' ligne(s) lue(s) · ' + a.propositions.length + ' nom(s) à corriger</p>';
      if (a.propositions.length) {
        html += '<ul class="propositions">' + a.propositions.map(function (p) {
          return '<li>n° ' + echapper(p.numero) + ' : <s>' + echapper(p.avant || '(aucun nom)') + '</s> → <strong>' + echapper(p.apres) + '</strong></li>';
        }).join('') + '</ul>'
          + '<div class="ligne-actions"><button type="button" class="petit" id="corriger-appliquer">Appliquer ces ' + a.propositions.length + ' correction(s)</button></div>';
      }
      if (a.inconnus.length) html += '<p class="alerte">Non reconnus dans l’appli : ' + echapper(a.inconnus.join(', ')) + '</p>';
      if (a.ambigus.length) html += '<p class="alerte">Noms en double (à corriger à la main) : ' + echapper(a.ambigus.join(', ')) + '</p>';
      if (sansNomClasse.length) html += '<p class="alerte">Élèves sans nom sur cet ordinateur : ' + sansNomClasse.length + ' (la comparaison ne peut pas les corriger ; utilisez une sauvegarde avec les numéros).</p>';
      zoneCorriger.innerHTML = html;
    }

    document.getElementById('corriger-fichier').addEventListener('change', function (ev) {
      var input = ev.target, f = input.files[0];
      input.value = '';
      if (!f) return;
      f.text().then(function (texte) {
        var lignes = lireCSV(texte);
        if (lignes.length < 2) throw new Error('Fichier vide ou illisible.');
        var entete = lignes[0].map(sansAccents);
        var iEleves = entete.findIndex(function (h) { return h === 'eleves'; });
        if (iEleves < 0) throw new Error('Colonne « Élèves » introuvable : il faut l’export Pronote.');
        montrerPropositions(analyserPronote(lignes, iEleves, -1, classeCorriger));
      }).catch(function (e) { afficherMessage(e.message, true); });
    });
    zoneCorriger.addEventListener('click', function (ev) {
      if (!ev.target.closest('#corriger-appliquer')) return;
      appliquerPropositions(enAttente);
      afficherNoms();
      zoneCorriger.innerHTML = '<p class="ok">' + enAttente.length + ' nom(s) corrigé(s) : format « NOM Prénom » du fichier Pronote.</p>';
      enAttente = [];
    });
  }

  /* ---------- Archive ZIP : noms, codes, besoins, sorties, feuilles et images (fichier .deanonim) ---------- */

  var TABLE_CRC = (function () {
    var t = [];
    for (var n = 0; n < 256; n++) {
      var c = n;
      for (var k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
      t[n] = c >>> 0;
    }
    return t;
  })();
  function crc32(octets) {
    var c = 0xFFFFFFFF;
    for (var i = 0; i < octets.length; i++) c = TABLE_CRC[(c ^ octets[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
  }
  function extensionImage(type) { return { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' }[type] || 'img'; }
  function typeImage(nom) { return { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp' }[(nom.split('.').pop() || '').toLowerCase()] || 'application/octet-stream'; }

  /** Écrit une archive ZIP (sans compression) : entrées = [{ nom, octets }]. */
  function ecrireZip(entrees) {
    var enc = new TextEncoder(), parties = [], centrale = [], decalage = 0;
    entrees.forEach(function (e) {
      var nom = enc.encode(e.nom), crc = crc32(e.octets), taille = e.octets.length;
      var h = new DataView(new ArrayBuffer(30));
      h.setUint32(0, 0x04034b50, true); h.setUint16(4, 20, true); h.setUint16(6, 0x0800, true); h.setUint16(8, 0, true);
      h.setUint16(10, 0, true); h.setUint16(12, 0x21, true); h.setUint32(14, crc, true);
      h.setUint32(18, taille, true); h.setUint32(22, taille, true); h.setUint16(26, nom.length, true); h.setUint16(28, 0, true);
      parties.push(new Uint8Array(h.buffer), nom, e.octets);
      var c = new DataView(new ArrayBuffer(46));
      c.setUint32(0, 0x02014b50, true); c.setUint16(4, 20, true); c.setUint16(6, 20, true); c.setUint16(8, 0x0800, true);
      c.setUint16(10, 0, true); c.setUint16(12, 0, true); c.setUint16(14, 0x21, true); c.setUint32(16, crc, true);
      c.setUint32(20, taille, true); c.setUint32(24, taille, true); c.setUint16(28, nom.length, true);
      c.setUint32(42, decalage, true);
      centrale.push(new Uint8Array(c.buffer), nom);
      decalage += 30 + nom.length + taille;
    });
    var taillerCentrale = centrale.reduce(function (s, p) { return s + p.length; }, 0);
    var fin = new DataView(new ArrayBuffer(22));
    fin.setUint32(0, 0x06054b50, true); fin.setUint16(8, entrees.length, true); fin.setUint16(10, entrees.length, true);
    fin.setUint32(12, taillerCentrale, true); fin.setUint32(16, decalage, true);
    return new Blob(parties.concat(centrale, [new Uint8Array(fin.buffer)]), { type: 'application/zip' });
  }

  /** Décompresse une entrée deflate (ZIP méthode 8) avec le navigateur. */
  function decompresser(octets) {
    var flux = new Blob([octets]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
    return new Response(flux).arrayBuffer().then(function (b) { return new Uint8Array(b); });
  }

  /** Lit une archive ZIP : renvoie { nom: Uint8Array }. */
  function lireZip(buf) {
    var dv = new DataView(buf), u8 = new Uint8Array(buf), fin = -1;
    for (var i = buf.byteLength - 22; i >= Math.max(0, buf.byteLength - 66000); i--) {
      if (dv.getUint32(i, true) === 0x06054b50) { fin = i; break; }
    }
    if (fin < 0) return Promise.reject(new Error('Archive ZIP illisible.'));
    var nombre = dv.getUint16(fin + 10, true), p = dv.getUint32(fin + 16, true), dec = new TextDecoder('utf-8');
    var attentes = [], res = {};
    for (var k = 0; k < nombre; k++) {
      if (dv.getUint32(p, true) !== 0x02014b50) return Promise.reject(new Error('Archive ZIP illisible.'));
      var methode = dv.getUint16(p + 10, true), csz = dv.getUint32(p + 20, true), nl = dv.getUint16(p + 28, true);
      var el2 = dv.getUint16(p + 30, true), cl = dv.getUint16(p + 32, true), off = dv.getUint32(p + 42, true);
      var nom = dec.decode(u8.subarray(p + 46, p + 46 + nl));
      var debut = off + 30 + dv.getUint16(off + 26, true) + dv.getUint16(off + 28, true);
      (function (n, d, m) {
        var octets = u8.subarray(d, d + csz);
        if (m === 0) res[n] = octets;
        else if (m === 8) attentes.push(decompresser(octets).then(function (o) { res[n] = o; }));
      })(nom, debut, methode);
      p += 46 + nl + el2 + cl;
    }
    return Promise.all(attentes).then(function () { return res; });
  }

  /** Confirmation qui reste à l'écran jusqu'à ce qu'on la ferme : titre et détails (classes, élèves, trombinoscopes…). */
  function confirmerSucces(titre, lignes) {
    var t = document.createElement('div');
    t.className = 'toast-succes';
    t.setAttribute('role', 'status');
    var fermer = document.createElement('button');
    fermer.type = 'button';
    fermer.className = 'toast-fermer';
    fermer.textContent = '×';
    fermer.setAttribute('aria-label', 'Fermer');
    fermer.addEventListener('click', function () { t.remove(); });
    var h = document.createElement('p');
    h.className = 'toast-titre';
    h.textContent = '✔ ' + titre;
    var liste = document.createElement('ul');
    lignes.forEach(function (l) { var li = document.createElement('li'); li.textContent = l; liste.appendChild(li); });
    t.append(fermer, h, liste);
    document.querySelectorAll('.toast-succes').forEach(function (vieux) { vieux.remove(); });
    document.body.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.remove(); }, 90000);
  }

  /** Bilan d'une sauvegarde : ce qu'elle contient (classes désanonymisées, élèves avec un nom, photos, cartons, codes). */
  function bilanSauvegarde(d, nbImages) {
    var noms = d.noms || {}, classes = Object.keys(noms);
    var elevesAvecNom = classes.reduce(function (n, c) {
      return n + Object.keys(noms[c] || {}).filter(function (k) { return noms[c][k]; }).length;
    }, 0);
    var cartons = Object.keys(d.cartons || {}).reduce(function (n, c) {
      return n + Object.keys(d.cartons[c] || {}).reduce(function (m, k) { return m + (d.cartons[c][k] || []).length; }, 0);
    }, 0);
    var codes = Object.keys(d.codes || {}).reduce(function (n, c) { return n + Object.keys(d.codes[c] || {}).length; }, 0);
    var lignes = [
      classes.length + ' classe(s) désanonymisée(s) : ' + elevesAvecNom + ' élève(s) avec leur nom'
        + (classes.length ? ' (' + classes.slice(0, 8).join(', ') + (classes.length > 8 ? '…' : '') + ')' : ''),
      nbImages + ' trombinoscope(s) de photos',
      cartons + ' carton(s) : observations et récompenses',
      codes + ' code(s) de connexion',
    ];
    return lignes;
  }

  /** Image (blob) en texte : une adresse « data: » que l'on peut relire et recopier. */
  function lireDataUrl(blob) {
    return new Promise(function (ok, ko) {
      var lecteur = new FileReader();
      lecteur.onload = function () { ok(lecteur.result); };
      lecteur.onerror = function () { ko(lecteur.error); };
      lecteur.readAsDataURL(blob);
    });
  }

  /** Sauvegarde : un seul fichier « centrale_AAAA-MM-JJ.desanonim » (texte lisible), trombinoscopes compris. */
  function exporterTout() {
    var feuilles = lireFeuilles(), images = {};
    var lectures = Object.keys(feuilles).map(function (nom) {
      return lireImage(nom).then(function (blob) {
        if (blob) return lireDataUrl(blob).then(function (adresse) { images[nom] = adresse; });
      });
    });
    return Promise.all(lectures).then(function () {
      var donnees = {
        format: 'centrale-deanonim', version: 2, exporte_le: aujourdhui(),
        noms: lire(), besoins: lireBesoins(), codes: lireCle(CLE_CODES), sorties: lireSorties(), statuts: lireCle(CLE_STATUTS), cartons: lireCle(CLE_CARTONS),
        feuilles: feuilles, images: images,
      };
      telechargerFichier('centrale_' + aujourdhui() + '.desanonim', JSON.stringify(donnees, null, 1), 'application/json');
      confirmerSucces('Sauvegarde créée : centrale_' + aujourdhui() + '.desanonim', bilanSauvegarde(donnees, Object.keys(images).length).concat(['Rangez ce fichier dans votre Nuage.']));
    }).catch(function (e) { afficherMessage('Sauvegarde impossible : ' + e.message, true); });
  }

  /** Lit une sauvegarde : un fichier .desanonim (texte), ou une ancienne archive .zip. Renvoie { d, images: [{ nom, blob }] }. */
  function lireSauvegarde(buf) {
    var u8 = new Uint8Array(buf);
    if (u8[0] === 0x50 && u8[1] === 0x4B) {   // ancienne archive .zip
      return lireZip(buf).then(function (contenu) {
        var cle = Object.keys(contenu).find(function (n) { return /\.deanonim$/i.test(n); });
        if (!cle) throw new Error('Pas de fichier .deanonim dans cette archive.');
        var d = JSON.parse(new TextDecoder('utf-8').decode(contenu[cle]));
        var images = Object.keys(d.fichiers || {}).map(function (nom) {
          var octets = contenu[d.fichiers[nom]];
          return octets ? { nom: nom, blob: new Blob([octets], { type: typeImage(d.fichiers[nom]) }) } : null;
        }).filter(Boolean);
        return { d: d, images: images };
      });
    }
    var d = JSON.parse(new TextDecoder('utf-8').decode(buf));
    return Promise.all(Object.keys(d.images || {}).map(function (nom) {
      return fetch(d.images[nom]).then(function (r) { return r.blob(); }).then(function (blob) { return { nom: nom, blob: blob }; });
    })).then(function (images) { return { d: d, images: images }; });
  }

  /** Chargement : les classes présentes dans la sauvegarde remplacent celles de cet ordinateur ; les feuilles sont ajoutées. */
  function importerTout(fichier) {
    return fichier.arrayBuffer().then(lireSauvegarde).then(function (x) {
      var d = x.d;
      if (d.format !== 'centrale-deanonim') throw new Error('Ce fichier n’est pas une sauvegarde de la Centrale.');
      var carte = lire(); Object.assign(carte, d.noms || {}); ecrire(carte);
      var besoins = lireBesoins(); Object.assign(besoins, d.besoins || {}); ecrireBesoins(besoins);
      var codes = lireCle(CLE_CODES); Object.assign(codes, d.codes || {}); ecrireCle(CLE_CODES, codes);
      var sorties = lireSorties(); Object.assign(sorties, d.sorties || {}); ecrireCle(CLE_SORTIES, sorties);
      var cartons = lireCle(CLE_CARTONS); Object.assign(cartons, d.cartons || {}); ecrireCle(CLE_CARTONS, cartons);
      var statuts = lireCle(CLE_STATUTS); Object.assign(statuts, d.statuts || {}); ecrireCle(CLE_STATUTS, statuts);
      var feuilles = lireFeuilles(); Object.assign(feuilles, d.feuilles || {}); ecrireFeuilles(feuilles);
      return Promise.all(x.images.map(function (im) {
        return ecrireImage(im.nom, im.blob).then(function () {
          if (urlsFeuilles[im.nom]) URL.revokeObjectURL(urlsFeuilles[im.nom]);
          urlsFeuilles[im.nom] = URL.createObjectURL(im.blob);
        });
      })).then(function () {
        afficherNoms();
        confirmerSucces('Sauvegarde chargée avec succès', bilanSauvegarde(d, x.images.length));
      });
    }).catch(function (e) { afficherMessage(e.message || 'Sauvegarde illisible.', true); });
  }

  document.addEventListener('click', function (ev) {
    if (ev.target.closest && ev.target.closest('.sauver-zip')) exporterTout();
  });
  document.addEventListener('change', function (ev) {
    var champ = ev.target;
    if (!champ.classList || !champ.classList.contains('charger-zip')) return;
    var f = champ.files[0];
    champ.value = '';
    if (f) importerTout(f);
  });

  /* ---------- Confirmations ---------- */

  // Confirmation sans fenêtre du navigateur (le navigateur intégré de Claude, par exemple, ne les affiche pas) :
  // le 1er clic transforme le bouton en « Confirmer ? » et affiche la question, le 2e clic (dans les 6 s) valide.
  function confirmerEnLigne(bouton, question, porteur) {
    if (bouton.dataset.confirme === '1') return true;
    bouton.dataset.texte = bouton.textContent;
    bouton.dataset.confirme = '1';
    bouton.textContent = 'Confirmer ?';
    bouton.classList.add('a-confirmer');
    var info = null;
    if (question && porteur) {
      info = document.createElement('div');
      info.className = 'question-confirmation';
      info.textContent = question;
      porteur.appendChild(info);
    }
    setTimeout(function () {
      bouton.dataset.confirme = '';
      bouton.textContent = bouton.dataset.texte;
      bouton.classList.remove('a-confirmer');
      if (info) info.remove();
    }, 6000);
    return false;
  }

  // « Tester à la place d'un élève » : un nouvel onglet par essai (on peut ouvrir deux coéquipiers côte à côte).
  // Si le navigateur n'ouvre pas d'onglet (bloqueur, volet navigateur de l'app Claude), la page s'ouvre ici.
  var formTester = document.getElementById('form-tester');
  if (formTester) {
    formTester.addEventListener('submit', function () {
      var nom = 'eleve-test-' + Date.now(), w = null;
      try { w = window.open('', nom); } catch (e) { w = null; }
      formTester.target = w ? nom : '_self';
    });
  }

  // Ateliers des applications : filtre par application, niveau, recherche ; ateliers retirés masqués
  var cat = document.getElementById('catalogue-ateliers');
  if (cat && document.getElementById('cat-recherche')) {
    var catNiveau = '', catChamp = document.getElementById('cat-recherche'), catApp = document.getElementById('cat-app'), catRet = document.getElementById('cat-retires');
    var catFiltrer = function () {
      var q = catChamp.value.trim().toLowerCase(), n = 0;
      cat.querySelectorAll('.table-catalogue tbody tr').forEach(function (tr) {
        var ok = (!catApp.value || tr.dataset.app === catApp.value) && (!catNiveau || tr.dataset.niveau === catNiveau)
          && (catRet.checked || !tr.dataset.retire) && (!q || tr.dataset.texte.indexOf(q) >= 0);
        tr.hidden = !ok; if (ok) n++;
      });
      document.getElementById('cat-rien').hidden = n > 0;
    };
    cat.querySelectorAll('.seg-niveaux button').forEach(function (b) {
      b.addEventListener('click', function () {
        catNiveau = b.dataset.niveau;
        cat.querySelectorAll('.seg-niveaux button').forEach(function (x) { x.classList.toggle('actif', x === b); });
        catFiltrer();
      });
    });
    catChamp.addEventListener('input', catFiltrer); catApp.addEventListener('change', catFiltrer); catRet.addEventListener('change', catFiltrer);
    catFiltrer();
  }

  // Onglet Compétences : filtre par niveau, recherche, « seulement celles évaluées », copie des codes au clic
  var ref = document.getElementById('referentiel');
  if (ref && document.getElementById('ref-recherche')) {
    var niveau = '', champ = document.getElementById('ref-recherche'), seules = document.getElementById('ref-utilisees');
    var filtrer = function () {
      var q = champ.value.trim().toLowerCase(), visibles = 0;
      ref.querySelectorAll('.sous-ref').forEach(function (s) {
        var dansSous = q !== '' && s.dataset.texte.indexOf(q) >= 0, n = 0;
        s.querySelectorAll('tr[data-niveau]').forEach(function (tr) {
          var ok = (!niveau || tr.dataset.niveau === niveau) && (!seules.checked || tr.dataset.utilisee) && (!q || dansSous || tr.dataset.texte.indexOf(q) >= 0);
          tr.hidden = !ok; if (ok) n++;
        });
        s.hidden = n === 0; visibles += n;
      });
      ref.querySelectorAll('.competence-ref').forEach(function (c) { c.hidden = !c.querySelector('.sous-ref:not([hidden])'); });
      ref.querySelectorAll('.theme-ref').forEach(function (t) { t.hidden = !t.querySelector('.competence-ref:not([hidden])'); });
      document.getElementById('ref-rien').hidden = visibles > 0;
    };
    ref.querySelectorAll('[data-niveau]').forEach(function (b) {
      if (b.tagName !== 'BUTTON') return;
      b.addEventListener('click', function () {
        niveau = b.dataset.niveau;
        ref.querySelectorAll('.seg-niveaux button').forEach(function (x) { x.classList.toggle('actif', x === b); });
        filtrer();
      });
    });
    champ.addEventListener('input', filtrer);
    seules.addEventListener('change', filtrer);
    // Tri par nombre d'ateliers : à chaque niveau (thèmes, compétences, sous-compétences, capacités), à égalité l'ordre du référentiel
    var tri = document.getElementById('ref-tri'), fin = document.getElementById('ref-rien');
    ['.theme-ref', '.competence-ref', '.sous-ref', 'tr[data-niveau]'].forEach(function (sel) {
      ref.querySelectorAll(sel).forEach(function (x, i) { x.dataset.ordre = i; });
    });
    var trier = function (parent, sel, avant) {
      var sens = tri.value === 'plus' ? -1 : tri.value === 'moins' ? 1 : 0;
      Array.prototype.filter.call(parent.children, function (x) { return x.matches(sel); })
        .sort(function (a, b) { return sens * (a.dataset.nb - b.dataset.nb) || a.dataset.ordre - b.dataset.ordre; })
        .forEach(function (x) { parent.insertBefore(x, avant || null); });
    };
    tri.addEventListener('change', function () {
      trier(ref, '.theme-ref', fin);
      ref.querySelectorAll('.theme-ref').forEach(function (t) { trier(t, '.competence-ref', null); });
      ref.querySelectorAll('.competence-ref').forEach(function (c) { trier(c, '.sous-ref', null); });
      ref.querySelectorAll('.table-ref tbody').forEach(function (b) { trier(b, 'tr[data-niveau]', null); });
    });
    ref.addEventListener('click', function (ev) {
      var c = ev.target.closest('.copier');
      if (!c) return;
      var code = c.textContent.trim();
      (navigator.clipboard ? navigator.clipboard.writeText(code) : Promise.reject()).then(function () {
        c.classList.add('copie'); setTimeout(function () { c.classList.remove('copie'); }, 1200);
      }).catch(function () { window.prompt('Code à copier :', code); });
    });
  }

  document.querySelectorAll('form[data-confirmer]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      var b = ev.submitter || f.querySelector('button');
      if (!confirmerEnLigne(b, f.dataset.confirmer, f)) ev.preventDefault();
    });
  });

  function fiche(classe, numero, nom, code, lien) {
    var f = document.createElement('div');
    f.className = 'fiche';
    f.innerHTML = '<div class="fiche-haut"><span></span><span></span></div><div class="fiche-code"></div><div class="fiche-bas"></div>';
    f.querySelector('.fiche-haut span:first-child').textContent = 'Technologie · ' + classe + ' · n° ' + numero;
    f.querySelector('.fiche-haut span:last-child').textContent = nom;
    f.querySelector('.fiche-code').textContent = code;
    f.querySelector('.fiche-bas').textContent = 'Mon code personnel, à ne pas partager.' + (lien ? ' ' + lien : '');
    return f;
  }

  /** Imprime seulement les fiches de « zone » (pas celles d'une autre section de la page). */
  function imprimerFiches(zone) {
    var autres = Array.prototype.filter.call(document.querySelectorAll('.fiches'), function (x) { return x !== zone; });
    autres.forEach(function (x) { x.classList.add('hors-impression'); });
    window.addEventListener('afterprint', function () {
      autres.forEach(function (x) { x.classList.remove('hors-impression'); });
    }, { once: true });
    window.print();
  }

  /* ---------- « Voir comme un élève » : nom affiché ici pour le compte test ---------- */
  var formApercu = document.getElementById('form-apercu');
  if (formApercu) formApercu.addEventListener('submit', function () {
    if (!nomLocal('TEST', '01')) ajouter('TEST', { '01': 'Élève test' });
  });

  /* ---------- Groupes d'une classe : effectifs et répartition automatique ---------- */

  var formGroupes = document.getElementById('form-groupes');
  if (formGroupes) {
    var lignesG = Array.prototype.slice.call(formGroupes.querySelectorAll('tbody tr'));
    var colonnesG = Array.prototype.map.call(formGroupes.querySelectorAll('.compte-groupe'), function (x) { return x.dataset.groupe; });
    var compterGroupes = function () {
      formGroupes.querySelectorAll('.compte-groupe').forEach(function (x) {
        x.textContent = '(' + formGroupes.querySelectorAll('input[type=radio][value="' + x.dataset.groupe + '"]:checked').length + ')';
      });
    };
    formGroupes.addEventListener('change', compterGroupes);
    document.getElementById('groupes-auto').addEventListener('click', function () {
      // Ordre alphabétique des noms gardés ici (sinon ordre des numéros), puis moitié / moitié
      var classe = formGroupes.querySelector('[data-nom-local]') ? formGroupes.querySelector('[data-nom-local]').dataset.classe : '';
      var cle = function (tr) {
        var nom = nomLocal(classe, tr.dataset.numero);
        if (!nom) return '~' + tr.dataset.numero;
        var m = nom.split(' ');   // « Léa D. » → trier par nom puis prénom quand c'est possible
        return sansAccents(m.length > 1 ? m.slice(1).join(' ') + ' ' + m[0] : nom);
      };
      var tries = lignesG.slice().sort(function (a, b) { return cle(a).localeCompare(cle(b), 'fr', { numeric: true }); });
      var vrais = colonnesG.filter(function (g) { return g !== '0'; });
      var taille = Math.ceil(tries.length / vrais.length);
      tries.forEach(function (tr, i) {
        var g = vrais[Math.min(vrais.length - 1, Math.floor(i / taille))];
        tr.querySelector('input[value="' + g + '"]').checked = true;
      });
      compterGroupes();
      document.getElementById('groupes-info').textContent = 'Répartition proposée : vérifiez-la, puis enregistrez.';
    });
    compterGroupes();
  }

  /* ---------- Tableau de bord : réinitialiser TOUS les codes + liste globale ---------- */

  var blocReinit = document.getElementById('json-reinit');
  if (blocReinit) {
    var dr = JSON.parse(blocReinit.textContent);
    var nouveauxTous = [];
    var conf = document.getElementById('reinit-confirmation');
    document.getElementById('reinit-ouvrir').addEventListener('click', function () {
      var n = compter(lire());
      var li = document.getElementById('reinit-noms');
      li.textContent = n >= dr.nb_eleves
        ? 'Noms chargés sur cet ordinateur : ' + n + ' — la liste et les fiches porteront les noms.'
        : 'Noms chargés sur cet ordinateur : ' + n + ' / ' + dr.nb_eleves + ". Les élèves sans nom ici n'auront que leur numéro dans la liste : chargez d'abord votre fichier de noms si besoin.";
      li.className = n >= dr.nb_eleves ? '' : 'alerte';
      conf.hidden = false;
      conf.scrollIntoView({ block: 'nearest' });
    });
    document.getElementById('reinit-annuler').addEventListener('click', function () { conf.hidden = true; });
    document.getElementById('reinit-confirmer').addEventListener('click', async function () {
      var b = this;
      b.disabled = true; b.textContent = 'Remplacement des codes…';
      try {
        var f = new FormData();
        f.append('csrf', dr.csrf); f.append('action', 'reinitialiser_tous_codes');
        var r = await (await fetch('./', { method: 'POST', body: f, credentials: 'same-origin' })).json();
        if (!r.ok) throw new Error(r.erreur || 'erreur du serveur');
        nouveauxTous = [];
        r.classes.forEach(function (c) {
          var copie = {};
          c.codes.forEach(function (x) { copie[x.numero] = x.code; nouveauxTous.push({ classe: c.classe, numero: x.numero, code: x.code }); });
          ecrireCle(CLE_CODES, Object.assign(lireCle(CLE_CODES), (function () { var o = {}; o[c.classe] = copie; return o; })()));
        });
        telechargerListeGlobale();
        conf.hidden = true;
        document.getElementById('reinit-titre').textContent = nouveauxTous.length + ' nouveaux codes';
        var zone = document.getElementById('fiches-toutes');
        zone.innerHTML = '';
        nouveauxTous.forEach(function (x) { zone.appendChild(fiche(x.classe, x.numero, nomLocal(x.classe, x.numero), x.code, dr.lien)); });
        document.getElementById('reinit-resultat').hidden = false;
        document.getElementById('reinit-resultat').scrollIntoView({ block: 'start' });
      } catch (e) {
        afficherMessage('Réinitialisation interrompue : ' + e.message, true);
      }
      b.disabled = false; b.textContent = 'Oui, remplacer les ' + dr.nb_eleves + ' codes';
    });
    document.getElementById('reinit-imprimer').addEventListener('click', function () { imprimerFiches(document.getElementById('fiches-toutes')); });
    document.getElementById('reinit-telecharger').addEventListener('click', telechargerListeGlobale);
  }

  /* ---------- Codes de la classe : réimprimer / retélécharger depuis la copie locale ---------- */

  var blocCodes = document.getElementById('json-codes-classe');
  if (blocCodes) {
    var dc = JSON.parse(blocCodes.textContent);
    var valides = [];   // numéros dont le code local correspond encore au serveur

    var empreinte = async function (code) {
      var octets = new TextEncoder().encode(code.replace(/\s+/g, '').toLowerCase());
      var h = await crypto.subtle.digest('SHA-256', octets);
      return Array.prototype.map.call(new Uint8Array(h), function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    };

    var verifierCodes = async function () {
      var locaux = codesLocaux(dc.classe), empreintes = {};
      var nums = Object.keys(locaux);
      try {
        for (var i = 0; i < nums.length; i++) empreintes[nums[i]] = await empreinte(locaux[nums[i]]);
        var f = new FormData();
        f.append('csrf', dc.csrf); f.append('action', 'verifier_codes'); f.append('classe_id', dc.classe_id);
        f.append('empreintes', JSON.stringify(empreintes));
        var r = await (await fetch('./', { method: 'POST', body: f, credentials: 'same-origin' })).json();
        valides = r.valides || [];
      } catch (e) { valides = []; }
      var total = dc.eleves.length, n = dc.eleves.filter(function (e) { return valides.indexOf(e.numero) >= 0; }).length;
      var perimes = nums.filter(function (x) { return valides.indexOf(x) < 0; }).length;
      document.getElementById('etat-codes').textContent = 'Codes connus sur cet ordinateur : ' + n + ' / ' + total
        + (perimes ? ' · ' + perimes + ' code(s) gardé(s) ici ne sont plus valables (changés depuis)' : '')
        + (n < total ? ". Pour les autres, chargez votre fichier de codes (CSV) avec « Charger mon fichier de noms », ou créez-leur un nouveau code." : '.');
      document.getElementById('codes-imprimer').disabled = !n;
      document.getElementById('codes-csv').disabled = !n;
      var manquants = dc.eleves.filter(function (e) { return valides.indexOf(e.numero) < 0; });
      var form = document.getElementById('form-codes-manquants');
      form.hidden = !manquants.length || manquants.length === total;
      form.querySelector('[name=eleves]').value = manquants.map(function (e) { return e.id; }).join(',');
      form.querySelector('button').textContent = 'Nouveaux codes pour les ' + manquants.length + ' élève(s) sans code ici';
    };

    var lignesCodes = function () {
      var locaux = codesLocaux(dc.classe);
      return dc.eleves.filter(function (e) { return valides.indexOf(e.numero) >= 0; }).map(function (e) {
        return { numero: e.numero, nom: nomLocal(dc.classe, e.numero), code: locaux[e.numero] };
      });
    };

    document.getElementById('codes-csv').addEventListener('click', function () {
      telechargerCSV('codes_' + dc.prefixe + '_' + aujourdhui() + '.csv', ENTETE_LISTE,
        lignesCodes().map(function (l) {
          var s = document.querySelector('.surnom[data-numero="' + l.numero + '"]');
          var g = s ? s.closest('tr').querySelector('.badge-groupe') : null;
          return [dc.etablissement || '', dc.classe, g ? g.textContent : '', l.numero, s ? s.textContent.trim() : '', l.nom, l.code, besoinsLocaux(dc.classe, l.numero).join(', ')];
        }));
    });

    document.getElementById('codes-imprimer').addEventListener('click', function () {
      var zone = document.getElementById('fiches-classe');
      zone.innerHTML = '';
      lignesCodes().forEach(function (l) { zone.appendChild(fiche(dc.classe, l.numero, l.nom, l.code, dc.lien)); });
      imprimerFiches(zone);
    });

    document.addEventListener('centrale-codes', verifierCodes);
    verifierCodes();
  }

  afficherNoms();
})();

/* ---------- Export CSV du tableau de suivi (avec les noms gardés localement) ---------- */
(function () {
  var b = document.getElementById('exporter-suivi');
  var table = document.getElementById('table-suivi');
  if (!b || !table) return;
  b.addEventListener('click', function () {
    var lignes = [];
    var entetes = Array.prototype.map.call(table.querySelectorAll('thead tr:last-child th'), function (th) {
      return th.getAttribute('title') || th.textContent.trim();
    });
    lignes.push(entetes);
    table.querySelectorAll('tbody tr, tfoot tr').forEach(function (tr) {
      lignes.push(Array.prototype.map.call(tr.children, function (td) {
        if (td.classList.contains('cellule') && td.dataset.taux !== undefined) {
          if (td.classList.contains('st-termine')) return 'terminé';
          if (td.classList.contains('st-commence')) return td.textContent.trim() || 'en cours';
          return '';
        }
        var copie = td.cloneNode(true);
        copie.querySelectorAll('.besoins-local').forEach(function (x) { x.remove(); });
        return copie.textContent.trim();
      }));
    });
    var csv = '﻿' + lignes.map(function (l) {
      return l.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(';');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = b.dataset.fichier + '_' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
  });
})();
