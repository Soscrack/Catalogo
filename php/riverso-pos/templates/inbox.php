<?php
/**
 * Bandeja unificada correo / WhatsApp.
 */

if (!defined('ABSPATH')) {
    exit;
}

$nonce = wp_create_nonce('riverso_pos_nonce');
$ajax = admin_url('admin-ajax.php');
$tipos = [
    'proveedor' => 'Proveedor',
    'cliente' => 'Cliente',
    'trabajo' => 'Trabajo',
    'spam' => 'Spam',
    'otro' => 'Otro',
];
$quotes_url = admin_url('admin.php?page=riverso-pos-received-quotes');
?>
<div class="wrap riverso-pos-wrap riverso-inbox-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-email-alt"></span>
        Bandeja correo / WhatsApp
    </h1>
    <button type="button" class="page-title-action" id="inbox-sync">Sincronizar ahora</button>
    <button type="button" class="page-title-action" id="inbox-gmail-connect">Conectar Gmail</button>
    <hr class="wp-header-end">

    <div id="inbox-status" class="notice notice-info" style="padding:8px 12px;"><em>Cargando estado de canales…</em></div>

    <div class="inbox-layout">
        <aside class="inbox-list-pane">
            <nav class="inbox-folders" id="inbox-folders">
                <button type="button" class="inbox-folder active" data-folder="inbox">Recibidos</button>
                <button type="button" class="inbox-folder" data-folder="important">Importante</button>
                <button type="button" class="inbox-folder" data-folder="spam">Spam</button>
                <button type="button" class="inbox-folder" data-folder="quotes">Cotizaciones</button>
            </nav>
            <div class="inbox-filters">
                <input type="search" id="inbox-buscar" placeholder="Buscar…">
                <select id="inbox-canal">
                    <option value="">Todos los canales</option>
                    <option value="email">Correo</option>
                    <option value="whatsapp">WhatsApp</option>
                </select>
                <select id="inbox-tipo">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($tipos as $k => $l): ?>
                        <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($l); ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Desde <input type="date" id="inbox-fecha-desde"></label>
                <label>Hasta <input type="date" id="inbox-fecha-hasta"></label>
                <label><input type="checkbox" id="inbox-unread"> No leídos</label>
                <button type="button" class="button" id="inbox-filtrar">Filtrar</button>
                <p class="description" style="margin:0;">Con fechas, Filtrar limita la lista; Sincronizar pide ese rango a Gmail.</p>
            </div>
            <ul id="inbox-threads"></ul>
        </aside>
        <section class="inbox-thread-pane">
            <div id="inbox-empty" class="inbox-empty">Selecciona un hilo</div>
            <div id="inbox-thread" style="display:none;">
                <header class="inbox-thread-head">
                    <div>
                        <h2 id="inbox-thread-title">—</h2>
                        <div id="inbox-thread-meta" class="description"></div>
                    </div>
                    <div class="inbox-thread-actions">
                        <?php foreach ($tipos as $k => $l): ?>
                            <button type="button" class="button inbox-tipo-btn" data-tipo="<?php echo esc_attr($k); ?>"><?php echo esc_html($l); ?></button>
                        <?php endforeach; ?>
                        <button type="button" class="button button-primary" id="inbox-reviewed">Marcar revisado</button>
                    </div>
                </header>
                <div id="inbox-quotes"></div>
                <div id="inbox-messages"></div>
                <form id="inbox-reply-form">
                    <textarea id="inbox-reply-body" rows="4" placeholder="Responder desde esta página (correo o WhatsApp)…"></textarea>
                    <button type="submit" class="button button-primary">Enviar</button>
                </form>
            </div>
        </section>
    </div>

    <details class="inbox-wa-help" style="margin-top:20px;">
        <summary><strong>Guía WhatsApp Cloud API</strong></summary>
        <ol id="inbox-wa-steps"></ol>
        <p>Webhook: <code id="inbox-wa-webhook"></code></p>
    </details>
