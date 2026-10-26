#!/bin/bash
set -e
set -x

cd /tmp

export TZ="America/Chicago"

export TZ="UTC"

# DB-IP publishes the new month's file partway through the 1st, so during that
# window fall back to the previous month instead of failing the build.
downloaded=0
for offset in 0 1; do
    YEAR=$(date -d "-${offset} month" +'%Y')
    MONTH=$(date -d "-${offset} month" +'%m')

    if wget --quiet -O dbip-city-lite.mmdb.gz "https://download.db-ip.com/free/dbip-city-lite-${YEAR}-${MONTH}.mmdb.gz"; then
        downloaded=1
        break
    fi
done

if [ "$downloaded" -ne 1 ]; then
    echo "DB-IP: no city-lite database available for the current or previous month." >&2
    exit 1
fi

gunzip dbip-city-lite.mmdb.gz

mv dbip-city-lite.mmdb /var/azuracast/dbip/

chown -R azuracast:azuracast /var/azuracast/dbip
