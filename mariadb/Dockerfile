# MariaDB Migration Runner with Embedded SQL Files
# Combines init and migration runner into one efficient image

FROM mariadb:latest

# Install bash for better script handling
RUN apt-get update && apt-get install -y bash && rm -rf /var/lib/apt/lists/*

# Copy all SQL migration files into the image
COPY mariadb/init/*.sql /migrations/

# Copy migration script
COPY mariadb/run-migrations.sh /usr/local/bin/run-migrations.sh
RUN chmod +x /usr/local/bin/run-migrations.sh

# Set working directory
WORKDIR /migrations

# Run migrations on container start
CMD ["/usr/local/bin/run-migrations.sh"]
