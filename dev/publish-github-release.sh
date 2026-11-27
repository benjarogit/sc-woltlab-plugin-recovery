#!/usr/bin/env bash
# Explicit publication of reviewed, already-built artifacts. Never builds,
# commits, tags, pushes, or overwrites an existing release.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APPLY=0
if [[ "${1:-}" == "--publish" ]]; then
  APPLY=1
  shift
fi
if [[ $# -ne 1 || ! "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Usage: $0 [--publish] X.Y.Z (default: local validation/plan only)" >&2
  exit 2
fi
VERSION="$1"
DIST="${RECOVERY_DIST_DIR:-$ROOT/dist}"
REPO="benjarogit/sc-woltlab-plugin-recovery"
python3 - "$ROOT" "$DIST" "$VERSION" <<'PY'
import hashlib, json, sys
from pathlib import Path
root, dist, version = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3]
try:
    manifest = json.loads((dist / 'build-manifest.json').read_text())
    expected = {'plugin-recovery-tool.php', 'recovery-' + version + '.tar.gz'}
    if manifest.get('schema') != 1 or manifest.get('version') != version or set(manifest.get('artifacts', {})) != expected:
        raise ValueError('Build manifest version or artifact set mismatch')
    for name, digest in manifest['artifacts'].items():
        if (dist / name).is_symlink() or hashlib.sha256((dist / name).read_bytes()).hexdigest() != digest:
            raise ValueError('Built artifact changed; rebuild and review')
    source = hashlib.sha256()
    for folder in ['stub', 'recovery-src']:
        for path in sorted((root / folder).rglob('*')):
            if path.is_file():
                source.update(path.relative_to(root).as_posix().encode() + b'\0')
                source.update(path.read_bytes())
    if source.hexdigest() != manifest.get('source_sha256'):
        raise ValueError('Source changed after build; rebuild and review')
    if hashlib.sha256((root / 'dev/build-release.sh').read_bytes()).hexdigest() != manifest.get('builder_sha256'):
        raise ValueError('Builder changed after build; rebuild and review')
except (OSError, ValueError, TypeError, KeyError) as error:
    raise SystemExit('BLOCKED: ' + str(error))
print('Verified prebuilt artifact hashes and source fingerprint')
PY
if [[ "$APPLY" != "1" ]]; then
  echo "PLAN ONLY: would publish v${VERSION} from existing reviewed artifacts to ${REPO}."
  echo "No build, commit, push, tag, GitHub authentication or release request performed."
  echo "Use --publish only after separate publication authorization."
  exit 0
fi
if [[ ! -d "$ROOT/.git" ]]; then
  echo "BLOCKED: publication requires the standalone Recovery repository." >&2
  exit 2
fi
if [[ -n "$(git -C "$ROOT" status --porcelain)" ]]; then
  echo "BLOCKED: review and commit the standalone source changes before publication." >&2
  exit 2
fi
HEAD_COMMIT="$(git -C "$ROOT" rev-parse HEAD)"
TAG_COMMIT="$(git -C "$ROOT" rev-parse "refs/tags/v${VERSION}^{commit}" 2>/dev/null || true)"
if [[ "$TAG_COMMIT" != "$HEAD_COMMIT" ]]; then
  echo "BLOCKED: an independently prepared v${VERSION} tag must identify this exact reviewed commit." >&2
  exit 2
fi
if ! gh auth status >/dev/null 2>&1; then
  echo "BLOCKED: GitHub authentication missing; no login started automatically." >&2
  exit 2
fi
if gh release view "v${VERSION}" --repo "$REPO" >/dev/null 2>&1; then
  echo "BLOCKED: release already exists; this script never overwrites release assets." >&2
  exit 2
fi
gh release create "v${VERSION}" --repo "$REPO" --verify-tag --target "$HEAD_COMMIT" \
  --title "v${VERSION}" --notes "WoltLab Plugin Recovery v${VERSION}" \
  "$DIST/plugin-recovery-tool.php" "$DIST/recovery-${VERSION}.tar.gz"
echo "https://github.com/${REPO}/releases/tag/v${VERSION}"