</div>
<style>
.inbox-layout { display:grid; grid-template-columns: 340px 1fr; gap:16px; min-height:70vh; }
.inbox-list-pane { background:#fff; border:1px solid #c3c4c7; border-radius:6px; overflow:auto; }
.inbox-folders { display:flex; flex-wrap:wrap; gap:4px; padding:8px 10px; border-bottom:1px solid #eee; }
.inbox-folder { border:1px solid #c3c4c7; background:#f6f7f7; border-radius:4px; padding:4px 8px; cursor:pointer; font-size:12px; }
.inbox-folder.active { background:#2271b1; color:#fff; border-color:#2271b1; }
.inbox-filters { padding:10px; display:flex; flex-direction:column; gap:6px; border-bottom:1px solid #eee; }
.inbox-thread-pane { background:#fff; border:1px solid #c3c4c7; border-radius:6px; display:flex; flex-direction:column; }
#inbox-threads { list-style:none; margin:0; padding:0; }
#inbox-threads li { padding:10px 12px; border-bottom:1px solid #eee; cursor:pointer; }
#inbox-threads li.active { background:#e7f2fb; }
#inbox-threads li.has-quote { border-left:4px solid #dba617; }
.inbox-preview { color:#646970; font-size:12px; }
.inbox-msg { padding:10px 12px; margin:8px 12px; border-radius:8px; max-width:92%; }
.inbox-msg.in { background:#f0f0f1; }
.inbox-msg.out { background:#d5f0d8; margin-left:auto; }
.inbox-msg.has-quote { border-left:4px solid #dba617; }
.inbox-msg-toolbar { display:flex; gap:6px; margin:6px 0; flex-wrap:wrap; }
.inbox-html-frame { width:100%; min-height:160px; border:0; background:#fff; border-radius:4px; }
#inbox-messages { flex:1; overflow:auto; }
.inbox-thread-head { display:flex; justify-content:space-between; gap:12px; padding:12px; border-bottom:1px solid #eee; flex-wrap:wrap; }
#inbox-reply-form { padding:12px; border-top:1px solid #eee; display:flex; flex-direction:column; gap:8px; }
.inbox-empty { padding:40px; text-align:center; color:#646970; }
.tipo-badge { font-size:11px; text-transform:uppercase; background:#f0f0f1; padding:2px 6px; border-radius:8px; }
.quote-badge { font-size:11px; text-transform:uppercase; background:#fcf9e8; color:#8a6d00; padding:2px 6px; border-radius:8px; }
.inbox-msg-text { white-space:pre-wrap; word-break:break-word; }
#inbox-quotes { padding:8px 12px; }
</style>
<script>
jQuery(function($) {
    const ajaxurl = window.ajaxurl || <?php echo wp_json_encode($ajax); ?>;
    const nonce = <?php echo wp_json_encode($nonce); ?>;
    const quotesUrl = <?php echo wp_json_encode($quotes_url); ?>;
    let currentId = 0;
    let currentThread = null;
    let currentFolder = 'inbox';
    const htmlById = {};
    const showImagesById = {};
    const viewModeById = {};

    function post(action, data) {
        return $.post(ajaxurl, Object.assign({ action: action, nonce: nonce }, data || {}));
    }

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function inboxNeedsHumanConfirm(tipoConfirmado) {
        return String(tipoConfirmado) === '0';
    }

    function inboxQuoteBadge(t) {
        if (inboxNeedsHumanConfirm(t.quote_tipo_confirmado)) {
            return 'Por confirmar';
        }
        if (t.quote_tipo_doc === 'posible_cotizacion' || !parseInt(t.quote_count || 0, 10)) {
            return 'Posible';
        }
        return 'Cotización';
    }

    function inboxMessageQuoteBadge(m) {
        if (m.has_quote && inboxNeedsHumanConfirm(m.quote_tipo_confirmado)) {
            return 'Por confirmar';
        }
        if (m.has_quote && m.quote_tipo_doc !== 'posible_cotizacion') {
            return 'Cotización detectada';
        }
        return 'Posible cotización';
    }

    function prepareEmailHtml(html, showImages) {
        let out = String(html || '');
        out = out.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
        out = out.replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi, '');
        out = out.replace(/<object\b[^>]*>[\s\S]*?<\/object>/gi, '');
        out = out.replace(/<embed\b[^>]*>/gi, '');
        out = out.replace(/<base\b[^>]*>/gi, '');
        out = out.replace(/<a\b([^>]*)>/gi, function(full, attrs) {
            if (!/\btarget=/i.test(attrs)) {
                attrs += ' target="_blank"';
            }
            if (!/\brel=/i.test(attrs)) {
                attrs += ' rel="noopener noreferrer"';
            }
            return '<a' + attrs + '>';
        });
        if (!showImages) {
            out = out.replace(/<img\b([^>]*)>/gi, function(full, attrs) {
                attrs = attrs.replace(/\ssrcset\s*=\s*("[^"]*"|'[^']*')/gi, ' data-riverso-srcset=$1');
                attrs = attrs.replace(/\ssrc\s*=\s*("[^"]*"|'[^']*')/gi, ' data-riverso-src=$1 src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"');
                return '<img' + attrs + '>';
            });
            out = out.replace(/url\((['"]?)https?:\/\/[^)]+\)/gi, 'none');
        }
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank"><style>body{margin:8px;font-family:sans-serif;}</style></head><body>' + out + '</body></html>';
    }

    function mountHtmlFrame(container, messageId, showImages) {
        container.empty();
        const html = htmlById[messageId] || '';
        if (!html) {
            return;
        }
        const iframe = document.createElement('iframe');
        iframe.className = 'inbox-html-frame';
        iframe.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox allow-same-origin');
        iframe.setAttribute('referrerpolicy', 'no-referrer');
        iframe.srcdoc = prepareEmailHtml(html, showImages);
        iframe.onload = function() {
            try {
                const doc = iframe.contentDocument;
                const h = Math.min(Math.max((doc.documentElement && doc.documentElement.scrollHeight) || 160, 120), 900);
                iframe.style.height = h + 'px';
            } catch (e) {
                iframe.style.height = '420px';
            }
        };
        container.append(iframe);
    }

    function loadStatus() {
        post('riverso_inbox_status').done(function(r) {
            if (!r.success) return;
            const g = r.data.gmail || {};
            const w = r.data.whatsapp || {};
            let html = 'Gmail: ' + (g.connected ? 'conectado (' + (g.user || '') + ')' : (g.configured ? 'OAuth pendiente' : 'falta CLIENT_ID/SECRET'));
            html += ' · WhatsApp: ' + (w.configured ? 'token OK' : 'pendiente de configuración');
            $('#inbox-status').html(html);
            $('#inbox-wa-webhook').text(w.webhook_url || '');
            const steps = (w.steps || []).map(function(s) { return '<li>' + s + '</li>'; }).join('');
            $('#inbox-wa-steps').html(steps);
        });
    }

    function dateFilters() {
        return {
            fecha_desde: $('#inbox-fecha-desde').val() || '',
            fecha_hasta: $('#inbox-fecha-hasta').val() || ''
        };
    }

    function loadThreads() {
        post('riverso_inbox_list', Object.assign({
            buscar: $('#inbox-buscar').val(),
            canal: $('#inbox-canal').val(),
            tipo_chat: $('#inbox-tipo').val(),
            unread: $('#inbox-unread').is(':checked') ? 1 : 0,
            folder: currentFolder
        }, dateFilters())).done(function(r) {
            const list = (r.data && r.data.threads) || [];
            if (!list.length) {
                $('#inbox-threads').html('<li>Sin conversaciones</li>');
                return;
            }
            let html = '';
            list.forEach(function(t) {
                const quote = t.has_quote || t.looks_like_quote;
                html += '<li data-id="' + t.id + '"' + (quote ? ' class="has-quote"' : '') + '>'
                    + '<div><strong>' + esc(t.contacto_nombre || t.contacto_identificador || '—') + '</strong> '
                    + '<span class="tipo-badge">' + esc(t.tipo_chat) + '</span> '
                    + (quote ? '<span class="quote-badge">' + inboxQuoteBadge(t) + '</span> ' : '')
                    + '<small>' + esc(t.canal) + '</small></div>'
                    + '<div class="inbox-preview">' + esc(t.last_preview || '') + '</div></li>';
            });
            $('#inbox-threads').html(html);
        });
    }

    function loadThread(id) {
        currentId = id;
        post('riverso_inbox_thread', { id: id }).done(function(r) {
            if (!r.success) { alert(r.data.message); return; }
            currentThread = r.data.thread;
            $('#inbox-empty').hide();
            $('#inbox-thread').show();
            $('#inbox-thread-title').text(currentThread.contacto_nombre || currentThread.contacto_identificador);
            let meta = (currentThread.canal || '') + ' · ' + (currentThread.contacto_identificador || '') + ' · tipo ' + (currentThread.tipo_chat || '');
            if (currentThread.has_quote || currentThread.looks_like_quote) {
                meta += ' · cotización';
            }
            $('#inbox-thread-meta').text(meta);
            let qh = '';
            (r.data.quotes || []).forEach(function(q) {
                const tipo = q.tipo_doc === 'posible_cotizacion' ? 'Posible cotización' : 'Cotización';
                const pending = String(q.tipo_confirmado) === '0' ? ' · por confirmar' : '';
                let ver = '';
                if (q.version_n && q.version_count && Number(q.version_count) > 1) {
                    ver = ' · v' + q.version_n + (q.is_version_final ? ' (Versión final)' : '');
                }
                qh += '<a class="button" href="' + quotesUrl + '&quote=' + q.id + '">' + tipo + ' #' + q.id + ver + ' (' + esc(q.estado) + pending + ')</a> ';
            });
            $('#inbox-quotes').html(qh);

            const pane = document.getElementById('inbox-messages');
            pane.innerHTML = '';
            const messages = r.data.messages || [];
            if (!messages.length) {
                pane.innerHTML = '<p class="inbox-empty">Sin mensajes</p>';
                return;
            }
            messages.forEach(function(m) {
                htmlById[m.id] = m.body_html || '';
                if (viewModeById[m.id] == null) {
                    viewModeById[m.id] = htmlById[m.id] ? 'html' : 'text';
                }
                const wrap = document.createElement('div');
                wrap.className = 'inbox-msg ' + (m.direction === 'out' ? 'out' : 'in')
                    + ((m.has_quote || m.looks_like_quote) ? ' has-quote' : '');
                if (m.subject) {
                    const sub = document.createElement('strong');
                    sub.textContent = m.subject;
                    wrap.appendChild(sub);
                    wrap.appendChild(document.createElement('br'));
                }
                if (m.has_quote || m.looks_like_quote) {
                    const badge = document.createElement('span');
                    badge.className = 'quote-badge';
                    badge.textContent = inboxMessageQuoteBadge(m);
                    wrap.appendChild(badge);
                }
                if (htmlById[m.id]) {
                    const bar = document.createElement('div');
                    bar.className = 'inbox-msg-toolbar';
                    const btnHtml = document.createElement('button');
                    btnHtml.type = 'button';
                    btnHtml.className = 'button button-small';
                    btnHtml.textContent = 'HTML';
                    const btnText = document.createElement('button');
                    btnText.type = 'button';
                    btnText.className = 'button button-small';
                    btnText.textContent = 'Texto';
                    const btnImg = document.createElement('button');
                    btnImg.type = 'button';
                    btnImg.className = 'button button-small inbox-show-images';
                    btnImg.textContent = showImagesById[m.id] ? 'Ocultar imágenes' : 'Mostrar imágenes';
                    bar.appendChild(btnHtml);
                    bar.appendChild(btnText);
                    bar.appendChild(btnImg);
                    wrap.appendChild(bar);
                    const htmlBox = document.createElement('div');
                    htmlBox.className = 'inbox-msg-html';
                    const textBox = document.createElement('div');
                    textBox.className = 'inbox-msg-text';
                    textBox.textContent = m.body_text || '';
                    wrap.appendChild(htmlBox);
                    wrap.appendChild(textBox);
                    const setMode = function(mode) {
                        viewModeById[m.id] = mode;
                        if (mode === 'html') {
                            htmlBox.style.display = '';
                            textBox.style.display = 'none';
                            mountHtmlFrame($(htmlBox), m.id, !!showImagesById[m.id]);
                        } else {
                            htmlBox.style.display = 'none';
                            textBox.style.display = '';
                        }
                    };
                    btnHtml.addEventListener('click', function() { setMode('html'); });
                    btnText.addEventListener('click', function() { setMode('text'); });
                    btnImg.addEventListener('click', function() {
                        showImagesById[m.id] = !showImagesById[m.id];
                        btnImg.textContent = showImagesById[m.id] ? 'Ocultar imágenes' : 'Mostrar imágenes';
                        if (viewModeById[m.id] === 'html') {
                            mountHtmlFrame($(htmlBox), m.id, !!showImagesById[m.id]);
                        }
                    });
                    pane.appendChild(wrap);
                    setMode(viewModeById[m.id]);
                } else {
                    const textBox = document.createElement('div');
                    textBox.className = 'inbox-msg-text';
                    textBox.textContent = m.body_text || '';
                    wrap.appendChild(textBox);
                    pane.appendChild(wrap);
                }
                (m.attachments || []).forEach(function(a) {
                    const row = document.createElement('div');
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'button button-small inbox-make-quote';
                    btn.setAttribute('data-msg', m.id);
                    btn.setAttribute('data-att', a.id);
                    btn.textContent = 'Crear cotización: ' + (a.filename || 'archivo');
                    row.appendChild(btn);
                    wrap.appendChild(row);
                });
            });
        });
    }

    $('#inbox-folders').on('click', '.inbox-folder', function() {
        currentFolder = $(this).data('folder');
        $('.inbox-folder').removeClass('active');
        $(this).addClass('active');
        loadThreads();
    });
    $('#inbox-filtrar').on('click', loadThreads);
    $('#inbox-threads').on('click', 'li[data-id]', function() {
        $('#inbox-threads li').removeClass('active');
        $(this).addClass('active');
        loadThread($(this).data('id'));
    });
    $('.inbox-tipo-btn').on('click', function() {
        if (!currentId) return;
        post('riverso_inbox_set_tipo', { id: currentId, tipo_chat: $(this).data('tipo') }).done(function() {
            loadThread(currentId);
            loadThreads();
        });
    });
    $('#inbox-reviewed').on('click', function() {
        if (!currentId) return;
        post('riverso_inbox_mark_reviewed', { id: currentId }).done(loadThreads);
    });
    $('#inbox-reply-form').on('submit', function(e) {
        e.preventDefault();
        if (!currentId) return;
        post('riverso_inbox_reply', { id: currentId, body: $('#inbox-reply-body').val() }).done(function(r) {
            if (!r.success) { alert(r.data && r.data.message ? r.data.message : 'Error'); return; }
            $('#inbox-reply-body').val('');
            loadThread(currentId);
        });
    });
    $('#inbox-sync').on('click', function() {
        post('riverso_inbox_sync', dateFilters()).done(function(r) {
            if (!r.success) { alert(r.data.message); return; }
            loadThreads();
            loadStatus();
        });
    });
    $('#inbox-gmail-connect').on('click', function() {
        post('riverso_inbox_gmail_auth').done(function(r) {
            if (r.success && r.data.url) window.location = r.data.url;
            else alert(r.data && r.data.message ? r.data.message : 'No se pudo iniciar OAuth');
        });
    });
    $(document).on('click', '.inbox-make-quote', function() {
        post('riverso_inbox_create_quote', {
            message_id: $(this).data('msg'),
            attachment_id: $(this).data('att')
        }).done(function(r) {
            if (!r.success) { alert(r.data.message); return; }
            window.location = quotesUrl;
        });
    });

    loadStatus();
    loadThreads();

    const params = new URLSearchParams(window.location.search);
    const openThread = parseInt(params.get('thread') || '0', 10);
    if (openThread > 0) {
        setTimeout(function() { loadThread(openThread); }, 400);
    }
});
</script>
