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
    Object.keys(attrs || {}).forEach(function (k) { if (attrs[k] != null) e.setAttribute(k, attrs[k]); });
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

  /**
   * Nom d'un élève sous sa place (vue à plat) : prénom, puis NOM en petit dessous, chacun ramené à la largeur disponible
   * (jusqu'à la place voisine) : resserré un peu, puis coupé avec « … » s'il reste trop long. Le nom complet est au survol.
   */
  function nomSurPlace(g, w, h, texte, max) {
    var bloc = blocNom(g, w / 2, h + 11, texte, max);
    el('title', {}, g).textContent = texte;
    return bloc;
  }

  /**
   * Prénom (puis NOM en petit dessous) centré en (cx, y), sur un encadré jaune transparent qui le fait ressortir.
   * max : largeur disponible ; un nom trop long est resserré un peu, puis coupé avec « … ».
   */
  function blocNom(parent, cx, y, texte, max, grand) {
    // grand : sous la photo agrandie au survol — plus gros et opaque
    var k = grand ? 1.45 : 1;
    var id = separerNom(texte), bloc = el('g', { class: 'bloc-nom' + (grand ? ' grand' : '') }, parent), larg = 0;
    function longueur(txt, t, taille) {
      var l = txt.getComputedTextLength ? txt.getComputedTextLength() : 0;
      return l || t.length * taille * 0.56;   // pas encore affiché (calque des bulles) : estimation
    }
    function ligne(yl, cls, t, taille) {
      var txt = el('text', { x: cx, y: yl, class: 'nom-eleve ' + cls }, bloc);
      txt.textContent = t;
      var l = longueur(txt, t, taille);
      if (l > max) {
        var s2 = t;
        while (s2.length > 3 && l > max / 0.8) { s2 = s2.slice(0, -1); txt.textContent = s2.replace(/[\s-]+$/, '') + '…'; l = longueur(txt, txt.textContent, taille); }
        if (l > max) { txt.setAttribute('textLength', max); txt.setAttribute('lengthAdjust', 'spacingAndGlyphs'); l = max; }
      }
      larg = Math.max(larg, l);
    }
    ligne(y, 'prenom-place', id.prenom, 10 * k);
    if (id.nom) ligne(y + 9 * k, 'nom-place', id.nom, 8 * k);
    var haut = (id.nom ? 21 : 12) * k, pad = 3 * k;
    var cadre = el('rect', { x: cx - larg / 2 - pad, y: y - 9 * k, width: larg + 2 * pad, height: haut, rx: 3 * k, class: 'cadre-nom' }, null);
    bloc.insertBefore(cadre, bloc.firstChild);
    bloc._boite = { x: cx - larg / 2 - pad, y: y - 9 * k, w: larg + 2 * pad, h: haut };   // dans le repère du parent
    return bloc;
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
    // largeur disponible pour le nom sous chaque place : jusqu'à la place voisine sur la même rangée
    var rectsPlaces = plan.objets.filter(estPlace).map(function (o) { return { id: o.id, r: tr.rect(o.x, o.y, largeurVue(o), hauteurVue(o)) }; });
    // Étiquettes déjà posées (repère de la salle, en pixels) : pour éviter les superpositions
    var etiquettesPosees = [];
    function chevauche(a, b) { return a.x < b.x + b.w - 1 && b.x < a.x + a.w - 1 && a.y < b.y + b.h - 1 && b.y < a.y + a.h - 1; }
    /**
     * L'étiquette est sous la place ; si elle recouvre une autre place (la photo de l'élève de la rangée de dessous) ou
     * une étiquette déjà posée, elle passe au-dessus de la photo — sauf si elle y gênerait autant.
     */
    function placerNom(bloc, id, r) {
      var b = bloc._boite;
      if (!b) return;
      function recouvrement(z, q) { return Math.max(0, Math.min(z.x + z.w, q.x + q.w) - Math.max(z.x, q.x)) * Math.max(0, Math.min(z.y + z.h, q.y + q.h) - Math.max(z.y, q.y)); }
      function gene(z) {   // surface recouverte : places des autres élèves et étiquettes déjà posées
        var t = 0;
        rectsPlaces.forEach(function (q) { if (q.id !== id) t += recouvrement(z, { x: q.r.x * U, y: q.r.y * U, w: q.r.w * U, h: q.r.h * U }); });
        etiquettesPosees.forEach(function (e) { t += recouvrement(z, e); });
        return t;
      }
      function candidats(h) {
        var dessous = { x: r.x * U + b.x, y: r.y * U + b.y, w: b.w, h: h };
        return [dessous, { x: dessous.x, y: r.y * U - 2 - h, w: b.w, h: h }];   // sous la place, ou au-dessus (bas de l'étiquette 2 px au-dessus)
      }
      function poser(z, h) {
        var dy = z.y - (r.y * U + b.y);
        if (dy) { bloc.setAttribute('transform', 'translate(0,' + dy + ')'); bloc.classList.add('au-dessus'); }
        etiquettesPosees.push({ x: z.x, y: z.y, w: z.w, h: h });
      }
      function libre(z) { return gene(z) <= z.w * z.h * 0.06; }   // un coin effleuré ne compte pas
      // 1. prénom et nom : dessous, sinon au-dessus
      var c = candidats(b.h);
      if (libre(c[0])) return poser(c[0], b.h);
      if (libre(c[1])) return poser(c[1], b.h);
      // 2. trop serré : le prénom seul (le nom complet reste au survol), dessous ou au-dessus
      var nomLigne = bloc.querySelector('.nom-place'), cadre = bloc.querySelector('.cadre-nom');
      var h1 = nomLigne ? b.h - 9 : b.h;
      if (nomLigne) { nomLigne.remove(); cadre.setAttribute('height', h1); }
      var c1 = candidats(h1);
      if (libre(c1[0])) return poser(c1[0], h1);
      if (libre(c1[1])) return poser(c1[1], h1);
      // 3. sinon : la position qui gêne le moins
      poser(gene(c1[1]) < gene(c1[0]) ? c1[1] : c1[0], h1);
    }
    function largeurNom(id, r) {
      var cx = r.x + r.w / 2, cy = r.y + r.h / 2, d = 2.4;
      rectsPlaces.forEach(function (q) {
        if (q.id === id || Math.abs(q.r.y + q.r.h / 2 - cy) > 0.7) return;
        d = Math.min(d, Math.abs(q.r.x + q.r.w / 2 - cx));
      });
      return Math.max(r.w, d) * U - 4;
    }
    ordre.forEach(function (o) {
      var r = tr.rect(o.x, o.y, largeurVue(o), hauteurVue(o));
      var g = el('g', { class: 'objet type-' + o.type, transform: 'translate(' + (r.x * U) + ',' + (r.y * U) + ')', 'data-id': o.id }, svg);
      var lab = etiquetteFlat(g);
      contenu(o, g, tr, r, lab, nums, ombre, false);
      groupes[o.id] = g;
      if (options.surObjet) options.surObjet(o, g, r.w * U, r.h * U, {
        lab: lab,
        nom: function (texte) { var bloc = nomSurPlace(g, r.w * U, r.h * U, texte, largeurNom(o.id, r)); placerNom(bloc, o.id, r); return bloc; },
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
      liens: infos.liens ? infos.liens() : [],   // liens entre élèves (à séparer, bonne dynamique)
      rectFrac: function (o, fx, fy, fw, fh) {
        var lw = largeurVue(o), lh = hauteurVue(o);
        return tr.rect(o.x + fx * lw, o.y + fy * lh, fw * lw, fh * lh);
      },
    }, { surClic: infos.surClic || null, surGlisser: infos.surGlisser || null, peutGlisser: infos.peutGlisser || null, libelle: infos.libelle || null,
         surLacherAilleurs: infos.surLacherAilleurs || null, immediat: infos.immediat || null,
         eviter: function () {   // accueil : les widgets posés sur la vue (le titre de la classe se place là où ils ne le cachent pas)
           var vue = svg.closest('.dash-vue');
           if (!vue) return [];
           return Array.prototype.filter.call(vue.querySelectorAll('.dz > *'), function (x) { return x.offsetParent && x.offsetWidth && x.offsetHeight; })
             .map(function (x) { return x.getBoundingClientRect(); });
         },
         quand: function () { document.dispatchEvent(new Event('centrale-vue')); } });
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
  /** Petits dessins de la barre de présentation et des options du plan (traits, couleur du texte). */
  var ICONES_BARRE = {
    plan: '<rect x="3.5" y="3.5" width="17" height="17" rx="2"/><path d="M3.5 9.5h17M9.5 3.5v17"/>',
    iso: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
    portrait: '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M10.5 17.5h3"/>',
    miroirX: '<path d="M12 3v18" stroke-dasharray="2 2"/><path d="M9 7l-5 5 5 5z"/><path d="M15 7l5 5-5 5z"/>',
    miroirY: '<path d="M3 12h18" stroke-dasharray="2 2"/><path d="M7 9l5-5 5 5z"/><path d="M7 15l5 5 5-5z"/>',
    sol: '<path d="M3 15l9-4 9 4-9 4z"/><path d="M7.5 13l9 4M16.5 13l-9 4"/>',
    tour: '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v4.5h-4.5"/>',
    reset: '<path d="M4 12a8 8 0 1 0 2.3-5.7"/><path d="M4 4v4.5h4.5"/>',
    echange: '<path d="M4 8h14l-3.5-3.5"/><path d="M20 16H6l3.5 3.5"/>',
    disposer: '<path d="M12 3v18M3 12h18"/><path d="M9 6l3-3 3 3M9 18l3 3 3-3M6 9l-3 3 3 3M18 9l3 3-3 3"/>',
    ajouter: '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M12 8v8M8 12h8"/>',
    classe: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M8.5 10h7M8.5 14h7M8.5 18h4"/>',
    progression: '<path d="M4 20h16"/><path d="M6.5 16v-3M11 16V9M15.5 16v-6M20 7l-4.5 3-4.5-3-4.5 4"/>',
  };
  function iconeBarre(nom) {
    return '<svg class="ico-barre" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
      + (ICONES_BARRE[nom] || '') + '</svg>';
  }
  /** Bouton à bascule de la barre (remplace les cases à cocher) : aria-pressed porte l'état. */
  function puceBarre(attrs, icone, texte, titre) {
    return '<button type="button" class="puce-option" aria-pressed="false" ' + attrs + ' title="' + titre + '">' + iconeBarre(icone)
      + '<span>' + texte + '</span></button>';
  }

  function barreVue(conteneur, plan) {
    if (!conteneur) return;
    conteneur.innerHTML = '<div class="barre-vue">'
      + '<div class="barre-ligne">'
      + '<span class="barre-titre">Présentation</span>'
      + '<div class="segment" role="group" aria-label="Vue">'
      + '<button type="button" data-iso="0" title="Vue à plat">' + iconeBarre('plan') + '<span>Plan</span></button>'
      + '<button type="button" data-iso="1" title="Vue en 3/4 (perspective)">' + iconeBarre('iso') + '<span>3/4</span></button></div>'
      + '<div class="angle-vue" data-angle hidden>'
      + '<input type="range" min="0" max="359" step="1" value="0" data-curseur aria-label="Angle de la vue 3/4">'
      + '<span class="angle-valeur" data-valeur>0°</span>'
      + '<button type="button" class="puce-option" data-tour title="Faire tourner la salle d’un quart de tour toutes les 2,5 s">' + iconeBarre('tour') + '<span data-tour-txt>Tour</span></button></div>'
      + '<div class="barre-groupe" data-plan-only role="group" aria-label="Orientation du plan">'
      + puceBarre('data-cle="portrait"', 'portrait', 'Portrait', 'Salle tournée en hauteur (et impression en portrait)')
      + puceBarre('data-cle="miroirX"', 'miroirX', 'Miroir ↔', 'Miroir horizontal : la gauche et la droite échangées')
      + puceBarre('data-cle="miroirY"', 'miroirY', 'Miroir ↕', 'Miroir vertical : le haut et le bas échangés (vue depuis le fond de la salle)')
      + '</div>'
      + '<label class="barre-sol" title="Revêtement du sol">' + iconeBarre('sol') + '<span class="visuel-cache">Sol</span><select data-sol aria-label="Sol">'
      + '<option value="">Sol de la salle</option><option value="uni">Sol uni</option>'
      + '<option value="bois">Sol en bois</option><option value="parquet">Parquet</option></select></label>'
      + '<button type="button" class="bouton-icone" data-reset title="Revenir aux réglages de chaque salle (oublier ceux de cet ordinateur)" aria-label="Réglages de chaque salle">' + iconeBarre('reset') + '</button>'
      + '</div>'
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
      conteneur.querySelectorAll('[data-plan-only]').forEach(function (x) { x.hidden = !!v.iso; });   // portrait, miroirs : vue à plat seulement
      curseur.value = String(Math.round(angleActuel()) % 360);
      valeur.textContent = (Math.round(angleActuel()) % 360) + '°';
      cases.forEach(function (c) { c.setAttribute('aria-pressed', String(!!v[c.dataset.cle])); });
      sel.value = l.sol || '';
    }
    function ecrire(iso, deg) {
      var vue = { iso: !!iso, angle: Math.round(((deg % 360) + 360) % 360) % 360 };
      cases.forEach(function (c) { vue[c.dataset.cle] = c.getAttribute('aria-pressed') === 'true'; });
      try { localStorage.setItem(CLE_VUE, JSON.stringify({ sol: sel.value || '', vue: vue })); } catch (e) { /* stockage bloqué */ }
      remplir();
      document.dispatchEvent(new Event('centrale-vue'));
    }
    function arreterTour() {
      if (minuteur) clearInterval(minuteur);
      minuteur = null;
      tour.querySelector('[data-tour-txt]').textContent = 'Tour';
      tour.setAttribute('aria-pressed', 'false');
    }
    function enregistrer(iso, deg) { arreterTour(); ecrire(iso, deg); }

    boutons.forEach(function (b) { b.addEventListener('click', function () { enregistrer(b.dataset.iso === '1', angleActuel()); }); });
    curseur.addEventListener('input', function () { ecrire(true, parseFloat(curseur.value)); });
    tour.addEventListener('click', function () {
      if (minuteur) { arreterTour(); return; }
      tour.querySelector('[data-tour-txt]').textContent = 'Arrêter';
      tour.setAttribute('aria-pressed', 'true');
      minuteur = setInterval(function () { ecrire(true, angleActuel() + 90); }, 2500);
    });
    cases.forEach(function (c) { c.addEventListener('click', function () {
      c.setAttribute('aria-pressed', String(c.getAttribute('aria-pressed') !== 'true'));
      enregistrer(isoActuel(), angleActuel());
    }); });
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
        afficherTaille(o);
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

    /** Portes et fenêtres se règlent au décimètre près (épaisseur comme largeur) ; le reste au demi-mètre. */
    function pasDe(o) { return o.type === 'porte' || o.type === 'fenetre' ? 0.1 : 0.5; }
    function afficherTaille(o) {
      document.getElementById('outil-taille').textContent = o.l.toFixed(1) + ' × ' + o.h.toFixed(1) + ' m';
    }

    function action(nom) {
      var o = objet(choisi);
      if (!o) return;
      var pas = pasDe(o);
      if (nom === 'rotation') { o.r = ((o.r || 0) + 90) % 360; }
      else if (nom === 'largeur+') { o.l = Math.min(20, Math.round((o.l + pas) * 10) / 10); }
      else if (nom === 'largeur-') { o.l = Math.max(pas, Math.round((o.l - pas) * 10) / 10); }
      else if (nom === 'hauteur+') { o.h = Math.min(20, Math.round((o.h + pas) * 10) / 10); }
      else if (nom === 'hauteur-') { o.h = Math.max(pas, Math.round((o.h - pas) * 10) / 10); }
      else if (nom === 'dupliquer') {
        var c = Object.assign({}, o, { id: nouvelId(), label: '' });
        c.x += largeurVue(o); if (c.x + largeurVue(c) > plan.largeur) { c.x = o.x; c.y += hauteurVue(o); }
        borner(c); plan.objets.push(c); choisir(c.id); return;
      } else if (nom === 'supprimer') {
        plan.objets = plan.objets.filter(function (p) { return p.id !== o.id; });
        choisir(null); return;
      }
      borner(o);
      afficherTaille(o);
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

  /** Couleurs des liens entre élèves (mêmes qu'en 3D : plan3d.js, COULEURS_LIENS). */
  var COULEURS_LIENS = { altercation: '#c0392b', dissipation: '#e0a800', entente: '#2e8b57' };
  /**
   * Vue à plat : un arc entre deux élèves liés, sous les photos ; un rond au milieu (💬 ⚔️ 🤝 et le nombre de fois).
   * trop : les duos à séparer assis trop près (duosTropPres) — leur trait est plus marqué.
   */
  function tracerLiens2D(svg, plan, tr, liens, trop) {
    if (!liens.length) return;
    var pres = {};
    (trop || []).forEach(function (c) { pres[[c.pa, c.pb].sort().join('|')] = c.voisins ? 'voisins' : 'proches'; });
    function centre(id) {
      var o = plan.objets.find(function (x) { return x.id === id; });
      if (!o) return null;
      var r = tr.rect(o.x, o.y, largeurVue(o), hauteurVue(o));
      return { x: (r.x + r.w / 2) * U, y: (r.y + r.h / 2) * U };
    }
    var calque = el('g', { class: 'calque-duos calque-liens' }, null);
    svg.insertBefore(calque, svg.querySelector('.objet.type-place') || svg.querySelector('.calque-survol'));
    var badges = el('g', { class: 'liens-badges' }, null);
    liens.forEach(function (l, i) {
      var A = centre(l.a), B = centre(l.b);
      if (!A || !B) return;
      var dx = B.x - A.x, dy = B.y - A.y, dist = Math.hypot(dx, dy) || 1, courbe = (i % 2 ? -1 : 1) * Math.min(0.22 * dist, 40);
      var C = { x: (A.x + B.x) / 2 - dy / dist * courbe, y: (A.y + B.y) / 2 + dx / dist * courbe };
      var M = { x: (A.x + 2 * C.x + B.x) / 4, y: (A.y + 2 * C.y + B.y) / 4 };   // milieu de l'arc
      var etat = l.type === 'entente' ? '' : (pres[[l.a, l.b].sort().join('|')] || '');
      var g = el('g', { class: 'lien-eleves lien-' + l.type + (etat ? ' ' + etat : '') }, calque);
      el('path', { d: 'M' + A.x + ',' + A.y + ' Q' + C.x + ',' + C.y + ' ' + B.x + ',' + B.y, fill: 'none', stroke: COULEURS_LIENS[l.type],
        'stroke-width': 2.5 + Math.min(l.nb, 4) + (etat === 'voisins' ? 2 : 0), 'stroke-linecap': 'round', 'stroke-dasharray': etat === 'proches' ? '6 5' : null }, g);
      el('title', {}, g).textContent = l.titre;
      var b = el('g', { class: 'lien-badge lien-' + l.type, transform: 'translate(' + M.x + ',' + M.y + ')' }, badges);
      el('circle', { r: 8.5, fill: '#ffffff', stroke: COULEURS_LIENS[l.type], 'stroke-width': 2 }, b);
      el('text', { y: 3.2, 'text-anchor': 'middle', 'font-size': 9 }, b).textContent = l.icone;
      if (l.nb > 1) {
        el('circle', { cx: 7.5, cy: -7, r: 5, fill: COULEURS_LIENS[l.type] }, b);
        el('text', { x: 7.5, y: -4.8, 'text-anchor': 'middle', 'font-size': 6.5, 'font-weight': 800, fill: '#ffffff' }, b).textContent = String(l.nb);
      }
      el('title', {}, b).textContent = l.titre;
      g.setAttribute('data-liens', l.a + ' ' + l.b);
      b.setAttribute('data-liens', l.a + ' ' + l.b);
    });
    svg.insertBefore(badges, svg.querySelector('.calque-survol'));   // les ronds au-dessus des photos, sous les bulles
    // survol d'un élève : ses liens ressortent, les autres s'effacent (comme en 3D)
    svg.querySelectorAll('.objet.type-place').forEach(function (gp) {
      var id = gp.getAttribute('data-id');
      gp.addEventListener('mouseenter', function () {
        var aDesLiens = false;
        svg.querySelectorAll('[data-liens]').forEach(function (x) {
          var a = x.getAttribute('data-liens').split(' ').indexOf(id) >= 0;
          x.classList.toggle('actif', a);
          aDesLiens = aDesLiens || a;
        });
        svg.classList.toggle('liens-focus', aDesLiens);
      });
      gp.addEventListener('mouseleave', function () { svg.classList.remove('liens-focus'); });
    });
  }

  /** Bulle sans photo : le nom de l'élève, dans un disque, apparaît au survol. */
  /**
   * Vue à plat : les pastilles d'un élève, comme en 3D — la plateforme (☁ puis les repères) au-dessus de la photo,
   * ce qui est noté en classe sous le nom (sous la photo si le nom a dû passer au-dessus).
   */
  function pastilles2D(g, w, h, blocNom, jetons) {
    var plat = jetons.filter(function (t) { return t.indexOf('rep-') === 0; });
    var enClasse = jetons.filter(function (t) { return t !== '|' && t.indexOf('rep-') !== 0; });
    if (!plat.length && !enClasse.length) return;
    // boîte du nom (repère de la place), décalage compris s'il est passé au-dessus de la photo
    var boite = null;
    if (blocNom && blocNom.getBBox) {
      try {
        var bb = blocNom.getBBox(), m = /translate\(0,\s*(-?[\d.]+)\)/.exec(blocNom.getAttribute('transform') || ''), dy = m ? +m[1] : 0;
        boite = { haut: bb.y + dy, bas: bb.y + dy + bb.height };
      } catch (err) { boite = null; }
    }
    var nomAuDessus = boite && boite.bas <= 2;
    var D = 11, PAS = 12;   // diamètre d'une pastille, pas entre deux
    function rangee(liste, yCentre, cls) {
      var montres = liste.slice(0, 8), n = montres.length + (liste.length > 8 ? 1 : 0), x0 = w / 2 - (n * PAS - (PAS - D)) / 2 + D / 2;
      var grp = el('g', { class: 'pastilles-2d ' + cls }, g);
      montres.forEach(function (t, i) { pastille2D(grp, t, x0 + i * PAS, yCentre, D); });
      if (liste.length > 8) {
        el('rect', { x: x0 + 8 * PAS - D / 2, y: yCentre - D / 2, width: D, height: D, rx: 2, fill: '#ffffff', stroke: '#1d1f24', 'stroke-width': .6 }, grp);
        var tp = el('text', { x: x0 + 8 * PAS, y: yCentre + 2.6, 'text-anchor': 'middle', 'font-size': 6.5, 'font-weight': 800, fill: '#1d1f24' }, grp);
        tp.textContent = '+' + (liste.length - 8);
      }
    }
    if (plat.length) rangee(['nuage'].concat(plat), Math.min(0, nomAuDessus ? boite.haut : 0) - D / 2 - 2, 'plateforme');
    if (enClasse.length) rangee(enClasse, (boite && !nomAuDessus ? Math.max(h, boite.bas) : h) + D / 2 + 2, 'en-classe');
  }
  /** Une pastille de la vue à plat (mêmes formes et couleurs qu'en 3D : plan3d.js, carteCartons). */
  function pastille2D(grp, t, cx, cy, D) {
    var r = D / 2, fond, texte = '', couleurTexte = '#ffffff', carre = false, def;
    if (t === 'nuage') { fond = '#2b6cb0'; texte = '☁'; carre = true; }
    else if (t.indexOf('rep-') === 0 && (def = REPERES[t.slice(4)])) { fond = def.couleur; texte = def.glyphe; carre = true; }
    else if ((def = CLASSE[t])) { fond = def.couleur; texte = def.glyphe; couleurTexte = def.texte || '#ffffff'; }
    else if (t.indexOf('ob:') === 0) {
      var mo = t.split(':'), niv = +mo[2], gv = GRAVITE[mo[3]] || GRAVITE.orange;
      fond = niv > 1 ? '#1e6b42' : niv > 0 ? '#2e8b57' : niv < -1 ? gv.fort : gv.couleur;
      couleurTexte = niv > 0 ? '#ffffff' : niv < -1 ? gv.texteFort : gv.texte;
      texte = mo[1];
    } else if (t === 'recompense') { fond = '#2e8b57'; }
    else { el('rect', { x: cx - 3.5, y: cy - r, width: 7, height: D, fill: '#f2b705', stroke: '#ffffff', 'stroke-width': .8 }, grp); return; }   // carton jaune
    if (carre) el('rect', { x: cx - r, y: cy - r, width: D, height: D, rx: 2.5, fill: fond, stroke: '#ffffff', 'stroke-width': .8 }, grp);
    else el('circle', { cx: cx, cy: cy, r: r, fill: fond, stroke: '#ffffff', 'stroke-width': .8 }, grp);
    if (texte) {
      var tx = el('text', { x: cx, y: cy + 2.4, 'text-anchor': 'middle', 'font-size': texte.length > 1 ? 5.5 : 7, 'font-weight': 800, fill: couleurTexte }, grp);
      tx.textContent = texte;
    }
  }

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
  function bulleVisage(g, ctx, visage, u, v, z, texte) {
    var bulle = ctx.bulle(u, v, z);
    var W = 64;
    var interieur = el('g', { transform: 'translate(' + (-W / 2) + ',' + (-W / 2) + ')' }, bulle);
    photoPlace(interieur, W, W, visage);
    if (texte) blocNom(bulle, 0, W / 2 + 16, texte, 170, true);   // agrandie, la photo garde son nom juste dessous, en grand
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
    // options du plan : deuxième ligne de la même barre (voir « Options du plan » plus bas)
    var barreOptions = creer('div', 'barre-ligne plan-options');
    (document.querySelector('#barre-vue .barre-vue') || document.getElementById('barre-vue')).appendChild(barreOptions);

    function nom(e) {
      var n = window.CentraleNoms ? window.CentraleNoms.nomLocal(d.classe, e.numero) : '';
      return n || e.simule || ('n° ' + e.numero);
    }
    function eleve(id) { return d.eleves.find(function (e) { return e.id === id; }); }
    function placeDe(id) { return Object.keys(carte).find(function (p) { return carte[p] === id; }); }

    /** Pose l'élève sur la place ; si elle est prise, son occupant part à l'ancienne place de l'élève (échange),
        ou redevient non placé si l'élève venait de la liste. */
    function poser(place, id) {
      if (apercu) return;   // aperçu d'un nouveau plan : on le garde ou on revient d'abord
      var ancienne = placeDe(id), occupant = carte[place];
      if (ancienne) delete carte[ancienne];
      carte[place] = id;
      if (occupant && occupant !== id) {
        if (ancienne) carte[ancienne] = occupant;
      }
      modifie();
    }
    /** Le plan affiché diffère du plan enregistré : on le signale (et « Charger une variante » demande confirmation). */
    var initial = JSON.stringify(Object.keys(carte).sort().map(function (k) { return [k, carte[k]]; }));
    function modifie() {
      var maintenant = JSON.stringify(Object.keys(carte).sort().map(function (k) { return [k, carte[k]]; }));
      document.body.classList.toggle('plan-modifie', maintenant !== initial);
    }
    var ignorerClic = false;   // un glisser-déposer ne compte pas comme un clic
    var apercu = null;          // aperçu d'un nouveau plan : { avant: plan affiché avant, minimal } ; carte est alors la proposition

    /* Mode échange : un clic sur un élève du plan le sélectionne (il reste à sa place), un clic sur un autre élève :
       les deux échangent leurs places ; sur une place libre : il s'y déplace ; sur lui-même : la sélection s'annule. */
    var modeEchange = false, placeChoisie = null;
    var modeDisposer = false;   // « Disposer les places » : glisser une place la déplace dans la salle (commune à toutes les classes)
    try { modeEchange = localStorage.getItem('centrale-plan-echange') === '1'; } catch (e) { /* rien */ }

    /** Clic sur une place (plan ou vue 3D) : pose l'élève sélectionné, ou reprend celui de la place (mode échange : voir plus haut). */
    function clicPlace(id) {
      if (ignorerClic) { ignorerClic = false; return; }
      if (apercu || modeDisposer) return;
      if (modeEchange && !choisi) {
        if (placeChoisie) { if (placeChoisie !== id && carte[placeChoisie]) poser(id, carte[placeChoisie]); placeChoisie = null; }
        else if (carte[id]) placeChoisie = id;
        rendre();
        return;
      }
      if (choisi) { poser(id, choisi); choisi = null; }
      else if (carte[id]) { choisi = carte[id]; delete carte[id]; modifie(); }
      rendre();
    }
    /** Glisser un élève d'une place à une autre (vue à plat ou 3D) : les deux élèves échangent leurs places. */
    function glisser(de, vers) {
      if (!carte[de] || de === vers) return;
      poser(vers, carte[de]);
      choisi = null;
      rendre();
    }

    /** Ce que la vue 3D affiche sur chaque place : le nom de l'élève et sa photo. */
    function nomNumero(num) { var e = d.eleves.find(function (x) { return String(x.numero) === String(num); }); return e ? nom(e) : 'n° ' + num; }
    var infosPlaces = {
      liens: function () { return liensPlaces(d, carte, nomNumero); },
      nomPlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e ? (o.id === placeChoisie ? '⇄ ' : '') + nom(e) : ''; },
      visagePlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e ? photo(d.classe, e.numero) : null; },
      cartonsPlace: function (o) {
        var id = carte[o.id], e = id && eleve(id);
        if (!e || !window.CentraleNoms || !window.CentraleNoms.cartons) return [];
        // en classe (hors de la classe, absences et retards répétés, cartons) | plateforme (repères)
        var classe = classeVisible() ? observationsClasse(d, e).map(function (r) { return r.code; })
          .concat(window.CentraleNoms.cartons(d.classe, e.numero).map(function (c) { return c.type; })) : [];
        var plateforme = reperesVisibles() ? reperesEleve(d, e).filter(function (r) { return REPERES[r.code]; }).map(function (r) { return 'rep-' + r.code; }) : [];
        return classe.concat(classe.length && plateforme.length ? ['|'] : [], plateforme);
      },
      surClic: function (idPlace) { clicPlace(idPlace); },
      surGlisser: glisser,
      peutGlisser: function (idPlace) { return !apercu && !!carte[idPlace]; },
      libelle: function (idPlace) { var e = eleve(carte[idPlace]); return e ? nom(e) : ''; },
      // lâché hors de la vue 3D : dans « Hors de la classe », ou dans la liste (l'élève n'est plus placé)
      surLacherAilleurs: function (idPlace, sous) { lacheHorsPlan(idPlace, sous); },
    };

    /* ---------- Hors de la classe : exclu du cours (garde sa place), exclu ou sorti de l'établissement ---------- */
    var zoneDehors = document.getElementById('zone-dehors');
    function lacheHorsPlan(idPlace, sous) {
      if (!sous || !sous.closest) return false;
      if (sous.closest('#zone-dehors')) { if (carte[idPlace]) sortir(carte[idPlace]); return true; }
      if (sous.closest('#liste-eleves')) { delete carte[idPlace]; modifie(); rendre(); return true; }
      return false;
    }
    var dialogueDehors = null;
    /** Demande le motif, puis met l'élève dehors (statut enregistré sur la Centrale, par numéro). */
    function sortir(idEleve) {
      var e = eleve(idEleve), cn = window.CentraleNoms;
      if (!e || !cn || !cn.definirStatut) return;
      if (!dialogueDehors) {
        dialogueDehors = creer('dialog', 'dialogue-dehors');
        document.body.appendChild(dialogueDehors);
      }
      dialogueDehors.replaceChildren();
      dialogueDehors.append(creer('h3', '', nom(e) + ' : hors de la classe'), creer('p', 'discret', 'Motif (avec la date du jour) :'));
      var choix = creer('div', 'dehors-choix');
      [['exclu_cours', 'Exclu du cours aujourd’hui', 'garde sa place pour les prochains cours'],
       ['exclu', 'Exclu de l’établissement', 'n’est plus placé'],
       ['sortie', 'Sorti de l’établissement (retiré)', 'n’est plus placé']].forEach(function (m) {
        var b = creer('button', 'dehors-' + m[0]);
        b.type = 'button';
        b.append(creer('strong', '', m[1]), creer('small', '', m[2]));
        b.addEventListener('click', function () {
          var auj = cn.dateLocale ? cn.dateLocale() : new Date().toISOString().slice(0, 10);
          dialogueDehors.close();
          if (m[0] !== 'exclu_cours') { var p = placeDe(idEleve); if (p) { delete carte[p]; modifie(); } }
          cn.definirStatut(d.classe, e.numero, m[0], auj);   // redessine (événement « centrale-noms »)
        });
        choix.appendChild(b);
      });
      var annuler = creer('button', 'secondaire petit', 'Annuler');
      annuler.type = 'button';
      annuler.addEventListener('click', function () { dialogueDehors.close(); });
      dialogueDehors.append(choix, annuler);
      dialogueDehors.showModal();
    }
    /** L'élève revient : son motif est effacé (il retrouve sa place s'il l'avait gardée). */
    function rentrer(e) {
      var dh = dehors(d.classe, e.numero), cn = window.CentraleNoms;
      if (dh && cn && cn.definirStatut) cn.definirStatut(d.classe, e.numero, dh.motif, '');
    }
    function rendreDehors() {
      if (!zoneDehors) return;
      var ul = zoneDehors.querySelector('.dehors-liste');
      ul.replaceChildren();
      d.eleves.forEach(function (e) {
        var dh = dehors(d.classe, e.numero);
        if (!dh) return;
        var li = creer('li', 'dehors-eleve motif-' + dh.motif), v = creer('span', 'vignette');
        var visage = photo(d.classe, e.numero);
        if (visage) window.CentraleNoms.styleVisage(v, visage, 26 / visage.l, 26, 32);
        var retour = creer('button', 'lien-bouton', '↩ revenir');
        retour.type = 'button';
        retour.title = 'Effacer le motif : l’élève revient dans la classe';
        retour.addEventListener('click', function () { rentrer(e); });
        li.append(v, creer('span', 'nom', nom(e)), creer('span', 'discret', dh.texte), retour);
        ul.appendChild(li);
      });
      zoneDehors.classList.toggle('vide', !ul.children.length);
    }
    if (zoneDehors) {
      zoneDehors.addEventListener('dragover', function (ev) { ev.preventDefault(); zoneDehors.classList.add('survol'); });
      zoneDehors.addEventListener('dragleave', function () { zoneDehors.classList.remove('survol'); });
      zoneDehors.addEventListener('drop', function (ev) {   // depuis la liste
        ev.preventDefault();
        zoneDehors.classList.remove('survol');
        var idE = +ev.dataTransfer.getData('text/plain');
        if (idE) sortir(idE);
      });
    }

    /** Vue à plat : glisser une place occupée vers une autre place (échange) ou vers la liste (l'élève n'est plus placé). */
    function glisserSvg(g, idPlace, ev) {
      if (ev.button !== 0) return;
      var x0 = ev.clientX, y0 = ev.clientY, vignette = null, cible = null;
      function bouge(e2) {
        if (!vignette && Math.abs(e2.clientX - x0) + Math.abs(e2.clientY - y0) > 5) {
          vignette = document.createElement('div');
          vignette.className = 'glisse-eleve';
          var el0 = eleve(carte[idPlace]);
          vignette.textContent = el0 ? nom(el0) : '';
          document.body.appendChild(vignette);
          g.classList.add('glisse-source');
        }
        if (!vignette) return;
        vignette.style.left = e2.clientX + 'px';
        vignette.style.top = e2.clientY + 'px';
        var sous = document.elementFromPoint(e2.clientX, e2.clientY), p = sous && sous.closest ? sous.closest('[data-place]') : null;
        if (cible && cible !== p) cible.classList.remove('survol');
        cible = p;
        if (cible) cible.classList.add('survol');
        liste.classList.toggle('depot', !!(sous && sous.closest && sous.closest('#liste-eleves')));
        if (zoneDehors) zoneDehors.classList.toggle('survol', !!(sous && sous.closest && sous.closest('#zone-dehors')));
      }
      function lache(e2) {
        document.removeEventListener('pointermove', bouge);
        document.removeEventListener('pointerup', lache);
        if (!vignette) return;   // simple clic : géré par « click »
        vignette.remove();
        g.classList.remove('glisse-source');
        liste.classList.remove('depot');
        ignorerClic = true;
        setTimeout(function () { ignorerClic = false; }, 0);
        var sous = document.elementFromPoint(e2.clientX, e2.clientY);
        if (zoneDehors) zoneDehors.classList.remove('survol');
        if (lacheHorsPlan(idPlace, sous)) return;
        if (cible) glisser(idPlace, cible.getAttribute('data-place'));
      }
      document.addEventListener('pointermove', bouge);
      document.addEventListener('pointerup', lache);
    }

    /* ---------- Élèves à séparer (dissipations, altercations de la feuille de séance, par paires) : voisins = côte à côte, en face ou en diagonale ; proches = juste à côté ---------- */
    var VOISINS = 1.9, PROCHES = 2.6;   // distance entre les centres des places, en carreaux (≈ 50 cm) : même table ≈ 1 à 1,8
    var centresPlaces = {};
    plan.objets.filter(estPlace).forEach(function (o) { centresPlaces[o.id] = { x: o.x + largeurVue(o) / 2, y: o.y + hauteurVue(o) / 2 }; });
    var idParNumero = {};
    d.eleves.forEach(function (e) { idParNumero[String(e.numero)] = e.id; });
    /** Les duos assis trop près dans une répartition : [{ paire, pa, pb, distance, voisins }]. */
    function duosTropPres(rep) {
      var placeDeId = {};
      Object.keys(rep).forEach(function (pl) { placeDeId[rep[pl]] = pl; });
      var res = [];
      pairesDuos(d.classe).forEach(function (paire) {
        var pa = placeDeId[idParNumero[paire.a]], pb = placeDeId[idParNumero[paire.b]];
        if (!pa || !pb || !centresPlaces[pa] || !centresPlaces[pb]) return;
        var dist = Math.hypot(centresPlaces[pa].x - centresPlaces[pb].x, centresPlaces[pa].y - centresPlaces[pb].y);
        if (dist <= PROCHES) res.push({ paire: paire, pa: pa, pb: pb, distance: dist, voisins: dist <= VOISINS });
      });
      return res;
    }
    function coutDuos(rep) { return duosTropPres(rep).reduce(function (t, c) { return t + c.paire.poids * (c.voisins ? 4 : 1); }, 0); }
    var boiteDuos = creer('div', 'duos-plan');
    boiteDuos.hidden = true;
    liste.before(boiteDuos);   // au-dessus de la liste des élèves : visible sans faire défiler
    function rendreDuos() {
      var paires = pairesDuos(d.classe), trop = duosTropPres(carte);
      boiteDuos.hidden = !paires.length;
      boiteDuos.replaceChildren();
      if (!paires.length) return;
      boiteDuos.className = 'duos-plan' + (trop.some(function (c) { return c.voisins; }) ? ' alerte' : trop.length ? ' attention' : ' ok');
      boiteDuos.appendChild(creer('p', 'duos-titre', trop.length ? '⚠ À séparer, assis trop près (' + trop.length + ')' : '✓ Élèves à séparer : bien séparés'));
      if (!trop.length) {
        boiteDuos.appendChild(creer('p', 'discret', paires.length + ' paire' + (paires.length > 1 ? 's' : '') + ' d’élèves notée' + (paires.length > 1 ? 's' : '') + ' (dissipation, altercation) dans la feuille de séance : aucune n’est assise ensemble.'));
        return;
      }
      var ul = creer('ul');
      trop.forEach(function (c) {
        var ea = eleve(idParNumero[c.paire.a]), eb = eleve(idParNumero[c.paire.b]), li = creer('li', c.voisins ? 'voisins' : 'proches');
        li.append(creer('strong', '', (ea ? nom(ea) : c.paire.a) + ' ↔ ' + (eb ? nom(eb) : c.paire.b)), creer('br'),
          creer('span', 'discret', (c.voisins ? 'côte à côte' : 'tout proches') + ' · ' + resumePaire(c.paire)));
        ul.appendChild(li);
      });
      boiteDuos.append(ul, creer('p', 'discret', 'Glissez l’un des deux ailleurs, ou « 🎲 Aléatoire » : il les éloigne.'));
    }
    /** Vue à plat : les liens entre élèves assis (à séparer : rouge, jaune ; bonne dynamique : vert), plus marqués s'ils sont voisins. */
    function tracerDuos() {
      var p = affichage(plan);
      if ((p.vue || {}).iso || svg.style.display === 'none') return;
      tracerLiens2D(svg, plan, transformation(p), liensPlaces(d, carte, nomNumero), duosTropPres(carte));
    }

    /* ---------- Disposer les places : glisser une place la déplace dans la salle (vue à plat) ---------- */
    function vueVersPlan(p, X, Y) {   // coordonnées affichées (portrait, miroirs) → coordonnées de la salle, en carreaux
      var v = p.vue || {}, portrait = !!v.portrait;
      var L = portrait ? p.hauteur : p.largeur, H = portrait ? p.largeur : p.hauteur;
      var vx = v.miroirX ? L - X : X, vy = v.miroirY ? H - Y : Y;
      return portrait ? { x: vy, y: p.hauteur - vx } : { x: vx, y: vy };
    }
    function formulairePlan() { return document.getElementById('form-placement'); }
    function envoyerPlaces(champs) {
      var f = formulairePlan(), fd = new FormData();
      ['csrf', 'classe_id', 'salle_id', 'groupe_id', 'variante_id'].forEach(function (n) { if (f.elements[n]) fd.append(n, f.elements[n].value); });
      fd.append('action', 'disposer_places');
      Object.keys(champs).forEach(function (k) { fd.append(k, champs[k]); });
      return fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' });
    }
    function deplacerPlace(g, idPlace, ev) {
      if (ev.button !== undefined && ev.button !== 0) return;
      ev.preventDefault();
      var o = plan.objets.find(function (x) { return x.id === idPlace; });
      if (!o) return;
      var depart = pointPlan(svg, ev), base = g.getAttribute('transform') || '', dx = 0, dy = 0;
      g.classList.add('en-deplacement');
      function bouger(e) {
        var q = pointPlan(svg, e);
        dx = q.x - depart.x; dy = q.y - depart.y;
        g.setAttribute('transform', base + ' translate(' + (dx * U) + ',' + (dy * U) + ')');
      }
      function lacher() {
        document.removeEventListener('pointermove', bouger);
        document.removeEventListener('pointerup', lacher);
        ignorerClic = true;
        setTimeout(function () { ignorerClic = false; }, 0);
        if (Math.abs(dx) < 0.25 && Math.abs(dy) < 0.25) { rendre(); return; }
        var p = affichage(plan), tr = transformation(p), r = tr.rect(o.x, o.y, largeurVue(o), hauteurVue(o));
        var c = vueVersPlan(p, r.x + r.w / 2 + dx, r.y + r.h / 2 + dy);
        o.x = Math.max(0, Math.min(plan.largeur - largeurVue(o), arrondi(c.x - largeurVue(o) / 2)));
        o.y = Math.max(0, Math.min(plan.hauteur - hauteurVue(o), arrondi(c.y - hauteurVue(o) / 2)));
        rendre();
        envoyerPlaces({ places: JSON.stringify([{ id: o.id, x: o.x, y: o.y }]), reponse: 'json' })
          .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
          .catch(function () { alert('La place n’a pas pu être enregistrée : rechargez la page.'); });
      }
      document.addEventListener('pointermove', bouger);
      document.addEventListener('pointerup', lacher);
    }

    /* Filtre de la liste des élèves (numéro ou nom) */
    var champFiltre = document.getElementById('filtre-eleves');
    function filtrerListe() {
      var q = champFiltre ? champFiltre.value.trim().toLowerCase() : '';
      liste.querySelectorAll('li').forEach(function (li) { li.hidden = !!q && (li.dataset.cherche || '').indexOf(q) < 0; });
    }
    if (champFiltre) champFiltre.addEventListener('input', filtrerListe);

    function rendre() {
      orientationPage(affichage(plan));
      var nums = numeros(plan);
      dessiner(svg, plan, {
        surObjet: function (o, g, w, h, ctx) {
          if (!estPlace(o)) return;
          g.classList.add('place-cliquable');
          g.setAttribute('data-place', o.id);
          if (modeDisposer) {   // la place elle-même se déplace (avec ou sans élève)
            g.classList.add('place-disposable');
            el('rect', { x: -3, y: -3, width: w + 6, height: h + 6, rx: 6, class: 'cadre-disposer' }, g);
            g.addEventListener('pointerdown', function (ev) { ev.stopImmediatePropagation(); deplacerPlace(g, o.id, ev); }, true);
          }
          var id = carte[o.id];
          if (id) {
            g.classList.add('occupee');
            if (o.id === placeChoisie) {   // mode échange : l'élève sélectionné, entouré d'un anneau qui pulse
              g.classList.add('echange-choisi');
              g.insertBefore(el('rect', { x: -10, y: -10, width: w + 20, height: h + 38, rx: 12, class: 'anneau-echange' }, null), g.firstChild);
            }
            if (apercu && apercu.avant[o.id] !== id) g.classList.add('deplace');   // aperçu : l'élève change de place
            g.addEventListener('pointerdown', function (ev) { if (!apercu) glisserSvg(g, o.id, ev); });
            var e = eleve(id);
            if (e && dehors(d.classe, e.numero)) g.classList.add('dehors');   // exclu du cours aujourd'hui : place gardée, grisée
            var visage = e ? photo(d.classe, e.numero) : null;
            if (visage) photoPlace(g, w, h, visage);
            var blocNomPlace = ctx.nom ? ctx.nom(e ? nom(e) : '?') : ctx.lab(w / 2, h + 13, 0, 'nom-eleve', e ? nom(e) : '?');
            if (ctx.nom) pastilles2D(g, w, h, blocNomPlace, infosPlaces.cartonsPlace(o));
            var bes = e && window.CentraleNoms && window.CentraleNoms.besoins ? window.CentraleNoms.besoins(d.classe, e.numero) : [];
            if (bes.length) {   // petit repère sur la place (masqué à l'impression)
              var m = el('circle', { cx: w - 5, cy: 5, r: 4.5, class: 'marque-besoin' }, g);
              el('title', {}, m).textContent = bes.join(', ');
            }
            if (visage) bulleVisage(g, ctx, visage, w / 2, h / 2, 0.9, e ? nom(e) : '');
            else bulleNom(g, ctx, e ? nom(e) : '?', w / 2, h / 2, 0.9);
          }
          g.addEventListener('click', function () { clicPlace(o.id); });
          g.addEventListener('dragover', function (ev) { ev.preventDefault(); g.classList.add('survol'); });
          g.addEventListener('dragleave', function () { g.classList.remove('survol'); });
          g.addEventListener('drop', function (ev) {
            ev.preventDefault();
            var idE = +ev.dataTransfer.getData('text/plain');
            if (idE) { poser(o.id, idE); choisi = null; rendre(); }   // depuis la liste : échange si la place est prise
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
        li.innerHTML = '<span class="vignette"></span><span class="numero"></span><span class="nom-cellule"><span class="nom"></span></span><span class="ou"></span>';
        var visage = photo(d.classe, e.numero);
        if (visage) window.CentraleNoms.styleVisage(li.querySelector('.vignette'), visage, 26 / visage.l, 26, 32);
        else li.querySelector('.vignette').title = window.CentraleNoms && window.CentraleNoms.raison ? window.CentraleNoms.raison(d.classe, e.numero) : '';
        li.querySelector('.numero').textContent = e.numero;
        var vraiNom = nom(e), sansNom = vraiNom === 'n° ' + e.numero;   // noms pas chargés ici : le numéro suffit
        li.querySelector('.nom').textContent = sansNom ? '' : vraiNom;
        li.querySelector('.nom').title = sansNom ? '' : vraiNom;
        var cellule = li.querySelector('.nom-cellule');
        var b = window.CentraleNoms && window.CentraleNoms.besoins ? window.CentraleNoms.besoins(d.classe, e.numero) : [];
        if (b.length) cellule.appendChild(window.CentraleNoms.pastilles(b));
        var nbCartons = window.CentraleNoms && window.CentraleNoms.cartons ? window.CentraleNoms.cartons(d.classe, e.numero).length : 0;
        if (nbCartons) cellule.appendChild(creer('span', 'pastille-carton', String(nbCartons)));
        var dhl = dehors(d.classe, e.numero);
        var ou = li.querySelector('.ou');
        ou.textContent = dhl ? 'dehors' : place ? 'place ' + nums[place] : 'non placé';
        ou.classList.toggle('non-place', !place && !dhl);
        ou.classList.toggle('ou-dehors', !!dhl);
        li.dataset.cherche = (e.numero + ' ' + (sansNom ? '' : vraiNom)).toLowerCase();
        if (dhl) li.title = dhl.texte;
        li.onclick = function () { choisi = choisi === e.id ? null : e.id; rendre(); };
        var fiche = creer('button', 'bouton-fiche', '🗂');
        fiche.type = 'button';
        fiche.title = 'Fiche et cartons de l’élève';
        fiche.addEventListener('click', function (ev) { ev.stopPropagation(); ouvrirFicheDialogue(d, e, place ? nums[place] : null); });
        li.appendChild(fiche);
        li.addEventListener('dragstart', function (ev) { ev.dataTransfer.setData('text/plain', String(e.id)); });
        liste.appendChild(li);
      });
      filtrerListe();
      var compteListe = document.getElementById('compte-liste');
      if (compteListe) {
        var nbPlaces = Object.keys(carte).length;
        compteListe.textContent = nbPlaces + ' / ' + d.eleves.length + ' placés';
      }
      rendreDehors();
      tracerDuos();
      rendreDuos();
      var nbPlaces = plan.objets.filter(estPlace).length;
      var nbPlaces2 = Object.keys(carte).length;
      document.getElementById('compte-places').textContent = nbPlaces2 + ' élève' + (nbPlaces2 > 1 ? 's' : '') + ' placé' + (nbPlaces2 > 1 ? 's' : '')
        + ' sur ' + d.eleves.length + ' · ' + nbPlaces + ' places élèves dans la salle'
        + (document.getElementById('compte-places').dataset.alerte ? ' · ⚠ ' + document.getElementById('compte-places').dataset.alerte : '');
    }

    /* ---------- Options du plan : mode échange, icônes au-dessus des élèves (réglages gardés sur cet ordinateur) ---------- */
    (function () {
      var puce = function (icone, texte, titre) {
        var b = creer('button', 'puce-option');
        b.type = 'button';
        b.title = titre;
        b.innerHTML = iconeBarre(icone) + '<span></span>';
        b.querySelector('span').textContent = texte;
        return b;
      };
      var echange = puce('echange', 'Échanger', 'Mode échange : cliquer sur un élève du plan, puis sur un autre, et ils échangent leurs places (sur une place libre : il s’y déplace). Échap annule.');
      echange.classList.add('bouton-echange');
      var aideEchange = creer('span', 'plan-options-aide', '');
      function majEchange() {
        echange.setAttribute('aria-pressed', String(modeEchange));
        document.body.classList.toggle('mode-echange', modeEchange);
        aideEchange.hidden = !modeEchange;
        aideEchange.textContent = placeChoisie ? 'Cliquez sur l’élève avec qui échanger, ou sur une place libre.' : 'Cliquez sur un premier élève du plan.';
      }
      echange.addEventListener('click', function () {
        modeEchange = !modeEchange;
        placeChoisie = null;
        if (modeEchange) modeDisposer = false;
        try { localStorage.setItem('centrale-plan-echange', modeEchange ? '1' : ''); } catch (e) { /* rien */ }
        rendre();
      });
      document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && placeChoisie) { placeChoisie = null; rendre(); } });
      var icones = creer('div', 'barre-groupe plan-options-icones');
      icones.setAttribute('role', 'group');
      icones.setAttribute('aria-label', 'Icônes au-dessus des élèves');
      icones.appendChild(creer('span', 'barre-titre', 'Icônes'));
      // mêmes réglages que « Repères des élèves » de l'accueil (classes sans-classe / sans-reperes de <html>)
      [['sans-classe', 'centrale-classe-cache', 'classe', 'En classe', 'Ce qui est observé en classe : appel, cartons, absences et retards répétés'],
       ['sans-reperes', 'centrale-reperes-caches', 'progression', 'Progression', 'Repères de la plateforme : activité et avancement dans les ateliers']].forEach(function (x) {
        var b = puce(x[2], x[3], x[4] + ' — montrer ou cacher sur le plan');
        function appliquer(cache) { document.documentElement.classList.toggle(x[0], cache); b.setAttribute('aria-pressed', String(!cache)); }
        try { appliquer(localStorage.getItem(x[1]) === '1'); } catch (e) { appliquer(false); }
        b.addEventListener('click', function () {
          var cache = b.getAttribute('aria-pressed') === 'true';
          try { localStorage.setItem(x[1], cache ? '1' : ''); } catch (e) { /* rien */ }
          appliquer(cache);
          rendre();
        });
        icones.appendChild(b);
      });
      // Places de la salle : les déplacer à la souris, en ajouter (la salle est commune à toutes ses classes)
      var disposer = puce('disposer', 'Disposer les places', 'Glisser une place pour la déplacer dans la salle (vue Plan). La salle est la même pour toutes les classes qui y ont cours.');
      var ajouter = puce('ajouter', 'Place', 'Ajouter une place élève à la salle (en bas du plan, puis « Disposer les places » pour la mettre où vous voulez)');
      ajouter.removeAttribute('aria-pressed');
      var aideDisposer = creer('span', 'plan-options-aide', '');
      function majDisposer() {
        var iso = !!(affichage(plan).vue || {}).iso;
        disposer.setAttribute('aria-pressed', String(modeDisposer));
        document.body.classList.toggle('mode-disposer', modeDisposer);
        aideDisposer.hidden = !modeDisposer;
        aideDisposer.textContent = iso ? 'Passez en vue « Plan » pour déplacer les places.' : 'Glissez une place où vous voulez (elle s’enregistre aussitôt).';
      }
      disposer.addEventListener('click', function () {
        modeDisposer = !modeDisposer;
        if (modeDisposer) { modeEchange = false; placeChoisie = null; }
        rendre();
      });
      ajouter.addEventListener('click', function () {
        var txt = ajouter.querySelector('span');   // 1er clic : « Confirmer ? » (5 s), 2e clic : ajoute
        if (ajouter.dataset.confirme !== '1') {
          ajouter.dataset.confirme = '1';
          txt.textContent = 'Ajouter à la salle ?';
          ajouter.classList.add('a-confirmer');
          clearTimeout(ajouter._minuteur);
          ajouter._minuteur = setTimeout(function () { ajouter.dataset.confirme = ''; txt.textContent = 'Place'; ajouter.classList.remove('a-confirmer'); }, 5000);
          return;
        }
        clearTimeout(ajouter._minuteur);
        ajouter.disabled = true;
        envoyerPlaces({ ajouter: '1', reponse: 'json' }).then(function () { location.reload(); }, function () { ajouter.disabled = false; });
      });
      document.addEventListener('centrale-vue', majDisposer);
      var groupePlaces = creer('div', 'barre-groupe');
      groupePlaces.append(disposer, ajouter);
      barreOptions.append(creer('span', 'barre-titre', 'Plan de classe'), echange, groupePlaces, aideEchange, aideDisposer, icones);
      majEchange();
      majDisposer();
      var rendreAvant = rendre;
      rendre = function () { rendreAvant(); majEchange(); majDisposer(); };
    })();

    var boutonVider = document.getElementById('p-vider');
    boutonVider.onclick = function () {
      if (apercu) revenirApercu();
      if (confirmerEnLigne(boutonVider, 'Confirmer : tout vider ?')) { carte = {}; choisi = null; modifie(); rendre(); }
    };
    /* ---------- Aperçu d'un nouveau plan : proposé d'après les liens entre élèves (feuille de séance), vu avant d'être gardé ---------- */
    var boiteApercu = document.getElementById('apercu-plan');
    function placesEleves() { return plan.objets.filter(estPlace).map(function (o) { return o.id; }); }
    function elevesAPlacer() { return d.eleves.filter(function (e) { return e.actif && !sorti(d.classe, e.numero); }).map(function (e) { return e.id; }); }
    /** Les paires à considérer : { a, b (ids d'élèves), neg (à séparer : altercation ×2 + dissipations), pos (bonnes dynamiques) }. */
    function pairesPlan() {
      var parNum = {};
      d.eleves.forEach(function (e) { parNum[String(e.numero)] = e.id; });
      return liensClasse(d.classe).map(function (p) {
        return { a: parNum[p.a], b: parNum[p.b], neg: p.altercation * 2 + p.dissipation, pos: p.entente };
      }).filter(function (p) { return p.a && p.b; });
    }
    function distance(pa, pb) {
      var A = centresPlaces[pa], B = centresPlaces[pb];
      return A && B ? Math.hypot(A.x - B.x, A.y - B.y) : Infinity;
    }
    /** Bilan d'une répartition : à séparer assis trop près, bonnes dynamiques assises côte à côte, élèves déplacés. */
    function bilanPlan(rep, paires, depart) {
      var ou = {}, b = { trop: 0, voisins: 0, ensemble: 0, deplaces: 0, cout: 0 };
      Object.keys(rep).forEach(function (pl) { ou[rep[pl]] = pl; });
      paires.forEach(function (p) {
        var dd = ou[p.a] && ou[p.b] ? distance(ou[p.a], ou[p.b]) : Infinity;
        if (p.neg && dd <= PROCHES) { b.trop++; if (dd <= VOISINS) b.voisins++; b.cout += p.neg * (dd <= VOISINS ? 4 : 1); }
        if (p.pos && dd <= VOISINS) { b.ensemble++; b.cout -= p.pos * 1.5; }
        else if (p.pos && dd <= PROCHES) b.cout -= p.pos * 0.5;
      });
      if (depart) Object.keys(ou).forEach(function (id) { if (!depart[ou[id]] || String(depart[ou[id]]) !== String(id)) b.deplaces++; });
      return b;
    }
    /**
     * Cherche un plan : échanges de places au hasard, gardés s'ils n'aggravent pas le coût (à séparer trop près, bonnes dynamiques
     * éloignées ; « minimal » : chaque élève déplacé coûte un peu, on part du plan affiché). Plusieurs départs : on garde le meilleur.
     */
    function chercherPlan(depart, minimal) {
      var places = placesEleves(), ids = elevesAPlacer(), paires = pairesPlan(), meilleur = null, coutMin = Infinity;
      function cout(rep) { var b = bilanPlan(rep, paires, depart); return b.cout + (minimal ? b.deplaces * 0.35 : 0); }
      for (var essai = 0; essai < (minimal ? 4 : 8); essai++) {
        var t = places.map(function () { return null; }), libres = ids.slice();
        if (minimal) {   // le plan affiché, puis les élèves non placés sur les places libres
          places.forEach(function (pl, i) { var id = depart[pl]; if (id && libres.indexOf(id) >= 0) { t[i] = id; libres.splice(libres.indexOf(id), 1); } });
          t.forEach(function (x, i) { if (!x && libres.length) t[i] = libres.shift(); });
        } else {
          for (var i = libres.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)), u = libres[i]; libres[i] = libres[j]; libres[j] = u; }
          libres.forEach(function (id, k) { if (k < t.length) t[k] = id; });
          for (var i2 = t.length - 1; i2 > 0; i2--) { var j2 = Math.floor(Math.random() * (i2 + 1)), u2 = t[i2]; t[i2] = t[j2]; t[j2] = u2; }
        }
        function rep() { var r = {}; t.forEach(function (id, k) { if (id) r[places[k]] = id; }); return r; }
        var c = cout(rep());
        if (paires.length) for (var n = 0; n < 2500; n++) {
          var x = Math.floor(Math.random() * t.length), y = Math.floor(Math.random() * t.length);
          if (x === y || (!t[x] && !t[y])) continue;
          var v = t[x]; t[x] = t[y]; t[y] = v;
          var c2 = cout(rep());
          if (c2 <= c) c = c2; else { v = t[x]; t[x] = t[y]; t[y] = v; }
        }
        if (c < coutMin) { coutMin = c; meilleur = rep(); }
      }
      return meilleur || {};
    }
    function texteBilan(b) {
      return b.trop + ' paire' + (b.trop > 1 ? 's' : '') + ' à séparer assise' + (b.trop > 1 ? 's' : '') + ' trop près'
        + (b.voisins ? ' (dont ' + b.voisins + ' côte à côte)' : '');
    }
    function proposer(minimal) {
      var avant = apercu ? apercu.avant : Object.assign({}, carte);
      carte = chercherPlan(avant, minimal);
      apercu = { avant: avant, minimal: minimal };
      choisi = null;
      majApercu();
      rendre();
    }
    function majApercu() {
      document.body.classList.toggle('plan-apercu', !!apercu);
      boiteApercu.hidden = !apercu;
      boiteApercu.replaceChildren();
      if (!apercu) return;
      var paires = pairesPlan(), b0 = bilanPlan(apercu.avant, paires), b1 = bilanPlan(carte, paires, apercu.avant);
      var nbPos = paires.filter(function (p) { return p.pos; }).length;
      boiteApercu.appendChild(creer('p', 'apercu-titre', '👁 Aperçu d’un nouveau plan — pas encore appliqué'));
      var modes = creer('div', 'apercu-modes');
      [[true, 'Changer le moins de places possible'], [false, 'Tout redistribuer']].forEach(function (m) {
        var b = creer('button', 'petit ' + (apercu.minimal === m[0] ? '' : 'secondaire'), m[1]);
        b.type = 'button';
        b.setAttribute('aria-pressed', String(apercu.minimal === m[0]));
        b.addEventListener('click', function () { proposer(m[0]); });
        modes.appendChild(b);
      });
      var ul = creer('ul', 'apercu-bilan');
      function ligne(cls, avantT, apresT) {
        var li = creer('li', cls);
        li.append(creer('span', 'apercu-avant', avantT), creer('span', 'apercu-fleche', '→'), creer('strong', '', apresT));
        ul.appendChild(li);
      }
      ligne(b1.trop < b0.trop ? 'mieux' : b1.trop > b0.trop ? 'moins-bien' : '', texteBilan(b0), texteBilan(b1));
      if (nbPos) ligne(b1.ensemble > b0.ensemble ? 'mieux' : b1.ensemble < b0.ensemble ? 'moins-bien' : '',
        b0.ensemble + ' / ' + nbPos + ' bonne' + (nbPos > 1 ? 's' : '') + ' dynamique' + (nbPos > 1 ? 's' : '') + ' côte à côte', b1.ensemble + ' / ' + nbPos + ' côte à côte');
      var dep = creer('li', 'apercu-deplaces');
      dep.append(creer('span', 'deplace-marque'), ' ' + b1.deplaces + ' élève' + (b1.deplaces > 1 ? 's' : '') + ' change' + (b1.deplaces > 1 ? 'nt' : '') + ' de place (cerclés en bleu sur le plan à plat)');
      ul.appendChild(dep);
      if (!paires.length) ul.appendChild(creer('li', 'discret', 'Aucun lien noté entre élèves dans la feuille de séance : la proposition est faite au hasard.'));
      var actions = creer('div', 'apercu-actions'), garder = creer('button', 'petit', '✓ Garder ce plan'),
          autre = creer('button', 'petit secondaire', '↻ Autre proposition'), retour = creer('button', 'petit secondaire', 'Revenir au plan actuel');
      [garder, autre, retour].forEach(function (b) { b.type = 'button'; });
      garder.title = 'Le plan proposé remplace le plan affiché ; « Enregistrer le plan de classe » le garde pour de bon';
      garder.addEventListener('click', function () { apercu = null; modifie(); majApercu(); rendre(); });
      autre.addEventListener('click', function () { proposer(apercu.minimal); });
      retour.addEventListener('click', revenirApercu);
      actions.append(garder, autre, retour);
      boiteApercu.append(modes, ul, actions, formVersion());
    }
    /**
     * « Ajouter comme version » : la proposition est enregistrée comme nouvelle variante (action enregistrer_version), sans toucher
     * au plan de la classe ; la page se recharge sur le plan d'origine, la variante apparaît dans « Variantes ».
     */
    function formVersion() {
      var fv = creer('form', 'apercu-version'), fp = document.getElementById('form-placement');
      fv.method = 'post';
      function cache(nom, valeur) { var i = creer('input'); i.type = 'hidden'; i.name = nom; i.value = valeur; fv.appendChild(i); return i; }
      ['csrf', 'classe_id', 'salle_id', 'groupe_id'].forEach(function (n) { var src = fp && fp.querySelector('[name="' + n + '"]'); if (src) cache(n, src.value); });
      cache('action', 'enregistrer_version');
      var champPlacement = cache('placement', '');
      var existants = Array.prototype.map.call(document.querySelectorAll('.form-variante .variante'), function (b) { return b.textContent.trim().toLowerCase(); });
      var n = 1;
      while (existants.indexOf('proposition ' + n) >= 0) n++;
      var nomV = creer('input', 'niv-com apercu-version-nom'), ajouter = creer('button', 'petit', '＋ Ajouter comme version');
      nomV.type = 'text'; nomV.name = 'nom_version'; nomV.maxLength = 60; nomV.required = true;
      nomV.value = 'Proposition ' + n;
      nomV.placeholder = 'Nom de la version';
      nomV.setAttribute('aria-label', 'Nom de la nouvelle version');
      ajouter.type = 'submit';
      ajouter.title = 'Enregistre ce plan proposé comme nouvelle variante ; le plan de la classe ne change pas';
      var ligne = creer('div', 'apercu-version-ligne');
      ligne.append(nomV, ajouter);
      fv.append(ligne, creer('p', 'discret apercu-version-aide', 'Garde la proposition sous ce nom, sans modifier le plan de la classe : elle s’ouvrira plus tard d’un clic dans « Variantes ».'));
      fv.addEventListener('submit', function (ev) {
        if (!nomV.value.trim()) { ev.preventDefault(); nomV.focus(); return; }
        // le plan affiché avant l'aperçu avait des changements non enregistrés : ils seraient perdus au rechargement
        var avantModifie = JSON.stringify(Object.keys(apercu.avant).sort().map(function (k) { return [k, apercu.avant[k]]; })) !== initial;
        if (avantModifie && !confirmerEnLigne(ajouter, 'Modifications non enregistrées perdues : confirmer ?')) { ev.preventDefault(); return; }
        champPlacement.value = JSON.stringify(carte);
      });
      return fv;
    }
    function revenirApercu() {
      if (!apercu) return;
      carte = apercu.avant;
      apercu = null;
      majApercu();
      rendre();
    }
    document.getElementById('p-proposer').addEventListener('click', function () {
      proposer(Object.keys(carte).length > 0);   // un plan existe : on le retouche ; sinon on répartit tout
      boiteApercu.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && apercu && !document.querySelector('dialog[open]')) revenirApercu(); });

    document.getElementById('p-aleatoire').onclick = function () {
      if (apercu) revenirApercu();
      var places = plan.objets.filter(estPlace).map(function (o) { return o.id; });
      var eleves = d.eleves.filter(function (e) { return e.actif && !sorti(d.classe, e.numero); }).map(function (e) { return e.id; });
      // plusieurs tirages : on garde celui où les duos (dissipation, altercation) sont le plus éloignés
      var meilleur = null, coutMin = Infinity, avecDuos = pairesDuos(d.classe).length > 0;
      for (var essai = 0; essai < (avecDuos ? 600 : 1) && coutMin > 0; essai++) {
        for (var i = places.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = places[i]; places[i] = places[j]; places[j] = t; }
        var rep = {};
        eleves.forEach(function (id, k) { if (places[k]) rep[places[k]] = id; });
        var cout = avecDuos ? coutDuos(rep) : 0;
        if (cout < coutMin) { coutMin = cout; meilleur = rep; }
      }
      carte = meilleur || {};
      modifie();
      if (eleves.length > places.length) {
        document.getElementById('compte-places').dataset.alerte = 'Il manque ' + (eleves.length - places.length) + ' place(s) dans la salle.';
      }
      choisi = null;
      rendre();
    };
    document.getElementById('form-placement').addEventListener('submit', function (ev) {
      if (apercu) {   // on n'enregistre pas un aperçu par mégarde : il faut d'abord le garder
        ev.preventDefault();
        boiteApercu.classList.remove('secoue'); void boiteApercu.offsetWidth; boiteApercu.classList.add('secoue');
        boiteApercu.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        return;
      }
      document.getElementById('champ-placement').value = JSON.stringify(carte);
    });
    // « Enregistrer comme version » / « Dupliquer en variante » : gardent le plan tel qu'il est affiché
    ['form-version', 'form-dupliquer'].forEach(function (idf) {
      var f = document.getElementById(idf);
      if (f) f.addEventListener('submit', function () { f.querySelector('[name="placement"]').value = JSON.stringify(carte); });
    });
    // Changer de variante : demande confirmation si le plan affiché n'est pas enregistré
    document.querySelectorAll('.form-variante').forEach(function (f) {
      f.addEventListener('submit', function (ev) {
        if (document.body.classList.contains('plan-modifie') && !confirm('Le plan affiché n’est pas enregistré. Ouvrir quand même cette variante ?')) ev.preventDefault();
      });
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
    var identite = separerNom(nomComplet || e.simule || ('n° ' + numero));
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

    // Corps de la fiche : un menu d'onglets (Résumé · Appel · Cartons · Plateforme) au-dessus d'une zone qui défile.
    // Un clic sur un onglet fait défiler jusqu'à sa partie ; l'onglet de la partie visible s'allume.
    var menu = creer('nav', 'fiche-menu'), defile = creer('div', 'fiche-defile');
    menu.setAttribute('aria-label', 'Parties de la fiche');
    corps.classList.add('fiche-a-menu');
    corps.append(menu, defile);
    var parties = [];
    function partie(id, titre, source, aide) {
      var sec = creer('section', 'fiche-partie' + (source ? ' fiche-source fiche-' + source : ''));
      sec.dataset.partie = id;
      sec.appendChild(creer('h4', 'fiche-source-titre', titre));
      if (aide) sec.appendChild(creer('p', 'discret fiche-source-aide', aide));
      var onglet = creer('button', 'fiche-onglet' + (source ? ' onglet-' + source : ''), titre.replace(/ ·.*$/, ''));
      onglet.type = 'button';
      onglet.addEventListener('click', function () { defile.scrollTo({ top: sec.offsetTop - defile.offsetTop - 4, behavior: 'smooth' }); });
      menu.appendChild(onglet);
      defile.appendChild(sec);
      parties.push({ sec: sec, onglet: onglet });
      return sec;
    }
    function lignesReperes(bloc, liste, defs) {
      liste.forEach(function (r) {
        var def = defs[r.code] || { glyphe: '•', couleur: '#888', libelle: r.code }, ligne = creer('p', 'fiche-repere');
        ligne.append(icone(def), creer('strong', '', def.libelle), creer('span', 'discret', ' · ' + r.texte));
        bloc.appendChild(ligne);
      });
    }
    var rep = reperesEleve(d, e), obs = observationsClasse(d, e);
    var pres = cn && cn.presence ? cn.presence(d.classe, e.numero, seanceDe(d).cle) : '';
    var cartons = cn && cn.cartons ? cn.cartons(d.classe, e.numero) : [];

    // 1. Résumé : place, état, besoins, et les icônes des deux sources côte à côte
    var resume = partie('resume', 'Résumé', '', '');
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
    var dhf = dehors(d.classe, e.numero);
    if (dhf && dhf.motif === 'exclu_cours') etats.push('Exclu du cours aujourd’hui');
    rang('État', (etats.length ? etats.join(' · ') : 'Présent') + (e.actif ? '' : ' · compte désactivé'));
    if (besoins.length && cn.pastilles) rang('Besoins', cn.pastilles(besoins));
    function puces(codes, defs, vide) {
      var dd = creer('dd', 'fiche-puces');
      codes.forEach(function (c) { var def = defs[c]; if (def) { var i = icone(def); i.title = def.libelle; dd.appendChild(i); } });
      if (!dd.childNodes.length) dd.appendChild(creer('span', 'discret', vide));
      return dd;
    }
    rang('En classe', puces((pres ? [pres] : []).concat(obs.map(function (r) { return r.code; }), cartons.map(function (c) { return c.type; })), CLASSE, 'rien de noté'));
    if (cn && cn.observation) {   // notes de la séance affichée : « P+ », « C− »
      var oSeance = cn.observation(d.classe, e.numero, seanceDe(d).cle), ddO = creer('dd', 'obs-seance');
      cn.criteres().forEach(function (k) {
        if (!oSeance[k] || !CRITERES[k]) return;
        var x = creer('span', (oSeance[k] > 0 ? 'plus' : 'moins') + (Math.abs(oSeance[k]) > 1 ? ' fort' : ''), CRITERES[k].lettre + signeNiveau(oSeance[k]));
        x.title = CRITERES[k].libelle + ((oSeance._c || {})[k] ? ' : ' + commentairesLisibles(oSeance._c[k], formateurNoms(d)) : '');
        ddO.appendChild(x);
      });
      if (!ddO.childNodes.length) ddO.appendChild(creer('span', 'discret', 'rien de noté'));
      rang('Séance', ddO);
    }
    rang('Plateforme', puces(rep.map(function (r) { return r.code; }), REPERES, 'rien de particulier'));
    resume.appendChild(detail);

    // 2. Observé en classe : observations de la séance (critères), l'appel, puis les cartons — synchronisé avec la Centrale
    if (cn && cn.observation) {
      var obsP = partie('observations', 'Observations · observé en classe', 'classe', 'Séance : de −− à ++ pour chaque critère (= : comme d’habitude), et un commentaire si besoin. Cumul de toutes les séances.');
      var sObs = seanceDe(d), actuelles = cn.observation(d.classe, e.numero, sObs.cle);
      cn.criteres().concat(['oubli']).forEach(function (k) {
        if (!CRITERES[k]) return;
        var ligneO = creer('div', 'obs-ligne'), b = bilanCritere(d.classe, e.numero, k);
        ligneO.appendChild(creer('span', 'obs-critere', CRITERES[k].libelle));
        if (CRITERES[k].caseACocher) {   // oubli de matériel : une case pour ce cours, et le nombre d'oublis
          var lab = creer('label', 'case obs-case'), ca = creer('input');
          ca.type = 'checkbox';
          ca.checked = actuelles[k] === -1;
          ca.addEventListener('change', function () { cn.definirObservation(d.classe, e.numero, sObs.cle, k, ca.checked ? -1 : 0); actualiser(); });
          lab.append(ca, ' ce cours');
          ligneO.append(lab, creer('span', 'obs-total', b.moins ? b.moins + ' oubli' + (b.moins > 1 ? 's' : '') : '—'), pointsSeances(b, 8));
          obsP.appendChild(ligneO);
          return;
        }
        var choix = creer('span', 'obs-choix');
        [-2, -1, 0, 1, 2].forEach(function (v) {
          var bt = creer('button', 'obs-' + (v > 0 ? 'plus' : v < 0 ? 'moins' : 'neutre') + (Math.abs(v) > 1 ? ' fort' : ''), signeNiveau(v));
          bt.type = 'button';
          bt.title = sObs.libelle + ' : ' + NIVEAUX[String(v)].libelle;
          bt.setAttribute('aria-pressed', String((actuelles[k] || 0) === v));
          bt.addEventListener('click', function () { cn.definirObservation(d.classe, e.numero, sObs.cle, k, v); actualiser(); });
          choix.appendChild(bt);
        });
        function texteF() { return (cn.observation(d.classe, e.numero, sObs.cle)._c || {})[k] || ''; }
        var motifsF = pastillesMotifs(k, function (motif, signe) {   // les anciens cartons : niveau et commentaire de la séance
          function appliquer(texte) {   // le carton, avec son heure et son niveau ; le critère prend la moyenne des cartons
            var garde = cn.definirCommentaire(d.classe, e.numero, sObs.cle, k, ajouterEntree(texteF(), ecrireEntree(signe, texte)));
            var moy = niveauMoyen(garde);
            cn.definirObservation(d.classe, e.numero, sObs.cle, k, moy ? moy.niveau : signe);
            actualiser();
          }
          var type = MOTIFS_AVEC[motif];
          if (!type) { appliquer(motif); return; }
          choisirCamarades(document.querySelector('dialog[open]') || document.body, d, e.numero, motif, type, function (nums) {
            if (nums.length && TYPES_DUO[type] && cn.ajouterIncident) cn.ajouterIncident(d.classe, [String(e.numero)].concat(nums), type, sObs.cle, motif);
            if (MOTIFS_POUR_TOUS[motif]) repercuterCarton(d.classe, e.numero, nums, sObs.cle, k, signe, motif);   // les camarades reçoivent aussi le carton
            appliquer(motifAvec(motif, nums));
          });
        }, choix);
        if (motifsF) motifsF.classList.add('fiche-motifs');
        ligneO.append(motifsF || choix, creer('span', 'obs-total', (b.plus || b.moins) ? '+' + b.plus + ' / −' + b.moins : '—'), pointsSeances(b, 8));
        // commentaire de la séance pour ce critère (enregistré en quittant le champ), puis les précédents
        var com = zoneCommentaires(texteF, function (t) { return cn.definirCommentaire(d.classe, e.numero, sObs.cle, k, t) || ''; }, null,
          'Ajouter un commentaire pour préciser', false, formateurNoms(d),
          function () { var moy = niveauMoyen(texteF()); cn.definirObservation(d.classe, e.numero, sObs.cle, k, moy ? moy.niveau : 0); actualiser(); },
          { d: d, num: e.numero, seance: sObs.cle, k: k, conteneur: function () { return document.querySelector('dialog[open]') || document.body; } });
        com.classList.add('obs-coms');
        ligneO.appendChild(com);

        var anciens = b.commentaires.filter(function (c) { return c.cle !== sObs.cle; }).slice(0, 3);
        if (anciens.length) {
          var ul = creer('ul', 'obs-anciens');
          anciens.forEach(function (c) { ul.appendChild(creer('li', '', c.cle.slice(0, 10).split('-').reverse().join('/') + ' (' + signeNiveau(c.v) + ') : ' + commentairesLisibles(c.texte, formateurNoms(d)))); });
          ligneO.appendChild(ul);
        }
        obsP.appendChild(ligneO);
      });
    }
    var appel = partie('appel', 'Appel · observé en classe', 'classe', 'Noté par vous, enregistré sur la Centrale.');
    lignesReperes(appel, obs, CLASSE);
    if (cn && cn.presence) appel.appendChild(sectionPresence(d, e.numero, actualiser));
    // les cartons sont devenus des motifs des observations de séance (ci-dessus) ; les anciens restent consultables
    if (cn && cn.cartons && cn.cartons(d.classe, e.numero).length) {
      var blocCartons = partie('cartons', 'Anciens cartons · observé en classe', 'classe', 'Donnés avant que les cartons deviennent des motifs des observations de séance.');
      blocCartons.appendChild(sectionCartons(d.classe, e.numero, actualiser));
    }

    // 3. Sur la plateforme : calculé par la Centrale d'après l'activité dans les ateliers
    var plateforme = partie('plateforme', 'Plateforme · activité dans les ateliers', 'plateforme', 'Calculé par la Centrale, en comparant l’élève à sa classe.');
    if (rep.length) lignesReperes(plateforme, rep, REPERES);
    else plateforme.appendChild(creer('p', 'discret', 'Rien de particulier.'));

    // onglet de la partie visible ; même élève rouvert après un clic (appel, carton) : on garde l'endroit
    function allumer() {
      var haut = defile.scrollTop + 24, courant = parties[0];
      parties.forEach(function (p) { if (p.sec.offsetTop - defile.offsetTop <= haut) courant = p; });
      if (defile.scrollTop > 0 && defile.scrollTop + defile.clientHeight >= defile.scrollHeight - 16) courant = parties[parties.length - 1];
      parties.forEach(function (p) { p.onglet.setAttribute('aria-current', String(p === courant)); });
    }
    var cle = d.classe + '|' + numero;
    defile.addEventListener('scroll', function () { corps._defile = { cle: cle, top: defile.scrollTop }; allumer(); }, { passive: true });
    if (corps._defile && corps._defile.cle === cle) defile.scrollTop = corps._defile.top;
    allumer();
    setTimeout(function () { if (corps._defile && corps._defile.cle === cle) defile.scrollTop = corps._defile.top; allumer(); }, 0);   // une fois la fiche affichée

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

  /** Présence à la séance (présent, absent, en retard), puis le relevé des absences et retards de l'élève. */
  function sectionPresence(d, numero, actualiser) {
    var cn = window.CentraleNoms, s = seanceDe(d), bloc = creer('div', 'fiche-presence');
    bloc.appendChild(creer('p', 'fiche-trois-cartons-titre', 'Présence · ' + s.libelle));
    var actuel = cn.presence(d.classe, numero, s.cle), choix = creer('div', 'presence-choix');
    [['', 'Présent'], ['absent', 'Absent'], ['retard', 'En retard']].forEach(function (x) {
      var b = creer('button', 'presence-' + (x[0] || 'present'), x[1]);
      b.type = 'button';
      b.setAttribute('aria-pressed', String(actuel === x[0]));
      b.addEventListener('click', function () { cn.definirPresence(d.classe, numero, s.cle, x[0]); actualiser(); });
      choix.appendChild(b);
    });
    bloc.appendChild(choix);
    var liste = cn.absences(d.classe, numero);
    var nbA = liste.filter(function (x) { return x.etat === 'absent'; }).length, nbR = liste.length - nbA;
    bloc.appendChild(creer('p', 'discret presence-bilan', liste.length
      ? nbA + ' absence' + (nbA > 1 ? 's' : '') + ' · ' + nbR + ' retard' + (nbR > 1 ? 's' : '') + ' notés à l’appel'
      : 'Aucune absence ni retard noté.'));
    if (liste.length) {
      var ul = creer('ul', 'presence-liste');
      liste.slice(0, 8).forEach(function (x) {
        var jour = x.date.split('-').reverse().join('/');
        ul.appendChild(creer('li', 'presence-' + x.etat, cn.etatsPresence[x.etat] + ' · ' + jour + (x.heure ? ' (cours de ' + x.heure + ')' : '')));
      });
      if (liste.length > 8) ul.appendChild(creer('li', 'discret', '+ ' + (liste.length - 8) + ' autre(s)'));
      bloc.appendChild(ul);
    }
    return bloc;
  }

  /** Anciens cartons de l'élève (avant les motifs de la feuille de séance) : la liste, avec retrait. */
  function sectionCartons(classe, numero, actualiser) {
    var cn = window.CentraleNoms, bloc = creer('div', 'fiche-trois-cartons');
    // liste des cartons déjà donnés, avec retrait
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
    var fiche = conteneur.querySelector('.fiche-trois:not(.bilan-obs)');   // ni le bilan ni la feuille de séance   // (le bilan de la classe a aussi la classe fiche-trois)
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
      if (cn && cn.observation) {   // « − » notés pour ce cours (les cartons sont devenus des motifs de la feuille de séance)
        var o = cn.observation(d.classe, e.numero, seanceDe(d).cle);
        Object.keys(o).forEach(function (k) { if (k !== '_c' && k !== 'note' && typeof o[k] === 'number' && o[k] < 0) observations++; });
      }
    });
    var actifs = (d.eleves || []).filter(function (e) { return e.actif; }).length;
    return Object.assign({}, d.etiquette, { atelier: d.atelier || 'aucun cours', eleves: actifs, observations: observations });
  }

  /* ---------- Appel : absences et retards de la séance affichée (gardés dans le navigateur, voir admin.js) ---------- */

  /** Séance d'un plan : celle du cours (« 2026-11-13 08:45 ») ou, à défaut, la journée. */
  function seanceDe(d) {
    if (d.seance && d.seance.cle) return d.seance;
    var cn = window.CentraleNoms;
    return { cle: cn && cn.dateLocale ? cn.dateLocale() : new Date().toISOString().slice(0, 10), libelle: 'aujourd’hui' };
  }

  /* ---------- Observations de séance (feuille de séance) : critères notés de −− à ++, oubli de matériel, commentaires ---------- */
  var CRITERES = {
    // gravite : couleur d'un « − » (jaune : léger, bavardage ; orange : cahier, matériel ; rouge : grave) — voir GRAVITE
    participation: { lettre: 'P', libelle: 'Participation', court: 'Particip.', gravite: 'jaune' },
    comportement:  { lettre: 'C', libelle: 'Comportement, bavardage', court: 'Comport.', gravite: 'jaune' },
    cahier:        { lettre: 'Ca', libelle: 'Cahier, classeur', court: 'Cahier', gravite: 'orange' },
    travail:       { lettre: 'T', libelle: 'Travail, autonomie', court: 'Travail', gravite: 'jaune' },
    materiel:      { lettre: 'M', libelle: 'Matériel (soin, utilisation)', court: 'Matériel', gravite: 'orange' },
    // case à cocher (toujours là, hors des critères choisis) : oublié ce cours = −1
    oubli:         { lettre: 'Ob', libelle: 'Oubli de matériel', caseACocher: true, gravite: 'orange' },
    equipe:        { lettre: 'E', libelle: 'Travail en équipe', court: 'Équipe', gravite: 'jaune' },
    rangement:     { lettre: 'Rg', libelle: 'Rangement, soin du matériel', court: 'Rangt', gravite: 'orange' },
    securite:      { lettre: 'S', libelle: 'Sécurité', court: 'Sécurité', gravite: 'rouge' },
    numerique:     { lettre: 'N', libelle: 'Usage du numérique', court: 'Numér.', gravite: 'jaune' },
  };
  /**
   * Motifs prêts à l'emploi pour préciser un critère (les anciens cartons d'observation et de récompense) : un clic
   * met le niveau (− ou +, s'il n'est pas déjà de ce côté) et ajoute le motif au commentaire de la séance.
   */
  var MOTIFS_CRITERES = {   // moins / plus : « − » et « + » ; moinsFort / plusFort : « −− » et « ++ »
    comportement:  { moins: ['Bavardage à voix haute', 'Bavardage hors de son groupe', 'Parle sans demander la parole', 'Ne lève pas la main avant de prendre la parole',
                             'Interrompt le prof quand il parle', 'Crie sans raison', 'Se lève sans autorisation', 'Se lève sans raison, sans autorisation'],
                     moinsFort: ['Insolent envers l’adulte', 'Refuse les consignes', 'Perturbe toute la classe', 'Jette un projectile dans la classe',
                                 'Jette un projectile derrière mon dos', 'Propos blessants', 'Gestes dangereux'],
                     plus: ['Calme et sérieux', 'Travaille calmement avec son voisin'], plusFort: ['Comportement exemplaire', 'Aide ses camarades', 'Bonne entente avec ses camarades', 'Ramène le calme dans son groupe'] },
    participation: { moins: ['Ne travaille pas sur son atelier', 'Ne participe pas', 'Surfe sur l’ordinateur pendant la correction'],
                     moinsFort: ['Refuse de participer', 'Aucun travail', 'Aucun travail malgré les relances'],
                     plus: ['Travail sérieux', 'Fait des efforts sur son atelier', 'Participe activement', 'Travaille bien en binôme'], plusFort: ['Travail remarquable', 'Va au-delà de la consigne', 'Moteur dans le groupe', 'Bonne dynamique de groupe'] },
    travail:       { moins: ['Ne travaille pas sur son atelier'], moinsFort: ['Aucun travail', 'Aucun travail malgré les relances', 'Refuse de travailler'],
                     plus: ['Travail sérieux', 'Fait des efforts sur son atelier', 'Travaille bien en binôme'], plusFort: ['Travail remarquable', 'Va au-delà de la consigne', 'Bonne dynamique de groupe'] },
    cahier:        { moins: ['Cahier non tenu', 'Classeur incomplet', 'Ne colle pas les feuilles distribuées', 'Ne prend pas la correction'],
                     moinsFort: ['Pas de cahier', 'Cahier perdu ou abîmé'],
                     plus: ['Cahier soigné'], plusFort: ['Cahier exemplaire'] },
    materiel:      { moins: ['Matériel mal utilisé'], moinsFort: ['Matériel dégradé'], plus: ['Prend soin du matériel'], plusFort: ['Soin exemplaire du matériel'] },
    rangement:     { moins: ['Poste non rangé'], moinsFort: ['Poste laissé en désordre, matériel abandonné'], plus: ['Range son poste'], plusFort: ['Range aussi pour les autres'] },
    securite:      { moins: ['Consignes de sécurité non respectées'], moinsFort: ['Comportement dangereux', 'Manipulation interdite'], plus: [], plusFort: [] },
    equipe:        { moins: ['Ne coopère pas'], moinsFort: ['Empêche son équipe de travailler'], plus: ['Aide ses camarades', 'Travaille bien en binôme'], plusFort: ['Moteur de son équipe', 'Bonne dynamique de groupe'] },
    numerique:     { moins: ['Usage du numérique hors consigne', 'Surfe sur l’ordinateur pendant la correction'], moinsFort: ['Usage interdit du numérique'], plus: [], plusFort: [] },
  };
  /** Cartons qui concernent d'autres élèves : on choisit les camarades (enregistrés par numéro, jamais par leur nom) ;
      dissipation / altercation : ils sont aussi notés « À séparer » ; entente : « Bonne dynamique » (à garder ensemble).
      Le plan de classe relie ces élèves (liensClasse). */
  var MOTIFS_AVEC = {
    'Bavardage à voix haute': 'dissipation', 'Bavardage hors de son groupe': 'dissipation',
    'Propos blessants': 'altercation', 'Gestes dangereux': 'altercation',
    'Aide ses camarades': 'entente', 'Travaille calmement avec son voisin': 'entente', 'Bonne entente avec ses camarades': 'entente',
    'Travaille bien en binôme': 'entente', 'Bonne dynamique de groupe': 'entente',
  };
  /** Cartons que les camarades choisis reçoivent aussi (on bavarde, on travaille bien… à plusieurs). */
  var MOTIFS_POUR_TOUS = {
    'Bavardage à voix haute': 1, 'Bavardage hors de son groupe': 1,
    'Travaille calmement avec son voisin': 1, 'Bonne entente avec ses camarades': 1, 'Travaille bien en binôme': 1, 'Bonne dynamique de groupe': 1,
  };
  /** Pour l'affichage seulement : « n° 07 » d'un commentaire devient le nom de l'élève (gardé sur cet ordinateur). */
  function formateurNoms(d) {
    var cn = window.CentraleNoms, par = {};
    (d.eleves || []).forEach(function (x) { par[String(x.numero)] = x; });
    return function (t) {
      return String(t || '').replace(/n° (\d+)(?!\d)(?! \()/g, function (tout, n) {
        var x = par[n], nom = x && ((cn && cn.nomLocal ? cn.nomLocal(d.classe, n) : '') || x.simule);
        return nom || tout;
      });
    };
  }
  /**
   * Fenêtre « avec qui ? » d'un carton qui concerne des camarades (bavardage avec…, propos blessants envers…) : les élèves
   * de la classe en pastilles (photo, nom) ; valider(numéros) — [] avec « Sans préciser ». Ajoutée dans conteneur
   * (la feuille, ou la fiche ouverte en fenêtre modale).
   */
  function choisirCamarades(conteneur, d, num, motif, type, valider, preselection) {
    var cn = window.CentraleNoms, fondC = creer('div', 'camarades-fond'), carte = creer('div', 'camarades-carte'), choix = {};
    (preselection || []).forEach(function (n) { choix[String(n)] = true; });
    function fermer() { fondC.remove(); document.removeEventListener('keydown', echap, true); }
    function echap(ev) { if (ev.key === 'Escape') { ev.stopImmediatePropagation(); fermer(); } }
    document.addEventListener('keydown', echap, true);
    fondC.addEventListener('click', function (ev) { ev.stopPropagation(); if (ev.target === fondC) fermer(); });
    var titre = creer('h4', 'camarades-titre', motif + ' : avec qui ?');
    var aide = creer('p', 'discret camarades-aide', 'Seuls les numéros sont enregistrés, jamais les noms.'
      + (type === 'dissipation' ? ' Les élèves choisis sont aussi notés « À séparer » (dissipation).' : type === 'altercation' ? ' Les élèves choisis sont aussi notés « À séparer » (altercation).'
        : type === 'entente' ? ' Les élèves choisis sont aussi notés « Bonne dynamique » : le plan de classe les relie en vert.' : '')
      + (MOTIFS_POUR_TOUS[motif] ? ' Ils reçoivent aussi ce carton.' : ''));
    var grille = creer('div', 'camarades-grille'), valide = creer('button', 'petit camarades-valider');
    function majValide() { var n = Object.keys(choix).filter(function (k) { return choix[k]; }).length; valide.textContent = n ? 'Valider (' + n + ')' : 'Valider'; valide.disabled = !n; }
    (d.eleves || []).filter(function (x) { return x.actif && String(x.numero) !== String(num); })
      .sort(function (a, b) { return String(a.numero).localeCompare(String(b.numero), 'fr', { numeric: true }); })
      .forEach(function (x) {
        var b = creer('button', 'camarade'), v = creer('span', 'camarade-photo'), visage = photo(d.classe, x.numero);
        var nom = (cn.nomLocal ? cn.nomLocal(d.classe, x.numero) : '') || x.simule || ('n° ' + x.numero);
        b.type = 'button';
        if (visage && cn.styleVisage) cn.styleVisage(v, visage, 30 / visage.l, 30, 36); else v.textContent = String(nom).replace(/[^A-Za-zÀ-ÿ0-9]/g, '').slice(0, 1);
        b.append(v, creer('span', 'camarade-nom', nom), creer('span', 'camarade-num', 'n° ' + x.numero));
        b.setAttribute('aria-pressed', String(!!choix[String(x.numero)]));
        b.addEventListener('click', function () { choix[x.numero] = !choix[x.numero]; b.setAttribute('aria-pressed', String(!!choix[x.numero])); majValide(); });
        grille.appendChild(b);
      });
    var pied = creer('div', 'camarades-pied'), annuler = creer('button', 'petit secondaire', 'Annuler'), sans = creer('button', 'petit secondaire', 'Sans préciser');
    annuler.type = sans.type = valide.type = 'button';
    annuler.addEventListener('click', fermer);
    sans.addEventListener('click', function () { fermer(); valider([]); });
    valide.addEventListener('click', function () {
      var nums = Object.keys(choix).filter(function (k) { return choix[k]; }).sort(function (a, b) { return a.localeCompare(b, 'fr', { numeric: true }); });
      fermer();
      valider(nums);
    });
    majValide();
    pied.append(annuler, sans, valide);
    carte.append(titre, aide, grille, pied);
    fondC.appendChild(carte);
    conteneur.appendChild(fondC);
  }
  /** Ajoute un carton (avec son heure) aux commentaires d'un élève pour un critère, et met son niveau à la moyenne des cartons. */
  function ajouterCartonEleve(classe, num, seance, k, v, texte, heure) {
    var cn = window.CentraleNoms;
    var garde = cn.definirCommentaire(classe, num, seance, k, ajouterEntree((cn.observation(classe, num, seance)._c || {})[k], ecrireEntree(v, texte, heure)));
    var moy = niveauMoyen(garde), niveau = moy ? moy.niveau : v;
    cn.definirObservation(classe, num, seance, k, niveau);
    return niveau;
  }
  /** Retire chez un camarade le carton répercuté (même heure, même motif, « avec » l'élève) ; son niveau suit la moyenne. */
  function retirerCartonRepercute(classe, n, seance, k, num, heure, base) {
    var cn = window.CentraleNoms, l = decouperCommentaires((cn.observation(classe, n, seance)._c || {})[k]), garde = null;
    var reste = l.filter(function (t) {
      var x = lireEntree(t), a = extraireAvec(x.texte);
      var meme = garde === null && x.heure === heure && a.base === base && a.nums.indexOf(String(num)) >= 0;
      if (meme) garde = t;
      return !meme;
    });
    if (garde === null) return null;
    var texte = cn.definirCommentaire(classe, n, seance, k, reste.join(' ; ')), moy = niveauMoyen(texte), niveau = moy ? moy.niveau : 0;
    cn.definirObservation(classe, n, seance, k, niveau);
    return niveau;
  }
  /** Bavardage (dissipation) avec des camarades : chacun reçoit aussi le carton, « … avec » les autres. */
  function repercuterCarton(classe, num, nums, seance, k, v, motif, apresEleve, heure) {
    nums.forEach(function (n) {
      var autres = [String(num)].concat(nums.filter(function (x) { return x !== n; }));
      var niveau = ajouterCartonEleve(classe, n, seance, k, v, motifAvec(motif, autres), heure);
      if (apresEleve) apresEleve(n, niveau);
    });
  }
  /** Le texte du carton, avec les camarades choisis : « Bavardage hors de son groupe avec n° 07, n° 12 ». */
  function motifAvec(motif, nums) { return motif + (nums.length ? ' avec ' + nums.map(function (n) { return 'n° ' + n; }).join(', ') : ''); }

  /*
   * Commentaires d'un critère pour une séance : des entrées séparées par « ; », chacune avec son heure et, pour un carton,
   * son niveau : « [09:12] [−] Bavardage hors de son groupe avec n° 11 », « [09:20] lance une gomme ». (Un seul texte :
   * il est enregistré et synchronisé tel quel ; les anciens commentaires sans crochets restent lisibles.)
   * Le niveau du critère est la MOYENNE des cartons de la séance (arrondie), recalculée à chaque carton ajouté ou retiré.
   */
  var SIGNES_NIVEAU = { '−−': -2, '−': -1, '+': 1, '++': 2 };
  function heureCourante() { var n = new Date(); return ('0' + n.getHours()).slice(-2) + ':' + ('0' + n.getMinutes()).slice(-2); }
  function lireEntree(t) {
    var m = /^(?:\[(\d{1,2}:\d{2})\]\s*)?(?:\[(−−|−|\+\+|\+)\]\s*)?([\s\S]*)$/.exec(String(t || '').trim());
    return { heure: m[1] || '', niveau: m[2] ? SIGNES_NIVEAU[m[2]] : null, texte: m[3] };
  }
  function ecrireEntree(niveau, texte, heure) { return '[' + (heure || heureCourante()) + '] ' + (niveau ? '[' + signeNiveau(niveau) + '] ' : '') + texte; }
  /** « Bavardage hors de son groupe avec n° 11, n° 13 » → { base: 'Bavardage hors de son groupe', nums: ['11', '13'] }. */
  function extraireAvec(texte) {
    var m = / avec ((?:n° \d+(?:, )?)+)$/.exec(String(texte || ''));
    if (!m) return { base: String(texte || ''), nums: [] };
    return { base: texte.slice(0, m.index), nums: (m[1].match(/\d+/g) || []) };
  }
  function ajouterEntree(texte, entree) { return decouperCommentaires(texte).concat([entree]).join(' ; '); }
  /** Moyenne des cartons d'un texte de commentaires : { n, moyenne, niveau } (niveau arrondi de −2 à 2), ou null sans carton. */
  function niveauMoyen(texte) {
    var vs = decouperCommentaires(texte).map(lireEntree).filter(function (x) { return x.niveau != null; }).map(function (x) { return x.niveau; });
    if (!vs.length) return null;
    var moy = vs.reduce(function (a, b) { return a + b; }, 0) / vs.length;
    return { n: vs.length, moyenne: moy, niveau: Math.max(-2, Math.min(2, (moy < 0 ? -1 : 1) * Math.round(Math.abs(moy)))) };
  }
  /** Une entrée en texte simple (bulle, historique) : « 09:12 − Bavardage… ». */
  function entreeLisible(t, f) {
    var x = lireEntree(t);
    return (x.heure ? x.heure + ' ' : '') + (x.niveau ? 'carton ' + signeNiveau(x.niveau) + ' · ' : '') + (f ? f(x.texte) : x.texte);
  }
  /** Tout un texte de commentaires, lisible : les entrées séparées par « ; ». */
  function commentairesLisibles(texte, f) { return decouperCommentaires(texte).map(function (t) { return entreeLisible(t, f); }).join(' ; '); }
  /** Les commentaires d'un critère pour une séance (un seul texte, séparés par « ; »). */
  function decouperCommentaires(texte) { return String(texte || '').split(/\s*;\s*/).map(function (x) { return x.trim(); }).filter(Boolean); }
  /**
   * Commentaires d'un critère pour la séance, qui s'accumulent pendant le cours : un champ pour en ajouter un (↵ ou
   * Entrée), puis la liste repliée (« ▸ 3 commentaires · le dernier ») qui se déplie, chaque commentaire avec ×.
   * lire() : le texte enregistré ; ecrire(texte) : l'enregistre et renvoie le texte gardé ; apres() : après un changement.
   */
  function zoneCommentaires(lire, ecrire, apres, placeholder, ouverte, formater, apresRetraitCarton, camarades) {
    // camarades : { d, num, seance, k, conteneur(), apresEleve(n, niveau) } — pour changer les élèves associés à un carton
    var f = formater || function (t) { return t; };
    var z = creer('div', 'coms'), saisie = creer('div', 'coms-saisie'), champ = creer('input', 'niv-com coms-champ'), ok = creer('button', 'coms-ok', '↵');
    var tete = creer('button', 'coms-tete'), liste = creer('ul', 'coms-liste');
    champ.type = 'text';
    champ.maxLength = 200;
    champ.placeholder = placeholder || 'Ajouter un commentaire';
    ok.type = tete.type = 'button';
    ok.title = 'Ajouter ce commentaire (Entrée)';
    ok.hidden = true;
    liste.hidden = !ouverte;
    if (ouverte) z.classList.add('coms-ouverte');   // fenêtre d'évaluation : la liste toujours dépliée
    function maj() {
      var l = decouperCommentaires(lire());
      tete.hidden = !l.length;
      tete.replaceChildren(creer('span', 'coms-fleche', liste.hidden ? '▸' : '▾'), creer('span', 'coms-nombre', l.length + ' commentaire' + (l.length > 1 ? 's' : '')),
        creer('span', 'coms-dernier', liste.hidden && l.length ? entreeLisible(l[l.length - 1], f) : ''));
      tete.title = liste.hidden ? 'Voir les commentaires' : 'Replier';
      liste.replaceChildren();
      l.forEach(function (t, i) {
        var li = creer('li'), x = creer('button', 'coms-retirer', '×');
        x.type = 'button';
        x.title = 'Retirer ce commentaire';
        x.addEventListener('click', function (ev) {
          ev.stopPropagation();
          ecrire(l.filter(function (_, j) { return j !== i; }).join(' ; '));
          maj();
          if (en.niveau != null && apresRetraitCarton) apresRetraitCarton();   // carton retiré : le niveau (moyenne) se recalcule
          if (apres) apres();
        });
        // l'heure, le carton (son niveau, aux couleurs) ou ✎ pour un commentaire écrit, puis le texte
        var en = lireEntree(t), corps = creer('span', 'coms-texte');
        if (en.niveau != null) {
          var c = creer('span', 'coms-carton ' + (en.niveau < 0 ? 'moins' : 'plus') + (Math.abs(en.niveau) > 1 ? ' fort' : ''), signeNiveau(en.niveau));
          c.title = 'Carton « ' + signeNiveau(en.niveau) + ' » (' + NIVEAUX[String(en.niveau)].libelle + ')';
          corps.appendChild(c);
        } else corps.appendChild(creer('span', 'coms-ecrit', '✎'));
        corps.appendChild(creer('span', '', ' ' + f(en.texte)));
        // ✎ : modifier sur place — le niveau du carton (ou commentaire écrit) et le texte ; l'heure reste
        var mod = creer('button', 'coms-modifier', '✎');
        mod.type = 'button';
        mod.title = 'Modifier ce ' + (en.niveau != null ? 'carton' : 'commentaire');
        mod.addEventListener('click', function (ev) {
          ev.stopPropagation();
          var form = creer('div', 'coms-edition'), niveaux = creer('div', 'coms-edition-niveaux'), champ = creer('input', 'niv-com'), choixNiv = en.niveau;
          [-2, -1, null, 1, 2].forEach(function (v) {
            var b = creer('button', 'coms-edition-niv ' + (v == null ? 'ecrit' : v < 0 ? 'moins' : 'plus') + (v != null && Math.abs(v) > 1 ? ' fort' : ''), v == null ? '✎' : signeNiveau(v));
            b.type = 'button';
            b.title = v == null ? 'Commentaire écrit (pas un carton : ne compte pas dans la moyenne)' : 'Carton « ' + signeNiveau(v) + ' » (' + NIVEAUX[String(v)].libelle + ')';
            b.setAttribute('aria-pressed', String(v === choixNiv));
            b.addEventListener('click', function (e2) {
              e2.stopPropagation();
              choixNiv = v;
              niveaux.querySelectorAll('button').forEach(function (x2) { x2.setAttribute('aria-pressed', 'false'); });
              b.setAttribute('aria-pressed', 'true');
            });
            niveaux.appendChild(b);
          });
          champ.type = 'text';
          champ.maxLength = 200;
          champ.value = f(en.texte);   // les noms affichés redeviennent des numéros à l'enregistrement
          // carton avec des camarades (« … avec n° 11, n° 13 ») : les élèves se changent dans la fenêtre de sélection
          var avec = extraireAvec(en.texte), typeAvec = camarades ? MOTIFS_AVEC[avec.base] : null, numsAvant = avec.nums.slice(), nums = avec.nums.slice(), btnAvec = null;
          if (camarades && (nums.length || typeAvec)) {
            champ.value = f(avec.base);
            btnAvec = creer('button', 'coms-edition-avec');
            btnAvec.type = 'button';
            var majAvec = function () {
              btnAvec.textContent = '👥 ' + (nums.length ? 'avec ' + nums.map(function (n) { return f('n° ' + n); }).join(', ') : 'aucun camarade') + ' — changer';
            };
            majAvec();
            btnAvec.addEventListener('click', function (e2) {
              e2.stopPropagation();
              choisirCamarades(camarades.conteneur(), camarades.d, camarades.num, avec.base, typeAvec || 'camarades', function (nouveaux) { nums = nouveaux; majAvec(); }, nums);
            });
          }
          var ok2 = creer('button', 'petit', 'Enregistrer'), annuler = creer('button', 'petit secondaire', 'Annuler'), boutons = creer('div', 'coms-edition-boutons');
          ok2.type = annuler.type = 'button';
          function enregistrer() {
            var texte = champ.value.replace(/;/g, ',').trim();
            if (!texte) { champ.focus(); return; }
            if (btnAvec) {   // la base (sans noms) puis les élèves choisis, par numéro
              texte = texte.replace(/ avec .*$/, '') + (nums.length ? ' avec ' + nums.map(function (n) { return 'n° ' + n; }).join(', ') : '');
              var c = camarades, base = texte.replace(/ avec .*$/, ''), cn2 = window.CentraleNoms;
              var ajoutes = nums.filter(function (n) { return numsAvant.indexOf(n) < 0; }), retires = numsAvant.filter(function (n) { return nums.indexOf(n) < 0; });
              if (MOTIFS_POUR_TOUS[avec.base] && choixNiv != null) {   // bavardage, bonne dynamique : le carton suit chez les camarades ajoutés ou retirés
                retires.forEach(function (n) { var niv = retirerCartonRepercute(c.d.classe, n, c.seance, c.k, c.num, en.heure, avec.base); if (niv !== null && c.apresEleve) c.apresEleve(n, niv); });
                if (ajoutes.length) repercuterCarton(c.d.classe, c.num, ajoutes, c.seance, c.k, choixNiv, base, c.apresEleve, en.heure);
              }
              if (ajoutes.length && TYPES_DUO[typeAvec] && cn2 && cn2.ajouterIncident) cn2.ajouterIncident(c.d.classe, [String(c.num)].concat(ajoutes), typeAvec, c.seance, base);
            }
            var nouvelle = (en.heure ? '[' + en.heure + '] ' : '') + (choixNiv != null ? '[' + signeNiveau(choixNiv) + '] ' : '') + texte;
            var l2 = decouperCommentaires(lire());
            l2[i] = nouvelle;
            ecrire(l2.join(' ; '));
            maj();
            if ((en.niveau != null || choixNiv != null) && apresRetraitCarton) apresRetraitCarton();   // un carton a changé : la moyenne se recalcule
            if (apres) apres();
          }
          ok2.addEventListener('click', function (e2) { e2.stopPropagation(); enregistrer(); });
          annuler.addEventListener('click', function (e2) { e2.stopPropagation(); maj(); });
          champ.addEventListener('keydown', function (e2) {
            if (e2.key === 'Enter') { e2.preventDefault(); e2.stopPropagation(); enregistrer(); }
            if (e2.key === 'Escape') { e2.stopPropagation(); maj(); }
          });
          boutons.append(annuler, ok2);
          form.append(niveaux, champ);
          if (btnAvec) form.appendChild(btnAvec);
          form.appendChild(boutons);
          form.addEventListener('click', function (e2) { e2.stopPropagation(); });
          li.replaceChildren(creer('span', 'coms-heure', en.heure || '—'), form);
          li.classList.add('en-edition');
          champ.focus();
          champ.select();
        });
        li.append(creer('span', 'coms-heure', en.heure || '—'), corps, mod, x);
        liste.appendChild(li);
      });
      if (!l.length) liste.hidden = true;
      else if (ouverte) { liste.hidden = false; tete.hidden = true; }
    }
    function ajouter() {
      var t = champ.value.replace(/;/g, ',').trim();
      if (!t) return;
      ecrire(ajouterEntree(lire(), ecrireEntree(null, t)));
      champ.value = '';
      ok.hidden = true;
      maj();
      if (apres) apres();
    }
    champ.addEventListener('input', function () { ok.hidden = !champ.value.trim(); });
    champ.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); ev.stopPropagation(); ajouter(); }
      if (ev.key === 'Escape') { ev.stopPropagation(); champ.blur(); }
    });
    ok.addEventListener('click', function (ev) { ev.stopPropagation(); ajouter(); champ.focus(); });
    tete.addEventListener('click', function (ev) { ev.stopPropagation(); liste.hidden = !liste.hidden; maj(); });
    saisie.append(champ, ok);
    z.append(saisie, tete, liste);
    z.maj = maj;
    z.champ = champ;
    maj();
    return z;
  }
  /** Une rangée : les cartons « À revoir » (couleur de la gravité) à gauche des niveaux −− · ++ (centre), les cartons
      « Bien » (vert) à droite ; surMotif(motif, signe) au clic. null si le critère n'a pas de motifs. */
  function pastillesMotifs(k, surMotif, centre) {
    var m = MOTIFS_CRITERES[k], boite = creer('div', 'niv-motifs grav-' + ((CRITERES[k] || {}).gravite || 'orange'));
    if (!m) return null;
    centre.classList.add('motifs-centre');
    [['moins', -1, 'À revoir', 'carton-pre-observation'], ['plus', 1, 'Bien', 'carton-pre-recompense']].forEach(function (g) {
      var col = creer('div', 'motifs-col motifs-' + g[0]);
      var liste = (m[g[0]] || []).map(function (x) { return { motif: x, fort: false }; }).concat((m[g[0] + 'Fort'] || []).map(function (x) { return { motif: x, fort: true }; }));
      // paquet replié à la hauteur des boutons : le premier carton, les autres dans une liste qui s'ouvre au survol
      var cases = creer('div', 'motifs-cases' + (liste.length > 1 ? ' en-pile' : '')), suite = liste.length > 1 ? creer('div', 'motifs-suite') : null;
      liste.forEach(function (x, i) {
        var motif = x.motif, signe = g[1] * (x.fort ? 2 : 1);
        var b = creer('button', 'motif carton-pre ' + g[3] + (x.fort ? ' fort' : '') + (MOTIFS_AVEC[motif] ? ' avec' : ''), motif);
        b.type = 'button';
        b.title = (x.fort ? (signe < 0 ? 'Gros souci' : 'Très bien') : g[2]) + ' : ' + motif + ' (le critère passe à « ' + signeNiveau(signe) + ' », le motif s’ajoute aux commentaires)';
        b.addEventListener('click', function (ev) { ev.stopPropagation(); surMotif(motif, signe); b.blur(); });
        (i === 0 ? cases : suite).appendChild(b);
      });
      if (suite) {
        cases.appendChild(suite);
        var nb = creer('span', 'motifs-nombre', String(liste.length));
        nb.title = liste.length + ' motifs « ' + g[2] + ' » : survolez le carton';
        cases.appendChild(nb);
      }
      if (!liste.length) cases.appendChild(creer('span', 'discret motifs-vide', '—'));
      col.appendChild(cases);
      boite.appendChild(col);
      if (g[1] < 0) boite.appendChild(centre);   // les niveaux entre les deux paquets
    });
    return boite;
  }


  /** Couleurs par gravité, partout (feuille, légende, plan 3D) : retard, bavardage, dissipation en jaune ; cahier, oubli
      en orange ; exclusion, altercation en rouge ; absent en gris bleu (une information, pas un comportement). */
  var GRAVITE = {
    jaune:  { couleur: '#e0a800', fort: '#a87b00', texte: '#2b2000', texteFort: '#ffffff', libelle: 'léger : retard, bavardage, dissipation' },
    orange: { couleur: '#e67e22', fort: '#a84f0c', texte: '#ffffff', texteFort: '#ffffff', libelle: 'à surveiller : cahier non tenu, oubli de matériel' },
    rouge:  { couleur: '#c0392b', fort: '#8e1f15', texte: '#ffffff', texteFort: '#ffffff', libelle: 'grave : exclusion, altercation' },
    gris:   { couleur: '#5b6b8c', fort: '#3d4a66', texte: '#ffffff', texteFort: '#ffffff', libelle: 'absent' },
  };
  /** Cinq niveaux : −− (-2), − (-1), = (rien, comme d'habitude), + (1), ++ (2). */
  var NIVEAUX = { '-2': { signe: '−−', libelle: 'gros souci' }, '-1': { signe: '−', libelle: 'à revoir' }, '0': { signe: '=', libelle: 'comme d’habitude' },
                  '1': { signe: '+', libelle: 'bien' }, '2': { signe: '++', libelle: 'très bien' } };
  function signeNiveau(v) { return (NIVEAUX[String(v)] || NIVEAUX['0']).signe; }
  /** Pastilles de la séance affichée : « P+ », « P++ » en vert, « C− », « C−− » en rouge (critères choisis seulement). */
  function pastillesObservation(d, e) {
    var cn = window.CentraleNoms;
    if (!cn || !cn.observation) return [];
    var o = cn.observation(d.classe, e.numero, seanceDe(d).cle), res = [];
    cn.criteres().concat(['oubli']).forEach(function (k) { if (o[k] && CRITERES[k]) res.push('ob:' + CRITERES[k].lettre + ':' + o[k] + ':' + (CRITERES[k].gravite || 'orange')); });
    return res;
  }
  /** Bilan d'un élève pour un critère, sur toutes les séances notées : { plus, moins, seances: [ {cle, v} ] (récentes d'abord) }. */
  function bilanCritere(classe, numero, k) {
    var cn = window.CentraleNoms, toutes = cn && cn.observationsEleve ? cn.observationsEleve(classe, numero) : {},
        b = { plus: 0, moins: 0, seances: [], commentaires: [] };
    Object.keys(toutes).sort().reverse().forEach(function (cle) {
      var v = toutes[cle][k], com = (toutes[cle]._c || {})[k];
      if (com) b.commentaires.push({ cle: cle, texte: com, v: v || 0 });
      if (!v) return;
      if (v > 0) b.plus += v; else b.moins -= v;   // ++ compte 2, −− compte 2
      b.seances.push({ cle: cle, v: v });
    });
    return b;
  }
  function pointsSeances(b, n) {   // les n dernières séances notées : ● vert (+) ou rouge (−), la plus récente à droite
    var span = creer('span', 'obs-points');
    span.title = 'Dernières séances notées (la plus récente à droite)';
    b.seances.slice(0, n).reverse().forEach(function (x) {
      var p = creer('i', (x.v > 0 ? 'plus' : 'moins') + (Math.abs(x.v) > 1 ? ' fort' : ''));
      p.title = x.cle.slice(0, 10).split('-').reverse().join('/') + (x.cle.length > 10 ? ' ' + x.cle.slice(11) : '') + ' : ' + signeNiveau(x.v);
      span.appendChild(p);
    });
    return span;
  }

  /**
   * Feuille de séance (comme une feuille d'appel) : tous les élèves d'un coup, une ligne chacun.
   *   En classe (noté par l'enseignant, synchronisé avec la Centrale) : Absent, Retard, Oubli de matériel, puis les critères de −− à ++
   *   (Cahier, Comportement, Participation, puis les autres critères choisis) — une pastille, qui se déplie au survol —
   *   et un commentaire.
   *   Plateforme (calculé par la Centrale) : activités terminées, réussite du 1er coup, temps actif, dernière activité, repères.
   * Pour la séance du plan affiché à l'accueil. Chaque case s'enregistre tout de suite ; le plan 3D suit.
   */
  var planAccueil = null;   // plan affiché dans la vue de l'accueil : { svg, d, carte } (feuille de séance)
  var ORDRE_FEUILLE = ['cahier', 'comportement', 'participation'];

  /* À séparer : des élèves (deux ou plus) qui se dissipent ensemble ou se sont disputés (noté dans la feuille de séance, gardé sur cet
   * ordinateur). Signalés sur le plan de classe quand ils sont voisins ; le placement au hasard les sépare. */
  var TYPES_DUO = {
    dissipation: { libelle: 'Dissipation', icone: '💬', texte: 'se dissipent ensemble (bavardages, chahut)' },
    altercation: { libelle: 'Altercation', icone: '⚔️', texte: 'se sont disputés ou accrochés' },
    entente: { libelle: 'Bonne dynamique', icone: '🤝', texte: 'travaillent bien ensemble : à garder ensemble', positif: true }
  };
  /** Les paires d'une classe : [{ a, b, dissipation, altercation, poids, derniere, textes }], la plus lourde d'abord. */
  function pairesDuos(classe) {   // chaque incident à plusieurs donne toutes ses paires
    var cn = window.CentraleNoms, carte = {};
    if (!cn || !cn.incidents) return [];
    cn.incidents(classe).forEach(function (x) {
      if (!TYPES_DUO[x.type] || TYPES_DUO[x.type].positif) return;   // bonne dynamique : pas à séparer
      var m = x.membres.slice().sort(function (u, v) { return u.localeCompare(v, 'fr', { numeric: true }); });
      for (var i = 0; i < m.length; i++) for (var j = i + 1; j < m.length; j++) {
        var a = m[i], b = m[j];
        var p = carte[a + '|' + b] = carte[a + '|' + b] || { a: a, b: b, dissipation: 0, altercation: 0, poids: 0, derniere: '', textes: [] };
        p[x.type]++;
        p.poids += x.type === 'altercation' ? 2 : 1;
        if (x.seance > p.derniere) p.derniere = x.seance;
        if (x.texte) p.textes.push(x.texte);
      }
    });
    return Object.keys(carte).map(function (k) { return carte[k]; }).sort(function (u, v) { return v.poids - u.poids; });
  }
  function jourSeance(cle) { return cle ? cle.slice(8, 10) + '/' + cle.slice(5, 7) : ''; }
  function resumePaire(p) {
    return [p.dissipation ? p.dissipation + ' dissipation' + (p.dissipation > 1 ? 's' : '') : '', p.altercation ? p.altercation + ' altercation' + (p.altercation > 1 ? 's' : '') : '',
      p.entente ? p.entente + ' bonne' + (p.entente > 1 ? 's' : '') + ' dynamique' + (p.entente > 1 ? 's' : '') : '']
      .filter(Boolean).join(' · ') + (p.derniere ? ' (dernière le ' + jourSeance(p.derniere) + ')' : '');
  }
  /**
   * Liens entre élèves (feuille de séance, cartons « avec qui ? »), par paire, toutes séances :
   * [{ a, b, dissipation, altercation, entente, derniere, type, nb }] — type : altercation, sinon dissipation (à séparer), sinon entente.
   */
  function liensClasse(classe) {
    var cn = window.CentraleNoms, carte = {};
    if (!cn || !cn.incidents) return [];
    cn.incidents(classe).forEach(function (x) {
      if (!TYPES_DUO[x.type]) return;
      var m = x.membres.slice().sort(function (u, v) { return u.localeCompare(v, 'fr', { numeric: true }); });
      for (var i = 0; i < m.length; i++) for (var j = i + 1; j < m.length; j++) {
        var p = carte[m[i] + '|' + m[j]] = carte[m[i] + '|' + m[j]] || { a: m[i], b: m[j], dissipation: 0, altercation: 0, entente: 0, derniere: '' };
        p[x.type]++;
        if (x.seance > p.derniere) p.derniere = x.seance;
      }
    });
    return Object.keys(carte).map(function (k) {
      var p = carte[k];
      p.type = p.altercation ? 'altercation' : p.dissipation ? 'dissipation' : 'entente';
      p.nb = p.type === 'entente' ? p.entente : p.altercation + p.dissipation;
      return p;
    });
  }
  function liensVisibles() { return !document.documentElement.classList.contains('sans-liens'); }
  /**
   * Les liens des élèves assis, pour le plan : [{ a, b (places), type, nb, titre }]. carte : place → id de l'élève ;
   * nomDe(numéro) : le nom affiché (gardé sur cet ordinateur).
   */
  function liensPlaces(d, carte, nomDe) {
    if (!liensVisibles()) return [];
    var placeDe = {}, idParNum = {};
    Object.keys(carte || {}).forEach(function (pl) { placeDe[String(carte[pl])] = pl; });
    (d.eleves || []).forEach(function (e) { idParNum[String(e.numero)] = e.id; });
    return liensClasse(d.classe).map(function (p) {
      var pa = placeDe[String(idParNum[p.a])], pb = placeDe[String(idParNum[p.b])];
      if (!pa || !pb) return null;
      var titre = (TYPES_DUO[p.type].positif ? 'Bonne dynamique' : 'À séparer') + ' · ' + nomDe(p.a) + ' ↔ ' + nomDe(p.b) + ' : ' + resumePaire(p);
      return { a: pa, b: pb, type: p.type, nb: p.nb, titre: titre, icone: TYPES_DUO[p.type].icone };
    }).filter(Boolean);
  }

  /* ---------- Note de séance : moyenne des critères (non noté = 0, comme d'habitude ; oubli de matériel = −1),
     corrigeable à la main (clé « note », de −2 à 2, avec un commentaire « note ») ---------- */
  function noteCalculee(ob, criteres) {
    var vals = criteres.map(function (k) { return ob[k] || 0; });
    if (ob.oubli) vals.push(-1);
    if (ob.exclusion) vals.push(-2);   // exclusion du cours
    return vals.length ? vals.reduce(function (a, b) { return a + b; }, 0) / vals.length : 0;
  }
  function noteSeance(ob, criteres) {
    var calc = noteCalculee(ob, criteres);
    return typeof ob.note === 'number' ? { v: ob.note, auto: false, calc: calc } : { v: calc, auto: true, calc: calc };
  }
  function texteNote(v) {
    if (v == null) return '—';
    var r = Math.round(v * 10) / 10;
    if (r === 0) return '=';   // comme d'habitude
    return (r > 0 ? '+' : '−') + String(Math.abs(r)).replace('.', ',');
  }
  function niveauNote(v) { return v == null ? null : v >= 1.5 ? 2 : v >= 0.5 ? 1 : v <= -1.5 ? -2 : v <= -0.5 ? -1 : 0; }
  function classeNote(v) { var n = niveauNote(v); return n == null ? 'note-vide' : 'note-n' + String(n).replace('-', 'm'); }
  function dateSeance(cle) { return cle.slice(8, 10) + '/' + cle.slice(5, 7) + (cle.length > 10 ? ' ' + cle.slice(11) : ''); }

  /**
   * Graphique d'évolution (fenêtre par-dessus la feuille) : une ligne de note par séance, de −− à ++ ;
   * les notes corrigées à la main sont cerclées ; au survol d'un point, le détail.
   * points : [{ cle, v (ou null : absent), auto, detail }], dans l'ordre des séances.
   */
  function graphiqueEvolution(conteneur, titre, sousTitre, points) {
    var pop = conteneur.querySelector('.evolution-pop');
    if (!pop) {
      pop = creer('div', 'legende-pop evolution-pop');
      pop.addEventListener('click', function (ev) { ev.stopPropagation(); });
      conteneur.appendChild(pop);
    }
    pop.replaceChildren();
    var fermerG = creer('button', 'fiche-trois-fermer', '×');
    fermerG.type = 'button';
    fermerG.addEventListener('click', function () { pop.hidden = true; });
    pop.append(fermerG, creer('h4', '', titre), creer('p', 'discret', sousTitre));
    var notes = points.filter(function (p) { return p.v != null; });
    if (!notes.length) {
      pop.appendChild(creer('p', '', 'Aucune séance notée pour l’instant.'));
      pop.hidden = false;
      return;
    }
    var W = 560, H = 230, gx = 46, gy = 16, dx = 18, bas = 34, n = points.length;
    var lw = W - gx - dx, lh = H - gy - bas;
    function X(i) { return gx + (n > 1 ? i * lw / (n - 1) : lw / 2); }
    function Y(v) { return gy + (2 - v) / 4 * lh; }
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'graphique-evolution', role: 'img', 'aria-label': titre });
    [2, 1, 0, -1, -2].forEach(function (v) {
      el('line', { x1: gx, x2: W - dx, y1: Y(v), y2: Y(v), class: v === 0 ? 'axe-zero' : 'grille-g' }, svg);
      var t = el('text', { x: gx - 8, y: Y(v) + 4, class: 'etiq-y' }, svg);
      t.textContent = signeNiveau(v);
    });
    // la ligne, coupée aux séances d'absence
    var d = '', leve = true;
    points.forEach(function (p, i) {
      if (p.v == null) { leve = true; return; }
      d += (leve ? 'M' : 'L') + X(i).toFixed(1) + ',' + Y(p.v).toFixed(1) + ' ';
      leve = false;
    });
    el('path', { d: d, class: 'ligne-note' }, svg);
    var pas = Math.max(1, Math.ceil(n / 10));   // pas plus de 10 dates sous l'axe
    points.forEach(function (p, i) {
      if (i % pas === 0 || i === n - 1) {
        var t = el('text', { x: X(i), y: H - bas + 18, class: 'etiq-x' }, svg);
        t.textContent = p.cle.slice(8, 10) + '/' + p.cle.slice(5, 7);
      }
      if (p.v == null) {
        var a = el('text', { x: X(i), y: Y(0) + 4, class: 'point-absent' }, svg);
        a.textContent = 'A';
        el('title', {}, a).textContent = dateSeance(p.cle) + ' : absent';
        return;
      }
      if (p.marque) { var mq = el('text', { x: X(i), y: Y(p.v) - 10, class: 'point-marque' }, svg); mq.textContent = p.marque; }
      var c = el('circle', { cx: X(i), cy: Y(p.v), r: p.auto ? 5 : 6.5, class: 'point-note ' + classeNote(p.v) + (p.auto ? '' : ' corrigee') }, svg);
      el('title', {}, c).textContent = dateSeance(p.cle) + ' : ' + texteNote(p.v) + (p.auto ? ' (calculée)' : ' (corrigée)') + (p.detail ? '\n' + p.detail : '');
    });
    pop.appendChild(svg);
    var moy = notes.reduce(function (a, p) { return a + p.v; }, 0) / notes.length, der = notes[notes.length - 1];
    pop.appendChild(creer('p', 'resume-evolution', notes.length + ' séance' + (notes.length > 1 ? 's' : '') + ' · moyenne ' + texteNote(moy)
      + ' · dernière ' + texteNote(der.v) + ' · ● calculée, ◉ corrigée à la main, A absent, ⚡ séance interrompue'));
    pop.hidden = false;
  }
  function dureeCourte(sec) {
    if (!sec) return '—';
    var h = Math.floor(sec / 3600), m = Math.round((sec % 3600) / 60);
    return h ? h + ' h ' + (m < 10 ? '0' : '') + m : m + ' min';
  }
  function dateCourte(ts) {
    if (!ts) return '—';
    var jours = Math.floor((Date.now() / 1000 - ts) / 86400), dt = new Date(ts * 1000);
    return jours <= 0 ? 'aujourd’hui' : jours === 1 ? 'hier' : jours < 7 ? 'il y a ' + jours + ' j' : (dt.getDate() < 10 ? '0' : '') + dt.getDate() + '/' + (dt.getMonth() < 9 ? '0' : '') + (dt.getMonth() + 1);
  }
  function feuilleSeance(dVoulu) {
    function pret(dv) {   // le plan voulu est affiché en 3D, et les noms locaux (admin.js, chargé après l'accueil) sont là
      return planAccueil && (!dv || planAccueil.d === dv) && planAccueil.svg._conteneurTrois && window.CentraleNoms && window.CentraleNoms.observation;
    }
    if (!pret(dVoulu)) {   // le plan de ce cours est en train de s'afficher : on l'attend
      if (!dVoulu && !planAccueil) return;
      var essais = 0;
      (function attendre() {
        if (pret(dVoulu)) feuilleSeance();
        else if (++essais < 300) setTimeout(attendre, 100);   // jusqu'à 30 s : au premier affichage, la vue 3D se charge d'abord
      })();
      return;
    }
    if (!planAccueil) return;
    var svg = planAccueil.svg, d = planAccueil.d, conteneur = svg._conteneurTrois, cn = window.CentraleNoms;
    if (!conteneur || !cn || !cn.observation) return;
    var s = seanceDe(d), fond = conteneur.querySelector('.feuille-seance');
    if (!fond) {
      fond = creer('div', 'fiche-trois bilan-obs feuille-seance');
      conteneur.appendChild(fond);
      fond.addEventListener('click', function (ev) { if (ev.target === fond) fond.hidden = true; });
      document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') fond.hidden = true; });
    }
    var defileAvant = fond.querySelector('.bilan-defile');
    var hautAvant = defileAvant ? defileAvant.scrollTop : 0;
    fond.replaceChildren();
    if (!fond._fermeMenus) {
      fond._fermeMenus = true;
      fond.addEventListener('click', function (ev) {
        if (!ev.target.closest('.feuille-niveaux, .feuille-com')) fond.querySelectorAll('.feuille-niveaux.ouvert, .feuille-com.ouvert').forEach(function (x) { x.classList.remove('ouvert'); });
        if (!ev.target.closest('.feuille-photo')) masquerPhotoGrande();
      });
    }
    /* Photo agrandie : posée sur la vignette (même bord gauche, centrée en hauteur), dans l'écran, au-dessus du tableau */
    var photoGrande = fond.querySelector('.feuille-photo-grande');
    function montrerPhotoGrande(ph, visage, nom) {
      if (!photoGrande) {
        photoGrande = creer('div', 'feuille-photo-grande');
        photoGrande.append(creer('div', 'fpg-image'), creer('div', 'fpg-nom'));
        fond.appendChild(photoGrande);
      }
      var L = 132, H = 160, r = ph.getBoundingClientRect();
      cn.styleVisage(photoGrande.firstChild, visage, L / visage.l, L, H);
      photoGrande.lastChild.textContent = nom;
      photoGrande._de = ph;
      photoGrande.hidden = false;
      var haut = Math.max(8, Math.min(window.innerHeight - H - 34, r.top + r.height / 2 - H / 2));
      photoGrande.style.left = Math.max(8, Math.min(window.innerWidth - L - 16, r.left - 4)) + 'px';
      photoGrande.style.top = haut + 'px';
    }
    function masquerPhotoGrande() { if (photoGrande) photoGrande.hidden = true; }
    var carteF = creer('div', 'fiche-trois-carte bilan-carte feuille-carte'), fermer = creer('button', 'fiche-trois-fermer', '×');
    fermer.type = 'button';
    fermer.addEventListener('click', function () { fond.hidden = true; });
    var choisis = cn.criteres().filter(function (k) { return CRITERES[k] && !CRITERES[k].caseACocher; });
    var criteres = ORDRE_FEUILLE.filter(function (k) { return choisis.indexOf(k) >= 0; })
      .concat(choisis.filter(function (k) { return ORDRE_FEUILLE.indexOf(k) < 0; }));
    var resume = creer('div', 'feuille-resume');

    // case en haut à gauche (au-dessus de « Élève ») : explication, légende des icônes, boutons Bilan et Critères…
    var outils = creer('div', 'feuille-outils'), bCrit = creer('button', 'petit secondaire', 'Critères…');
    bCrit.type = 'button';
    var reglage = creer('div', 'feuille-reglage');
    reglage.hidden = true;
    Object.keys(CRITERES).filter(function (k) { return !CRITERES[k].caseACocher; }).forEach(function (k) {
      var l = creer('label', 'case'), i = creer('input');
      i.type = 'checkbox';
      i.checked = choisis.indexOf(k) >= 0;
      i.addEventListener('change', function () {
        var liste = Object.keys(CRITERES).filter(function (x) { return !CRITERES[x].caseACocher && (x === k ? i.checked : cn.criteres().indexOf(x) >= 0); });
        if (!liste.length) { i.checked = true; return; }
        cn.definirCriteres(liste);
        feuilleSeance();
      });
      l.append(i, ' ' + CRITERES[k].libelle);
      reglage.appendChild(l);
    });
    bCrit.addEventListener('click', function () { reglage.hidden = !reglage.hidden; corpsL.hidden = true; });
    outils.append(bCrit, reglage);
    // légende : chaque icône de la feuille, et la règle de chaque repère de la plateforme
    // (fenêtre flottante par-dessus le tableau : ouverte, elle n'étire pas l'en-tête)
    var legende = creer('button', 'lien-bouton feuille-legende', 'ⓘ Légende des icônes'), corpsL = creer('div', 'legende-corps legende-pop');
    legende.type = 'button';
    corpsL.hidden = true;
    var fermerL = creer('button', 'fiche-trois-fermer', '×');
    fermerL.type = 'button';
    fermerL.addEventListener('click', function () { corpsL.hidden = true; });
    corpsL.appendChild(fermerL);
    legende.addEventListener('click', function (ev) { ev.stopPropagation(); corpsL.hidden = !corpsL.hidden; reglage.hidden = true; });
    corpsL.addEventListener('click', function (ev) { ev.stopPropagation(); });
    fond.addEventListener('click', function () { corpsL.hidden = true; });
    function ligneL(parent, ico, texte) { var li = creer('li'); if (ico) li.appendChild(ico); li.append(creer('span', '', texte)); parent.appendChild(li); }
    function cocheL(cls) { var c = creer('span', 'leg-case ' + cls); return c; }
    function pastilleL(v) { var c = creer('span', 'leg-niv ' + (v > 0 ? 'niv-plus' : v < 0 ? 'niv-moins' : 'niv-vide') + (Math.abs(v) > 1 ? ' fort' : ''), v ? signeNiveau(v) : '·'); return c; }
    corpsL.appendChild(creer('p', 'leg-titre leg-classe', 'En classe · noté par vous, enregistré sur la Centrale (par numéro)'));
    var ulC = creer('ul');
    ligneL(ulC, cocheL('leg-absent'), 'Absent (Abs.) : une information, pas un comportement');
    ligneL(ulC, cocheL('leg-retard'), 'En retard (Ret.) — l’un ou l’autre avec Absent');
    ligneL(ulC, cocheL('leg-oubli'), 'Oubli de matériel');
    [2, 1, 0].forEach(function (v) { ligneL(ulC, pastilleL(v), NIVEAUX[String(v)].libelle + (v === 0 ? ' (rien de noté)' : '')); });
    ['jaune', 'orange', 'rouge'].forEach(function (g) {   // « − » et « −− » : couleur selon la gravité du critère
      var deux = creer('span', 'leg-paire grav-' + g), a = pastilleL(-1), b = pastilleL(-2);
      deux.append(a, b);
      ligneL(ulC, deux, '− à revoir, −− gros souci · ' + GRAVITE[g].libelle);
    });
    ligneL(ulC, creer('span', 'leg-niv niv-vide avec-com', '·'), 'pastille C : un commentaire écrit pour ce critère (texte au survol)');
    var exL = cocheL('leg-exclusion'); exL.classList.add('avec-com');
    ligneL(ulC, exL, 'Exclusion de cours — pastille C : le motif est écrit (survol)');
    ligneL(ulC, creer('span', 'duo-bouton duo-dissipation', '💬2'), 'À séparer (Sép.) : se dissipe avec des camarades (chiffre : combien) — à ne pas placer ensemble');
    ligneL(ulC, creer('span', 'duo-bouton duo-altercation', '⚔️1'), 'À séparer : altercation avec un ou plusieurs camarades');
    corpsL.appendChild(ulC);
    corpsL.appendChild(creer('p', 'leg-titre leg-plateforme', 'Plateforme · calculé d’après l’activité dans les ateliers'));
    var ulP = creer('ul');
    var jaugeL = creer('span', 'mini-jauge'), jr = creer('i'); jr.style.width = '60%'; jaugeL.appendChild(jr);
    ligneL(ulP, jaugeL, 'Avancement : part des activités déjà abordées par la classe que l’élève a terminées');
    ligneL(ulP, creer('span', 'leg-txt', '2/4'), 'En cours : questions faites sur le total de l’activité en cours');
    ligneL(ulP, creer('span', 'leg-txt haut', '32'), 'en vert / en rouge : nettement au-dessus / au-dessous de la classe');
    Object.keys(REPERES).forEach(function (k) { ligneL(ulP, icone(REPERES[k]), REPERES[k].libelle + ' — ' + REPERES[k].regle); });
    corpsL.appendChild(ulP);
    // Interruption de cours (intervention, exercice incendie, confinement, autre) : pour toute la séance, notée sur « _classe »
    var bInt = creer('button', 'petit secondaire bouton-interruption'), popInt = creer('div', 'legende-pop interruption-pop');
    bInt.type = 'button';
    popInt.hidden = true;
    popInt.addEventListener('click', function (ev) { ev.stopPropagation(); });
    fond.addEventListener('click', function () { popInt.hidden = true; });
    function interruption() { var o = cn.observation(d.classe, '_classe', s.cle); return o.interruption ? ((o._c || {}).interruption || 'Interruption') : ''; }
    function majInterruption() {
      var t = interruption();
      bInt.textContent = t ? '⚡ Interrompu' : '⚡ Interruption';
      bInt.classList.toggle('actif', !!t);
      bInt.title = t ? 'Séance interrompue : ' + t : 'Signaler une interruption de cours (intervention, exercice incendie…)';
    }
    function remplirInterruption() {
      popInt.replaceChildren();
      var ferme = creer('button', 'fiche-trois-fermer', '×');
      ferme.type = 'button';
      ferme.addEventListener('click', function () { popInt.hidden = true; });
      var actuel = interruption(), motifActuel = actuel.split(' : ')[0], detail = creer('input', 'niv-com');
      detail.type = 'text';
      detail.maxLength = 200;
      detail.placeholder = 'Précision (facultatif) : durée, intervenant…';
      detail.value = actuel.indexOf(' : ') >= 0 ? actuel.slice(actuel.indexOf(' : ') + 3) : '';
      var motifs = creer('div', 'interruption-motifs');
      ['Intervention', 'Exercice incendie', 'Confinement', 'Autre'].forEach(function (m) {
        var b = creer('button', 'variante' + (m === motifActuel ? ' active' : ''), m);
        b.type = 'button';
        b.addEventListener('click', function () {
          cn.definirObservation(d.classe, '_classe', s.cle, 'interruption', -1);
          cn.definirCommentaire(d.classe, '_classe', s.cle, 'interruption', m + (detail.value.trim() ? ' : ' + detail.value.trim() : ''));
          majInterruption(); compter(); remplirInterruption();
        });
        motifs.appendChild(b);
      });
      detail.addEventListener('change', function () {
        var m = interruption().split(' : ')[0];
        if (m) { cn.definirCommentaire(d.classe, '_classe', s.cle, 'interruption', m + (detail.value.trim() ? ' : ' + detail.value.trim() : '')); majInterruption(); compter(); }
      });
      var retirer = creer('button', 'petit secondaire', 'Pas d’interruption');
      retirer.type = 'button';
      retirer.disabled = !actuel;
      retirer.addEventListener('click', function () {
        cn.definirObservation(d.classe, '_classe', s.cle, 'interruption', 0);
        cn.definirCommentaire(d.classe, '_classe', s.cle, 'interruption', '');
        majInterruption(); compter(); remplirInterruption();
      });
      popInt.append(ferme, creer('h4', '', '⚡ Interruption de cours'), creer('p', 'discret', 'Pour toute la séance : elle apparaît dans le résumé et sur l’évolution de la classe.'), motifs, detail, retirer);
    }
    bInt.addEventListener('click', function (ev) { ev.stopPropagation(); remplirInterruption(); popInt.hidden = !popInt.hidden; corpsL.hidden = true; reglage.hidden = true; });
    majInterruption();
    outils.insertBefore(bInt, outils.firstChild);
    var aide = creer('div', 'feuille-aide');
    aide.append(resume, legende, outils, creer('div', 'th-eleve', 'Élève'));
    // En tête et en bas : une grosse case « Feuille terminée » (statut de la feuille), puis l'atelier en cours de la classe
    var enTete = creer('div', 'feuille-entete'), casesTerm = [], piedResume = creer('div', 'feuille-pied-resume');
    function caseTerminee(bas) {
      var lab = creer('label', 'feuille-terminee' + (bas ? ' en-bas' : '')), c = creer('input');
      c.type = 'checkbox';
      c.addEventListener('change', function () {
        cn.definirObservation(d.classe, '_classe', s.cle, 'terminee', c.checked ? 1 : 0);
        majStatut();
        document.dispatchEvent(new Event('centrale-appel'));
      });
      lab.append(c, creer('span', '', 'Feuille terminée'));
      casesTerm.push(c);
      return lab;
    }
    function majStatut() {
      var fini = cn.observation(d.classe, '_classe', s.cle).terminee === 1;
      casesTerm.forEach(function (c) {
        c.checked = fini;
        c.parentNode.classList.toggle('cochee', fini);
        c.parentNode.title = fini ? 'Feuille terminée : décocher pour la rouvrir' : 'Cocher quand tout est noté pour ce cours (statut « terminée » à l’accueil)';
      });
      majWidgetFeuille();
    }
    var blocTitre = creer('div', 'feuille-titre'), sousTitre = creer('div', 'feuille-sous');
    sousTitre.append(creer('span', 'feuille-sous-classe', d.classe), creer('span', '', s.libelle),
      creer('span', 'discret', (d.eleves || []).filter(function (e) { return e.actif; }).length + ' élèves'));
    blocTitre.append(creer('h3', '', 'Feuille de séance'), sousTitre);
    var droite = creer('div', 'feuille-entete-droite');   // « Feuille terminée » et × côte à côte, centrés sur la même ligne
    fermer.classList.add('feuille-fermer');
    droite.append(caseTerminee(false), fermer);
    enTete.append(blocTitre, droite);
    // Atelier papier (FicheStudio) : présentation et correction projetées, reprise là où la classe s'est arrêtée
    var fi = d.fiche || {}, barreAt = creer('div', 'feuille-atelier');
    function lienFiche(texte, titre, params, principal) {
      var a = creer('a', 'btn petit' + (principal ? '' : ' secondaire'), texte);
      a.href = 'appli.php/fichestudio/#' + params + '&seance=' + encodeURIComponent(s.cle);   // même onglet : « Feuille d'appel » y ramène
      a.title = titre;
      return a;
    }
    var ligneP = creer('div', 'feuille-atelier-ligne');
    ligneP.appendChild(creer('span', 'feuille-atelier-label', 'Atelier papier'));
    if (fi.doc) {
      var po = fi.position, ou = po ? po.feuille + (po.total ? ', étape ' + po.etape + ' / ' + po.total : po.etape ? ', étape ' + po.etape : '') : '';
      var quandP = po && po.quand ? new Date(po.quand * 1000).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }) : '';
      ligneP.append(creer('strong', '', fi.doc), creer('span', 'discret', ou ? ' · ' + (po.fin_cours ? 'arrêt du ' + quandP : 'vu en dernier' + (quandP ? ' le ' + quandP : '')) + ' : ' + ou
        : fi.autre ? ' · commencé avec ' + (fi.autre.indexOf('g') > 0 ? 'l’autre groupe' : 'la classe entière') + ' : ce groupe part du début' : ' · pas encore commencé'));
      var cibleQ = 'presenter=' + encodeURIComponent(fi.fichier) + '&classe=' + encodeURIComponent(fi.cible);
      ligneP.appendChild(lienFiche('Correction en cours →', 'FicheStudio : ' + d.classe + ', ' + fi.doc + (ou ? ', correction reprise à ' + ou : ', correction depuis le début')
        + ' · « Feuille d’appel » y ramène ici', cibleQ + '&lancer=correction', true));
      if (!fi.present) ligneP.appendChild(creer('span', 'feuille-atelier-alerte', '⚠ document pas rangé sur la Centrale (☁ Enregistrer dans FicheStudio)'));
    } else {
      ligneP.append(creer('span', 'discret', 'aucun présenté à cette classe dans FicheStudio pour l’instant'),
        lienFiche('Choisir dans FicheStudio →', 'FicheStudio, espace Présentation, avec cette classe choisie : ouvrez le document à présenter (☁ Ouvrir…)', 'classe=' + encodeURIComponent(fi.cible || ('c' + d.classe_id)), false));
    }
    /** Une ligne de la barre : libellé | ce qui est en cours | bouton, alignés en colonnes d'une ligne à l'autre. */
    function ranger(ligne) {
      var corps = creer('div', 'feuille-atelier-corps'), act = creer('div', 'feuille-atelier-actions');
      [].slice.call(ligne.childNodes).forEach(function (n) {
        if (n.classList && n.classList.contains('feuille-atelier-label')) return;
        (n.tagName === 'A' ? act : corps).appendChild(n);
      });
      ligne.append(corps, act);
      return ligne;
    }
    barreAt.appendChild(ranger(ligneP));
    // Sur la plateforme : ce sur quoi les élèves travaillent (pour information)
    var at = (d.stats_classe || {}).atelier;
    if (at) {
      var ligneA = creer('div', 'feuille-atelier-ligne');
      ligneA.append(creer('span', 'feuille-atelier-label', 'Sur la plateforme'), creer('strong', '', at.titre), creer('span', 'discret', ' · ' + (at.app_nom || at.app)
        + (at.source === 'eleves' ? ' · ' + at.nb + ' élève' + (at.nb > 1 ? 's' : '') + ' dessus' + (at.sur ? ' sur ' + at.sur : '') : ' · ouvert pour la classe')));
      barreAt.appendChild(ranger(ligneA));
    }
    carteF.append(enTete, barreAt, corpsL, popInt);

    var defile = creer('div', 'bilan-defile'), table = creer('table', 'feuille-table');
    var thead = creer('thead'), groupes = creer('tr', 'feuille-groupes'), tete = creer('tr');
    var gClasse = creer('th', 'groupe-classe', 'En classe · noté par vous'), gPlat = creer('th', 'groupe-plateforme', 'Plateforme · activité dans les ateliers');
    gClasse.colSpan = 4 + criteres.length + 2;   // Abs., Ret., Excl., Oubli, les critères, À séparer, Note
    gPlat.colSpan = 7;                            // Avancement, En cours, Term., 1er coup, Temps, Dern., Rep.
    var coin = creer('th', 'col-nom feuille-coin');   // explication, légende, boutons, puis « Élève » : sur les deux lignes d'en-tête
    coin.rowSpan = 2;
    coin.appendChild(aide);
    groupes.append(coin, gClasse, gPlat);
    // En-têtes de colonnes étroites : le nom en grand, à la verticale, et en petit, à l'horizontale, en dessous ;
    // le détail au survol.
    function entete(cls, court, vertical, detail) {
      var th = creer('th', cls);
      if (vertical) {
        th.appendChild(creer('div', 'th-v', vertical));
        th.appendChild(creer('div', 'th-h', court));
      } else {
        th.textContent = court;
      }
      th.title = detail || vertical || court;
      tete.appendChild(th);
    }
    entete('col-case', 'Abs.', 'Absent');
    entete('col-case', 'Ret.', 'En retard');
    entete('col-case col-excl', 'Excl.', 'Exclusion de cours', 'Exclusion de cours (avec un commentaire facultatif) : l’élève passe « hors de la classe » pour aujourd’hui');
    entete('col-case', 'Oubli', 'Oubli de matériel');
    criteres.forEach(function (k) {
      entete('col-niveau', CRITERES[k].court || CRITERES[k].lettre, CRITERES[k].libelle, CRITERES[k].libelle + ' : de −− à ++, cartons et commentaires (survol : résumé ; clic : évaluer)');
    });
    entete('col-duo', 'Sép.', 'À séparer', 'Dissipation ou altercation entre élèves (deux ou plus) : à ne pas placer ensemble (clic pour noter ou voir)');
    entete('col-note', 'Note', 'Note de la séance', 'Note de la séance : moyenne des critères (non noté = comme d’habitude, oubli = −1), corrigeable à la main avec un commentaire');
    var sc = d.stats_classe || {};
    entete('col-plat', 'Avanc.', 'Avancement', 'Part des activités déjà abordées par la classe (' + (sc.programme || 0) + ') que l’élève a terminées (médiane : '
      + (sc.avancement != null ? Math.round(sc.avancement) + ' %' : '—') + ')');
    entete('col-plat', 'En cours', 'En cours', 'Activité en cours (la plus récente) : questions faites sur le total');
    entete('col-plat', 'Term.', 'Activités terminées', 'Activités terminées (médiane de la classe : ' + (sc.terminees != null ? Math.round(sc.terminees) : '—') + ')');
    entete('col-plat', '1er coup', 'Réussite 1er coup', 'Réussite du premier coup (médiane : ' + (sc.premier_coup != null ? Math.round(sc.premier_coup) + ' %' : '—') + ')');
    entete('col-plat', 'Temps', 'Temps actif', 'Temps de travail effectif dans les ateliers (médiane : ' + dureeCourte(sc.temps) + ')');
    entete('col-plat', 'Dern.', 'Dernière activité', 'Dernière activité ou connexion');
    entete('col-plat', 'Rep.', 'Repères', 'Repères de la plateforme (détail au survol)');
    thead.append(groupes, tete);
    table.appendChild(thead);
    var corps = creer('tbody');
    function compter() {
      var a = 0, r = 0, o = 0, x = 0, p = 0, m = 0;
      (d.eleves || []).forEach(function (e) {
        var pr = cn.presence(d.classe, e.numero, s.cle), ob = cn.observation(d.classe, e.numero, s.cle);
        if (pr === 'absent') a++; else if (pr === 'retard') r++;
        if (ob.oubli) o++;
        if (ob.exclusion) x++;
        criteres.forEach(function (k) { if (ob[k] > 0) p++; else if (ob[k] < 0) m++; });
      });
      var pl = function (n, mot) { return n + ' ' + mot + (n > 1 ? 's' : ''); };
      resume.replaceChildren(creer('div', 'resume-titre', 'Résumé de la séance'),
        creer('div', '', pl(a, 'absent') + ' · ' + pl(r, 'retard')),
        creer('div', '', pl(x, 'exclusion') + ' · ' + pl(o, 'oubli') + ' de matériel'),
        creer('div', '', p + ' + · ' + m + ' − notés'));
      var it = typeof interruption === 'function' ? interruption() : '';
      if (it) resume.appendChild(creer('div', 'resume-interruption', '⚡ Interrompue : ' + it));
      // en bas : le même résumé sur une ligne, avec la note de la classe
      var nc = noteDeLaClasse(d, s.cle), chipN = creer('span', 'widget-note ' + classeNote(nc ? nc.v : null), nc ? texteNote(nc.v) : '—');
      piedResume.replaceChildren(creer('span', 'pied-chiffres', pl(a, 'absent') + ' · ' + pl(r, 'retard') + ' · ' + pl(x, 'exclusion') + ' · ' + pl(o, 'oubli') + ' · ' + p + ' + · ' + m + ' −'),
        chipN);
      if (it) piedResume.appendChild(creer('span', 'resume-interruption', '⚡ ' + it));
      if (typeof majRangeeClasse === 'function') majRangeeClasse();
      majStatut();
      document.dispatchEvent(new Event('centrale-appel'));
    }
    function niveauChip(bt, v) {   // la pastille résumée : · (rien), +, ++, −, −−
      bt.textContent = v ? signeNiveau(v) : '·';
      bt.className = 'niv-courant ' + (v > 0 ? 'niv-plus' : v < 0 ? 'niv-moins' : 'niv-vide') + (Math.abs(v) > 1 ? ' fort' : '');
    }
    var presents = (d.eleves || []).filter(function (e) { return e.actif; });
    /** Séances de la classe : toutes celles où quelque chose a été noté (élèves ou classe), plus la séance affichée. */
    function seancesClasse() {
      var cles = {};
      cles[s.cle] = true;
      presents.forEach(function (e) { Object.keys(cn.observationsEleve(d.classe, e.numero)).forEach(function (k) { cles[k] = true; }); });
      Object.keys(cn.observationsEleve(d.classe, '_classe')).forEach(function (k) { cles[k] = true; });
      return Object.keys(cles).sort();
    }
    function noteEleveA(e, cle) {   // note affichée d'un élève pour une séance (null s'il était absent)
      if (cn.presence(d.classe, e.numero, cle) === 'absent') return null;
      return noteSeance(cn.observation(d.classe, e.numero, cle), criteres);
    }
    function noteClasseCalculee(cle) {
      var vs = presents.map(function (e) { return noteEleveA(e, cle); }).filter(function (n) { return n; });
      return vs.length ? vs.reduce(function (a, n) { return a + n.v; }, 0) / vs.length : null;
    }
    function interrompue(cle) { var o = cn.observation(d.classe, '_classe', cle); return o.interruption ? ((o._c || {}).interruption || 'interruption') : ''; }
    function evolutionEleve(e, nomE) {
      var pts = seancesClasse().map(function (cle) {
        var n = noteEleveA(e, cle);
        if (!n) return { cle: cle, v: null };
        var ob = cn.observation(d.classe, e.numero, cle), det = criteres.filter(function (k) { return ob[k]; }).map(function (k) { return (CRITERES[k].court || k) + ' ' + signeNiveau(ob[k]); });
        if (ob.oubli) det.push('oubli de matériel');
        if (ob.exclusion) det.push('exclu du cours' + ((ob._c || {}).exclusion ? ' (' + ob._c.exclusion + ')' : ''));
        if ((ob._c || {}).note) det.push('« ' + ob._c.note + ' »');
        if (interrompue(cle)) det.push('⚡ ' + interrompue(cle));
        return { cle: cle, v: n.v, auto: n.auto, detail: det.join(' · '), marque: interrompue(cle) ? '⚡' : '' };
      });
      graphiqueEvolution(carteF, 'Évolution · ' + nomE, 'Note de chaque séance de ' + d.classe + ' (calculée d’après les critères, ou corrigée).', pts);
    }
    function evolutionClasse() {
      var pts = seancesClasse().map(function (cle) {
        var ob = cn.observation(d.classe, '_classe', cle), calc = noteClasseCalculee(cle);
        var manuel = typeof ob.note === 'number' ? ob.note : null;
        var v = manuel != null ? manuel : calc;
        return { cle: cle, v: v, auto: manuel == null, marque: interrompue(cle) ? '⚡' : '',
                 detail: 'moyenne des élèves : ' + texteNote(calc) + ((ob._c || {}).note ? ' · « ' + ob._c.note + ' »' : '') + (interrompue(cle) ? ' · ⚡ ' + interrompue(cle) : '') };
      });
      graphiqueEvolution(carteF, 'Évolution · classe ' + d.classe, 'Note de la classe à chaque séance : moyenne des élèves présents, ou corrigée.', pts);
    }

    /**
     * Cellule « Note » : la note calculée (ou corrigée, ✎) ; au survol, la même bulle que les critères — −− à ++ pour
     * corriger, « Auto » pour revenir au calcul, et un commentaire facultatif.
     */
    function celluleNote(tr, numero, nomE, calculer, estAbsent) {
      var td = creer('td', 'col-note'), boite = creer('span', 'feuille-niveaux note-boite'), chip = creer('button', 'note-courante'),
          palette = creer('span', 'niv-palette'), boutons = creer('span', 'niv-boutons'), auto = creer('button', 'note-auto'), comN = creer('input', 'niv-com');
      chip.type = auto.type = 'button';
      comN.type = 'text';
      comN.maxLength = 300;
      comN.placeholder = 'Préciser la note (facultatif)';
      comN.value = (cn.observation(d.classe, numero, s.cle)._c || {}).note || '';
      function maj() {
        var ob = cn.observation(d.classe, numero, s.cle), abs = estAbsent && estAbsent(), calc = calculer();
        var manuel = typeof ob.note === 'number' ? ob.note : null, v = abs ? null : (manuel != null ? manuel : calc);
        chip.textContent = abs ? 'abs.' : texteNote(v) + (manuel != null ? ' ✎' : '');
        chip.className = 'note-courante ' + classeNote(v) + (manuel != null ? ' corrigee' : '') + (comN.value.trim() ? ' avec-com' : '');
        chip.title = nomE + ' · note de la séance : ' + (abs ? 'absent' : texteNote(v) + (manuel != null ? ' (corrigée ; calculée : ' + texteNote(calc) + ')' : ' (calculée)'))
          + (comN.value.trim() ? ' · « ' + comN.value.trim() + ' »' : '');
        boutons.querySelectorAll('button').forEach(function (b2, i) { b2.setAttribute('aria-pressed', String(manuel === [-2, -1, 0, 1, 2][i])); });
        auto.textContent = 'Auto (' + texteNote(calc) + ')';
        auto.disabled = manuel == null;
      }
      chip.addEventListener('click', function () {
        var ouvrir = !boite.classList.contains('ouvert');
        fond.querySelectorAll('.feuille-niveaux.ouvert, .feuille-com.ouvert').forEach(function (x) { x.classList.remove('ouvert'); });
        boite.classList.toggle('ouvert', ouvrir);
      });
      [-2, -1, 0, 1, 2].forEach(function (v) {
        var bt = creer('button', 'niv niv-' + (v > 0 ? 'plus' : v < 0 ? 'moins' : 'neutre') + (Math.abs(v) > 1 ? ' fort' : ''), v === 0 ? '=' : signeNiveau(v));
        bt.type = 'button';
        bt.title = 'Corriger la note : ' + NIVEAUX[String(v)].libelle;
        bt.addEventListener('click', function () {
          var ob = cn.observation(d.classe, numero, s.cle);
          cn.definirObservation(d.classe, numero, s.cle, 'note', ob.note === v ? null : v);   // re-cliquer : retour au calcul
          bt.blur();
          maj();
          compter();
        });
        boutons.appendChild(bt);
      });
      auto.addEventListener('click', function () { cn.definirObservation(d.classe, numero, s.cle, 'note', null); auto.blur(); maj(); compter(); });
      comN.addEventListener('change', function () { comN.value = cn.definirCommentaire(d.classe, numero, s.cle, 'note', comN.value) || ''; maj(); });
      comN.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === 'Escape') { ev.stopPropagation(); comN.blur(); boite.classList.remove('ouvert'); } });
      palette.append(creer('span', 'niv-titre', 'Note de la séance · ' + nomE), boutons, auto, comN);
      boite.append(chip, palette);
      td.appendChild(boite);
      tr.appendChild(td);
      maj();
      return maj;
    }

    /** Détail de la plateforme pour un élève (clic sur une case de la partie Plateforme) : fenêtre par-dessus la feuille. */
    function detailPlateforme(e, nomE, st) {
      var pop = carteF.querySelector('.detail-pop');
      if (!pop) {
        pop = creer('div', 'legende-pop detail-pop');
        pop.addEventListener('click', function (ev) { ev.stopPropagation(); });
        fond.addEventListener('click', function () { pop.hidden = true; });
        carteF.appendChild(pop);
      }
      pop.replaceChildren();
      var ferme = creer('button', 'fiche-trois-fermer', '×');
      ferme.type = 'button';
      ferme.addEventListener('click', function () { pop.hidden = true; });
      pop.append(ferme, creer('h4', '', 'Plateforme · ' + nomE), creer('p', 'discret', 'Activité dans les ateliers, comparée à la classe ' + d.classe + '.'));
      var dl = creer('dl', 'detail-plateforme');
      function ligne(t, v, cmp) { dl.append(creer('dt', '', t), creer('dd', '', v + (cmp ? ' · classe : ' + cmp : ''))); }
      ligne('Avancement', st.avancement != null ? st.avancement + ' % (' + st.terminees + ' terminées sur ' + (sc.programme || 0) + ' activités abordées par la classe)' : '—',
        sc.avancement != null ? Math.round(sc.avancement) + ' %' : '');
      var ec = st.en_cours;
      ligne('En cours', ec ? ec.titre + ' (' + ec.app + ')' + (ec.total ? ' : ' + (ec.faites || 0) + ' sur ' + ec.total : '') + ' · ' + dateCourte(ec.maj) : 'aucune activité en cours', '');
      ligne('Activités', (st.terminees || 0) + ' terminées sur ' + (st.commencees || 0) + ' commencées', sc.terminees != null ? Math.round(sc.terminees) + ' terminées' : '');
      ligne('Réussite du 1er coup', st.premier_coup != null ? st.premier_coup + ' %' : '—', sc.premier_coup != null ? Math.round(sc.premier_coup) + ' %' : '');
      ligne('Temps actif', dureeCourte(st.temps), sc.temps ? dureeCourte(sc.temps) : '');
      ligne('Dernière activité', dateCourte(st.derniere) + (st.jours != null ? ' · ' + st.jours + ' jour' + (st.jours > 1 ? 's' : '') + ' de connexion en 8 semaines' : ''), sc.jours != null ? Math.round(sc.jours) + ' jours' : '');
      pop.appendChild(dl);
      var reps = (e.reperes || []).filter(function (r) { return REPERES[r.code]; });
      pop.appendChild(creer('p', 'leg-titre leg-plateforme', 'Repères'));
      var ul = creer('ul', 'detail-reperes');
      if (!reps.length) ul.appendChild(creer('li', 'discret', 'Rien de particulier.'));
      reps.forEach(function (r) {
        var li = creer('li'), def = REPERES[r.code];
        li.append(icone(def), creer('span', '', ''));
        li.lastChild.append(creer('strong', '', def.libelle), ' · ' + r.texte, creer('br'), creer('span', 'discret', 'Règle : ' + def.regle));
        ul.appendChild(li);
      });
      pop.appendChild(ul);
      pop.hidden = false;
    }

    /* ---------- Évaluation d'un critère pour un élève ----------
       Survol de la pastille : une bulle résumé (niveau, commentaires). Clic : une fenêtre avec tout — les cinq niveaux,
       les cartons de chaque niveau (−− · − · + · ++), les commentaires de la séance (ajout ↵, liste, ×). */
    var bulleEval = null, fenEval = null, ctxEval = null, avecNoms = formateurNoms(d), cellulesEval = {};   // « n° 07 » affiché avec le nom
    function etatEval(ctx) {
      var o = cn.observation(d.classe, ctx.e.numero, s.cle);
      return { v: o[ctx.k] || 0, coms: decouperCommentaires((o._c || {})[ctx.k]) };
    }
    function pastilleNiveau(k, v) {   // la pastille du niveau, aux couleurs du critère
      var boite = creer('span', 'feuille-niveaux grav-' + (CRITERES[k].gravite || 'orange')), p = creer('span');
      niveauChip(p, v);
      boite.appendChild(p);
      return boite;
    }
    function montrerBulleEval(ctx, chip) {
      if (fenEval && !fenEval.hidden) return;
      if (!bulleEval) { bulleEval = creer('div', 'eval-bulle'); fond.appendChild(bulleEval); }
      var st = etatEval(ctx), tete = creer('div', 'eval-bulle-tete'), niv = creer('div', 'eval-bulle-niveau');
      tete.append(creer('strong', '', CRITERES[ctx.k].libelle), creer('span', 'discret', ' · ' + ctx.nomE));
      niv.append(pastilleNiveau(ctx.k, st.v), creer('span', '', NIVEAUX[String(st.v)].libelle));
      bulleEval.replaceChildren(tete, niv);
      if (st.coms.length) {
        var ul = creer('ul', 'eval-bulle-coms');
        st.coms.slice(-5).forEach(function (t) { ul.appendChild(creer('li', '', entreeLisible(t, avecNoms))); });
        if (st.coms.length > 5) ul.appendChild(creer('li', 'discret', '… et ' + (st.coms.length - 5) + ' de plus'));
        bulleEval.appendChild(ul);
      } else bulleEval.appendChild(creer('p', 'discret eval-bulle-vide', 'Aucun commentaire'));
      bulleEval.appendChild(creer('p', 'eval-bulle-aide', 'Clic : évaluer'));
      bulleEval.hidden = false;
      var r = chip.getBoundingClientRect(), L = 260, H = bulleEval.offsetHeight;
      bulleEval.style.left = Math.max(8, Math.min(window.innerWidth - L - 8, r.left + r.width / 2 - L / 2)) + 'px';
      bulleEval.style.top = (r.bottom + 8 + H > window.innerHeight - 8 ? Math.max(8, r.top - 8 - H) : r.bottom + 8) + 'px';
    }
    function masquerBulleEval() { if (bulleEval) bulleEval.hidden = true; }
    function fermerEval() { if (fenEval) fenEval.hidden = true; ctxEval = null; }
    function ouvrirEval(ctx) {
      masquerBulleEval();
      ctxEval = ctx;
      if (!fenEval) {
        fenEval = creer('div', 'eval-fond');
        fenEval.addEventListener('click', function (ev) { ev.stopPropagation(); if (ev.target === fenEval) fermerEval(); });
        fond.appendChild(fenEval);
        document.addEventListener('keydown', function (ev) {   // Échap : ferme la fenêtre d'évaluation (pas la feuille)
          if (ev.key === 'Escape' && fenEval && !fenEval.hidden) { ev.stopImmediatePropagation(); fermerEval(); }
        }, true);
      }
      var k = ctx.k, e = ctx.e, m = MOTIFS_CRITERES[k] || {};
      var carte = creer('div', 'eval-carte grav-' + (CRITERES[k].gravite || 'orange'));
      // en-tête : photo, élève, critère, séance
      var tete = creer('div', 'eval-tete'), ph = creer('span', 'eval-photo'), visage = photo(d.classe, e.numero), titres = creer('div', 'eval-titres');
      if (visage && cn.styleVisage) cn.styleVisage(ph, visage, 46 / visage.l, 46, 56);
      else ph.textContent = String(ctx.nomE).replace(/[^A-Za-zÀ-ÿ]/g, '').slice(0, 1);
      var ligneCrit = creer('div', 'eval-critere');
      ligneCrit.append(creer('strong', '', CRITERES[k].libelle), creer('span', 'discret', ' · ' + d.classe + ' · ' + s.libelle));
      titres.append(creer('div', 'eval-nom', ctx.nomE + ' · n° ' + e.numero), ligneCrit);
      var fermer = creer('button', 'fiche-trois-fermer eval-fermer', '×');
      fermer.type = 'button';
      fermer.title = 'Fermer (Échap)';
      fermer.addEventListener('click', fermerEval);
      tete.append(ph, titres, fermer);
      // niveaux, avec au-dessus de chacun son paquet de cartons (replié ; déplié vers le haut au survol, au toucher sur tablette)
      var tactile = window.matchMedia && window.matchMedia('(hover: none)').matches;
      var moyenne = creer('div', 'eval-moyenne');   // au-dessus de « = » : la moyenne des cartons
      var grille = creer('div', 'eval-grille'), btNiv = {}, CLES = { '-2': ['moinsFort', 'Gros souci'], '-1': ['moins', 'À revoir'], '1': ['plus', 'Bien'], '2': ['plusFort', 'Très bien'] };
      function carteMotif(motif, v) {
        var b = creer('button', 'motif carton-pre ' + (v < 0 ? 'carton-pre-observation' : 'carton-pre-recompense') + (Math.abs(v) > 1 ? ' fort' : '') + (MOTIFS_AVEC[motif] ? ' avec' : ''), motif);
        b.type = 'button';
        b.title = motif + ' — le critère passe à « ' + signeNiveau(v) + ' », le motif s’ajoute aux commentaires' + (MOTIFS_AVEC[motif] ? ' ; vous choisissez avec qui' : '');
        b.addEventListener('click', function (ev) {
          ev.stopPropagation();
          grille.querySelectorAll('.eval-pile.ouverte').forEach(function (x) { x.classList.remove('ouverte'); });
          function appliquer(texte) {   // le carton, avec son heure et son niveau ; le critère prend la moyenne des cartons
            var garde = cn.definirCommentaire(d.classe, e.numero, s.cle, k, ajouterEntree((cn.observation(d.classe, e.numero, s.cle)._c || {})[k], ecrireEntree(v, texte)));
            coms.maj();
            var moy = niveauMoyen(garde);
            choisir(moy ? moy.niveau : v);
          }
          var type = MOTIFS_AVEC[motif];
          if (!type) { appliquer(motif); return; }
          choisirCamarades(fond, d, e.numero, motif, type, function (nums) {   // avec qui ? (numéros) ; dissipation, altercation : « À séparer »
            if (MOTIFS_POUR_TOUS[motif]) {   // bavardage, bonne dynamique : les camarades reçoivent aussi le carton (leur pastille et leur note suivent)
              repercuterCarton(d.classe, e.numero, nums, s.cle, k, v, motif, function (n, niveau) {
                var c = (cellulesEval[n] || {})[k];
                if (c) { c.majChip(niveau); if (c.tr._majNote) c.tr._majNote(); }
              });
            }
            appliquer(motifAvec(motif, nums));
            if (nums.length && TYPES_DUO[type]) { cn.ajouterIncident(d.classe, [String(e.numero)].concat(nums), type, s.cle, motif); rafraichirDuos(); }
          });
        });
        return b;
      }
      [-2, -1, 0, 1, 2].forEach(function (v) {
        var col = creer('div', 'eval-colonne'), cle = CLES[String(v)], liste = cle ? (m[cle[0]] || []) : [];
        var pile = creer('div', 'eval-pile ' + (v < 0 ? 'pile-moins' : v > 0 ? 'pile-plus' : 'pile-vide') + (Math.abs(v) > 1 ? ' fort' : ''));
        if (liste.length) {
          // replié : le premier carton (texte sur une ligne) et le nombre ; déplié : tous les cartons, en entier
          var dessus = carteMotif(liste[0], v), suite = creer('div', 'eval-pile-suite'), nb = creer('span', 'eval-pile-nombre', String(liste.length));
          dessus.classList.add('eval-pile-dessus');
          if (tactile) {   // tablette : le premier toucher déplie le paquet (un second toucher choisit un carton)
            dessus.addEventListener('click', function (ev) {
              if (pile.classList.contains('ouverte')) return;
              ev.stopImmediatePropagation();
              grille.querySelectorAll('.eval-pile.ouverte').forEach(function (x) { x.classList.remove('ouverte'); });
              pile.classList.add('ouverte');
              if (pile._ajuster) pile._ajuster();
            }, true);
          }
          suite.appendChild(creer('span', 'eval-pile-titre', signeNiveau(v) + ' ' + cle[1]));
          liste.forEach(function (motif) { suite.appendChild(carteMotif(motif, v)); });
          nb.title = liste.length + ' carton' + (liste.length > 1 ? 's' : '') + ' « ' + cle[1] + ' »';
          pile.append(dessus, nb, suite);
          // la liste dépliée tient dans la fenêtre : sa hauteur suit la place qui reste sous le paquet, et elle défile
          var ajuster = function () {
            var bas = carte.getBoundingClientRect().bottom, haut = pile.getBoundingClientRect().top;
            suite.style.maxHeight = Math.max(150, Math.min(340, bas - haut - 6)) + 'px';
            requestAnimationFrame(ombre);
          };
          var ombre = function () { suite.classList.toggle('a-suivre', suite.scrollTop + suite.clientHeight < suite.scrollHeight - 4); };   // d'autres cartons plus bas
          suite.addEventListener('scroll', ombre);
          pile._ajuster = ajuster;
          pile.addEventListener('mouseenter', ajuster);
          pile.addEventListener('focusin', ajuster);
        }
        if (v === 0) pile.appendChild(moyenne);
        var b = creer('button', 'eval-niv ' + (v > 0 ? 'eval-plus' : v < 0 ? 'eval-moins' : 'eval-neutre') + (Math.abs(v) > 1 ? ' fort' : ''));
        b.type = 'button';
        b.append(creer('span', 'eval-signe', v === 0 ? '=' : signeNiveau(v)), creer('span', 'eval-lib', NIVEAUX[String(v)].libelle));
        b.addEventListener('click', function () { var st = etatEval(ctx); choisir(st.v === v ? 0 : v); });
        btNiv[v] = b;
        col.append(pile, b);
        grille.appendChild(col);
      });
      carte.addEventListener('click', function (ev) {   // toucher ailleurs : les paquets se replient
        if (!ev.target.closest('.eval-pile')) grille.querySelectorAll('.eval-pile.ouverte').forEach(function (x) { x.classList.remove('ouverte'); });
      });
      // commentaires de la séance
      var coms = zoneCommentaires(function () { return (cn.observation(d.classe, e.numero, s.cle)._c || {})[k] || ''; },
        function (t) { return cn.definirCommentaire(d.classe, e.numero, s.cle, k, t) || ''; }, apres, 'Ajouter un commentaire', true, avecNoms,
        function () { var moy = niveauMoyen((cn.observation(d.classe, e.numero, s.cle)._c || {})[k]); choisir(moy ? moy.niveau : 0); },
        { d: d, num: e.numero, seance: s.cle, k: k, conteneur: function () { return fond; },
          apresEleve: function (n, niveau) { var c = (cellulesEval[n] || {})[k]; if (c) { c.majChip(niveau); if (c.tr._majNote) c.tr._majNote(); } if (typeof rafraichirDuos === 'function') rafraichirDuos(); } });
      coms.classList.add('eval-coms');
      function section(titre, contenu) { var sec = creer('section', 'eval-section'); sec.append(creer('h4', 'eval-section-titre', titre), contenu); return sec; }
      carte.append(tete, grille, section('Commentaires de la séance', coms));
      fenEval.replaceChildren(carte);
      fenEval.hidden = false;
      function majNiveaux() {
        var v = etatEval(ctx).v, moy = niveauMoyen((cn.observation(d.classe, e.numero, s.cle)._c || {})[k]);
        Object.keys(btNiv).forEach(function (x) { btNiv[x].setAttribute('aria-pressed', String(+x === v)); });
        moyenne.replaceChildren();
        if (moy) {
          moyenne.append(creer('span', 'eval-moyenne-valeur', texteNote(moy.moyenne)), creer('span', 'eval-moyenne-lib', 'moyenne de ' + moy.n + ' carton' + (moy.n > 1 ? 's' : '')));
          if (moy.niveau !== v) moyenne.appendChild(creer('span', 'eval-moyenne-main', 'niveau choisi à la main'));
        } else moyenne.appendChild(creer('span', 'eval-moyenne-lib', 'aucun carton'));
        moyenne.title = moy ? 'Niveau du critère : moyenne des cartons de la séance (' + texteNote(moy.moyenne) + ')' : 'Pas encore de carton : choisissez un niveau, ou un carton';
      }
      function apres() { var v = etatEval(ctx).v; ctx.majChip(v); if (ctx.tr._majNote) ctx.tr._majNote(); compter(); }
      function choisir(v) { cn.definirObservation(d.classe, e.numero, s.cle, k, v); majNiveaux(); apres(); }
      majNiveaux();
    }

    // À séparer (dissipation, altercation, à deux ou plus) : un panneau pour noter avec qui, et les incidents déjà notés
    var majDuos = [], parNumero = {};
    (d.eleves || []).forEach(function (e) { parNumero[String(e.numero)] = e; });
    function nomDe(num) { var e = parNumero[String(num)]; return cn.nomLocal(d.classe, num) || (e && e.simule) || ('n° ' + num); }
    function rafraichirDuos() { majDuos.forEach(function (f) { f(); }); majStatut(); document.dispatchEvent(new Event('centrale-appel')); }
    /** Un sélecteur d'élèves (pastilles) : choix[numero] = true ; « exclus » : numéros à ne pas proposer. */
    function choixEleves(choix, exclus, auChoix) {
      var boite = creer('div', 'duo-camarades');
      presents.filter(function (x) { return exclus.indexOf(String(x.numero)) < 0; })
        .sort(function (u, v) { return String(u.numero).localeCompare(String(v.numero), 'fr', { numeric: true }); })
        .forEach(function (x) {
          var b = creer('button', 'duo-camarade');
          b.type = 'button';
          b.append(creer('span', 'numero', x.numero), ' ' + nomDe(x.numero));
          b.setAttribute('aria-pressed', 'false');
          b.addEventListener('click', function () {
            choix[x.numero] = !choix[x.numero];
            b.setAttribute('aria-pressed', String(!!choix[x.numero]));
            if (auChoix) auChoix(String(x.numero));
          });
          boite.appendChild(b);
        });
      return boite;
    }
    /** Panneau des dissipations et altercations : pour un élève (eleve), ou pour toute la classe (null). */
    function ouvrirDuos(eleve) {
      var pop = carteF.querySelector('.duo-pop');
      if (!pop) {
        pop = creer('div', 'legende-pop detail-pop duo-pop');
        pop.addEventListener('click', function (ev) { ev.stopPropagation(); });
        fond.addEventListener('click', function () { pop.hidden = true; });
        carteF.appendChild(pop);
      }
      var defilePop = pop.scrollTop;
      pop.replaceChildren();
      var ferme = creer('button', 'fiche-trois-fermer', '×');
      ferme.type = 'button';
      ferme.addEventListener('click', function () { pop.hidden = true; });
      pop.appendChild(ferme);
      var num = eleve ? String(eleve.numero) : null, nomE = eleve ? nomDe(num) : '';
      function rouvrir() { rafraichirDuos(); ouvrirDuos(eleve); }
      pop.append(creer('h4', '', eleve ? 'Entre élèves · ' + nomE : 'Entre élèves · classe ' + d.classe),
        creer('p', 'discret', eleve ? 'Nouvel incident : avec qui ? un ou plusieurs camarades, puis le type.'
          : 'Nouvel incident : choisissez au moins deux élèves, puis le type. Le plan de classe signale ceux qui sont assis ensemble et le placement au hasard les sépare.'));
      // nouvel incident
      var choix = {}, type = 'dissipation';
      var texte = creer('input', 'niv-com duo-texte'), noter = creer('button', 'petit duo-noter', 'Noter');
      function retenus() { return Object.keys(choix).filter(function (k) { return choix[k]; }); }
      function majNoter() { noter.disabled = retenus().length < (eleve ? 1 : 2); }
      pop.appendChild(choixEleves(choix, num ? [num] : [], majNoter));
      var types = creer('div', 'duo-types'), btTypes = {};
      Object.keys(TYPES_DUO).forEach(function (t) {
        var b = creer('button', 'duo-type duo-' + t, TYPES_DUO[t].icone + ' ' + TYPES_DUO[t].libelle);
        b.type = 'button';
        b.title = TYPES_DUO[t].texte;
        b.setAttribute('aria-pressed', String(t === type));
        b.addEventListener('click', function () { type = t; Object.keys(btTypes).forEach(function (k) { btTypes[k].setAttribute('aria-pressed', String(k === t)); }); });
        btTypes[t] = b;
        types.appendChild(b);
      });
      texte.type = 'text';
      texte.maxLength = 200;
      texte.placeholder = 'Préciser (facultatif)';
      noter.type = 'button';
      majNoter();
      noter.addEventListener('click', function () {
        cn.ajouterIncident(d.classe, (num ? [num] : []).concat(retenus()), type, s.cle, texte.value);
        rouvrir();
      });
      texte.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && !noter.disabled) noter.click(); });
      var ligneN = creer('div', 'duo-ligne');
      ligneN.append(texte, noter);
      pop.append(types, ligneN);
      // incidents déjà notés (de l'élève, ou de la classe) : retirer un élève, en ajouter, supprimer
      var liste = cn.incidents(d.classe).filter(function (x) { return !num || x.membres.indexOf(num) >= 0; })
        .sort(function (u, v) { return String(v.seance).localeCompare(String(u.seance)); });
      pop.appendChild(creer('p', 'leg-titre', 'Déjà noté (' + liste.length + ')'));
      var ul = creer('ul', 'duo-liste');
      if (!liste.length) ul.appendChild(creer('li', 'discret', 'Rien pour l’instant.'));
      liste.forEach(function (x) {
        var li = creer('li', 'duo-' + x.type), tete = creer('div', 'duo-incident-tete'), membres = creer('div', 'duo-membres');
        var sup = creer('button', 'duo-suppr', '✕');
        sup.type = 'button';
        sup.title = 'Supprimer cet incident';
        sup.addEventListener('click', function () { if (confirmerEnLigne(sup, 'Supprimer ?')) { cn.supprimerIncident(d.classe, x.id); rouvrir(); } });
        tete.append(creer('strong', '', TYPES_DUO[x.type].icone + ' ' + TYPES_DUO[x.type].libelle), creer('span', 'discret', ' · ' + jourSeance(x.seance) + ' · ' + x.membres.length + ' élèves'), sup);
        x.membres.slice().sort(function (u, v) { return u.localeCompare(v, 'fr', { numeric: true }); }).forEach(function (m) {
          var puce = creer('span', 'duo-membre' + (m === num ? ' moi' : ''));
          puce.append(creer('span', 'numero', m), ' ' + nomDe(m));
          if (m !== num) {
            var r = creer('button', 'duo-retirer', '×');
            r.type = 'button';
            r.title = 'Retirer ' + nomDe(m) + ' de cet incident' + (x.membres.length <= 2 ? ' (l’incident disparaît)' : '');
            r.addEventListener('click', function () { cn.modifierIncident(d.classe, x.id, { retirer: m }); rouvrir(); });
            puce.appendChild(r);
          }
          membres.appendChild(puce);
        });
        var plus = creer('button', 'duo-ajouter', '＋ ajouter');
        plus.type = 'button';
        plus.title = 'Ajouter des élèves à cet incident';
        membres.appendChild(plus);
        li.append(tete, membres);
        if (x.texte) li.appendChild(creer('div', 'discret duo-precision', '« ' + x.texte + ' »'));
        plus.addEventListener('click', function () {   // les autres élèves : un clic les ajoute aussitôt
          var ouvert = li.querySelector('.duo-camarades');
          if (ouvert) { ouvert.remove(); return; }
          var aj = {};
          li.appendChild(choixEleves(aj, x.membres, function (m) { if (aj[m]) { cn.modifierIncident(d.classe, x.id, { ajouter: [m] }); rouvrir(); } }));
        });
        ul.appendChild(li);
      });
      pop.appendChild(ul);
      pop.hidden = false;
      pop.scrollTop = defilePop;
    }

    // Rangée « Classe » (en tête) : effectifs, moyenne de chaque critère, note de la classe (corrigeable), médianes de la plateforme
    var trC = creer('tr', 'rangee-classe'), celC = {};
    var tdCN = creer('td', 'col-nom'), blocC = creer('div', 'feuille-eleve rangee-titre');
    blocC.append(creer('span', 'feuille-photo icone-classe', '👥'), creer('span', 'feuille-nom', 'Classe'), creer('span', 'numero', 'séance'));
    blocC.title = 'Classe ' + d.classe + ' : clic pour l’évolution de toutes les séances';
    blocC.addEventListener('click', evolutionClasse);
    tdCN.appendChild(blocC);
    trC.appendChild(tdCN);
    ['absent', 'retard', 'exclusion', 'oubli'].forEach(function (k) { celC[k] = creer('td', 'col-case compte-classe' + (k === 'exclusion' ? ' col-excl' : '')); trC.appendChild(celC[k]); });
    criteres.forEach(function (k) { celC[k] = creer('td', 'col-niveau moyenne-classe'); trC.appendChild(celC[k]); });
    var tdDC = creer('td', 'col-duo'), bDC = creer('button', 'duo-bouton');
    bDC.type = 'button';
    bDC.addEventListener('click', function (ev) { ev.stopPropagation(); ouvrirDuos(null); });
    tdDC.appendChild(bDC);
    trC.appendChild(tdDC);
    majDuos.push(function () {
      var tous = cn.incidents(d.classe), l = tous.filter(function (x) { return x.type !== 'entente'; }), ent = tous.length - l.length;
      var alt = l.some(function (x) { return x.type === 'altercation'; });
      bDC.textContent = l.length ? (alt ? '⚔️' : '💬') + l.length : ent ? '🤝' + ent : '＋';
      bDC.className = 'duo-bouton' + (l.length ? (alt ? ' duo-altercation' : ' duo-dissipation') : ent ? ' duo-entente' : '');
      bDC.title = tous.length ? (l.length ? l.length + ' incident' + (l.length > 1 ? 's' : '') + ' à séparer' : '') + (l.length && ent ? ', ' : '')
        + (ent ? ent + ' bonne' + (ent > 1 ? 's' : '') + ' dynamique' + (ent > 1 ? 's' : '') : '') + ' dans la classe : clic pour la liste, ou en noter un'
        : 'Noter une dissipation, une altercation ou une bonne dynamique entre élèves';
    });
    var majNoteClasse = celluleNote(trC, '_classe', 'classe ' + d.classe, function () { return noteClasseCalculee(s.cle); }, null);
    [[sc.avancement != null ? Math.round(sc.avancement) + ' %' : '—', 'Avancement médian'], ['', ''], [sc.terminees != null ? String(Math.round(sc.terminees)) : '—', 'Activités terminées : médiane'],
     [sc.premier_coup != null ? Math.round(sc.premier_coup) + ' %' : '—', 'Réussite du 1er coup : médiane'], [dureeCourte(sc.temps), 'Temps actif : médiane'], ['', ''], ['', '']].forEach(function (x) {
      var td = creer('td', 'col-plat', x[0]);
      if (x[1]) td.title = x[1] + ' de la classe';
      trC.appendChild(td);
    });
    function majRangeeClasse() {
      var a = 0, r = 0, o = 0, x = 0, somme = {}, nb = 0;
      criteres.forEach(function (k) { somme[k] = 0; });
      presents.forEach(function (e) {
        var pr = cn.presence(d.classe, e.numero, s.cle), ob = cn.observation(d.classe, e.numero, s.cle);
        if (pr === 'absent') { a++; return; }
        if (pr === 'retard') r++;
        if (ob.oubli) o++;
        if (ob.exclusion) x++;
        nb++;
        criteres.forEach(function (k) { somme[k] += ob[k] || 0; });
      });
      celC.absent.textContent = a || '';
      celC.retard.textContent = r || '';
      celC.oubli.textContent = o || '';
      celC.exclusion.textContent = x || '';
      criteres.forEach(function (k) {
        var m = nb ? somme[k] / nb : 0;
        celC[k].textContent = texteNote(m);
        celC[k].className = 'col-niveau moyenne-classe ' + classeNote(m);
        celC[k].title = CRITERES[k].libelle + ' : moyenne des ' + nb + ' élèves présents';
      });
      majNoteClasse();
    }
    corps.appendChild(trC);

    presents.slice().sort(function (a, b) { return String(a.numero).localeCompare(String(b.numero), 'fr', { numeric: true }); }).forEach(function (e) {
      var tr = creer('tr'), ob = cn.observation(d.classe, e.numero, s.cle), pr = cn.presence(d.classe, e.numero, s.cle);
      var nomE = cn.nomLocal(d.classe, e.numero) || e.simule || ('n° ' + e.numero);
      // élève : petite photo (grande au survol), numéro, nom
      var tdNom = creer('td', 'col-nom'), blocNomE = creer('div', 'feuille-eleve'), ph = creer('span', 'feuille-photo'), visage = photo(d.classe, e.numero);
      if (visage && cn.styleVisage) cn.styleVisage(ph, visage, 28 / visage.l, 28, 34);
      else ph.textContent = String(nomE).replace(/[^A-Za-zÀ-ÿ]/g, '').slice(0, 1);
      if (visage) {   // agrandie au survol (ou au toucher) : une photo flottante posée sur la vignette, jamais coupée
        ph.addEventListener('mouseenter', function () { montrerPhotoGrande(ph, visage, nomE); });
        ph.addEventListener('mouseleave', masquerPhotoGrande);
        ph.addEventListener('click', function () { if (photoGrande && photoGrande._de === ph && !photoGrande.hidden) masquerPhotoGrande(); else montrerPhotoGrande(ph, visage, nomE); });
      }
      var lienEvo = creer('span', 'lien-evolution');
      lienEvo.append(creer('span', 'numero', e.numero), creer('span', 'feuille-nom', nomE));
      lienEvo.title = nomE + ' : clic pour l’évolution de toutes les séances';
      lienEvo.addEventListener('click', function () { evolutionEleve(e, nomE); });
      blocNomE.append(ph, lienEvo);
      tdNom.appendChild(blocNomE);
      tr.appendChild(tdNom);
      // absent / en retard : l'un ou l'autre
      var cases = {};
      ['absent', 'retard'].forEach(function (etat) {
        var td = creer('td', 'col-case'), c = creer('input');
        c.type = 'checkbox';
        c.className = 'case-' + etat;
        c.checked = pr === etat;
        c.title = (etat === 'absent' ? 'Absent' : 'En retard') + ' · ' + nomE;
        c.addEventListener('change', function () {
          if (c.checked) cases[etat === 'absent' ? 'retard' : 'absent'].checked = false;
          cn.definirPresence(d.classe, e.numero, s.cle, c.checked ? etat : '');
          tr.classList.toggle('absent', cases.absent.checked);
          if (tr._majNote) tr._majNote();
          compter();
        });
        cases[etat] = c;
        td.appendChild(c);
        tr.appendChild(td);
      });
      tr.classList.toggle('absent', pr === 'absent');
      // exclusion de cours : case, et un commentaire facultatif (bulle) ; l'élève passe « hors de la classe » aujourd'hui
      var tdX = creer('td', 'col-case col-excl col-exclusion'), cx = creer('input'), boiteX = creer('span', 'feuille-niveaux excl-niveaux'),
          pointX = creer('span', 'excl-point', 'C'), paletteX = creer('span', 'niv-palette excl-palette'), comX = creer('input', 'niv-com');
      cx.type = 'checkbox';
      cx.className = 'case-exclusion';
      cx.checked = ob.exclusion === -1;
      comX.type = 'text';
      comX.maxLength = 300;
      comX.placeholder = 'Motif (facultatif)';
      comX.value = (ob._c || {}).exclusion || '';
      function majX() {   // pastille « C » s'il y a un motif ; motif au survol, comme les autres critères
        var t = comX.value.trim();
        boiteX.classList.toggle('coche', cx.checked);
        pointX.hidden = !(cx.checked && t);
        cx.title = 'Exclusion de cours · ' + nomE + (cx.checked ? (t ? ' : « ' + t + ' »' : ' : survolez pour préciser le motif') : '');
        tr.classList.toggle('exclu', cx.checked);
      }
      cx.addEventListener('change', function () {
        cn.definirObservation(d.classe, e.numero, s.cle, 'exclusion', cx.checked ? -1 : 0);
        if (cn.definirStatut && cn.dateLocale && seanceDe(d).cle.slice(0, 10) === cn.dateLocale()) cn.definirStatut(d.classe, e.numero, 'exclu_cours', cx.checked ? cn.dateLocale() : '');
        majX();
        fond.querySelectorAll('.feuille-niveaux.ouvert').forEach(function (z) { z.classList.remove('ouvert'); });
        if (cx.checked) { boiteX.classList.add('ouvert'); setTimeout(function () { comX.focus(); }, 0); }
        if (tr._majNote) tr._majNote();
        compter();
      });
      pointX.addEventListener('click', function () {   // toucher : rouvrir le motif
        var ouvrir = !boiteX.classList.contains('ouvert');
        fond.querySelectorAll('.feuille-niveaux.ouvert').forEach(function (z) { z.classList.remove('ouvert'); });
        boiteX.classList.toggle('ouvert', ouvrir);
      });
      comX.addEventListener('change', function () { comX.value = cn.definirCommentaire(d.classe, e.numero, s.cle, 'exclusion', comX.value) || ''; majX(); });
      comX.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === 'Escape') { ev.stopPropagation(); comX.value = cn.definirCommentaire(d.classe, e.numero, s.cle, 'exclusion', comX.value) || ''; majX(); comX.blur(); boiteX.classList.remove('ouvert'); }
      });
      paletteX.append(creer('span', 'niv-titre', 'Exclusion · ' + nomE), comX);
      boiteX.append(cx, pointX, paletteX);
      tdX.appendChild(boiteX);
      tr.appendChild(tdX);
      majX();
      // oubli de matériel
      var tdO = creer('td', 'col-case'), co = creer('input');
      co.type = 'checkbox';
      co.className = 'case-oubli';
      co.checked = ob.oubli === -1;
      co.title = 'Oubli de matériel · ' + nomE;
      co.addEventListener('change', function () { cn.definirObservation(d.classe, e.numero, s.cle, 'oubli', co.checked ? -1 : 0); if (tr._majNote) tr._majNote(); compter(); });
      tdO.appendChild(co);
      tr.appendChild(tdO);
      // critères : pastille résumée ; au survol, une bulle résumé ; au clic, la fenêtre d'évaluation (niveaux, cartons, commentaires)
      criteres.forEach(function (k) {
        var td = creer('td', 'col-niveau'), boite = creer('span', 'feuille-niveaux grav-' + (CRITERES[k].gravite || 'orange')), chip = creer('button');
        chip.type = 'button';
        function majChip(v) {   // pastille : niveau, « C » s'il y a des commentaires
          niveauChip(chip, v);
          chip.classList.toggle('avec-com', decouperCommentaires((cn.observation(d.classe, e.numero, s.cle)._c || {})[k]).length > 0);
          chip.setAttribute('aria-label', CRITERES[k].libelle + ' · ' + nomE + ' : ' + NIVEAUX[String(v)].libelle + ' — cliquer pour évaluer');
        }
        majChip(ob[k] || 0);
        var ctx = { e: e, k: k, nomE: nomE, tr: tr, majChip: majChip };
        (cellulesEval[String(e.numero)] = cellulesEval[String(e.numero)] || {})[k] = ctx;   // pour les cartons répercutés (bavardage avec…)
        chip.addEventListener('mouseenter', function () { montrerBulleEval(ctx, chip); });
        chip.addEventListener('mouseleave', masquerBulleEval);
        chip.addEventListener('click', function (ev) { ev.stopPropagation(); ouvrirEval(ctx); });
        boite.appendChild(chip);
        td.appendChild(boite);
        tr.appendChild(td);
      });
      // duo : avec qui l'élève se dissipe ou s'est disputé (toutes séances) ; clic pour noter
      var tdD = creer('td', 'col-duo'), bD = creer('button', 'duo-bouton');
      bD.type = 'button';
      bD.addEventListener('click', function (ev) { ev.stopPropagation(); ouvrirDuos(e); });
      tdD.appendChild(bD);
      tr.appendChild(tdD);
      majDuos.push(function () {
        var num = String(e.numero), l = pairesDuos(d.classe).filter(function (p) { return p.a === num || p.b === num; });
        var alt = l.some(function (p) { return p.altercation; });
        var ent = liensClasse(d.classe).filter(function (p) { return p.entente && (p.a === num || p.b === num); });
        bD.textContent = l.length ? (alt ? '⚔️' : '💬') + l.length : ent.length ? '🤝' + ent.length : '·';
        bD.className = 'duo-bouton' + (l.length ? (alt ? ' duo-altercation' : ' duo-dissipation') : ent.length ? ' duo-entente' : '');
        bD.title = (l.length ? 'À séparer de : ' + l.map(function (p) { return nomDe(p.a === num ? p.b : p.a) + ' (' + resumePaire(p) + ')'; }).join(', ') : '')
          + (l.length && ent.length ? ' · ' : '')
          + (ent.length ? 'Bonne dynamique avec : ' + ent.map(function (p) { return nomDe(p.a === num ? p.b : p.a); }).join(', ') : '')
          || nomE + ' : clic pour noter une dissipation, une altercation ou une bonne dynamique avec un ou plusieurs camarades';
      });
      // note de la séance (calculée, corrigeable)
      tr._majNote = celluleNote(tr, e.numero, nomE, function () { return noteCalculee(cn.observation(d.classe, e.numero, s.cle), criteres); },
        function () { return cn.presence(d.classe, e.numero, s.cle) === 'absent'; });
      // plateforme : chiffres comparés à la médiane de la classe
      var st = e.stats || {};
      function cellule(texte, titre, etat) {
        var td = creer('td', 'col-plat' + (etat ? ' ' + etat : ''), texte);
        if (titre) td.title = titre;
        tr.appendChild(td);
      }
      var medT = sc.terminees, medP = sc.premier_coup, medA = sc.avancement;
      // avancement : une jauge et le pourcentage ; en cours : faites / total de l'activité la plus récente
      var tdA = creer('td', 'col-plat col-avancement' + (medA >= 10 && st.avancement >= medA * 1.4 ? ' haut' : medA >= 10 && st.avancement <= medA * 0.6 ? ' bas' : ''));
      if (st.avancement != null) {
        var jauge = creer('span', 'mini-jauge'), rempli = creer('i');
        rempli.style.width = Math.min(100, st.avancement) + '%';
        jauge.appendChild(rempli);
        tdA.append(jauge, creer('span', '', st.avancement + ' %'));
        tdA.title = st.terminees + ' terminées sur ' + (sc.programme || 0) + ' activités abordées par la classe' + (medA != null ? ' · médiane : ' + Math.round(medA) + ' %' : '');
      } else tdA.textContent = '—';
      tr.appendChild(tdA);
      var ec = st.en_cours;
      cellule(ec ? (ec.total ? (ec.faites || 0) + '/' + ec.total : '…') : '—', ec ? ec.titre + ' (' + ec.app + ')' + (ec.total ? ' : ' + (ec.faites || 0) + ' sur ' + ec.total : '') + ' · ' + dateCourte(ec.maj) : 'Aucune activité en cours', ec ? 'col-encours' : '');
      cellule(st.terminees != null ? String(st.terminees) : '—', (st.commencees || 0) + ' commencées' + (medT != null ? ' · médiane de la classe : ' + Math.round(medT) : ''),
        medT >= 2 && st.terminees >= medT * 1.5 ? 'haut' : medT >= 2 && st.terminees <= medT * 0.6 ? 'bas' : '');
      cellule(st.premier_coup != null ? st.premier_coup + ' %' : '—', medP != null ? 'médiane de la classe : ' + Math.round(medP) + ' %' : '',
        st.premier_coup != null && st.premier_coup < 40 ? 'bas' : st.premier_coup != null && st.premier_coup >= 80 ? 'haut' : '');
      cellule(dureeCourte(st.temps), sc.temps ? 'médiane de la classe : ' + dureeCourte(sc.temps) : '', '');
      var vieux = st.derniere && (Date.now() / 1000 - st.derniere) > 14 * 86400;
      cellule(dateCourte(st.derniere), st.jours != null ? st.jours + ' jour' + (st.jours > 1 ? 's' : '') + ' de connexion en 8 semaines' : '', !st.derniere || vieux ? 'bas' : '');
      var tdR = creer('td', 'col-plat col-reperes');
      (e.reperes || []).forEach(function (r) {
        var def = REPERES[r.code];
        if (!def) return;
        var ic = icone(def);
        ic.title = def.libelle + ' · ' + r.texte;
        tdR.appendChild(ic);
      });
      tr.appendChild(tdR);
      tr.querySelectorAll('td.col-plat').forEach(function (td) {   // clic : le détail de la plateforme
        td.classList.add('cliquable');
        td.addEventListener('click', function (ev) { ev.stopPropagation(); detailPlateforme(e, nomE, st); });
      });
      corps.appendChild(tr);
    });
    table.appendChild(corps);
    defile.appendChild(table);
    carteF.appendChild(defile);
    var pied = creer('div', 'feuille-pied');
    pied.append(piedResume, caseTerminee(true));
    carteF.appendChild(pied);
    fond.appendChild(carteF);
    fond.hidden = false;
    defile.scrollTop = hautAvant;
    rafraichirDuos();
    compter();
  }



  /*
   * Deux sources qu'on ne mélange jamais :
   *  - OBSERVÉ EN CLASSE (CLASSE) : ce que l'enseignant a noté (synchronisé avec la Centrale) — appel de la séance, absences ou
   *    retards répétés à l'appel, cartons d'observation et récompenses. Ronds et cartons.
   *  - SUR LA PLATEFORME (REPERES) : ce que la Centrale calcule d'après l'activité dans les ateliers (lib/reperes.php),
   *    en comparant l'élève à sa classe. Carrés arrondis.
   * Sur le plan, les deux groupes sont séparés par un trait ; dans la fiche, ce sont deux parties.
   */
  var CLASSE = {
    absent:         { forme: 'rond', glyphe: 'A', couleur: '#5b6b8c', libelle: 'Absent à ce cours (appel)' },
    retard:         { forme: 'rond', glyphe: 'R', couleur: '#e0a800', texte: '#2b2000', libelle: 'En retard à ce cours (appel)' },
    'cl-absences':  { forme: 'rond', glyphe: 'A+', couleur: '#3d4a66', libelle: 'Absences répétées (3 ou plus à l’appel)' },
    'cl-retards':   { forme: 'rond', glyphe: 'R+', couleur: '#a87b00', libelle: 'Retards répétés (2 ou plus à l’appel)' },
    'cl-exclu':     { forme: 'rond', glyphe: '⇥', couleur: '#c0392b', libelle: 'Exclu (du cours aujourd’hui, ou de l’établissement)' },
    'cl-dehors':    { forme: 'rond', glyphe: '⇥', couleur: '#5d6d7e', libelle: 'Sorti de l’établissement (retiré)' },
    observation:    { forme: 'carton', glyphe: '', couleur: '#f2b705', libelle: 'Observation (carton)' },
    recompense:     { forme: 'medaille', glyphe: '', couleur: '#2e8b57', libelle: 'Récompense' },
  };
  var REPERES = {
    avance:       { forme: 'carre', glyphe: '▲', couleur: '#2e8b57', libelle: 'En avance sur la classe', regle: 'au moins 1,5 fois la médiane de la classe en activités terminées' },
    retard:       { forme: 'carre', glyphe: '▼', couleur: '#e67e22', libelle: 'En retard dans le travail', regle: 'au plus 60 % de la médiane de la classe en activités terminées' },
    difficulte:   { forme: 'carre', glyphe: '!', couleur: '#c0392b', libelle: 'En difficulté (essais, 1er coup)', regle: 'moins de 40 % de réussite du 1er coup, ou 3 essais par question ou plus' },
    corrections:  { forme: 'carre', glyphe: '?', couleur: '#2f6fb5', libelle: 'Regarde souvent les corrections', regle: 'affiche souvent les corrections (5 au moins, et 30 % des questions)' },
    retardataire: { forme: 'carre', glyphe: '⏱', couleur: '#d35400', libelle: 'Se connecte après son groupe', regle: 'se connecte en général 8 min ou plus après son groupe' },
    absences:     { forme: 'carre', glyphe: '∅', couleur: '#8e44ad', libelle: 'Peu de connexions', regle: 'deux fois moins de jours de connexion que la classe (8 dernières semaines)' },
    decroche:     { forme: 'carre', glyphe: 'Z', couleur: '#5d6d7e', libelle: 'Plus d’activité (décroché)', regle: 'plus aucune activité depuis 2 semaines alors que la classe travaille' },
    jamais:       { forme: 'carre', glyphe: '–', couleur: '#95a5a6', libelle: 'Jamais connecté', regle: 'ne s’est jamais connecté' },
  };
  /** Sur la plateforme : repères calculés par la Centrale (rien de ce qui est noté en classe). */
  function reperesEleve(d, e) { return (e.reperes || []).slice(); }
  /**
   * Élève « hors de la classe » (statuts synchronisés avec la Centrale) : exclu du cours aujourd'hui (il garde sa place),
   * exclu ou sorti de l'établissement. Renvoie { motif, date, texte } ou null.
   */
  var MOTIFS_DEHORS = { exclu_cours: 'Exclu du cours', exclu: 'Exclu de l’établissement', sortie: 'Sorti de l’établissement' };
  function dehors(classe, numero) {
    var cn = window.CentraleNoms, st = cn && cn.statuts ? cn.statuts(classe, numero) : {};
    var auj = cn && cn.dateLocale ? cn.dateLocale() : new Date().toISOString().slice(0, 10);
    var jour = function (dt) { return dt.split('-').reverse().join('/'); };
    if (st.sortie) return { motif: 'sortie', date: st.sortie, texte: MOTIFS_DEHORS.sortie + ' le ' + jour(st.sortie) };
    if (st.exclu) return { motif: 'exclu', date: st.exclu, texte: MOTIFS_DEHORS.exclu + ' depuis le ' + jour(st.exclu) };
    if (st.exclu_cours === auj) return { motif: 'exclu_cours', date: auj, texte: MOTIFS_DEHORS.exclu_cours + ' aujourd’hui' };
    return null;
  }
  /** Observé en classe, répété : absences (3 ou plus) et retards (2 ou plus) notés à l'appel. */
  function observationsClasse(d, e) {
    var cn = window.CentraleNoms, res = [];
    var dh = dehors(d.classe, e.numero);
    if (dh) res.push({ code: dh.motif === 'sortie' ? 'cl-dehors' : 'cl-exclu', texte: dh.texte });   // exclusion : rouge, comme dans la feuille
    if (!cn || !cn.absences) return res;
    var a = cn.absences(d.classe, e.numero), nA = a.filter(function (x) { return x.etat === 'absent'; }).length, nR = a.length - nA;
    if (nA >= 3) res.push({ code: 'cl-absences', texte: nA + ' absences notées à l’appel' });
    if (nR >= 2) res.push({ code: 'cl-retards', texte: nR + ' retards notés à l’appel' });
    return res;
  }
  function reperesVisibles() { return !document.documentElement.classList.contains('sans-reperes'); }
  function classeVisible() { return !document.documentElement.classList.contains('sans-classe'); }
  /** Pastille de légende ou de fiche : même forme et même couleur que sur le plan. */
  function icone(def) {
    var ico = creer('span', 'repere-ico forme-' + (def.forme || 'carre'), def.glyphe || '');
    ico.style.background = def.couleur;
    if (def.texte) ico.style.color = def.texte;
    return ico;
  }
  /**
   * Statut de la feuille de séance d'un cours, d'après ce qui est noté (synchronisé avec la Centrale) :
   * terminee (marquée par l'enseignant), commencee (quelque chose est noté), a-remplir (cours commencé ou passé, rien de noté),
   * a-venir. null si les noms ne sont pas encore chargés (admin.js).
   */
  function statutFeuille(classe, cle, debut, fin) {
    var cn = window.CentraleNoms;
    if (!cn || !cn.etatSeance) return null;
    var e = cn.etatSeance(classe, cle), n = new Date();
    var maintenant = cn.dateLocale() + ' ' + ('0' + n.getHours()).slice(-2) + ':' + ('0' + n.getMinutes()).slice(-2);
    var jour = String(cle).slice(0, 10), debutCle = debut ? jour + ' ' + debut : cle, finCle = fin ? jour + ' ' + fin : jour + ' 23:59';
    var quoi = [e.eleves ? e.eleves + ' élève' + (e.eleves > 1 ? 's' : '') + ' noté' + (e.eleves > 1 ? 's' : '') : '', e.incidents ? e.incidents + ' incident' + (e.incidents > 1 ? 's' : '') : '',
      e.interruption ? 'interruption' : ''].filter(Boolean).join(', ');
    if (e.terminee) return { code: 'terminee', court: '✓ terminée', libelle: 'terminée' + (quoi ? ' (' + quoi + ')' : '') };
    if (e.eleves || e.incidents || e.classe) return { code: 'commencee', court: '✎ commencée', libelle: 'commencée' + (quoi ? ' (' + quoi + ')' : '') + ', pas encore marquée terminée' };
    if (maintenant < debutCle) return { code: 'a-venir', court: 'à venir', libelle: 'à venir' };
    if (maintenant <= finCle) return { code: 'a-remplir en-cours', court: '● à remplir', libelle: 'cours en ce moment, rien de noté' };
    return { code: 'a-remplir', court: '! à remplir', libelle: 'rien de noté pour ce cours' };
  }
  /** Critères notés dans la feuille, dans l'ordre des colonnes. */
  function criteresFeuille() {
    var cn = window.CentraleNoms, choisis = cn.criteres().filter(function (k) { return CRITERES[k] && !CRITERES[k].caseACocher; });
    return ORDRE_FEUILLE.filter(function (k) { return choisis.indexOf(k) >= 0; }).concat(choisis.filter(function (k) { return ORDRE_FEUILLE.indexOf(k) < 0; }));
  }
  /** Note de la classe pour une séance (comme la rangée « Classe ») : corrigée à la main, sinon moyenne des présents ;
      null si rien n'est noté. */
  function noteDeLaClasse(d, cle) {
    var cn = window.CentraleNoms;
    if (!cn || !cn.observation || !cn.etatSeance) return null;
    var oc = cn.observation(d.classe, '_classe', cle);
    if (typeof oc.note === 'number') return { v: oc.note, auto: false };
    if (!cn.etatSeance(d.classe, cle).eleves) return null;
    var crit = criteresFeuille(), vs = (d.eleves || []).filter(function (e) { return e.actif && cn.presence(d.classe, e.numero, cle) !== 'absent'; })
      .map(function (e) { return noteSeance(cn.observation(d.classe, e.numero, cle), crit).v; });
    return vs.length ? { v: vs.reduce(function (a, b) { return a + b; }, 0) / vs.length, auto: true } : null;
  }
  /** Le widget « Feuille de séance » de l'accueil : la classe, le cours, le statut de sa feuille et la note de la classe. */
  function majWidgetFeuille() {
    var el = document.getElementById('feuille-statut');
    if (!el || !planAccueil) return;
    var d = planAccueil.d, s = seanceDe(d), st = statutFeuille(d.classe, s.cle, s.cle.slice(11, 16), ''), n = noteDeLaClasse(d, s.cle);
    var stat = creer('span', 'widget-statut' + (st ? ' statut-' + st.code : ''), st ? st.court : '');
    var note = creer('span', 'widget-note ' + classeNote(n ? n.v : null), n ? texteNote(n.v) : '—');
    note.title = n ? 'Note de la classe pour ce cours' + (n.auto ? ' (moyenne des élèves présents)' : ' (corrigée)') : 'Pas encore de note pour ce cours';
    el.replaceChildren(creer('span', 'widget-cours', d.classe + ' · ' + s.libelle.replace(/^cours de /, '')), stat, note);
    el.className = 'widget-feuille-etat';
  }

  /** Aperçu en lecture seule d'un plan placé (accueil) : dessine la salle + les élèves posés. */
  /* ======================================================================
     Imprimer plusieurs plans de classe sur une feuille (./?p=imprimer_plans, lib/admin_organisation.php)
     ====================================================================== */

  /**
   * Plan à plat pour l'impression : la salle dans son propre sens (portrait, miroirs) ; pour chaque élève, sa photo (si elle est
   * chargée sur cet ordinateur) et son nom sur deux lignes, aussi grands que possible SANS SE CHEVAUCHER : chaque élève a une case
   * autour de sa place, qui s'arrête à mi-chemin de chaque voisin, du côté (horizontal ou vertical) où il y a le plus de place.
   */
  function dessinerImprimable(svg, d, avecNumero, avecPhotos) {
    var carte = d.placement || {};
    var vueSalle = Object.assign({}, (d.plan && d.plan.vue) || {}, { iso: false });
    var p = Object.assign({}, d.plan, { vue: vueSalle });
    function eleve(id) { return (d.eleves || []).find(function (e) { return e.id === id; }); }
    dessinerPlan(svg, p, {});
    svg.style.aspectRatio = '';
    var tr = transformation(p);
    var MAXL = 2.6, MAXH = 3.2, JEU = 0.12;   // case la plus grande (en carreaux) et petit écart entre deux cases
    var places = p.objets.filter(function (o) { return estPlace(o) && carte[o.id] && eleve(carte[o.id]); }).map(function (o) {
      var r = tr.rect(o.x, o.y, largeurVue(o), hauteurVue(o));
      // étendue de la case de chaque côté de la place (gauche, droite, haut, bas), en carreaux
      return { o: o, e: eleve(carte[o.id]), cx: r.x + r.w / 2, cy: r.y + r.h / 2, g: MAXL / 2, d: MAXL / 2, h: MAXH / 2, b: MAXH / 2 };
    });
    // chaque paire de voisins se sépare à mi-chemin, sur l'axe où ils sont le plus loin l'un de l'autre (rapporté à la taille
    // de case) : un côté libre (allée) garde toute son étendue
    places.forEach(function (a, i) {
      places.forEach(function (b, j) {
        if (j <= i) return;
        var dx = Math.abs(a.cx - b.cx), dy = Math.abs(a.cy - b.cy);
        if (dx >= MAXL || dy >= MAXH) return;   // trop loin : les deux cases ne peuvent pas se toucher
        var g = a.cx <= b.cx ? a : b, dr = g === a ? b : a, ha = a.cy <= b.cy ? a : b, ba = ha === a ? b : a;
        if (dx / MAXL >= dy / MAXH) { g.d = Math.min(g.d, dx / 2); dr.g = Math.min(dr.g, dx / 2); }
        else { ha.b = Math.min(ha.b, dy / 2); ba.h = Math.min(ba.h, dy / 2); }
      });
    });
    var calque = el('g', { class: 'calque-imprime' }, svg);
    // Disposition de chaque case : photo au-dessus du nom (case haute), ou à gauche du nom (case large et basse : deux places
    // l'une au-dessus de l'autre). Les photos sont ramenées à la taille médiane du plan, pour un rendu régulier.
    places.forEach(function (pl) {
      pl.L = (pl.g + pl.d - JEU) * U; pl.H = (pl.h + pl.b - JEU) * U;
      pl.X0 = (pl.cx - pl.g + JEU / 2) * U; pl.Y0 = (pl.cy - pl.h + JEU / 2) * U;
      pl.MX = pl.X0 + pl.L / 2; pl.MY = pl.Y0 + pl.H / 2;   // milieu de la case (pas forcément la place : un côté libre l'élargit)
      pl.cote = pl.L >= 1.6 * pl.H;   // nom à côté de la photo
      pl.D = pl.cote ? Math.min(pl.H, pl.L * 0.42) : Math.max(0, Math.min(pl.L, pl.H - Math.max(0.45 * U, Math.min(0.8 * U, pl.H * 0.38))));
    });
    var tailles = places.map(function (pl) { return pl.D; }).sort(function (x, y) { return x - y; });
    var mediane = tailles.length ? tailles[Math.floor(tailles.length / 2)] : 0;
    places.forEach(function (pl) {
      var D = Math.min(pl.D, mediane);
      var l = pl.L, h = pl.H, x0 = pl.X0, y0 = pl.Y0;
      var g = el('g', { class: 'case-eleve', 'data-boite': [x0, y0, l, h].map(function (v) { return v.toFixed(1); }).join(' ') }, calque);
      var visage = avecPhotos ? photo(d.classe, pl.e.numero) : null;
      if (!visage) D = 0;
      var n = window.CentraleNoms ? window.CentraleNoms.nomLocal(d.classe, pl.e.numero) : '';
      n = n || pl.e.simule || '';
      // deux lignes : prénom (avec le numéro) puis nom ; sans nom chargé : le numéro seul
      var mots = n.split(/\s+/).filter(Boolean), l1, l2;
      if (!mots.length) { l1 = 'n° ' + pl.e.numero; l2 = ''; }
      else if (mots.length === 1) { l1 = mots[0]; l2 = ''; }
      else { l1 = mots[0]; l2 = mots.slice(1).join(' '); }
      if (avecNumero && mots.length) l1 = pl.e.numero + ' · ' + l1;
      var lignes = l2 ? 2 : 1;
      // zone du texte, selon la disposition
      var zx, zy, zl, zh, ancre;
      if (D > 0 && pl.cote) {
        var bloc = Math.min(l, D + 0.06 * U + l * 0.58), bx = pl.MX - bloc / 2;   // photo + nom, centrés ensemble dans la case
        var Dc = D / 0.92;
        photoPlace(el('g', { transform: 'translate(' + (bx - (Dc - D) / 2) + ',' + (pl.MY - Dc / 2) + ')' }, g), Dc, Dc, visage);
        zx = bx + D + 0.06 * U; zl = bx + bloc - zx; zy = pl.MY - D / 2; zh = D; ancre = 'start';
      } else if (D > 0) {
        var Dc2 = D / 0.92, haut = D + Math.min(h - D, 0.9 * U), ys = pl.MY - haut / 2;   // photo + nom, centrés ensemble
        photoPlace(el('g', { transform: 'translate(' + (pl.MX - Dc2 / 2) + ',' + (ys - (Dc2 - D) / 2) + ')' }, g), Dc2, Dc2, visage);
        zx = x0; zl = l; zy = ys + D + 1; zh = haut - D - 1; ancre = 'middle';
      } else {
        zx = x0; zl = l; zh = Math.min(h, 0.9 * U); zy = pl.MY - zh / 2; ancre = 'middle';
      }
      var taille = Math.min(0.36 * U, (zh - 2) / (lignes * 1.18));
      var tx = ancre === 'middle' ? zx + zl / 2 : zx + 3;
      var fond = el('rect', { class: 'imp-nom-fond', rx: 3 }, g);
      var texte = el('text', { class: 'imp-nom', 'text-anchor': ancre, 'font-size': taille.toFixed(2) }, g);
      [l1, l2].filter(Boolean).forEach(function (t, k) {
        var ts = el('tspan', { x: tx.toFixed(2), dy: k ? (taille * 1.15).toFixed(2) : 0 }, texte);
        ts.textContent = t;
        if (k === 0) ts.setAttribute('font-weight', '700');
      });
      function poser() {   // texte centré verticalement dans sa zone
        var hT = taille * (1 + 1.15 * (lignes - 1));
        texte.setAttribute('y', (zy + (zh - hT) / 2 + taille * 0.86).toFixed(2));
      }
      poser();
      // trop large pour sa zone : la police se réduit (mesurée une fois le texte posé)
      var larg = 0;
      try { larg = texte.getBBox().width; } catch (e) { /* hors page */ }
      if (larg > zl - 6) {   // 2 px de fond de chaque côté, et 1 px de marge
        taille = taille * (zl - 6) / larg;
        texte.setAttribute('font-size', taille.toFixed(2));
        Array.prototype.forEach.call(texte.querySelectorAll('tspan'), function (ts, k) { if (k) ts.setAttribute('dy', (taille * 1.15).toFixed(2)); });
        poser();
      }
      try {
        var bb = texte.getBBox();
        fond.setAttribute('x', (bb.x - 2).toFixed(2)); fond.setAttribute('y', (bb.y - 1).toFixed(2));
        fond.setAttribute('width', (bb.width + 4).toFixed(2)); fond.setAttribute('height', (bb.height + 2).toFixed(2));
      } catch (e) { fond.remove(); }
    });
  }

  function impression() {
    var src = document.getElementById('donnees-impression'), zone = document.getElementById('feuilles-impression');
    if (!src || !zone) return;
    var plans = JSON.parse(src.textContent);
    var cases = Array.prototype.slice.call(document.querySelectorAll('.impression-liste input[type=checkbox]'));
    var parFeuille = document.getElementById('imp-par-feuille'), orientation = document.getElementById('imp-orientation');
    var numeros = document.getElementById('imp-numeros'), photos = document.getElementById('imp-photos');
    try {   // derniers réglages de cet ordinateur
      var r = JSON.parse(localStorage.getItem('centrale-impression-plans')) || {};
      if (r.n) parFeuille.value = r.n;
      if (r.o) orientation.value = r.o;
      if (r.num === false) numeros.checked = false;
      if (r.ph === false && photos) photos.checked = false;
    } catch (e) { /* rien */ }
    // Mise en page qui donne les plus grands plans : pour chaque feuille, l'orientation (si « Automatique ») et la grille
    // (colonnes × rangées) qui maximisent l'échelle du plus petit plan de la feuille. Surface imprimable A4 avec 6 mm de marge.
    var FEUILLE = { landscape: [285, 198], portrait: [198, 285] }, ECART = 3, TETE = 7, BORD = 3;   // en mm
    function format(pl) {   // largeur / hauteur du plan dessiné (salle + marge, dans le sens de la salle)
      var pp = pl.donnees.plan || {}, v = pp.vue || {};
      var L = (v.portrait ? pp.hauteur : pp.largeur) || 16, H = (v.portrait ? pp.largeur : pp.hauteur) || 11;
      return { l: L + 2 * MARGE, h: H + 2 * MARGE };
    }
    function meilleureMiseEnPage(lot, o) {
      var orients = o === 'auto' ? ['landscape', 'portrait'] : [o], meilleure = null;
      orients.forEach(function (or) {
        var W = FEUILLE[or][0], H = FEUILLE[or][1];
        for (var col = 1; col <= lot.length; col++) {
          var rangs = Math.ceil(lot.length / col);
          if ((col - 1) * rangs >= lot.length) continue;   // une colonne vide : inutile
          var cl = (W - (col - 1) * ECART) / col - BORD, ch = (H - (rangs - 1) * ECART) / rangs - BORD - TETE;
          var echelle = Math.min.apply(null, lot.map(function (pl) { var f = format(pl); return Math.min(cl / f.l, ch / f.h); }));
          if (!meilleure || echelle > meilleure.echelle + 1e-6) meilleure = { or: or, col: col, rangs: rangs, echelle: echelle };
        }
      });
      return meilleure;
    }
    var style = document.createElement('style');
    document.head.appendChild(style);
    style.textContent = '@page { size: A4; margin: 6mm; } @page paysage { size: A4 landscape; margin: 6mm; } @page portrait { size: A4 portrait; margin: 6mm; }';

    function rendre() {
      var n = +parFeuille.value, o = orientation.value;
      try { localStorage.setItem('centrale-impression-plans', JSON.stringify({ n: n, o: o, num: numeros.checked, ph: !photos || photos.checked })); } catch (e) { /* rien */ }
      zone.replaceChildren();
      var choisis = cases.filter(function (c) { return c.checked; }).map(function (c) { return plans[+c.value]; });
      document.getElementById('imp-compte').textContent = choisis.length + ' plan' + (choisis.length > 1 ? 's' : '') + ' coché' + (choisis.length > 1 ? 's' : '');
      var nbFeuilles = Math.ceil(choisis.length / n);
      document.getElementById('imp-feuilles').textContent = choisis.length ? '· ' + nbFeuilles + ' feuille' + (nbFeuilles > 1 ? 's' : '') : '';
      document.getElementById('imp-imprimer').disabled = !choisis.length;
      if (!choisis.length) { zone.appendChild(creer('p', 'vide', 'Cochez un ou plusieurs plans.')); return; }
      for (var i = 0; i < choisis.length; i += n) {
        var lot = choisis.slice(i, i + n), mp = meilleureMiseEnPage(lot, o);
        var feuille = creer('div', 'feuille ' + (mp.or === 'portrait' ? 'portrait' : 'paysage'));
        feuille.style.gridTemplateColumns = 'repeat(' + mp.col + ', minmax(0, 1fr))';
        feuille.style.gridTemplateRows = 'repeat(' + mp.rangs + ', minmax(0, 1fr))';
        zone.appendChild(feuille);   // dans la page avant de dessiner : la taille des noms se mesure
        lot.forEach(function (pl) {
          var cas = creer('section', 'case-plan');
          // en-tête : pastille de la classe (couleurs du niveau et de l'établissement), salle et variante ; à droite l'établissement et la date
          var tete = creer('div', 'case-plan-tete'), gauche = creer('div', 'case-plan-titre'), pastille = creer('span', 'case-plan-pastille');
          if (pl.pastille) pastille.innerHTML = pl.pastille; else pastille.textContent = pl.classe;   // HTML produit par la Centrale (pastille_classe)
          gauche.append(pastille, creer('span', 'case-plan-salle', 'Salle ' + pl.salle), creer('span', 'case-plan-sous', pl.sous));
          tete.append(gauche, creer('span', 'case-plan-etab', (pl.etablissement || '') + ' · ' + new Date().toLocaleDateString('fr-FR')));
          var svg = document.createElementNS(NS, 'svg');
          svg.setAttribute('class', 'plan-svg plan-imprime');
          cas.append(tete, svg);
          feuille.appendChild(cas);
          dessinerImprimable(svg, pl.donnees, numeros.checked, !photos || photos.checked);
        });
      }
    }
    cases.forEach(function (c) { c.addEventListener('change', rendre); });
    [parFeuille, orientation, numeros, photos].forEach(function (x) { if (x) x.addEventListener('change', rendre); });
    document.getElementById('imp-tout').addEventListener('click', function () { cases.forEach(function (c) { c.checked = true; }); rendre(); });
    document.getElementById('imp-rien').addEventListener('click', function () { cases.forEach(function (c) { c.checked = false; }); rendre(); });
    document.getElementById('imp-imprimer').addEventListener('click', function () { window.print(); });
    document.addEventListener('centrale-noms', rendre);   // noms chargés après coup
    rendre();
  }

  function apercu(svg, d) {
    if (!svg || !d || !d.plan) return;
    var carte = d.placement || {}, enAttente = false;
    function eleve(id) { return (d.eleves || []).find(function (e) { return e.id === id; }); }
    function nom(e) { var n = window.CentraleNoms ? window.CentraleNoms.nomLocal(d.classe, e.numero) : ''; return n || e.simule || ('n° ' + e.numero); }
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
          if (ctx.nom) ctx.nom(e ? nom(e) : '?'); else ctx.lab(w / 2, h + 13, 0, 'nom-eleve', e ? nom(e) : '?');
          if (visage) bulleVisage(g, ctx, visage, w / 2, h / 2, 0.9, e ? nom(e) : '');
          else bulleNom(g, ctx, e ? nom(e) : '?', w / 2, h / 2, 0.9);
        },
        infos: {
          liens: function () {
            return liensPlaces(d, carte, function (num) { var e = (d.eleves || []).find(function (x) { return String(x.numero) === String(num); }); return e ? nom(e) : 'n° ' + num; });
          },
          nomPlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e ? nom(e) : ''; },
          visagePlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e ? photo(d.classe, e.numero) : null; },
          cartonsPlace: function (o) {
            var id = carte[o.id], e = id && eleve(id), cn = window.CentraleNoms;
            if (!e || !cn || !cn.cartons) return [];
            // en classe (appel, répétitions, cartons) | sur la plateforme (repères)
            var p = cn.presence ? cn.presence(d.classe, e.numero, seanceDe(d).cle) : '';
            var classe = classeVisible() ? (p ? [p] : []).concat(pastillesObservation(d, e), observationsClasse(d, e).map(function (r) { return r.code; }),
              cn.cartons(d.classe, e.numero).map(function (c) { return c.type; })) : [];
            var plateforme = reperesVisibles() ? reperesEleve(d, e).map(function (r) { return 'rep-' + r.code; }) : [];
            return classe.concat(classe.length && plateforme.length ? ['|'] : [], plateforme);
          },
          surClic: function (idPlace) {
            var cn = window.CentraleNoms, e = carte[idPlace] && eleve(carte[idPlace]);
            ficheEleve(svg, d, carte, idPlace);
          },
        },
      });
    }
    var rendreDessin = rendre;
    rendre = function () {
      rendreDessin();
      if (svg.closest('#dash-plan')) { planAccueil = { svg: svg, d: d, carte: carte }; majWidgetFeuille(); }
    };
    rendre();
    document.addEventListener('centrale-appel', function () { if (svg.closest('#dash-plan')) majWidgetFeuille(); });
    document.addEventListener('centrale-noms', rendre);   // redessine quand les noms locaux sont chargés
    document.addEventListener('centrale-vue', rendre);    // et quand la présentation change
  }

  return { editeur: editeur, placement: placement, dessiner: dessiner, apercu: apercu, barreVue: barreVue, impression: impression, TYPES: TYPES,
           REPERES: REPERES, CLASSE: CLASSE, CRITERES: CRITERES, GRAVITE: GRAVITE, icone: icone, feuilleSeance: feuilleSeance, statutFeuille: statutFeuille };
})();
