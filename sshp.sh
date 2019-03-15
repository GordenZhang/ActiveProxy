#!/bin/sh

USER_NAME=""
TARGET_HOST=""
TARGET_AREA=""

while getopts ":u:n:h:p:" opt
do
        case $opt in
                u)
                        USER_NAME=$OPTARG
                        ;;
                h)
                        TARGET_HOST=$OPTARG
                        ;;
                n)
                        TARGET_AREA=$OPTARG
                        ;;
                \?)
                        echo "Invalid option: -${OPTARG}"
                        ;;
        esac
done

CurrentDir="$( cd "$( dirname "$0"  )" && pwd  )"

rm ~/.ssh/known_hosts >/dev/null 2>&1

echo "ssh $USER_NAME@$TARGET_HOST for $TARGET_AREA"

php $CurrentDir/src/configure.php $* && ssh $USER_NAME@localhost