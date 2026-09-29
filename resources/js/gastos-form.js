// Importes de dos decimales: unidades menores enteras también en el formulario.
const centavos = value => /^\d{1,9}(\.\d{1,2})?$/.test(String(value))
    ? Number(String(value).split('.')[0]) * 100 + Number((String(value).split('.')[1] || '').padEnd(2, '0')) : 0;
const decimal = value => `${Math.floor(value / 100)}.${String(value % 100).padStart(2, '0')}`;

export function gastoFormulario(inicial) {
    return {
        ...inicial, enviando: false,
        get totalCuotas() { return this.cuotas.reduce((total, c) => total + centavos(c.importe), 0); },
        get totalAplicado() { return this.cuotas.reduce((total, c) => total + centavos(c.aplicar), 0); },
        get diferenciaCuotas() { return decimal(Math.abs(centavos(this.importe) - this.totalCuotas)); },
        get restantePago() { return decimal(Math.max(0, centavos(this.importe) - centavos(this.pagoImporte))); },
        formato: decimal,
        // Con una sola cuota el usuario no ve campos de cuota: la cuota ES el gasto,
        // así que su importe y su reparto se copian de los de arriba. Con varias, cada
        // fila es suya y esto no toca nada.
        sincronizar() {
            // Marcar «Ya lo pagué» y escribir el total después es el orden natural: si el
            // importe pagado sigue en blanco, se propone el total (editable para un abono).
            if (this.pagado && !this.pagoImporte) this.pagoImporte = this.importe;
            if (this.cuotas.length === 1) this.cuotas[0].importe = this.importe;
            if (this.pagado && this.cuotas.length === 1) this.cuotas[0].aplicar = this.pagoImporte;
        },
        cambiarPago() {
            if (this.pagado) {
                this.montoPendiente = false;
                this.sincronizar();
                this.repartir();
            }
        },
        agregarCuota() {
            if (this.cuotas.length < this.maxCuotas) this.cuotas.push({ importe: '', vence: '', aplicar: '' });
        },
        quitarCuota(i) { this.cuotas.splice(i, 1); this.sincronizar(); },
        repartir() {
            let resto = centavos(this.pagoImporte);
            this.cuotas.forEach(c => { c.aplicar = '0.00'; });
            [...this.cuotas].sort((a, b) => (a.vence || '9999').localeCompare(b.vence || '9999')).forEach(c => {
                const monto = Math.min(resto, centavos(c.importe));
                c.aplicar = decimal(monto);
                resto -= monto;
            });
        },
        enviar(event) {
            this.sincronizar();
            if (this.enviando || !event.target.reportValidity()) { event.preventDefault(); return; }
            this.enviando = true;
        },
        init() {
            this.sincronizar();
            window.addEventListener('pageshow', () => { this.enviando = false; });
        },
    };
}

// Los límites llegan desde config/gastos.php: acá solo se avisa antes de subir. La
// validación que MANDA es la del servidor, que además comprueba el contenido real del
// archivo y no el tipo que declare el navegador.
// Registrar pago sobre obligaciones que YA existen. A diferencia del alta, acá los
// saldos vienen del servidor y el reparto se contrasta contra ellos en vivo; el
// servidor los vuelve a leer bajo bloqueo antes de guardar, así que esto es ayuda
// para el operador, no el control.
export function pagoFormulario(inicial) {
    return {
        ...inicial, enviando: false,
        centavosDe: centavos,
        formato: decimal,
        get totalAplicado() { return this.cuotas.reduce((t, c) => t + centavos(c.aplicar), 0); },
        get cuadra() { return this.totalAplicado === centavos(this.importe) && this.totalAplicado > 0; },
        excede(c) { return centavos(c.aplicar) > centavos(c.saldo); },
        // Más antiguas primero. Es una PROPUESTA: el operador puede reescribir cualquier
        // fila, y el reparto que se guarda es el que quede en pantalla.
        repartir() {
            let resto = centavos(this.importe);
            this.cuotas.forEach(c => { c.aplicar = ''; });
            for (const c of this.cuotas) {
                if (resto <= 0) break;
                const monto = Math.min(resto, centavos(c.saldo));
                if (monto > 0) { c.aplicar = decimal(monto); resto -= monto; }
            }
        },
        enviar(event) {
            if (this.enviando || !event.target.reportValidity()) { event.preventDefault(); return; }
            this.enviando = true;
        },
        init() { window.addEventListener('pageshow', () => { this.enviando = false; }); },
    };
}

export function gastoAdjuntos(limites) {
    return {
        ...limites, archivos: [], aviso: '', arrastrando: false,
        agregar(lista) {
            this.aviso = '';
            for (const file of Array.from(lista)) {
                if (this.archivos.length >= this.maxArchivos) {
                    this.aviso = `Podés adjuntar hasta ${this.maxArchivos} archivos.`;
                    break;
                }
                if (!this.tipos.includes(file.type) || file.size > this.maxBytes) {
                    this.aviso = this.avisoTipo;
                    continue;
                }
                this.archivos.push({ file, url: file.type.startsWith('image/') ? URL.createObjectURL(file) : null });
            }
            this.sincronizarArchivos();
        },
        quitar(index) {
            const [item] = this.archivos.splice(index, 1);
            if (item.url) URL.revokeObjectURL(item.url);
            this.sincronizarArchivos();
        },
        sincronizarArchivos() {
            const dt = new DataTransfer();
            this.archivos.forEach(item => dt.items.add(item.file));
            this.$refs.archivos.files = dt.files;
        },
        pegar(event) {
            if (event.clipboardData?.files.length) { event.preventDefault(); this.agregar(event.clipboardData.files); }
        },
        destroy() { this.archivos.forEach(item => { if (item.url) URL.revokeObjectURL(item.url); }); },
    };
}
