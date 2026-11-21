#!/bin/bash
# Seed the shared MongoDB (platform infra) for a first install. Restores a private dump only into empty databases,
# then applies mongo-defaults.js, which itself leaves existing servers untouched.
set -eu
games=$(mongosh "$LHS_MONGO" --quiet --eval 'print(db.getSiblingDB("lhs_s1001").getCollectionNames().length)')
if [ -d /seed/mongodump ] && [ "$games" = "0" ]; then
  mongorestore --uri "$LHS_MONGO" --nsInclude 'lhs_s1001.*' --nsInclude 'lhs_dist.*' /seed/mongodump
else
  echo 'No private MongoDB backup to restore (or lhs_s1001 is not empty); skipping restore.'
fi
mongosh "$LHS_MONGO" --quiet /seed-defaults/mongo-defaults.js
