FROM php:8.2-apache

# Dependencias do sistema
RUN apt-get update && apt-get install -y --no-install-recommends \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    libcurl4-openssl-dev \
    ffmpeg \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Extensoes PHP (GD para imagens)
RUN docker-php-ext-configure gd \
        --with-jpeg \
        --with-webp \
        --with-freetype \
    && docker-php-ext-install -j$(nproc) gd

# Apache: habilita mod_rewrite e configura DocumentRoot pro webhook
RUN a2enmod rewrite

# Configura VirtualHost apontando pro /var/www/html/webhook
# PassEnv garante que env vars do Docker cheguem no PHP
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html/webhook\n\
    <Directory /var/www/html/webhook>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    PassEnv TELEGRAM_BOT_TOKEN\n\
    PassEnv TELEGRAM_GROUP_ID\n\
    PassEnv COBALT_URL\n\
    PassEnv WELLDEV_API_URL\n\
    PassEnv WELLDEV_API_KEY\n\
    PassEnv OPENAI_API_KEY\n\
    PassEnv OPENROUTER_API_KEY\n\
    PassEnv INSTAGRAM_ACCESS_TOKEN\n\
    PassEnv INSTAGRAM_ACCOUNT_ID\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Copia projeto
COPY . /var/www/html/

# Permissoes das pastas de storage
RUN mkdir -p /var/www/html/storage/uploads \
             /var/www/html/storage/processed \
             /var/www/html/storage/queue \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 755 /var/www/html/storage

# PHP config: aumenta limites pra upload de midia
RUN echo "upload_max_filesize = 50M\n\
post_max_size = 50M\n\
memory_limit = 256M\n\
max_execution_time = 120" > /usr/local/etc/php/conf.d/midiaflow.ini

EXPOSE 80
