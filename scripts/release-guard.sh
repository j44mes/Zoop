#!/usr/bin/env bash
# Exit 0 only when this commit may become a draft Zoop release.
set -euo pipefail
exec python3 "$(cd "$(dirname "$0")" && pwd)/release_guard.py"
