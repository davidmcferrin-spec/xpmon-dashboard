##
## TO DO ITEMS
##

## Add Local and LDAP Auth, Bind to User, no search,
##     Manually add approved users and groups

## Change to profile based Alerts, allow a user
##     to change what Systems and how alerts
##     (Flash/Horn/Both/None) happen

## Allow user to pick if they want 'Ignored' services to be shown

## Allow user/admin to hide Windows Updates and Door (Admin can set global and override user)

## COMPLETED ──────────────────────────────────────────────────────────────────

## [DONE] Make bridge stop faster — fast shutdown via SIGTERM, force-closes
##     all WebSocket connections immediately, returns in <2s

## [DONE] Light and Dark Theme — toggle in topbar, saved to localStorage,
##     applies to dashboard and bridge admin page

## [DONE] Allow for retry before saying a system/service is down —
##     OFFLINE_MISS_LIMIT constant (default 2 = ~90s grace period)

## [DONE] Download RossVideo Status Client XCL file — xcl.php generates
##     a native-compatible StatusClientList.xcl from config.json

## [DONE] Bridge 24/7 robustness — content_hash diff broadcast, smart
##     _dispatch, atomic config save, staggered connect/keepalive, unified
##     offline watchdog with force reconnect, task supervisor, health log,
##     WS ping/dead-client pruning, host_command with per-host lock,
##     TCP SO_KEEPALIVE, asyncio exception handler
