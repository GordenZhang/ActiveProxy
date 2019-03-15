#!/bin/sh
CurrentDir="$( cd "$( dirname "$0"  )" && pwd  )"

cd $CurrentDir
cd ..
composer install

mkdir $CurrentDir/app
cp -r src/ $CurrentDir/app/src
cp -r vendor/ $CurrentDir/app/vendor
cp *.sh $CurrentDir/app/

cd build
docker rmi activeproxy:latest
docker build -t activeproxy:latest .

rm -rf $CurrentDir/app