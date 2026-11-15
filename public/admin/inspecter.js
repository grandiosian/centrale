/*
 * Centrale élèves — « Inspecter un atelier » (lib/admin_inspecter.php), partie 4 : ce que l'application publie.
 * Lit chez l'application sa fiche dans ateliers.json et, s'il existe, le fichier complet de l'atelier.
 * Un atelier TechnoStudio (format « Workshop », champ steps) est détaillé feuille par feuille : codes P1/R1/S1/E1
 * (comme js/passerelle.js de TechnoStudio), blocs, blocs notés, compétences, images. Rien n'est modifié.
 */
(function () {
  'use strict';
  var zone = document.getElementById('inspect-contenu');
  var src = document.getElementById('json-inspecter');
  if (!zone || !src) return;
  var D = JSON.parse(src.textContent);

  // types de blocs TechnoStudio comptés dans l'avancement (descripteur : graded = true)
  var NOTES = ['qcm', 'vraifaux', 'cloze', 'numeric', 'match', 'order', 'sort', 'table', 'boxes', 'chain', 'label', 'bits', 'graph',
    'draw', 'mindmap', 'procedure', 'questions', 'report', 'circuit', 'blocks', 'mblock', 'robot', 'code'];
  var KIND = { feuille: 'P', ressource: 'R', synthese: 'S', evaluation: 'E', fiche: 'F' };
  var NOMS_KIND = { feuille: 'feuille', ressource: 'ressources', synthese: 'synthèse', evaluation: 'évaluation', fiche: 'fiche' };

  function el(tag, cls, enfants) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    [].concat(enfants == null ? [] : enfants).forEach(function (c) {
      if (c == null || c === false) return;
      e.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    });
    return e;
  }
  function code(t) { return el('code', '', t); }
  function lien(url, texte) { var a = el('a', '', texte || url); a.href = url; a.target = '_blank'; a.rel = 'noopener'; return a; }
  function ko(o) { return Math.round(o / 1024) + ' ko'; }
  function adresse(base, rel) { try { return new URL(rel, base).href; } catch (e) { return rel; } }

  /** JSON lisible, les images intégrées (data:…) raccourcies. */
  function jsonLisible(v) {
    return JSON.stringify(v, function (k, x) {
      return typeof x === 'string' && x.indexOf('data:') === 0 && x.length > 120 ? x.slice(0, 40) + '… (' + ko(x.length) + ')' : x;
    }, 2);
  }
  function brut(titre, v) {
    var d = el('details', 'inspect-brut', [el('summary', '', titre)]);
    d.appendChild(el('pre', 'inspect-json', jsonLisible(v)));
    return d;
  }
  function lire(url) {
    return fetch(url, { cache: 'no-store' }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }
  function dl(lignes) {
    var d = el('dl', 'inspect-dl');
    lignes.forEach(function (l) { if (l) { d.appendChild(el('dt', '', l[0])); d.appendChild(el('dd', '', l[1])); } });
    return d;
  }

  /** Codes des feuilles, comme TechnoStudio : P d'après « feuille » (ou l'ordre), R/S/E d'après l'ordre ; S·P1 en SEGPA. */
  function codesFeuilles(w) {
    var cnt = {};
    return (w.steps || []).map(function (s) {
      var k = s.kind || 'feuille', cle = k + (s.segpa ? 's' : '');
      cnt[cle] = (cnt[cle] || 0) + 1;
      return (s.segpa ? 'S·' : '') + (KIND[k] || 'P') + (k === 'feuille' ? (s.feuille || cnt[cle]) : cnt[cle]);
    });
  }

  function images(v) {
    var n = 0, t = 0;
    (function tour(x) {
      if (typeof x === 'string') { if (x.indexOf('data:image') === 0) { n++; t += x.length; } }
      else if (Array.isArray(x)) x.forEach(tour);
      else if (x && typeof x === 'object') Object.keys(x).forEach(function (k) { tour(x[k]); });
    })(v);
    return { n: n, taille: t };
  }

  /** Atelier TechnoStudio : en-tête, compétences, puis une ligne par feuille. */
  function technostudio(w, taille) {
    var box = el('div');
    var img = images(w);
    var comps = (w.competences && typeof w.competences === 'object' ? [].concat(w.competences) : []);
    var sansRef = [];
    comps.forEach(function (c) {
      if (!c || typeof c !== 'object') return;
      if (!(c.ref || []).length && !(c.sub || []).some(function (s) { return (s.ref || []).length; })) sansRef.push(c.code);
    });
    box.appendChild(el('h3', '', 'Fichier complet de l’atelier'));
    box.appendChild(dl([
      ['Format', [code('format ' + (w.format || 1)), ' · version ', code(String(w.version || '—')), w.updatedAt ? ' · modifié le ' + String(w.updatedAt).slice(0, 10) : '']],
      ['Identifiant', [code(w.id || '—'), w.id && w.id !== D.atelier ? el('span', 'alerte', ' ≠ catalogue (' + D.atelier + ')') : '',
        /^at-/.test(w.id || '') ? el('span', 'discret', ' — identifiant donné au hasard par l’éditeur') : '']],
      ['Filière · niveau', (w.level || 'college') + (w.niveau ? ' · ' + w.niveau : '') + (w.cycle ? ' · cycle ' + w.cycle : '') + (w.seq ? ' · AT SEQ ' + w.seq : '')],
      ['Durée', w.duration ? w.duration + ' min' : '—'],
      w.problematique ? ['Problématique', w.problematique] : null,
      w.objet ? ['Objet d’étude', w.objet] : null,
      ['Compétences de la fiche', comps.length ? el('ul', 'inspect-liste', comps.map(function (c) {
        if (!c || typeof c !== 'object') return el('li', '', String(c));
        var refs = [].concat(c.ref || []);
        (c.sub || []).forEach(function (s) { refs = refs.concat(s.ref || []); });
        return el('li', '', [code(c.code || '?'), ' ', c.label || '', (c.sub || []).length ? el('span', 'discret', ' — ' + c.sub.map(function (s) { return s.code; }).join(', ')) : '',
          refs.length ? el('span', 'discret', ' → ' + Array.from(new Set(refs)).join(', ')) : el('span', 'alerte', ' → aucun lien au référentiel (ref)')]);
      })) : (w.referentiel ? [].concat(w.referentiel).join(', ') : el('span', 'alerte', 'aucune'))],
      ['Fiche pédagogique', ['presentation', 'deroulement', 'synthese', 'evaluation', 'differenciation', 'materiel', 'prof'].filter(function (k) { return w[k] && (w[k].length || Object.keys(w[k]).length); }).join(', ') || '—'],
      w['interface'] ? ['Interface (mode atelier)', jsonLisible(w['interface']).replace(/\s+/g, ' ')] : null,
      ['Poids', (taille ? ko(taille) : '—') + (img.n ? ' · ' + img.n + ' image' + (img.n > 1 ? 's' : '') + ' intégrée' + (img.n > 1 ? 's' : '') + ' (' + ko(img.taille) + ')' : '')],
    ]));
    if (sansRef.length) box.appendChild(el('p', 'alerte', 'Sans lien au référentiel : ' + sansRef.join(', ') + '. Les pastilles de ces compétences n’arrivent pas à la Centrale.'));

    var codes = codesFeuilles(w), ids = {};
    var t = el('table', '', el('thead', '', el('tr', '', ['Code', 'Type', 'Titre', 'Séance', 'Blocs', 'Notés', 'Pastilles', 'Activité Centrale'].map(function (x) { return el('th', '', x); }))));
    var tb = el('tbody');
    (w.steps || []).forEach(function (s, i) {
      var blocs = s.blocks || [], types = {};
      blocs.forEach(function (b) {
        types[b.type] = (types[b.type] || 0) + 1;
        if (b.id) ids[b.id] = (ids[b.id] || 0) + 1;
      });
      var notes = blocs.filter(function (b) { return NOTES.indexOf(b.type) >= 0 && !b.demo; });
      var pastilles = Array.from(new Set([].concat.apply([], notes.map(function (b) { return b.comp || []; }))));
      var act = notes.length ? (w.id + '/' + codes[i]).replace(/[^A-Za-z0-9_\-./]/g, '-') : '';
      var detail = el('details', '', [el('summary', '', blocs.length + ' bloc' + (blocs.length > 1 ? 's' : '')),
        el('ul', 'inspect-liste', blocs.map(function (b) {
          return el('li', '', [code(b.type), ' ', b.id ? el('span', 'discret', b.id) : el('span', 'alerte', 'sans id'),
            b.tag ? el('span', 'discret', ' · ' + b.tag) : '', NOTES.indexOf(b.type) >= 0 && !b.demo ? ' · noté' : '',
            b.question ? el('span', '', ' — ' + String(b.question).slice(0, 80)) : '']);
        }))]);
      tb.appendChild(el('tr', '', [el('td', '', el('strong', '', codes[i])), el('td', '', NOMS_KIND[s.kind || 'feuille'] || s.kind),
        el('td', '', [s.title || '', s.id ? el('div', 'discret', s.id) : '']), el('td', '', s.seance ? String(s.seance) : '—'),
        el('td', '', [detail, el('div', 'discret', Object.keys(types).map(function (k) { return k + (types[k] > 1 ? ' ×' + types[k] : ''); }).join(', '))]),
        el('td', '', String(notes.length || '—')), el('td', '', pastilles.join(' ') || '—'),
        el('td', '', act ? code(act) : el('span', 'discret', 'pas d’activité (rien de noté)'))]));
    });
    t.appendChild(tb);
    box.appendChild(el('h3', '', 'Feuilles (' + (w.steps || []).length + ')'));
    box.appendChild(el('p', 'aide', 'Le code d’une feuille P vient de son numéro de feuille ; celui d’une feuille R, S ou E vient de son ordre. '
      + 'Insérer ou déplacer une feuille R/S/E change donc ses codes, et détache le travail déjà fait.'));
    box.appendChild(el('div', 'defile', t));
    var doubles = Object.keys(ids).filter(function (k) { return ids[k] > 1; });
    if (doubles.length) box.appendChild(el('p', 'alerte', 'Identifiants de blocs en double : ' + doubles.join(', ')));
    return box;
  }

  function generique(v) {
    var box = el('div');
    box.appendChild(el('h3', '', 'Fichier complet de l’atelier'));
    box.appendChild(el('p', 'discret', 'Format propre à l’application : voir les données brutes ci-dessous.'));
    return box;
  }

  var catalogue = D.catalogue || (D.url_app ? adresse(D.url_app.replace(/[?#].*$/, '').replace(/\/?$/, '/'), 'ateliers.json') : '');
  zone.replaceChildren();
  var p1 = catalogue ? lire(catalogue).then(function (c) {
    var liste = Array.isArray(c) ? c : (c && c.ateliers) || [];
    var a = liste.filter(function (x) { return x && x.id === D.atelier; })[0];
    var box = el('div');
    box.appendChild(el('h3', '', 'Fiche dans ateliers.json'));
    box.appendChild(el('p', 'discret', [lien(catalogue), c && c.format ? ' · ' + c.format + ' v' + c.version + (c.app_version ? ' · application ' + c.app_version : '') : '']));
    if (!a) box.appendChild(el('p', 'alerte', 'Cet atelier n’est plus dans le catalogue publié par l’application (il sera marqué « retiré » au prochain import).'));
    else box.appendChild(brut('Voir la fiche publiée (' + (a.activites || []).length + ' activité' + ((a.activites || []).length > 1 ? 's' : '') + ')', a));
    zone.appendChild(box);
    return a;
  }).catch(function (e) {
    zone.appendChild(el('p', 'alerte', 'ateliers.json illisible (' + e.message + ') : ' + catalogue));
  }) : Promise.resolve(null);

  p1.then(function (a) {
    var f = D.fichier || (a && a.fichier ? adresse(catalogue, a.fichier) : '');
    if (!f) {
      zone.appendChild(el('p', 'discret', 'Pas de fichier séparé : l’atelier est écrit dans le code de l’application'
        + (D.app === 'technostudio' ? ' (js/demos.js ou js/demos2.js).' : '.')));
      return;
    }
    return fetch(f, { cache: 'no-store' }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    }).then(function (txt) {
      var v = JSON.parse(txt);
      var w = v && v.workshops ? v.workshops.filter(function (x) { return x.id === D.atelier; })[0] || v.workshops[0] : Array.isArray(v) ? v[0] : v;
      zone.appendChild(el('p', 'discret', ['Fichier : ', lien(f)]));
      zone.appendChild(w && w.steps ? technostudio(w, txt.length) : generique(v));
      zone.appendChild(brut('Données brutes du fichier (images raccourcies)', v));
    }).catch(function (e) {
      zone.appendChild(el('p', 'alerte', 'Fichier illisible (' + e.message + ') : ' + f));
    });
  });
})();
