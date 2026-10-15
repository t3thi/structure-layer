#!/usr/bin/env bash
#
# Build a browsable TYPO3 instance with EXT:structure_layer for manual testing.
# Run inside DDEV: `ddev demo`. config/, var/, .Build/ and the database are git-ignored
# and fully regenerated - re-run any time for a clean instance.
#
# The site gets the structure-layer language layout (0 = Structure, de, en -> de, en-GB -> en, de)
# and the start state of the Phase 3 gate walkthrough (structure-layer:demo).

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

echo "› structure-layer site configuration"
cat > config/sites/main/config.yaml <<YAML
rootPageId: 1
base: '${BASE_URL}'
dependencies:
  - typo3/fluid-styled-content
  - typo3/fluid-styled-content-css
languages:
  - languageId: 0
    title: Structure
    enabled: false
    base: /_structure/
    locale: en_US.UTF-8
    flag: global
  - languageId: 1
    title: Deutsch
    navigationTitle: Deutsch
    base: /
    locale: de_DE.UTF-8
    flag: de
    fallbacks: ''
  - languageId: 2
    title: English
    navigationTitle: English
    base: /en/
    locale: en_US.UTF-8
    flag: us
    fallbacks: '1'
  - languageId: 3
    title: 'English (UK)'
    navigationTitle: 'English (UK)'
    base: /en-gb/
    locale: en_GB.UTF-8
    flag: gb
    fallbacks: '2,1'
YAML
cat >> config/sites/main/setup.typoscript <<'TYPOSCRIPT'

# structure-layer demo: page and language menu above the content
page.10.15 = COA
page.10.15 {
  10 = HMENU
  10.wrap = <nav>|</nav>
  10.1 = TMENU
  10.1.NO.allWrap = | &middot;&nbsp;
  20 = HMENU
  20.special = language
  20.special.value = 1,2,3
  20.wrap = <p>|</p>
  20.1 = TMENU
  20.1.NO.allWrap = | &nbsp;
  20.1.ACT < .20.1.NO
  20.1.ACT = 1
  20.1.ACT.doNotLinkIt = 1
  20.1.USERDEF1 < .20.1.NO
  20.1.USERDEF1 = 1
  20.1.USERDEF1.doNotLinkIt = 1
  20.1.USERDEF1.allWrap = <s>|</s> &nbsp;
}
TYPOSCRIPT
"${TYPO3_BIN}" cache:flush

echo "› demo content"
"${TYPO3_BIN}" structure-layer:demo
"${TYPO3_BIN}" cache:flush

echo "› done: ${BASE_URL}typo3/ (admin / Password.1)"
