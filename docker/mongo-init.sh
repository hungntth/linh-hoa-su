#!/bin/bash
set -eu
if [ -d /seed/mongodump ]; then
  mongorestore --host 127.0.0.1 --nsInclude 'lhs_s1001.*' --nsInclude 'lhs_dist.*' /seed/mongodump
else
  echo 'No MongoDB seed found; starting with an empty database.'
fi
