#!/bin/bash
set -eu
if [ -d /seed/mongodump ]; then
  mongorestore --host 127.0.0.1 --nsInclude 'lhs_s1001.*' --nsInclude 'lhs_dist.*' /seed/mongodump
else
  echo 'No private MongoDB backup found; initializing a new game.'
fi
mongosh --quiet --host 127.0.0.1 /seed-defaults/mongo-defaults.js
