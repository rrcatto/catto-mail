#!/bin/sh
# Readiness: FPM must answer its FastCGI ping, not merely be running.
SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null | grep -q '^pong'
