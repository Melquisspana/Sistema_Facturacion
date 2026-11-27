#!/usr/bin/env bash
#
# ¿Este push necesita correr las pruebas de PHP? Escribe «completo=true» o «completo=false»
# en $GITHUB_OUTPUT (o en la salida, si no existe).
#
# Solo da «false» cuando TODO lo que cambió contra master es el CHANGELOG.md o la carpeta
# firmware/ (el programa de los lectores, que se compila aparte). Ni la aplicación ni las
# pruebas leen esos archivos, así que no pueden romperlas. Otros .md de docs/ NO entran:
# hay pruebas que los leen (ProcedimientoDespliegueTest, DteSchemaRepositoryTest).
# Cualquier otra cosa, una duda o un error dan «true»: ante la duda se corre todo. En
# master siempre se corre todo.
set -u

decidir() {
    local completo=true

    if [[ "${GITHUB_REF:-}" == "refs/heads/master" ]]; then
        echo "master: se corre todo." >&2
        echo "$completo"
        return
    fi

    git fetch --no-tags --quiet origin master 2> /dev/null || { echo "no se pudo traer master: se corre todo." >&2; echo "$completo"; return; }
    local base
    base=$(git merge-base FETCH_HEAD HEAD 2> /dev/null) || { echo "sin ancestro común con master: se corre todo." >&2; echo "$completo"; return; }

    local cambiados
    cambiados=$(git diff --name-only "$base" HEAD 2> /dev/null) || { echo "no se pudo comparar: se corre todo." >&2; echo "$completo"; return; }
    if [[ -z "$cambiados" ]]; then
        echo "sin archivos cambiados: se corre todo." >&2
        echo "$completo"
        return
    fi

    local otros
    otros=$(grep -vE '^(CHANGELOG\.md$|firmware/)' <<< "$cambiados" || true)
    if [[ -z "$otros" ]]; then
        echo "solo el CHANGELOG o el firmware: las pruebas de PHP no hacen falta." >&2
        completo=false
    else
        echo "cambian archivos del sistema: se corre todo." >&2
    fi
    echo "$completo"
}

completo=$(decidir)
echo "completo=$completo" >> "${GITHUB_OUTPUT:-/dev/stdout}"
