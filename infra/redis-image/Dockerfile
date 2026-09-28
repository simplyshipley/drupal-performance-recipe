# Cache-only Redis for Drupal on Azure Container Apps (docs/redis-image.md).
# Pinned official base; runs as the image's non-root "redis" user; the config
# is rendered at start from environment variables and secret files.
FROM redis:7.4.11-alpine

COPY --chown=root:root --chmod=0755 entrypoint.sh /usr/local/bin/perf-redis-entrypoint
COPY --chown=root:root --chmod=0755 healthcheck.sh /usr/local/bin/perf-redis-healthcheck
COPY --chown=root:root --chmod=0644 redis.conf /etc/perf-redis/redis.base.conf
RUN chmod 0755 /etc/perf-redis && mkdir -p /run/redis && chown redis:redis /run/redis && chmod 0700 /run/redis

USER redis
ENV REDIS_PORT=6379 \
    REDIS_MAXMEMORY=256mb \
    REDIS_MAXMEMORY_POLICY=allkeys-lru \
    REDIS_TLS=0
EXPOSE 6379 6380
HEALTHCHECK --interval=15s --timeout=3s --retries=3 CMD ["/usr/local/bin/perf-redis-healthcheck"]
ENTRYPOINT ["/usr/local/bin/perf-redis-entrypoint"]
