#!/usr/bin/env bash
#
# Build a browsable TYPO3 instance with EXT:structure_layer for manual testing.
# Run inside DDEV: `ddev demo`. config/, var/, .Build/ and the database are git-ignored
# and fully regenerated - re-run any time for a clean instance.
#
# Structure-layer demo content (shadow pages/content) is seeded from Phase 3 on.

set -euo pipefail

BASE_URL="${BASE_URL:-https://${DDEV_SITENAME:-structure-layer}.ddev.site/}"
TYPO3_BIN="${TYPO3_BIN:-.Build/bin/typo3}"

echo "› resetting the database"
mysql -h db -u root -proot \
    -e "DROP DATABASE IF EXISTS db; CREATE DATABASE db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null

echo "› TYPO3 setup"
"${TYPO3_BIN}" setup \
    --driver=mysqli --host=db --port=3306 --dbname=db --username=db --password=db \
    --admin-username=admin --admin-user-password='Password.1' --admin-email=admin@example.com \
    --project-name='Structure Layer PoC' --create-site="${BASE_URL}" --server-type=other --no-interaction --force

"${TYPO3_BIN}" configuration:set SYS/trustedHostsPattern '.*'
"${TYPO3_BIN}" extension:setup
"${TYPO3_BIN}" cache:flush

echo "› done: ${BASE_URL}typo3/ (admin / Password.1)"
