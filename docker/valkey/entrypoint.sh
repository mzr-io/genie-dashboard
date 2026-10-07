#!/bin/sh
# Starts one Valkey instance: TLS only, no persistence, per-role ACL users.
# Usage: entrypoint.sh <queue|cache>
# The ACL template (docker/valkey/<store>.acl) holds __PASSWORD_<ROLE>__
# placeholders that are filled from VALKEY_PASSWORD_<ROLE> environment variables.
set -eu

store="${1:?store name (queue or cache) required}"
policy="${VALKEY_MAXMEMORY_POLICY:?VALKEY_MAXMEMORY_POLICY required}"
template="/etc/valkey/${store}.acl"
acl="/tmp/users.acl"

# The ACL file format has no comments: drop them from the template.
grep -v "^[[:space:]]*#" "$template" > "$acl"
for placeholder in $(grep -o '__PASSWORD_[A-Z_]*__' "$template" | sort -u); do
  name="VALKEY_${placeholder#__}"
  name="${name%__}"
  eval "value=\${$name:-}"
  [ -n "$value" ] || { echo "valkey: $name is not set" >&2; exit 1; }
  case "$value" in
    *[!A-Za-z0-9._~-]*) echo "valkey: $name may only contain letters, digits and . _ ~ -" >&2; exit 1 ;;
  esac
  sed -i "s|${placeholder}|${value}|g" "$acl"
done
chmod 600 "$acl"

set -- --port 0 --tls-port 6379 \
  --tls-cert-file /tls/server.crt --tls-key-file /tls/server.key --tls-ca-cert-file /tls-ca/ca.crt \
  --tls-auth-clients no --tls-protocols "TLSv1.2 TLSv1.3" \
  --save "" --appendonly no \
  --aclfile "$acl" \
  --maxmemory-policy "$policy"
[ -z "${VALKEY_MAXMEMORY:-}" ] || set -- "$@" --maxmemory "$VALKEY_MAXMEMORY"

exec valkey-server "$@"
