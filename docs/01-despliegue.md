# Puesta en producción

Guía para dejar Cerquín corriendo en un servidor propio. Está
escrita para **AlmaLinux 10** —que es lo que hay en el Contabo— pero solo la
parte de paquetes y SELinux es específica; lo demás vale para cualquier Linux.

> **Esta guía todavía no se ha ejecutado en el servidor.** Está escrita a partir
> de la configuración real del proyecto, pero hasta que no se corra en el
> 86.48.20.72 sigue siendo un plan, no un registro. Lo que sí está probado es lo
> que corre en la máquina de desarrollo: el respaldo, su restauración completa y
> `contable:check-produccion`.

La instalación se hace **una vez**. Para subir una versión nueva a un servidor
que ya está montado, no vuelvas aquí: corré [`scripts/deploy.sh`](../scripts/deploy.sh).

---

## 1. Lo que hay que decidir antes

| | |
|---|---|
| **Dominio** | Hace falta uno apuntando al servidor **antes** de pedir el certificado. Sin dominio no hay HTTPS, y sin HTTPS no se entrega. |
| **Quién administra** | El correo del superadministrador, que es quien da de alta a los clientes. |
| **Correo saliente** | Un SMTP real. Sin él nadie puede recuperar una contraseña olvidada. |
| **Dónde van los respaldos** | Un lugar **fuera** de este servidor. Un respaldo en el mismo disco se pierde con el disco. |

## 2. Preparar el servidor

```bash
sudo dnf upgrade -y
sudo dnf install -y epel-release
sudo dnf module reset php -y
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-10.rpm
sudo dnf module enable php:remi-8.4 -y
sudo dnf install -y php php-fpm php-mysqlnd php-mbstring php-xml php-bcmath \
  php-gd php-zip php-intl php-opcache php-curl \
  mysql-server nginx git unzip policycoreutils-python-utils
```

`php-bcmath` no es opcional: **todo el dinero del sistema pasa por bcmath**. Sin
esa extensión no arranca nada.

Node, para compilar la interfaz:

```bash
sudo dnf module enable nodejs:22 -y
sudo dnf install -y nodejs
```

Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

## 3. Firewall

```bash
sudo systemctl enable --now firewalld
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

**MySQL no se abre.** La aplicación y la base están en la misma máquina y hablan
por `127.0.0.1`. Un 3306 abierto a internet es una invitación.

## 4. Usuario del sistema

La aplicación no corre como root:

```bash
sudo useradd -r -m -d /var/www/contable -s /bin/bash contable
sudo usermod -aG contable nginx
```

## 5. Base de datos

```bash
sudo systemctl enable --now mysqld
sudo mysql_secure_installation
```

Y el usuario de la aplicación, que **no es root**:

```sql
CREATE DATABASE contable CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE USER 'contable'@'127.0.0.1' IDENTIFIED BY 'una-clave-larga-y-aleatoria';

-- Solo sobre su base, y sin GRANT OPTION: la aplicación no puede darse
-- permisos a sí misma ni tocar ninguna otra base del servidor.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES,
      CREATE VIEW, SHOW VIEW, TRIGGER, EXECUTE, CREATE ROUTINE, ALTER ROUTINE
  ON contable.* TO 'contable'@'127.0.0.1';

FLUSH PRIVILEGES;
```

`CREATE` y `ALTER` hacen falta porque las migraciones corren con este usuario.
`DROP` también: `migrate:rollback` lo necesita. Lo que **no** se concede es
`GRANT OPTION` ni nada fuera de `contable.*`.

Para los respaldos conviene un usuario aparte que solo pueda leer:

```sql
CREATE USER 'contable_backup'@'127.0.0.1' IDENTIFIED BY 'otra-clave-larga';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON contable.* TO 'contable_backup'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## 6. Traer el proyecto

```bash
sudo -u contable -H bash
cd /var/www/contable
git clone <url-del-repositorio> .

composer install --no-dev --optimize-autoloader
npm ci --omit=dev
npm run build

cp .env.production.example .env
php artisan key:generate
```

Llená el `.env`. Todo lo que dice `CAMBIAR` es obligatorio.

```bash
chmod 640 .env
php artisan migrate --force
php artisan identity:sync-roles
exit
```

### El superadministrador

Es quien entra a `/admin/cuentas` a dar de alta clientes. No hay pantalla para
crearlo —sería una pantalla para fabricarse permisos— así que se crea a mano:

