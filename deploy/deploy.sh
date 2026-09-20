#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
readonly CONFIG_FILE="${DEPLOY_CONFIG:-$REPO_ROOT/.deploy.env}"
readonly STATE_DIR="$REPO_ROOT/.deploy"
readonly STATE_FILE="$STATE_DIR/manifest.tsv"

DRY_RUN=false
DELETE_REMOTE=false
FULL_DEPLOY=false

usage() {
  cat <<'EOF'
Usage: bash deploy/deploy.sh [options]

Upload only tracked files whose contents changed since the last successful run.

Options:
  --dry-run   Show what would change without connecting to the server.
  --delete    Also delete remote files that were removed from Git.
  --full      Upload every deployable tracked file.
  -h, --help  Show this help.
EOF
}

while (($#)); do
  case "$1" in
    --dry-run) DRY_RUN=true ;;
    --delete) DELETE_REMOTE=true ;;
    --full) FULL_DEPLOY=true ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unknown option: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

if [[ -f "$CONFIG_FILE" ]]; then
  # This is intentionally a local, Git-ignored shell environment file.
  # shellcheck source=/dev/null
  source "$CONFIG_FILE"
elif [[ -z "${FTP_HOST:-}" || -z "${FTP_USER:-}" || -z "${FTP_REMOTE_ROOT:-}" ]]; then
  printf 'Missing %s\nCopy deploy/deploy.env.example to .deploy.env and edit it.\n' "$CONFIG_FILE" >&2
  exit 1
fi

: "${FTP_HOST:?Set FTP_HOST in .deploy.env}"
: "${FTP_USER:?Set FTP_USER in .deploy.env}"
: "${FTP_REMOTE_ROOT:?Set FTP_REMOTE_ROOT in .deploy.env}"

FTP_SCHEME="${FTP_SCHEME:-ftp}"
FTP_PORT="${FTP_PORT:-21}"
FTP_TLS="${FTP_TLS:-true}"
FTP_INSECURE="${FTP_INSECURE:-false}"

case "$FTP_SCHEME" in
  ftp|ftps) ;;
  *) printf 'FTP_SCHEME must be ftp or ftps.\n' >&2; exit 1 ;;
esac

for command_name in git sha256sum curl od; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    printf 'Required command not found: %s\n' "$command_name" >&2
    exit 1
  fi
done

cd "$REPO_ROOT"
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
  printf 'This script must be inside a Git working tree.\n' >&2
  exit 1
}

