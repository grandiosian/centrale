/**
 * Centrale élèves — rendu 3D de la salle (vue 3/4), sans perspective : caméra orthographique.
 * Dépend de three.js (r128). PlanSalle3D.rendre(conteneur, plan, opts) : plan = { L, H, sol, angle, objets:[{ obj, rect, label, visage }], rectFrac(obj, fx, fy, fw, fh) }.
 * opts.surClic(id) : clic sur une place. opts.quand() : appelé quand une photo est chargée (redessiner).
 */
window.PlanSalle3D = (function () {
  'use strict';

  var COULEURS = { table: '#c98f56', paillasse: '#b08a5c', bureau: '#a8683a', tableau: '#f5f7f9', armoire: '#a4adb7', porte: '#8a5a32', fenetre: '#a9dcf0', place: '#3f9b6a', poste: '#2b2f36', mur: '#f78f4a', chapeau: '#b9c8d6', socle: '#d8cfc2' };
  var VOLUMES = { table: [0, 0.75], paillasse: [0, 0.9], bureau: [0, 0.8], place: [0, 0.45], poste: [0.75, 1.05], tableau: [0.9, 2], armoire: [0, 2.2], porte: [0, 2.1], fenetre: [0.9, 2] };
  var ECRAN = { 0: [0.15, 0.08, 0.7, 0.12], 90: [0.8, 0.15, 0.12, 0.7], 180: [0.15, 0.8, 0.7, 0.12], 270: [0.08, 0.15, 0.12, 0.7] };
  var CLAVIER = { 0: [0.2, 0.55, 0.6, 0.25], 90: [0.2, 0.2, 0.25, 0.6], 180: [0.15, 0.2, 0.7, 0.25], 270: [0.55, 0.2, 0.25, 0.6] };
  var MURS = 2.6, SOCLE = 0.35;
  /** Palettes : murs, chapeau, chaises, tableau. La classe choisit la sienne (classe_id modulo le nombre de palettes). */
  var PALETTES = [
    { mur: '#f78f4a', chapeau: '#b9c8d6', chaise: '#3f9b6a', tableau: '#2c4a3e' },   // orange et vert
    { mur: '#6fb6d9', chapeau: '#f2c14e', chaise: '#c2456b', tableau: '#2b3f5c' },   // bleu et framboise
    { mur: '#b9d98a', chapeau: '#6b4f8a', chaise: '#e0873a', tableau: '#3d4a33' },   // vert anis et violet
    { mur: '#e8c9a0', chapeau: '#4d6b5c', chaise: '#3b6ea5', tableau: '#2c4a3e' }    // sable et bleu
  ];
  var pal = PALETTES[0];
  var images = {};

  function chargerImage(url, quand) {
    if (images[url]) return images[url];
    var im = new Image();
    im.onload = function () { if (quand) quand(); };
    im.src = url;
    images[url] = im;
    return im;
  }

  /** « NOM Prénom » (nom de famille en capitales) : le prénom passe en premier. */
  function decouper(texte) {
    var mots = String(texte).trim().split(/\s+/), i = 0;
    while (i < mots.length - 1 && /[A-ZÀ-Ý]/.test(mots[i]) && mots[i] === mots[i].toUpperCase()) i++;
    var nom = mots.slice(0, i).join(' '), prenom = mots.slice(i).join(' ');
    return prenom ? { prenom: prenom, nom: nom } : { prenom: nom, nom: '' };
  }

  var ECHELLE = 0.0085;   // unités 3D par pixel de la carte d'étiquette

  /**
   * Carte d'un élève : petite photo ronde (recadrée sur le visage) et prénom dans une bande.
   * grand : au survol, photo et prénom un peu plus gros, et le nom de famille s'ajoute en petit.
   * Renvoie la texture et la taille de la carte en unités 3D.
   */
  function carteEtiquette(visage, texte, quand, grand) {
    var mots = decouper(texte);
    var avecNom = !!mots.nom && (grand || !visage);   // le nom de famille est dessous, plus petit que le prénom
    // au repos : petite photo, prénom ; au survol : grande photo, prénom plus grand, nom en dessous
    var R = visage ? (grand ? 78 : 26) : 0;
    var fPrenom = grand ? 62 : 34, fNom = grand ? 36 : 22;
    var mesure = document.createElement('canvas').getContext('2d');
    mesure.font = '700 ' + fPrenom + 'px system-ui, sans-serif';
    var lp = mesure.measureText(mots.prenom).width, ln = 0;
    if (avecNom) { mesure.font = '600 ' + fNom + 'px system-ui, sans-serif'; ln = mesure.measureText(mots.nom).width; }
    var W = Math.ceil(Math.max(lp, ln, visage ? 2 * R : 0) + 36);
    var haut = visage ? 2 * R + 12 : 4;
    var barre = avecNom ? Math.round(fPrenom * 1.2 + fNom * 1.3 + 10) : Math.round(fPrenom * 1.3);
    var H = haut + barre + 4;
    var cv = document.createElement('canvas'), g = cv.getContext('2d');
    cv.width = W; cv.height = H;
    if (visage) {
      var cx = W / 2, cy = R + 4;
      g.fillStyle = '#dde4ea'; g.beginPath(); g.arc(cx, cy, R, 0, Math.PI * 2); g.fill();
      var im = chargerImage(visage.image, quand);
      if (im.complete && im.naturalWidth) {
        var dw = 2 * R, dh = dw * visage.h / visage.l, dx = cx - R, dy = cy - visage.fy * dh;
        g.save(); g.beginPath(); g.arc(cx, cy, R, 0, Math.PI * 2); g.clip();
        g.drawImage(im, visage.x, visage.y, visage.l, visage.h, dx, dy, dw, dh);
        g.restore();
      }
      g.strokeStyle = '#ffffff'; g.lineWidth = grand ? 6 : 3; g.beginPath(); g.arc(cx, cy, R, 0, Math.PI * 2); g.stroke();
    }
    var rayon = Math.min(22, barre / 2);
    g.fillStyle = 'rgba(255,255,255,0.94)'; rectArrondi(g, 2, haut, W - 4, barre, rayon); g.fill();
    g.strokeStyle = '#1f5f8b'; g.lineWidth = 4; rectArrondi(g, 2, haut, W - 4, barre, rayon); g.stroke();
    g.textAlign = 'center'; g.textBaseline = 'middle';
    g.fillStyle = '#14284b'; g.font = '700 ' + fPrenom + 'px system-ui, sans-serif';
    g.fillText(mots.prenom, W / 2, avecNom ? haut + fPrenom * 0.6 + 6 : haut + barre / 2);
    if (avecNom) { g.fillStyle = '#1f3a63'; g.font = '600 ' + fNom + 'px system-ui, sans-serif'; g.fillText(mots.nom, W / 2, haut + barre - fNom * 0.6 - 6); }
    return { tex: texture(cv, 1, 1), w: W * ECHELLE, h: H * ECHELLE };
  }

  /** Rectangle à coins arrondis (chemin de canvas, à remplir ou à contourner). */
  function rectArrondi(g, x, y, w, h, r) {
    g.beginPath();
    g.moveTo(x + r, y);
    g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r);
    g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r);
    g.closePath();
  }

  /** Générateur pseudo-aléatoire déterministe : même motif à chaque affichage. */
  function generateur(graine) {
    return function () { graine = (graine * 16807) % 2147483647; return graine / 2147483647; };
  }

  /** Sol : carrelage beige (uni), lames de bois chaud, ou parquet en blocs, avec un léger grain. */
  function texSol(type) {
    var cv = document.createElement('canvas'); cv.width = cv.height = 512;
    var g = cv.getContext('2d'), alea = generateur(7);
    function veines(y0, hauteur, nb) {
      for (var v = 0; v < nb; v++) {
        g.fillStyle = 'rgba(110,64,30,' + (0.06 + alea() * 0.12).toFixed(2) + ')';
        g.fillRect(alea() * 512, y0 + alea() * hauteur, 40 + alea() * 130, 1.5);
      }
    }
    if (type === 'bois') {
      for (var r = 0; r < 4; r++) {
        var y = r * 128, off = (r % 2) * 128;
        g.fillStyle = ['#c98d56', '#c28250', '#cf9760', '#c48753'][r];
        g.fillRect(0, y, 512, 128);
        for (var c = -1; c < 3; c++) { g.fillStyle = 'rgba(80,45,20,0.6)'; g.fillRect(off + c * 256, y, 3, 128); }
        veines(y, 128, 24);
      }
    } else if (type === 'parquet') {
      for (var a = 0; a < 8; a++) for (var b = 0; b < 8; b++) {
        g.fillStyle = (a + b) % 2 ? '#c4915c' : '#b98350';
        g.fillRect(a * 64, b * 64, 64, 64);
        g.strokeStyle = 'rgba(90,50,25,0.45)'; g.lineWidth = 2; g.strokeRect(a * 64 + 1, b * 64 + 1, 62, 62);
        veines(b * 64, 64, 2);
      }
    } else {
      // carrelage : dalles beige clair sur joints gris chaud
      g.fillStyle = '#b3a590'; g.fillRect(0, 0, 512, 512);
      var teintes = ['#dcd0bd', '#d5c8b3', '#e2d7c5'];
      for (var i = 0; i < 8; i++) for (var j = 0; j < 8; j++) {
        g.fillStyle = teintes[(i * 3 + j * 5) % 3];
        g.fillRect(i * 64 + 2, j * 64 + 2, 60, 60);
      }
    }
    return cv;
  }

  /** Murs : peinture unie, grain très léger (la teinte vient du matériau). */
  function texMur() {
    var cv = document.createElement('canvas'); cv.width = cv.height = 256;
    var g = cv.getContext('2d'), alea = generateur(3);
    g.fillStyle = '#ffffff'; g.fillRect(0, 0, 256, 256);
    for (var i = 0; i < 160; i++) {
      g.fillStyle = 'rgba(90,60,30,' + (alea() * 0.035).toFixed(3) + ')';
      g.fillRect(alea() * 256, alea() * 256, 4 + alea() * 16, 2);
    }
    return cv;
  }

  /** Livres de l'armoire : tranches colorées sur des étagères (motif fixe, sans hasard). */
  function texLivres() {
    var cv = document.createElement('canvas'); cv.width = 128; cv.height = 256;
    var g = cv.getContext('2d');
    var couleurs = ['#c0392b', '#2e86c1', '#27ae60', '#f1c40f', '#8e44ad', '#e67e22', '#ecf0f1'];
    g.fillStyle = '#4a5666'; g.fillRect(0, 0, 128, 256);
    for (var r = 0; r < 4; r++) {
      var y = r * 64, x = 4, k = 0;
      while (x < 120) {
        var lg = 5 + ((k * 7 + r * 3) % 4);
        g.fillStyle = couleurs[(k * 3 + r) % couleurs.length];
        g.fillRect(x, y + 6, lg, 50);
        x += lg + 1; k++;
      }
      g.fillStyle = '#2d3642'; g.fillRect(0, y + 58, 128, 6);
    }
    return cv;
  }

  function texture(cv, rx, ry) {
    var t = new THREE.CanvasTexture(cv);
    t.anisotropy = 8;
    t.wrapS = t.wrapT = THREE.RepeatWrapping;
    t.repeat.set(rx, ry);
    return t;
  }

  function boite(scene, x, y, z, w, h, d, couleur) {
    var m = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), new THREE.MeshLambertMaterial({ color: couleur }));
    m.position.set(x, y, z);
    m.castShadow = true; m.receiveShadow = true;
    scene.add(m);
    return m;
  }

  /** Murs extérieurs : peints en orange chaud, avec un chapeau clair sur les deux murs du fond. */
  function murs(scene, L, H) {
    var mat = new THREE.MeshLambertMaterial({ color: pal.mur, map: texture(texMur(), 2, 1), side: THREE.FrontSide });
    function mur(w, x, z, ry) {
      var m = new THREE.Mesh(new THREE.PlaneGeometry(w, MURS), mat);
      m.position.set(x, MURS / 2, z);
      m.rotation.y = ry;
      m.receiveShadow = true;
      scene.add(m);
    }
    mur(L, L / 2, 0, 0);
    mur(H, 0, H / 2, Math.PI / 2);
    mur(L, L / 2, H, Math.PI);
    mur(H, L, H / 2, -Math.PI / 2);
    boite(scene, L / 2, MURS, 0.06, L, 0.12, 0.12, pal.chapeau);
    boite(scene, 0.06, MURS, H / 2, 0.12, 0.12, H, pal.chapeau);
  }

  /**
   * Pastille de la classe (nom court, fond = niveau, bord = établissement) : une étiquette au centre de chaque mur,
   * posée dans le plan du mur. Seule celle du mur qui fait face à la caméra est montrée (voir panneauFace).
   */
  function panneauxClasse(scene, st, L, H, pastille) {
    var police = '700 96px system-ui, sans-serif', police2 = '600 64px system-ui, sans-serif', police3 = '600 66px system-ui, sans-serif';
    var BLEU = '#1565c0', HT = 150, bord = pastille.bord ? 12 : 0, r = HT / 2, ligne = 80, espace = 18, ecart = 44;   // ecart : entre les infos du haut et la pastille
    // de haut en bas, sur le même axe à gauche : atelier en cours, nombre d'observations, pastille, puis « Salle - Collège »
    var dessus = [];
    if (pastille.atelier) dessus.push({ texte: 'Atelier : ' + pastille.atelier, police: police3, hauteur: ligne });
    if (pastille.eleves !== undefined) dessus.push({ texte: pastille.eleves + ' élève' + (pastille.eleves > 1 ? 's' : ''), police: police3, hauteur: ligne });
    if (pastille.observations !== undefined) dessus.push({ texte: pastille.observations + ' observation' + (pastille.observations > 1 ? 's' : ''), police: police3, hauteur: ligne });
    var dessous = pastille.infos ? [{ texte: pastille.infos, police: police2 }] : [];   // à droite de la pastille, même niveau
    var mesure = document.createElement('canvas').getContext('2d');
    mesure.font = police;
    var pillW = Math.ceil(mesure.measureText(pastille.texte).width + 96 + 2 * bord), W = pillW;
    dessus.forEach(function (it) { mesure.font = it.police; W = Math.max(W, Math.ceil(mesure.measureText(it.texte).width)); });
    dessous.forEach(function (it) { mesure.font = it.police; it.x = pillW + 2 * espace; W = Math.max(W, it.x + Math.ceil(mesure.measureText(it.texte).width)); });
    var y = 0;
    dessus.forEach(function (it) { it.y = y; y += it.hauteur; });
    var yPastille = y + (dessus.length ? ecart : 0);
    dessous.forEach(function (it) { it.y = yPastille + HT / 2; });   // centré sur la pastille
    y = yPastille + HT;
    var cv = document.createElement('canvas'), g = cv.getContext('2d');
    cv.width = W; cv.height = y;
    function pilule(x, yy, w, h, rayon) {
      g.beginPath(); g.moveTo(x + rayon, yy); g.lineTo(x + w - rayon, yy);
      g.arc(x + w - rayon, yy + rayon, rayon, -Math.PI / 2, Math.PI / 2);
      g.lineTo(x + rayon, yy + h); g.arc(x + rayon, yy + rayon, rayon, Math.PI / 2, 3 * Math.PI / 2); g.closePath();
    }
    // lignes en bleu au-dessus de la pastille
    g.textAlign = 'left'; g.textBaseline = 'middle'; g.fillStyle = BLEU;
    dessus.forEach(function (it) { g.font = it.police; g.fillText(it.texte, 0, it.y + it.hauteur / 2); });
    // pastille de la classe
    if (bord) { g.fillStyle = pastille.bord; pilule(0, yPastille, pillW, HT, r); g.fill(); }
    g.fillStyle = pastille.fond; pilule(bord, yPastille + bord, pillW - 2 * bord, HT - 2 * bord, r - bord); g.fill();
    g.fillStyle = '#1d1f24'; g.font = police; g.textAlign = 'center';
    g.fillText(pastille.texte, pillW / 2, yPastille + HT / 2 + 4);
    // « Salle - Collège » à droite de la pastille, en bleu
    g.textAlign = 'left'; g.fillStyle = BLEU;
    dessous.forEach(function (it) { g.font = it.police; g.fillText(it.texte, it.x, it.y); });
    var mat = new THREE.MeshBasicMaterial({ map: texture(cv, 1, 1), transparent: true, toneMapped: false });
    var lw = W * ECHELLE * 0.75, lh = cv.height * ECHELLE * 0.75, bordure = 0.12;   // marge au bord du mur
    // murs : centre, rotation, longueur ; l'axe local +x va de la gauche à la droite de qui regarde la salle
    st.murs = [{ x: L / 2, z: 0, ry: 0, w: L }, { x: 0, z: H / 2, ry: Math.PI / 2, w: H }, { x: L / 2, z: H, ry: Math.PI, w: L }, { x: L, z: H / 2, ry: -Math.PI / 2, w: H }];
    var anciens = st.panneaux || [];   // on garde l'état du fondu quand la scène est reconstruite
    st.panneaux = st.murs.map(function (c, i) {
      var k = Math.min(1, c.w * 0.5 / lw), lx = lw * k, ly = lh * k, sc = ECHELLE * 0.75 * k;
      var m = new THREE.Mesh(new THREE.PlaneGeometry(lx, ly), mat.clone());   // un matériau par mur : chacun a son opacité
      var p = anciens[i] ? anciens[i].userData.p : 0;
      m.userData = { p: p, cible: anciens[i] ? anciens[i].userData.cible : 0 };
      m.material.opacity = p;
      var ax = Math.cos(c.ry), az = -Math.sin(c.ry), d = -c.w / 2 + bordure + lx / 2;   // à gauche du mur
      // la pastille est calée sur le bord haut du mur ; les lignes au-dessus dépassent du mur (en dehors de la salle)
      var haut = MURS - bordure + yPastille * sc - ly / 2;
      m.position.set(c.x + ax * d + Math.sin(c.ry) * 0.02, haut, c.z + az * d + Math.cos(c.ry) * 0.02);
      m.rotation.y = c.ry;
      m.visible = p > 0;
      scene.add(m);
      return m;
    });
  }

  /** Montre l'étiquette du mur dont la normale (vers l'intérieur) pointe vers la caméra ; les autres sont cachées. */
  function panneauFace(st) {
    if (!st.panneaux) return;
    var cam = st.camera.position, meilleur = 0, score = -Infinity;
    st.murs.forEach(function (c, i) {
      var s = Math.sin(c.ry) * (cam.x - c.x) + Math.cos(c.ry) * (cam.z - c.z);
      if (s > score) { score = s; meilleur = i; }
    });
    st.panneaux.forEach(function (m, i) { m.userData.cible = i === meilleur ? 1 : 0; });
    animer(st);   // le fondu se joue dans la boucle d'animation
  }

  /** Plateau sur pieds fins : table élève, bureau du professeur. */
  function table(scene, r, hauteur, plateau, pieds) {
    var e = 0.06, p = 0.07, m = 0.12;
    boite(scene, r.x + r.w / 2, hauteur - e / 2, r.y + r.h / 2, r.w, e, r.h, plateau);
    [[r.x + m, r.y + m], [r.x + r.w - m, r.y + m], [r.x + r.w - m, r.y + r.h - m], [r.x + m, r.y + r.h - m]].forEach(function (c) {
      boite(scene, c[0], (hauteur - e) / 2, c[1], p, hauteur - e, p, pieds);
    });
    return null;
  }

  /** Paillasse : plan de travail clair posé sur un caisson. */
  function paillasse(scene, r, hauteur) {
    var e = 0.06, cx = r.x + r.w / 2, cz = r.y + r.h / 2;
    boite(scene, cx, (hauteur - e) / 2, cz, r.w - 0.1, hauteur - e, r.h - 0.1, COULEURS.paillasse);
    boite(scene, cx, hauteur - e / 2, cz, r.w, e, r.h, '#d5dbe2');
    return null;
  }

  /** Armoire à livres : tranches colorées sur les faces latérales. */
  function armoire(scene, r, hauteur) {
    var cote = new THREE.MeshLambertMaterial({ map: texture(texLivres(), 1, 1) });
    var dessus = new THREE.MeshLambertMaterial({ color: COULEURS.armoire });
    var m = new THREE.Mesh(new THREE.BoxGeometry(r.w, hauteur, r.h), [cote, cote, dessus, dessus, cote, cote]);
    m.position.set(r.x + r.w / 2, hauteur / 2, r.y + r.h / 2);
    m.castShadow = true; m.receiveShadow = true;
    scene.add(m);
    return null;
  }

  /** Tableau : cadre clair et surface verte, un peu en saillie. */
  function tableau(scene, r, bas, haut) {
    var cx = r.x + r.w / 2, cz = r.y + r.h / 2, hauteur = haut - bas, y = (bas + haut) / 2;
    boite(scene, cx, y, cz, r.w, hauteur, r.h, '#a86b3a');
    boite(scene, cx, y, cz, r.w - 0.14, hauteur - 0.14, r.h + 0.04, pal.tableau);
    return null;
  }

  /** Pièce posée le long du mur : dl = décalage le long du mur, longueur le long du mur, épaisseur à travers. */
  function piece(scene, r, dl, y, hauteur, longueur, epaisseur, couleur, materiau) {
    var horiz = r.w >= r.h, cx = r.x + r.w / 2, cz = r.y + r.h / 2;
    var w = horiz ? longueur : epaisseur, d = horiz ? epaisseur : longueur;
    var m = new THREE.Mesh(new THREE.BoxGeometry(w, hauteur, d), materiau || new THREE.MeshLambertMaterial({ color: couleur }));
    m.position.set(cx + (horiz ? dl : 0), y, cz + (horiz ? 0 : dl));
    m.castShadow = true; m.receiveShadow = true;
    scene.add(m);
    return m;
  }

  /** Porte : cadre et linteau en bois foncé, vantail clair avec panneau vitré, poignée et seuil. */
  function porte(scene, r, bas, haut) {
    var horiz = r.w >= r.h, L = horiz ? r.w : r.h, T = horiz ? r.h : r.w;
    var cadre = '#6b4423', bois = '#c9a174', vitre = new THREE.MeshLambertMaterial({ color: '#cfe9f5', transparent: true, opacity: 0.6 });
    var hauteur = haut - bas, milieu = (bas + haut) / 2;
    piece(scene, r, -L / 2 + 0.05, milieu, hauteur, 0.1, T * 1.1, cadre);
    piece(scene, r, L / 2 - 0.05, milieu, hauteur, 0.1, T * 1.1, cadre);
    piece(scene, r, 0, haut - 0.04, 0.08, L, T * 1.1, cadre);
    piece(scene, r, 0, bas + 0.02, 0.04, L + 0.06, T * 1.3, '#8a7a6a');
    piece(scene, r, 0, milieu, hauteur - 0.1, L - 0.14, T * 0.5, bois);
    piece(scene, r, 0, 1.45, 0.7, L - 0.45, T * 0.6, vitre);
    piece(scene, r, L / 2 - 0.3, 1.0, 0.14, 0.14, T * 0.5 + 0.06, '#c0c6cc');
    return null;
  }

  /** Fenêtre : cadre clair, appui, vitrage translucide et meneaux en croix. */
  function fenetre(scene, r, bas, haut) {
    var horiz = r.w >= r.h, L = horiz ? r.w : r.h, T = horiz ? r.h : r.w;
    var cadre = '#f2f4f6', verre = new THREE.MeshLambertMaterial({ color: '#bfe6f5', transparent: true, opacity: 0.45 });
    var hauteur = haut - bas, milieu = (bas + haut) / 2;
    piece(scene, r, 0, bas + 0.03, 0.06, L + 0.12, T * 1.4, cadre);
    piece(scene, r, 0, haut - 0.04, 0.08, L, T * 0.9, cadre);
    piece(scene, r, -L / 2 + 0.04, milieu, hauteur, 0.08, T * 0.9, cadre);
    piece(scene, r, L / 2 - 0.04, milieu, hauteur, 0.08, T * 0.9, cadre);
    piece(scene, r, 0, milieu, hauteur - 0.1, L - 0.1, T * 0.3, verre);
    piece(scene, r, 0, milieu, hauteur - 0.1, 0.05, T * 0.5, cadre);
    piece(scene, r, 0, milieu, 0.05, L - 0.1, T * 0.5, cadre);
    return null;
  }

  /** Un meuble (ou une place) à partir de sa description : boîte, tabouret, ordinateur. */
  function meuble(scene, item, st) {
    var o = item.obj, r = item.rect, v = VOLUMES[o.type] || [0, 0.6];
    var cx = r.x + r.w / 2, cz = r.y + r.h / 2;
    var couleur = COULEURS[o.type] || '#a4adb7';
    if (o.type === 'place') {
      // tabouret : assise ronde, quatre pieds
      var assise = new THREE.Mesh(new THREE.CylinderGeometry(0.27, 0.27, 0.06, 24), new THREE.MeshLambertMaterial({ color: pal.chaise }));
      assise.position.set(cx, 0.42, cz);
      assise.castShadow = true;
      assise.userData.placeId = o.id;
      scene.add(assise);
      st.pick.push(assise);
      [[-0.16, -0.16], [0.16, -0.16], [0.16, 0.16], [-0.16, 0.16]].forEach(function (d) {
        boite(scene, cx + d[0], 0.2, cz + d[1], 0.05, 0.4, 0.05, '#2e3138');
      });
      return null;
    }
    if (o.type === 'poste') {
      var kb = st.plan.rectFrac(o, CLAVIER[o.r || 0][0], CLAVIER[o.r || 0][1], CLAVIER[o.r || 0][2], CLAVIER[o.r || 0][3]);
      boite(scene, kb.x + kb.w / 2, v[0] + 0.025, kb.y + kb.h / 2, kb.w, 0.05, kb.h, '#3b4250');
      var ec = st.plan.rectFrac(o, ECRAN[o.r || 0][0], ECRAN[o.r || 0][1], ECRAN[o.r || 0][2], ECRAN[o.r || 0][3]);
      var ex = ec.x + ec.w / 2, ez = ec.y + ec.h / 2;
      boite(scene, ex, v[0] + 0.25, ez, ec.w, 0.5, ec.h, '#d9dee4');
      // écran : sur la face tournée vers l'élève (vers le centre de la place)
      var dx = cx - ex, dz = cz - ez;
      if (Math.abs(dx) >= Math.abs(dz)) {
        var sx = dx > 0 ? 1 : -1;
        boite(scene, ex + sx * (ec.w / 2 + 0.01), v[0] + 0.3, ez, 0.02, 0.34, ec.h * 0.8, '#173a5e');
      } else {
        var sz = dz > 0 ? 1 : -1;
        boite(scene, ex, v[0] + 0.3, ez + sz * (ec.h / 2 + 0.01), ec.w * 0.8, 0.34, 0.02, '#173a5e');
      }
      return null;
    }
    if (o.type === 'table') return table(scene, r, v[1], '#c98f56', '#3a3d46');
    if (o.type === 'bureau') return table(scene, r, v[1], '#a8683a', '#33261d');
    if (o.type === 'paillasse') return paillasse(scene, r, v[1]);
    if (o.type === 'armoire') return armoire(scene, r, v[1]);
    if (o.type === 'tableau') return tableau(scene, r, v[0], v[1]);
    if (o.type === 'porte') return porte(scene, r, v[0], v[1]);
    if (o.type === 'fenetre') return fenetre(scene, r, v[0], v[1]);
    boite(scene, cx, (v[0] + v[1]) / 2, cz, r.w, v[1] - v[0], r.h, couleur);
    return null;
  }

  var ECHELLE_CARTON = 0.0085;   // unités 3D par pixel des petits cartons
  /** Petits cartons, un par observation (jaune) ou récompense (vert). */
  function carteCartons(types) {
    var cv = document.createElement('canvas'), g = cv.getContext('2d');
    var montres = types.slice(0, 5), reste = types.length - montres.length;
    cv.width = montres.length * 30 + 12 + (reste ? 40 : 0); cv.height = 40;
    montres.forEach(function (t, i) {
      var x = 6 + i * 30;
      if (t === 'recompense') {   // médaille verte : ruban rouge en dessous
        g.fillStyle = '#c0392b';
        g.fillRect(x + 4, 22, 7, 15); g.fillRect(x + 13, 22, 7, 15);
        g.beginPath(); g.arc(x + 12, 15, 11, 0, Math.PI * 2);
        g.fillStyle = '#2e8b57'; g.fill(); g.strokeStyle = '#ffffff'; g.lineWidth = 3; g.stroke();
      } else {   // carton jaune
        g.fillStyle = '#f2b705';
        g.fillRect(x, 3, 24, 34);
        g.strokeStyle = '#ffffff'; g.lineWidth = 3; g.strokeRect(x, 3, 24, 34);
      }
    });
    if (reste) {
      var x0 = 6 + montres.length * 30;
      g.fillStyle = '#ffffff'; g.fillRect(x0, 3, 34, 34);
      g.fillStyle = '#1d1f24'; g.font = '700 22px system-ui, sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
      g.fillText('+' + reste, x0 + 17, 21);
    }
    return { tex: texture(cv, 1, 1), w: cv.width * ECHELLE_CARTON, h: cv.height * ECHELLE_CARTON };
  }

  /** Étiquette d'une place : deux cartes superposées, une au repos (petite) et une au survol (grande), en fondu croisé. */
  function etiquettePlace(scene, st, item) {
    var o = item.obj, r = item.rect, visage = item.visage;
    if (!visage && !item.label) return;
    function sprite(carte, cible) {
      var s = new THREE.Sprite(new THREE.SpriteMaterial({ map: carte.tex, transparent: true, depthTest: false, toneMapped: false }));
      s.userData.placeId = o.id;
      scene.add(s);
      if (cible) st.pick.push(s);   // seule la petite étiquette est détectée par la souris
      return s;
    }
    var etiquette = {
      x: r.x + r.w / 2, z: r.y + r.h / 2, p: 0, cible: 0,
      repos: carteEtiquette(visage, item.label, st.opts.quand, false),
      grand: carteEtiquette(visage, item.label, st.opts.quand, true),
    };
    etiquette.depart = etiquette.repos.w / etiquette.grand.w;
    etiquette.sRepos = sprite(etiquette.repos, true);
    etiquette.sGrand = sprite(etiquette.grand, false);
    if (item.cartons && item.cartons.length) {   // petits cartons au-dessus de l'étiquette
      var cartes = carteCartons(item.cartons);
      etiquette.cartons = cartes;
      etiquette.sCartons = sprite(cartes, false);
      etiquette.sCartons.scale.set(cartes.w, cartes.h, 1);
    }
    st.labels[o.id] = etiquette;
    appliquer(etiquette);
  }

  /** Bas de l'étiquette posé sur le tabouret. p : 0 au repos, 1 au survol. */
  var BAS_ETIQUETTE = 0.5;
  function appliquer(l) {
    var echelle = l.depart + (1 - l.depart) * l.p;   // la carte survolée part de la taille du repos et grandit
    var g = l.grand, r = l.repos;
    l.sGrand.scale.set(g.w * echelle, g.h * echelle, 1);
    l.sGrand.position.set(l.x, BAS_ETIQUETTE + g.h * echelle / 2, l.z);
    l.sGrand.material.opacity = 1;
    l.sGrand.renderOrder = l.p > 0 ? 10 : 0;   // la carte survolée passe devant les autres
    l.sGrand.visible = l.p > 0.01;
    l.sRepos.scale.set(r.w, r.h, 1);
    l.sRepos.position.set(l.x, BAS_ETIQUETTE + r.h / 2, l.z);
    l.sRepos.renderOrder = 1;
    l.sRepos.visible = l.p < 0.01;   // l'étiquette de repos disparaît dès le survol
    if (l.sCartons) {   // les cartons suivent le haut de l'étiquette affichée
      var hauteur = l.p > 0.01 ? g.h * echelle : r.h;
      l.sCartons.position.set(l.x, BAS_ETIQUETTE + hauteur + l.cartons.h / 2 + 0.03, l.z);
      l.sCartons.renderOrder = l.p > 0.01 ? 12 : 2;   // cartons de l'élève survolé devant sa photo ; ceux des autres derrière la carte survolée (10)
    }
  }

  /** Le survol fait grandir l'étiquette sous le pointeur, en douceur (une animation à la fois). */
  function mettreEnValeur(st) {
    Object.keys(st.labels).forEach(function (k) { st.labels[k].cible = k === st.survol ? 1 : 0; });
    animer(st);
  }
  function animer(st) {
    if (st.animation) return;
    st.animation = requestAnimationFrame(function pas() {
      var encore = false;
      Object.keys(st.labels).forEach(function (k) {
        var l = st.labels[k];
        l.p += (l.cible - l.p) * 0.3;
        if (Math.abs(l.cible - l.p) < 0.01) l.p = l.cible; else encore = true;
        appliquer(l);
      });
      (st.panneaux || []).forEach(function (m) {
        var u = m.userData;
        u.p += (u.cible - u.p) * 0.15;
        if (Math.abs(u.cible - u.p) < 0.01) u.p = u.cible; else encore = true;
        m.material.opacity = u.p; m.visible = u.p > 0;
      });
      st.renderer.render(st.scene, st.camera);
      st.animation = encore ? requestAnimationFrame(pas) : null;
    });
  }

  /* ---------- Décor extérieur : posé sur le même plan que le sol de la salle (y = 0) ---------- */

  /** Ambiances de la vue : couleurs du sol, de la cour et du feuillage, selon la saison ou la météo. 'neutre' : pas de décor. */
  var AMBIANCES = {
    printemps: { sol: '#9ccf7e', clair: '#b5e096', cour: '#6d7278', feuillage: ['#8fd08a', '#b8e3a0'], nuage: '#ffffff', petales: ['#f4a6bd', '#f7c6d4', '#ffffff'], anim: ['oiseaux', 'petales', 'nuages'], vent: 0.03 },
    ete: { sol: '#6fb35a', clair: '#86c76c', cour: '#5d6168', feuillage: ['#3f8f3a', '#4f9f45'], nuage: '#ffffff', anim: ['oiseaux', 'nuages', 'soleil'], vent: 0.025 },
    automne: { sol: '#b38a45', clair: '#c99d55', cour: '#5d6168', feuillage: ['#d2682a', '#e0a23a', '#a8432a'], feuilles: ['#d2682a', '#e0a23a', '#a8432a', '#8c3b1f'], nuage: '#d9cdb8', anim: ['feuilles', 'nuages', 'traits'], vent: 0.05 },
    hiver: { sol: '#eef4f9', clair: '#ffffff', cour: '#d6dde5', feuillage: ['#f4f8fb', '#e6eef5'], toit: '#eef3f7', nuage: '#e6ecf2', anim: ['neige', 'nuages', 'traits'], vent: 0.03 },
    pluie: { sol: '#5f7a63', clair: '#6e8c70', cour: '#4b5157', feuillage: ['#3c6b45', '#44764c'], mur: '#b9b2a4', nuage: '#7d8798', flaques: true, lumiere: 0.8, anim: ['pluie', 'nuages'], vent: 0.02 },
    orage: { sol: '#4a5d52', clair: '#56705c', cour: '#3c4046', feuillage: ['#2f5139', '#365c40'], mur: '#7d8590', nuage: '#4b5466', flaques: true, lumiere: 0.6, anim: ['pluie', 'nuages', 'eclair'], vent: 0.06 },
    nuit: { sol: '#253047', clair: '#2e3b57', cour: '#2a2f3a', feuillage: ['#1d3a3a', '#24454a'], mur: '#3c4660', toit: '#2a2f3d', lumiere: 0.35, nuit: true, anim: ['etoiles', 'lune'], vent: 0.02 },
    classique: { sol: '#8fc77a', clair: '#a6d68f', cour: '#5d6168', feuillage: ['#4f9a4a', '#5fa85a'], anim: ['oiseaux'], vent: 0.025 }
  };
  var ambianceCourante = 'neutre';

  /** Ambiance à dessiner : 'auto' prend la saison du calendrier (la nuit si l'heure est tardive). null = pas de décor. */
  function ambianceDe(nom) {
    if (!nom || nom === 'neutre') return null;
    if (nom !== 'auto') return AMBIANCES[nom] || null;
    var date = new Date(), m = date.getMonth(), h = date.getHours();
    var saison = (m >= 2 && m <= 4) ? 'printemps' : (m >= 5 && m <= 7) ? 'ete' : (m >= 8 && m <= 10) ? 'automne' : 'hiver';
    var nuit = h >= 19 || h < 7;
    return Object.assign({}, AMBIANCES[saison], nuit ? { lumiere: 0.5, nuit: true } : {});
  }

  /** Sol en aplat avec un grain : herbe, asphalte ou neige. */
  function texTerrain(couleur, clair, repet) {
    var cv = document.createElement('canvas'); cv.width = cv.height = 256;
    var g = cv.getContext('2d'), alea = generateur(11);
    g.fillStyle = couleur; g.fillRect(0, 0, 256, 256);
    for (var i = 0; i < 1400; i++) {
      g.fillStyle = alea() < 0.5 ? clair : 'rgba(0, 0, 0, 0.07)';
      g.fillRect(alea() * 256, alea() * 256, 2, 2);
    }
    return texture(cv, repet, repet);
  }

  /** Surface plate posée sur le sol, à la hauteur y (0 = niveau du sol de la salle). */
  function surface(scene, x, z, w, d, materiau, y) {
    var m = new THREE.Mesh(new THREE.PlaneGeometry(w, d), materiau);
    m.rotation.x = -Math.PI / 2;
    m.position.set(x, y === undefined ? 0.004 : y, z);
    m.receiveShadow = true;
    scene.add(m);
    return m;
  }

  /** Façade : grille de fenêtres sur une tuile de 3 m ; la nuit, certaines fenêtres sont éclairées. */
  function texFacade(mur, vitre, nuit) {
    var cv = document.createElement('canvas'); cv.width = cv.height = 256;
    var g = cv.getContext('2d'), alea = generateur(3);
    g.fillStyle = mur; g.fillRect(0, 0, 256, 256);
    [[36, 40], [140, 40], [36, 150], [140, 150]].forEach(function (p) {
      g.fillStyle = nuit && alea() < 0.45 ? '#ffd97a' : vitre;
      g.fillRect(p[0], p[1], 80, 70);
      g.fillStyle = '#e9e4da';
      g.fillRect(p[0] + 38, p[1], 4, 70);
    });
    return cv;
  }

  /** Ajoute l'objet aux éléments qui s'estompent quand ils passent entre la caméra et la salle. */
  function ajouterOccluder(st, obj) {
    var mats = [];
    obj.traverse(function (o) {
      if (!o.material) return;
      (Array.isArray(o.material) ? o.material : [o.material]).forEach(function (m) { m.transparent = true; mats.push(m); });
    });
    st.occluders.push({ obj: obj, mats: mats });
  }

  /** Toit à deux pentes, faîtage selon z. */
  function toitPente(groupe, x0, x1, z0, z1, hBase, hauteur, couleur) {
    var a = (x1 - x0) / 2, ang = Math.atan2(hauteur, a), long = Math.hypot(a, hauteur) + 0.3, zc = (z0 + z1) / 2, d = z1 - z0 + 0.4;
    var mat = new THREE.MeshLambertMaterial({ color: couleur });
    [[1, -ang], [-1, ang]].forEach(function (s) {
      var pan = new THREE.Mesh(new THREE.BoxGeometry(long, 0.15, d), mat);
      pan.position.set((x0 + x1) / 2 + s[0] * a / 2, hBase + hauteur / 2, zc);
      pan.rotation.z = s[1];
      pan.castShadow = true; pan.receiveShadow = true;
      groupe.add(pan);
    });
  }

  /** Bâtiment à façades vitrées ; toit plat (avec unité technique) ou à deux pentes si pente > 0. */
  function batiment(st, scene, x0, x1, z0, z1, h, B, pente) {
    var w = x1 - x0, d = z1 - z0, groupe = new THREE.Group();
    var cv = texFacade(B.mur, B.vitre, B.nuit);
    var facadeX = new THREE.MeshLambertMaterial({ map: texture(cv, d / 3, h / 3) });
    var facadeZ = new THREE.MeshLambertMaterial({ map: texture(cv, w / 3, h / 3) });
    var toit = new THREE.MeshLambertMaterial({ color: B.toit });
    var corps = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), [facadeX, facadeX, toit, toit, facadeZ, facadeZ]);
    corps.position.set((x0 + x1) / 2, h / 2, (z0 + z1) / 2);
    corps.castShadow = true; corps.receiveShadow = true;
    groupe.add(corps);
    if (pente) toitPente(groupe, x0, x1, z0, z1, h, pente, B.toit);
    else boite(groupe, x0 + w * 0.3, h + 0.5, (z0 + z1) / 2, w * 0.2, 1, d * 0.3, '#c9c2b5');
    scene.add(groupe);
    ajouterOccluder(st, groupe);
  }

  /** Arbre : tronc et feuillage à facettes ; le feuillage pivote au vent (phase décale les arbres entre eux). */
  function arbre(st, scene, x, z, h, couleur, phase) {
    var groupe = new THREE.Group();
    var tronc = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.2, h * 0.45, 8), new THREE.MeshLambertMaterial({ color: '#7a5230' }));
    tronc.position.set(x, h * 0.225, z);
    tronc.castShadow = true;
    groupe.add(tronc);
    var feuillage = new THREE.Group();
    feuillage.position.set(x, h * 0.45, z);
    var mat = new THREE.MeshLambertMaterial({ color: couleur, flatShading: true });
    [[0, 0.62, 0, 0.36], [0.18, 0.5, 0.1, 0.27], [-0.16, 0.55, -0.12, 0.27]].forEach(function (b) {
      var m = new THREE.Mesh(new THREE.IcosahedronGeometry(b[3] * h, 0), mat);
      m.position.set(b[0] * h, (b[1] - 0.45) * h, b[2] * h);
      m.castShadow = true; m.receiveShadow = true;
      feuillage.add(m);
    });
    groupe.add(feuillage);
    scene.add(groupe);
    ajouterOccluder(st, groupe);
    st.anim.arbres.push({ g: feuillage, phase: phase });
  }

  /** Nuage : trois volumes à facettes, qui dérivent lentement. */
  function nuage3d(st, scene, x, y, z, couleur, s) {
    var groupe = new THREE.Group(), mat = new THREE.MeshLambertMaterial({ color: couleur, flatShading: true });
    [[0, 0, 0, 1.6], [1.5, -0.2, 0.3, 1.2], [-1.4, -0.1, -0.2, 1.1]].forEach(function (b) {
      var m = new THREE.Mesh(new THREE.IcosahedronGeometry(b[3] * s, 0), mat);
      m.position.set(b[0] * s, b[1] * s, b[2] * s);
      groupe.add(m);
    });
    groupe.position.set(x, y, z);
    scene.add(groupe);
    ajouterOccluder(st, groupe);
    return groupe;
  }

  /** Astre (lune, soleil) : disque sans éclairage, dans le ciel. */
  function astre3d(st, scene, x, y, z, r, couleur) {
    var m = new THREE.Mesh(new THREE.SphereGeometry(r, 20, 14), new THREE.MeshBasicMaterial({ color: couleur }));
    m.position.set(x, y, z);
    scene.add(m);
    ajouterOccluder(st, m);
  }

  /** Étoiles fixes sur une voûte autour de la salle. */
  function etoiles3d(scene, n, cx, cz) {
    var rnd = generateur(91), pos = new Float32Array(n * 3);
    for (var i = 0; i < n; i++) {
      var th = rnd() * Math.PI * 2;
      pos[i * 3] = cx + 70 * Math.cos(th); pos[i * 3 + 1] = 25 + rnd() * 20; pos[i * 3 + 2] = cz + 70 * Math.sin(th);
    }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    scene.add(new THREE.Points(geo, new THREE.PointsMaterial({ color: '#ffffff', size: 2, sizeAttenuation: false })));
  }

  /** Oiseau : corps et deux ailes qui battent ; il tourne en cercle au-dessus de la salle. */
  function oiseau3d(st, scene, cx, cz, rayon, hauteur, vitesse, phase) {
    var groupe = new THREE.Group(), mat = new THREE.MeshLambertMaterial({ color: '#2b2f36' });
    groupe.add(new THREE.Mesh(new THREE.BoxGeometry(0.3, 0.08, 0.12), mat));
    var ailes = [-1, 1].map(function (s) {
      var pivot = new THREE.Group();
      var aile = new THREE.Mesh(new THREE.BoxGeometry(0.75, 0.02, 0.16), mat);
      aile.position.x = s * 0.37;
      pivot.add(aile);
      groupe.add(pivot);
      return { pivot: pivot, s: s };
    });
    scene.add(groupe);
    st.anim.oiseaux.push({ g: groupe, ailes: ailes, cx: cx, cz: cz, rayon: rayon, hauteur: hauteur, vitesse: vitesse, phase: phase });
  }

  /** Neige qui tombe et dérive avec le vent, dans le volume autour de la salle. */
  function neige3d(st, scene, n) {
    var v = st.anim.volume, rnd = generateur(21), pos = new Float32Array(n * 3), vit = new Float32Array(n);
    for (var i = 0; i < n; i++) {
      pos[i * 3] = v.x0 + rnd() * (v.x1 - v.x0); pos[i * 3 + 1] = v.y0 + rnd() * (v.y1 - v.y0); pos[i * 3 + 2] = v.z0 + rnd() * (v.z1 - v.z0);
      vit[i] = 0.6 + rnd() * 0.6;
    }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    scene.add(new THREE.Points(geo, new THREE.PointsMaterial({ color: '#ffffff', size: 3, sizeAttenuation: false, transparent: true, opacity: 0.9, depthWrite: false })));
    st.anim.particules.push(function (dt, t) {
      for (var i = 0; i < n; i++) {
        pos[i * 3 + 1] -= vit[i] * dt;
        pos[i * 3] += (st.anim.vent * 25 + Math.sin(t + i) * 0.3) * dt;
        if (pos[i * 3 + 1] < v.y0) {
          pos[i * 3 + 1] = v.y1; pos[i * 3] = v.x0 + rnd() * (v.x1 - v.x0); pos[i * 3 + 2] = v.z0 + rnd() * (v.z1 - v.z0);
        }
      }
      geo.attributes.position.needsUpdate = true;
    });
  }

  /** Pluie : traits qui tombent vite, inclinés par le vent. */
  function pluie3d(st, scene, n) {
    var v = st.anim.volume, rnd = generateur(37), pos = new Float32Array(n * 6), vit = new Float32Array(n), LONG = 0.6;
    function placer(i, haut) {
      var x = v.x0 + rnd() * (v.x1 - v.x0), z = v.z0 + rnd() * (v.z1 - v.z0), y = haut ? v.y1 : v.y0 + rnd() * (v.y1 - v.y0);
      pos.set([x, y, z, x, y - LONG, z], i * 6);
    }
    for (var i = 0; i < n; i++) { placer(i, false); vit[i] = 14 + rnd() * 6; }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    scene.add(new THREE.LineSegments(geo, new THREE.LineBasicMaterial({ color: '#c7dcf2', transparent: true, opacity: 0.5, depthWrite: false })));
    st.anim.particules.push(function (dt) {
      for (var i = 0; i < n; i++) {
        var y = pos[i * 6 + 1] - vit[i] * dt;
        if (y < v.y0) { placer(i, true); continue; }
        var x = pos[i * 6] + st.anim.vent * 20 * dt, z = pos[i * 6 + 2];
        pos.set([x, y, z, x, y - LONG, z], i * 6);
      }
      geo.attributes.position.needsUpdate = true;
    });
  }

  /** Traînées de vent : traits horizontaux qui filent à travers le décor. */
  function traits3d(st, scene, n) {
    var v = st.anim.volume, rnd = generateur(53), pos = new Float32Array(n * 6), LONG = 2.2;
    function placer(i, partout) {
      var x = partout ? v.x0 + rnd() * (v.x1 - v.x0) : v.x0;
      var y = v.y0 + 1 + rnd() * (v.y1 - v.y0 - 2), z = v.z0 + rnd() * (v.z1 - v.z0);
      pos.set([x, y, z, x + LONG, y, z], i * 6);
    }
    for (var i = 0; i < n; i++) placer(i, true);
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    scene.add(new THREE.LineSegments(geo, new THREE.LineBasicMaterial({ color: '#ffffff', transparent: true, opacity: 0.35, depthWrite: false })));
    st.anim.particules.push(function (dt) {
      var vitesse = st.anim.vent * 40 + 4;
      for (var i = 0; i < n; i++) {
        var x = pos[i * 6] + vitesse * dt;
        if (x > v.x1) placer(i, false);
        else pos.set([x, pos[i * 6 + 1], pos[i * 6 + 2], x + LONG, pos[i * 6 + 1], pos[i * 6 + 2]], i * 6);
      }
      geo.attributes.position.needsUpdate = true;
    });
  }

  /** Feuilles ou pétales qui tombent en tournoyant, dérivant avec le vent. */
  function feuilles3d(st, scene, n, couleurs) {
    var v = st.anim.volume, rnd = generateur(71), liste = [], geo = new THREE.PlaneGeometry(0.26, 0.18);
    for (var i = 0; i < n; i++) {
      var m = new THREE.Mesh(geo, new THREE.MeshLambertMaterial({ color: couleurs[i % couleurs.length], side: THREE.DoubleSide }));
      m.position.set(v.x0 + rnd() * (v.x1 - v.x0), v.y0 + rnd() * (v.y1 - v.y0), v.z0 + rnd() * (v.z1 - v.z0));
      scene.add(m);
      liste.push({ m: m, vy: 0.9 + rnd() * 0.8, rx: 1 + rnd() * 2, rz: 1 + rnd() * 2, ph: rnd() * 6.28 });
    }
    st.anim.particules.push(function (dt, t) {
      liste.forEach(function (f) {
        f.m.position.y -= f.vy * dt;
        f.m.position.x += (st.anim.vent * 30 + Math.sin(t * 1.3 + f.ph) * 0.8) * dt;
        f.m.position.z += Math.cos(t * 0.9 + f.ph) * 0.3 * dt;
        f.m.rotation.x += f.rx * dt; f.m.rotation.z += f.rz * dt;
        if (f.m.position.y < v.y0) {
          f.m.position.set(v.x0 + rnd() * (v.x1 - v.x0), v.y1, v.z0 + rnd() * (v.z1 - v.z0));
        }
      });
    });
  }

  /** Décor complet : terrain, cour d'école, trottoir et arrêt de bus, bâtiments du collège, arbres, puis les animations de l'ambiance. */
  function decor(st, scene, L, H, amb) {
    var a = st.anim, x0 = L + 3, x1 = L + 15, z0 = -3, z1 = H - 2, cx = (x0 + x1) / 2, cz = (z0 + z1) / 2;
    a.actif = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) && amb.anim.length > 0;
    a.vent = amb.vent || 0.03;
    a.volume = { x0: -18, x1: L + 24, y0: 0.3, y1: 14, z0: -24, z1: H + 20 };
    surface(scene, L / 2, H / 2, 160, 160, new THREE.MeshLambertMaterial({ map: texTerrain(amb.sol, amb.clair, 40) }), 0);
    surface(scene, cx, cz, x1 - x0, z1 - z0, new THREE.MeshLambertMaterial({ map: texTerrain(amb.cour, 'rgba(255, 255, 255, 0.12)', 6) }), 0.002);
    var blanc = new THREE.MeshLambertMaterial({ color: '#f5f7f9' });
    [[cx, z0 + 0.05, x1 - x0, 0.1], [cx, z1 - 0.05, x1 - x0, 0.1], [x0 + 0.05, cz, 0.1, z1 - z0], [x1 - 0.05, cz, 0.1, z1 - z0], [cx, cz, 0.1, z1 - z0]]
      .forEach(function (l) { surface(scene, l[0], l[1], l[2], l[3], blanc, 0.006); });
    but(scene, cx, z0 + 0.6);
    but(scene, cx, z1 - 0.6);
    surface(scene, L / 2, H + 3.2, L + 30, 2.2, new THREE.MeshLambertMaterial({ color: '#cfc8bd' }), 0.003);
    var bus = new THREE.Group();
    arretBus(bus, 2.2, H + 3.2);
    scene.add(bus);
    ajouterOccluder(st, bus);

    var B = { mur: amb.mur || '#e8dccb', toit: amb.toit || '#9a4a32', vitre: '#3e5a73', nuit: !!amb.nuit };
    batiment(st, scene, -8, L + 14, -18, -9, 9.5, B, 0);                  // bâtiment principal, 3 étages
    batiment(st, scene, L / 2 - 2.2, L / 2 + 2.2, -21, -18, 4.5, B, 2);   // entrée
    batiment(st, scene, -15, -7, -8, 2, 6, B, 2.5);                       // gymnase
    batiment(st, scene, L + 18, L + 26, H - 13, H - 3, 5, B, 0);          // annexe
    batiment(st, scene, -6, 8, H + 10, H + 15, 4, B, 0);                  // bâtiment sportif, côté avant
    batiment(st, scene, -25.5, -20.5, -17, -11, 3.2, B, 2.4);             // pavillons
    batiment(st, scene, -25.5, -20.5, -6, 0, 3.2, B, 2.4);
    // enseigne « COLLÈGE » sur la façade du bâtiment principal, tournée vers la salle
    var enseigne = document.createElement('canvas'); enseigne.width = 512; enseigne.height = 128;
    var ge = enseigne.getContext('2d');
    ge.fillStyle = '#1f5f8b'; ge.fillRect(0, 0, 512, 128);
    ge.fillStyle = '#ffffff'; ge.font = 'bold 84px system-ui, sans-serif'; ge.textAlign = 'center'; ge.textBaseline = 'middle';
    ge.fillText('COLLÈGE', 256, 66);
    var panneau = new THREE.Mesh(new THREE.PlaneGeometry(6, 1.5), new THREE.MeshLambertMaterial({ map: texture(enseigne, 1, 1) }));
    panneau.position.set(L / 2, 8, -8.95);
    scene.add(panneau);
    ajouterOccluder(st, panneau);

    // arbres : quelques-uns près des bâtiments, puis un anneau plus loin ; jamais dans une zone construite
    var zones = [[-8, L + 14, -21, -9], [-15, -7, -8, 2], [L + 18, L + 26, H - 13, H - 3], [-6, 8, H + 10, H + 15],
      [-25.5, -20.5, -17, -11], [-25.5, -20.5, -6, 0], [x0 - 1, x1 + 1, z0 - 1, z1 + 1], [-16, L + 16, H + 1.5, H + 5], [-1.5, L + 1.5, -1.5, H + 1.5]];
    var positions = [[-5, -4], [L * 0.3, -6], [L + 18, -3], [L + 19, H + 6], [-6, H * 0.4], [L * 0.7, H + 8], [-4, -8], [L + 7, -9], [-9, H + 7]];
    for (var k = 0; k < 16; k++) {
      var angle = k * Math.PI / 8 + 0.2, R = Math.max(L, H) / 2 + 14 + (k % 2) * 3;
      positions.push([L / 2 + R * Math.cos(angle), H / 2 + R * Math.sin(angle)]);
    }
    positions.filter(function (p) {
      return !zones.some(function (z) { return p[0] > z[0] && p[0] < z[1] && p[1] > z[2] && p[1] < z[3]; });
    }).forEach(function (p, i) {
      arbre(st, scene, p[0], p[1], 5.2 + (i % 3) * 0.9, amb.feuillage[i % amb.feuillage.length], i * 1.7);
    });

    if (amb.flaques) {
      var flaque = new THREE.MeshPhongMaterial({ color: '#2c3a4a', specular: '#9fb8d0', shininess: 140, transparent: true, opacity: 0.8 });
      [[L + 8, 2, 0.9], [L + 11, H - 4, 0.6], [-3, 1, 1.1], [L * 0.5, -6, 0.8]].forEach(function (f) {
        var disque = new THREE.Mesh(new THREE.CircleGeometry(f[2], 20), flaque);
        disque.rotation.x = -Math.PI / 2;
        disque.position.set(f[0], 0.005, f[1]);
        disque.receiveShadow = true;
        scene.add(disque);
        a.flaques.push(disque);
      });
    }

    // ciel et animations propres à l'ambiance
    var anim = amb.anim;
    if (anim.indexOf('nuages') >= 0) {
      for (var n = 0; n < 5; n++) {
        var th = n * 1.26 + 0.4, Rn = Math.max(L, H) / 2 + 26 + (n % 2) * 6;
        a.nuages.push({ g: nuage3d(st, scene, L / 2 + Rn * Math.cos(th), 15 + (n % 3) * 2, H / 2 + Rn * Math.sin(th), amb.nuage || '#ffffff', 2.2), v: 0.25 + n * 0.08, debut: -40, fin: L + 40 });
      }
    }
    if (anim.indexOf('oiseaux') >= 0) {
      for (var o = 0; o < 3; o++) oiseau3d(st, scene, L / 2, H / 2, 20 + o * 4, 9 + o * 1.5, 0.22 - o * 0.03, o * 2.1);
    }
    if (anim.indexOf('lune') >= 0) astre3d(st, scene, -22, 22, -28, 1.3, '#f5f2dc');
    if (anim.indexOf('soleil') >= 0) astre3d(st, scene, -24, 20, -30, 2.4, '#ffe27a');
    if (anim.indexOf('etoiles') >= 0) etoiles3d(scene, 140, L / 2, H / 2);
    if (anim.indexOf('neige') >= 0) neige3d(st, scene, 320);
    if (anim.indexOf('pluie') >= 0) pluie3d(st, scene, 260);
    if (anim.indexOf('traits') >= 0) traits3d(st, scene, 36);
    if (anim.indexOf('petales') >= 0) feuilles3d(st, scene, 26, amb.petales || ['#f4a6bd', '#f7c6d4']);
    if (anim.indexOf('feuilles') >= 0) feuilles3d(st, scene, 26, amb.feuilles || amb.feuillage);
    a.eclair = anim.indexOf('eclair') >= 0;
  }

  /** Rectangle écran (coordonnées normalisées) d'une boîte 3D vue par la caméra. */
  function ecranBoite(cadre, cam) {
    var r = { minX: Infinity, maxX: -Infinity, minY: Infinity, maxY: -Infinity };
    [cadre.min.x, cadre.max.x].forEach(function (x) {
      [cadre.min.y, cadre.max.y].forEach(function (y) {
        [cadre.min.z, cadre.max.z].forEach(function (z) {
          var p = new THREE.Vector3(x, y, z).project(cam);
          r.minX = Math.min(r.minX, p.x); r.maxX = Math.max(r.maxX, p.x);
          r.minY = Math.min(r.minY, p.y); r.maxY = Math.max(r.maxY, p.y);
        });
      });
    });
    return r;
  }

  /** Estompe les éléments du décor qui se trouvent entre la caméra et la salle : la salle reste entièrement visible. */
  function masquer(st) {
    if (!st.occluders || !st.occluders.length) return;
    var cam = st.camera, L = st.plan.L, H = st.plan.H;
    cam.updateMatrixWorld(true);
    var salle = ecranBoite(new THREE.Box3(new THREE.Vector3(0, 0, 0), new THREE.Vector3(L, MURS, H)), cam);
    var centre = new THREE.Vector3(L / 2, MURS / 2, H / 2);
    var dir = centre.clone().sub(cam.position).normalize();
    var profondeurSalle = centre.clone().sub(cam.position).dot(dir);
    st.occluders.forEach(function (o) {
      var cadre = new THREE.Box3().setFromObject(o.obj);
      if (cadre.isEmpty()) return;
      var devant = cadre.getCenter(new THREE.Vector3()).sub(cam.position).dot(dir) < profondeurSalle - 1;
      var ecran = ecranBoite(cadre, cam);
      var recouvre = ecran.minX < salle.maxX && ecran.maxX > salle.minX && ecran.minY < salle.maxY && ecran.maxY > salle.minY;
      var opacite = devant && recouvre ? 0.2 : 1;
      o.mats.forEach(function (m) { m.opacity = opacite; m.depthWrite = opacite === 1; });
    });
  }

  /** Animation du décor : arbres au vent, oiseaux, particules, flaques, éclairs. */
  function animerDecor(st, t, dt) {
    var a = st.anim;
    a.arbres.forEach(function (x) {
      x.g.rotation.z = Math.sin(t * 0.9 + x.phase) * a.vent;
      x.g.rotation.x = Math.cos(t * 0.7 + x.phase) * a.vent * 0.5;
    });
    a.particules.forEach(function (f) { f(dt, t); });
    a.oiseaux.forEach(function (o) {
      var th = o.phase + t * o.vitesse, px = o.cx + o.rayon * Math.cos(th), pz = o.cz + o.rayon * Math.sin(th);
      o.g.position.set(px, o.hauteur + Math.sin(t * 0.5 + o.phase) * 0.4, pz);
      o.g.lookAt(px - Math.sin(th), o.hauteur, pz + Math.cos(th));
      var battement = Math.sin(t * 9 + o.phase) * 0.5;
      o.ailes.forEach(function (aile) { aile.pivot.rotation.z = -aile.s * battement; });
    });
    a.nuages.forEach(function (n) {
      n.g.position.x += n.v * dt;
      if (n.g.position.x > n.fin) n.g.position.x = n.debut;
    });
    a.flaques.forEach(function (m, i) { var s = 1 + 0.08 * Math.sin(t * 2 + i); m.scale.set(s, s, 1); });
    if (a.eclair) {
      var c = t % 7.5, k = c < 0.08 ? 3 : c < 0.16 ? 1 : c < 0.22 ? 2.2 : 1;
      a.lumieres.forEach(function (l) { l.l.intensity = l.base * k; });
    }
  }

  /** Fait tourner la boucle d'animation du décor tant que l'ambiance le demande ; une seule boucle par vue. */
  function demarrerDecor(st) {
    if (!st.anim.actif || st.boucle) return;
    st.boucle = true;
    requestAnimationFrame(function pas() {
      if (!st.anim.actif || !document.contains(st.renderer.domElement)) { st.boucle = false; return; }
      var t = performance.now() / 1000;
      var dt = Math.min(0.05, t - (st.anim.t0 || t));
      st.anim.t0 = t;
      animerDecor(st, t, dt);
      masquer(st);
      st.renderer.render(st.scene, st.camera);
      requestAnimationFrame(pas);
    });
  }

  /** Arrêt de bus : abribus vitré, banc et poteau d'information, sur le trottoir. */
  function arretBus(scene, x, z) {
    var verre = new THREE.MeshLambertMaterial({ color: '#bfe6f5', transparent: true, opacity: 0.45 });
    boite(scene, x, 2.3, z, 2.6, 0.1, 1.4, '#3d6f9c');
    [[-1.25, -0.6], [1.25, -0.6], [-1.25, 0.6], [1.25, 0.6]].forEach(function (p) {
      boite(scene, x + p[0], 1.15, z + p[1], 0.07, 2.3, 0.07, '#4d5a66');
    });
    var fond = new THREE.Mesh(new THREE.BoxGeometry(2.4, 1.6, 0.03), verre);
    fond.position.set(x, 1.25, z - 0.6);
    scene.add(fond);
    var cote = new THREE.Mesh(new THREE.BoxGeometry(0.03, 1.6, 1.2), verre);
    cote.position.set(x - 1.2, 1.25, z);
    scene.add(cote);
    boite(scene, x, 0.45, z + 0.25, 1.8, 0.08, 0.45, '#8a5a32');
    boite(scene, x + 1.6, 1.3, z, 0.06, 2.6, 0.06, '#4d5a66');
    boite(scene, x + 1.6, 2.4, z, 0.5, 0.5, 0.06, '#f2c14e');
  }

  /** Cage de but : deux poteaux et une barre, sur le bord de la cour. */
  function but(scene, x, z) {
    boite(scene, x - 1.2, 0.7, z, 0.08, 1.4, 0.08, '#f5f7f9');
    boite(scene, x + 1.2, 0.7, z, 0.08, 1.4, 0.08, '#f5f7f9');
    boite(scene, x, 1.4, z, 2.4, 0.08, 0.08, '#f5f7f9');
  }

  function construire(st, plan) {
    var scene = new THREE.Scene(), L = plan.L, H = plan.H;
    var amb = ambianceDe(ambianceCourante), lum = amb && amb.lumiere ? amb.lumiere : 1;
    st.pick = []; st.labels = {}; st.survol = null; st.plan = plan;
    st.occluders = []; st.anim = { actif: false, arbres: [], particules: [], oiseaux: [], flaques: [], nuages: [], lumieres: [], eclair: false, vent: 0, volume: null, t0: 0 };
    pal = PALETTES[(plan.palette || 0) % PALETTES.length];
    var hemi = new THREE.HemisphereLight(amb && amb.nuit ? 0x9fb4ff : 0xfff3dc, 0x9c8068, 0.85 * lum);
    scene.add(hemi);
    st.anim.lumieres.push({ l: hemi, base: 0.85 * lum });
    var soleil = new THREE.DirectionalLight(0xffe4b8, 1.0 * lum);
    st.anim.lumieres.push({ l: soleil, base: 1.0 * lum });
    soleil.position.set(L / 2 - 9, 16, H / 2 + 7);
    soleil.target.position.set(L / 2, 0, H / 2);
    soleil.castShadow = true;
    soleil.shadow.mapSize.set(2048, 2048);
    soleil.shadow.bias = -0.0006;
    soleil.shadow.normalBias = 0.02;
    var ombre = soleil.shadow.camera, marge = Math.max(L, H) * 0.8;
    ombre.left = -marge; ombre.right = marge; ombre.top = marge; ombre.bottom = -marge; ombre.near = 1; ombre.far = 60;
    scene.add(soleil);
    scene.add(soleil.target);
    var remplissage = new THREE.DirectionalLight(0xcfe2ff, 0.3 * lum);
    st.anim.lumieres.push({ l: remplissage, base: 0.3 * lum });
    remplissage.position.set(L / 2 + 10, 8, H / 2 - 8);
    remplissage.target.position.set(L / 2, 0, H / 2);
    scene.add(remplissage);
    scene.add(remplissage.target);
    // dalle et sol
    boite(scene, L / 2, -SOCLE / 2, H / 2, L, SOCLE, H, COULEURS.socle);
    var sol = new THREE.Mesh(new THREE.PlaneGeometry(L, H), new THREE.MeshLambertMaterial({ map: texture(texSol(plan.sol), L / 4, H / 4) }));
    sol.rotation.x = -Math.PI / 2;
    sol.position.set(L / 2, 0.001, H / 2);
    sol.receiveShadow = true;
    scene.add(sol);
    st.decorActif = !!amb;
    if (amb) decor(st, scene, L, H, amb);
    murs(scene, L, H);
    if (plan.etiquette) panneauxClasse(scene, st, L, H, plan.etiquette);
    plan.objets.forEach(function (item) { meuble(scene, item, st); });
    plan.objets.forEach(function (item) { if (item.obj.type === 'place') etiquettePlace(scene, st, item); });
    mettreEnValeur(st);
    demarrerDecor(st);
    return scene;
  }

  /** Cadre la caméra : rotation (azimut), hauteur (élévation) et zoom de l'utilisateur, sans perspective. */
  function cadrer(st, plan, w, h) {
    var L = plan.L, H = plan.H, aspect = w / h;
    var az = st.azimut * Math.PI / 180 + Math.PI / 4, e = st.elev * Math.PI / 180, D = 80;
    st.camera.position.set(L / 2 + D * Math.cos(e) * Math.sin(az), D * Math.sin(e), H / 2 + D * Math.cos(e) * Math.cos(az));
    st.camera.lookAt(L / 2, 0, H / 2);
    // la salle, murs compris, est centrée dans la vue ; le cadre s'élargit s'il faut la voir en entier (écran étroit)
    st.camera.updateMatrixWorld(true);
    var min = { x: Infinity, y: Infinity }, max = { x: -Infinity, y: -Infinity };
    [0, L].forEach(function (x) { [0, MURS].forEach(function (y) { [0, H].forEach(function (z) {
      var p = new THREE.Vector3(x, y, z).applyMatrix4(st.camera.matrixWorldInverse);
      min.x = Math.min(min.x, p.x); max.x = Math.max(max.x, p.x);
      min.y = Math.min(min.y, p.y); max.y = Math.max(max.y, p.y);
    }); }); });
    var demi = Math.max(0.46 * Math.hypot(L, H), (max.x - min.x) / (2 * aspect) * 1.06, (max.y - min.y) / 2 * 1.06);
    if (st.decorActif) demi *= 2;   // le décor (bâtiments, arbres) reste visible autour de la salle
    var decalX = (min.x + max.x) / 2, decalY = (min.y + max.y) / 2;
    st.camera.left = -demi * aspect + decalX; st.camera.right = demi * aspect + decalX;
    st.camera.top = demi + decalY; st.camera.bottom = -demi + decalY;
    st.camera.zoom = st.zoom;
    st.camera.near = 0.1; st.camera.far = 400;
    st.camera.updateProjectionMatrix();
    panneauFace(st);
  }

  function pointeur(st, ev) {
    var r = st.renderer.domElement.getBoundingClientRect();
    var ndc = new THREE.Vector2(((ev.clientX - r.left) / r.width) * 2 - 1, -((ev.clientY - r.top) / r.height) * 2 + 1);
    st.ray.setFromCamera(ndc, st.camera);
    var touches = st.ray.intersectObjects(st.pick, false);
    if (touches.length) return touches[0].object.userData.placeId;
    var l = st.survol && st.labels[st.survol];
    if (l && st.ray.intersectObject(l.sGrand, false).length) return st.survol;   // la carte agrandie garde le survol
    return null;
  }

  /** Dessine la salle en 3D dans le conteneur. Glisser : tourner ; molette : zoom ; double-clic : vue d'ensemble. */
  function rendre(conteneur, plan, opts) {
    var st = conteneur._trois;
    if (!st) {
      var renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
      renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
      renderer.setClearColor(0x000000, 0);
      renderer.shadowMap.enabled = true;
      renderer.shadowMap.type = THREE.PCFSoftShadowMap;
      renderer.toneMapping = THREE.ACESFilmicToneMapping;
      renderer.toneMappingExposure = 1.1;
      var canvas = renderer.domElement;
      canvas.style.width = '100%';
      canvas.style.height = 'auto';
      canvas.style.display = 'block';
      canvas.style.touchAction = 'none';
      canvas.style.outline = 'none';
      canvas.tabIndex = 0;   // reçoit la touche F
      conteneur.appendChild(canvas);
      st = conteneur._trois = {
        renderer: renderer, camera: new THREE.OrthographicCamera(-1, 1, 1, -1, 0.1, 400), ray: new THREE.Raycaster(),
        azimut: 0, elev: 34, zoom: 1, angleBase: null, glisse: null, deplacement: 0,
      };
      canvas.addEventListener('pointerdown', function (ev) {
        st.glisse = { x: ev.clientX, y: ev.clientY, az: st.azimut, el: st.elev };
        st.deplacement = 0;
        canvas.setPointerCapture(ev.pointerId);
        canvas.focus({ preventScroll: true });
      });
      canvas.addEventListener('pointermove', function (ev) {
        if (st.glisse) {
          var dx = ev.clientX - st.glisse.x, dy = ev.clientY - st.glisse.y;
          st.deplacement = Math.max(st.deplacement, Math.abs(dx) + Math.abs(dy));
          st.azimut = st.glisse.az - dx * 0.4;
          st.elev = Math.min(80, Math.max(12, st.glisse.el + dy * 0.3));
          rafraichir(conteneur);
          return;
        }
        var id = pointeur(st, ev);
        canvas.style.cursor = id ? 'pointer' : 'grab';
        if (id === st.survol) return;
        st.survol = id;
        mettreEnValeur(st);
        st.renderer.render(st.scene, st.camera);
      });
      canvas.addEventListener('pointerup', function () { st.glisse = null; });
      canvas.addEventListener('pointerleave', function () {
        st.survol = null;
        mettreEnValeur(st);
        st.renderer.render(st.scene, st.camera);
      });
      canvas.addEventListener('wheel', function (ev) {
        ev.preventDefault();
        st.zoom = Math.min(8, Math.max(0.6, st.zoom * Math.exp(-ev.deltaY * 0.0015)));
        rafraichir(conteneur);
      }, { passive: false });
      canvas.addEventListener('dblclick', function () {
        clearTimeout(st.tClic);
        recentrer(conteneur);
      });
      canvas.addEventListener('click', function (ev) {
        clearTimeout(st.tClic);
        if (st.deplacement > 5) return;   // un glissement n'est pas un clic
        var id = pointeur(st, ev);
        // le clic attend la fin d'un éventuel double-clic, qui recentre la vue au lieu d'ouvrir la fiche
        if (id && st.opts && st.opts.surClic) st.tClic = setTimeout(function () { st.opts.surClic(id); }, 250);
      });
      canvas.addEventListener('keydown', function (ev) {
        if (ev.key === 'f' || ev.key === 'F') { ev.preventDefault(); recentrer(conteneur); }
      });
    }
    st.opts = opts || {};
    st.plan = plan;
    // un nouveau réglage d'angle (curseur) remet la vue sur cet angle
    if (st.angleBase !== plan.angle) { st.angleBase = plan.angle; st.azimut = plan.angle || 0; }
    st.scene = construire(st, plan);
    rafraichir(conteneur);
  }

  /** Recentre la salle : vue d'origine (zoom, angle, hauteur), cadrée au centre. Touche F ou double-clic. */
  function recentrer(conteneur) {
    var st = conteneur._trois;
    st.zoom = 1; st.azimut = st.angleBase; st.elev = 34;
    rafraichir(conteneur);
  }

  /** Redessine avec la taille actuelle du conteneur et la caméra courante. */
  function rafraichir(conteneur) {
    var st = conteneur._trois;
    var w = Math.max(320, conteneur.clientWidth || 640);
    // tableau de bord (plan-pleine) : la vue remplit le cadre ; ailleurs, un format fixe
    var pleine = conteneur.classList.contains('plan-pleine') && conteneur.clientHeight > 0;
    var h = pleine ? Math.max(240, conteneur.clientHeight) : Math.round(w * 0.62);
    st.renderer.setSize(w, h, false);
    var canvas = st.renderer.domElement;
    canvas.style.width = pleine ? w + 'px' : '100%';
    canvas.style.height = pleine ? h + 'px' : 'auto';
    canvas.style.position = pleine ? 'absolute' : '';
    cadrer(st, st.plan, w, h);
    masquer(st);
    st.renderer.render(st.scene, st.camera);
  }

  // la salle suit la taille de la fenêtre
  window.addEventListener('resize', function () {
    document.querySelectorAll('.plan-trois').forEach(function (c) { if (c._trois) rafraichir(c); });
  });

  /** Change l'ambiance de toutes les vues 3D ouvertes (saison, météo) et les redessine. 'neutre' retire le décor. */
  function ambiance(nom) {
    ambianceCourante = nom || 'neutre';
    document.querySelectorAll('.plan-trois').forEach(function (c) {
      var st = c._trois;
      if (!st || !st.plan) return;
      st.scene = construire(st, st.plan);
      rafraichir(c);
    });
  }

  return { rendre: rendre, ambiance: ambiance };
})();
