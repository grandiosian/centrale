/**
 * Centrale élèves — plans de salle.
 *  - PlanSalle.editeur()  : dessiner la salle (tables, postes, places élèves, tableau…)
 *  - PlanSalle.placement() : placer les élèves d'une classe sur les places de la salle
 * Les noms des élèves viennent de l'ordinateur de l'enseignant (admin.js), jamais du serveur.
 */
window.PlanSalle = (function () {
  'use strict';

  var U = 40;          // pixels par carreau (≈ 50 cm)
  var MARGE = 0.5;     // marge autour de la salle, en carreaux
  var NS = 'http://www.w3.org/2000/svg';

  var TYPES = {
    table:     { nom: 'Table', l: 2, h: 1 },
    poste:     { nom: 'Ordinateur', l: 1, h: 1 },
    place:     { nom: 'Place élève', l: 1, h: 1, place: true },
    paillasse: { nom: 'Paillasse / établi', l: 4, h: 1 },
    bureau:    { nom: 'Bureau du professeur', l: 2, h: 1 },
    tableau:   { nom: 'Tableau', l: 4, h: 0.5 },
    armoire:   { nom: 'Armoire', l: 2, h: 1 },
    porte:     { nom: 'Porte', l: 2, h: 0.5 },
    fenetre:   { nom: 'Fenêtre', l: 3, h: 0.5 },
  };

  function el(tag, attrs, parent) {
    var e = document.createElementNS(NS, tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (parent) parent.appendChild(e);
    return e;
  }

  /**
   * Confirmation sans fenêtre du navigateur (certains navigateurs intégrés ne les affichent pas) :
   * le premier clic transforme le bouton en « Confirmer ? », le second clic (dans les 5 s) valide.
   */
  function confirmerEnLigne(bouton, texte) {
    if (bouton.dataset.confirme === '1') {
      clearTimeout(bouton._minuteur);
      bouton.dataset.confirme = '';
      bouton.textContent = bouton.dataset.texte;
      bouton.classList.remove('a-confirmer');
      return true;
    }
    bouton.dataset.texte = bouton.textContent;
    bouton.dataset.confirme = '1';
    bouton.textContent = texte;
    bouton.classList.add('a-confirmer');
    bouton._minuteur = setTimeout(function () {
      bouton.dataset.confirme = '';
      bouton.textContent = bouton.dataset.texte;
      bouton.classList.remove('a-confirmer');
    }, 5000);
    return false;
  }

  function lireDonnees() {
    return JSON.parse(document.getElementById('donnees-plan').textContent);
  }

  function tourne(o) { return o.r === 90 || o.r === 270; }
  function largeurVue(o) { return tourne(o) ? o.h : o.l; }
  function hauteurVue(o) { return tourne(o) ? o.l : o.h; }
  function estPlace(o) { return !!(TYPES[o.type] && TYPES[o.type].place); }
  function nouvelId() { return 'o' + Math.random().toString(36).slice(2, 9); }
  function arrondi(v) { return Math.round(v * 2) / 2; }

  /** Numéro affiché de chaque place : son étiquette, sinon son rang (de gauche à droite, de haut en bas). */
  function numeros(plan) {
    var places = plan.objets.filter(estPlace).slice().sort(function (a, b) { return (a.y - b.y) || (a.x - b.x); });
    var res = {};
    places.forEach(function (o, i) { res[o.id] = o.label || String(i + 1); });
    return res;
  }

  /** Dessine la salle et ses objets. options.surObjet(o, groupe) permet d'ajouter des interactions. */
  function dessiner(svg, plan, options) {
    options = options || {};
    while (svg.firstChild) svg.removeChild(svg.firstChild);
    var L = plan.largeur, H = plan.hauteur;
    svg.setAttribute('viewBox', [-MARGE * U, -MARGE * U, (L + 2 * MARGE) * U, (H + 2 * MARGE) * U].join(' '));
    svg.style.aspectRatio = (L + 2 * MARGE) + ' / ' + (H + 2 * MARGE);
    var fond = el('g', { class: 'fond' }, svg);
    el('rect', { x: 0, y: 0, width: L * U, height: H * U, class: 'sol' }, fond);
    if (options.grille) {
      for (var x = 1; x < L; x++) el('line', { x1: x * U, y1: 0, x2: x * U, y2: H * U, class: 'grille' }, fond);
      for (var y = 1; y < H; y++) el('line', { x1: 0, y1: y * U, x2: L * U, y2: y * U, class: 'grille' }, fond);
    }
    el('rect', { x: 0, y: 0, width: L * U, height: H * U, class: 'murs' }, fond);
    var nums = numeros(plan);
    var groupes = {};
    // Les places au-dessus des tables
    var ordre = plan.objets.slice().sort(function (a, b) { return (estPlace(a) ? 1 : 0) - (estPlace(b) ? 1 : 0); });
    ordre.forEach(function (o) {
      var w = largeurVue(o) * U, h = hauteurVue(o) * U;
      var g = el('g', { class: 'objet type-' + o.type, transform: 'translate(' + (o.x * U) + ',' + (o.y * U) + ')', 'data-id': o.id }, svg);
      if (o.type === 'place') {
        el('circle', { cx: w / 2, cy: h / 2, r: Math.min(w, h) * 0.42, class: 'forme' }, g);
      } else {
        el('rect', { x: 2, y: 2, width: w - 4, height: h - 4, rx: o.type === 'poste' ? 6 : 3, class: 'forme' }, g);
      }
      if (o.type === 'poste') {
        // écran : côté opposé à l'élève (en haut si r = 0)
        var e = { 0: [w * 0.2, 4, w * 0.6, 6], 90: [w - 10, h * 0.2, 6, h * 0.6], 180: [w * 0.2, h - 10, w * 0.6, 6], 270: [4, h * 0.2, 6, h * 0.6] }[o.r || 0];
        el('rect', { x: e[0], y: e[1], width: e[2], height: e[3], rx: 1.5, class: 'ecran' }, g);
      }
      var texte = estPlace(o) ? nums[o.id] : (o.label || (['table', 'paillasse', 'poste'].indexOf(o.type) >= 0 ? '' : TYPES[o.type].nom.split(' ')[0]));
      if (texte) {
        var t = el('text', { x: w / 2, y: h / 2, class: 'etiquette' + (estPlace(o) ? ' numero-place' : '') }, g);
        t.textContent = texte;
      }
      groupes[o.id] = g;
      if (options.surObjet) options.surObjet(o, g, w, h);
    });
    return groupes;
  }

  /** Coordonnées (en carreaux) d'un événement souris dans le SVG. */
  function pointPlan(svg, ev) {
    var p = svg.createSVGPoint();
    p.x = ev.clientX; p.y = ev.clientY;
    var q = p.matrixTransform(svg.getScreenCTM().inverse());
    return { x: q.x / U, y: q.y / U };
  }

  /* ======================================================================
     Éditeur de salle
     ====================================================================== */

  function editeur() {
    var plan = lireDonnees();
    plan.objets = plan.objets || [];
    var svg = document.getElementById('plan');
    var choisi = null, glisse = null;
    var outils = document.getElementById('outils');
    var champLabel = document.getElementById('outil-label');
    var champL = document.getElementById('largeur'), champH = document.getElementById('hauteur');
    champL.value = plan.largeur; champH.value = plan.hauteur;

    // Palette
    var palette = document.getElementById('palette');
    Object.keys(TYPES).forEach(function (type) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'petit secondaire bouton-type type-' + type;
      b.textContent = TYPES[type].nom;
      b.onclick = function () { ajouter(type); };
      palette.appendChild(b);
    });

    function objet(id) { return plan.objets.find(function (o) { return o.id === id; }); }

    function borner(o) {
      o.x = Math.max(0, Math.min(plan.largeur - largeurVue(o), arrondi(o.x)));
      o.y = Math.max(0, Math.min(plan.hauteur - hauteurVue(o), arrondi(o.y)));
    }

    function ajouter(type, x, y) {
      var t = TYPES[type];
      var o = { id: nouvelId(), type: type, x: x != null ? x : plan.largeur / 2 - t.l / 2, y: y != null ? y : plan.hauteur / 2 - t.h / 2, l: t.l, h: t.h, r: 0, label: '' };
      // décale si la place est déjà prise
      for (var n = 0; n < 40 && plan.objets.some(function (p) { return Math.abs(p.x - o.x) < 0.5 && Math.abs(p.y - o.y) < 0.5; }); n++) {
        o.x += 1; if (o.x > plan.largeur - o.l) { o.x = 0; o.y += 1; }
      }
      borner(o);
      plan.objets.push(o);
      choisir(o.id);
    }

    function choisir(id) {
      choisi = id;
      var o = id && objet(id);
      outils.hidden = !o;
      if (o) {
        document.getElementById('outil-titre').textContent = TYPES[o.type].nom;
        champLabel.value = o.label || '';
        champLabel.placeholder = estPlace(o) ? 'numéro auto' : '';
      }
      rendre();
    }

    function rendre() {
      var groupes = dessiner(svg, plan, {
        grille: true,
        surObjet: function (o, g) {
          g.classList.add('deplacable');
          if (o.id === choisi) g.classList.add('choisi');
          g.addEventListener('pointerdown', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            if (choisi !== o.id) choisir(o.id);
            var p = pointPlan(svg, ev);
            glisse = { id: o.id, dx: p.x - o.x, dy: p.y - o.y };
            svg.setPointerCapture(ev.pointerId);
          });
        },
      });
      var nb = plan.objets.filter(estPlace).length;
      var nbo = plan.objets.filter(function (o) { return o.type === 'poste'; }).length;
      document.getElementById('compte-places').textContent = nb + ' place' + (nb > 1 ? 's' : '') + ' élève' + (nb > 1 ? 's' : '')
        + (nbo ? ' · ' + nbo + ' ordinateur' + (nbo > 1 ? 's' : '') : '') + ' · ' + plan.objets.length + ' objet' + (plan.objets.length > 1 ? 's' : '');
      return groupes;
    }

    svg.addEventListener('pointermove', function (ev) {
      if (!glisse) return;
      var o = objet(glisse.id);
      var p = pointPlan(svg, ev);
      var nx = arrondi(p.x - glisse.dx), ny = arrondi(p.y - glisse.dy);
      if (nx !== o.x || ny !== o.y) {
        o.x = nx; o.y = ny; borner(o);
        var g = svg.querySelector('[data-id="' + o.id + '"]');
        if (g) g.setAttribute('transform', 'translate(' + (o.x * U) + ',' + (o.y * U) + ')');
      }
    });
    svg.addEventListener('pointerup', function () { if (glisse) { glisse = null; rendre(); } });
    svg.addEventListener('pointerdown', function () { choisir(null); });

    function action(nom) {
      var o = objet(choisi);
      if (!o) return;
      if (nom === 'rotation') { o.r = ((o.r || 0) + 90) % 360; }
      else if (nom === 'largeur+') { o.l = Math.min(20, o.l + 0.5); }
      else if (nom === 'largeur-') { o.l = Math.max(0.5, o.l - 0.5); }
      else if (nom === 'hauteur+') { o.h = Math.min(20, o.h + 0.5); }
      else if (nom === 'hauteur-') { o.h = Math.max(0.5, o.h - 0.5); }
      else if (nom === 'dupliquer') {
        var c = Object.assign({}, o, { id: nouvelId(), label: '' });
        c.x += largeurVue(o); if (c.x + largeurVue(c) > plan.largeur) { c.x = o.x; c.y += hauteurVue(o); }
        borner(c); plan.objets.push(c); choisir(c.id); return;
      } else if (nom === 'supprimer') {
        plan.objets = plan.objets.filter(function (p) { return p.id !== o.id; });
        choisir(null); return;
      }
      borner(o);
      rendre();
    }
    outils.querySelectorAll('[data-outil]').forEach(function (b) { b.onclick = function () { action(b.dataset.outil); }; });
    champLabel.addEventListener('input', function () { var o = objet(choisi); if (o) { o.label = champLabel.value; rendre(); } });

    document.addEventListener('keydown', function (ev) {
      if (!choisi || /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) return;
      var o = objet(choisi);
      var pas = { ArrowLeft: [-0.5, 0], ArrowRight: [0.5, 0], ArrowUp: [0, -0.5], ArrowDown: [0, 0.5] }[ev.key];
      if (pas) { o.x += pas[0]; o.y += pas[1]; borner(o); rendre(); ev.preventDefault(); }
      else if (ev.key === 'Delete' || ev.key === 'Backspace') { action('supprimer'); ev.preventDefault(); }
      else if (ev.key === 'r' || ev.key === 'R') { action('rotation'); }
      else if (ev.key === 'd' || ev.key === 'D') { action('dupliquer'); }
      else if (ev.key === 'Escape') { choisir(null); }
    });

    function changerTaille() {
      plan.largeur = Math.max(4, Math.min(60, parseFloat(champL.value) || plan.largeur));
      plan.hauteur = Math.max(4, Math.min(60, parseFloat(champH.value) || plan.hauteur));
      plan.objets.forEach(borner);
      rendre();
    }
    champL.addEventListener('change', changerTaille);
    champH.addEventListener('change', changerTaille);

    // Disposition rapide : tableau, bureau, rangées de tables avec leurs places
    var boutonGenerer = document.getElementById('r-generer');
    boutonGenerer.onclick = function () {
      if (plan.objets.length && !confirmerEnLigne(boutonGenerer, 'Confirmer : remplacer le plan ?')) return;
      var R = +document.getElementById('r-rangees').value || 4;
      var T = +document.getElementById('r-tables').value || 3;
      var P = +document.getElementById('r-places').value || 2;
      var postes = parseFloat(document.getElementById('r-postes').value) || 0;   // 0, 1 ou 0,5 ordinateur par place
      var largeur = Math.max(plan.largeur, T * P + (T + 1) * 1);
      var hauteur = Math.max(plan.hauteur, 3 + R * 3);
      var objets = [];
      objets.push({ id: nouvelId(), type: 'tableau', x: largeur / 2 - 2, y: 0, l: 4, h: 0.5, r: 0, label: '' });
      objets.push({ id: nouvelId(), type: 'bureau', x: 1, y: 1, l: 2, h: 1, r: 0, label: '' });
      objets.push({ id: nouvelId(), type: 'porte', x: largeur - 2.5, y: hauteur - 0.5, l: 2, h: 0.5, r: 0, label: '' });
      var gauche = arrondi((largeur - (T * P + (T - 1))) / 2);
      for (var r = 0; r < R; r++) {
        var y = 3 + r * 3;
        for (var t = 0; t < T; t++) {
          var x = gauche + t * (P + 1);
          objets.push({ id: nouvelId(), type: 'table', x: x, y: y, l: P, h: 1, r: 0, label: '' });
          for (var p = 0; p < P; p++) {
            // la place de l'élève, devant la table
            objets.push({ id: nouvelId(), type: 'place', x: x + p, y: y + 1, l: 1, h: 1, r: 0, label: '' });
          }
          // les ordinateurs, posés sur la table
          var nbOrdis = Math.round(P * postes);
          for (var k = 0; k < nbOrdis; k++) {
            var largeurOrdi = P / nbOrdis;
            objets.push({ id: nouvelId(), type: 'poste', x: arrondi(x + k * largeurOrdi + (largeurOrdi - 1) / 2), y: y, l: 1, h: 1, r: 0, label: '' });
          }
        }
      }
      plan.largeur = largeur; plan.hauteur = hauteur; plan.objets = objets;
      champL.value = largeur; champH.value = hauteur;
      choisir(null);
    };

    document.getElementById('form-plan').addEventListener('submit', function () {
      document.getElementById('champ-plan').value = JSON.stringify(plan);
    });
    window.addEventListener('beforeunload', function () { /* rien : enregistrement explicite */ });
    rendre();
  }

  /* ======================================================================
     Placement des élèves d'une classe
     ====================================================================== */

  function placement() {
    var d = lireDonnees();
    var plan = d.plan;
    var carte = Object.assign({}, d.placement);       // place -> id élève
    var svg = document.getElementById('plan');
    var liste = document.getElementById('liste-eleves');
    var choisi = null;                                 // id de l'élève sélectionné

    function nom(e) {
      var n = window.CentraleNoms ? window.CentraleNoms.nomLocal(d.classe, e.numero) : '';
      return n || ('n° ' + e.numero);
    }
    function eleve(id) { return d.eleves.find(function (e) { return e.id === id; }); }
    function placeDe(id) { return Object.keys(carte).find(function (p) { return carte[p] === id; }); }

    function poser(place, id) {
      var ancienne = placeDe(id);
      if (ancienne) delete carte[ancienne];
      carte[place] = id;
    }

    function rendre() {
      var nums = numeros(plan);
      dessiner(svg, plan, {
        surObjet: function (o, g, w, h) {
          if (!estPlace(o)) return;
          g.classList.add('place-cliquable');
          var id = carte[o.id];
          if (id) {
            g.classList.add('occupee');
            var e = eleve(id);
            var n = el('text', { x: w / 2, y: h + 13, class: 'nom-eleve' }, g);
            n.textContent = e ? nom(e) : '?';
          }
          g.addEventListener('click', function () {
            if (choisi) { poser(o.id, choisi); choisi = null; }
            else if (carte[o.id]) { choisi = carte[o.id]; delete carte[o.id]; }
            rendre();
          });
          g.addEventListener('dragover', function (ev) { ev.preventDefault(); g.classList.add('survol'); });
          g.addEventListener('dragleave', function () { g.classList.remove('survol'); });
          g.addEventListener('drop', function (ev) {
            ev.preventDefault();
            var idE = +ev.dataTransfer.getData('text/plain');
            if (idE) { poser(o.id, idE); choisi = null; rendre(); }
          });
        },
      });
      // Liste des élèves
      liste.innerHTML = '';
      d.eleves.forEach(function (e) {
        var li = document.createElement('li');
        var p = placeDe(e.id);
        li.className = (p ? 'place ' : '') + (e.id === choisi ? 'choisi ' : '') + (e.actif ? '' : 'inactif');
        li.draggable = true;
        li.innerHTML = '<span class="numero"></span><span class="nom"></span><span class="ou"></span>';
        li.querySelector('.numero').textContent = e.numero;
        li.querySelector('.nom').textContent = nom(e);
        li.querySelector('.ou').textContent = p ? 'place ' + nums[p] : '';
        li.onclick = function () { choisi = choisi === e.id ? null : e.id; rendre(); };
        li.addEventListener('dragstart', function (ev) { ev.dataTransfer.setData('text/plain', String(e.id)); });
        liste.appendChild(li);
      });
      var nbPlaces = plan.objets.filter(estPlace).length;
      var nbPlaces2 = Object.keys(carte).length;
      document.getElementById('compte-places').textContent = nbPlaces2 + ' élève' + (nbPlaces2 > 1 ? 's' : '') + ' placé' + (nbPlaces2 > 1 ? 's' : '')
        + ' sur ' + d.eleves.length + ' · ' + nbPlaces + ' places élèves dans la salle'
        + (document.getElementById('compte-places').dataset.alerte ? ' · ⚠ ' + document.getElementById('compte-places').dataset.alerte : '');
    }

    var boutonVider = document.getElementById('p-vider');
    boutonVider.onclick = function () {
      if (confirmerEnLigne(boutonVider, 'Confirmer : tout vider ?')) { carte = {}; choisi = null; rendre(); }
    };
    document.getElementById('p-aleatoire').onclick = function () {
      var places = plan.objets.filter(estPlace).map(function (o) { return o.id; });
      var eleves = d.eleves.filter(function (e) { return e.actif; }).map(function (e) { return e.id; });
      for (var i = places.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = places[i]; places[i] = places[j]; places[j] = t; }
      carte = {};
      eleves.forEach(function (id, i) { if (places[i]) carte[places[i]] = id; });
      if (eleves.length > places.length) {
        document.getElementById('compte-places').dataset.alerte = 'Il manque ' + (eleves.length - places.length) + ' place(s) dans la salle.';
      }
      choisi = null;
      rendre();
    };
    document.getElementById('form-placement').addEventListener('submit', function () {
      document.getElementById('champ-placement').value = JSON.stringify(carte);
    });
    // admin.js (chargé ensuite) fournit les noms : on redessine quand la page est prête, et quand la liste locale change
    document.addEventListener('DOMContentLoaded', rendre);
    document.addEventListener('centrale-noms', rendre);
    rendre();
  }

  return { editeur: editeur, placement: placement, dessiner: dessiner, TYPES: TYPES };
})();
