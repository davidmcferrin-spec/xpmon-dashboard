'use strict';

function xpmonApplyTheme(theme) {
  if (theme !== 'dark' && theme !== 'light') theme = 'dark';
  document.documentElement.setAttribute('data-theme', theme);
  try { localStorage.setItem('xpmon-theme', theme); } catch (e) { /* private mode */ }
  xpmonUpdateThemeButton(theme);
}

function xpmonUpdateThemeButton(theme) {
  const btn = document.getElementById('btnTheme');
  if (!btn) return;
  btn.textContent = theme === 'dark' ? '☀' : '🌙';
  btn.title = theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';
}

async function xpmonSaveThemePreference(theme) {
  try { localStorage.setItem('xpmon-theme', theme); } catch (e) { /* private mode */ }
  try {
    await fetch('api/profile.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save_prefs', theme }),
    });
  } catch (e) { /* localStorage fallback */ }
}

(function xpmonInitThemeToggle() {
  const current = document.documentElement.getAttribute('data-theme') || 'dark';
  xpmonUpdateThemeButton(current);

  const btn = document.getElementById('btnTheme');
  if (!btn) return;
  btn.addEventListener('click', () => {
    const now = document.documentElement.getAttribute('data-theme') || 'dark';
    const next = now === 'dark' ? 'light' : 'dark';
    xpmonApplyTheme(next);
    xpmonSaveThemePreference(next);
  });
})();
