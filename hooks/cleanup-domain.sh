#!/usr/bin/env bash
# Hook helper: clean git-deploy artefacts when a web domain is deleted.
# Wire from Pluginable / custom wrapper around v-delete-web-domain, e.g.:
#   /usr/local/hestia/plugins/git-deploy/hooks/cleanup-domain.sh USER DOMAIN
#
# Safe to call if git-deploy was never configured.

set -euo pipefail

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=/dev/null
source "${PLUGIN_ROOT}/func/main.sh"

user="${1:-}"
domain="${2:-}"

[[ -n "$user" && -n "$domain" ]] || exit 0
[[ "$user" =~ ^[a-zA-Z0-9._-]+$ ]] || exit 0
[[ "$domain" =~ ^[a-zA-Z0-9._-]+$ ]] || exit 0

deploy_dir="$(git_deploy_dir "$user" "$domain")"
src_dir="$(git_src_dir "$user" "$domain")"

rm -rf "$src_dir" "$deploy_dir"
exit 0
