# syntax=docker/dockerfile:1
# Planquadro – Container-Image (PHP 8.3 + Apache)
# ---------------------------------------------------------------------------
# Bauen:   docker build -t planquadro/app .
#          (bei Docker-Hub-Limits: --build-arg BASE=mirror.gcr.io/library/php:8.3-apache)
# Läuft als www-data (uid 33) auf Port 8080; DocumentRoot = public/, dadurch
# greift public/.htaccess unverändert. Persistente Daten unter /data
# (Dateien: /data/storage, lokale Backups: /data/backups).
# Siehe docs/INSTALLATION.md und docs/CONFIGURATION.md.

ARG BASE=docker.io/library/php:8.3-apache

# ---------------------------------------------------------------------------
# Stufe 1: PHP-Erweiterungen kompilieren (Build-Abhängigkeiten bleiben hier)
# ---------------------------------------------------------------------------
FROM ${BASE} AS ext-build
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libzip-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl calendar zip pcntl; \
    php -m | grep -qi opcache || docker-php-ext-install opcache; \
    # Laufzeit-Bibliotheken der neuen Erweiterungen ermitteln (Paketnamen
    # hängen von der Debian-Version ab, daher nicht fest verdrahtet)
    extdir="$(php -r 'echo ini_get("extension_dir");')"; \
    find "$extdir" -name '*.so' -exec ldd {} + 2>/dev/null \
      | awk '/=> \// { print $3 }' | sort -u \
      | xargs -r realpath | xargs -r dpkg -S 2>/dev/null \
      | cut -d: -f1 | sort -u | grep -v -- '-dev$' > /runtime-packages.txt; \
    cat /runtime-packages.txt

# ---------------------------------------------------------------------------
# Stufe 2: Laufzeit-Image (ohne Compiler, ohne Dev-Abhängigkeiten)
# ---------------------------------------------------------------------------
FROM ${BASE} AS runtime
ARG INSTALL_RCLONE=1
ARG PQ_VERSION=dev
LABEL org.opencontainers.image.title="Planquadro" \
      org.opencontainers.image.description="Planquadro – Einsatz-, Team- und Abwesenheitsplanung" \
      org.opencontainers.image.version="${PQ_VERSION}" \
      org.opencontainers.image.source="https://github.com/florians25/planquadro"

COPY --from=ext-build /runtime-packages.txt /tmp/runtime-packages.txt
COPY --from=ext-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=ext-build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $(cat /tmp/runtime-packages.txt) \
        mariadb-client tini ca-certificates; \
    if [ "${INSTALL_RCLONE}" = "1" ]; then apt-get install -y --no-install-recommends rclone; fi; \
    rm -rf /var/lib/apt/lists/* /tmp/runtime-packages.txt; \
    for m in pdo_mysql intl calendar zip pcntl sodium; do php -m | grep -qi "^$m\$"; done; \
    php -r 'exit(function_exists("opcache_get_status") ? 0 : 1);'; \
    a2enmod rewrite headers expires; \
    a2dissite 000-default; \
    rm -f /etc/apache2/ports.conf && touch /etc/apache2/ports.conf; \
    cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

COPY docker/apache/planquadro.conf /etc/apache2/sites-available/planquadro.conf
COPY docker/apache/security.conf /etc/apache2/conf-available/planquadro-security.conf
COPY docker/php/planquadro.ini /usr/local/etc/php/conf.d/zz-planquadro.ini
RUN set -eux; \
    a2ensite planquadro; a2disconf security; a2enconf planquadro-security; \
    # vom Entrypoint zur Laufzeit geschrieben (Vertrauen in den Reverse Proxy)
    touch /etc/apache2/conf-enabled/zz-planquadro-runtime.conf; \
    chown www-data:www-data /etc/apache2/conf-enabled/zz-planquadro-runtime.conf; \
    mkdir -p /data/storage /data/backups /data/sessions; chmod 0700 /data/sessions; \
    chown -R www-data:www-data /data; chmod 0750 /data

WORKDIR /var/www/html
COPY --chown=root:www-data . /var/www/html
RUN set -eux; \
    rm -rf /var/www/html/tests /var/www/html/vendor /var/www/html/app/storage; \
    find /var/www/html -type d -exec chmod 0755 {} +; \
    find /var/www/html -type f -exec chmod 0644 {} +; \
    chmod 0755 /var/www/html/docker/entrypoint.sh; \
    # Alt-Backup-Ordner (Fallback ohne /data) beschreibbar für den Webserver
    chown www-data:www-data /var/www/html/app/backups; chmod 0750 /var/www/html/app/backups; \
    php -l public/index.php >/dev/null; php -l app/lib/Backup.php >/dev/null

ENV PQ_STORAGE_DIR=/data/storage \
    PQ_BACKUP_DIR=/data/backups \
    PQ_TIMEZONE=Europe/Berlin \
    TZ=Europe/Berlin \
    APACHE_LISTEN_PORT=8080

USER www-data
EXPOSE 8080
VOLUME ["/data"]
STOPSIGNAL SIGWINCH
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1:8080/api/healthz || exit 1

ENTRYPOINT ["/usr/bin/tini", "--", "/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
