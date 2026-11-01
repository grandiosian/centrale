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
    g.fillStyle = 'rgba(255,255,255,0.94)'; g.fillRect(2, haut, W - 4, barre);
    g.strokeStyle = '#1f5f8b'; g.lineWidth = 4; g.strokeRect(2, haut, W - 4, barre);
    g.textAlign = 'center'; g.textBaseline = 'middle';
    g.fillStyle = '#1d1f24'; g.font = '700 ' + fPrenom + 'px system-ui, sans-serif';
    g.fillText(mots.prenom, W / 2, avecNom ? haut + fPrenom * 0.6 + 6 : haut + barre / 2);
    if (avecNom) { g.fillStyle = '#4a5563'; g.font = '600 ' + fNom + 'px system-ui, sans-serif'; g.fillText(mots.nom, W / 2, haut + barre - fNom * 0.6 - 6); }
    return { tex: texture(cv, 1, 1), w: W * ECHELLE, h: H * ECHELLE };
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

  function construire(st, plan) {
    var scene = new THREE.Scene(), L = plan.L, H = plan.H;
    st.pick = []; st.labels = {}; st.survol = null; st.plan = plan;
    pal = PALETTES[(plan.palette || 0) % PALETTES.length];
    scene.add(new THREE.HemisphereLight(0xfff3dc, 0x9c8068, 0.85));
    var soleil = new THREE.DirectionalLight(0xffe4b8, 1.0);
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
    var remplissage = new THREE.DirectionalLight(0xcfe2ff, 0.3);
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
    murs(scene, L, H);
    if (plan.etiquette) panneauxClasse(scene, st, L, H, plan.etiquette);
    plan.objets.forEach(function (item) { meuble(scene, item, st); });
    plan.objets.forEach(function (item) { if (item.obj.type === 'place') etiquettePlace(scene, st, item); });
    mettreEnValeur(st);
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
    st.renderer.render(st.scene, st.camera);
  }

  // la salle suit la taille de la fenêtre
  window.addEventListener('resize', function () {
    document.querySelectorAll('.plan-trois').forEach(function (c) { if (c._trois) rafraichir(c); });
  });

  return { rendre: rendre };
})();
