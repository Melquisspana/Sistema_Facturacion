#!/usr/bin/env bash
#
# Reparte los archivos de prueba en partes para correr la suite en paralelo en la CI
# (job «PHPUnit (MySQL estricto)» de .github/workflows/ci.yml).
#
#   reparto-pruebas.sh archivos  <parte> <total>   imprime los archivos de esa parte
#   reparto-pruebas.sh correr    <parte> <total>   corre PHPUnit solo con esa parte
#   reparto-pruebas.sh comprobar <total>           falla si un archivo se pierde o se repite
#   reparto-pruebas.sh contra-phpunit              falla si la lista no es la que corre PHPUnit
#
# Cómo reparte. La lista sale de las carpetas que phpunit.xml declara en <testsuites>,
# en ese orden, con los archivos *Test.php de cada una ordenados por ruta: es el mismo
# orden en que los corre PHPUnit. Se reparten por peso (ver filtrar_parte): así las
# partes terminan más o menos juntas. No hay ninguna lista escrita a mano: un archivo o
# una carpeta de pruebas nuevos entran solos.
#
# Cada parte se corre pasando sus archivos como argumentos a PHPUnit, que sigue leyendo
# phpunit.xml completo: mismas variables, mismo arranque, misma configuración.
#
# «comprobar» no necesita PHP ni corre ninguna prueba. «contra-phpunit» tampoco corre
# pruebas: solo le pide a PHPUnit la lista de archivos (--list-test-files).

set -euo pipefail

# Siempre desde la raíz del proyecto, se llame desde donde se llame.
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

fallar() {
    echo "reparto-pruebas: $*" >&2
    exit 1
}

validar_total() {
    [[ "${1:-}" =~ ^[1-9][0-9]*$ ]] || fallar "el total de partes debe ser un entero positivo (llegó «${1:-}»)."
}

validar_parte() {
    validar_total "$2"
    [[ "${1:-}" =~ ^[1-9][0-9]*$ ]] && (( $1 <= $2 )) || fallar "la parte debe estar entre 1 y $2 (llegó «${1:-}»)."
}

# Las carpetas de <testsuites> de phpunit.xml, en el orden en que están escritas.
# (El <directory> de <source> queda fuera: es el código de la aplicación, no pruebas.)
carpetas_de_pruebas() {
    sed -n '/<testsuites>/,/<\/testsuites>/p' phpunit.xml | tr -d '\r' \
        | sed -n 's:.*<directory[^>]*>\([^<]*\)</directory>.*:\1:p'
}

# Todos los archivos de prueba, siempre en el mismo orden en cualquier máquina (LC_ALL=C).
todos_los_archivos() {
    local carpetas carpeta
    carpetas=$(carpetas_de_pruebas)
    [[ -n "$carpetas" ]] || fallar "phpunit.xml no declara ninguna carpeta de pruebas en <testsuites>."

    while IFS= read -r carpeta; do
        [[ -d "$carpeta" ]] || fallar "la carpeta de pruebas «$carpeta» de phpunit.xml no existe."
        find "$carpeta" -type f -name '*Test.php' | LC_ALL=C sort
    done <<< "$carpetas"
}

