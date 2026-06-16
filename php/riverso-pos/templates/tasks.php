<?php
/**
 * Template: Lista de Tareas
 */

if (!defined('ABSPATH')) {
    exit;
}

$task_types = Riverso_Task_Module::TASK_TYPES;
$priorities = Riverso_Task_Module::PRIORITIES;
?>

<div class="wrap riverso-tasks">
    <h1>
        <span class="dashicons dashicons-clipboard"></span>
        Gestión de Tareas
        <button type="button" class="page-title-action" id="btn-new-task">
            <span class="dashicons dashicons-plus-alt"></span> Nueva Tarea
        </button>
    </h1>

    <!-- Tabs -->
    <div class="nav-tab-wrapper">
        <a href="#" class="nav-tab nav-tab-active" data-tab="todas">Todas</a>
        <a href="#" class="nav-tab" data-tab="mis-tareas">Mis Tareas</a>
        <a href="#" class="nav-tab" data-tab="sin-asignar">Sin Asignar</a>
        <a href="#" class="nav-tab" data-tab="completadas">Completadas</a>
    </div>

    <!-- Filtros -->
    <div class="riverso-filters">
        <select id="filter-tipo">
            <option value="">Todos los tipos</option>
            <?php foreach ($task_types as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        
        <select id="filter-prioridad">
            <option value="">Todas las prioridades</option>
            <?php foreach ($priorities as $key => $p): ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($p['label']); ?></option>
            <?php endforeach; ?>
        </select>
        
        <button type="button" class="button" id="btn-refresh">
            <span class="dashicons dashicons-update"></span> Actualizar
        </button>
    </div>

    <!-- Lista de tareas -->
    <div class="tasks-container">
        <div class="tasks-list" id="tasks-list">
            <div class="loading-tasks" style="text-align: center; padding: 60px;">
                <span class="spinner is-active" style="float: none;"></span>
                <p>Cargando tareas...</p>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Nueva/Editar Tarea -->
<div id="modal-task" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content">
        <div class="riverso-modal-header">
            <h2 id="modal-task-title">Nueva Tarea</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <form id="form-task">
                <input type="hidden" id="task-id" name="task_id" value="">
                
                <div class="form-field">
                    <label for="task-tipo">Tipo *</label>
                    <select id="task-tipo" name="tipo" required>
                        <option value="">Seleccionar...</option>
                        <?php foreach ($task_types as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-field">
                    <label for="task-titulo">Título *</label>
                    <input type="text" id="task-titulo" name="titulo" required maxlength="255">
                </div>
                
                <div class="form-field">
                    <label for="task-descripcion">Descripción</label>
                    <textarea id="task-descripcion" name="descripcion" rows="4"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-field">
                        <label for="task-prioridad">Prioridad</label>
                        <select id="task-prioridad" name="prioridad">
                            <?php foreach ($priorities as $key => $p): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php echo $key === 'normal' ? 'selected' : ''; ?>>
                                    <?php echo esc_html($p['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-field">
                        <label for="task-fecha-limite">Fecha Límite</label>
                        <input type="date" id="task-fecha-limite" name="fecha_limite">
                    </div>
                </div>
                
                <div class="form-field">
                    <label for="task-asignado">Asignar a</label>
                    <select id="task-asignado" name="asignado_a">
                        <option value="">Sin asignar</option>
                        <?php
                        $users = get_users([
                            'role__in' => ['administrator', 'riverso_editor', 'riverso_vendedor'],
                        ]);
                        foreach ($users as $user) {
                            echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->display_name) . '</option>';
                        }
                        ?>
                    </select>
                </div>
            </form>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-task">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-save-task">
                <span class="dashicons dashicons-saved"></span> Guardar
            </button>
        </div>
    </div>
</div>

<!-- Modal: Completar Tarea -->
<div id="modal-complete" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 400px;">
        <div class="riverso-modal-header">
            <h2>Completar Tarea</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <p id="complete-task-title" style="font-weight: 600;"></p>
            <div class="form-field">
                <label for="complete-notas">Notas (opcional)</label>
                <textarea id="complete-notas" rows="3" placeholder="Observaciones sobre la tarea completada..."></textarea>
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-complete">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-confirm-complete">
                <span class="dashicons dashicons-yes"></span> Completar
            </button>
        </div>
    </div>
</div>

<style>
.riverso-tasks .nav-tab-wrapper {
    margin-bottom: 0;
}

.riverso-tasks .riverso-filters {
    display: flex;
    gap: 10px;
    padding: 15px;
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-top: none;
}

.tasks-container {
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    min-height: 400px;
}

.tasks-list {
    display: flex;
    flex-direction: column;
}

.task-card {
    display: flex;
    padding: 15px;
    border-bottom: 1px solid #eee;
    transition: background 0.2s;
}

.task-card:hover {
    background: #fafafa;
}

.task-priority-indicator {
    width: 4px;
    border-radius: 2px;
    margin-right: 15px;
}

.task-priority-urgente { background: #f44336; }
.task-priority-alta { background: #ff9800; }
.task-priority-normal { background: #2196f3; }
.task-priority-baja { background: #9e9e9e; }

.task-content {
    flex: 1;
}

.task-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.task-title {
    font-weight: 600;
    font-size: 14px;
    margin: 0;
}

.task-type {
    font-size: 11px;
    color: #666;
    background: #f0f0f0;
    padding: 2px 6px;
    border-radius: 3px;
}

.task-description {
    font-size: 13px;
    color: #555;
    margin-bottom: 10px;
    white-space: pre-line;
}

.task-meta {
    display: flex;
    gap: 15px;
    font-size: 12px;
    color: #888;
}

.task-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.task-actions {
    display: flex;
    gap: 5px;
    margin-left: 15px;
}

.task-actions button {
    padding: 5px 8px;
}

.form-field {
    margin-bottom: 15px;
}

.form-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
}

.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
}

.form-row {
    display: flex;
    gap: 15px;
}

.form-row .form-field {
    flex: 1;
}

.empty-tasks {
    text-align: center;
    padding: 60px;
    color: #666;
}

.empty-tasks .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    margin-bottom: 10px;
}
</style>

<script>
jQuery(function($) {
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    let currentTab = 'todas';
    let taskTypes = <?php echo wp_json_encode($task_types); ?>;
    
    // Cargar tareas
    function loadTasks() {
        const filters = {
            action: 'riverso_get_tasks',
            nonce: nonce,
            tipo: $('#filter-tipo').val(),
            prioridad: $('#filter-prioridad').val()
        };
        
        if (currentTab === 'mis-tareas') {
            filters.asignado_a = <?php echo get_current_user_id(); ?>;
        } else if (currentTab === 'sin-asignar') {
            filters.sin_asignar = 1;
        } else if (currentTab === 'completadas') {
            filters.estado = 'completada';
        }
        // Tab "todas" no filtra por estado - muestra todas las no completadas
        
        $('#tasks-list').html('<div class="loading-tasks" style="text-align: center; padding: 60px;"><span class="spinner is-active" style="float: none;"></span><p>Cargando tareas...</p></div>');
        
        $.post(ajaxurl, filters, function(response) {
            if (response.success) {
                renderTasks(response.data.tasks);
            } else {
                $('#tasks-list').html('<div class="empty-tasks"><span class="dashicons dashicons-warning"></span><p>Error al cargar tareas: ' + (response.data?.message || 'Error desconocido') + '</p></div>');
            }
        }).fail(function() {
            $('#tasks-list').html('<div class="empty-tasks"><span class="dashicons dashicons-warning"></span><p>Error de conexión</p></div>');
        });
    }
    
    function renderTasks(tasks) {
        const container = $('#tasks-list');
        container.empty();
        
        if (!tasks.length) {
            container.html(`
                <div class="empty-tasks">
                    <span class="dashicons dashicons-clipboard"></span>
                    <p>No hay tareas</p>
                </div>
            `);
            return;
        }
        
        tasks.forEach(function(task) {
            const card = $(`
                <div class="task-card" data-id="${task.id}">
                    <div class="task-priority-indicator task-priority-${task.prioridad}"></div>
                    <div class="task-content">
                        <div class="task-header">
                            <h4 class="task-title">${escapeHtml(task.titulo)}</h4>
                            <span class="task-type">${taskTypes[task.tipo] || task.tipo}</span>
                        </div>
                        ${task.descripcion ? `<div class="task-description">${escapeHtml(task.descripcion.substring(0, 200))}${task.descripcion.length > 200 ? '...' : ''}</div>` : ''}
                        <div class="task-meta">
                            <span><span class="dashicons dashicons-calendar-alt"></span> ${task.created_at.split(' ')[0]}</span>
                            ${task.asignado_nombre ? `<span><span class="dashicons dashicons-admin-users"></span> ${task.asignado_nombre}</span>` : ''}
                            ${task.fecha_limite ? `<span><span class="dashicons dashicons-clock"></span> Límite: ${task.fecha_limite}</span>` : ''}
                        </div>
                    </div>
                    <div class="task-actions">
                        ${task.target_url ? `
                            <a href="${escapeHtml(task.target_url)}" class="button button-small btn-goto-task" title="Ir a la tarea">
                                <span class="dashicons dashicons-arrow-right"></span>
                            </a>
                        ` : ''}
                        ${task.estado !== 'completada' ? `
                            <button class="button button-small btn-complete-task" title="Completar">
                                <span class="dashicons dashicons-yes"></span>
                            </button>
                        ` : ''}
                        <button class="button button-small btn-edit-task" title="Editar">
                            <span class="dashicons dashicons-edit"></span>
                        </button>
                    </div>
                </div>
            `);
            container.append(card);
        });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Tabs
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        currentTab = $(this).data('tab');
        loadTasks();
    });
    
    // Filtros
    $('#btn-refresh').on('click', loadTasks);
    $('#filter-tipo, #filter-prioridad').on('change', loadTasks);
    
    // Nueva tarea
    $('#btn-new-task').on('click', function() {
        $('#form-task')[0].reset();
        $('#task-id').val('');
        $('#modal-task-title').text('Nueva Tarea');
        $('#modal-task').show();
    });
    
    // Cerrar modales
    $('.riverso-modal-close, #btn-cancel-task, #btn-cancel-complete').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });
    
    // Guardar tarea
    $('#btn-save-task').on('click', function() {
        const form = $('#form-task');
        const taskId = $('#task-id').val();
        
        const data = {
            action: taskId ? 'riverso_update_task' : 'riverso_create_task',
            nonce: nonce,
            task_id: taskId,
            tipo: $('#task-tipo').val(),
            titulo: $('#task-titulo').val(),
            descripcion: $('#task-descripcion').val(),
            prioridad: $('#task-prioridad').val(),
            fecha_limite: $('#task-fecha-limite').val(),
            asignado_a: $('#task-asignado').val()
        };
        
        if (!data.tipo || !data.titulo) {
            alert('Tipo y título son requeridos');
            return;
        }
        
        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                $('#modal-task').hide();
                loadTasks();
            } else {
                alert(response.data.message);
            }
        });
    });
    
    // Editar tarea - cargar datos del servidor
    $(document).on('click', '.btn-edit-task', function() {
        const card = $(this).closest('.task-card');
        const taskId = card.data('id');
        
        // Cargar datos de la tarea desde el servidor
        $.post(ajaxurl, {
            action: 'riverso_get_task',
            nonce: nonce,
            task_id: taskId
        }, function(response) {
            if (response.success && response.data.task) {
                const task = response.data.task;
                $('#task-id').val(task.id);
                $('#task-tipo').val(task.tipo);
                $('#task-titulo').val(task.titulo);
                $('#task-descripcion').val(task.descripcion || '');
                $('#task-prioridad').val(task.prioridad);
                $('#task-fecha-limite').val(task.fecha_limite || '');
                $('#task-asignado').val(task.asignado_a || '');
                $('#modal-task-title').text('Editar Tarea');
                $('#modal-task').show();
            } else {
                alert('Error al cargar la tarea');
            }
        }).fail(function() {
            alert('Error de conexión');
        });
    });
    
    // Completar tarea
    let completingTaskId = null;
    
    $(document).on('click', '.btn-complete-task', function() {
        const card = $(this).closest('.task-card');
        completingTaskId = card.data('id');
        const title = card.find('.task-title').text();
        
        $('#complete-task-title').text(title);
        $('#complete-notas').val('');
        $('#modal-complete').show();
    });
    
    $('#btn-confirm-complete').on('click', function() {
        if (!completingTaskId) return;
        
        $.post(ajaxurl, {
            action: 'riverso_complete_task',
            nonce: nonce,
            task_id: completingTaskId,
            notas: $('#complete-notas').val()
        }, function(response) {
            if (response.success) {
                $('#modal-complete').hide();
                loadTasks();
            } else {
                alert(response.data.message);
            }
        });
    });
    
    // Cargar al inicio
    loadTasks();
});
</script>
