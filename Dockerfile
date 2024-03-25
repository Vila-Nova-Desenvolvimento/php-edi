FROM php:8.2-fpm

# Arguments defined in docker-compose.yml
ARG user
ARG uid

# Install system dependencies
RUN apt-get update && apt-get install -y \
  git \
  curl \
  gnupg \
  libaio1 \
  libpng-dev \
  libonig-dev \
  libxml2-dev \
  libzip-dev \
  zip \
  unzip \
  libicu-dev \
  supervisor


# Clear cache
RUN apt-get purge -y && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Oracle Instant Client
RUN mkdir /opt/oracle && cd /opt/oracle && \
  curl -O --insecure https://download.oracle.com/otn_software/linux/instantclient/1912000/instantclient-basiclite-linux.x64-19.12.0.0.0dbru.zip && \
  curl -O --insecure https://download.oracle.com/otn_software/linux/instantclient/1912000/instantclient-sdk-linux.x64-19.12.0.0.0dbru.zip && \
  unzip instantclient-basiclite-linux.x64-19.12.0.0.0dbru.zip && \
  unzip instantclient-sdk-linux.x64-19.12.0.0.0dbru.zip && \
  rm instantclient-basiclite-linux.x64-19.12.0.0.0dbru.zip && \
  rm instantclient-sdk-linux.x64-19.12.0.0.0dbru.zip && \
  cd /opt/oracle/instantclient_19_12 && \
  export LD_LIBRARY_PATH=/opt/oracle/instantclient_19_12:$LD_LIBRARY_PATH && \
  echo 'export LD_LIBRARY_PATH="/opt/oracle/instantclient_19_12:$LD_LIBRARY_PATH"' >> /etc/profile.d/oracle.sh

# Add Oracle Instant Client to the library path
RUN echo '/opt/oracle/instantclient_19_12/' > /etc/ld.so.conf.d/oracle-instantclient.conf && \
  ldconfig

# Install lib intl
RUN docker-php-ext-configure intl && docker-php-ext-install intl && docker-php-ext-enable intl

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd sockets zip soap

# Install Xdebug extension
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Install redis
RUN pecl install -o -f redis \
  &&  rm -rf /tmp/pear \
  &&  docker-php-ext-enable redis \
  && docker-php-ext-configure oci8 --with-oci8=instantclient,/opt/oracle/instantclient_19_12 \
  && docker-php-ext-configure pdo_oci --with-pdo-oci=instantclient,/opt/oracle/instantclient_19_12 \
  && docker-php-ext-install oci8 pdo_oci

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
RUN useradd -G www-data,root -u $uid -d /home/$user $user
RUN mkdir -p /home/$user/.composer && \
  chown -R $user:$user /home/$user

# Install LDAP extension
RUN apt-get update && \
    apt-get install -y libldap2-dev && \
    rm -rf /var/lib/apt/lists/* && \
    docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu/ && \
    docker-php-ext-install ldap

# Install mongodb
RUN pecl install mongodb

# Set working directory
WORKDIR /var/www

# Instale o Yarn
RUN curl -sS https://dl.yarnpkg.com/debian/pubkey.gpg | apt-key add - && \
    echo "deb https://dl.yarnpkg.com/debian/ stable main" | tee /etc/apt/sources.list.d/yarn.list && \
    apt-get update && \
    apt-get install -y yarn && \
    rm -rf /var/lib/apt/lists/*

# Configurações Xdebug
COPY docker-compose/php/php.ini $PHP_INI_DIR/conf.d/

# Portas usadas pelo xdebug e php-fpm
EXPOSE 9003 9000

USER $user
