/**
 * Centrale élèves — plans de salle.
 *  - PlanSalle.editeur()    : dessiner la salle (tables, postes, places élèves, tableau…), toujours dans le sens réel
 *  - PlanSalle.placement()  : placer les élèves d'une classe sur les places de la salle
 *  - PlanSalle.apercu()     : plan en lecture seule (accueil)
 *  - PlanSalle.barreVue()   : présentation (plan / 3/4 avec angle, portrait, miroirs, sol), sur cet ordinateur
 * Les noms des élèves viennent de l'ordinateur de l'enseignant (admin.js), jamais du serveur.
 * Affichage : plan.sol et plan.vue (enregistrés avec la salle), remplacés par la barre de présentation si elle est utilisée.
 */
window.PlanSalle = (function () {
  'use strict';

  var U = 40;          // pixels par carreau (≈ 50 cm)
  var MARGE = 0.5;     // marge autour de la salle, en carreaux
  var NS = 'http://www.w3.org/2000/svg';
  var C = Math.cos(Math.PI / 6), S = 0.5;   // vue 3/4 : axes à 30°
  var MURS = 2.6;      // hauteur des murs de fond (carreaux)
  var SOCLE = 0.35;    // épaisseur de la dalle (carreaux)
  var CLE_VUE = 'centrale-vue';   // présentation choisie sur cet ordinateur : { sol, vue: { iso, angle, portrait, miroirX, miroirY } }

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

  // Hauteurs en vue 3/4 : [dessous, dessus] en carreaux
  var VOLUMES = {
    table: [0, 0.75], paillasse: [0, 0.9], bureau: [0, 0.8], place: [0, 0.45],
    poste: [0.75, 1.05], tableau: [0.9, 2], armoire: [0, 2.2], porte: [0, 2.1], fenetre: [0.9, 2],
  };

  // Écran et clavier d'un ordinateur, en fractions de l'objet, selon son orientation (r) : l'élève est du côté opposé à l'écran
  var ECRAN = { 0: [0.15, 0.08, 0.7, 0.12], 90: [0.8, 0.15, 0.12, 0.7], 180: [0.15, 0.8, 0.7, 0.12], 270: [0.08, 0.15, 0.12, 0.7] };
  var CLAVIER = { 0: [0.2, 0.55, 0.6, 0.25], 90: [0.2, 0.2, 0.25, 0.6], 180: [0.15, 0.2, 0.7, 0.25], 270: [0.55, 0.2, 0.25, 0.6] };

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

  /** Élève sorti définitivement (date saisie dans la liste de la classe) : il ne se place plus. */
  function sorti(classe, numero) {
    // un élève sorti ou exclu ne se place plus (le changement de classe crée un nouvel élève dans la nouvelle classe)
    var statuts = window.CentraleNoms && window.CentraleNoms.statuts ? window.CentraleNoms.statuts(classe, numero) : {};
    return !!(statuts.sortie || statuts.exclu);
  }

  /** Numéro affiché de chaque place : son étiquette, sinon son rang (de gauche à droite, de haut en bas). */
  function numeros(plan) {
    var places = plan.objets.filter(estPlace).slice().sort(function (a, b) { return (a.y - b.y) || (a.x - b.x); });
    var res = {};
    places.forEach(function (o, i) { res[o.id] = o.label || String(i + 1); });
    return res;
  }

  /* ======================================================================
     Présentation : plan, 3/4 (angle continu), portrait, miroirs, sol
     ====================================================================== */

  function vueLocale() {
    try { return JSON.parse(localStorage.getItem(CLE_VUE)) || null; } catch (e) { return null; }
  }

  /** Le plan tel qu'il s'affiche : réglages de la salle, remplacés par ceux de la barre de présentation s'ils existent. */
  function affichage(plan) {
    var l = vueLocale();
    if (!l) return plan;
    return Object.assign({}, plan, { sol: l.sol || plan.sol, vue: Object.assign({}, l.vue || {}) });
  }

  /** Angle de la vue 3/4 en degrés (0 à 359). Les anciens réglages par quarts de tour restent lisibles. */
  function angleDe(plan) {
    var v = plan.vue || {};
    if (v.angle != null && !isNaN(parseFloat(v.angle))) return ((parseFloat(v.angle) % 360) + 360) % 360;
    return ((((parseInt(v.rot, 10) || 0) % 4) + 4) % 4) * 90;
  }

  /**
   * Transformation d'affichage : portrait (salle tournée de 90°) et miroirs. Les formes restent des rectangles ;
   * les textes (noms, numéros) ne sont jamais tournés ni retournés.
   */
  function transformation(plan, sansVue) {
    var v = sansVue ? {} : (plan.vue || {});
    var portrait = !!v.portrait, mx = !!v.miroirX, my = !!v.miroirY;
    var L = portrait ? plan.hauteur : plan.largeur;
    var H = portrait ? plan.largeur : plan.hauteur;
    return {
      L: L,
      H: H,
      rect: function (x, y, w, h) {
        var pts = [[x, y], [x + w, y + h]].map(function (p) {
          var vx = portrait ? plan.hauteur - p[1] : p[0];
          var vy = portrait ? p[0] : p[1];
          return [mx ? L - vx : vx, my ? H - vy : vy];
        });
        return {
          x: Math.min(pts[0][0], pts[1][0]), y: Math.min(pts[0][1], pts[1][1]),
          w: Math.abs(pts[1][0] - pts[0][0]), h: Math.abs(pts[1][1] - pts[0][1]),
        };
      },
    };
  }

  /** Repère de la vue 3/4 : la salle tourne autour de son centre d'un angle (en degrés). */
  function contexteIso(L, H, angle) {
    var r = angle * Math.PI / 180;
    return { cx: L / 2, cy: H / 2, cos: Math.cos(r), sin: Math.sin(r) };
  }
  /** Coordonnées (en carreaux) après rotation : servent à savoir quelle face est vue. */
  function tournerPt(c, x, y) {
    var dx = x - c.cx, dy = y - c.cy;
    return [c.cx + dx * c.cos - dy * c.sin, c.cy + dx * c.sin + dy * c.cos];
  }
  /** Projection 3/4 d'un point (x, y) du plan, à la hauteur z ; résultat en pixels. */
  function projeter(c, x, y, z) {
    var p = tournerPt(c, x, y);
    return [(p[0] - p[1]) * C * U, ((p[0] + p[1]) * S - z) * U];
  }
  function pts(liste) { return liste.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' '); }
  /** Une face (normale (nx, ny) dans le plan) est vue si elle regarde l'observateur, placé du côté (+,+) de la salle. */
  function faceVisible(c, nx, ny) {
    var a = tournerPt(c, c.cx, c.cy), b = tournerPt(c, c.cx + nx, c.cy + ny);
    return (b[0] - a[0]) + (b[1] - a[1]) > 1e-9;
  }
  /** Rectangle (vue) en polygone, dans l'ordre antihoraire. */
  function rectPoly(r) { return [[r.x, r.y], [r.x + r.w, r.y], [r.x + r.w, r.y + r.h], [r.x, r.y + r.h]]; }

  /**
   * Volume : pour chaque face du polygone (vue), dessine les faces visibles entre z0 et z1.
   * classes : { x, y } = classe des faces qui regardent surtout selon x ou selon y (ombrage).
   */
  function extruder(c, poly, z0, z1, classes, parent) {
    var n = poly.length, i, aire = 0;
    var rot = poly.map(function (p) { return tournerPt(c, p[0], p[1]); });
    for (i = 0; i < n; i++) {
      var a = rot[i], b = rot[(i + 1) % n];
      aire += a[0] * b[1] - b[0] * a[1];
    }
    var sens = aire >= 0 ? 1 : -1;
    for (i = 0; i < n; i++) {
      var ra = rot[i], rb = rot[(i + 1) % n], dx = rb[0] - ra[0], dy = rb[1] - ra[1];
      var nx = sens * dy, ny = -sens * dx;
      if (nx + ny <= 1e-9) continue;   // face cachée
      var pa = poly[i], pb = poly[(i + 1) % n];
      el('polygon', {
        points: pts([projeter(c, pa[0], pa[1], z0), projeter(c, pb[0], pb[1], z0), projeter(c, pb[0], pb[1], z1), projeter(c, pa[0], pa[1], z1)]),
        class: Math.abs(nx) >= Math.abs(ny) ? classes.x : classes.y,
      }, parent);
    }
  }

  /** Matrice qui pose le contenu d'un objet (en pixels) sur le dessus de son rectangle r (vue), à la hauteur z. */
  function matriceDessus(c, r, z) {
    var O = projeter(c, r.x, r.y, z), B = projeter(c, r.x + r.w, r.y, z), D = projeter(c, r.x, r.y + r.h, z);
    var lw = r.w * U, lh = r.h * U;
    return 'matrix(' + [(B[0] - O[0]) / lw, (B[1] - O[1]) / lw, (D[0] - O[0]) / lh, (D[1] - O[1]) / lh, O[0], O[1]].join(' ') + ')';
  }

  /* ======================================================================
     Dessin : éléments communs
     ====================================================================== */

  /** Case du spritesheet pour cet élève (trombinoscope), ou null. */
  function photo(classe, numero) {
    return window.CentraleNoms && window.CentraleNoms.visage ? window.CentraleNoms.visage(classe, numero) : null;
  }

  /**
   * Photo recadrée en rond : le visage tombe au centre du rond.
   * p : case du spritesheet (x, y, l, h en pixels de l'image ; fx, fy : centre du visage, 0 à 1).
   */
  function photoPlace(g, w, h, p) {
    var r = Math.min(w, h) * 0.46, s = 2 * r / p.l, W = p.l * s, H = p.h * s;
    var ox = w / 2 - p.fx * W, oy = h / 2 - p.fy * H;   // coin haut-gauche de la photo
    var id = nouvelId(), defs = el('defs', {}, g);
    el('rect', { x: ox, y: oy, width: W, height: H }, el('clipPath', { id: 'cadre-' + id }, defs));
    el('circle', { cx: w / 2, cy: h / 2, r: r }, el('clipPath', { id: 'rond-' + id }, defs));
    var cadre = el('g', { 'clip-path': 'url(#cadre-' + id + ')' }, g);
    el('image', { href: p.image, x: ox - p.x * s, y: oy - p.y * s, width: p.sheetW * s, height: p.sheetH * s, 'clip-path': 'url(#rond-' + id + ')' }, cadre);
    el('circle', { cx: w / 2, cy: h / 2, r: r, class: 'anneau-photo' }, g);
  }

  /**
   * Motif de sol (bois en lames, parquet en blocs) dans <defs> ; renvoie l'identifiant, ou null pour un sol uni.
   * Les couleurs viennent des classes CSS (.sol-fond, .sol-trait), donc suivent le mode jour / nuit.
   */
  function motifSol(defs, type, transform) {
    if (type !== 'bois' && type !== 'parquet') return null;
    var id = nouvelId();
    var taille = type === 'bois' ? { w: 2 * U, h: U } : { w: 2 * U, h: 2 * U };
    var m = el('pattern', { id: id, patternUnits: 'userSpaceOnUse', width: taille.w, height: taille.h }, defs);
    if (transform) m.setAttribute('patternTransform', transform);
    el('rect', { x: 0, y: 0, width: taille.w, height: taille.h, class: 'sol-fond' }, m);
    if (type === 'bois') {
      el('line', { x1: 0, y1: U / 2, x2: 2 * U, y2: U / 2, class: 'sol-trait' }, m);
      el('line', { x1: U / 2, y1: 0, x2: U / 2, y2: U / 2, class: 'sol-trait' }, m);
      el('line', { x1: 3 * U / 2, y1: U / 2, x2: 3 * U / 2, y2: U, class: 'sol-trait' }, m);
      el('line', { x1: 0, y1: U / 4, x2: 2 * U, y2: U / 4, class: 'sol-veine' }, m);
      el('line', { x1: 0, y1: 3 * U / 4, x2: 2 * U, y2: 3 * U / 4, class: 'sol-veine' }, m);
    } else {
      [[0, 0, true], [U, 0, false], [0, U, false], [U, U, true]].forEach(function (b) {
        var x = b[0], y = b[1], horizontal = b[2];
        el('rect', { x: x, y: y, width: U, height: U, class: 'sol-trait' }, m);
        for (var k = 1; k <= 2; k++) {
          if (horizontal) el('line', { x1: x, y1: y + k * U / 3, x2: x + U, y2: y + k * U / 3, class: 'sol-veine' }, m);
          else el('line', { x1: x + k * U / 3, y1: y, x2: x + k * U / 3, y2: y + U, class: 'sol-veine' }, m);
        }
      });
    }
    return id;
  }

  /** Ombre portée douce des formes (vue à plat). */
  function filtreOmbre(defs) {
    var id = nouvelId(), f = el('filter', { id: id, x: '-20%', y: '-20%', width: '140%', height: '150%' }, defs);
    el('feDropShadow', { dx: 1, dy: 2, stdDeviation: 1.4, 'flood-color': '#000', 'flood-opacity': 0.25 }, f);
    return id;
  }

  /** Texte d'une forme, en vue à plat : (u, v) en pixels de l'objet. */
  function etiquetteFlat(g) {
    return function (u, v, z, cls, texte) {
      var t = el('text', { x: u, y: v, class: cls }, g);
      t.textContent = texte;
      return t;
    };
  }

  /** Texte en vue 3/4 : toujours droit, posé à la hauteur z (calque au-dessus de la scène). */
  function etiquetteIso(c, r, calque) {
    return function (u, v, z, cls, texte) {
      var q = projeter(c, r.x + u / U, r.y + v / U, z);
      var t = el('text', { x: q[0], y: q[1], class: cls }, calque);
      t.textContent = texte;
      return t;
    };
  }

  /**
   * Contenu d'un objet (en pixels de l'objet) : forme, écran, texte. r : rectangle de l'objet dans l'affichage.
   * iso : en vue 3/4, le poste et son écran sont des volumes dessinés à part.
   */
  function contenu(o, g, tr, r, lab, nums, ombre, iso) {
    var w = r.w * U, h = r.h * U, zt = (VOLUMES[o.type] || [0, 0.6])[1];
    var attrs = ombre ? { filter: 'url(#' + ombre + ')' } : {};
    if (!(iso && o.type === 'poste')) {
      if (o.type === 'place') {
        el('circle', Object.assign({ cx: w / 2, cy: h / 2, r: Math.min(w, h) * 0.42, class: 'forme' }, attrs), g);
      } else {
        el('rect', Object.assign({ x: 2, y: 2, width: w - 4, height: h - 4, rx: o.type === 'poste' ? 6 : 3, class: 'forme' }, attrs), g);
      }
    }
    if (o.type === 'poste' && !iso) {
      var W0 = largeurVue(o) * U, H0 = hauteurVue(o) * U;
      var e = { 0: [W0 * 0.2, 4, W0 * 0.6, 6], 90: [W0 - 10, H0 * 0.2, 6, H0 * 0.6], 180: [W0 * 0.2, H0 - 10, W0 * 0.6, 6], 270: [4, H0 * 0.2, 6, H0 * 0.6] }[o.r || 0];
      var a = tr.rect(o.x + e[0] / U, o.y + e[1] / U, e[2] / U, e[3] / U);
      el('rect', { x: (a.x - r.x) * U, y: (a.y - r.y) * U, width: a.w * U, height: a.h * U, rx: 1.5, class: 'ecran' }, g);
    }
    var texte = estPlace(o) ? nums[o.id] : (o.label || (['table', 'paillasse', 'poste'].indexOf(o.type) >= 0 ? '' : TYPES[o.type].nom.split(' ')[0]));
    if (texte) lab(w / 2, h / 2, zt, 'etiquette' + (estPlace(o) ? ' numero-place' : ''), texte);
  }

  /** Dessine la salle et ses objets. options.surObjet(o, groupe, w, h, ctx) : ctx.lab (texte), ctx.bulle (bulle au survol). options.sansVue : éditeur. */
  function dessiner(svg, plan, options) {
    options = options || {};
    while (svg.firstChild) svg.removeChild(svg.firstChild);
    var p = options.sansVue ? plan : affichage(plan);
    // vue 3/4 si le plan la demande, ou si l'appelant la impose (tableau de bord de l'accueil)
    if (!options.sansVue && (options.troisD || (p.vue || {}).iso)) {
      if (window.THREE && window.PlanSalle3D) return dessinerTrois(svg, p, options);
      chargerTrois();          // en attendant : vue 3/4 en SVG
      return dessinerIso(svg, p, options);
    }
    return dessinerPlan(svg, p, options);
  }

  /* ======================================================================
     Vue à plat
     ====================================================================== */

  function montrerSvg(svg) {
    if (svg._conteneurTrois) svg._conteneurTrois.style.display = 'none';
    svg.style.display = '';
  }

  function dessinerPlan(svg, plan, options) {
    montrerSvg(svg);
    var tr = transformation(plan, options.sansVue);
    var L = tr.L, H = tr.H;
    svg.setAttribute('viewBox', [-MARGE * U, -MARGE * U, (L + 2 * MARGE) * U, (H + 2 * MARGE) * U].join(' '));
    svg.style.aspectRatio = (L + 2 * MARGE) + ' / ' + (H + 2 * MARGE);
    var defs = el('defs', {}, svg);
    var ombre = filtreOmbre(defs);
    var fond = el('g', { class: 'fond' }, svg);
    var sol = el('rect', { x: 0, y: 0, width: L * U, height: H * U, class: 'sol' }, fond);
    var motif = motifSol(defs, plan.sol, null);
    if (motif) sol.style.fill = 'url(#' + motif + ')';
    if (options.grille) {
      for (var x = 1; x < L; x++) el('line', { x1: x * U, y1: 0, x2: x * U, y2: H * U, class: 'grille' }, fond);
      for (var y = 1; y < H; y++) el('line', { x1: 0, y1: y * U, x2: L * U, y2: y * U, class: 'grille' }, fond);
    }
    el('rect', { x: 0, y: 0, width: L * U, height: H * U, class: 'murs' }, fond);
    var nums = numeros(plan), groupes = {};
    var survol = el('g', { class: 'calque-survol' }, null);   // bulles : ajoutées en dernier, au-dessus de tout
    var ordre = plan.objets.slice().sort(function (a, b) { return (estPlace(a) ? 1 : 0) - (estPlace(b) ? 1 : 0); });
    ordre.forEach(function (o) {
      var r = tr.rect(o.x, o.y, largeurVue(o), hauteurVue(o));
      var g = el('g', { class: 'objet type-' + o.type, transform: 'translate(' + (r.x * U) + ',' + (r.y * U) + ')', 'data-id': o.id }, svg);
      var lab = etiquetteFlat(g);
      contenu(o, g, tr, r, lab, nums, ombre, false);
      groupes[o.id] = g;
      if (options.surObjet) options.surObjet(o, g, r.w * U, r.h * U, {
        lab: lab,
        bulle: function (u, v) {
          var out = el('g', { transform: 'translate(' + (r.x * U + u) + ',' + (r.y * U + v) + ')' }, survol);
          return el('g', { class: 'bulle' }, out);
        },
      });
    });
    svg.appendChild(survol);
    return groupes;
  }

  /* ======================================================================
     Vue 3/4 : dalle, murs de fond, sol, volumes orientés (tabouret, poste)
     ====================================================================== */

  /** Vue 3/4 en 3D (three.js) : la salle est dessinée dans un conteneur à côté du SVG. */
  function dessinerTrois(svg, plan, options) {
    var tr = transformation(plan, false);
    var infos = options.infos || {}, nums = numeros(plan);
    svg.style.display = 'none';
    var conteneur = svg._conteneurTrois;
    if (!conteneur) {
      conteneur = document.createElement('div');
      conteneur.className = 'plan-trois';
      svg.parentNode.insertBefore(conteneur, svg.nextSibling);
      svg._conteneurTrois = conteneur;
    }
    conteneur.style.display = '';
    conteneur.classList.toggle('plan-pleine', !!svg.closest('.dash-vue'));   // tableau de bord : la vue remplit son cadre
    var items = plan.objets.map(function (o) {
      return {
        obj: o,
        rect: tr.rect(o.x, o.y, largeurVue(o), hauteurVue(o)),
        // vue 3D : pas de numéro de place, seulement le nom de l'élève posé (rien si la place est vide)
        label: infos.nomPlace ? infos.nomPlace(o) : (nums[o.id] || ''),
        visage: infos.visagePlace ? infos.visagePlace(o) : null,
        cartons: infos.cartonsPlace ? infos.cartonsPlace(o) : [],
      };
    });
    window.PlanSalle3D.rendre(conteneur, {
      L: tr.L, H: tr.H, sol: plan.sol, angle: angleDe(plan), objets: items, palette: options.palette || 0, etiquette: options.etiquette || null,
      rectFrac: function (o, fx, fy, fw, fh) {
        var lw = largeurVue(o), lh = hauteurVue(o);
        return tr.rect(o.x + fx * lw, o.y + fy * lh, fw * lw, fh * lh);
      },
    }, { surClic: infos.surClic || null, quand: function () { document.dispatchEvent(new Event('centrale-vue')); } });
    return {};
  }

  /** Charge three.js et le rendu 3D (une fois). Hors ligne, la vue 3/4 reste en SVG. */
  var chargementTrois = null;
  function chargerTrois() {
    if (chargementTrois) return;
    chargementTrois = true;
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
    s.onload = function () {
      var t = document.createElement('script');
      t.src = 'plan3d.js?v=' + Date.now();   // pas de version en cache
      t.onload = function () { document.dispatchEvent(new Event('centrale-vue')); };
      document.head.appendChild(t);
    };
    document.head.appendChild(s);
  }

  function dessinerIso(svg, plan, options) {
    montrerSvg(svg);
    var tr = transformation(plan, false);
    var c = contexteIso(tr.L, tr.H, angleDe(plan));
    var L = tr.L, H = tr.H;

    // Cadrage : coins de la dalle, du sol et des murs
    var coins = [[0, 0, 0], [L, 0, 0], [L, H, 0], [0, H, 0], [0, 0, MURS], [L, 0, MURS], [0, H, MURS], [L, H, MURS], [L, H, -SOCLE], [0, H, -SOCLE]]
      .map(function (q) { return projeter(c, q[0], q[1], q[2]); });
    var minX = Math.min.apply(null, coins.map(function (q) { return q[0]; })), maxX = Math.max.apply(null, coins.map(function (q) { return q[0]; }));
    var minY = Math.min.apply(null, coins.map(function (q) { return q[1]; })), maxY = Math.max.apply(null, coins.map(function (q) { return q[1]; }));
    var pad = 0.6 * U, largeur = maxX - minX + 2 * pad, hauteur = maxY - minY + 2 * pad;
    svg.setAttribute('viewBox', [minX - pad, minY - pad, largeur, hauteur].join(' '));
    svg.style.aspectRatio = largeur + ' / ' + hauteur;

    var defs = el('defs', {}, svg);
    var O0 = projeter(c, 0, 0, 0), P10 = projeter(c, 1, 0, 0), P01 = projeter(c, 0, 1, 0);
    var motif = motifSol(defs, plan.sol, 'matrix(' + [(P10[0] - O0[0]) / U, (P10[1] - O0[1]) / U, (P01[0] - O0[0]) / U, (P01[1] - O0[1]) / U, O0[0], O0[1]].join(' ') + ')');

    // Dalle : faces visibles sous le sol
    var dalle = [[0, 0], [L, 0], [L, H], [0, H]];
    extruder(c, dalle, -SOCLE, 0, { x: 'socle-x', y: 'socle-y' }, svg);
    var sol = el('polygon', { points: pts(dalle.map(function (p) { return projeter(c, p[0], p[1], 0); })), class: 'sol' }, svg);
    if (motif) sol.style.fill = 'url(#' + motif + ')';

    // Murs de fond : les deux côtés au fond, selon l'angle (le coin le plus éloigné de l'observateur)
    var rot = dalle.map(function (p) { return tournerPt(c, p[0], p[1]); });
    var k = 0;
    rot.forEach(function (p, i) { if (p[0] + p[1] < rot[k][0] + rot[k][1]) k = i; });
    [(k + 1) % 4, (k + 3) % 4].forEach(function (n) {
      var a = dalle[k], b = dalle[n];
      el('polygon', { points: pts([projeter(c, a[0], a[1], 0), projeter(c, b[0], b[1], 0), projeter(c, b[0], b[1], MURS), projeter(c, a[0], a[1], MURS)]), class: 'mur' }, svg);
    });

    // Objets, du fond vers l'avant
    var nums = numeros(plan), groupes = {};
    var calque = el('g', { class: 'etiquettes' }, null), survol = el('g', { class: 'calque-survol' }, null);
    var ordre = plan.objets.map(function (o) {
      var r = tr.rect(o.x, o.y, largeurVue(o), hauteurVue(o));
      var m = tournerPt(c, r.x + r.w / 2, r.y + r.h / 2);
      return { o: o, r: r, k: m[0] + m[1] + (estPlace(o) ? 0.01 : 0) };
    }).sort(function (a, b) { return a.k - b.k; });

    ordre.forEach(function (item) {
      var o = item.o, r = item.r, v = VOLUMES[o.type] || [0, 0.6];
      var cotes = el('g', { class: 'objet cotes type-' + o.type }, svg);
      el('polygon', { points: pts(rectPoly(r).map(function (p) { return projeter(c, p[0], p[1], 0); })), class: 'ombre' }, cotes);
      if (o.type === 'place') {
        // tabouret : quatre pieds, assise ronde
        var cx = r.x + r.w / 2, cy = r.y + r.h / 2;
        [[-0.26, -0.26], [0.26, -0.26], [0.26, 0.26], [-0.26, 0.26]].forEach(function (d) {
          var q = [[cx + d[0] - 0.05, cy + d[1] - 0.05], [cx + d[0] + 0.05, cy + d[1] - 0.05], [cx + d[0] + 0.05, cy + d[1] + 0.05], [cx + d[0] - 0.05, cy + d[1] + 0.05]];
          extruder(c, q, 0, v[1] - 0.07, { x: 'pied', y: 'pied' }, cotes);
        });
        var anneau = [];
        for (var i = 0; i < 16; i++) anneau.push([cx + 0.42 * Math.cos(2 * Math.PI * i / 16), cy + 0.42 * Math.sin(2 * Math.PI * i / 16)]);
        extruder(c, anneau, v[1] - 0.07, v[1], { x: 'face-x', y: 'face-y' }, cotes);
      } else if (o.type === 'poste') {
        // clavier sur le bureau, écran dressé du côté opposé à l'élève
        var lw = largeurVue(o), lh = hauteurVue(o), kb = CLAVIER[o.r || 0], ec = ECRAN[o.r || 0];
        var rk = tr.rect(o.x + kb[0] * lw, o.y + kb[1] * lh, kb[2] * lw, kb[3] * lh);
        extruder(c, rectPoly(rk), v[0], v[0] + 0.05, { x: 'clavier', y: 'clavier' }, cotes);
        var re = tr.rect(o.x + ec[0] * lw, o.y + ec[1] * lh, ec[2] * lw, ec[3] * lh);
        extruder(c, rectPoly(re), v[0], v[0] + 0.5, { x: 'face-x', y: 'face-y' }, cotes);
        // face de l'écran tournée vers l'élève (côté intérieur du bloc)
        var dx = (r.x + r.w / 2) - (re.x + re.w / 2), dy = (r.y + r.h / 2) - (re.y + re.h / 2);
        if (Math.abs(dx) >= Math.abs(dy)) {
          var sx = dx > 0 ? 1 : -1;
          if (faceVisible(c, sx, 0)) {
            var xf = sx > 0 ? re.x + re.w : re.x;
            el('polygon', { points: pts([projeter(c, xf, re.y + 0.08, v[0] + 0.1), projeter(c, xf, re.y + re.h - 0.08, v[0] + 0.1), projeter(c, xf, re.y + re.h - 0.08, v[0] + 0.45), projeter(c, xf, re.y + 0.08, v[0] + 0.45)]), class: 'ecran-iso' }, cotes);
          }
        } else {
          var sy = dy > 0 ? 1 : -1;
          if (faceVisible(c, 0, sy)) {
            var yf = sy > 0 ? re.y + re.h : re.y;
            el('polygon', { points: pts([projeter(c, re.x + 0.08, yf, v[0] + 0.1), projeter(c, re.x + re.w - 0.08, yf, v[0] + 0.1), projeter(c, re.x + re.w - 0.08, yf, v[0] + 0.45), projeter(c, re.x + 0.08, yf, v[0] + 0.45)]), class: 'ecran-iso' }, cotes);
          }
        }
      } else {
        extruder(c, rectPoly(r), v[0], v[1], { x: 'face-x', y: 'face-y' }, cotes);
      }
      // dessus de l'objet : son contenu (forme, photo, texte) posé sur le plateau
      var g = el('g', { class: 'objet dessus type-' + o.type, transform: matriceDessus(c, r, v[1]), 'data-id': o.id }, svg);
      var lab = etiquetteIso(c, r, calque);
      contenu(o, g, tr, r, lab, nums, null, true);
      groupes[o.id] = g;
      if (options.surObjet) options.surObjet(o, g, r.w * U, r.h * U, {
        lab: lab,
        bulle: function (u, vv, z) {
          var q = projeter(c, r.x + u / U, r.y + vv / U, z);
          var out = el('g', { transform: 'translate(' + q[0] + ',' + q[1] + ')' }, survol);
          return el('g', { class: 'bulle' }, out);
        },
      });
    });
    svg.appendChild(calque);
    svg.appendChild(survol);
    return groupes;
  }

  /* ======================================================================
     Présentation : barre de réglages (accueil, placement)
     ====================================================================== */

  /**
   * Barre « Présentation » : plan ou 3/4 (avec curseur d'angle), portrait, miroirs, sol. Les réglages sont gardés sur cet
   * ordinateur et s'appliquent à tous les plans affichés ici ; « Réglages de chaque salle » les retire.
   * plan (facultatif) : la salle affichée, pour partir de ses réglages.
   */
  function barreVue(conteneur, plan) {
    if (!conteneur) return;
    conteneur.innerHTML = '<div class="barre-vue">'
      + '<span class="barre-titre">Présentation</span>'
      + '<div class="segment" role="group" aria-label="Vue">'
      + '<button type="button" data-iso="0">Plan</button><button type="button" data-iso="1">3/4</button></div>'
      + '<div class="angle-vue" data-angle hidden>'
      + '<input type="range" min="0" max="359" step="1" value="0" data-curseur aria-label="Angle de la vue 3/4">'
      + '<span class="angle-valeur" data-valeur>0°</span>'
      + '<button type="button" class="lien-bouton tour" data-tour>▶ Tour</button></div>'
      + '<label data-plan-only><input type="checkbox" data-cle="portrait"> Portrait</label>'
      + '<label data-plan-only><input type="checkbox" data-cle="miroirX"> Miroir horizontal</label>'
      + '<label data-plan-only><input type="checkbox" data-cle="miroirY"> Miroir vertical</label>'
      + '<label>Sol <select data-sol><option value="">Celui de la salle</option><option value="uni">Uni</option>'
      + '<option value="bois">Bois</option><option value="parquet">Parquet</option></select></label>'
      + '<button type="button" class="lien-bouton" data-reset>Réglages de chaque salle</button>'
      + '</div>';
    var boutons = conteneur.querySelectorAll('[data-iso]'), cases = conteneur.querySelectorAll('[data-cle]');
    var sel = conteneur.querySelector('[data-sol]'), reset = conteneur.querySelector('[data-reset]');
    var angle = conteneur.querySelector('[data-angle]'), curseur = conteneur.querySelector('[data-curseur]');
    var valeur = conteneur.querySelector('[data-valeur]'), tour = conteneur.querySelector('[data-tour]');
    var minuteur = null;

    function reglages() { return (vueLocale() || {}).vue || (plan && plan.vue) || {}; }
    function isoActuel() { return !!reglages().iso; }
    function angleActuel() { return angleDe({ vue: reglages() }); }

    function remplir() {
      var v = reglages(), l = vueLocale() || {};
      boutons.forEach(function (b) { b.classList.toggle('actif', !!v.iso === (b.dataset.iso === '1')); });
      angle.hidden = !v.iso;
      curseur.value = String(Math.round(angleActuel()) % 360);
      valeur.textContent = (Math.round(angleActuel()) % 360) + '°';
      cases.forEach(function (c) { c.checked = !!v[c.dataset.cle]; });
      sel.value = l.sol || '';
    }
    function ecrire(iso, deg) {
      var vue = { iso: !!iso, angle: Math.round(((deg % 360) + 360) % 360) % 360 };
      cases.forEach(function (c) { vue[c.dataset.cle] = c.checked; });
      try { localStorage.setItem(CLE_VUE, JSON.stringify({ sol: sel.value || '', vue: vue })); } catch (e) { /* stockage bloqué */ }
      remplir();
      document.dispatchEvent(new Event('centrale-vue'));
    }
    function arreterTour() {
      if (minuteur) clearInterval(minuteur);
      minuteur = null;
      tour.textContent = '▶ Tour';
      tour.classList.remove('actif');
    }
    function enregistrer(iso, deg) { arreterTour(); ecrire(iso, deg); }

    boutons.forEach(function (b) { b.addEventListener('click', function () { enregistrer(b.dataset.iso === '1', angleActuel()); }); });
    curseur.addEventListener('input', function () { ecrire(true, parseFloat(curseur.value)); });
    tour.addEventListener('click', function () {
      if (minuteur) { arreterTour(); return; }
      tour.textContent = '■ Arrêter';
      tour.classList.add('actif');
      minuteur = setInterval(function () { ecrire(true, angleActuel() + 90); }, 2500);
    });
    cases.forEach(function (c) { c.addEventListener('change', function () { enregistrer(isoActuel(), angleActuel()); }); });
    sel.addEventListener('change', function () { enregistrer(isoActuel(), angleActuel()); });
    reset.addEventListener('click', function () {
      arreterTour();
      try { localStorage.removeItem(CLE_VUE); } catch (e) { /* rien */ }
      remplir();
      document.dispatchEvent(new Event('centrale-vue'));
    });
    remplir();
  }

  /** Mise en page d'impression : A4 en portrait si la salle est affichée en portrait. */
  function orientationPage(plan) {
    var st = document.getElementById('page-plan');
    if (!st) {
      st = document.createElement('style');
      st.id = 'page-plan';
      document.head.appendChild(st);
    }
    st.textContent = '@page { size: A4 ' + ((plan.vue || {}).portrait ? 'portrait' : 'landscape') + '; margin: 10mm; }';
  }

  /** Coordonnées (en carreaux) d'un événement souris dans le SVG. */
  function pointPlan(svg, ev) {
    var p = svg.createSVGPoint();
    p.x = ev.clientX; p.y = ev.clientY;
    var q = p.matrixTransform(svg.getScreenCTM().inverse());
    return { x: q.x / U, y: q.y / U };
  }

  /* ======================================================================
     Éditeur de salle (toujours dans le sens réel de la salle, à plat)
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

    // Affichage enregistré avec la salle : sol, 3/4 et angle, portrait, miroirs
    var selSol = document.getElementById('sol');
    var cIso = document.getElementById('vue-iso'), cPortrait = document.getElementById('vue-portrait');
    var cMiroirX = document.getElementById('vue-miroir-x'), cMiroirY = document.getElementById('vue-miroir-y');
    var curseurAngle = document.getElementById('vue-angle'), valeurAngle = document.getElementById('vue-angle-valeur');
    selSol.value = plan.sol || 'uni';
    cIso.checked = !!(plan.vue || {}).iso;
    cPortrait.checked = !!(plan.vue || {}).portrait;
    cMiroirX.checked = !!(plan.vue || {}).miroirX;
    cMiroirY.checked = !!(plan.vue || {}).miroirY;
    curseurAngle.value = String(Math.round(angleDe(plan)) % 360);
    valeurAngle.textContent = curseurAngle.value + '°';
    function enregistrerAffichage() {
      plan.sol = selSol.value;
      plan.vue = { iso: cIso.checked, angle: parseInt(curseurAngle.value, 10) || 0, portrait: cPortrait.checked, miroirX: cMiroirX.checked, miroirY: cMiroirY.checked };
      valeurAngle.textContent = curseurAngle.value + '°';
      rendre();
    }
    [selSol, cIso, cPortrait, cMiroirX, cMiroirY, curseurAngle].forEach(function (c) { c.addEventListener('change', enregistrerAffichage); });
    curseurAngle.addEventListener('input', function () { valeurAngle.textContent = curseurAngle.value + '°'; });

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
        sansVue: true,   // l'éditeur reste dans le sens réel de la salle (glisser, flèches)
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
      var P2 = +document.getElementById('r-places').value || 2;
      var postes = parseFloat(document.getElementById('r-postes').value) || 0;   // 0, 1 ou 0,5 ordinateur par place
      var largeur = Math.max(plan.largeur, T * P2 + (T + 1) * 1);
      var hauteur = Math.max(plan.hauteur, 3 + R * 3);
      var objets = [];
      objets.push({ id: nouvelId(), type: 'tableau', x: largeur / 2 - 2, y: 0, l: 4, h: 0.5, r: 0, label: '' });
      objets.push({ id: nouvelId(), type: 'bureau', x: 1, y: 1, l: 2, h: 1, r: 0, label: '' });
      objets.push({ id: nouvelId(), type: 'porte', x: largeur - 2.5, y: hauteur - 0.5, l: 2, h: 0.5, r: 0, label: '' });
      var gauche = arrondi((largeur - (T * P2 + (T - 1))) / 2);
      for (var r = 0; r < R; r++) {
        var y = 3 + r * 3;
        for (var t = 0; t < T; t++) {
          var x = gauche + t * (P2 + 1);
          objets.push({ id: nouvelId(), type: 'table', x: x, y: y, l: P2, h: 1, r: 0, label: '' });
          for (var p = 0; p < P2; p++) {
            // la place de l'élève, devant la table
            objets.push({ id: nouvelId(), type: 'place', x: x + p, y: y + 1, l: 1, h: 1, r: 0, label: '' });
          }
          // les ordinateurs, posés sur la table
          var nbOrdis = Math.round(P2 * postes);
          for (var k = 0; k < nbOrdis; k++) {
            var largeurOrdi = P2 / nbOrdis;
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

  /** Bulle sans photo : le nom de l'élève, dans un disque, apparaît au survol. */
  function bulleNom(g, ctx, texte, u, v, z) {
    var bulle = ctx.bulle(u, v, z);
    var inter = el('g', {}, bulle);
    el('circle', { cx: 0, cy: 0, r: 34, class: 'bulle-fond' }, inter);
    var t = el('text', { x: 0, y: 4, class: 'bulle-texte' }, inter);
    t.textContent = String(texte).slice(0, 14);
    g.addEventListener('mouseenter', function () { bulle.classList.add('montre'); });
    g.addEventListener('mouseleave', function () { bulle.classList.remove('montre'); });
  }

  /** Bulle du visage : apparaît au survol de la place et grandit. */
  function bulleVisage(g, ctx, visage, u, v, z) {
    var bulle = ctx.bulle(u, v, z);
    var W = 64;
    var interieur = el('g', { transform: 'translate(' + (-W / 2) + ',' + (-W / 2) + ')' }, bulle);
    photoPlace(interieur, W, W, visage);
    g.addEventListener('mouseenter', function () { bulle.classList.add('montre'); });
    g.addEventListener('mouseleave', function () { bulle.classList.remove('montre'); });
  }

  function placement() {
    var d = lireDonnees();
    var plan = d.plan;
    var carte = Object.assign({}, d.placement);       // place -> id élève
    var svg = document.getElementById('plan');
    var liste = document.getElementById('liste-eleves');
    var choisi = null;                                 // id de l'élève sélectionné
    barreVue(document.getElementById('barre-vue'), plan);

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

    /** Clic sur une place (plan ou vue 3D) : pose l'élève sélectionné, ou reprend celui de la place. */
    function clicPlace(id) {
      if (choisi) { poser(id, choisi); choisi = null; }
      else if (carte[id]) { choisi = carte[id]; delete carte[id]; }
      rendre();
    }

    /** Ce que la vue 3D affiche sur chaque place : le nom de l'élève et sa photo. */
    var infosPlaces = {
      nomPlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e ? nom(e) : ''; },
      visagePlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e ? photo(d.classe, e.numero) : null; },
      cartonsPlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e && window.CentraleNoms && window.CentraleNoms.cartons ? window.CentraleNoms.cartons(d.classe, e.numero).map(function (c) { return c.type; }) : []; },
      surClic: function (idPlace) { clicPlace(idPlace); },
    };

    function rendre() {
      orientationPage(affichage(plan));
      var nums = numeros(plan);
      dessiner(svg, plan, {
        surObjet: function (o, g, w, h, ctx) {
          if (!estPlace(o)) return;
          g.classList.add('place-cliquable');
          var id = carte[o.id];
          if (id) {
            g.classList.add('occupee');
            var e = eleve(id);
            var visage = e ? photo(d.classe, e.numero) : null;
            if (visage) photoPlace(g, w, h, visage);
            ctx.lab(w / 2, h + 13, 0, 'nom-eleve', e ? nom(e) : '?');
            var bes = e && window.CentraleNoms && window.CentraleNoms.besoins ? window.CentraleNoms.besoins(d.classe, e.numero) : [];
            if (bes.length) {   // petit repère sur la place (masqué à l'impression)
              var m = el('circle', { cx: w - 5, cy: 5, r: 4.5, class: 'marque-besoin' }, g);
              el('title', {}, m).textContent = bes.join(', ');
            }
            if (visage) bulleVisage(g, ctx, visage, w / 2, h / 2, 0.9);
            else bulleNom(g, ctx, e ? nom(e) : '?', w / 2, h / 2, 0.9);
          }
          g.addEventListener('click', function () { clicPlace(o.id); });
          g.addEventListener('dragover', function (ev) { ev.preventDefault(); g.classList.add('survol'); });
          g.addEventListener('dragleave', function () { g.classList.remove('survol'); });
          g.addEventListener('drop', function (ev) {
            ev.preventDefault();
            var idE = +ev.dataTransfer.getData('text/plain');
            if (idE) { poser(o.id, idE); choisi = null; rendre(); }
          });
        },
        infos: infosPlaces,
      });
      // Liste des élèves, dans l'ordre alphabétique
      liste.innerHTML = '';
      d.eleves.slice().sort(function (a, b) {
        return nom(a).localeCompare(nom(b), 'fr', { numeric: true });
      }).forEach(function (e) {
        var li = document.createElement('li');
        var place = placeDe(e.id);
        li.className = (place ? 'place ' : '') + (e.id === choisi ? 'choisi ' : '') + (e.actif && !sorti(d.classe, e.numero) ? '' : 'inactif');
        li.draggable = true;
        li.innerHTML = '<span class="vignette"></span><span class="numero"></span><span class="nom"></span><span class="ou"></span>';
        var visage = photo(d.classe, e.numero);
        if (visage) window.CentraleNoms.styleVisage(li.querySelector('.vignette'), visage, 26 / visage.l, 26, 32);
        else li.querySelector('.vignette').title = window.CentraleNoms && window.CentraleNoms.raison ? window.CentraleNoms.raison(d.classe, e.numero) : '';
        li.querySelector('.numero').textContent = e.numero;
        li.querySelector('.nom').textContent = nom(e);
        var b = window.CentraleNoms && window.CentraleNoms.besoins ? window.CentraleNoms.besoins(d.classe, e.numero) : [];
        if (b.length) li.querySelector('.nom').after(window.CentraleNoms.pastilles(b));
        var nbCartons = window.CentraleNoms && window.CentraleNoms.cartons ? window.CentraleNoms.cartons(d.classe, e.numero).length : 0;
        if (nbCartons) li.querySelector('.nom').after(creer('span', 'pastille-carton', String(nbCartons)));
        li.querySelector('.ou').textContent = place ? 'place ' + nums[place] : '';
        li.onclick = function () { choisi = choisi === e.id ? null : e.id; rendre(); };
        var fiche = creer('button', 'bouton-fiche', '🗂');
        fiche.type = 'button';
        fiche.title = 'Fiche et cartons de l’élève';
        fiche.addEventListener('click', function (ev) { ev.stopPropagation(); ouvrirFicheDialogue(d, e, place ? nums[place] : null); });
        li.appendChild(fiche);
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
      var eleves = d.eleves.filter(function (e) { return e.actif && !sorti(d.classe, e.numero); }).map(function (e) { return e.id; });
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
    // « Enregistrer comme version » : garde le plan tel qu'il est affiché
    var formVersion = document.getElementById('form-version');
    if (formVersion) formVersion.addEventListener('submit', function () {
      document.getElementById('champ-version').value = JSON.stringify(carte);
    });
    // admin.js (chargé ensuite) fournit les noms : on redessine quand la page est prête, quand la liste locale change, quand la présentation change
    document.addEventListener('DOMContentLoaded', rendre);
    document.addEventListener('centrale-noms', rendre);
    document.addEventListener('centrale-vue', rendre);
    rendre();
  }

  /** Un élément avec sa classe et son texte. */
  function creer(balise, classe, texte) {
    var n = document.createElement(balise);
    if (classe) n.className = classe;
    if (texte != null) n.textContent = texte;
    return n;
  }

  /** « NOM Prénom » : le nom de famille est en capitales, le prénom suit. */
  function separerNom(texte) {
    var mots = String(texte).trim().split(/\s+/), i = 0;
    while (i < mots.length - 1 && /[A-ZÀ-Ý]/.test(mots[i]) && mots[i] === mots[i].toUpperCase()) i++;
    var nom = mots.slice(0, i).join(' '), prenom = mots.slice(i).join(' ');
    return prenom ? { prenom: prenom, nom: nom } : { prenom: nom, nom: '' };
  }

  /** Contenu de la fiche d'un élève (identité, détail, cartons, code), dans le conteneur donné. */
  function remplirFiche(corps, d, e, placeLabel, actualiser) {
    var cn = window.CentraleNoms;
    var numero = String(e.numero), nomComplet = cn ? cn.nomLocal(d.classe, e.numero) : '';
    var identite = separerNom(nomComplet || ('n° ' + numero));
    var visage = photo(d.classe, e.numero);
    var statuts = cn && cn.statuts ? cn.statuts(d.classe, e.numero) : {};
    var besoins = cn && cn.besoins ? cn.besoins(d.classe, e.numero) : [];
    var code = cn && cn.codes ? (cn.codes(d.classe)[numero] || '') : '';

    // en-tête : grande photo, prénom en gros, nom de famille dessous plus petit
    var haut = creer('div', 'fiche-trois-haut');
    var pho = creer('div', 'fiche-trois-photo');
    if (visage) cn.styleVisage(pho, visage, 120 / visage.l, 120, 120);
    else pho.textContent = (identite.prenom[0] || '') + (identite.nom[0] || '');
    var titres = creer('div', 'fiche-trois-titres');
    titres.append(creer('p', 'fiche-trois-prenom', identite.prenom));
    if (identite.nom) titres.append(creer('p', 'fiche-trois-nom', identite.nom));
    titres.append(creer('p', 'fiche-trois-classe', 'Classe ' + d.classe + ' · élève n° ' + numero));
    haut.append(pho, titres);
    corps.appendChild(haut);

    // détail : place, état, besoins
    var detail = creer('dl', 'fiche-trois-details');
    function rang(libelle, valeur) {
      detail.append(creer('dt', '', libelle), valeur instanceof Node ? valeur : creer('dd', '', valeur));
      if (valeur instanceof Node) valeur.classList.add('fiche-trois-valeur');
    }
    rang('Place', placeLabel ? 'place ' + placeLabel : 'non placé');
    var etats = [];
    if (statuts.sortie) etats.push('Sorti depuis le ' + statuts.sortie);
    if (statuts.exclu) etats.push('Exclu depuis le ' + statuts.exclu);
    if (statuts.changement) etats.push('Changement de classe depuis le ' + statuts.changement);
    rang('État', (etats.length ? etats.join(' · ') : 'Présent') + (e.actif ? '' : ' · compte désactivé'));
    if (besoins.length && cn.pastilles) rang('Besoins', cn.pastilles(besoins));
    corps.appendChild(detail);

    if (cn && cn.cartons) corps.appendChild(sectionCartons(d.classe, e.numero, actualiser));

    // code de connexion : seulement la copie gardée sur cet ordinateur, masquée jusqu'au clic
    var bloc = creer('div', 'fiche-trois-code');
    titres.appendChild(bloc);   // le code est en haut, à côté du prénom et du nom
    if (!code) {
      bloc.textContent = 'Code de connexion : non enregistré sur cet ordinateur';
    } else {
      var valeur = creer('span', '', '');
      var bouton = creer('button', 'secondaire petit', '');
      bouton.type = 'button';
      function montrer(on) {
        valeur.textContent = on ? code : '•••••••••';
        bouton.textContent = on ? 'Masquer le code' : 'Voir le code';
      }
      bouton.addEventListener('click', function () { montrer(bouton.textContent === 'Voir le code'); });
      bloc.append('Code de connexion : ', valeur, ' ', bouton);
      montrer(false);
    }
  }

  /** Cartons de l'élève : cartons préremplis en rang, carton personnalisé, puis la liste des cartons donnés. */
  function sectionCartons(classe, numero, actualiser) {
    var cn = window.CentraleNoms, bloc = creer('div', 'fiche-trois-cartons');
    bloc.appendChild(creer('p', 'fiche-trois-cartons-titre', 'Cartons'));

    // cartons préremplis : un clic donne le carton ; alignés en rang, décollés au survol
    [['observation', 'Observations', cn.motifsCartons, 'carton-pre-observation'],
     ['recompense', 'Récompenses', cn.motifsRecompenses, 'carton-pre-recompense']].forEach(function (g) {
      var rang = creer('div', 'cartons-rang');
      rang.appendChild(creer('span', 'cartons-rang-titre', g[1]));
      var cases = creer('div', 'cartons-cases');
      g[2].forEach(function (m) {
        if (m === cn.autreMotif) return;
        var b = creer('button', 'carton-pre ' + g[3], m);
        b.type = 'button';
        b.title = 'Donner : ' + m;
        b.addEventListener('click', function () { cn.ajouterCarton(classe, numero, g[0], m); actualiser(); });
        cases.appendChild(b);
      });
      rang.appendChild(cases);
      bloc.appendChild(rang);
    });

    // carton personnalisé : même champ que ci-dessous
    bloc.appendChild(creer('p', 'cartons-sous-titre', 'Carton personnalisé'));
    var form = creer('form', 'carton-ajout');
    var choix = creer('select', '');
    Object.keys(cn.typesCartons).forEach(function (t) { var o = creer('option', '', cn.typesCartons[t]); o.value = t; choix.appendChild(o); });
    var motifs = creer('select', '');
    function remplirMotifs() {   // les motifs suivent le type choisi
      motifs.replaceChildren();
      var vide = creer('option', '', 'Motif…');
      vide.value = '';
      motifs.appendChild(vide);
      (choix.value === 'recompense' ? cn.motifsRecompenses : cn.motifsCartons).forEach(function (m) { var o = creer('option', '', m); o.value = m; motifs.appendChild(o); });
    }
    choix.addEventListener('change', remplirMotifs);
    remplirMotifs();
    var champ = creer('input', '');
    champ.type = 'text';
    champ.maxLength = 200;
    champ.placeholder = 'Précision (facultative)';
    var ajouter = creer('button', 'petit', 'Ajouter');
    ajouter.type = 'submit';
    form.append(choix, motifs, champ, ajouter);
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var motif = motifs.value, precision = champ.value.trim();
      if (!motif) return;
      if (motif === cn.autreMotif && !precision) { champ.focus(); return; }   // « Autre » demande une précision
      var texte = motif === cn.autreMotif ? precision : motif + (precision ? ' — ' + precision : '');
      cn.ajouterCarton(classe, numero, choix.value, texte);
      actualiser();
    });
    bloc.appendChild(form);

    // liste des cartons déjà donnés, avec retrait
    bloc.appendChild(creer('p', 'cartons-sous-titre', 'Cartons donnés'));
    var liste = cn.cartons(classe, numero);
    if (!liste.length) bloc.appendChild(creer('p', 'discret', 'Aucun carton pour l’instant.'));
    liste.forEach(function (c, i) {
      var ligne = creer('div', 'carton carton-' + c.type);
      var retirer = creer('button', 'lien-bouton carton-retirer', '×');
      retirer.type = 'button';
      retirer.title = 'Retirer ce carton';
      retirer.addEventListener('click', function () { cn.supprimerCarton(classe, numero, i); actualiser(); });
      ligne.append(creer('span', 'carton-type', cn.typesCartons[c.type] || c.type), creer('span', 'carton-texte', c.texte),
        creer('span', 'carton-date', c.date), retirer);
      bloc.appendChild(ligne);
    });
    return bloc;
  }

  /**
   * Fiche d'un élève, en surimpression sur toute la vue 3D : photo, identité, place, état, besoins,
   * puis code de connexion (gardé sur cet ordinateur, masqué jusqu'à « Voir le code »).
   * Fermeture : × , clic à côté, Échap.
   */
  function ficheEleve(svg, d, carte, idPlace) {
    var conteneur = svg._conteneurTrois, id = carte[idPlace];
    var e = id && (d.eleves || []).find(function (x) { return x.id === id; });
    if (!conteneur || !e) return;
    var cn = window.CentraleNoms;
    var fiche = conteneur.querySelector('.fiche-trois');
    if (!fiche) {
      fiche = creer('div', 'fiche-trois');
      fiche.hidden = true;
      var carteFiche = creer('div', 'fiche-trois-carte');
      carteFiche.setAttribute('role', 'dialog');
      carteFiche.setAttribute('aria-label', 'Fiche élève');
      var fermer = creer('button', 'fiche-trois-fermer', '×');
      fermer.type = 'button';
      fermer.setAttribute('aria-label', 'Fermer');
      carteFiche.append(fermer, creer('div', 'fiche-trois-corps'));
      fiche.appendChild(carteFiche);
      conteneur.appendChild(fiche);
      fermer.addEventListener('click', function () { fiche.hidden = true; });
      fiche.addEventListener('click', function (ev) { if (ev.target === fiche) fiche.hidden = true; });
      document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !fiche.hidden) fiche.hidden = true; });
    }
    var corps = fiche.querySelector('.fiche-trois-corps');
    corps.replaceChildren();

    remplirFiche(corps, d, e, numeros(d.plan)[idPlace], function () { ficheEleve(svg, d, carte, idPlace); });
    fiche.hidden = false;
    fiche.querySelector('.fiche-trois-fermer').focus({ preventScroll: true });
  }

  /** Fiche d'un élève dans une boîte de dialogue (page plan de classe) : cartons, détail, code. */
  var dialogueFicheEleve = null;
  function ouvrirFicheDialogue(d, e, placeLabel) {
    if (!dialogueFicheEleve) {
      dialogueFicheEleve = creer('dialog', 'dialogue-fiche-eleve');
      var fermer = creer('form', 'dialogue-fermer');
      fermer.method = 'dialog';
      var bouton = creer('button', 'fiche-trois-fermer', '×');
      bouton.setAttribute('aria-label', 'Fermer');
      fermer.appendChild(bouton);
      dialogueFicheEleve.append(fermer, creer('div', 'fiche-trois-corps'));
      document.body.appendChild(dialogueFicheEleve);
    }
    var corps = dialogueFicheEleve.querySelector('.fiche-trois-corps');
    corps.replaceChildren();
    remplirFiche(corps, d, e, placeLabel, function () { ouvrirFicheDialogue(d, e, placeLabel); });
    if (dialogueFicheEleve.open) dialogueFicheEleve.close();
    dialogueFicheEleve.showModal();
  }

  /** Étiquette de l'accueil : pastille + atelier en cours et nombre d'observations (cartons, lus dans le navigateur). */
  function etiquetteAccueil(d) {
    var cn = window.CentraleNoms, observations = 0;
    (d.eleves || []).forEach(function (e) {
      if (cn && cn.cartons) cn.cartons(d.classe, e.numero).forEach(function (c) { if (c.type !== 'recompense') observations++; });
    });
    var actifs = (d.eleves || []).filter(function (e) { return e.actif; }).length;
    return Object.assign({}, d.etiquette, { atelier: d.atelier || 'aucun cours', eleves: actifs, observations: observations });
  }

  /** Aperçu en lecture seule d'un plan placé (accueil) : dessine la salle + les élèves posés. */
  function apercu(svg, d) {
    if (!svg || !d || !d.plan) return;
    var carte = d.placement || {}, enAttente = false;
    function eleve(id) { return (d.eleves || []).find(function (e) { return e.id === id; }); }
    function nom(e) { var n = window.CentraleNoms ? window.CentraleNoms.nomLocal(d.classe, e.numero) : ''; return n || ('n° ' + e.numero); }
    function rendre() {
      // l'accueil montre la salle en 3D : tant que three.js n'est pas chargé, on n'affiche pas la vue 2D (elle clignoterait)
      if (!(window.THREE && window.PlanSalle3D)) {
        if (enAttente) return;
        enAttente = true;
        chargerTrois();
        document.addEventListener('centrale-vue', function attendre() {
          if (!(window.THREE && window.PlanSalle3D)) return;
          document.removeEventListener('centrale-vue', attendre);
          enAttente = false;
          rendre();
        });
        return;
      }
      dessiner(svg, d.plan, {
        troisD: true,   // l'accueil montre toujours la salle en 3D
        palette: d.classe_id || 0,   // couleurs propres à chaque classe
        etiquette: d.etiquette ? etiquetteAccueil(d) : null,   // pastille de la classe, posée sur le mur face à la caméra
        surObjet: function (o, g, w, h, ctx) {
          if (!estPlace(o) || !carte[o.id]) return;
          g.classList.add('occupee');
          var e = eleve(carte[o.id]);
          var visage = e ? photo(d.classe, e.numero) : null;
          if (visage) photoPlace(g, w, h, visage);
          ctx.lab(w / 2, h + 13, 0, 'nom-eleve', e ? nom(e) : '?');
          if (visage) bulleVisage(g, ctx, visage, w / 2, h / 2, 0.9);
          else bulleNom(g, ctx, e ? nom(e) : '?', w / 2, h / 2, 0.9);
        },
        infos: {
          nomPlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e ? nom(e) : ''; },
          visagePlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e ? photo(d.classe, e.numero) : null; },
      cartonsPlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e && window.CentraleNoms && window.CentraleNoms.cartons ? window.CentraleNoms.cartons(d.classe, e.numero).map(function (c) { return c.type; }) : []; },
          surClic: function (idPlace) { ficheEleve(svg, d, carte, idPlace); },
        },
      });
    }
    rendre();
    document.addEventListener('centrale-noms', rendre);   // redessine quand les noms locaux sont chargés
    document.addEventListener('centrale-vue', rendre);    // et quand la présentation change
  }

  return { editeur: editeur, placement: placement, dessiner: dessiner, apercu: apercu, barreVue: barreVue, TYPES: TYPES };
})();
