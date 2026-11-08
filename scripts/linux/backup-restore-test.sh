#!/usr/bin/env bash
# ==========================================================================
#  PRUEBA SEGURA DE RESTAURACIÓN DE BACKUP (Linux con Docker).
#  Equivalente de scripts/backup-restore-test.bat.
#
#  Restaura un respaldo SOLO en la base TEMPORAL indicada. Se corre dentro del
#  contenedor web, que tiene el cliente MySQL 8.4 y ve storage/ y backups/:
#
#    docker compose --env-file .env.compose exec -u www-data \
#        -e BACKUP_REAL_DB=base_ejemplo \
#        -e BACKUP_RESTORE_TEST_DB=base_ejemplo_restore_test \
#        web bash scripts/linux/backup-restore-test.sh [archivo.zip|archivo.sql]
#
#  - Sin archivo: usa el ZIP más reciente de spatie en storage/app/private/<APP_NAME>/.
#    También acepta un volcado .sql de backups/ (backup:mysql-diario).
#  - Pide confirmación y la contraseña del usuario de MySQL (por defecto root, que
#    puede crear y borrar bases). La contraseña no se muestra ni va en la línea de
#    comandos: viaja en un archivo temporal 0600 que se borra al terminar.
#  - Si el ZIP está cifrado, toma la clave del .env de Laravel (backup.backup.password,
#    BACKUP_ARCHIVE_PASSWORD); no hace falta pasarla por la línea de comandos. Si está
#    en el entorno, esa tiene prioridad.
#  - Importa, muestra conteos básicos y al final ofrece borrar la base temporal, también
#    si la creación o la importación fallan.
#
#  Variables opcionales: MYSQL_HOST (mysql), MYSQL_PORT (3306), MYSQL_RESTORE_USER (root).
# ==========================================================================
set -euo pipefail

cd "$(dirname "$0")/../.."

REAL_DB="${BACKUP_REAL_DB:-}"
TEMP_DB="${BACKUP_RESTORE_TEST_DB:-}"
HOST="${MYSQL_HOST:-mysql}"
PUERTO="${MYSQL_PORT:-3306}"
USUARIO="${MYSQL_RESTORE_USER:-root}"

# Validar los nombres antes de usarlos en SQL: solo letras, números y guion bajo.
if [[ ! "$REAL_DB" =~ ^[A-Za-z0-9_]+$ || ! "$TEMP_DB" =~ ^[A-Za-z0-9_]+_restore_test$ ]]; then
    echo "ERROR: configure BACKUP_REAL_DB y BACKUP_RESTORE_TEST_DB con nombres válidos."
    echo "La base temporal debe terminar en _restore_test."
    exit 1
fi

# Guarda de seguridad: la temporal jamás puede ser la real.
if [[ "${TEMP_DB,,}" == "${REAL_DB,,}" ]]; then
    echo "ERROR de seguridad: la base temporal coincide con la real. Abortando."
    exit 1
fi

if [[ ! "$USUARIO" =~ ^[A-Za-z0-9_.-]+$ || ! "$PUERTO" =~ ^[0-9]+$ || ! "$HOST" =~ ^[A-Za-z0-9_.-]+$ ]]; then
    echo "ERROR: MYSQL_HOST, MYSQL_PORT o MYSQL_RESTORE_USER no son válidos."
    exit 1
fi

# 1. Ubicar el respaldo.
ARCHIVO="${1:-}"
if [[ -z "$ARCHIVO" ]]; then
    ARCHIVO="$(find storage/app/private -mindepth 2 -maxdepth 2 -type f -name '*.zip' -printf '%T@ %p\n' 2>/dev/null \
        | sort -nr | head -n 1 | cut -d' ' -f2-)"
fi
if [[ -z "$ARCHIVO" || ! -f "$ARCHIVO" ]]; then
    echo "ERROR: no se encontró el respaldo (${ARCHIVO:-ningún .zip en storage/app/private/<APP_NAME>/})."
    exit 1
fi

echo "=========================================================================="
echo " Prueba de restauración en base TEMPORAL: $TEMP_DB"
echo " La base real ($REAL_DB) NO se toca."
echo " Respaldo: $ARCHIVO"
echo "=========================================================================="
echo

read -r -p "Escriba SI (mayúsculas) para continuar: " CONFIRMA
if [[ "$CONFIRMA" != "SI" ]]; then
    echo "Cancelado por el usuario."
    exit 0
fi

WORK_DIR="$(mktemp -d)"
chmod 700 "$WORK_DIR"
trap 'rm -rf "$WORK_DIR"' EXIT

