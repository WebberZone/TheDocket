#!/usr/bin/env bash
#
# Docket installer for a fresh Ubuntu/Debian server.
#
# Usage:
#   sudo bash tools/install.sh
#
# Non-interactive use (every prompt has an override):
#   sudo DOCKET_DOMAIN=read.example.com DOCKET_PATH=/var/www/docket \
#        DOCKET_HTTPS=yes DOCKET_EMAIL=you@example.com DOCKET_YES=1 \
#        bash tools/install.sh
#
set -euo pipefail

REPO_URL="https://github.com/WebberZone/TheDocket.git"
REPO_HTTPS="https://github.com/WebberZone/TheDocket"
DEFAULT_PATH="/var/www/docket"

DOCKET_DOMAIN="${DOCKET_DOMAIN:-}"
DOCKET_PATH="${DOCKET_PATH:-$DEFAULT_PATH}"
DOCKET_HTTPS="${DOCKET_HTTPS:-yes}"
DOCKET_EMAIL="${DOCKET_EMAIL:-}"
DOCKET_YES="${DOCKET_YES:-}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

ask() {
    # ask <prompt> <default> -> answer on stdout
    local prompt="$1" default="${2:-}" answer
    if [ -n "$default" ]; then
        read -r -p "$prompt [$default]: " answer || true
        printf '%s' "${answer:-$default}"
    else
        read -r -p "$prompt: " answer || true
        printf '%s' "$answer"
    fi
}

confirm() {
    # confirm <prompt> -> exit 1 unless y/yes (or DOCKET_YES=1)
    [ "$DOCKET_YES" = "1" ] && return 0
    local answer
    read -r -p "$1 [y/N]: " answer || true
    case "$answer" in
        y|Y|yes|YES) return 0 ;;
        *) return 1 ;;
    esac
}

# ---------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------

if [ "$(id -u)" -ne 0 ]; then
    die "This installer must run as root (use sudo)."
fi

if [ ! -f /etc/os-release ]; then
    die "Cannot detect the OS — this installer targets Ubuntu and Debian."
fi
. /etc/os-release
case "${ID:-}" in
    ubuntu|debian) ;;
    *) die "Unsupported OS '${ID:-unknown}' — this installer targets Ubuntu and Debian." ;;
esac

log "Detected OS: ${PRETTY_NAME:-$ID}"

# Refuse to touch an existing, non-empty install path.
if [ -d "$DOCKET_PATH" ] && [ -n "$(ls -A "$DOCKET_PATH" 2>/dev/null)" ]; then
    die "$DOCKET_PATH already exists and is not empty — refusing to overwrite it."
fi

# Port 80 must be free, or already served by nginx (a vhost will be added).
port80_owner=""
if command -v ss >/dev/null 2>&1; then
    port80_owner="$(ss -lptnH 'sport = :80' 2>/dev/null || true)"
fi
if [ -n "$port80_owner" ] && ! printf '%s' "$port80_owner" | grep -qi 'nginx'; then
    die "Something other than nginx is listening on port 80. Free the port (or configure that server yourself) and re-run."
fi

# Show the detected public IP so the DNS A record can be checked first.
detected_ip="$(curl -fsS --max-time 10 https://api.ipify.org 2>/dev/null || true)"
if [ -n "$detected_ip" ]; then
    log "This server's public IP appears to be: $detected_ip"
fi

if [ -z "$DOCKET_DOMAIN" ]; then
    echo
    echo "Point your domain's DNS A record at this server's IP first."
    DOCKET_DOMAIN="$(ask 'Domain Docket will be served from (e.g. read.example.com)')"
fi
[ -n "$DOCKET_DOMAIN" ] || die "A domain is required."

if [ "$DOCKET_HTTPS" != "yes" ] && [ "$DOCKET_HTTPS" != "no" ]; then
    die "DOCKET_HTTPS must be 'yes' or 'no'."
