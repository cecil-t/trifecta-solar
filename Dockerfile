# Trifecta Solar Tracker - runtime image
# PHP 8.3 + Apache. SQLite (pdo_sqlite) and Argon2 password hashing are built into the official image.
FROM php:8.3-apache

ENV TZ=America/New_York

RUN a2enmod headers \
	&& mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
	&& echo "ServerTokens Prod\nServerSignature Off\nServerName trifecta-tracker" > /etc/apache2/conf-enabled/zz-security.conf \
	&& ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# SAM (System Advisor Model, National Laboratory of the Rockies) for production estimates: PySAM in
# its own Python venv, plus SAM's CEC module and inverter libraries from the matching SAM release
# (checked by hash). Both live in /opt/sam, outside the app folder, because docker-compose.yml
# bind-mounts the repo over /var/www/app. bin/sam.py runs it; App\Sam calls that script.
# To upgrade: change the versions and hashes here, rebuild, then run Lists > SAM > Check.
ARG PYSAM_VERSION=8.0.0
ARG SAM_LIBRARY_TAG=2026.7.3.r0.ssc.308
ARG SAM_MODULES_SHA256=a28cf8956a839ed98bb941809b439e4907f5b4c237bd9299bc123fb502c29aff
ARG SAM_INVERTERS_SHA256=0c1b2188415dad6928f91f1a4c9a820dadc80962549f903d57d9282ba3753760
RUN apt-get update \
	&& apt-get install -y --no-install-recommends python3 python3-venv \
	&& rm -rf /var/lib/apt/lists/* \
	&& python3 -m venv /opt/sam \
	&& /opt/sam/bin/pip install --no-cache-dir "nlr-pysam==${PYSAM_VERSION}" \
	&& mkdir -p /opt/sam/lib \
	&& curl -fsSL -o /opt/sam/lib/cec_modules.csv "https://raw.githubusercontent.com/NatLabRockies/SAM/${SAM_LIBRARY_TAG}/deploy/libraries/CEC%20Modules.csv" \
	&& curl -fsSL -o /opt/sam/lib/cec_inverters.csv "https://raw.githubusercontent.com/NatLabRockies/SAM/${SAM_LIBRARY_TAG}/deploy/libraries/CEC%20Inverters.csv" \
	&& printf '%s  %s\n%s  %s\n' "$SAM_MODULES_SHA256" /opt/sam/lib/cec_modules.csv "$SAM_INVERTERS_SHA256" /opt/sam/lib/cec_inverters.csv | sha256sum -c - \
	&& echo "$SAM_LIBRARY_TAG" > /opt/sam/lib/VERSION

# WeasyPrint turns the app's HTML (proposals, reports) into PDF: its own venv in /opt/pdf, plus the
# Pango and HarfBuzz libraries it draws text with and basic fonts (Liberation is metric-compatible
# with Arial, Helvetica and Times). App\Pdf calls it. To upgrade: change the version, rebuild, then
# run Lists > SAM > Test PDF.
ARG WEASYPRINT_VERSION=70.0
RUN apt-get update \
	&& apt-get install -y --no-install-recommends libpango-1.0-0 libpangoft2-1.0-0 libharfbuzz-subset0 fonts-liberation fonts-dejavu-core \
	&& rm -rf /var/lib/apt/lists/* \
	&& python3 -m venv /opt/pdf \
	&& /opt/pdf/bin/pip install --no-cache-dir "weasyprint==${WEASYPRINT_VERSION}" \
	&& /opt/pdf/bin/python -m weasyprint --version

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-trifecta.ini"
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/trifecta-entrypoint
RUN chmod +x /usr/local/bin/trifecta-entrypoint

WORKDIR /var/www/app
# The code is baked into the image, but docker-compose.yml also bind-mounts the
# repo over this path so a `git pull` on the host goes live without a rebuild.
COPY . /var/www/app

ENTRYPOINT ["trifecta-entrypoint"]
CMD ["apache2-foreground"]
