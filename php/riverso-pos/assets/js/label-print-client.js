/**
 * RiversoLabelPrint - Cliente JavaScript para impresión de etiquetas desde el navegador
 * Se comunica con el agente local PrintAgentHost ejecutándose en http://127.0.0.1:19284/
 */

const RiversoLabelPrint = (function () {
    const DEFAULT_AGENT_URL = 'http://127.0.0.1:19284';
    let agentUrl = DEFAULT_AGENT_URL;
    let authToken = '';
    let agentHealthy = false;

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
            return agentHealthy;
        } catch (error) {
            agentHealthy = false;
            return false;
        }
    }

    /**
     * Retorna el estado del agente
     */
    function isHealthy() {
        return agentHealthy;
    }

    /**
     * Imprime un lote de trabajos
     * @param {Array} jobs - Array de trabajos: { nombre, sku, cantidad, precio, copias, modo, color, ean13 }
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
                ean13: job.ean13 || null
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
     * Abre un modal interactivo para imprimir con opciones
     */
    function showPrintDialog(defaultJob, onPrint) {
        const modalId = 'riverso-print-modal-' + Date.now();
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
                    <input type="number" id="print-cantidad" value="${defaultJob?.cantidad || 100}" min="1" max="99999" style="
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
                    <input type="number" id="print-copias" value="${defaultJob?.copias || 1}" min="1" max="100" style="
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
                        Tipo de Etiqueta
                    </label>
                    <select id="print-modo" style="
                        width: 100%;
                        padding: 8px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        font-size: 13px;
                    ">
                        <option value="BolsaCOD" ${defaultJob?.modo === 'BolsaCOD' ? 'selected' : ''}>Bolsa COD</option>
                        <option value="Bolsa" ${defaultJob?.modo === 'Bolsa' ? 'selected' : ''}>Bolsa</option>
                        <option value="EtiquetaSimple" ${defaultJob?.modo === 'EtiquetaSimple' ? 'selected' : ''}>Simple</option>
                        <option value="EtiquetaLogo" ${defaultJob?.modo === 'EtiquetaLogo' ? 'selected' : ''}>Logo</option>
                        <option value="EtiquetaLogoPrecio" ${defaultJob?.modo === 'EtiquetaLogoPrecio' ? 'selected' : ''}>Logo + Precio</option>
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

            <div style="display: flex; gap: 8px; margin-top: 16px;">
                <button type="button" id="print-cancel" style="
                    flex: 1;
                    padding: 10px;
                    border: 1px solid #ddd;
                    background: #fff;
                    border-radius: 4px;
                    font-size: 13px;
                    font-weight: 500;
                    cursor: pointer;
                    transition: all 0.2s;
                ">
                    Cancelar
                </button>
                <button type="submit" id="print-submit" style="
                    flex: 1;
                    padding: 10px;
                    border: none;
                    background: ${agentHealthy ? '#0EA5E9' : '#999'};
                    color: white;
                    border-radius: 4px;
                    font-size: 13px;
                    font-weight: 500;
                    cursor: ${agentHealthy ? 'pointer' : 'not-allowed'};
                    transition: all 0.2s;
                " ${agentHealthy ? '' : 'disabled'}>
                    🖨️ Imprimir
                </button>
            </div>
        </form>
    </div>
</div>
        `;

        const container = document.createElement('div');
        container.innerHTML = html;
        document.body.appendChild(container);

        const modal = document.getElementById(modalId);
        const form = document.getElementById('riverso-print-form');
        const submitBtn = document.getElementById('print-submit');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (!agentHealthy) {
                alert('El agente de impresión no está disponible');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = '⏳ Imprimiendo...';

            try {
                const job = {
                    nombre: document.getElementById('print-nombre').value,
                    sku: document.getElementById('print-sku').value,
                    cantidad: parseInt(document.getElementById('print-cantidad').value) || 100,
                    copias: parseInt(document.getElementById('print-copias').value) || 1,
                    modo: document.getElementById('print-modo').value,
                    color: document.getElementById('print-color').value,
                    precio: defaultJob?.precio || null
                };

                const result = await print([job]);

                alert(`✅ ${result.printed} etiqueta(s) impresa(s) correctamente`);
                modal.remove();

                if (onPrint) {
                    onPrint(result);
                }
            } catch (error) {
                alert(`❌ Error: ${error.message}`);
                submitBtn.disabled = false;
                submitBtn.textContent = '🖨️ Imprimir';
            }
        });

        document.getElementById('print-cancel').addEventListener('click', () => {
            modal.remove();
        });
    }

    return {
        init,
        checkAgent,
        isHealthy,
        print,
        showPrintDialog,
        setAgentUrl: (url) => { agentUrl = url; },
        setAuthToken: (token) => { authToken = token; },
        getAgentUrl: () => agentUrl
    };
})();

// Auto-inicializar si está en el contexto de WordPress
if (typeof wp !== 'undefined' && wp.hooks) {
    // Pasar URL y token desde PHP si están disponibles
    if (typeof riversoLabelPrintConfig !== 'undefined') {
        RiversoLabelPrint.init(
            riversoLabelPrintConfig.agentUrl,
            riversoLabelPrintConfig.authToken
        );
    }
}
