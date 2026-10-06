#!/bin/sh
# One-shot: creates a throwaway CA and a server certificate for both Valkey
# instances (development only; real certificates come from your own PKI).
# Server key and certificate go to /tls-server (mounted only into Valkey);
# only the CA certificate goes to /tls-ca (mounted read-only into app roles).
set -eu

if [ -s /tls-ca/ca.crt ] && [ -s /tls-server/server.key ]; then
  echo "valkey-tls: certificates already exist"
  exit 0
fi

work=$(mktemp -d)
cd "$work"

openssl req -x509 -newkey rsa:3072 -nodes -days 825 -subj "/CN=dashflow-dev-valkey-ca" \
  -keyout ca.key -out ca.crt
openssl req -newkey rsa:3072 -nodes -subj "/CN=valkey" -keyout server.key -out server.csr
printf 'subjectAltName=DNS:valkey-queue,DNS:valkey-cache,DNS:localhost\nextendedKeyUsage=serverAuth\n' > san.ext
openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 825 \
  -extfile san.ext -out server.crt

install -m 0644 ca.crt /tls-ca/ca.crt
install -m 0444 -o 999 -g 1000 server.crt /tls-server/server.crt
install -m 0400 -o 999 -g 1000 server.key /tls-server/server.key
rm -rf "$work"
echo "valkey-tls: certificates created"
