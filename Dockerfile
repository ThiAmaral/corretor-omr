FROM php:8.3-apache

# Python + leitor óptico (OpenCV) + poppler para converter PDF em imagem.
# libpq + pdo_pgsql para o PostgreSQL do Supabase (a extensão curl já vem na imagem).
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 python3-pip poppler-utils libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*
COPY worker/requirements.txt /tmp/requirements.txt
RUN pip3 install --no-cache-dir --break-system-packages -r /tmp/requirements.txt

# Uploads maiores (lotes de folhas digitalizadas)
RUN { echo "upload_max_filesize=50M"; echo "post_max_size=200M"; echo "max_file_uploads=100"; \
      echo "max_execution_time=300"; echo "memory_limit=512M"; } > /usr/local/etc/php/conf.d/corretor.ini

COPY worker /var/www/worker
COPY web /var/www/html

# Banco e imagens ficam no Supabase (variáveis do .env); o container não guarda dados.
ENV OMR_PYTHON=python3 \
    LIMITE_REFORCO=60 \
    PERIODO_ESCOLA=bimestre
