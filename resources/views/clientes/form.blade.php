<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $cliente->exists ? 'Editar' : 'Nuevo' }} cliente
        </h2>
    </x-slot>

    @php
        $tipoActual = old('tipo_cliente', $cliente->tipo_cliente?->value);

        // El bloque "Datos adicionales" nace abierto cuando hace falta: si el tipo de
        // cliente exige esos campos, si hay errores adentro, o si el cliente que se
        // edita ya tiene datos ahí (así nunca se esconde información existente).
        $camposAdicionales = [
            'codigo', 'tipo_persona', 'pais_id', 'departamento_id', 'municipio_id', 'distrito_id',
            'direccion', 'complemento_direccion', 'contacto_principal', 'observaciones',
            'observaciones_facturacion',
        ];
        $adicionalesConDatos = collect($camposAdicionales)->contains(fn ($campo) => filled($cliente->{$campo}));
        $adicionalesAbierto = $errors->hasAny($camposAdicionales)
            || in_array($tipoActual, ['contribuyente', 'exportacion'], true)
            || $adicionalesConDatos
            // Un cliente ya guardado sin salas muestra su ubicación abierta aunque
            // todavía esté vacía: es donde se le carga si no va a tener salas.
            || $clienteSinSalas;

        // El nombre comercial ya no se pide al dar de alta (se captura en el nombre
        // de cada sala). Solo se muestra, por compatibilidad, si el cliente ya lo
        // tiene cargado; si no se renderiza no se postea y la columna queda intacta.
        $mostrarNombreComercial = $cliente->exists && filled($cliente->nombre_comercial);
    @endphp

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                        <p class="font-medium">Corrige los siguientes errores:</p>
                        <ul class="list-disc list-inside mt-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST"
                      action="{{ $cliente->exists ? route('clientes.update', $cliente) : route('clientes.store') }}"
                      x-data="{
                          tipo: @js($tipoActual),
                          tamanio: @js(old('tamanio_contribuyente', $cliente->tamanio_contribuyente?->value)),
                          requiereOc: @js((bool) old('requiere_orden_compra', $cliente->requiere_orden_compra)),
                          adicionales: @js((bool) $adicionalesAbierto),
                          sinSalas: @js((bool) old('sin_salas', false)),
                          clienteSinSalas: @js((bool) $clienteSinSalas),
                          clienteTieneContacto: @js((bool) $clienteTieneContacto),
                          clienteTieneOc: @js((bool) $clienteTieneOc),
                          departamentoId: @js((string) old('departamento_id', $cliente->departamento_id)),
                          municipioId: @js((string) old('municipio_id', $cliente->municipio_id)),
                          distritoId: @js((string) old('distrito_id', $cliente->distrito_id)),
                          {{-- Municipio con su nombre FISCAL 2024 (ej. "Cabañas Oeste") y su
                               código CAT-013: el código es lo que permite filtrar los
                               distritos por municipio. --}}
                          municipios: @js($municipios->map(fn ($m) => ['id' => (string) $m->id, 'nombre' => $m->nombre_fiscal ?? $m->nombre, 'codigo' => (string) $m->codigo, 'departamento_id' => (string) $m->departamento_id])->values()),
                          distritos: @js($distritos->map(fn ($d) => ['id' => (string) $d->id, 'nombre' => $d->nombre, 'municipio' => $d->municipio, 'municipio_codigo' => (string) $d->municipio_codigo, 'departamento_id' => (string) $d->departamento_id])->values()),
                          get esNacional() { return this.tipo === 'consumidor_final' || this.tipo === 'contribuyente'; },
                          get esContribuyente() { return this.tipo === 'contribuyente'; },
                          // La ubicación general del cliente solo se pide cuando NO va a
                          // tener salas, o cuando se edita uno que hoy no tiene ninguna.
                          get mostrarUbicacion() { return this.esNacional && (this.sinSalas || this.clienteSinSalas); },
                          // Contacto: en la sala por defecto. En el cliente solo cuando es
                          // exportación (la FEX lo toma del cliente), cuando se declara sin
                          // salas, o cuando un cliente antiguo sin salas ya lo tiene.
                          get mostrarContacto() { return this.tipo === 'exportacion' || (this.esNacional && this.sinSalas) || (this.clienteSinSalas && this.clienteTieneContacto); },
                          // Orden de compra: se configura en la sala. En el cliente solo
                          // cuando no va a tener salas, o cuando un cliente antiguo sin
                          // salas ya la tiene marcada.
                          get mostrarOc() { return (this.esNacional && this.sinSalas) || (this.clienteSinSalas && this.clienteTieneOc); },
                          get municipiosFiltrados() { return this.municipios.filter(m => m.departamento_id === this.departamentoId); },
                          get municipioElegido() { return this.municipios.find(m => m.id === this.municipioId) ?? null; },
                          {{-- El distrito se filtra por el MUNICIPIO elegido, no solo por el
                               departamento: así no se puede guardar un par imposible como
                               «Cabañas Este» + distrito «Ilobasco» (que es de Cabañas Oeste),
                               que Hacienda rechaza. El servidor lo revalida igual. --}}
                          get distritosFiltrados() {
                              const m = this.municipioElegido;
                              if (! m) { return []; }
                              return this.distritos.filter(d =>
                                  d.departamento_id === m.departamento_id && d.municipio_codigo === m.codigo);
                          },
                          onMunicipioChange() {
                              if (! this.distritosFiltrados.some(d => d.id === this.distritoId)) { this.distritoId = ''; }
                          },
                      }"
                      x-init="$watch('tipo', valor => { if (valor === 'contribuyente' || valor === 'exportacion') adicionales = true })"
                      class="space-y-6">
                    @csrf
                    @if ($cliente->exists) @method('PUT') @endif

                    {{-- ── Bloque 1: identificación ─────────────────────────────────
                         Lo mínimo para dar de alta un cliente. Los campos que no
                         aplican al tipo elegido se ocultan con x-show (no se quitan
                         del DOM: si el cliente ya traía valor, se conserva al guardar). --}}
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 mb-3">Identificación</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="tipo_cliente" value="Tipo de cliente *" />
                                <select id="tipo_cliente" name="tipo_cliente" x-model="tipo"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                    <option value="">— Seleccione —</option>
                                    @foreach ($tiposCliente as $valor => $label)
                                        <option value="{{ $valor }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('tipo_cliente')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="nombre" value="Nombre o razón social *" />
                                <x-text-input id="nombre" name="nombre" type="text" class="mt-1 block w-full"
                                    :value="old('nombre', $cliente->nombre)" required />
                                <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="tipo_documento" value="Tipo de documento" />
                                <select id="tipo_documento" name="tipo_documento" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    @foreach ($tiposDocumento as $valor => $label)
                                        <option value="{{ $valor }}" @selected(old('tipo_documento', $cliente->tipo_documento?->value) === $valor)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('tipo_documento')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="num_documento" value="Número de documento" />
                                <x-text-input id="num_documento" name="num_documento" type="text" class="mt-1 block w-full"
                                    :value="old('num_documento', $cliente->num_documento)" />
                                <x-input-error :messages="$errors->get('num_documento')" class="mt-1" />
                            </div>

                            <div x-show="esContribuyente" x-cloak>
                                <x-input-label for="nrc" value="NRC *" />
                                <x-text-input id="nrc" name="nrc" type="text" class="mt-1 block w-full"
                                    :value="old('nrc', $cliente->nrc)" />
                                <x-input-error :messages="$errors->get('nrc')" class="mt-1" />
                            </div>
                            <div x-show="esContribuyente || tipo === 'exportacion'" x-cloak>
                                <x-input-label for="actividad_economica_id" value="Actividad económica *" />
                                <select id="actividad_economica_id" name="actividad_economica_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    @foreach ($actividades as $actividad)
                                        <option value="{{ $actividad->id }}" @selected(old('actividad_economica_id', $cliente->actividad_economica_id) == $actividad->id)>{{ $actividad->codigo }} — {{ $actividad->nombre }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('actividad_economica_id')" class="mt-1" />
                            </div>

                            {{-- Contacto: por defecto se captura en la sala. Se muestra en
                                 el cliente solo cuando lo necesita (exportación, sin salas,
                                 o cliente antiguo sin salas que ya lo tiene). Sigue en el
                                 DOM para no perder el valor de un cliente existente. --}}
                            <div x-show="mostrarContacto" x-cloak>
                                <x-input-label for="correo" value="Correo" />
                                <x-text-input id="correo" name="correo" type="email" class="mt-1 block w-full"
                                    :value="old('correo', $cliente->correo)" />
                                <x-input-error :messages="$errors->get('correo')" class="mt-1" />
                            </div>
                            <div x-show="mostrarContacto" x-cloak>
                                <x-input-label for="telefono" value="Teléfono" />
                                <x-text-input id="telefono" name="telefono" type="text" class="mt-1 block w-full"
                                    :value="old('telefono', $cliente->telefono)" />
                                <x-input-error :messages="$errors->get('telefono')" class="mt-1" />
                            </div>

                            <div class="md:col-span-2" x-show="esNacional && !mostrarContacto" x-cloak>
                                <p class="text-xs text-gray-400">
                                    El teléfono y el correo se capturan en cada sala. Marque "Este cliente no tendrá salas" (en Datos adicionales) para cargarlos aquí.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- ── Bloque 2: reglas de facturación propias del cliente ────── --}}
                    <div class="border-t border-gray-100 pt-5">
                        <h3 class="text-sm font-medium text-gray-700 mb-3">Reglas de facturación</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="condicion_operacion_default" value="Condición de pago" />
                                <select id="condicion_operacion_default" name="condicion_operacion_default"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Sin definir —</option>
                                    @foreach ($condicionesPago as $valor => $label)
                                        <option value="{{ $valor }}" @selected((string) old('condicion_operacion_default', $cliente->condicion_operacion_default) === (string) $valor)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('condicion_operacion_default')" class="mt-1" />
                                <p class="text-xs text-gray-400 mt-1">
                                    Valor por defecto al crear documentos de este cliente. Una sala puede tener el suyo propio.
                                </p>
                            </div>

                            <div>
                                <x-input-label for="descuento_global_default" value="Descuento global (%)" />
                                <x-text-input id="descuento_global_default" name="descuento_global_default" type="number"
                                    min="0" max="100" step="0.01" placeholder="0.00" class="mt-1 block w-full"
                                    :value="old('descuento_global_default', $cliente->descuento_global_default)" />
                                <x-input-error :messages="$errors->get('descuento_global_default')" class="mt-1" />
                                <p class="text-xs text-gray-400 mt-1">
                                    Es un porcentaje. Ej. escriba <strong>5</strong> para aplicar 5% de descuento automáticamente, salvo que una sucursal tenga un porcentaje específico.
                                </p>
                            </div>

                            <div>
                                <x-input-label for="tamanio_contribuyente" value="Tamaño de contribuyente" />
                                <select id="tamanio_contribuyente" name="tamanio_contribuyente" x-model="tamanio"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    @foreach ($tamaniosContribuyente as $valor => $label)
                                        <option value="{{ $valor }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('tamanio_contribuyente')" class="mt-1" />

                                {{-- Retención: informativa, no manual. Se deriva del tamaño. --}}
                                <p class="mt-2 text-xs" x-show="tamanio === 'grande'" x-cloak>
                                    <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700">
                                        Este cliente se marcará como agente de retención.
                                    </span>
                                </p>
                                <p class="mt-2 text-xs text-gray-500" x-show="tamanio === 'pequeno' || tamanio === 'mediano'" x-cloak>
                                    Este cliente no se marcará como agente de retención.
                                </p>
                                <p class="mt-2 text-xs text-gray-400" x-show="!tamanio" x-cloak>
                                    Seleccione el tamaño del contribuyente para determinar la retención.
                                </p>
                            </div>

                            {{-- Orden de compra: por defecto se configura por sala. En el
                                 cliente solo cuando no tendrá salas (o ya la tenía). El
                                 hidden "0" y el checkbox siguen en el DOM: un cliente que ya
                                 la tiene marcada conserva el valor aunque el campo se oculte. --}}
                            <div x-show="mostrarOc" x-cloak>
                                <label class="inline-flex items-center mt-7">
                                    <input type="hidden" name="requiere_orden_compra" value="0">
                                    <input type="checkbox" name="requiere_orden_compra" value="1" x-model="requiereOc"
                                        class="rounded border-gray-300"
                                        @checked(old('requiere_orden_compra', $cliente->requiere_orden_compra))>
                                    <span class="ml-2 text-sm text-gray-700">Requiere orden de compra en CCF</span>
                                </label>

                                <div class="mt-3" x-show="requiereOc" x-cloak>
                                    <x-input-label for="etiqueta_orden_compra" value="Etiqueta del campo" />
                                    <x-text-input id="etiqueta_orden_compra" name="etiqueta_orden_compra" type="text" class="mt-1 block w-full"
                                        :value="old('etiqueta_orden_compra', $cliente->etiqueta_orden_compra ?? 'Orden de compra')" />
                                    <x-input-error :messages="$errors->get('etiqueta_orden_compra')" class="mt-1" />
                                    <p class="text-xs text-gray-400 mt-1">Si se deja vacío, se usará "Orden de compra".</p>
                                </div>
                            </div>

                            <div x-show="esNacional && !mostrarOc" x-cloak>
                                <p class="text-xs text-gray-400 mt-7">
                                    La orden de compra se configura en cada sala.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- ── Bloque 3: datos adicionales (plegable) ───────────────────
                         Ubicación, dirección y notas. Se abre solo cuando el tipo de
                         cliente los exige, cuando hay errores adentro o cuando el
                         cliente que se edita ya tiene algo cargado. --}}
                    <details class="border-t border-gray-100 pt-5 group"
                             x-bind:open="adicionales"
                             x-on:toggle="adicionales = $event.target.open">
                        <summary class="cursor-pointer text-sm font-medium text-gray-700 select-none">
                            Datos adicionales
                            <span class="ml-1 text-xs font-normal text-gray-400">
                                (ubicación, dirección, contacto y notas — opcionales para consumidor final)
                            </span>
                        </summary>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                            <div>
                                <x-input-label for="codigo" value="Código interno" />
                                <x-text-input id="codigo" name="codigo" type="text" class="mt-1 block w-full"
                                    :value="old('codigo', $cliente->codigo)" />
                                <x-input-error :messages="$errors->get('codigo')" class="mt-1" />
                                <p class="text-xs text-gray-400 mt-1">Opcional. Si se usa, no puede repetirse entre clientes.</p>
                            </div>
                            <div>
                                <x-input-label for="tipo_persona" value="Tipo de persona" />
                                <select id="tipo_persona" name="tipo_persona" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    @foreach ($tiposPersona as $valor => $label)
                                        <option value="{{ $valor }}" @selected(old('tipo_persona', $cliente->tipo_persona?->value) === $valor)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('tipo_persona')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="pais_id" value="País" />
                                <select id="pais_id" name="pais_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    @foreach ($paises as $pais)
                                        <option value="{{ $pais->id }}" @selected(old('pais_id', $cliente->pais_id ?? $paisElSalvadorId) == $pais->id)>{{ $pais->nombre }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('pais_id')" class="mt-1" />
                            </div>
                            <div class="hidden md:block"></div>

                            {{-- Declaración explícita del usuario: si el cliente no va a
                                 tener salas, su propia ubicación es la fiscal y se le
                                 exige (al contribuyente). Nunca se marca sola. --}}
                            <div class="md:col-span-2" x-show="esNacional" x-cloak>
                                <label class="inline-flex items-start">
                                    <input type="hidden" name="sin_salas" value="0">
                                    <input type="checkbox" name="sin_salas" value="1" x-model="sinSalas"
                                        class="rounded border-gray-300 mt-0.5">
                                    <span class="ml-2 text-sm text-gray-700">
                                        Este cliente no tendrá salas
                                        <span class="block text-xs text-gray-400">
                                            Su dirección será la que viaje en el documento. Si más adelante se le agrega una sala, la sala manda.
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <div class="md:col-span-2" x-show="esContribuyente && sinSalas" x-cloak>
                                <p class="text-xs text-amber-600">
                                    Departamento y municipio son obligatorios: sin salas, esta es la ubicación fiscal que se usa en el CCF.
                                </p>
                            </div>

                            <div x-show="mostrarUbicacion" x-cloak>
                                <x-input-label for="departamento_id" value="Departamento" />
                                <select id="departamento_id" name="departamento_id"
                                        x-model="departamentoId" x-on:change="municipioId=''; distritoId=''"
                                        :disabled="!esNacional"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    @foreach ($departamentos as $depto)
                                        <option value="{{ $depto->id }}">{{ $depto->nombre }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('departamento_id')" class="mt-1" />
                            </div>
                            <div x-show="mostrarUbicacion" x-cloak>
                                <x-input-label for="municipio_id" value="Municipio" />
                                <select id="municipio_id" name="municipio_id"
                                        x-model="municipioId" x-on:change="onMunicipioChange()" :disabled="!esNacional"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    <template x-for="m in municipiosFiltrados" :key="m.id">
                                        <option :value="m.id" x-text="m.nombre"></option>
                                    </template>
                                </select>
                                <x-input-error :messages="$errors->get('municipio_id')" class="mt-1" />
                                <p class="text-xs text-gray-400 mt-1" x-show="esNacional && departamentoId === ''">Seleccione primero un departamento.</p>
                            </div>
                            <div x-show="mostrarUbicacion" x-cloak>
                                <x-input-label for="distrito_id" value="Distrito (división 2024)" />
                                <select id="distrito_id" name="distrito_id"
                                        x-model="distritoId" :disabled="!esNacional"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    {{-- Solo el nombre del distrito: el municipio ya se eligió
                                         arriba, prefijarlo acá era redundante y hacía parecer
                                         que el distrito traía su propio municipio. --}}
                                    <template x-for="d in distritosFiltrados" :key="d.id">
                                        <option :value="d.id" x-text="d.nombre"></option>
                                    </template>
                                </select>
                                <x-input-error :messages="$errors->get('distrito_id')" class="mt-1" />
                                <p class="text-xs text-gray-400 mt-1" x-show="esNacional && municipioId === ''" x-cloak>Seleccione primero un municipio.</p>
                            </div>
                            <div class="hidden md:block" x-show="mostrarUbicacion" x-cloak></div>

                            <div class="md:col-span-2" x-show="mostrarUbicacion || tipo === 'exportacion'" x-cloak>
                                <x-input-label for="direccion" value="Dirección" />
                                <x-text-input id="direccion" name="direccion" type="text" class="mt-1 block w-full"
                                    :value="old('direccion', $cliente->direccion)" />
                                <x-input-error :messages="$errors->get('direccion')" class="mt-1" />
                            </div>
                            <div class="md:col-span-2" x-show="mostrarUbicacion || tipo === 'exportacion'" x-cloak>
                                <x-input-label for="complemento_direccion" value="Complemento de dirección" />
                                <x-text-input id="complemento_direccion" name="complemento_direccion" type="text" class="mt-1 block w-full"
                                    :value="old('complemento_direccion', $cliente->complemento_direccion)" />
                                <x-input-error :messages="$errors->get('complemento_direccion')" class="mt-1" />
                                <p class="text-xs text-gray-400 mt-1" x-show="tipo === 'exportacion'" x-cloak>Para exportación, indique dirección o complemento.</p>
                            </div>

                            <div class="md:col-span-2">
                                <x-input-label for="contacto_principal" value="Contacto principal" />
                                <x-text-input id="contacto_principal" name="contacto_principal" type="text" class="mt-1 block w-full"
                                    :value="old('contacto_principal', $cliente->contacto_principal)" />
                                <x-input-error :messages="$errors->get('contacto_principal')" class="mt-1" />
                            </div>

                            <div class="md:col-span-2">
                                <x-input-label for="observaciones" value="Observaciones" />
                                <textarea id="observaciones" name="observaciones" rows="2"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('observaciones', $cliente->observaciones) }}</textarea>
                                <x-input-error :messages="$errors->get('observaciones')" class="mt-1" />
                            </div>
                            <div class="md:col-span-2">
                                <x-input-label for="observaciones_facturacion" value="Observaciones de facturación" />
                                <textarea id="observaciones_facturacion" name="observaciones_facturacion" rows="2"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('observaciones_facturacion', $cliente->observaciones_facturacion) }}</textarea>
                                <x-input-error :messages="$errors->get('observaciones_facturacion')" class="mt-1" />
                            </div>
                        </div>

                        {{-- Compatibilidad: el nombre comercial ya no se pide al dar de
                             alta (se captura como nombre de cada sala), pero se sigue
                             mostrando y guardando en los clientes que ya lo tienen. --}}
                        @if ($mostrarNombreComercial)
                            <div class="mt-6 pt-4 border-t border-gray-100">
                                <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-3">Compatibilidad</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <x-input-label for="nombre_comercial" value="Nombre comercial" />
                                        <x-text-input id="nombre_comercial" name="nombre_comercial" type="text" class="mt-1 block w-full"
                                            :value="old('nombre_comercial', $cliente->nombre_comercial)" />
                                        <x-input-error :messages="$errors->get('nombre_comercial')" class="mt-1" />
                                        <p class="text-xs text-gray-400 mt-1">
                                            Dato heredado. En los clientes nuevos el nombre comercial se captura como nombre de cada sala.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </details>

                    <label class="inline-flex items-center">
                        <input type="hidden" name="activo" value="0">
                        <input type="checkbox" name="activo" value="1" class="rounded border-gray-300"
                            @checked(old('activo', $cliente->activo ?? true))>
                        <span class="ml-2 text-sm text-gray-700">Activo</span>
                    </label>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-primary-button name="accion" value="guardar">
                            {{ $cliente->exists ? 'Guardar cambios' : 'Guardar cliente' }}
                        </x-primary-button>

                        @unless ($cliente->exists)
                            <button type="submit" name="accion" value="guardar_y_sala"
                                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                Guardar y agregar primera sala
                            </button>
                        @endunless

                        <a href="{{ route('clientes.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