fi

echo
echo "  Domain:          https://$DOCKET_DOMAIN"
echo "  Install path:    $DOCKET_PATH"
echo "  HTTPS (certbot): $DOCKET_HTTPS"
echo
confirm "Proceed with the installation?" || die "Aborted."

# ---------------------------------------------------------------------
# Packages
# ---------------------------------------------------------------------

export DEBIAN_FRONTEND=noninteractive

apt-get update -qq
apt-get install -y -qq ca-certificates curl git unzip sqlite3 gnupg lsb-release apt-transport-https

# Pick the newest available 8.x php-fpm package (php8.6-fpm … php8.3-fpm).
pick_php_version() {
    local v
    for v in 8.6 8.5 8.4 8.3; do
        if apt-cache show "php${v}-fpm" >/dev/null 2>&1; then
            printf '%s' "$v"
            return 0
        fi
    done
    return 1
}

php_version="$(pick_php_version || true)"

# None found: the distro's PHP is older than 8.3, so add a newer-PHP
# repository and look again.
if [ -z "$php_version" ]; then
    if [ "$ID" = "ubuntu" ]; then
        log "Adding ppa:ondrej/php for PHP >= 8.3"
        apt-get install -y -qq software-properties-common
        add-apt-repository -y ppa:ondrej/php
    else
        log "Adding packages.sury.org for PHP >= 8.3"
        curl -fsSL https://packages.sury.org/php/apt.gpg -o /etc/apt/trusted.gpg.d/sury-php.gpg
        echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/sury-php.list
    fi
    apt-get update -qq
    php_version="$(pick_php_version || true)"
fi

[ -n "$php_version" ] || die "No php-fpm package >= 8.3 available after repository setup."

log "Installing PHP $php_version (FPM) and extensions"
apt-get install -y -qq nginx \
    "php${php_version}-fpm" "php${php_version}-cli" \
    "php${php_version}-sqlite3" "php${php_version}-mbstring" \
    "php${php_version}-xml" "php${php_version}-curl" \
    "php${php_version}-intl" "php${php_version}-zip"

PHP_BIN="/usr/bin/php${php_version}"
command -v "$PHP_BIN" >/dev/null 2>&1 || PHP_BIN="$(command -v php)"

# ---------------------------------------------------------------------
# Application code
# ---------------------------------------------------------------------

mkdir -p "$DOCKET_PATH"

use_dist=0
if git ls-remote --exit-code --heads "$REPO_URL" dist >/dev/null 2>&1; then
    use_dist=1
fi

if [ "$use_dist" -eq 1 ]; then
    log "Cloning the prebuilt 'dist' branch (no build needed)"
    git clone --depth 1 --branch dist "$REPO_URL" "$DOCKET_PATH"
else
    log "No 'dist' branch yet — cloning 'master' and building from source"
    git clone "$REPO_URL" "$DOCKET_PATH"

    # Composer, verified against its published SHA-384.
    if ! command -v composer >/dev/null 2>&1; then
        log "Installing Composer"
        installer="$(mktemp)"
        curl -fsSL https://getcomposer.org/installer -o "$installer"
        expected="$(curl -fsSL https://composer.github.io/installer.sig)"
        actual="$(php -r 'echo hash_file("sha384", $argv[1]);' "$installer")"
        [ "$expected" = "$actual" ] || die "Composer installer checksum mismatch."
        php "$installer" --install-dir=/usr/local/bin --filename=composer --quiet
        rm -f "$installer"
    fi

    # Node 22 via NodeSource.
    if ! command -v node >/dev/null 2>&1 || [ "$(node -v | cut -d. -f1 | tr -d v)" -lt 22 ]; then
        log "Installing Node.js 22 (NodeSource)"
        curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
        apt-get install -y -qq nodejs
    fi

    (cd "$DOCKET_PATH" && composer install --no-dev --optimize-autoloader --no-interaction)
    (cd "$DOCKET_PATH" && npm ci && npm run build)
