/**
 * Centrale élèves — module à inclure dans chaque application élève.
 *
 *   <script src="https://VOTRE-COMPTE.alwaysdata.net/js/centrale-client.js"></script>
 *   <script>
 *     Centrale.init({ api: "https://VOTRE-COMPTE.alwaysdata.net/api/login.php", app: "mon-app" });
 *
 *     // Formulaire de connexion tout fait :
 *     Centrale.afficherConnexion(document.getElementById("connexion"), {
 *       onConnecte: (infos) => { ... infos.classe, infos.eleve, infos.depot.url ... }
 *     });
 *   </script>
 *
 * La connexion est gardée le temps de l'onglet (sessionStorage) : sur un poste partagé,
 * fermer l'onglet suffit à déconnecter l'élève. Le code lui-même n'est jamais stocké.
 */
(function (global) {
  'use strict';

  var CLE_SESSION = 'centrale-session';
  var config = { api: '', app: '*' };

  function init(options) {
    config = Object.assign({}, config, options || {});
    return Centrale;
  }

  /** Envoie le code au serveur. Renvoie { classe, eleve, app, depot: { url, mot_de_passe } }. */
  async function connexion(code) {
    if (!config.api) {
      throw new Error('Centrale.init({ api: "…" }) doit être appelé avant la connexion.');
    }
    var reponse;
    try {
      reponse = await fetch(config.api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: String(code || '').trim(), app: config.app }),
      });
    } catch (e) {
      throw new Error('Serveur injoignable. Vérifiez la connexion internet.');
    }
    var donnees = {};
    try { donnees = await reponse.json(); } catch (e) { /* réponse non JSON */ }
    if (!reponse.ok || !donnees.ok) {
      throw new Error(donnees.erreur || ('Erreur du serveur (' + reponse.status + ').'));
    }
    try { sessionStorage.setItem(CLE_SESSION, JSON.stringify(donnees)); } catch (e) { /* stockage bloqué */ }
    memoire = donnees;
    return donnees;
  }

  var memoire = null; // secours si sessionStorage est indisponible

  /** Infos de l'élève connecté, ou null. */
  function session() {
    try {
      var brut = sessionStorage.getItem(CLE_SESSION);
      if (brut) return JSON.parse(brut);
    } catch (e) { /* stockage bloqué */ }
    return memoire;
  }

  function deconnexion() {
    memoire = null;
    try { sessionStorage.removeItem(CLE_SESSION); } catch (e) { /* stockage bloqué */ }
  }

  /** Nettoie un texte pour un nom de fichier : "Léa D." → "Lea-D" */
  function propre(texte) {
    return String(texte || '')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^A-Za-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  /** Nom de fichier standard : 5A_Lea-D_mon-app_2026-10-30_suffixe.ext */
  function nomFichier(extension, suffixe) {
    var s = session() || {};
    var date = new Date();
    var jour = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    var morceaux = [propre(s.classe) || 'classe', propre(s.eleve) || 'eleve'];
    if (s.app && s.app !== '*') morceaux.push(propre(s.app));
    morceaux.push(jour);
    if (suffixe) morceaux.push(propre(suffixe));
    return morceaux.join('_') + '.' + String(extension || 'txt').replace(/^\./, '');
  }

  /** Fait télécharger un fichier (texte, Blob…) à l'élève. */
  function telecharger(contenu, nom, type) {
    var blob = contenu instanceof Blob ? contenu : new Blob([contenu], { type: type || 'text/plain;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = nom || nomFichier('txt');
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
  }

  /** Ouvre la page de dépôt Nuage de la classe dans un nouvel onglet. */
  function ouvrirDepot() {
    var s = session();
    if (!s || !s.depot || !s.depot.url) {
      throw new Error("Aucun dossier de dépôt n'est configuré pour ta classe. Préviens ton professeur.");
    }
    window.open(s.depot.url, '_blank', 'noopener');
  }

  /* ---------- Formulaire de connexion prêt à l'emploi ---------- */

  var STYLE = [
    '.centrale-form{display:flex;flex-direction:column;gap:10px;max-width:420px}',
    '.centrale-form label{font-weight:600}',
    '.centrale-form input{font:500 1.05rem ui-monospace,Menlo,Consolas,monospace;padding:10px 12px;border:1px solid #b9b6ae;border-radius:8px;width:100%;box-sizing:border-box;background:inherit;color:inherit}',
    '.centrale-form button{font:inherit;font-weight:600;padding:10px 16px;border:0;border-radius:8px;background:#1f5f8b;color:#fff;cursor:pointer}',
    '.centrale-form button[disabled]{opacity:.6;cursor:wait}',
    '.centrale-erreur{color:#b3261e;min-height:1.4em;margin:0}',
  ].join('');

  function ajouterStyle() {
    if (document.getElementById('centrale-style')) return;
    var st = document.createElement('style');
    st.id = 'centrale-style';
    st.textContent = STYLE;
    document.head.appendChild(st);
  }

  /**
   * Affiche un formulaire "Mon code" dans `element`.
   * Si l'élève est déjà connecté dans cet onglet, appelle directement onConnecte.
   */
  function afficherConnexion(element, options) {
    options = options || {};
    var deja = session();
    if (deja) {
      if (options.onConnecte) options.onConnecte(deja);
      return;
    }
    ajouterStyle();
    element.innerHTML = '';
    var form = document.createElement('form');
    form.className = 'centrale-form';
    form.innerHTML =
      '<label for="centrale-code">' + (options.titre || 'Mon code') + '</label>' +
      '<input id="centrale-code" name="code" autocomplete="off" autocapitalize="off" spellcheck="false" ' +
      'placeholder="ex. 5A-abcdefg-hjkm-npqrs" required>' +
      '<button type="submit">Se connecter</button>' +
      '<p class="centrale-erreur" role="alert"></p>';
    element.appendChild(form);
    var champ = form.querySelector('input');
    var bouton = form.querySelector('button');
    var erreur = form.querySelector('.centrale-erreur');
    champ.focus();

    form.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      erreur.textContent = '';
      bouton.disabled = true;
      bouton.textContent = 'Connexion…';
      try {
        var infos = await connexion(champ.value);
        element.innerHTML = '';
        if (options.onConnecte) options.onConnecte(infos);
      } catch (e) {
        erreur.textContent = e.message;
        bouton.disabled = false;
        bouton.textContent = 'Se connecter';
        champ.select();
      }
    });
  }

  var Centrale = {
    init: init,
    connexion: connexion,
    session: session,
    deconnexion: deconnexion,
    nomFichier: nomFichier,
    telecharger: telecharger,
    ouvrirDepot: ouvrirDepot,
    afficherConnexion: afficherConnexion,
  };
  global.Centrale = Centrale;
})(window);
