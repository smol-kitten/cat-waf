# syntax=docker/dockerfile:1.4
# CatWAF API Server Dockerfile
# Multi-stage build with BuildKit caching for fast rebuilds

# Build stage
FROM golang:1.22-alpine AS builder

# Install build dependencies
RUN apk add --no-cache git ca-certificates tzdata

WORKDIR /build

# Copy go mod files first for better caching
COPY go.mod go.sum ./

# Download dependencies with cache mount
RUN --mount=type=cache,target=/go/pkg/mod \
    go mod download

# Copy source code
COPY . .

# Build the binary with cache mount for faster rebuilds
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build \
    -ldflags="-w -s -X main.Version=$(git describe --tags --always --dirty 2>/dev/null || echo dev)" \
    -o catwaf ./cmd/catwaf

# Runtime stage
FROM alpine:3.19

# Install runtime dependencies
RUN apk add --no-cache ca-certificates tzdata curl

# Create non-root user
RUN addgroup -g 1000 catwaf && \
    adduser -u 1000 -G catwaf -s /bin/sh -D catwaf

WORKDIR /app

# Copy binary from builder
COPY --from=builder /build/catwaf /app/catwaf

# Copy migrations
COPY --from=builder /build/migrations /app/migrations

# Set ownership
RUN chown -R catwaf:catwaf /app

# Switch to non-root user
USER catwaf

# Expose port
EXPOSE 8080

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/api/health || exit 1

# Run the binary
ENTRYPOINT ["/app/catwaf"]
CMD ["serve"]
