import os
from pathlib import Path
import sys

root = Path(sys.argv[1]).resolve()
state = root / ".preview"
app = state / "app"
if not (state / "ready").exists():
    raise SystemExit("Preview setup has not completed")
for name in ["client_temp", "fastcgi_temp"]:
    (state / name).mkdir(exist_ok=True)
https = "on" if os.environ["APP_URL"].startswith("https://") else "off"
(state / "fpm.conf").write_text(f"""[global]
daemonize = no
error_log = {state}/fpm-error.log
[www]
listen = 127.0.0.1:9070
pm = dynamic
pm.max_children = 3
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 2
clear_env = no
catch_workers_output = yes
chdir = {app}
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 32M
php_admin_value[post_max_size] = 34M
""")
(state / "nginx.conf").write_text(f"""pid {state}/nginx.pid;
error_log {state}/nginx-error.log;
events {{ worker_connections 128; }}
http {{
  include /etc/nginx/mime.types;
  access_log {state}/nginx-access.log;
  client_body_temp_path {state}/client_temp;
  fastcgi_temp_path {state}/fastcgi_temp;
  server {{
    listen 127.0.0.1:8000;
    root "{app}/public";
    index index.php;
    client_max_body_size 34m;
    location / {{ try_files $uri $uri/ /index.php?$query_string; }}
    location = /index.php {{
      include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME "{app}/public/index.php";
      fastcgi_param HTTPS {https};
      fastcgi_param HTTP_PROXY "";
      fastcgi_pass 127.0.0.1:9070;
      fastcgi_read_timeout 120s;
    }}
    location ~ \\.php$ {{ return 404; }}
    location ~ /\\. {{ deny all; }}
  }}
}}
""")
(state / "supervisor.conf").write_text(f"""[unix_http_server]
file={state}/supervisor.sock
chmod=0700
[supervisord]
logfile={state}/supervisor.log
pidfile={state}/supervisor.pid
childlogdir={state}
[rpcinterface:supervisor]
supervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface
[supervisorctl]
serverurl=unix://{state}/supervisor.sock
[program:fpm]
command=/usr/sbin/php-fpm8.3 -d opcache.jit=off -d opcache.jit_buffer_size=0 -y {state}/fpm.conf
directory={app}
autorestart=true
stdout_logfile={state}/fpm.log
redirect_stderr=true
[program:web]
command=/usr/sbin/nginx -c {state}/nginx.conf -g "daemon off;"
autorestart=true
stdout_logfile={state}/web.log
redirect_stderr=true
[program:queue]
command=/usr/bin/php8.3 artisan queue:work --sleep=2 --tries=3 --timeout=120
directory={app}
autorestart=true
stopwaitsecs=130
stdout_logfile={state}/queue.log
redirect_stderr=true
""")
