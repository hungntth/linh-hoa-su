#!/bin/bash
set -eu
if [ -f /seed/mysql-cdk.sql ]; then
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /seed/mysql-cdk.sql
else
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /schema.sql
fi
