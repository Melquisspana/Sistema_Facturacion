@echo off
setlocal enableextensions
title Abrir Facturacion en el navegador

rem ------------------------------------------------------------------
rem  Acceso rapido OPCIONAL: solo ABRE la app en el navegador.
rem
rem   - NO arranca Laragon, ni el worker, ni el firmador (son aparte).
rem   - NO emite, NO transmite, NO envia correos.
rem   - Es solo un doble clic comodo para abrir la pantalla de inicio.
rem
rem  Si tu URL no es http://localhost (por ejemplo un host de Laragon o
rem  la IP de la PC en la red), edita la linea "set URL=" de abajo.
rem ------------------------------------------------------------------

set "URL=http://localhost/"

echo Abriendo Facturacion: %URL%
echo (Si no abre, revisa que Laragon este corriendo: Apache/Nginx + MySQL.)
start "" "%URL%"

rem Sugerencia: despues de abrir, revisa el semaforo en
rem   Facturacion ^> Preparar emision real
rem para confirmar app, base de datos, worker y firmador.
