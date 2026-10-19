# どんなdockerイメージを利用して構築をするか
FROM php:8.5.11-apache

ARG TZ
# ホストの作業ユーザーに www-data の UID/GID を合わせ、バインドマウント上のファイル所有者を揃える
ARG UID=1000
ARG GID=1000
# Node.js 26.11.1（2026-10-07 時点の最新 Current）
ARG NODE_VERSION=26.11.1

# 設定ファイルをdockerコンテナ内のPHP、Apacheに読み込ませる
COPY php.ini /usr/local/etc/php/
COPY 000-default.conf /etc/apache2/sites-enabled/

# Composer 2.10.3（公式イメージのバイナリを固定）
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

# ミドルウェア、PHP拡張、Node.js、Apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    gettext git \
    libzip-dev zip unzip \
    libjpeg-dev \
    libpng-dev \
    libwebp-dev \
    libonig-dev \
    libicu-dev \
    mariadb-client \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install bcmath gd gettext intl mysqli pdo pdo_mysql zip exif \
 && arch="$(dpkg --print-architecture)" \
 && case "$arch" in \
      amd64) node_arch="x64"; node_sha="15fc043a51a5783f2f4b634561091658f5a7f0e3db03f997dca3a8033a33e45b" ;; \
      arm64) node_arch="arm64"; node_sha="170454fb022f5e9b5f6cc76c25fa32ef5100895be44eb04e92f9780591491bbf" ;; \
      *) echo "unsupported architecture: $arch" >&2; exit 1 ;; \
    esac \
 && curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-${node_arch}.tar.gz" -o /tmp/node.tar.gz \
 && echo "${node_sha}  /tmp/node.tar.gz" | sha256sum -c - \
 && tar -xzf /tmp/node.tar.gz -C /usr/local --strip-components=1 \
 && rm /tmp/node.tar.gz \
 && a2enmod rewrite \
 && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
 && rm -rf /var/lib/apt/lists/* \
 && groupmod -o -g "$GID" www-data \
 && usermod -o -u "$UID" -g "$GID" www-data \
 && chown www-data:www-data /var/www

USER root
RUN docker-php-ext-install pcntl

# Trafilatura は PHP から標準入力で呼ぶ。巡回は backend と scheduler が同じイメージで動く。
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 python3-venv \
 && python3 -m venv /opt/trafilatura \
 && /opt/trafilatura/bin/pip install --no-cache-dir 'trafilatura==2.3.1' \
 && rm -rf /var/lib/apt/lists/*

# Apache と docker compose exec の既定ユーザーを www-data にする（root 作業は exec -u root）
USER www-data