```bash
sudo -u contable php artisan tinker
```

```php
$u = new App\Models\User;
$u->forceFill([
    'name' => 'Tu nombre',
    'email' => 'vos@tudominio.hn',
    'password' => Hash::make('una-clave-larga'),
    'is_active' => true,
    'is_super_admin' => true,
])->save();
```

Cambiá esa contraseña la primera vez que entres.

## 7. Permisos de archivos

```bash
sudo chown -R contable:nginx /var/www/contable
sudo find /var/www/contable -type f -exec chmod 640 {} \;
sudo find /var/www/contable -type d -exec chmod 750 {} \;
sudo chmod -R 770 /var/www/contable/storage /var/www/contable/bootstrap/cache
sudo chmod 750 /var/www/contable/scripts/deploy.sh
```

Solo `storage` y `bootstrap/cache` son escribibles. Todo lo demás, incluido el
código, es de solo lectura para el proceso web.

## 8. SELinux

En AlmaLinux viene en *enforcing*, y es la razón número uno de un 502 que no
aparece en ningún log de la aplicación. **No lo desactives**: se le dice qué
puede hacer.

```bash
# nginx y php-fpm leen el proyecto…
sudo semanage fcontext -a -t httpd_sys_content_t "/var/www/contable(/.*)?"
# …y escriben solo en storage y en la caché de bootstrap.
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/contable/storage(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/contable/bootstrap/cache(/.*)?"
sudo restorecon -Rv /var/www/contable

# Conectarse a MySQL y al SMTP del correo.
sudo setsebool -P httpd_can_network_connect_db 1
sudo setsebool -P httpd_can_sendmail 1
```

Si algo falla sin explicación, la respuesta suele estar acá:

```bash
sudo ausearch -m avc -ts recent
```

## 9. PHP-FPM

En `/etc/php-fpm.d/contable.conf`:

```ini
[contable]
user = contable
group = nginx
listen = /run/php-fpm/contable.sock
listen.owner = nginx
listen.group = nginx
listen.mode = 0660

pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6

; Los PDF de reportes largos y las importaciones tardan más que lo normal.
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 120
php_admin_value[upload_max_filesize] = 16M
php_admin_value[post_max_size] = 16M

; Que un error nunca se imprima en la respuesta, pase lo que pase con APP_DEBUG.
php_admin_flag[display_errors] = off
php_admin_value[error_log] = /var/log/php-fpm/contable-error.log

php_admin_value[opcache.enable] = 1
php_admin_value[opcache.memory_consumption] = 192
php_admin_value[opcache.validate_timestamps] = 0
```

`opcache.validate_timestamps = 0` es lo que hace que PHP no revise el disco en
cada petición —y también la razón por la que **hay que recargar php-fpm en cada
despliegue**, cosa que `deploy.sh` ya hace.

```bash
sudo systemctl enable --now php-fpm
```

## 10. nginx

En `/etc/nginx/conf.d/contable.conf`:

```nginx
server {
    listen 80;
    server_name contable.tudominio.hn;
    # certbot reescribe esto para redirigir a HTTPS.
    root /var/www/contable/public;

    index index.php;
    charset utf-8;
    client_max_body_size 16M;

    # La raíz del proyecto no se sirve; solo public/.
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/contable.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
        fastcgi_hide_header X-Powered-By;
    }

    # Ni .env, ni .git, ni ningún archivo oculto.
    location ~ /\. {
        deny all;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~* \.(css|js|woff2?|png|jpg|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    server_tokens off;
    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    error_page 404 /index.php;
}
```

```bash
sudo nginx -t && sudo systemctl enable --now nginx
```

Las cabeceras de seguridad las pone la aplicación
([`SecurityHeaders`](../app/Http/Middleware/SecurityHeaders.php)), no nginx, para
que no dependan de la configuración del servidor.

## 11. HTTPS

```bash
sudo dnf install -y certbot python3-certbot-nginx
sudo certbot --nginx -d contable.tudominio.hn
sudo systemctl enable --now certbot-renew.timer
```

Certbot edita el `server` de arriba y agrega la redirección. Después de esto,
`APP_URL` en el `.env` tiene que decir `https://`.

## 12. Cron y cola

Una sola línea de cron dispara todo lo programado —el respaldo diario y el
vencimiento de los CAI—:

```bash
sudo crontab -u contable -e
```

```cron
* * * * * cd /var/www/contable && php artisan schedule:run >> /dev/null 2>&1
```

La cola, como servicio, en `/etc/systemd/system/contable-queue.service`:

```ini
[Unit]
Description=Cola de Cerquín
After=network.target mysqld.service

[Service]
User=contable
Group=contable
Restart=always
RestartSec=5
WorkingDirectory=/var/www/contable
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now contable-queue
```

`--max-time=3600` reinicia el proceso cada hora: un worker de PHP que vive días
acumula memoria.

## 13. Respaldos

El respaldo diario ya queda programado por el cron de arriba. Lo que falta es
sacarlo del servidor, que es lo que lo convierte en un respaldo de verdad:

```bash
sudo mkdir -p /var/backups/contable
sudo chown contable:contable /var/backups/contable
sudo chmod 700 /var/backups/contable
```

Y algo que se los lleve —`rclone` a un bucket, `rsync` a otra máquina—:

```cron
30 3 * * * rclone copy /var/backups/contable remoto:contable-respaldos --max-age 25h
```

### Probar que restauran

Un respaldo que nunca se restauró no es un respaldo. Al menos una vez, y después
cada tanto:

```bash
mysql -u root -p -e "CREATE DATABASE contable_prueba"
gunzip -c /var/backups/contable/contable-AAAA-MM-DD-HHMMSS.sql.gz | mysql -u root -p contable_prueba

# Lo que hay que mirar: que la partida doble siga cuadrada después de restaurar.
mysql -u root -p contable_prueba -e \
  "SELECT ROUND(SUM(debit) - SUM(credit), 4) AS descuadre FROM journal_entry_lines;"

mysql -u root -p -e "DROP DATABASE contable_prueba"
```

Tiene que dar `0.0000`. Este ensayo se corrió en desarrollo con la base real de
pruebas: 73 tablas, y el descuadre dio cero.

## 14. Comprobar

```bash
sudo -u contable php artisan contable:check-produccion
```

Va una por una: `APP_DEBUG`, `APP_KEY`, `APP_ENV`, HTTPS, que la aplicación no
entre a la base como root, que las migraciones estén aplicadas, que los permisos
estén sincronizados, que haya superadministrador, que los respaldos estén
corriendo, que el correo no siga en `log` y que el nivel de registro no sea
`debug`. Devuelve código distinto de cero si algo falla, así que `deploy.sh` se
para solo.

## 15. Subir una versión nueva

```bash
sudo -u contable /var/www/contable/scripts/deploy.sh
```

Respalda, entra en mantenimiento, actualiza, migra, **sincroniza los roles**,
recachea, reinicia la cola, recarga php-fpm, comprueba y abre. Si algo revienta
en medio, el `trap` saca el sitio de mantenimiento igual.

Lo de sincronizar los roles no es decorativo: los permisos se siembran al crear
la empresa, y uno nuevo no llega solo a las empresas que ya existen. El síntoma
es un 403 en una pantalla que debería abrirse, sin nada mal en el código. Ya
pasó dos veces en desarrollo.

## 16. Qué vigilar

| Señal | Dónde |
|---|---|
| Errores de la aplicación | `storage/logs/laravel-*.log` |
| 502 sin rastro en el log de la aplicación | `/var/log/php-fpm/contable-error.log` y `sudo ausearch -m avc -ts recent` (SELinux) |
| Respaldos que dejaron de correr | `contable:check-produccion` avisa si el último tiene más de 48 h |
| CAI por vencerse | La pantalla de facturación lo muestra; `fiscal:expire-authorizations` corre a diario |
| Espacio en disco | Los respaldos y los logs son lo que crece |

## 17. Lo que esta guía no cubre

- **Alta disponibilidad.** Un servidor, una base. Para una PYME hondureña es lo
  correcto; el día que no lo sea, esto se queda corto.
- **Monitoreo con alertas.** No hay nada que avise a las tres de la mañana. Lo
  mínimo razonable es un chequeo externo contra `/up`, que Laravel ya sirve.
- **Réplica de la base.** El respaldo diario cubre el desastre; no cubre perder
  las últimas horas de facturación.
- **Despliegue sin corte.** `deploy.sh` baja el sitio un rato. Para una empresa
  que factura en horario de oficina, se despliega fuera de ese horario.