# Keep this aligned with the application paths in .cpanel.yml. Production data,
# secrets, documentation, tests, and desktop build artifacts are not deployable.
is_deployable() {
  case "$1" in
    "General Setting"/*|Invited/*|api/*|assets/*|bots/*|campaigns/*|"mini apps"/*|modules/*|standalone-draw/*|style/*|system/*)
      ;;
    .htaccess|app.js|dev-settings.php|index.php|legacy-egm-path.php|login.php|logout.php|panel.php|users.php)
      ;;
    *) return 1 ;;
  esac

  case "$1" in
    api/config.php|api/config.local.php|*/config.local.php|api/data/*|data/*|uploads/*|campaigns/redirects.json|standalone-draw/data/*|"mini apps"/*/*\ Event/*|"mini apps"/*/tasks/*/*.csv)
      return 1
      ;;
  esac
}

urlencode_path() {
  local input="$1" hex byte output=""
  for hex in $(printf '%s' "$input" | od -An -v -tx1); do
    case "$hex" in
      2f|2d|2e|5f|7e|3[0-9]|4[1-9a-f]|5[0-9a]|6[1-9a-f]|7[0-9a])
        printf -v byte '%b' "\\x$hex"
        output+="$byte"
        ;;
      *) output+="%${hex^^}" ;;
    esac
  done
  printf '%s' "$output"
}

declare -A old_hashes=()
declare -A new_hashes=()
declare -a uploads=()
declare -a deletions=()

if [[ -f "$STATE_FILE" ]]; then
  while IFS=$'\t' read -r hash path; do
    [[ -n "$hash" && -n "$path" ]] && old_hashes["$path"]="$hash"
  done < "$STATE_FILE"
fi

while IFS= read -r -d '' path; do
  is_deployable "$path" || continue
  [[ -f "$path" ]] || continue
  hash="$(sha256sum -- "$path")"
  hash="${hash%% *}"
  new_hashes["$path"]="$hash"
  if $FULL_DEPLOY || [[ "${old_hashes[$path]-}" != "$hash" ]]; then
    uploads+=("$path")
  fi
done < <(git ls-files -z)

for path in "${!old_hashes[@]}"; do
  if [[ -z "${new_hashes[$path]+present}" ]]; then
    deletions+=("$path")
  fi
done

if ((${#uploads[@]} == 0 && ${#deletions[@]} == 0)); then
  printf 'Nothing to deploy.\n'
  exit 0
fi

printf 'Changed files to upload: %d\n' "${#uploads[@]}"
printf 'Tracked remote deletions: %d\n' "${#deletions[@]}"
for path in "${uploads[@]}"; do printf '  UPLOAD  %s\n' "$path"; done
for path in "${deletions[@]}"; do
  if $DELETE_REMOTE; then
    printf '  DELETE  %s\n' "$path"
  else
    printf '  KEEP    %s (use --delete to remove remotely)\n' "$path"
  fi
done

if $DRY_RUN; then
  printf 'Dry run complete; no server changes were made.\n'
  exit 0
fi

if [[ -z "${FTP_PASSWORD:-}" ]]; then
  read -r -s -p "FTP password for $FTP_USER@$FTP_HOST: " FTP_PASSWORD
  printf '\n'
fi
[[ -n "$FTP_PASSWORD" ]] || { printf 'FTP password cannot be empty.\n' >&2; exit 1; }
if [[ "$FTP_USER$FTP_PASSWORD" == *$'\n'* || "$FTP_USER$FTP_PASSWORD" == *$'\r'* ]]; then
  printf 'FTP credentials cannot contain line breaks.\n' >&2
  exit 1
fi

base_url="$FTP_SCHEME://$FTP_HOST:$FTP_PORT"
mkdir -p "$STATE_DIR"
curl_config="$(mktemp "$STATE_DIR/curl-config.XXXXXX")"
trap 'rm -f -- "$curl_config"' EXIT
chmod 600 "$curl_config"
curl_credential="$FTP_USER:$FTP_PASSWORD"
curl_credential="${curl_credential//\\/\\\\}"
curl_credential="${curl_credential//\"/\\\"}"
printf 'user = "%s"\n' "$curl_credential" > "$curl_config"
unset FTP_PASSWORD curl_credential

curl_args=(
  --fail --show-error --silent --globoff
  --connect-timeout 20 --retry 2 --retry-delay 2
  --config "$curl_config"
)
[[ "$FTP_TLS" == true ]] && curl_args+=(--ssl-reqd)
[[ "$FTP_INSECURE" == true ]] && curl_args+=(--insecure)

for path in "${uploads[@]}"; do
  remote_path="${FTP_REMOTE_ROOT%/}/$path"
  encoded_path="$(urlencode_path "/${remote_path#/}")"
  printf 'Uploading %s\n' "$path"
  curl "${curl_args[@]}" --ftp-create-dirs --upload-file "$path" "$base_url$encoded_path"
done

if $DELETE_REMOTE; then
  for path in "${deletions[@]}"; do
    remote_path="${FTP_REMOTE_ROOT%/}/$path"
    printf 'Deleting %s\n' "$path"
    curl "${curl_args[@]}" --quote "DELE $remote_path" "$base_url/"
  done
fi

temporary_state="$STATE_FILE.tmp"
: > "$temporary_state"
for path in "${!new_hashes[@]}"; do
  printf '%s\t%s\n' "${new_hashes[$path]}" "$path" >> "$temporary_state"
done
if ! $DELETE_REMOTE; then
  # Retain pending deletions so a later run with --delete can still remove them.
  for path in "${deletions[@]}"; do
    printf '%s\t%s\n' "${old_hashes[$path]}" "$path" >> "$temporary_state"
  done
fi
sort -t $'\t' -k2,2 "$temporary_state" -o "$temporary_state"
mv -f "$temporary_state" "$STATE_FILE"

printf 'Deployment completed successfully.\n'
