FROM mcr.microsoft.com/playwright:v1.41.0-jammy

WORKDIR /app

# Install Node.js dependencies
COPY package.json ./
RUN npm install

# Copy application code
COPY server.js ./

# Create directories
RUN mkdir -p /app/screenshots /app/cache

# Set permissions
RUN chmod -R 755 /app

# Expose API port
EXPOSE 3000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:3000/health || exit 1

CMD ["node", "server.js"]
