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

  function sansAccents(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }

  function filtrer() {
    var mots = sansAccents(texte.value.trim()).split(/\s+/).filter(Boolean);
    var n = 0;
    items.forEach(function (li) {
      var t = sansAccents(li.dataset.texte);
      var ok = (!niveau || li.dataset.niveau === niveau)
        && (!app.value || li.dataset.app === app.value)
        && (!vue.value || (vue.value === 'proposees' ? li.dataset.classes !== '' : (' ' + li.dataset.classes + ' ').indexOf(' ' + vue.value + ' ') >= 0))
        && (!comp || !comp.value || (' ' + (li.dataset.competences || '') + ' ').indexOf(' ' + comp.value + ' ') >= 0)
        && mots.every(function (m) { return t.indexOf(m) >= 0; });
      li.hidden = !ok;
      if (ok) n++;
    });
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
    b.addEventListener('click', function () {
      var f = document.getElementById('form-essayer');
      f.elements.app.value = b.dataset.app;
      f.elements.activite.value = b.dataset.activite;
      f.submit();
    });
  });
  filtrer();
  compterSelection();
})();
