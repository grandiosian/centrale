/* Centrale élèves — page Bibliothèque : filtres, sélection et essai d'une activité. */
(function () {
  'use strict';
  var form = document.getElementById('form-biblio');
  if (!form) return;
  var items = Array.prototype.slice.call(document.querySelectorAll('.biblio-item'));
  var texte = document.getElementById('b-texte');
  var app = document.getElementById('b-app');
  var vue = document.getElementById('b-vue');
  var comp = document.getElementById('b-competence');
  var condense = document.getElementById('b-condense');
  var main = document.querySelector('main.biblio');
  var niveaux = Array.prototype.slice.call(document.querySelectorAll('.puces-niveaux button'));
  var actif = niveaux.filter(function (b) { return b.classList.contains('actif'); })[0];
  var niveau = actif ? actif.dataset.niveau : '';
  var barre = document.getElementById('barre-selection');

  var donnees = JSON.parse((document.getElementById('b-donnees-ateliers') || {}).textContent || '{"ateliers":{}}');
  var ateliers = donnees.ateliers || {};
  var activitesDepliees = false;   // comparaison : section Activités dépliée
  var choisis = [];   // clés « app|atelier » des ateliers cochés (gardées dans l'onglet)
  try { choisis = JSON.parse(sessionStorage.getItem('biblio-ateliers') || '[]').filter(function (k) { return ateliers[k]; }); } catch (e) {}
  var panneau = document.getElementById('b-panneau-ateliers');
  var dialogue = document.getElementById('b-dialogue');

  function sansAccents(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }

  function filtrer() {
    var mots = sansAccents(texte.value.trim()).split(/\s+/).filter(Boolean);
    var n = 0;
    var possibles = {};   // ateliers qui ont au moins une activité visible avec les autres filtres
    items.forEach(function (li) {
      var t = sansAccents(li.dataset.texte);
      var ok = (!niveau || li.dataset.niveau === niveau)
        && (!app.value || li.dataset.app === app.value)
        && (!vue.value || (vue.value === 'proposees' ? li.dataset.classes !== '' : (' ' + li.dataset.classes + ' ').indexOf(' ' + vue.value + ' ') >= 0))
        && (!comp || !comp.value || (' ' + (li.dataset.competences || '') + ' ').indexOf(' ' + comp.value + ' ') >= 0)
        && mots.every(function (m) { return t.indexOf(m) >= 0; });
      if (ok) possibles[li.dataset.atelier] = true;
      ok = ok && (!choisis.length || choisis.indexOf(li.dataset.atelier) >= 0);
      li.hidden = !ok;
      if (ok) n++;
    });
    document.querySelectorAll('.ligne-atelier').forEach(function (l) {
      l.hidden = !possibles[l.dataset.cle] && choisis.indexOf(l.dataset.cle) < 0;
      l.querySelector('input').checked = choisis.indexOf(l.dataset.cle) >= 0;
    });
    document.querySelectorAll('.panneau-ateliers-apps section').forEach(function (s) { s.hidden = !s.querySelector('.ligne-atelier:not([hidden])'); });
    majChoisis();
    // titres de groupe et sections sans activité visible
    document.querySelectorAll('.biblio-groupe').forEach(function (g) {
      var x = g.nextElementSibling, vu = false;
      while (x && !x.classList.contains('biblio-groupe')) { if (!x.hidden) vu = true; x = x.nextElementSibling; }
      g.hidden = !vu;
    });
    document.querySelectorAll('.biblio-app').forEach(function (s) { s.hidden = !s.querySelector('.biblio-item:not([hidden])'); });
    document.getElementById('b-compte').textContent = n + ' activité' + (n > 1 ? 's' : '');
    document.getElementById('b-rien').hidden = n > 0;
    // filtres gardés dans l'adresse et renvoyés avec les formulaires
    var q = new URLSearchParams(location.search);
    [['niveau', niveau], ['app', app.value], ['vue', vue.value], ['competence', comp ? comp.value : '']].forEach(function (p) {
      if (p[1]) q.set(p[0], p[1]); else q.delete(p[0]);
      document.querySelectorAll('input[name="f_' + p[0] + '"]').forEach(function (i) { i.value = p[1]; });
    });
    history.replaceState(null, '', '?' + q.toString());
  }

  function compterSelection() {
    var n = form.querySelectorAll('input[name="sel[]"]:checked').length;
    barre.hidden = n === 0;
    document.getElementById('b-sel').textContent = n + (n > 1 ? ' activités choisies' : ' activité choisie');
  }

  /* ---------- Ateliers : sélection, détail, comparaison ---------- */

  function el(tag, cls, contenu) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    (Array.isArray(contenu) ? contenu : [contenu]).forEach(function (c) {
      if (c === null || c === undefined || c === '') return;
      e.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    });
    return e;
  }
  function bouton(texteBouton, cls, action) {
    var b = el('button', cls, texteBouton);
    b.type = 'button';
    b.addEventListener('click', action);
    return b;
  }
  function puceComp(code, avecTexte) {
    var c = (donnees.competences || {})[code] || {};
    var p = el('span', 'code-comp th', code);
    p.style.setProperty('--t', c.couleur || '#888888');
    p.title = c.texte || '';
    return avecTexte ? el('span', 'comp-texte', [p, ' ', c.texte || '']) : p;
  }
  function puceNiveau(niv) {
    var coul = (donnees.couleurs || {})[niv];
    if (!coul) return null;
    var p = el('span', 'puce-niveau', (donnees.niveaux || {})[niv] || niv);
    p.style.setProperty('--niv', coul);
    return p;
  }
  function pluriel(n, mot) { return n + ' ' + mot + (n > 1 ? 's' : ''); }
  function etapes(at) { return at.activites.reduce(function (s, a) { return s + (a.total || 0); }, 0); }
  function suivi(at) {
    var t = 0, c = 0;
    at.activites.forEach(function (a) { t += a.terminees; c += a.commencees; });
    return c ? t + ' terminée' + (t > 1 ? 's' : '') + ' / ' + c + ' commencée' + (c > 1 ? 's' : '') : '';
  }
  function cibles(at) {
    var vu = {};
    at.activites.forEach(function (a) { a.propositions.forEach(function (p) { vu[p.cible] = true; }); });
    return Object.keys(vu).join(', ');
  }

  function memoriser() {
    try { sessionStorage.setItem('biblio-ateliers', JSON.stringify(choisis)); } catch (e) {}
  }
  function basculer(cle, oui) {
    var i = choisis.indexOf(cle);
    if (oui === undefined) oui = i < 0;
    if (oui && i < 0) choisis.push(cle);
    if (!oui && i >= 0) choisis.splice(i, 1);
    memoriser();
    filtrer();
  }
  function majChoisis() {
    var zone = document.getElementById('b-sel-ateliers');
    if (!zone) return;
    zone.hidden = !choisis.length;
    document.getElementById('b-sel-ateliers-n').textContent = choisis.length > 1 ? choisis.length + ' ateliers :' : '1 atelier :';
    var puces = document.getElementById('b-puces-ateliers');
    puces.textContent = '';
    choisis.forEach(function (k) {
      var at = ateliers[k];
      var p = el('span', 'puce-sel', [bouton(at.titre, 'lien-bouton', function () { detail(k); })]);
      var x = bouton('×', 'retirer', function () { basculer(k, false); });
      x.setAttribute('aria-label', 'Retirer ' + at.titre + ' de la sélection');
      p.appendChild(x);
      puces.appendChild(p);
    });
    document.getElementById('b-comparer').disabled = choisis.length < 2;
    document.getElementById('b-comparer').title = choisis.length < 2 ? 'Choisissez au moins deux ateliers' : 'Comparer les ateliers choisis';
  }

  function ouvrirDialogue(titre, corps, large) {
    document.getElementById('b-dialogue-titre').textContent = titre;
    var c = document.getElementById('b-dialogue-corps');
    c.textContent = '';
    c.appendChild(corps);
    dialogue.classList.toggle('large', !!large);
    if (!dialogue.open) dialogue.showModal();
    c.scrollTop = 0;
    dialogue.scrollTop = 0;
  }

  function essayer(app, activite) {
    var f = document.getElementById('form-essayer');
    f.elements.app.value = app;
    f.elements.activite.value = activite;
    f.submit();
  }

  /** Tout ce que la Centrale sait d'une activité d'un atelier (détail et comparaison dépliée). */
  function ficheActivite(at, a) {
    var tete = el('div', 'detail-act-tete', [el('strong', '', a.titre), el('code', 'discret', a.id)]);
    if (a.essayer) tete.appendChild(bouton('Essayer', 'petit secondaire', function () { essayer(at.app, a.id); }));
    var li = el('li', '', tete);
    var meta = [];
    if (a.total) meta.push(pluriel(a.total, 'étape'));
    if (a.duree && a.duree !== at.duree) meta.push(a.duree);
    if (a.categorie) meta.push(a.categorie);
    if (a.type) meta.push(a.type);
    if (a.commencees) meta.push('✓ ' + a.terminees + ' / ' + pluriel(a.commencees, 'élève'));
    if (!a.dans_bibliotheque) meta.push('pas encore dans la bibliothèque');
    if (meta.length) li.appendChild(el('div', 'discret', meta.join(' · ')));
    if (a.resume && a.resume !== at.resume) li.appendChild(el('p', '', a.resume));
    if (a.competences.length) li.appendChild(el('div', 'biblio-comps', a.competences.map(function (c) { return puceComp(c); })));
    if (a.questions.length) {
      li.appendChild(el('div', 'detail-sous', 'Questions posées à l’élève'));
      li.appendChild(el('ul', '', a.questions.map(function (q) { return el('li', '', q); })));
    }
    if (a.propositions.length) {
      li.appendChild(el('div', 'detail-sous', 'Proposée à'));
      li.appendChild(el('ul', '', a.propositions.map(function (p) {
        return el('li', '', [el('strong', '', p.cible), p.pour_le ? ' — pour le ' + p.pour_le : '', p.consigne ? ' — consigne : « ' + p.consigne + ' »' : '']);
      })));
    }
    return li;
  }

  function detail(cle) {
    var at = ateliers[cle];
    if (!at) return;
    var corps = el('div', 'detail-atelier');
    var infos = el('div', 'detail-infos', [el('span', '', [at.icone || '🧩', ' ', at.nom_app]), puceNiveau(at.niveau)]);
    if (at.theme) {
      var th = el('span', 'pastille-theme', at.theme);
      th.style.setProperty('--t', (donnees.themes || {})[at.theme] || '#888888');
      infos.appendChild(th);
    }
    if (at.duree) infos.appendChild(el('span', '', '⏱ ' + at.duree));
    infos.appendChild(el('span', '', pluriel(at.activites.length, 'activité') + (etapes(at) ? ' · ' + pluriel(etapes(at), 'étape') : '')));
    if (suivi(at)) infos.appendChild(el('span', '', 'Élèves : ' + suivi(at)));
    corps.appendChild(infos);
    if (at.resume) corps.appendChild(el('p', 'detail-resume', at.resume));

    var actions = el('div', 'boutons');
    var dedans = choisis.indexOf(cle) >= 0;
    actions.appendChild(bouton(dedans ? 'Retirer de la sélection' : 'Ajouter à la sélection', dedans ? 'petit secondaire' : 'petit', function () {
      basculer(cle);
      detail(cle);
    }));
    var dispo = at.activites.filter(function (a) { return a.dans_bibliotheque; });
    if (dispo.length) {
      actions.appendChild(bouton('Cocher ses activités (pour les proposer)', 'petit secondaire', function () {
        dispo.forEach(function (a) {
          var c = form.querySelector('input[name="sel[]"][value="' + CSS.escape(at.app + '|' + a.id) + '"]');
          if (c) c.checked = true;
        });
        compterSelection();
        dialogue.close();
      }));
    }
    if (at.ouvrir) {
      var lien = el('a', 'btn petit secondaire', 'Ouvrir dans l’application ↗');
      lien.href = at.ouvrir;
      lien.target = '_blank';
      lien.rel = 'noopener';
      actions.appendChild(lien);
    }
    if (choisis.length > 1) actions.appendChild(bouton('Comparer la sélection', 'petit secondaire', comparer));
    corps.appendChild(actions);

    if (at.competences.length) {
      corps.appendChild(el('h3', '', 'Compétences travaillées'));
      corps.appendChild(el('ul', 'detail-comps', at.competences.map(function (c) { return el('li', '', puceComp(c, true)); })));
    }

    corps.appendChild(el('h3', '', 'Activités'));
    var ol = el('ol', 'detail-activites');
    at.activites.forEach(function (a) {
      ol.appendChild(ficheActivite(at, a));
    });
    corps.appendChild(ol);

    if (at.ressources.length) {
      corps.appendChild(el('h3', '', 'Ressources déposées pour ' + at.nom_app));
      corps.appendChild(el('ul', '', at.ressources.map(function (r) { return el('li', '', [r.nom, ' ', el('small', 'discret', r.taille)]); })));
    }
    var origine = ['Source : ' + at.source];
    if (at.atelier) origine.push('identifiant ' + at.atelier);
    if (at.version) origine.push('version ' + at.version);
    if (at.auteur) origine.push('auteur : ' + at.auteur);
    var pied = el('p', 'discret detail-origine', origine.join(' · '));
    if (at.fichier) {
      var f = el('a', '', 'contenu publié ↗');
      f.href = at.fichier;
      f.target = '_blank';
      f.rel = 'noopener';
      pied.appendChild(document.createTextNode(' · '));
      pied.appendChild(f);
    }
    corps.appendChild(pied);
    ouvrirDialogue(at.titre, corps);
  }

  function comparer() {
    var liste = choisis.map(function (k) { return ateliers[k]; });
    if (liste.length < 2) return;
    var table = el('table', 'table-comparer');
    var tr = el('tr', '', el('th', '', ''));
    liste.forEach(function (at) {
      var th = el('th', '', [bouton(at.titre, 'lien-bouton', function () { detail(at.cle); }), el('div', 'discret', (at.icone || '🧩') + ' ' + at.nom_app)]);
      var x = bouton('×', 'retirer', function () { basculer(at.cle, false); if (choisis.length > 1) comparer(); else dialogue.close(); });
      x.setAttribute('aria-label', 'Retirer ' + at.titre + ' de la comparaison');
      th.appendChild(x);
      tr.appendChild(th);
    });
    table.appendChild(el('thead', '', tr));
    var tbody = el('tbody');
    function ligne(titre, cellule, cls) {
      var r = el('tr', cls || '', el('th', '', titre));
      liste.forEach(function (at) { r.appendChild(el('td', '', cellule(at) || el('span', 'discret', '—'))); });
      tbody.appendChild(r);
    }
    function section(titre) { tbody.appendChild(el('tr', 'sous-titre', el('th', '', el('span', '', titre)))).firstChild.colSpan = liste.length + 1; }
    ligne('Niveau', function (at) { return puceNiveau(at.niveau) || el('span', 'discret', 'tous niveaux'); });
    ligne('Thème', function (at) { return at.theme; });
    ligne('Durée', function (at) { return at.duree; });
    ligne('Activités', function (at) { return pluriel(at.activites.length, 'activité') + (etapes(at) ? ' · ' + pluriel(etapes(at), 'étape') : ''); });
    ligne('Résumé', function (at) { return at.resume; }, 'texte');
    ligne('Élèves', suivi);
    ligne('Proposé à', cibles);

    var codes = [];
    liste.forEach(function (at) { at.competences.forEach(function (c) { if (codes.indexOf(c) < 0) codes.push(c); }); });
    codes.sort();
    section('Compétences (' + codes.length + ')');
    if (!codes.length) ligne('', function () { return ''; });
    codes.forEach(function (c) {
      var tous = liste.every(function (at) { return at.competences.indexOf(c) >= 0; });
      ligne(puceComp(c, true), function (at) {
        if (at.competences.indexOf(c) < 0) return el('span', 'absent', '—');
        var n = at.activites.filter(function (a) { return a.competences.indexOf(c) >= 0; }).length;
        return el('span', 'present', ['✓', n && at.activites.length > 1 ? el('small', 'discret', ' ' + pluriel(n, 'activité')) : '']);
      }, tous ? 'commune' : '');
    });

    section('Activités');
    var plier = bouton(activitesDepliees ? '−' : '+', 'petit secondaire plier', function () {
      activitesDepliees = !activitesDepliees;
      majPlier();
    });
    tbody.lastChild.querySelector('span').appendChild(plier);
    ligne('', function (at) {
      return el('ol', 'comparer-activites', at.activites.map(function (a) {
        return el('li', '', [a.titre, a.total ? el('small', 'discret', ' · ' + pluriel(a.total, 'étape')) : '']);
      }));
    }, 'texte act-court');
    ligne('', function (at) {
      return el('ol', 'detail-activites comparer-detail', at.activites.map(function (a) { return ficheActivite(at, a); }));
    }, 'texte act-detail');
    function majPlier() {
      plier.textContent = activitesDepliees ? '−' : '+';
      plier.title = activitesDepliees ? 'Replier les activités' : 'Déplier le détail de chaque activité';
      plier.setAttribute('aria-label', plier.title);
      plier.setAttribute('aria-expanded', activitesDepliees ? 'true' : 'false');
      tbody.querySelector('.act-court').hidden = activitesDepliees;
      tbody.querySelector('.act-detail').hidden = !activitesDepliees;
    }
    majPlier();
    table.appendChild(tbody);

    var communes = codes.filter(function (c) { return liste.every(function (at) { return at.competences.indexOf(c) >= 0; }); }).length;
    var corps = el('div', 'comparaison', [
      el('p', 'discret', (communes ? pluriel(communes, 'compétence') + ' en commun (lignes surlignées) sur ' + codes.length : 'Aucune compétence en commun')
        + '. Cliquez un titre pour voir le détail.'),
      el('div', 'defile', table)
    ]);
    ouvrirDialogue('Comparer ' + liste.length + ' ateliers', corps, true);
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.voir-atelier');
    if (b) detail(b.dataset.cle);
  });
  if (panneau) {
    var voir = document.getElementById('b-voir-ateliers');
    voir.addEventListener('click', function () {
      panneau.hidden = !panneau.hidden;
      voir.setAttribute('aria-expanded', panneau.hidden ? 'false' : 'true');
    });
    panneau.addEventListener('change', function (e) { if (e.target.type === 'checkbox') basculer(e.target.value, e.target.checked); });
    document.getElementById('b-comparer').addEventListener('click', comparer);
    document.getElementById('b-effacer-ateliers').addEventListener('click', function () { choisis = []; memoriser(); filtrer(); });
    document.getElementById('b-fermer').addEventListener('click', function () { dialogue.close(); });
    // clic sur le fond : fermer
    dialogue.addEventListener('click', function (e) { if (e.target === dialogue) dialogue.close(); });
  }

  texte.addEventListener('input', filtrer);
  app.addEventListener('change', filtrer);
  vue.addEventListener('change', filtrer);
  if (comp) comp.addEventListener('change', filtrer);
  if (condense && main) {
    var condenseOn = false;
    try { condenseOn = localStorage.getItem('biblio-condense') === '1'; } catch (e) {}
    var majCondense = function () { main.classList.toggle('condense', condenseOn); condense.setAttribute('aria-pressed', condenseOn ? 'true' : 'false'); };
    condense.addEventListener('click', function () {
      condenseOn = !condenseOn;
      try { localStorage.setItem('biblio-condense', condenseOn ? '1' : '0'); } catch (e) {}
      majCondense();
    });
    majCondense();
  }
  niveaux.forEach(function (b) {
    b.addEventListener('click', function () {
      niveaux.forEach(function (x) { x.classList.toggle('actif', x === b); });
      niveau = b.dataset.niveau;
      filtrer();
    });
  });
  form.addEventListener('change', function (e) { if (e.target.name === 'sel[]') compterSelection(); });
  document.getElementById('b-aucune').addEventListener('click', function () {
    form.querySelectorAll('input[name="sel[]"]:checked').forEach(function (c) { c.checked = false; });
    compterSelection();
  });
  form.addEventListener('submit', function (e) {
    var bouton = e.submitter;
    if (bouton && bouton.value === 'proposer' && !form.querySelector('input[name="cibles[]"]:checked')) {
      e.preventDefault();
      form.querySelector('.choix-cibles').open = true;
      document.getElementById('b-err').hidden = false;
    }
  });
  document.querySelectorAll('.essayer').forEach(function (b) {
    b.addEventListener('click', function () { essayer(b.dataset.app, b.dataset.activite); });
  });
  filtrer();
  compterSelection();
})();
