##
## TO DO ITEMS
##

## WebSocket token auth — PHP issues short-lived HMAC token; bridge validates
##     on WS connect and gates host_command, add_host, etc.

## Admin UI gaps — permission overrides UI, forced-pref indicators in profile

## COMPLETED ──────────────────────────────────────────────────────────────────

## [DONE] Profile-based alerts — per-user alert_hosts, alert_mode, optional
##     user_critical_apps; evaluateAlerts in app.js uses session prefs

## [DONE] XCL hostname fidelity — auto-persist reported_hostname on serverinfo,
##     import hostname/reportedhostname, connection hostname in edit modal

## [DONE] Block config.json from web — public/.htaccess Require all denied

## [DONE] Stability polish — config save lock, CHECKING degraded badge,
##     case-insensitive Stop All exclusions, removed bridge/*.bak* files

## [DONE] Add Local and LDAP Auth — PHP sessions, roles, LDAPS user bind,
##     LDAP groups → roles, admin UI, default admin/admin (change on first login)

## [DONE] Allow user to pick if they want 'Ignored' services to be shown — profile pref

## [DONE] Allow user/admin to hide Windows Updates and Door — profile pref + admin force

## [DONE] Make bridge stop faster — fast shutdown via SIGTERM

## [DONE] Light and Dark Theme — toggle in topbar, saved to profile + localStorage

## [DONE] Allow for retry before saying a system/service is down —
##     OFFLINE_MISS_LIMIT constant (default 2 = ~90s grace period)

## [DONE] Download RossVideo Status Client XCL file — xcl.php

## [DONE] Bridge 24/7 robustness — content_hash diff broadcast, watchdog,
##     task supervisor, upgrade grace, atomic config save, staggered keepalive
