#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")"
exec php \
  -d upload_max_filesize=50M \
  -d post_max_size=256M \
  -d max_file_uploads=1000 \
  -d max_execution_time=0 \
  -d memory_limit=2G \
  -S 127.0.0.1:8000
