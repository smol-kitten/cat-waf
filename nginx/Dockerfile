FROM nginx:1.25-alpine

# Install dependencies
RUN apk add --no-cache \
    gcc \
    libc-dev \
    make \
    openssl \
    openssl-dev \
    pcre-dev \
    pcre2-dev \
    zlib-dev \
    linux-headers \
    curl \
    gnupg \
    libxslt-dev \
    gd-dev \
    geoip-dev \
    perl-dev \
    git \
    libtool \
    automake \
    autoconf \
    g++ \
    flex \
    bison \
    yajl-dev \
    yajl \
    libxml2-dev \
    libmaxminddb-dev \
    curl-dev \
    libstdc++

# Install ModSecurity
WORKDIR /opt
RUN git clone --depth 1 -b v3/master https://github.com/SpiderLabs/ModSecurity && \
    cd ModSecurity && \
    git submodule init && \
    git submodule update && \
    ./build.sh && \
    ./configure --with-pcre2 && \
    make && \
    make install

# Install ModSecurity-nginx connector
RUN git clone --depth 1 https://github.com/SpiderLabs/ModSecurity-nginx.git

# Get NGINX source to compile with ModSecurity
RUN NGINX_VERSION=$(nginx -v 2>&1 | sed -n 's/.*nginx\/\([0-9.]\+\).*/\1/p') && \
    wget http://nginx.org/download/nginx-${NGINX_VERSION}.tar.gz && \
    tar -xzvf nginx-${NGINX_VERSION}.tar.gz

# Compile NGINX with ModSecurity module
RUN NGINX_VERSION=$(nginx -v 2>&1 | sed -n 's/.*nginx\/\([0-9.]\+\).*/\1/p') && \
    cd nginx-${NGINX_VERSION} && \
    ./configure --with-compat --add-dynamic-module=../ModSecurity-nginx --with-http_geoip_module && \
    make modules && \
    cp objs/ngx_http_modsecurity_module.so /etc/nginx/modules/

# Download OWASP CRS
RUN mkdir -p /etc/nginx/modsecurity && \
    git clone https://github.com/coreruleset/coreruleset /etc/nginx/modsecurity/coreruleset && \
    cp /etc/nginx/modsecurity/coreruleset/crs-setup.conf.example /etc/nginx/modsecurity/coreruleset/crs-setup.conf && \
    echo "" >> /etc/nginx/modsecurity/coreruleset/crs-setup.conf && \
    echo "# Set paranoia level" >> /etc/nginx/modsecurity/coreruleset/crs-setup.conf && \
    echo 'SecAction "id:900000,phase:1,nolog,pass,t:none,setvar:tx.paranoia_level=1"' >> /etc/nginx/modsecurity/coreruleset/crs-setup.conf

# Download GeoIP databases
RUN mkdir -p /usr/share/GeoIP && \
    cd /usr/share/GeoIP && \
    wget https://github.com/maxmind/MaxMind-DB/raw/main/test-data/GeoIP2-Country-Test.mmdb -O GeoIP2-Country.mmdb || \
    wget https://github.com/maxmind/MaxMind-DB/raw/main/test-data/GeoLite2-Country-Test.mmdb -O GeoIP2-Country.mmdb || \
    echo "GeoIP database download skipped - configure with your own MaxMind key"

# Download and setup ModSecurity configuration files
RUN cd /etc/nginx/modsecurity && \
    wget https://raw.githubusercontent.com/SpiderLabs/ModSecurity/v3/master/modsecurity.conf-recommended -O modsecurity.conf && \
    wget https://raw.githubusercontent.com/SpiderLabs/ModSecurity/v3/master/unicode.mapping -O unicode.mapping && \
    sed -i 's/SecRuleEngine DetectionOnly/SecRuleEngine On/' modsecurity.conf && \
    sed -i 's|SecAuditLog /var/log/modsec_audit.log|SecAuditLog /var/log/modsec/modsec_audit.log|' modsecurity.conf && \
    sed -i 's/SecAuditEngine RelevantOnly/SecAuditEngine On/' modsecurity.conf && \
    echo "" >> modsecurity.conf && \
    echo "# Include OWASP CRS" >> modsecurity.conf && \
    echo "Include /etc/nginx/modsecurity/coreruleset/crs-setup.conf" >> modsecurity.conf && \
    echo "Include /etc/nginx/modsecurity/coreruleset/rules/*.conf" >> modsecurity.conf && \
    echo "" >> modsecurity.conf && \
    echo "# Include CatWAF override configuration (paranoia level, etc.)" >> modsecurity.conf && \
    echo "Include /etc/nginx/modsecurity/crs-setup-override.conf" >> modsecurity.conf

# Create empty override config file (will be managed by dashboard)
RUN echo "# CatWAF Override Config - Managed by Dashboard" > /etc/nginx/modsecurity/crs-setup-override.conf

# Create custom rules directory
RUN mkdir -p /etc/nginx/modsecurity/custom-rules

# Create sites-enabled directory for dynamic site configs
RUN mkdir -p /etc/nginx/sites-enabled

# Copy NGINX configuration
COPY nginx.conf /etc/nginx/nginx.conf
COPY conf.d /etc/nginx/conf.d
COPY banlist.conf /etc/nginx/banlist.conf

# Copy config watcher script
COPY config-watcher.sh /usr/local/bin/config-watcher.sh
RUN chmod +x /usr/local/bin/config-watcher.sh

# Copy error pages
COPY error-pages /usr/share/nginx/error-pages

# Copy emergency recovery page
COPY emergency.html /emergency.html

# Create log directories
RUN mkdir -p /var/log/nginx /var/log/modsec && \
    chown -R nginx:nginx /var/log/nginx /var/log/modsec

# Clean up
WORKDIR /
RUN rm -rf /opt/*

# Copy and setup entrypoint
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 443

# Use proper entrypoint that installs CRS before starting nginx
CMD ["/usr/local/bin/entrypoint.sh"]
