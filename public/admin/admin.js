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
  var CLE_BESOINS = 'centrale-besoins';     // { "5A": { "07": ["PAP", "PAI"], … } } — PPS, PAP, PAI, PPRE
  var TYPES_BESOINS = ['PPS', 'PAP', 'PAI', 'PPRE'];
  var CLE_CODES = 'centrale-codes';         // { "5A": { "07": "5A-abcdefg-hjkm-npqrs" } } — copie locale pour réimprimer

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

  /** Classes renommées sur le serveur : on déplace les noms, PAP… et codes gardés ici vers le nouveau nom. */
  function appliquerRenommages() {
    var bloc = document.getElementById('json-renommages');
    if (!bloc) return;
    var liste = [];
    try { liste = JSON.parse(bloc.textContent) || []; } catch (e) { return; }
    [CLE_NOMS, CLE_BESOINS, CLE_CODES].forEach(function (cle) {
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
    document.dispatchEvent(new Event('centrale-noms')); // ex. plan de classe : redessiner avec les noms
  }

  // Accès aux noms locaux pour les autres scripts de l'espace enseignant (plan de classe)
  window.CentraleNoms = {
    nomLocal: nomLocal,
    ajouter: function (classe, paires) { ajouter(classe, paires); afficherNoms(); },
    carte: function (classe) { return Object.assign({}, lire()[classe] || {}); },
    besoins: besoinsLocaux,
    typesBesoins: typesBesoins,
    pastilles: pastilles,
    ajouterBesoins: function (classe, paires) { ajouterBesoins(classe, paires); afficherNoms(); },
    ajouterCodes: ajouterCodes,
    codes: codesLocaux,
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

  function importerCSV(texte) {
    var lignes = lireCSV(texte);
    if (lignes.length < 2) throw new Error('Fichier vide ou illisible.');
    var entete = lignes[0].map(sansAccents);
    var iClasse = entete.findIndex(function (h) { return h === 'classe'; });
    var iNum = entete.findIndex(function (h) { return h === 'numero' || h === 'n°' || h === 'no' || h === 'num'; });
    var iNom = entete.findIndex(function (h) { return h === 'nom' || h === 'eleve' || h === 'nom prenom' || h === 'prenom nom'; });
    var iCode = entete.findIndex(function (h) { return h === 'code' || h === 'code eleve' || h === 'code de connexion'; });
    var iBesoins = entete.findIndex(function (h) { return /^besoins|accompagnement|^pap|^amenagements?$/.test(h); });
    if (iClasse < 0 || iNum < 0 || iNom < 0) {
      throw new Error('Colonnes attendues : Classe ; Numéro ; Nom (le fichier téléchargé lors de la création des codes convient).');
    }
    var carte = lire(), n = 0, besoins = {}, codes = {};
    lignes.slice(1).forEach(function (l) {
      var classe = (l[iClasse] || '').trim(), num = (l[iNum] || '').trim(), nom = (l[iNom] || '').trim();
      if (!classe || !num || !nom) return;
      if (/^\d$/.test(num)) num = '0' + num;
      carte[classe] = carte[classe] || {};
      carte[classe][num] = nom;
      if (iBesoins >= 0) (besoins[classe] = besoins[classe] || {})[num] = typesBesoins(l[iBesoins]);
      if (iCode >= 0 && /^[A-Za-z0-9]+(-[A-Za-z0-9]+){2,4}$/.test((l[iCode] || '').trim())) (codes[classe] = codes[classe] || {})[num] = l[iCode].trim();
      n++;
    });
    ecrire(carte);
    Object.keys(besoins).forEach(function (c) { ajouterBesoins(c, besoins[c]); });
    Object.keys(codes).forEach(function (c) { ajouterCodes(c, codes[c]); });
    appliquerRenommages();   // fichier ancien : classes renommées depuis
    return n;
  }

  function telechargerCSV(nomFichier, entete, lignes) {
    var csv = '﻿' + [entete].concat(lignes).map(function (l) {
      return l.map(function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; }).join(';');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
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
      if (!confirmerEnLigne(b, "Effacer les noms, PAP… et codes de ce navigateur ? Gardez bien votre fichier CSV : c'est la seule copie.", b.parentNode)) return;
      try { [CLE_NOMS, CLE_BESOINS, CLE_CODES].forEach(function (k) { localStorage.removeItem(k); }); } catch (e) { /* rien */ }
      afficherNoms();
    });
  });

  /**
   * Liste globale : tous les établissements, toutes les classes, tous les élèves du serveur,
   * avec ce que cet ordinateur sait d'eux (nom, code, PAP / PPS / PAI). Un seul fichier « désanonymiseur ».
   */
  var ENTETE_LISTE = ['Établissement', 'Classe', 'Groupe', 'Numéro', 'Identifiant', 'Nom', 'Code', 'Besoins (PAP, PPS, PAI, PPRE)'];
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
        lignes.push([c.etablissement, c.nom, e[2] || '', num, e[1], (noms[c.nom] || {})[num] || '', (codes[c.nom] || {})[num] || '',
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
