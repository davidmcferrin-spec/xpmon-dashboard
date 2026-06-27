<?php
/**
 * Inline theme bootstrap — include in <head> before stylesheet to avoid flash.
 * Optional $user from session_user_payload_full() supplies server-side pref.
 */
declare(strict_types=1);

$theme_pref = null;
if (isset($user) && is_array($user) && !empty($user['prefs']['theme'])) {
    $candidate = $user['prefs']['theme'];
    if (in_array($candidate, ['dark', 'light'], true)) {
        $theme_pref = $candidate;
    }
}
?>
<script>
(function() {
  var serverTheme = <?= json_encode($theme_pref) ?>;
  var theme = serverTheme || localStorage.getItem('xpmon-theme') || 'dark';
  if (theme !== 'dark' && theme !== 'light') theme = 'dark';
  document.documentElement.setAttribute('data-theme', theme);
  try { localStorage.setItem('xpmon-theme', theme); } catch (e) {}
})();
</script>
