# Mock Azure Front Door purge endpoint. LOCAL TESTING ONLY.
# The self-signed certificate is generated at build time and never leaves the
# image; it is not a secret and is only trusted by the lab (verify = FALSE in
# the lab's http_client_config).
FROM python:3.12-alpine
RUN apk add --no-cache openssl \
 && mkdir -p /opt/mock /data \
 && openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
      -subj "/CN=mock-afd" \
      -addext "subjectAltName=DNS:management.azure.com,DNS:login.microsoftonline.com,DNS:mock-afd" \
      -keyout /opt/mock/key.pem -out /opt/mock/cert.pem
COPY mock_afd.py /opt/mock/mock_afd.py
ENV MOCK_LOG=/data/requests.jsonl MOCK_PORT=8080
EXPOSE 8080
CMD ["python3", "-u", "/opt/mock/mock_afd.py"]
