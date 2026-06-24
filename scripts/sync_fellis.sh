#!/bin/bash

REPO="/var/www/aiinf.gnf.dk/repos/fellis"
DB="aiinf"

cd $REPO

git fetch origin main
git pull origin main

# hent sidste 20 commits
git log -n 20 --pretty=format:"%H|%an|%s" > /tmp/commits.txt

