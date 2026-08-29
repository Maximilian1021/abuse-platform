-- =============================================================================
--  Auth-Monitor-Tabellen entfernen
--
--  Diese Tabellen werden nach dem Ausbau des Auth-Monitors nicht mehr benutzt.
--  Erst ausführen, wenn du sicher bist, dass keine Daten daraus gebraucht werden.
--  Die Login-/Report-/Hoster-Tabellen bleiben unangetastet.
--
--  Ausführen:
--    mysql -h HOST -P PORT -u USER -p DBNAME < sql/drop_auth_monitor.sql
-- =============================================================================

DROP TABLE IF EXISTS auth_events;
DROP TABLE IF EXISTS daily_stats;
DROP TABLE IF EXISTS ip_info;
DROP TABLE IF EXISTS platform_servers;
DROP TABLE IF EXISTS platform_reg_tokens;
DROP TABLE IF EXISTS auth_reports;
