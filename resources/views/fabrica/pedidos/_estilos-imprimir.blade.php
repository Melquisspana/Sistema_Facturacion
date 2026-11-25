        {{-- Lo de pantalla de los papeles que se imprimen desde el navegador (la hoja de despacho
             y la orden para la caja): la barra que no se imprime, el celular y la impresión. El
             PDF del correo no lo usa. --}}
        .no-print { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; max-width: 820px; margin: 0 auto 16px; }
        .no-print a, .no-print button { display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px; border: 1px solid #C9CDD4; background: #fff; color: #20242C; font: inherit; font-weight: 600; text-decoration: none; border-radius: 6px; cursor: pointer; }
        .no-print a:hover, .no-print button:hover { background: #F8F9FA; }
        .no-print a:focus-visible, .no-print button:focus-visible { outline: 2px solid #2F6B4E; outline-offset: 2px; }

        /* En el celular el recuadro baja debajo de la empresa (solo pantalla; al imprimir, al lado). */
        @media screen and (max-width: 600px) {
            td.head-emisor, td.head-dbox { display: block; width: auto; padding: 0 0 14px; }
        }

        @media print {
            body { padding: 0; }
            .hoja { max-width: none; }
            .head, .sec { page-break-inside: avoid; }
            .no-print { display: none; }
        }
