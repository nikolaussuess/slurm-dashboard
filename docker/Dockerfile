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
COPY docker/slurm-dashboard.conf /etc/apache2/sites-available/slurm-dashboard.conf

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
