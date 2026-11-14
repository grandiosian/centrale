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
      rectFrac: function (o, fx, fy, fw, fh) {
        var lw = largeurVue(o), lh = hauteurVue(o);
        return tr.rect(o.x + fx * lw, o.y + fy * lh, fw * lw, fh * lh);
      },
    }, { surClic: infos.surClic || null, surGlisser: infos.surGlisser || null, peutGlisser: infos.peutGlisser || null, libelle: infos.libelle || null,
         surLacherAilleurs: infos.surLacherAilleurs || null, immediat: infos.immediat || null,
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

    function nom(e) {
      var n = window.CentraleNoms ? window.CentraleNoms.nomLocal(d.classe, e.numero) : '';
      return n || e.simule || ('n° ' + e.numero);
    }
    function eleve(id) { return d.eleves.find(function (e) { return e.id === id; }); }
    function placeDe(id) { return Object.keys(carte).find(function (p) { return carte[p] === id; }); }

    /** Pose l'élève sur la place ; si elle est prise, son occupant part à l'ancienne place de l'élève (échange),
        ou redevient non placé si l'élève venait de la liste. */
    function poser(place, id) {
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

    /** Clic sur une place (plan ou vue 3D) : pose l'élève sélectionné, ou reprend celui de la place. */
    function clicPlace(id) {
      if (ignorerClic) { ignorerClic = false; return; }
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
    var infosPlaces = {
      nomPlace: function (o) { var id = carte[o.id], e = id && eleve(id); return e ? nom(e) : ''; },
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
      peutGlisser: function (idPlace) { return !!carte[idPlace]; },
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
      boiteDuos.append(ul, creer('p', 'discret', 'Glissez l’un des deux ailleurs, ou « Placement aléatoire » : il les éloigne.'));
    }
    /** Vue à plat : un trait entre deux élèves d'un duo trop près (rouge : voisins, orange : tout proches). */
    function tracerDuos() {
      var p = affichage(plan);
      if ((p.vue || {}).iso || svg.style.display === 'none') return;
      var trop = duosTropPres(carte);
      if (!trop.length) return;
      var tr = transformation(p), calque = el('g', { class: 'calque-duos' }, svg);
      trop.forEach(function (c) {
        var oa = plan.objets.find(function (o) { return o.id === c.pa; }), ob = plan.objets.find(function (o) { return o.id === c.pb; });
        if (!oa || !ob) return;
        var ra = tr.rect(oa.x, oa.y, largeurVue(oa), hauteurVue(oa)), rb = tr.rect(ob.x, ob.y, largeurVue(ob), hauteurVue(ob));
        var ln = el('line', { x1: (ra.x + ra.w / 2) * U, y1: (ra.y + ra.h / 2) * U, x2: (rb.x + rb.w / 2) * U, y2: (rb.y + rb.h / 2) * U,
          class: 'ligne-duo ' + (c.voisins ? 'voisins' : 'proches') }, calque);
        el('title', {}, ln).textContent = 'À séparer · ' + resumePaire(c.paire);
      });
    }

    function rendre() {
      orientationPage(affichage(plan));
      var nums = numeros(plan);
      dessiner(svg, plan, {
        surObjet: function (o, g, w, h, ctx) {
          if (!estPlace(o)) return;
          g.classList.add('place-cliquable');
          g.setAttribute('data-place', o.id);
          var id = carte[o.id];
          if (id) {
            g.classList.add('occupee');
            g.addEventListener('pointerdown', function (ev) { glisserSvg(g, o.id, ev); });
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
        var dhl = dehors(d.classe, e.numero);
        li.querySelector('.ou').textContent = (place ? 'place ' + nums[place] : '') + (dhl ? (place ? ' · ' : '') + 'dehors' : '');
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
      rendreDehors();
      tracerDuos();
      rendreDuos();
      var nbPlaces = plan.objets.filter(estPlace).length;
      var nbPlaces2 = Object.keys(carte).length;
      document.getElementById('compte-places').textContent = nbPlaces2 + ' élève' + (nbPlaces2 > 1 ? 's' : '') + ' placé' + (nbPlaces2 > 1 ? 's' : '')
        + ' sur ' + d.eleves.length + ' · ' + nbPlaces + ' places élèves dans la salle'
        + (document.getElementById('compte-places').dataset.alerte ? ' · ⚠ ' + document.getElementById('compte-places').dataset.alerte : '');
    }

    var boutonVider = document.getElementById('p-vider');
    boutonVider.onclick = function () {
      if (confirmerEnLigne(boutonVider, 'Confirmer : tout vider ?')) { carte = {}; choisi = null; modifie(); rendre(); }
    };
    document.getElementById('p-aleatoire').onclick = function () {
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
    document.getElementById('form-placement').addEventListener('submit', function () {
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
        x.title = CRITERES[k].libelle + ((oSeance._c || {})[k] ? ' : ' + oSeance._c[k] : '');
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
        ligneO.append(choix, creer('span', 'obs-total', (b.plus || b.moins) ? '+' + b.plus + ' / −' + b.moins : '—'), pointsSeances(b, 8));
        // commentaire de la séance pour ce critère (enregistré en quittant le champ), puis les précédents
        var com = creer('input', 'obs-commentaire');
        com.type = 'text';
        com.maxLength = 300;
        com.placeholder = 'Commentaire pour préciser (facultatif)';
        com.value = (actuelles._c || {})[k] || '';
        com.addEventListener('change', function () { com.value = cn.definirCommentaire(d.classe, e.numero, sObs.cle, k, com.value) || ''; });
        com.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') com.blur(); });
        ligneO.appendChild(com);
        var anciens = b.commentaires.filter(function (c) { return c.cle !== sObs.cle; }).slice(0, 3);
        if (anciens.length) {
          var ul = creer('ul', 'obs-anciens');
          anciens.forEach(function (c) { ul.appendChild(creer('li', '', c.cle.slice(0, 10).split('-').reverse().join('/') + ' (' + signeNiveau(c.v) + ') : ' + c.texte)); });
          ligneO.appendChild(ul);
        }
        obsP.appendChild(ligneO);
      });
    }
    var appel = partie('appel', 'Appel · observé en classe', 'classe', 'Noté par vous, enregistré sur la Centrale.');
    lignesReperes(appel, obs, CLASSE);
    if (cn && cn.presence) appel.appendChild(sectionPresence(d, e.numero, actualiser));
    var blocCartons = partie('cartons', 'Cartons · observé en classe', 'classe', 'Observations et récompenses, enregistrées sur la Centrale.');
    if (cn && cn.cartons) blocCartons.appendChild(sectionCartons(d.classe, e.numero, actualiser));

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
      if (cn && cn.cartons) cn.cartons(d.classe, e.numero).forEach(function (c) { if (c.type !== 'recompense') observations++; });
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
    altercation: { libelle: 'Altercation', icone: '⚔️', texte: 'se sont disputés ou accrochés' }
  };
  /** Les paires d'une classe : [{ a, b, dissipation, altercation, poids, derniere, textes }], la plus lourde d'abord. */
  function pairesDuos(classe) {   // chaque incident à plusieurs donne toutes ses paires
    var cn = window.CentraleNoms, carte = {};
    if (!cn || !cn.incidents) return [];
    cn.incidents(classe).forEach(function (x) {
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
    return [p.dissipation ? p.dissipation + ' dissipation' + (p.dissipation > 1 ? 's' : '') : '', p.altercation ? p.altercation + ' altercation' + (p.altercation > 1 ? 's' : '') : '']
      .filter(Boolean).join(' · ') + (p.derniere ? ' (dernière le ' + jourSeance(p.derniere) + ')' : '');
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
    enTete.append(blocTitre, caseTerminee(false));
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
    carteF.append(fermer, enTete, barreAt, corpsL, popInt);

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
      entete('col-niveau', CRITERES[k].court || CRITERES[k].lettre, CRITERES[k].libelle, CRITERES[k].libelle + ' : de −− à ++, et un commentaire (survolez ou touchez la pastille)');
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
      pop.append(creer('h4', '', eleve ? 'À séparer · ' + nomE : 'À séparer · classe ' + d.classe),
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
      var l = cn.incidents(d.classe), alt = l.some(function (x) { return x.type === 'altercation'; });
      bDC.textContent = l.length ? (alt ? '⚔️' : '💬') + l.length : '＋';
      bDC.className = 'duo-bouton' + (l.length ? (alt ? ' duo-altercation' : ' duo-dissipation') : '');
      bDC.title = l.length ? l.length + ' incident' + (l.length > 1 ? 's' : '') + ' noté' + (l.length > 1 ? 's' : '') + ' dans la classe : clic pour la liste, ou en noter un'
        : 'Noter une dissipation ou une altercation entre élèves';
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
      // critères : pastille résumée ; au survol (ou au clic), les 5 niveaux ; re-cliquer sur le niveau choisi l'efface
      criteres.forEach(function (k) {
        var td = creer('td', 'col-niveau'), boite = creer('span', 'feuille-niveaux grav-' + (CRITERES[k].gravite || 'orange')), chip = creer('button'), palette = creer('span', 'niv-palette'),
            boutons = creer('span', 'niv-boutons'), comK = creer('input', 'niv-com');
        chip.type = 'button';
        comK.type = 'text';
        comK.maxLength = 300;
        comK.placeholder = 'Préciser (facultatif)';
        comK.value = (ob._c || {})[k] || '';
        function majChip(v) {   // pastille : niveau, « C » s'il y a un commentaire, commentaire au survol
          niveauChip(chip, v);
          chip.classList.toggle('avec-com', !!comK.value.trim());
          chip.title = CRITERES[k].libelle + ' · ' + nomE + (comK.value.trim() ? ' : « ' + comK.value.trim() + ' »' : ' : survolez pour choisir');
        }
        majChip(ob[k] || 0);
        comK.addEventListener('change', function () {
          comK.value = cn.definirCommentaire(d.classe, e.numero, s.cle, k, comK.value) || '';
          majChip(cn.observation(d.classe, e.numero, s.cle)[k] || 0);
        });
        comK.addEventListener('keydown', function (ev) {
          if (ev.key === 'Enter' || ev.key === 'Escape') { ev.stopPropagation(); comK.blur(); boite.classList.remove('ouvert'); }
        });
        chip.addEventListener('click', function () {   // toucher (tablette, smartphone) : ouvre ou ferme, un seul à la fois
          var ouvrir = !boite.classList.contains('ouvert');
          fond.querySelectorAll('.feuille-niveaux.ouvert, .feuille-com.ouvert').forEach(function (x) { x.classList.remove('ouvert'); });
          boite.classList.toggle('ouvert', ouvrir);
        });
        [-2, -1, 0, 1, 2].forEach(function (v) {
          var bt = creer('button', 'niv niv-' + (v > 0 ? 'plus' : v < 0 ? 'moins' : 'neutre') + (Math.abs(v) > 1 ? ' fort' : ''), v === 0 ? '·' : signeNiveau(v));
          bt.type = 'button';
          bt.title = CRITERES[k].libelle + ' : ' + NIVEAUX[String(v)].libelle;
          bt.setAttribute('aria-pressed', String((ob[k] || 0) === v && v !== 0));
          bt.addEventListener('click', function () {
            var actuel = cn.observation(d.classe, e.numero, s.cle)[k] || 0, nouveau = actuel === v ? 0 : v;
            cn.definirObservation(d.classe, e.numero, s.cle, k, nouveau);
            boutons.querySelectorAll('button').forEach(function (b2, i) { b2.setAttribute('aria-pressed', String([-2, -1, 0, 1, 2][i] === nouveau && nouveau !== 0)); });
            majChip(nouveau);
            if (tr._majNote) tr._majNote();
            bt.blur();   // au survol, la palette reste là le temps d'écrire un commentaire ; elle se replie quand la souris s'en va
            compter();
          });
          boutons.appendChild(bt);
        });
        palette.append(creer('span', 'niv-titre', CRITERES[k].libelle + ' · ' + nomE), boutons, comK);   // titre : affiché sur smartphone (bandeau en bas)
        boite.append(chip, palette);
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
        bD.textContent = l.length ? (alt ? '⚔️' : '💬') + l.length : '·';
        bD.className = 'duo-bouton' + (l.length ? (alt ? ' duo-altercation' : ' duo-dissipation') : '');
        bD.title = l.length ? 'À séparer de : ' + l.map(function (p) { return nomDe(p.a === num ? p.b : p.a) + ' (' + resumePaire(p) + ')'; }).join(', ')
          : nomE + ' : clic pour noter une dissipation ou une altercation avec un ou plusieurs camarades';
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

  return { editeur: editeur, placement: placement, dessiner: dessiner, apercu: apercu, barreVue: barreVue, TYPES: TYPES,
           REPERES: REPERES, CLASSE: CLASSE, CRITERES: CRITERES, GRAVITE: GRAVITE, icone: icone, feuilleSeance: feuilleSeance, statutFeuille: statutFeuille };
})();