fi

chown -R www-data:www-data "$DOCKET_PATH"

# ---------------------------------------------------------------------
# nginx virtual host
# ---------------------------------------------------------------------

fpm_sock=""
for sock in "/run/php/php${php_version}-fpm.sock" /run/php/php*-fpm.sock; do
    if [ -S "$sock" ]; then
        fpm_sock="$sock"
        break
    fi
done
[ -n "$fpm_sock" ] || die "Could not find the PHP-FPM socket under /run/php/."

vhost="/etc/nginx/sites-available/${DOCKET_DOMAIN}"
if [ -e "$vhost" ] || [ -e "/etc/nginx/sites-enabled/${DOCKET_DOMAIN}" ]; then
    die "${vhost} already exists — refusing to overwrite another site's config. Remove or rename it first."
fi

cat > "$vhost" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOCKET_DOMAIN};
    root ${DOCKET_PATH}/public;
    index index.php;

    client_max_body_size 25M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:${fpm_sock};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
EOF

ln -sf "$vhost" "/etc/nginx/sites-enabled/${DOCKET_DOMAIN}"

# Other sites may already be hosted here: sites-enabled/default stays.
nginx -t
systemctl reload nginx

# ---------------------------------------------------------------------
# HTTPS
# ---------------------------------------------------------------------

scheme="http"
if [ "$DOCKET_HTTPS" = "yes" ]; then
    log "Obtaining a TLS certificate with certbot"
    apt-get install -y -qq certbot python3-certbot-nginx

    certbot_args=(--nginx -d "$DOCKET_DOMAIN" --redirect --agree-tos --non-interactive)
    if [ -n "$DOCKET_EMAIL" ]; then
        certbot_args+=(-m "$DOCKET_EMAIL")
    else
        certbot_args+=(--register-unsafely-without-email)
    fi

    # A failure here (usually DNS not pointing at the server yet) must not
    # abort the install — the app is already deployed over http.
    if certbot "${certbot_args[@]}"; then
        scheme="https"
    else
        warn "certbot could not get a certificate — most often the domain's DNS does not point at this server yet."
        warn "Once the A record resolves here, run:"
        warn "  certbot --nginx -d ${DOCKET_DOMAIN} --redirect"
        warn "Then continue below using https instead of http."
    fi
fi

# ---------------------------------------------------------------------
# Cron for www-data (idempotent)
# ---------------------------------------------------------------------

cron_line="* * * * * cd ${DOCKET_PATH} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1"
update_line="# 15 4 * * * ${DOCKET_PATH}/tools/update.sh --yes >> /dev/null 2>&1"

existing_cron="$(crontab -u www-data -l 2>/dev/null || true)"
if ! printf '%s\n' "$existing_cron" | grep -qF 'artisan schedule:run'; then
    printf '%s\n%s\n%s\n' "$existing_cron" "$cron_line" "$update_line" | grep -v '^$' | crontab -u www-data -
    log "Cron added for www-data"
else
    log "Cron line already present — left alone"
fi

# ---------------------------------------------------------------------
# Requirements check + setup code
# ---------------------------------------------------------------------

log "Checking server requirements"
sudo -u www-data "$PHP_BIN" "$DOCKET_PATH/artisan" docket:check-requirements || true

setup_code="$(cd "$DOCKET_PATH" && sudo -u www-data "$PHP_BIN" artisan docket:setup-code)"

echo
log "Docket is deployed."
echo
echo "  Finish the install in your browser:"
echo "    ${scheme}://${DOCKET_DOMAIN}/install"
echo
echo "  Setup code (the wizard asks for it):"
echo "    $setup_code"
echo
echo "  The same code is also in ${DOCKET_PATH}/storage/app/install/setup-code"
echo "  and printed by: php artisan docket:setup-code"
echo