read -r -s -p "Contraseña de MySQL para $USUARIO: " CLAVE
echo
CREDENCIALES="$WORK_DIR/credenciales.cnf"
# En el archivo de opciones, dentro de comillas, \ y " se escriben escapados.
CLAVE="${CLAVE//\\/\\\\}"
CLAVE="${CLAVE//\"/\\\"}"
( umask 077; printf '[client]\nuser=%s\npassword="%s"\nhost=%s\nport=%s\n' \
    "$USUARIO" "$CLAVE" "$HOST" "$PUERTO" > "$CREDENCIALES" )
unset CLAVE
mysql_() { mysql --defaults-extra-file="$CREDENCIALES" "$@"; }

# Al final y ante un error de creación o importación: la temporal puede tener datos
# reales a medias y no se deja sin preguntar.
ofrecer_borrado() {
    read -r -p "¿Borrar la base temporal $TEMP_DB ahora? (SI/NO): " BORRAR
    if [[ "$BORRAR" == "SI" ]]; then
        mysql_ -e "DROP DATABASE IF EXISTS \`$TEMP_DB\`;"
        echo "Base temporal eliminada."
    else
        echo "Se deja $TEMP_DB para que la inspecciones. Bórrala luego con:"
        echo "  mysql -u $USUARIO -p -h $HOST -e 'DROP DATABASE IF EXISTS \`$TEMP_DB\`;'"
    fi
}

# 2-3. Sacar SOLO el .sql del ZIP (o usar el .sql tal cual).
SQL="$WORK_DIR/dump.sql"
case "$ARCHIVO" in
    *.sql)
        cp "$ARCHIVO" "$SQL"
        ;;
    *.zip)
        php -r '
            $zip = new ZipArchive();
            if ($zip->open($argv[1]) !== true) { fwrite(STDERR, "No se pudo abrir el ZIP.\n"); exit(1); }
            // La clave vive en el .env montado, no en el entorno del contenedor: se toma de
            // la configuración de Laravel sin imprimirla ni pasarla por la línea de comandos.
            $clave = getenv("BACKUP_ARCHIVE_PASSWORD");
            if ($clave === false || $clave === "") {
                require "vendor/autoload.php";
                $app = require "bootstrap/app.php";
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                $clave = (string) config("backup.backup.password");
            }
            if ($clave !== "") { $zip->setPassword($clave); }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = $zip->getNameIndex($i);
                if (str_ends_with(strtolower($nombre), ".sql")) {
                    $entrada = $zip->getStream($nombre);
                    if ($entrada === false) { fwrite(STDERR, "No se pudo leer el .sql (¿el ZIP está cifrado y la clave BACKUP_ARCHIVE_PASSWORD del .env falta o no es la correcta?).\n"); exit(1); }
                    $salida = fopen($argv[2], "wb");
                    stream_copy_to_stream($entrada, $salida);
                    exit(0);
                }
            }
            fwrite(STDERR, "El ZIP no trae un .sql.\n");
            exit(1);
        ' "$ARCHIVO" "$SQL"
        ;;
    *)
        echo "ERROR: el respaldo tiene que ser .zip o .sql."
        exit 1
        ;;
esac
if [[ ! -s "$SQL" ]]; then
    echo "ERROR: el .sql del respaldo está vacío."
    exit 1
fi
echo "Dump listo: $(du -h "$SQL" | cut -f1)"

# 4. (Re)crear la base temporal: DROP/CREATE solo de la TEMPORAL.
echo "Creando base temporal $TEMP_DB ..."
if ! mysql_ -e "DROP DATABASE IF EXISTS \`$TEMP_DB\`; CREATE DATABASE \`$TEMP_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"; then
    echo "ERROR creando la base temporal."
    ofrecer_borrado
    exit 1
fi

# 5. Importar.
echo "Importando dump en $TEMP_DB ... (puede tardar)"
if ! mysql_ "$TEMP_DB" < "$SQL"; then
    echo "ERROR importando el dump. La base $TEMP_DB puede tener datos a medias."
    ofrecer_borrado
    exit 1
fi

# 6. Verificación: conteos básicos.
echo
echo "--- Tablas restauradas en $TEMP_DB ---"
mysql_ -e "SELECT COUNT(*) AS total_tablas FROM information_schema.tables WHERE table_schema='$TEMP_DB';"
echo "--- Filas en tablas clave ---"
mysql_ "$TEMP_DB" -e "SELECT 'clientes' AS tabla, COUNT(*) AS filas FROM clientes UNION ALL SELECT 'productos', COUNT(*) FROM productos UNION ALL SELECT 'dtes', COUNT(*) FROM dtes UNION ALL SELECT 'users', COUNT(*) FROM users;" || true

echo
echo "===================================================================="
echo " PRUEBA EXITOSA: el respaldo se restauró en la base temporal $TEMP_DB."
echo " La base real $REAL_DB NO fue modificada."
echo "===================================================================="
echo

ofrecer_borrado
