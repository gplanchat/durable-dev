/*
 * Ce que le composant du canevas faisait et qui n'existe plus hors de lui :
 * la bascule de thème et les annotations ligne à ligne de l'exemple.
 *
 * Les deux marqueurs sont remplacés par ./import-design.py, qui lit les
 * annotations dans le composant d'origine plutôt que de les recopier — les
 * laisser en double se serait payé au premier changement du design.
 */
(function () {
  'use strict';

  var NOTES = __NOTES__;
  var DEFAULT = __DEFAULT_NOTE__;
  // Le libellé nomme le thème vers lequel on bascule, pas le thème courant.
  var THEMES = __THEME_LABELS__;

  // --- Thème ---------------------------------------------------------------
  // Trois états, pas deux : « clair », « sombre », et l'absence de choix, qui
  // laisse décider prefers-color-scheme. Le bouton n'écrit que sur les deux
  // premiers.
  var root = document.documentElement;

  function systemPrefersDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function currentTheme() {
    var set = root.getAttribute('data-theme');
    if (set === 'dark' || set === 'light') return set;
    return systemPrefersDark() ? 'dark' : 'light';
  }

  function paintThemeLabel() {
    var label = currentTheme() === 'dark' ? THEMES.light : THEMES.dark;
    var buttons = document.querySelectorAll('[data-dz-theme-toggle]');
    for (var i = 0; i < buttons.length; i++) buttons[i].textContent = label;
  }

  function toggleTheme() {
    var next = currentTheme() === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try {
      localStorage.setItem('durable-theme', next);
    } catch (e) { /* le thème vaut pour la visite, faute de pouvoir le garder */ }
    paintThemeLabel();
  }

  // Le libellé suit le système tant que personne n'a tranché.
  if (window.matchMedia) {
    var media = window.matchMedia('(prefers-color-scheme: dark)');
    var onChange = function () {
      if (!root.getAttribute('data-theme')) paintThemeLabel();
    };
    if (media.addEventListener) media.addEventListener('change', onChange);
    else if (media.addListener) media.addListener(onChange);
  }

  // --- Annotations de l'exemple -------------------------------------------
  function showNote(index) {
    var note = NOTES[index] || DEFAULT;
    var title = document.querySelector('[data-dz-note-title]');
    var text = document.querySelector('[data-dz-note-text]');
    if (title) title.textContent = note[0];
    if (text) text.textContent = note[1];
  }

  function clearNote() {
    showNote(-1);
  }

  // Délégation : un seul écouteur, et les lignes peuvent changer de nombre
  // sans qu'on y revienne.
  document.addEventListener('click', function (event) {
    var target = event.target;
    if (target && target.closest && target.closest('[data-dz-theme-toggle]')) toggleTheme();
  });

  document.addEventListener('mouseover', function (event) {
    var target = event.target;
    if (!target || !target.closest) return;
    var line = target.closest('[data-dz-note]');
    if (line) showNote(parseInt(line.getAttribute('data-dz-note'), 10));
  });

  document.addEventListener('mouseout', function (event) {
    var target = event.target;
    if (!target || !target.closest) return;
    var zone = target.closest('[data-dz-note-clear]');
    if (zone && !zone.contains(event.relatedTarget)) clearNote();
  });

  // Le survol n'existe pas au doigt : sur écran tactile, une ligne se touche.
  document.addEventListener('focusin', function (event) {
    var line = event.target && event.target.closest && event.target.closest('[data-dz-note]');
    if (line) showNote(parseInt(line.getAttribute('data-dz-note'), 10));
  });

  paintThemeLabel();
})();
