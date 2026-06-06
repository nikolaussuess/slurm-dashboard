FROM php:8.4-apache

LABEL org.opencontainers.image.title="Slurm Dashboard" \
      org.opencontainers.image.description="PHP web dashboard for SLURM clusters via slurmrestd (Unix socket or TCP)" \
      org.opencontainers.image.source="https://github.com/nikolaussuess/slurm-dashboard" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.authors="Nikolaus Süß <web@suess.dev>"

# UID/GID must match the user slurmrestd runs as on the host (if using unix sockets)
# so the unix socket (bind-mounted via -v) is accessible.
ARG DASHBOARD_UID=994
ARG DASHBOARD_GID=994

RUN groupadd -g "${DASHBOARD_GID}" slurm-dashboard \
 && useradd -u "${DASHBOARD_UID}" -g slurm-dashboard -r -M -s /usr/sbin/nologin slurm-dashboard

# The container has a single purpose, so running all of Apache as
# slurm-dashboard is acceptable and simpler than mpm-itk's per-vhost switching.
# APACHE_RUN_USER/GROUP are read by /etc/apache2/envvars in the base image.
ENV APACHE_RUN_USER=slurm-dashboard
ENV APACHE_RUN_GROUP=slurm-dashboard

# Install dependencies
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libldap2-dev \
      libssh2-1-dev \
      curl \
 && docker-php-ext-configure ldap \
 && docker-php-ext-install -j"$(nproc)" ldap \
 && pecl install apcu ssh2 \
 && docker-php-ext-enable apcu ssh2 \
 && rm -rf /var/lib/apt/lists/*

RUN printf 'ServerTokens Prod\nServerSignature Off\n' \
      > /etc/apache2/conf-available/docker-security.conf \
 && a2enconf docker-security

RUN echo 'expose_php = Off' > /usr/local/etc/php/conf.d/docker-security.ini

# Apache configuration
RUN a2enmod env rewrite headers \
 && a2dissite 000-default

# All env vars passed via `docker run -e` / --env-file are forwarded to PHP
# through Apache's PassEnv (mod_env). Unset variables are silently ignored.
RUN cat > /etc/apache2/sites-available/slurm-dashboard.conf <<'APACHEEOF'
<VirtualHost *:80>
    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        AllowOverride None
        Require all granted

        # Security headers (inlined from public/.htaccess — no per-request htaccess lookup in Docker)
        Header always set X-Frame-Options "SAMEORIGIN"
        Header set X-XSS-Protection "1; mode=block"
        Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self';"
        Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        Header set X-Content-Type-Options "nosniff"
    </Directory>

    # Required
    PassEnv CLUSTER_NAME ADMIN_NAMES ADMIN_EMAIL SLURM_LOGIN_NODE WIKI_LINK

    # REST API connection (unix socket or TCP)
    PassEnv CONNECTION_MODE UNIX_SOCKET_PATH
    PassEnv SLURM_TCP_HOST SLURM_TCP_PORT SLURM_TCP_CA_CERT SLURM_TCP_PROTOCOL
    PassEnv REST_API_VERSION

    # LDAP authentication
    PassEnv LDAP_URI LDAP_BASE LDAP_ADMIN_USER LDAP_ADMIN_PASSWORD

    # Local (SSH) authentication
    PassEnv SSH_SERVER_URL

    # JWT
    PassEnv JWT_PATH SLURM_USER

    # Misc / feature flags
    PassEnv PRIV_USERS USE_CACHE
    PassEnv FEATURE_P_LOW_IN_CLUSTER_OVERVIEW FEATURE_RESOURCES_PER_USER

    # Log on stdout / stderr so docker shows them
    ErrorLog  /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
</VirtualHost>
APACHEEOF

RUN a2ensite slurm-dashboard

# Application files
# Files: 640 (root:slurm-dashboard) — protects globals.inc.php if passwords are set there.
# Directories: 755 — must remain world-traversable for Apache's initial path resolution.
COPY --chown=root:slurm-dashboard . /var/www/html/
RUN chown root:slurm-dashboard /var/www/html \
 && find /var/www/html -type f -exec chmod 640 {} \; \
 && find /var/www/html -type d -exec chmod 755 {} \;

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsSo /dev/null http://localhost/ || exit 1

# Mount unix socket dir and/or JWT key at runtime, e.g.:
#   -v /run/slurmrestd:/run/slurmrestd
#   -v /var/lib/slurm-dashboard/jwt.key:/var/lib/slurm-dashboard/jwt.key:ro
CMD ["apache2-foreground"]
