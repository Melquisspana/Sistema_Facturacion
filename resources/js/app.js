import './bootstrap';

// Editor rápido del borrador CCF/Factura/Exportación (carrito sin recargar). No-op fuera
// de la pantalla de edición.
import './ccf-editor';

import Alpine from 'alpinejs';
import { gastoFormulario, pagoFormulario, gastoAdjuntos } from './gastos-form';

Alpine.data('gastoFormulario', gastoFormulario);
Alpine.data('pagoFormulario', pagoFormulario);
Alpine.data('gastoAdjuntos', gastoAdjuntos);

window.Alpine = Alpine;

Alpine.start();
