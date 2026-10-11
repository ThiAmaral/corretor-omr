FROM php:8.3-apache

# Python + leitor óptico (OpenCV) + poppler para converter PDF em imagem
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 python3-pip poppler-utils \
 && rm -rf /var/lib/apt/lists/*
COPY worker/requirements.txt /tmp/requirements.txt
RUN pip3 install --no-cache-dir --break-system-packages -r /tmp/requirements.txt

# Uploads maiores (lotes de folhas digitalizadas)
RUN { echo "upload_max_filesize=50M"; echo "post_max_size=200M"; echo "max_file_uploads=100"; \
      echo "max_execution_time=300"; echo "memory_limit=512M"; } > /usr/local/etc/php/conf.d/corretor.ini

COPY worker /var/www/worker
COPY web /var/www/html
RUN mkdir -p /var/www/armazenamento && chown -R www-data:www-data /var/www/armazenamento

ENV ARMAZENAMENTO=/var/www/armazenamento \
    OMR_PYTHON=python3 \
    LIMITE_REFORCO=60 \
    PERIODO_ESCOLA=bimestre
