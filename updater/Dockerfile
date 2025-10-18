FROM alpine:latest

# Install required tools
RUN apk add --no-cache \
    docker-cli \
    docker-cli-compose \
    curl \
    jq \
    bash \
    tzdata

# Create updater script
COPY updater.sh /usr/local/bin/updater.sh
RUN chmod +x /usr/local/bin/updater.sh

# Set timezone
ENV TZ=Europe/Berlin

# Health check
HEALTHCHECK --interval=60s --timeout=10s --retries=3 \
    CMD pgrep -f updater.sh || exit 1

# Run updater in loop
CMD ["/usr/local/bin/updater.sh"]
