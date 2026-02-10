# syntax=docker/dockerfile:1.4
# CatWAF UI Dockerfile - Production
# Multi-stage build with BuildKit caching

# Build stage
FROM node:20-alpine AS builder

WORKDIR /app

# Copy package files
COPY package.json pnpm-lock.yaml ./

# Install pnpm and dependencies with cache mount
RUN corepack enable && corepack prepare pnpm@latest --activate
RUN --mount=type=cache,target=/root/.local/share/pnpm/store \
    pnpm install --frozen-lockfile

# Copy source code
COPY . .

# Build the application
RUN pnpm build

# Runtime stage
FROM node:20-alpine

WORKDIR /app

# Install pnpm
RUN corepack enable && corepack prepare pnpm@latest --activate

# Copy built application
COPY --from=builder /app/build ./build
COPY --from=builder /app/package.json ./
COPY --from=builder /app/pnpm-lock.yaml ./

# Install production dependencies only with cache
RUN --mount=type=cache,target=/root/.local/share/pnpm/store \
    pnpm install --prod --frozen-lockfile

# Create non-root user
RUN addgroup -g 1000 catwaf && \
    adduser -u 1000 -G catwaf -s /bin/sh -D catwaf && \
    chown -R catwaf:catwaf /app

USER catwaf

# Expose port
EXPOSE 3000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD wget --no-verbose --tries=1 --spider http://localhost:3000/health || exit 1

# Run the application
ENV NODE_ENV=production
ENV PORT=3000
CMD ["node", "build"]
