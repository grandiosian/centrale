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
  function compter(carte) {
    return Object.keys(carte).reduce(function (n, c) { return n + Object.keys(carte[c]).length; }, 0);
  }

  /* ---------- Affichage ---------- */

  function afficherNoms() {
    document.querySelectorAll('[data-nom-local]').forEach(function (el) {
      var nom = nomLocal(el.dataset.classe, el.dataset.numero);
      el.textContent = nom || (el.dataset.vide || '—');
      el.classList.toggle('inconnu', !nom);
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
  window.CentraleNoms = { nomLocal: nomLocal };

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
    if (iClasse < 0 || iNum < 0 || iNom < 0) {
      throw new Error('Colonnes attendues : Classe ; Numéro ; Nom (le fichier téléchargé lors de la création des codes convient).');
    }
    var carte = lire(), n = 0;
    lignes.slice(1).forEach(function (l) {
      var classe = (l[iClasse] || '').trim(), num = (l[iNum] || '').trim(), nom = (l[iNom] || '').trim();
      if (!classe || !num || !nom) return;
      if (/^\d$/.test(num)) num = '0' + num;
      carte[classe] = carte[classe] || {};
      carte[classe][num] = nom;
      n++;
    });
    ecrire(carte);
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

  document.querySelectorAll('.charger-liste').forEach(function (input) {
    input.addEventListener('change', function () {
      var f = input.files[0];
      if (!f) return;
      f.text().then(function (texte) {
        try {
          var n = importerCSV(texte);
          afficherNoms();
          afficherMessage(n + ' nom' + (n > 1 ? 's' : '') + ' chargé' + (n > 1 ? 's' : '') + ' sur cet ordinateur.');
        } catch (e) { afficherMessage(e.message, true); }
        input.value = '';
      });
    });
  });

  document.querySelectorAll('.oublier-liste').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!confirmerEnLigne(b, "Effacer les noms de ce navigateur ? Gardez bien votre fichier CSV : c'est la seule copie.", b.parentNode)) return;
      try { localStorage.removeItem(CLE_NOMS); } catch (e) { /* rien */ }
      afficherNoms();
    });
  });

  document.querySelectorAll('.exporter-liste').forEach(function (b) {
    b.addEventListener('click', function () {
      var carte = lire(), lignes = [];
      Object.keys(carte).sort().forEach(function (c) {
        Object.keys(carte[c]).sort().forEach(function (num) { lignes.push([c, num, carte[c][num]]); });
      });
      if (!lignes.length) { afficherMessage('Aucun nom chargé sur cet ordinateur.', true); return; }
      telechargerCSV('correspondance_eleves_' + aujourdhui() + '.csv', ['Classe', 'Numéro', 'Nom'], lignes);
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
        return td.textContent.trim();
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
