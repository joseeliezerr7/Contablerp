#!/usr/bin/env bash
#
# Despliegue de una versión nueva sobre una instalación que ya existe.
#
# No instala el servidor: eso es docs/01-despliegue.md y se hace una vez. Esto
# es lo de todos los días.
#
#   ./scripts/deploy.sh
#
# Se detiene al primer error (`set -e`) y también si falla algo en medio de una
# tubería (`pipefail`), porque un despliegue a medias es peor que uno que no
# empezó.

set -Eeuo pipefail

cd "$(dirname "$0")/.."

php_bin="${PHP_BIN:-php}"

info()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$1"; }
fail()  { printf '\n\033[1;31m✗ %s\033[0m\n' "$1" >&2; exit 1; }

[ -f .env ] || fail "No hay .env. Este script actualiza una instalación existente."

# ── 1. Respaldo antes de tocar nada ─────────────────────────────────────────
# Si una migración sale mal, la única vuelta atrás es este archivo. Va primero
# y si falla, el despliegue no sigue.
info "Respaldando la base"
"$php_bin" artisan db:backup

# ── 2. Modo mantenimiento ───────────────────────────────────────────────────
# `--secret` deja una puerta para entrar a comprobar el resultado antes de
# abrirle al resto. `trap` se asegura de que el sitio vuelva aunque el script
# reviente en medio.
info "Entrando en mantenimiento"
"$php_bin" artisan down --render="errors::503" --retry=60 || true
trap '"$php_bin" artisan up || true' EXIT

# ── 3. Código y dependencias ────────────────────────────────────────────────
if [ -d .git ]; then
    info "Trayendo el código"
    git pull --ff-only
fi

info "Instalando dependencias de PHP"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

info "Compilando la interfaz"
npm ci --omit=dev
npm run build

# ── 4. Base de datos ────────────────────────────────────────────────────────
# `--force` porque en producción Laravel pregunta y aquí no hay nadie que
# conteste.
info "Aplicando migraciones"
"$php_bin" artisan migrate --force

# Los roles se siembran al crear la empresa; un permiso nuevo no llega solo a
# las empresas que ya existen. Sin esto, el síntoma es un 403 en una pantalla
# que debería abrirse, sin nada mal en el código. Va en CADA despliegue.
info "Sincronizando roles y permisos"
"$php_bin" artisan identity:sync-roles

# ── 5. Cachés ───────────────────────────────────────────────────────────────
# Primero se limpia y después se cachea: una caché vieja de config puede
# apuntar a valores que ya no existen.
info "Regenerando cachés"
"$php_bin" artisan optimize:clear
"$php_bin" artisan config:cache
"$php_bin" artisan route:cache
"$php_bin" artisan view:cache
"$php_bin" artisan event:cache

# ── 6. Servicios ────────────────────────────────────────────────────────────
# La cola tiene el código viejo en memoria hasta que se reinicia.
info "Reiniciando la cola"
"$php_bin" artisan queue:restart

# OPcache guarda el PHP compilado; sin recargar php-fpm sigue sirviendo el de
# antes. Se salta sin ruido si el script no corre como root.
if command -v systemctl >/dev/null 2>&1; then
    info "Recargando php-fpm"
    sudo systemctl reload php-fpm 2>/dev/null || echo "  (sin permisos para recargar php-fpm; hacelo a mano)"
fi

# ── 7. Comprobación ─────────────────────────────────────────────────────────
info "Revisando la instalación"
"$php_bin" artisan contable:check-produccion

# ── 8. Abrir ────────────────────────────────────────────────────────────────
info "Saliendo de mantenimiento"
"$php_bin" artisan up
trap - EXIT

printf '\n\033[1;32m✓ Desplegado.\033[0m\n'
