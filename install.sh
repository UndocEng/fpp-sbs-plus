#!/bin/bash
# Thin wrapper - delegates to scripts/fpp_install.sh
# FPP's plugin manager calls this file at the repo root.

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
exec bash "$SCRIPT_DIR/scripts/fpp_install.sh" "$@"
