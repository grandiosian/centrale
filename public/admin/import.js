/**
 * Centrale élèves — import des classes depuis Pronote.
 * Le fichier est lu ici, dans le navigateur. Le serveur ne reçoit que « classe + nombre d'élèves ».
 */
(function () {
  'use strict';

  var D = JSON.parse(document.getElementById('imp-donnees').textContent);
  var $ = function (id) { return document.getElementById(id); };
  var analyse = null;          // { classes: [ { nom, eleves: [ {nom, prenom} ] } ] }
  var resultat = [];           // [ { classe, numero, nom, code } ]

  /* ---------- Lecture du fichier ---------- */

  /** Texte d'un fichier Pronote : UTF-8 si possible, sinon Windows-1252 (exports « ANSI »). */
  function decoder(octets) {
    var utf8 = new TextDecoder('utf-8', { fatal: false }).decode(octets);
    if (utf8.indexOf('�') === -1) return utf8.replace(/^﻿/, '');
    return new TextDecoder('windows-1252').decode(octets);
  }

  function sansAccents(s) {
    return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  }

  /** Séparateur le plus probable : tabulation (copier-coller), point-virgule ou virgule. */
  function separateur(texte) {
    var lignes = texte.split(/\r?\n/).filter(function (l) { return l.trim(); }).slice(0, 15);
    var meilleur = ';', score = -1;
    ['\t', ';', ','].forEach(function (sep) {
      var comptes = lignes.map(function (l) { return l.split(sep).length - 1; }).filter(function (n) { return n > 0; });
      var s = comptes.length * 10 + (comptes.length ? Math.min.apply(null, comptes) : 0);
      if (s > score) { score = s; meilleur = sep; }
    });
    return meilleur;
  }

  function lireTableau(texte) {
    var sep = separateur(texte);
    var lignes = [], champ = '', ligne = [], guillemets = false;
    for (var i = 0; i < texte.length; i++) {
      var c = texte[i];
      if (guillemets) {
        if (c === '"' && texte[i + 1] === '"') { champ += '"'; i++; }
        else if (c === '"') guillemets = false;
        else champ += c;
      } else if (c === '"' && champ === '') guillemets = true;
      else if (c === sep) { ligne.push(champ.trim()); champ = ''; }
      else if (c === '\n' || c === '\r') {
        if (c === '\r' && texte[i + 1] === '\n') i++;
        ligne.push(champ.trim()); lignes.push(ligne); ligne = []; champ = '';
      } else champ += c;
    }
    if (champ !== '' || ligne.length) { ligne.push(champ.trim()); lignes.push(ligne); }
    return lignes.filter(function (l) { return l.some(function (v) { return v !== ''; }); });
  }

  var ENTETES = {
    complet: ['eleve', 'eleves', 'nom prenom', 'nom et prenom', 'nom prenoms', 'identite', 'nom de l eleve', 'apprenant'],
    nom: ['nom', 'nom de famille', 'nom d usage', 'nom eleve', 'nom de l eleve'],
    prenom: ['prenom', 'prenoms', 'prenom 1', 'premier prenom', 'prenom eleve', 'prenom usuel'],
    classe: ['classe', 'classes', 'division', 'classe de rattachement', 'div', 'structure'],
    sortie: ['sortie', 'date de sortie', 'sorti le', 'date sortie'],
  };

  /** « 05/01/2026 » (Pronote) ou « 2026-01-05 » → Date, sinon null. */
  function lireDate(s) {
    var m = String(s || '').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/);
    if (m) return new Date(+(m[3].length === 2 ? '20' + m[3] : m[3]), +m[2] - 1, +m[1]);
    m = String(s || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  }

  function trouverColonnes(entete) {
    var n = entete.map(sansAccents);
    var col = {};
    Object.keys(ENTETES).forEach(function (k) {
      col[k] = n.findIndex(function (h) { return ENTETES[k].indexOf(h) >= 0; });
    });
    // « Nom » seul + « Prénom » : colonnes séparées ; sinon « Élève » = NOM Prénom
    if (col.nom >= 0 && col.prenom < 0 && col.complet < 0) { col.complet = col.nom; col.nom = -1; }
    return col;
  }

  /** « DE LA FONTAINE Jean-Pierre » → { nom: 'DE LA FONTAINE', prenom: 'Jean-Pierre' } (nom en majuscules, comme dans Pronote). */
  function decouper(complet) {
    var mots = complet.trim().split(/\s+/);
    var i = 0;
    while (i < mots.length - 1 && mots[i] === mots[i].toLocaleUpperCase('fr') && /\p{L}/u.test(mots[i])) i++;
    if (i === 0) return { nom: mots.slice(0, 1).join(' '), prenom: mots.slice(1).join(' ') };   // « Dupont Léa »
    return { nom: mots.slice(0, i).join(' '), prenom: mots.slice(i).join(' ') };
  }

  function majusculesInitiales(s) {
    return s.toLocaleLowerCase('fr').replace(/(^|[\s'’-])(\p{L})/gu, function (m, a, b) { return a + b.toLocaleUpperCase('fr'); });
  }

  /** Même transformation que le serveur (nom_classe_import) : « 5°A » → « 5A ». */
  function nomClasse(nom) {
    return String(nom || '').replace(/\s+/g, ' ').replace(/[°]/g, '').replace(/ᵉ/g, 'e').replace(/è/g, 'e')
      .replace(/[^\p{L}0-9 _-]/gu, '').trim().slice(0, 20);
  }

  function formater(e, format) {
    var prenom = majusculesInitiales(e.prenom);
    var nom = e.nom.toLocaleUpperCase('fr');
    if (format === 'prenom-nom') return (prenom + ' ' + nom).trim();
    if (format === 'nom-prenom') return (nom + ' ' + prenom).trim();
    return (prenom + ' ' + (nom ? nom[0] + '.' : '')).trim();
  }

  function afficherErreur(msg) {
    $('imp-erreur').textContent = msg || '';
    $('imp-erreur').hidden = !msg;
  }

  async function texteSource() {
    var f = $('imp-fichier').files[0];
    if (f) return decoder(new Uint8Array(await f.arrayBuffer()));
    return $('imp-texte').value;
  }

  /* ---------- Analyse ---------- */

  async function analyser() {
    afficherErreur('');
    $('imp-apercu').hidden = true;
    var texte = await texteSource();
    if (!texte.trim()) { afficherErreur('Choisissez un fichier ou collez la liste des élèves.'); return; }
    var tableau = lireTableau(texte);
    // L'en-tête peut être précédé de lignes de titre (« Liste des élèves – 5A… »)
    var iEntete = -1, col = null;
    for (var i = 0; i < Math.min(tableau.length, 15); i++) {
      var c = trouverColonnes(tableau[i]);
      if (c.complet >= 0 || (c.nom >= 0 && c.prenom >= 0)) { iEntete = i; col = c; break; }
    }
    if (iEntete < 0) {
      afficherErreur("Colonnes non reconnues. Il faut une colonne « Élève » (NOM Prénom) ou deux colonnes « Nom » et « Prénom », et si possible « Classe ».");
      return;
    }
    var classeDefaut = nomClasse($('imp-classe-defaut').value);
    if (col.classe < 0 && !classeDefaut) {
      afficherErreur("Ce fichier n'a pas de colonne « Classe » : indiquez la classe dans le champ prévu, puis cliquez à nouveau sur Analyser.");
      return;
    }
    var format = $('imp-format').value;
    var parClasse = {}, ignorees = 0, partis = 0, sansClasse = 0, trouves = 0;
    var aujourdhui = new Date(); aujourdhui.setHours(0, 0, 0, 0);
    tableau.slice(iEntete + 1).forEach(function (l) {
      var e;
      if (col.nom >= 0 && col.prenom >= 0) e = { nom: (l[col.nom] || '').trim(), prenom: (l[col.prenom] || '').trim() };
      else e = decouper(l[col.complet] || '');
      if (!(e.nom || e.prenom)) { ignorees++; return; }
      // Élève sorti de l'établissement (colonne « Sortie » de Pronote, date passée) : on ne le crée pas
      var sortie = col.sortie >= 0 ? lireDate(l[col.sortie]) : null;
      if (sortie && sortie < aujourdhui) { partis++; return; }
      var classe = (col.classe >= 0 ? nomClasse(l[col.classe]) : '') || classeDefaut;
      if (!classe) { sansClasse++; return; }
      trouves++;
      (parClasse[classe] = parClasse[classe] || []).push(e);
    });
    if (!trouves) {
      afficherErreur(sansClasse
        ? sansClasse + " élève(s) sans classe dans le fichier : indiquez la classe dans le champ prévu, puis cliquez à nouveau sur Analyser."
        : "Aucun élève trouvé dans ce fichier.");
      return;
    }
    var classes = Object.keys(parClasse).sort(function (a, b) { return a.localeCompare(b, 'fr', { numeric: true }); }).map(function (nom) {
      var eleves = parClasse[nom].sort(function (a, b) { return (a.nom + a.prenom).localeCompare(b.nom + b.prenom, 'fr'); });
      // Noms au format choisi ; en cas de doublon (deux « Léa D. »), on écrit le nom complet
      var noms = eleves.map(function (e) { return formater(e, format); });
      noms = noms.map(function (n, i) {
        return noms.indexOf(n) !== noms.lastIndexOf(n) ? formater(eleves[i], format === 'nom-prenom' ? 'nom-prenom' : 'prenom-nom') : n;
      });
      var existante = D.classes.find(function (c) { return c.nom.toLowerCase() === nom.toLowerCase(); });
      var nomServeur = existante ? existante.nom : nom;
      var deja = window.CentraleNoms ? window.CentraleNoms.carte(nomServeur) : {};
      var connus = Object.keys(deja).map(function (k) { return sansAccents(deja[k]); });
      var nouveaux = noms.filter(function (n) { return connus.indexOf(sansAccents(n)) < 0; });
      var remarque = '';
      if (existante && existante.nb && !Object.keys(deja).length) {
        remarque = existante.nb + ' élève(s) déjà dans cette classe, mais leurs noms ne sont pas chargés sur cet ordinateur : chargez votre fichier de noms avant, sinon ils seront recréés.';
      }
      return { nom: nom, existe: !!existante, total: noms.length, nouveaux: nouveaux, deja: noms.length - nouveaux.length, remarque: remarque };
    });
    analyse = { classes: classes };
    $('imp-resume').textContent = trouves + ' élève(s) trouvé(s) dans ' + classes.length + ' classe(s)'
      + (partis ? ' · ' + partis + ' élève(s) sorti(s) de l\'établissement, non créé(s)' : '')
      + (sansClasse ? ' · ' + sansClasse + ' élève(s) sans classe, ignoré(s) (indiquez une classe dans le champ prévu pour les inclure)' : '')
      + (ignorees ? ' · ' + ignorees + ' ligne(s) vide(s) ignorée(s)' : '') + '.';
    var corps = $('imp-classes');
    corps.innerHTML = '';
    classes.forEach(function (c, i) {
      var tr = document.createElement('tr');
      tr.innerHTML = '<td><input type="checkbox" checked data-i="' + i + '"></td><td><strong></strong> <span class="discret"></span></td>'
        + '<td>' + c.total + '</td><td>' + c.nouveaux.length + '</td><td>' + c.deja + '</td><td class="discret"></td>';
      tr.querySelector('strong').textContent = c.nom;
      tr.querySelector('span').textContent = c.existe ? '(existe déjà)' : '(nouvelle)';
      tr.lastChild.textContent = c.remarque || (c.nouveaux.length > 60 ? 'Plus de 60 élèves : créés en plusieurs fois.' : '');
      if (!c.nouveaux.length) { tr.querySelector('input').checked = false; tr.classList.add('inactif'); }
      corps.appendChild(tr);
    });
    $('imp-apercu').hidden = false;
    $('imp-apercu').scrollIntoView({ block: 'start', behavior: 'smooth' });
  }

  /* ---------- Création ---------- */

  async function envoyer(classe, nombre) {
    var f = new FormData();
    f.append('csrf', D.csrf);
    f.append('action', 'import_classe');
    f.append('classe', classe);
    f.append('nombre', String(nombre));
    f.append('etablissement_id', $('imp-etab').value);
    var r = await fetch('./', { method: 'POST', body: f, credentials: 'same-origin' });
    var j = await r.json();
    if (!j.ok) throw new Error(j.erreur || 'Erreur du serveur');
    return j;
  }

  async function lancer() {
    if (!analyse) return;
    var choix = Array.prototype.filter.call($('imp-classes').querySelectorAll('input[type=checkbox]'), function (c) { return c.checked; })
      .map(function (c) { return analyse.classes[+c.dataset.i]; })
      .filter(function (c) { return c.nouveaux.length; });
    if (!choix.length) { afficherErreur('Aucune classe à créer.'); return; }
    var bouton = $('imp-lancer');
    bouton.disabled = true;
    resultat = [];
    var bilan = [];
    try {
      for (var i = 0; i < choix.length; i++) {
        var c = choix[i];
        bouton.textContent = 'Classe ' + c.nom + '… (' + (i + 1) + '/' + choix.length + ')';
        for (var debut = 0; debut < c.nouveaux.length; debut += 60) {
          var lot = c.nouveaux.slice(debut, debut + 60);
          var r = await envoyer(c.nom, lot.length);
          var paires = {};
          r.codes.forEach(function (x, k) {
            paires[x.numero] = lot[k];
            resultat.push({ classe: r.classe, prefixe: r.prefixe, numero: x.numero, nom: lot[k], code: x.code });
          });
          window.CentraleNoms.ajouter(r.classe, paires);   // les noms restent sur cet ordinateur
          if (debut === 0) bilan.push({ classe: r.classe, id: r.classe_id, creee: r.creee, n: 0 });
          bilan[bilan.length - 1].n += r.codes.length;
        }
      }
    } catch (e) {
      afficherErreur("L'import s'est arrêté : " + e.message + ' (les classes déjà traitées sont enregistrées).');
    }
    bouton.disabled = false;
    bouton.textContent = 'Créer les classes et les codes';
    if (!resultat.length) return;
    afficherResultat(bilan);
    telechargerCodes();
  }

  function afficherResultat(bilan) {
    $('imp-apercu').hidden = true;
    var b = $('imp-bilan');
    b.innerHTML = '';
    var ul = document.createElement('ul');
    bilan.forEach(function (x) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = './?p=classe&id=' + x.id;
      a.textContent = x.classe;
      li.appendChild(a);
      li.appendChild(document.createTextNode(' : ' + x.n + ' code(s) créé(s)' + (x.creee ? ' · nouvelle classe' : '')));
      ul.appendChild(li);
    });
    b.appendChild(ul);
    // Fiches à découper (impression)
    var fiches = $('imp-fiches');
    fiches.innerHTML = '';
    resultat.forEach(function (r) {
      var f = document.createElement('div');
      f.className = 'fiche';
      f.innerHTML = '<div class="fiche-haut"><span></span><span></span></div><div class="fiche-code"></div><div class="fiche-bas"></div>';
      f.querySelector('.fiche-haut span:first-child').textContent = 'Technologie · ' + r.classe + ' · n° ' + r.numero;
      f.querySelector('.fiche-haut span:last-child').textContent = r.nom;
      f.querySelector('.fiche-code').textContent = r.code;
      f.querySelector('.fiche-bas').textContent = 'Mon code personnel, à ne pas partager.' + (D.lien_eleves ? ' ' + D.lien_eleves : '');
      fiches.appendChild(f);
    });
    $('imp-resultat').hidden = false;
    $('imp-resultat').scrollIntoView({ block: 'start', behavior: 'smooth' });
  }

  function telechargerCodes() {
    window.CentraleNoms.telechargerCSV('codes_import_' + new Date().toISOString().slice(0, 10) + '.csv', ['Classe', 'Numéro', 'Nom', 'Code'],
      resultat.map(function (r) { return [r.classe, r.numero, r.nom, r.code]; }));
  }

  $('imp-analyser').onclick = analyser;
  $('imp-lancer').onclick = lancer;
  $('imp-csv').onclick = telechargerCodes;
  $('imp-fichier').addEventListener('change', function () { if (this.files[0]) analyser(); });
  $('imp-tout').addEventListener('change', function () {
    var v = this.checked;
    $('imp-classes').querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = v; });
  });
})();
