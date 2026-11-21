#!/bin/bash
# Seed the shared MySQL (platform infra) for a first install: the CDK schema goes into `lhs_cdks`
# (renamed from `cdks`, which Hải Tặc H5 also uses). Skips when lhs_cdks already has tables.
set -eu
m() { mysql -h infra-mysql -u"$MYSQL_USER" "$@"; }
tables=$(m -N -e "select count(*) from information_schema.tables where table_schema='lhs_cdks'")
if [ "$tables" != "0" ]; then
  echo "lhs_cdks already has $tables tables; skipping."
  exit 0
fi
src=/schema.sql
[ -f /seed/mysql-cdk.sql ] && src=/seed/mysql-cdk.sql
sed -e 's/`cdks`/`lhs_cdks`/g' "$src" | m
echo "Seeded lhs_cdks from $src"
