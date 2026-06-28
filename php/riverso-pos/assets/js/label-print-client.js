/**
 * RiversoLabelPrint - Cliente JavaScript para impresión de etiquetas desde el navegador
 * Se comunica con el agente local PrintAgentHost ejecutándose en http://127.0.0.1:19284/
 */

const RiversoLabelPrint = (function () {
    const DEFAULT_AGENT_URL = 'http://127.0.0.1:19284';
    let agentUrl = DEFAULT_AGENT_URL;
    let authToken = '';
    let agentHealthy = false;
    let availablePrinters = [];
    let preferredPrinter = null;

    /**
     * Inicializa el cliente con URL y token personalizados
     */
    function init(customUrl, customToken) {
        if (customUrl) {
            agentUrl = customUrl;
        }
        if (customToken) {
            authToken = customToken;
        }
        checkAgent();
    }

    /**
     * Verifica si el agente está activo
     */
    async function checkAgent() {
        try {
            const response = await fetch(`${agentUrl}/health`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken && { 'Authorization': `Bearer ${authToken}` })
                }
            });
            agentHealthy = response.ok;
            if (agentHealthy) {
                loadPrinters();
            }
            return agentHealthy;
        } catch (error) {
            agentHealthy = false;
            return false;
        }
    }

    /**
     * Carga la lista de impresoras disponibles
     */
    async function loadPrinters() {
        try {
            const response = await fetch(`${agentUrl}/printers`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken && { 'Authorization': `Bearer ${authToken}` })
                }
            });

            if (response.ok) {
                const data = await response.json();
                availablePrinters = data.printers || [];
                preferredPrinter = data.preferred;
                return data;
            }
        } catch (error) {
            // Error al cargar impresoras
        }
        return null;
    }

    /**
     * Retorna el estado del agente
     */
    function isHealthy() {
        return agentHealthy;
    }

    /**
     * Retorna la lista de impresoras disponibles
     */
    function getPrinters() {
        return availablePrinters;
    }

    /**
     * Retorna la impresora preferida
     */
    function getPreferred() {
        return preferredPrinter;
    }

    /**
     * Imprime un lote de trabajos
     * @param {Array} jobs - Array de trabajos: { nombre, sku, cantidad, precio, copias, modo, color, ean13, printerName }
     * @returns {Promise} Resultado de la impresión
     */
    async function print(jobs) {
        if (!Array.isArray(jobs) || jobs.length === 0) {
            throw new Error('Se requiere al menos un trabajo');
        }

        const payload = {
            jobs: jobs.map(job => ({
                nombre: job.nombre || 'Sin nombre',
                sku: job.sku || '0',
                cantidad: Math.max(1, job.cantidad || 100),
                precio: job.precio || null,
                copias: Math.max(1, job.copias || 1),
                modo: job.modo || 'BolsaCOD',
                color: job.color || 'BN',
                ean13: job.ean13 || null,
                printerName: job.printerName || null
            }))
        };

        try {
            const response = await fetch(`${agentUrl}/print`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken && { 'Authorization': `Bearer ${authToken}` })
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || `Error ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            throw new Error(`Error de impresión: ${error.message}`);
        }
    }

    /**
     * Guarda la impresora preferida en el agente
     */
    async function selectPrinter(printerName) {
        try {
            const response = await fetch(`${agentUrl}/printers/select`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken && { 'Authorization': `Bearer ${authToken}` })
                },
                body: JSON.stringify({ printerName })
            });

            if (response.ok) {
                preferredPrinter = printerName;
                return true;
            }
        } catch (error) {
            // Error al guardar preferencia
        }
        return false;
    }

    /**
     * Abre un modal interactivo para imprimir con opciones
     */
    function showPrintDialog(defaultJob, onPrint) {
        const modalId = 'riverso-print-modal-' + Date.now();
        const defaultPrinter = preferredPrinter || (availablePrinters.length > 0 ? availablePrinters[0].name : '');
        
        const html = `
<div id="${modalId}" class="riverso-print-modal" style="
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
">
    <div class="riverso-print-modal-content" style="
        background: white;
        border-radius: 8px;
        padding: 24px;
        max-width: 500px;
        width: 90%;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    ">
        <h2 style="margin-top: 0; margin-bottom: 20px; font-size: 18px;">
            📋 Imprimir Etiqueta
        </h2>

        ${!agentHealthy ? `
            <div style="
                background: #fee2e2;
                border: 1px solid #fca5a5;
                color: #991b1b;
                padding: 12px;
                border-radius: 4px;
                margin-bottom: 16px;
                font-size: 13px;
            ">
                ⚠️ <strong>Agente de impresión no disponible</strong><br>
                Asegúrate de que EtiquetadorRS.exe está ejecutándose en este PC.
            </div>
        ` : ''}

        <form id="riverso-print-form" style="display: flex; flex-direction: column; gap: 12px;">
            <div>
                <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">
                    Producto
                </label>
                <input type="text" id="print-nombre" value="${defaultJob?.nombre || ''}" 
                    placeholder="Nombre del producto" readonly style="
                        width: 100%;
                        padding: 8px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        background: #f5f5f5;
                        font-size: 13px;
                    ">
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">
                    SKU
                </label>
                <input type="text" id="print-sku" value="${defaultJob?.sku || ''}" 
                    placeholder="SKU" readonly style="
                        width: 100%;
                        padding: 8px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        background: #f5f5f5;
                        font-size: 13px;
                    ">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">
                        Cantidad EAN
                    </label>
                    <input type="number" id="print-cantidad" value="${defaultJob?.cantidad || 100}" 
                        min="1" max="99999" style="
                            width: 100%;
                            padding: 8px;
                            border: 1px solid #ddd;
                            border-radius: 4px;
                            font-size: 13px;
                        ">
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">
                        Copias
                    </label>
                    <input type="number" id="print-copias" value="${defaultJob?.copias || 1}" 
                        min="1" max="100" style="
                            width: 100%;
                            padding: 8px;
                            border: 1px solid #ddd;
                            border-radius: 4px;
                            font-size: 13px;
                        ">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">
                        Modo
                    </label>
                    <select id="print-modo" style="
                        width: 100%;
                        padding: 8px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        font-size: 13px;
                    ">
                        <option value="Bolsa" ${defaultJob?.modo === 'Bolsa' ? 'selected' : ''}>Bolsa</option>
                        <option value="BolsaCOD" ${defaultJob?.modo === 'BolsaCOD' ? 'selected' : ''}>Bolsa con Código</option>
                        <option value="EtiquetaSimple" ${defaultJob?.modo === 'EtiquetaSimple' ? 'selected' : ''}>Etiqueta Simple</option>
                        <option value="EtiquetaLogo" ${defaultJob?.modo === 'EtiquetaLogo' ? 'selected' : ''}>Etiqueta con Logo</option>
                        <option value="EtiquetaLogoPrecio" ${defaultJob?.modo === 'EtiquetaLogoPrecio' ? 'selected' : ''}>Etiqueta con Precio</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">
                        Color
                    </label>
                    <select id="print-color" style="
                        width: 100%;
                        padding: 8px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        font-size: 13px;
                    ">
                        <option value="BN" ${defaultJob?.color === 'BN' ? 'selected' : ''}>Blanco/Negro</option>
                        <option value="RN" ${defaultJob?.color === 'RN' ? 'selected' : ''}>Rojo/Negro</option>
                    </select>
                </div>
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">
                    Impresora
                </label>
                <select id="print-printer" style="
                    width: 100%;
                    padding: 8px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    font-size: 13px;
                    ${!agentHealthy ? 'opacity: 0.5; pointer-events: none;' : ''}
                ">
                    ${availablePrinters.length === 0 ? `
                        <option>No hay impresoras disponibles</option>
                    ` : availablePrinters.map(p => `
                        <option value="${escapeHtml(p.name)}" ${p.name === defaultPrinter ? 'selected' : ''}>
                            ${escapeHtml(p.name)}${p.isBrother ? ' (Brother)' : ''}${p.isDefault ? ' (por defecto)' : ''}
                        </option>
                    `).join('')}
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 8px;">
                <button type="button" id="print-cancel" style="
                    padding: 10px;
                    background: #f0f0f0;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 13px;
                    font-weight: 500;
                    transition: background 0.2s;
                " onmouseover="this.style.background='#e0e0e0'" onmouseout="this.style.background='#f0f0f0'">
                    Cancelar
                </button>

                <button type="submit" id="print-submit" style="
                    padding: 10px;
                    background: #4CAF50;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 13px;
                    font-weight: 500;
                    transition: background 0.2s;
                    ${!agentHealthy ? 'opacity: 0.5; pointer-events: none;' : ''}
                " ${!agentHealthy ? 'disabled' : ''} onmouseover="this.style.background='#45a049'" onmouseout="this.style.background='#4CAF50'">
                    Imprimir
                </button>
            </div>
        </form>
    </div>
</div>
        `;

        document.body.insertAdjacentHTML('beforeend', html);
        const modal = document.getElementById(modalId);
        const form = modal.querySelector('#riverso-print-form');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const job = {
                nombre: document.getElementById('print-nombre').value,
                sku: document.getElementById('print-sku').value,
                cantidad: parseInt(document.getElementById('print-cantidad').value) || 100,
                precio: defaultJob?.precio || null,
                copias: parseInt(document.getElementById('print-copias').value) || 1,
                modo: document.getElementById('print-modo').value,
                color: document.getElementById('print-color').value,
                ean13: defaultJob?.ean13 || null,
                printerName: document.getElementById('print-printer').value
            };

            try {
                document.getElementById('print-submit').disabled = true;
                document.getElementById('print-submit').textContent = 'Imprimiendo...';

                const result = await print([job]);

                if (result.ok) {
                    // Guardar impresora preferida
                    await selectPrinter(job.printerName);

                    alert(`✅ Etiqueta impresa correctamente (${result.printed} etiquetas)`);
                    modal.remove();

                    if (typeof onPrint === 'function') {
                        onPrint(result);
                    }
                } else {
                    const errorMsg = result.errors && result.errors.length > 0 
                        ? result.errors[0] 
                        : 'Error desconocido';
                    alert(`❌ Error: ${errorMsg}`);
                    document.getElementById('print-submit').disabled = false;
                    document.getElementById('print-submit').textContent = 'Imprimir';
                }
            } catch (error) {
                alert(`❌ Error: ${error.message}`);
                document.getElementById('print-submit').disabled = false;
                document.getElementById('print-submit').textContent = 'Imprimir';
            }
        });

        document.getElementById('print-cancel').addEventListener('click', () => {
            modal.remove();
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.remove();
            }
        });
    }

    /**
     * Escapa caracteres HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    return {
        init,
        checkAgent,
        isHealthy,
        getPrinters,
        getPreferred,
        print,
        selectPrinter,
        loadPrinters,
        showPrintDialog
    };
})();

// Auto-inicializar cuando WordPress localiza la config
(function () {
    function boot() {
        if (typeof RiversoLabelPrint === 'undefined') {
            return;
        }
        if (typeof riverso_label_print_config !== 'undefined') {
            RiversoLabelPrint.init(
                riverso_label_print_config.agentUrl,
                riverso_label_print_config.authToken
            );
        } else if (typeof riverso_pos !== 'undefined' && riverso_pos.label_print) {
            RiversoLabelPrint.init(
                riverso_pos.label_print.agentUrl,
                riverso_pos.label_print.authToken
            );
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
