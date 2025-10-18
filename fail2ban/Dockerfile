FROM alpine:3.19

# Install Fail2Ban and dependencies
RUN apk add --no-cache \
    fail2ban \
    python3 \
    py3-pip \
    iptables \
    whois \
    bash \
    logrotate \
    dcron \
    tzdata && \
    rm -rf /etc/fail2ban/jail.d/*

# Create necessary directories
RUN mkdir -p /var/log/fail2ban \
    /var/run/fail2ban \
    /etc/fail2ban/jail.d \
    /etc/fail2ban/filter.d \
    /etc/fail2ban/action.d

# Copy configuration files
COPY fail2ban.conf /etc/fail2ban/fail2ban.conf
COPY jail.local /etc/fail2ban/jail.local
COPY jail.d /etc/fail2ban/jail.d
COPY filter.d /etc/fail2ban/filter.d
COPY action.d /etc/fail2ban/action.d
COPY logrotate.conf /etc/logrotate.d/fail2ban

# Create state directory for persistent data
RUN mkdir -p /etc/fail2ban/state

# Copy and set up entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set permissions
RUN chmod 755 /etc/fail2ban/action.d/*.sh 2>/dev/null || true

VOLUME ["/var/log/nginx", "/var/log/modsec", "/etc/fail2ban/state"]

CMD ["/entrypoint.sh"]
