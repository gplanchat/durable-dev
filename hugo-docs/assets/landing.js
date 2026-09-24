/*
 * What the canvas component did and that no longer exists outside it:
 * the theme toggle and the line-by-line annotations of the example.
 *
 * The two markers are replaced by ./import-design.py, which reads the
 * annotations from the original component rather than copying them: keeping
 * them duplicated would have been paid for at the first design change.
 */
(function () {
  'use strict';

  var NOTES = __NOTES__;
  var DEFAULT = __DEFAULT_NOTE__;
  // The label names the theme being switched to, not the current theme.
  var THEMES = __THEME_LABELS__;

  // --- Theme ---------------------------------------------------------------
  // Three states, not two: "light", "dark", and the absence of a choice, which
  // lets prefers-color-scheme decide. The button only writes the first
  // two.
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
    } catch (e) { /* the theme holds for the visit, since it cannot be kept */ }
    paintThemeLabel();
  }

  // The label follows the system as long as nobody has decided.
  if (window.matchMedia) {
    var media = window.matchMedia('(prefers-color-scheme: dark)');
    var onChange = function () {
      if (!root.getAttribute('data-theme')) paintThemeLabel();
    };
    if (media.addEventListener) media.addEventListener('change', onChange);
    else if (media.addListener) media.addListener(onChange);
  }

  // --- Example annotations -----------------------------------------------
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

  // Delegation: a single listener, and the number of lines can change
  // without coming back to this.
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

  // Hover does not exist for a finger: on a touch screen, a line is tapped.
  document.addEventListener('focusin', function (event) {
    var line = event.target && event.target.closest && event.target.closest('[data-dz-note]');
    if (line) showNote(parseInt(line.getAttribute('data-dz-note'), 10));
  });

  paintThemeLabel();
})();
