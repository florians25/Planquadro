# Planquadro – Rechte für den Anwendungsbenutzer (nur bei der Erstinitialisierung
# des MariaDB-Volumes; wird vom MariaDB-Entrypoint eingelesen, nicht ausgeführt).
#
# Der Benutzer erhält zusätzlich zu MARIADB_DATABASE alle Rechte auf Datenbanken
# mit dem Präfix "planquadro_". Benötigt für:
#   - Restore und Restore-Test in neue/temporäre Datenbanken (planquadro_rt_…)
#   - SaaS: Mandanten-Datenbanken (planquadro_t_…) und Plattform-DB (planquadro_platform)
# Bestehende Volumes: einmalig manuell ausführen (docs/BACKUP.md, „Rechte“).

if [ -n "${MARIADB_USER:-}" ]; then
    docker_process_sql --database=mysql <<-EOSQL
	GRANT ALL PRIVILEGES ON \`planquadro\\_%\`.* TO '${MARIADB_USER}'@'%';
	FLUSH PRIVILEGES;
	EOSQL
fi
