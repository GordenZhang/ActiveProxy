FROM gordenzhang/tiny-swoole:latest

RUN apk add openssh

COPY src/ /home/work/app/src
COPY vendor/ /home/work/app/vendor
COPY sshp.sh /home/work/app/sshp.sh