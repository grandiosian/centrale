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
      if (iCode >= 0 && /^[A-Za-z0-9]+-[a-z]{7}-[a-z]{4}-[a-z]{5}$/i.test((l[iCode] || '').trim())) (codes[classe] = codes[classe] || {})[num] = l[iCode].trim();
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

  document.querySelectorAll('.exporter-liste').forEach(function (b) {
    b.addEventListener('click', function () {
      var carte = lire(), lignes = [];
      Object.keys(carte).sort().forEach(function (c) {
        Object.keys(carte[c]).sort().forEach(function (num) { lignes.push([c, num, carte[c][num], besoinsLocaux(c, num).join(', ')]); });
      });
      if (!lignes.length) { afficherMessage('Aucun nom chargé sur cet ordinateur.', true); return; }
      telechargerCSV('correspondance_eleves_' + aujourdhui() + '.csv', ['Classe', 'Numéro', 'Nom', 'Besoins (PAP, PPS, PAI, PPRE)'], lignes);
    });
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

  document.querySelectorAll('form[data-confirmer]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      var b = ev.submitter || f.querySelector('button');
      if (!confirmerEnLigne(b, f.dataset.confirmer, f)) ev.preventDefault();
    });
  });

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
      telechargerCSV('codes_' + dc.prefixe + '_' + aujourdhui() + '.csv', ['Classe', 'Numéro', 'Nom', 'Code', 'Besoins (PAP, PPS, PAI, PPRE)'],
        lignesCodes().map(function (l) { return [dc.classe, l.numero, l.nom, l.code, besoinsLocaux(dc.classe, l.numero).join(', ')]; }));
    });

    document.getElementById('codes-imprimer').addEventListener('click', function () {
      var zone = document.getElementById('fiches-classe');
      zone.innerHTML = '';
      lignesCodes().forEach(function (l) {
        var f = document.createElement('div');
        f.className = 'fiche';
        f.innerHTML = '<div class="fiche-haut"><span></span><span></span></div><div class="fiche-code"></div><div class="fiche-bas"></div>';
        f.querySelector('.fiche-haut span:first-child').textContent = 'Technologie · ' + dc.classe + ' · n° ' + l.numero;
        f.querySelector('.fiche-haut span:last-child').textContent = l.nom;
        f.querySelector('.fiche-code').textContent = l.code;
        f.querySelector('.fiche-bas').textContent = 'Mon code personnel, à ne pas partager.' + (dc.lien ? ' ' + dc.lien : '');
        zone.appendChild(f);
      });
      // N'imprimer que ces fiches (pas celles d'une génération affichée plus haut)
      var autres = Array.prototype.filter.call(document.querySelectorAll('.fiches'), function (x) { return x !== zone; });
      autres.forEach(function (x) { x.classList.add('hors-impression'); });
      window.addEventListener('afterprint', function () {
        autres.forEach(function (x) { x.classList.remove('hors-impression'); });
      }, { once: true });
      window.print();
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
