#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

find . -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l >/dev/null
php tests/run.php
php tests/onix_large_feed_smoke.php
php tests/onix_validation_commercial_smoke.php
php tests/onix_enrichment_source_smoke.php
php tests/repository_smoke.php
php tests/mapper_smoke.php
php tests/settings_migration_smoke.php
php tests/sftp_endpoint_smoke.php
python3 tests/package_check_v0124.py
php tests/omp35_smoke.php

api_capture=$(mktemp)
feed_capture=$(mktemp)
trap 'rm -f "$api_capture" "$feed_capture"' EXIT HUP INT TERM
api_out=$(GOOGLEBOOKS_OPERATION_SMOKE=save-api GOOGLEBOOKS_SETTINGS_CAPTURE="$api_capture" php tests/omp35_smoke.php)
printf '%s\n' "$api_out" | grep -q '/press/pt_BR/googlebooks?message=apiSettingsSaved'
grep -q '"name":"googlePartnerId","value":"partner-42"' "$api_capture"
grep -q '"name":"googleApiKeyEncrypted","value":"gbapi:v1:' "$api_capture"
! grep -q 'api-key-value' "$api_capture"
grep -q '"name":"googleApiKey","value":""' "$api_capture"
grep -q '"name":"autoDiscovery","value":true' "$api_capture"
grep -q '"name":"showPublicLink","value":true' "$api_capture"
feed_out=$(GOOGLEBOOKS_OPERATION_SMOKE=save-feed GOOGLEBOOKS_SETTINGS_CAPTURE="$feed_capture" php tests/omp35_smoke.php)
printf '%s\n' "$feed_out" | grep -q '/press/pt_BR/googlebooks?message=feedSettingsSaved'
grep -q '"name":"collectionCode","value":"AB12345"' "$feed_capture"
grep -q '"name":"feedUsername","value":"googlefeed"' "$feed_capture"
grep -q '"name":"feedEnabled","value":true' "$feed_capture"
grep -q '"name":"feedPasswordHash"' "$feed_capture"
normalized_capture=$(mktemp)
normalized_out=$(GOOGLEBOOKS_OPERATION_SMOKE=save-normalized-code GOOGLEBOOKS_SETTINGS_CAPTURE="$normalized_capture" php tests/omp35_smoke.php)
printf '%s\n' "$normalized_out" | grep -q '/press/pt_BR/googlebooks?message=feedSettingsSaved'
grep -q '"name":"collectionCode","value":"AB12345"' "$normalized_capture"
rm -f "$normalized_capture"
invalid_out=$(GOOGLEBOOKS_OPERATION_SMOKE=invalid-collection php tests/omp35_smoke.php)
printf '%s\n' "$invalid_out" | grep -q 'message=invalidCollectionCode'
printf '%s\n' "$invalid_out" | grep -q 'collectionCodeAttempt=AB1234'
csrf_out=$(GOOGLEBOOKS_OPERATION_SMOKE=csrf php tests/omp35_smoke.php)
printf '%s\n' "$csrf_out" | grep -q '/press/pt_BR/googlebooks?message=csrfExpired'
download_out=$(GOOGLEBOOKS_OPERATION_SMOKE=download-no-selection php tests/omp35_smoke.php)
printf '%s\n' "$download_out" | grep -q '/press/pt_BR/googlebooks?message=validationNotSelected'

delivery_capture=$(mktemp)
delivery_out=$(GOOGLEBOOKS_OPERATION_SMOKE=save-delivery GOOGLEBOOKS_SETTINGS_CAPTURE="$delivery_capture" php tests/omp35_smoke.php)
printf '%s\n' "$delivery_out" | grep -q '/press/pt_BR/googlebooks?message=deliverySettingsSaved'
grep -q '"name":"deliveryMode","value":"google_sftp"' "$delivery_capture"
grep -q '"name":"googleSftpHost","value":"sftp.example.test"' "$delivery_capture"
grep -q '"name":"feedEnabled","value":true' "$delivery_capture"
grep -q '"name":"deliverOnixRights","value":true' "$delivery_capture"
rm -f "$delivery_capture"

auth_capture=$(mktemp)
auth_out=$(GOOGLEBOOKS_OPERATION_SMOKE=save-transport-auth GOOGLEBOOKS_SETTINGS_CAPTURE="$auth_capture" php tests/omp35_smoke.php)
printf '%s\n' "$auth_out" | grep -q '/press/pt_BR/googlebooks?message=transportAuthSaved'
grep -q '"name":"googleSftpUsername","value":"google-dropbox-user"' "$auth_capture"
grep -q '"name":"googleSftpPasswordEncrypted","value":"gbsec:v1:' "$auth_capture"
! grep -q 'TemporarySftpSecret123' "$auth_capture"
rm -f "$auth_capture"

behavior_capture=$(mktemp)
behavior_out=$(GOOGLEBOOKS_OPERATION_SMOKE=save-behavior GOOGLEBOOKS_SETTINGS_CAPTURE="$behavior_capture" php tests/omp35_smoke.php)
printf '%s\n' "$behavior_out" | grep -q '/press/pt_BR/googlebooks?message=behaviorSettingsSaved'
grep -q '"name":"autoSync","value":true' "$behavior_capture"
grep -q '"name":"autoVerifyGoogle","value":true' "$behavior_capture"
rm -f "$behavior_capture"

queue_capture=$(mktemp)
discover_out=$(GOOGLEBOOKS_OPERATION_SMOKE=discover GOOGLEBOOKS_QUEUE_CONNECTION=sync GOOGLEBOOKS_QUEUE_CAPTURE="$queue_capture" php tests/omp35_smoke.php)
printf '%s\n' "$discover_out" | grep -q '/press/pt_BR/googlebooks?message=discoveryQueued'
grep -q '^connection=database$' "$queue_capture"
grep -q '^delayed=1$' "$queue_capture"
rm -f "$queue_capture"

sync_capture=$(mktemp)
sync_out=$(GOOGLEBOOKS_OPERATION_SMOKE=sync GOOGLEBOOKS_QUEUE_CONNECTION=sync GOOGLEBOOKS_QUEUE_CAPTURE="$sync_capture" php tests/omp35_smoke.php)
printf '%s\n' "$sync_out" | grep -q '/press/pt_BR/googlebooks?message=feedSyncQueued'
grep -q '^connection=database$' "$sync_capture"
grep -q '^delayed=1$' "$sync_capture"
rm -f "$sync_capture"

force_capture=$(mktemp)
force_out=$(GOOGLEBOOKS_OPERATION_SMOKE=force GOOGLEBOOKS_QUEUE_CONNECTION=sync GOOGLEBOOKS_QUEUE_CAPTURE="$force_capture" php tests/omp35_smoke.php)
printf '%s\n' "$force_out" | grep -q '/press/pt_BR/googlebooks?message=forceQueued'
grep -q '^connection=database$' "$force_capture"
grep -q '^delayed=1$' "$force_capture"
rm -f "$force_capture"
echo "OK 40 dashboard operation/persistence smoke assertions"
