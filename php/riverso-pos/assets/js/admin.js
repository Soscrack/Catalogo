/**
 * Riverso POS - Admin JavaScript
 */

(function($) {
    'use strict';
    
    // Objeto principal
    window.RiversoPOS = {
        
        /**
         * Inicialización
         */
        init: function() {
            this.bindEvents();
            this.initUploadAreas();
        },
        
        /**
         * Bindea eventos globales
         */
        bindEvents: function() {
            // Confirmación de eliminación
            $(document).on('click', '.riverso-confirm-delete', function(e) {
                if (!confirm(riversoPOS.i18n.confirm_delete)) {
                    e.preventDefault();
                }
            });
            
            // Cerrar modales
            $(document).on('click', '.riverso-modal-close, .riverso-modal-overlay', function(e) {
                if (e.target === this) {
                    RiversoPOS.closeModal();
                }
            });
            
            // ESC para cerrar modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    RiversoPOS.closeModal();
                }
            });
        },
        
        /**
         * Inicializa áreas de upload
         */
        initUploadAreas: function() {
            var $areas = $('.riverso-upload-area');
            
            $areas.each(function() {
                var $area = $(this);
                var $input = $area.find('input[type="file"]');
                
                // Click para abrir selector
                $area.on('click', function() {
                    $input.trigger('click');
                });
                
                // Drag and drop
                $area.on('dragover dragenter', function(e) {
                    e.preventDefault();
                    $area.addClass('dragover');
                });
                
                $area.on('dragleave dragend drop', function(e) {
                    e.preventDefault();
                    $area.removeClass('dragover');
                });
                
                $area.on('drop', function(e) {
                    var files = e.originalEvent.dataTransfer.files;
                    if (files.length) {
                        $input[0].files = files;
                        $input.trigger('change');
                    }
                });
            });
        },
        
        /**
         * Realiza petición AJAX
         */
        ajax: function(action, data, callback) {
            data = data || {};
            data.action = action;
            data.nonce = riversoPOS.nonce;
            
            return $.ajax({
                url: riversoPOS.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (callback) {
                        callback(response.success, response.data);
                    }
                },
                error: function() {
                    if (callback) {
                        callback(false, {message: riversoPOS.i18n.error});
                    }
                }
            });
        },
        
        /**
         * Muestra un modal
         */
        showModal: function(content, title) {
            var html = '<div class="riverso-modal-overlay">' +
                '<div class="riverso-modal">' +
                    '<div class="riverso-modal-header">' +
                        '<h3>' + (title || '') + '</h3>' +
                        '<button class="riverso-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="riverso-modal-body">' + content + '</div>' +
                '</div>' +
            '</div>';
            
            $('body').append(html);
        },
        
        /**
         * Cierra el modal
         */
        closeModal: function() {
            $('.riverso-modal-overlay').remove();
        },
        
        /**
         * Muestra notificación
         */
        notify: function(message, type) {
            type = type || 'success';
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
            $('.riverso-pos-wrap h1').after($notice);
            
            // Auto dismiss
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },
        
        /**
         * Formatea moneda CLP
         */
        formatCLP: function(amount) {
            return '$' + parseInt(amount).toLocaleString('es-CL');
        },
        
        /**
         * Busca productos WooCommerce
         */
        searchProducts: function(query, callback) {
            this.ajax('riverso_search_products', {search: query}, callback);
        },
        
        /**
         * Actualiza estadísticas del dashboard
         */
        refreshStats: function() {
            this.ajax('riverso_get_stats', {}, function(success, data) {
                if (success) {
                    // Actualizar números en el dashboard
                    $.each(data, function(key, value) {
                        $('[data-stat="' + key + '"]').text(value);
                    });
                }
            });
        }
    };
    
    // Inicializar cuando el DOM esté listo
    $(document).ready(function() {
        RiversoPOS.init();
    });
    
})(jQuery);
