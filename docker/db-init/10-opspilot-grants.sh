# OpsPilot – Rechte für den Anwendungsbenutzer (nur bei der Erstinitialisierung
# des MariaDB-Volumes; wird vom MariaDB-Entrypoint eingelesen, nicht ausgeführt).
#
# Der Benutzer erhält zusätzlich zu MARIADB_DATABASE alle Rechte auf Datenbanken
# mit dem Präfix "opspilot_". Benötigt für:
#   - Restore und Restore-Test in neue/temporäre Datenbanken (opspilot_rt_…)
#   - SaaS: Mandanten-Datenbanken (opspilot_t_…) und Plattform-DB (opspilot_platform)
# Bestehende Volumes: einmalig manuell ausführen (docs/BACKUP.md, „Rechte“).

if [ -n "${MARIADB_USER:-}" ]; then
    docker_process_sql --database=mysql <<-EOSQL
	GRANT ALL PRIVILEGES ON \`opspilot\\_%\`.* TO '${MARIADB_USER}'@'%';
	FLUSH PRIVILEGES;
	EOSQL
fi
