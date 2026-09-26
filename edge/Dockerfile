# Local stand-in for Azure Front Door (cache) + Azure WAF. LAB ONLY.
# Extends the official OWASP ModSecurity CRS image, pinned to CRS 3.3.10
# (Azure DRS 2.2 is based on CRS 3.3.4). See docs/research-afd-waf.md.
FROM owasp/modsecurity-crs:3.3.10-nginx-alpine-202609241109-lts

# Front Door stand-in settings. All IDs are SYNTHETIC.
ENV EDGE_ORIGIN=http://web:80 \
    EDGE_FDID=00000000-0000-0000-0000-0000000fd1d0 \
    EDGE_QS_MODE=use \
    EDGE_QS_IGNORE_PARAMS=utm_source,utm_medium,utm_campaign,utm_term,utm_content,fbclid,gclid \
    EDGE_QS_INCLUDE_PARAMS=page,v \
    EDGE_STATIC_TTL=7d \
    EDGE_DEFAULT_TTL=1d \
    EDGE_CACHE_MAX_SIZE=1g \
    EDGE_WAF_MODE=Prevention \
    EDGE_RL_LOGIN=10 \
    EDGE_RL_SEARCH=60 \
    EDGE_SEARCH_PATHS=^/search \
    EDGE_RL_GENERAL=300 \
    BLOCKING_PARANOIA=1 \
    ANOMALY_INBOUND=5 \
    ANOMALY_OUTBOUND=4 \
    MODSEC_AUDIT_LOG=/tmp/edge-modsec-audit.log \
    MODSEC_AUDIT_ENGINE=RelevantOnly

COPY --chown=nginx:nginx templates/ /etc/nginx/templates/
COPY --chown=nginx:nginx edge.js /etc/nginx/njs/edge.js
COPY --chown=nginx:nginx --chmod=0755 05-edge-mode.envsh /docker-entrypoint.d/05-edge-mode.envsh
# The image healthcheck probes its own HTTPS listener, which this config
# replaces; probe the control listener instead.
HEALTHCHECK --interval=10s --timeout=3s CMD curl -sf http://127.0.0.1:8099/__edge/health || exit 1
EXPOSE 8080 8081 8090 8091 8092 8093 8099