# Lee la lista completa por la entrada y deja pasar solo los archivos de la parte pedida.
#
# Reparte por PESO, no por turno (27/11/2026: por turno, la parte más cargada de MySQL
# tardaba 38 minutos y la más liviana 22). El peso de un archivo es cuántas pruebas tiene
# (`function test…`, `#[Test]` o `@test`) más uno por arrancarlo. Se ordenan de más pesado
# a más liviano (a igual peso, por ruta) y cada uno va a la parte que lleva menos peso
# hasta ese momento (a igual carga, la de número más chico). Es determinista: la misma
# lista da el mismo reparto en cualquier máquina. Cada parte sale ordenada por ruta.
filtrar_parte() {
    local parte="$1" total="$2"
    local -a archivos
    mapfile -t archivos
    (( ${#archivos[@]} > 0 )) || return 0
    # Un solo grep para todos los archivos (uno por archivo tardaba minutos en Windows).
    # -H fuerza «archivo:cuenta» aunque haya uno solo; -c da 0 si no encuentra nada.
    grep -HcE 'function test[A-Za-z0-9_]*[[:space:]]*\(|#\[Test\]|@test\b' -- "${archivos[@]}" \
        | awk -F ':' '{ cuenta = $NF; sub(/:[0-9]+$/, ""); printf "%d\t%s\n", cuenta + 1, $0 }' \
        | LC_ALL=C sort -t $'\t' -k1,1nr -k2,2 \
        | awk -F '\t' -v parte="$parte" -v total="$total" '
            {
                elegida = 1
                for (p = 2; p <= total; p++) if (carga[p] + 0 < carga[elegida] + 0) elegida = p
                carga[elegida] += $1
                if (elegida == parte) print $2
            }' \
        | LC_ALL=C sort
}

modo="${1:-}"

case "$modo" in
    archivos)
        validar_parte "${2:-}" "${3:-}"
        lista=$(todos_los_archivos)
        filtrar_parte "$2" "$3" <<< "$lista"
        ;;

    correr)
        validar_parte "${2:-}" "${3:-}"
        lista=$(todos_los_archivos)
        mapfile -t archivos < <(filtrar_parte "$2" "$3" <<< "$lista")
        # Sin argumentos PHPUnit correría la suite entera: mejor fallar que esconderlo.
        (( ${#archivos[@]} > 0 )) || fallar "la parte $2 de $3 quedó sin archivos."
        echo "Parte $2 de $3: ${#archivos[@]} archivos de prueba."
        exec vendor/bin/phpunit "${archivos[@]}"
        ;;

    comprobar)
        validar_total "${2:-}"
        total="$2"
        lista=$(todos_los_archivos)
        [[ -n "$lista" ]] || fallar "no se encontró ningún archivo de prueba."
        tmp=$(mktemp -d)
        trap 'rm -rf "$tmp"' EXIT

        LC_ALL=C sort <<< "$lista" > "$tmp/completo"

        # Las partes, una detrás de otra y SIN quitar repetidos: un archivo que cayera
        # en dos partes tiene que aparecer dos veces y hacer fallar lo de abajo.
        : > "$tmp/partes"
        for (( parte = 1; parte <= total; parte++ )); do
            filtrar_parte "$parte" "$total" <<< "$lista" > "$tmp/parte"
            cuenta=$(wc -l < "$tmp/parte" | tr -d ' ')
            (( cuenta > 0 )) || fallar "la parte $parte de $total quedó sin archivos: hay más partes que archivos."
            echo "Parte $parte de $total: $cuenta archivos."
            cat "$tmp/parte" >> "$tmp/partes"
        done
        LC_ALL=C sort -o "$tmp/partes" "$tmp/partes"

        repetidos=$(uniq -d "$tmp/partes")
        if [[ -n "$repetidos" ]]; then
            echo "Hay archivos de prueba en más de una parte:" >&2
            echo "$repetidos" >&2
            exit 1
        fi

        if ! diff "$tmp/completo" "$tmp/partes" > "$tmp/diferencias"; then
            echo "El reparto NO coincide con la lista completa." >&2
            echo "  «<» = archivo que ninguna parte corre; «>» = archivo que sobra." >&2
            cat "$tmp/diferencias" >&2
            exit 1
        fi

        echo "Reparto correcto: $(wc -l < "$tmp/completo" | tr -d ' ') archivos de prueba, cada uno en exactamente una de las $total partes."
        ;;

    contra-phpunit)
        lista=$(todos_los_archivos)
        salida=$(vendor/bin/phpunit --list-test-files) || fallar "PHPUnit no pudo listar los archivos de prueba."
        tmp=$(mktemp -d)
        trap 'rm -rf "$tmp"' EXIT

        LC_ALL=C sort <<< "$lista" > "$tmp/reparto"

        # PHPUnit imprime una línea « - /ruta/completa/al/archivo » por cada archivo.
        raiz=$(pwd -P)
        tr -d '\r' <<< "$salida" | sed -n 's/^ - //p' | while IFS= read -r archivo; do
            printf '%s\n' "${archivo#"$raiz"/}"
        done | LC_ALL=C sort > "$tmp/phpunit"
        [[ -s "$tmp/phpunit" ]] || fallar "PHPUnit no listó ningún archivo de prueba."

        if ! diff "$tmp/reparto" "$tmp/phpunit" > "$tmp/diferencias"; then
            echo "La lista del reparto NO es la que corre PHPUnit con phpunit.xml." >&2
            echo "  «<» = archivo *Test.php que PHPUnit no corre (clase abstracta, sin pruebas o" >&2
            echo "        con otro nombre que el archivo): renombrarlo para que no termine en Test.php." >&2
            echo "  «>» = archivo que PHPUnit corre y el reparto no ve: ninguna parte lo correría." >&2
            cat "$tmp/diferencias" >&2
            exit 1
        fi

        echo "La lista del reparto es la misma que corre PHPUnit: $(wc -l < "$tmp/phpunit" | tr -d ' ') archivos."
        ;;

    *)
        fallar "uso: reparto-pruebas.sh archivos|correr <parte> <total>  |  comprobar <total>  |  contra-phpunit"
        ;;
esac
