#!/usr/bin/env bash
set -euo pipefail
[[ "${APP_ENV:-}" == pilot && "${PILOT_TEST_MODE:-}" == 1 && "${DB_DATABASE:-}" == *_pilot_test ]] || { echo 'Disposable pilot environment required' >&2; exit 1; }
pilot_user=$(id -un)
pilot_group=$(id -gn)
cat > /tmp/akaiv-pilot-fpm.conf <<FPM
[global]
daemonize = no
error_log = /tmp/akaiv-pilot-fpm-error.log
[www]
user = $pilot_user
group = $pilot_group
listen = 127.0.0.1:9070
pm = dynamic
pm.max_children = 4
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
clear_env = no
catch_workers_output = yes
chdir = $PWD
php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 256M
php_admin_value[post_max_size] = 256M
php_admin_flag[log_errors] = on
FPM
python3 - <<'PY'
import os, pwd, grp
config = '''user __PILOT_USER__ __PILOT_GROUP__;
pid /tmp/akaiv-pilot-nginx.pid;
events { worker_connections 128; }
http {
    include /etc/nginx/mime.types;
    access_log /tmp/akaiv-pilot-nginx-access.log;
    error_log /tmp/akaiv-pilot-nginx-error.log;
    server {
        listen 127.0.0.1:8000;
        root __PUBLIC_ROOT__;
        index index.php;
        client_max_body_size 256m;
        location / { try_files $uri $uri/ /index.php?$query_string; }
        location ~ \\.php$ {
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param HTTP_PROXY "";
            fastcgi_pass 127.0.0.1:9070;
            fastcgi_read_timeout 120s;
        }
        location ~ /\\. { deny all; }
    }
}
'''
open('/tmp/akaiv-pilot-nginx.conf', 'w').write(config.replace('__PUBLIC_ROOT__', os.getcwd()+'/public').replace('__PILOT_USER__', pwd.getpwuid(os.getuid()).pw_name).replace('__PILOT_GROUP__', grp.getgrgid(os.getgid()).gr_name))
PY
pilot_environment=()
for name in APP_ENV APP_DEBUG APP_URL APP_KEY DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD SESSION_DRIVER SESSION_SECURE_COOKIE CACHE_STORE QUEUE_CONNECTION MAIL_MAILER CLAMAV_HOST AWS_ENDPOINT AWS_DEFAULT_REGION AWS_BUCKET AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_USE_PATH_STYLE_ENDPOINT REDIS_HOST PILOT_TEST_MODE; do
  pilot_environment+=("$name=${!name}")
done
sudo env "${pilot_environment[@]}" /usr/sbin/php-fpm8.3 -d opcache.jit=off -d opcache.jit_buffer_size=0 -y /tmp/akaiv-pilot-fpm.conf > /tmp/akaiv-pilot-fpm.log 2>&1 &
sudo nginx -c /tmp/akaiv-pilot-nginx.conf -g 'daemon off;' > /tmp/akaiv-pilot-server.log 2>&1 &
