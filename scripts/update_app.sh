#!/bin/bash

if [[ "$PWD" == *"scripts"* ]]; then
   cd ..
fi

git submodule foreach --recursive '
    before=$(git describe --tags --abbrev=0 HEAD 2>/dev/null || echo "no tag") 
    git pull
    after=$(git describe --tags --abbrev=0 HEAD 2>/dev/null || echo "no tag")
    if [ "$before" != "$after" ]; then
        echo "from $before to $after"
    fi
    echo ""
'

echo "Back to 'laraplate'"
before=$(git describe --tags --abbrev=0 HEAD 2>/dev/null || echo "no tag")
git pull
after=$(git describe --tags --abbrev=0 HEAD 2>/dev/null || echo "no tag")
[ "$before" != "$after" ] && echo "from $before to $after" || echo""

echo "Checking for new submodules"
git submodule status | awk '/^-/ {print $2}' | while read -r path; do
    echo "Initialising $path"
    git submodule update --init -- "$path"

    branch=$(git config -f .gitmodules --get "submodule.$path.branch")
    if [ -z "$branch" ]; then
        branch=$(git -C "$path" symbolic-ref --short refs/remotes/origin/HEAD 2>/dev/null | sed 's#^origin/##')
    fi
    [ -n "$branch" ] && git -C "$path" switch "$branch"

    pushurl=$(git config -f .gitmodules --get "submodule.$path.pushurl")
    [ -n "$pushurl" ] && git -C "$path" config remote.origin.pushurl "$pushurl"
done
echo ""

read -p "Do you want to run the full Laraplate update? (y/N): " choice

if [[ ! "$choice" =~ ^[Yy]$ ]]; then
    composer install --ignore-platform-req=ext-oci8
    npm install
else
    composer update --ignore-platform-req=ext-oci8
    npm update
fi


php artisan migrate
php artisan db:seed

php artisan optimize:clear

read -p "Do you want to run the optimize command? (y/N): " choice

if [[ ! "$choice" =~ ^[Yy]$ ]]; then
    php artisan optimize
fi

if command -v supervisorctl &> /dev/null; then
    supervisorctl reload
    php artisan horizon:restart
fi
