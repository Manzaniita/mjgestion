<?php
session_start();
require_once 'config.php';
requireRole(['admin', 'supervisor', 'reventa']); // Todos pueden ver, pero con diferentes permisos
include 'header.php';

$puedeEditar = hasRole('admin');
$puedeAgregar = hasRole('admin');

// Procesar acciones (eliminar, duplicar) - SOLO ADMIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeEditar) {
    if (isset($_POST['eliminar_producto'])) {
        try {
            $producto_id = $_POST['producto_id'];
            $conn->beginTransaction();
            $stmt = $conn->prepare("DELETE FROM producto_datos WHERE producto_id = ?");
            $stmt->execute([$producto_id]);
            $stmt = $conn->prepare("DELETE FROM productos WHERE id = ?");
            $stmt->execute([$producto_id]);
            $conn->commit();
            $mensaje = "Producto eliminado correctamente";
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Error al eliminar producto: " . $e->getMessage();
        }
    }
    elseif (isset($_POST['duplicar_producto'])) {
        try {
            $producto_id_original = $_POST['producto_id'];
            $conn->beginTransaction();
            // Obtener datos del producto original, incluyendo nombre_visualizacion
            $stmt = $conn->prepare("SELECT categoria_id, nombre, nombre_visualizacion FROM productos WHERE id = ?");
            $stmt->execute([$producto_id_original]);
            $producto_original = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$producto_original) {
                throw new Exception("Producto original no encontrado para duplicar.");
            }

            $nombre_duplicado_interno = $producto_original['nombre'] . " (copia)";
            $nombre_duplicado_visualizacion = !empty($producto_original['nombre_visualizacion'])
                ? $producto_original['nombre_visualizacion'] . " (copia)"
                : $nombre_duplicado_interno;

            $stmt = $conn->prepare("INSERT INTO productos (categoria_id, nombre, nombre_visualizacion) VALUES (?, ?, ?)");
            $stmt->execute([
                $producto_original['categoria_id'],
                $nombre_duplicado_interno,
                $nombre_duplicado_visualizacion
            ]);
            $nuevo_id = $conn->lastInsertId();

            $stmt = $conn->prepare("SELECT campo_nombre, valor FROM producto_datos WHERE producto_id = ?");
            $stmt->execute([$producto_id_original]);
            $campos_originales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($campos_originales as $campo) {
                $stmt = $conn->prepare("INSERT INTO producto_datos (producto_id, campo_nombre, valor) VALUES (?, ?, ?)");
                $stmt->execute([$nuevo_id, $campo['campo_nombre'], $campo['valor']]);
            }

            $conn->commit();
            $mensaje = "Producto duplicado correctamente (Nuevo ID: $nuevo_id)";
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Error al duplicar producto: " . $e->getMessage();
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Obtener todos los productos con sus datos, agrupados por categoría y nombre
$query = "
    SELECT 
        p.id,
        p.nombre AS producto_nombre_interno,
        p.nombre_visualizacion,
        c.nombre AS categoria_nombre,
        c.id AS categoria_id,
        GROUP_CONCAT(
            CONCAT(pd.campo_nombre, '::', pd.valor) 
            SEPARATOR '||'
        ) AS datos_personalizados,
        p.created_at
    FROM productos p
    JOIN categorias c ON p.categoria_id = c.id
    LEFT JOIN producto_datos pd ON p.id = pd.producto_id
    GROUP BY p.id, p.nombre, p.nombre_visualizacion, c.nombre, c.id, p.created_at
    ORDER BY c.nombre ASC, p.nombre ASC, p.created_at DESC
";

$productos_raw = $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

$productos_por_categoria = [];
foreach ($productos_raw as $producto) {
    $categoria_nombre = $producto['categoria_nombre'];
    $nombre_base_agrupacion = $producto['producto_nombre_interno'];
    $nombre_a_mostrar = !empty($producto['nombre_visualizacion']) ? $producto['nombre_visualizacion'] : $producto['producto_nombre_interno'];

    $datos_para_variante = [
        'Categoría' => [
            'valor' => $producto['categoria_nombre'],
            'categoria_id' => $producto['categoria_id']
        ]
    ];

    if (!empty($producto['datos_personalizados'])) {
        $campos = explode('||', $producto['datos_personalizados']);
        foreach ($campos as $campo_str) {
            if (!empty($campo_str)) {
                list($nombre_campo, $valor) = explode('::', $campo_str, 2);
                $datos_para_variante[$nombre_campo] = ['valor' => $valor];
            }
        }
    }

    if (!isset($productos_por_categoria[$categoria_nombre])) {
        $productos_por_categoria[$categoria_nombre] = [
            'categoria_id' => $producto['categoria_id'],
            'productos' => []
        ];
    }

    if (!isset($productos_por_categoria[$categoria_nombre]['productos'][$nombre_base_agrupacion])) {
        $productos_por_categoria[$categoria_nombre]['productos'][$nombre_base_agrupacion] = [
            'nombre_para_mostrar_grupo' => $nombre_a_mostrar,
            'nombre_interno_grupo' => $producto['producto_nombre_interno'],
            'variantes' => []
        ];
    }

    if (empty($productos_por_categoria[$categoria_nombre]['productos'][$nombre_base_agrupacion]['nombre_para_mostrar_grupo'])) {
        $productos_por_categoria[$categoria_nombre]['productos'][$nombre_base_agrupacion]['nombre_para_mostrar_grupo'] = $nombre_a_mostrar;
    }

    $productos_por_categoria[$categoria_nombre]['productos'][$nombre_base_agrupacion]['variantes'][] = [
        'id' => $producto['id'],
        'datos' => $datos_para_variante,
        'fecha_creacion' => $producto['created_at'],
        'nombre_visualizacion_variante' => $producto['nombre_visualizacion'],
        'nombre_interno_variante' => $producto['producto_nombre_interno']
    ];
}

$categorias_db = $conn->query("SELECT * FROM categorias ORDER BY nombre")->fetchAll();
$campos_personalizados_db = $conn->query("SELECT * FROM campos_personalizados ORDER BY nombre")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Productos por Categoría</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/productos.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="productos-page">
    <div class="container">
        <h1><i class="fas fa-boxes-stacked"></i> Gestión de Productos</h1>
        
        <?php if (isset($mensaje)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if (empty($productos_por_categoria)): ?>
            <div class="empty-state">
                <i class="fas fa-box-open fa-3x" style="color: #bdc3c7; margin-bottom: 20px;"></i>
                <p>No hay productos registrados aún.</p>
                <?php if ($puedeAgregar): ?>
                <a href="mj_alta_productos.php" class="btn btn-success">
                    <i class="fas fa-plus"></i> Agregar Primer Producto
                </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($productos_por_categoria as $categoria_nombre_display => $categoria_data_display): ?>
                <div class="categoria-section">
                    <div class="categoria-header">
                        <span>
                            <i class="fas fa-folder-open"></i> <?= htmlspecialchars($categoria_nombre_display) ?>
                        </span>
                        <span>
                            <?= count($categoria_data_display['productos']) ?> tipo<?= count($categoria_data_display['productos']) !== 1 ? 's' : '' ?> de producto
                        </span>
                    </div>
                    
                    <div class="productos-grid-container">
                        <?php foreach ($categoria_data_display['productos'] as $nombre_grupo_producto => $producto_data_display): ?>
                            <div class="producto-card">
                                <div class="producto-header">
                                    <h3>
                                        <i class="fas fa-cube"></i> 
                                        <?= htmlspecialchars($producto_data_display['nombre_para_mostrar_grupo']) ?>
                                        <?php if ($producto_data_display['nombre_para_mostrar_grupo'] !== $producto_data_display['nombre_interno_grupo']): ?>
                                            <small class="nombre-interno-ref">(Interno: <?= htmlspecialchars($producto_data_display['nombre_interno_grupo']) ?>)</small>
                                        <?php endif; ?>
                                    </h3>
                                    <span class="variantes-count">
                                        <?= count($producto_data_display['variantes']) ?> variante<?= count($producto_data_display['variantes']) > 1 ? 's' : '' ?>
                                    </span>
                                </div>
                                
                                <?php foreach ($producto_data_display['variantes'] as $variante_display): ?>
                                    <div class="variante">
                                        <div class="variante-header">
                                            <span class="variante-id">
                                                <i class="fas fa-barcode"></i> ID: <?= $variante_display['id'] ?>
                                            </span>
                                            <?php 
                                            $nombre_visual_variante = !empty($variante_display['nombre_visualizacion_variante']) 
                                                                    ? $variante_display['nombre_visualizacion_variante'] 
                                                                    : $variante_display['nombre_interno_variante'];
                                            if ($nombre_visual_variante !== $producto_data_display['nombre_para_mostrar_grupo'] && $nombre_visual_variante !== $variante_display['nombre_interno_variante'] ) : ?>
                                            <span class="nombre-visual-variante">
                                                 <i class="fas fa-eye"></i> <?= htmlspecialchars($nombre_visual_variante) ?>
                                            </span>
                                            <?php endif; ?>
                                            <span class="fecha-creacion">
                                                <i class="far fa-calendar-check"></i> <?= date('d/m/Y H:i', strtotime($variante_display['fecha_creacion'])) ?>
                                            </span>
                                        </div>
                                        <div class="datos-grid">
                                            <?php foreach ($variante_display['datos'] as $label => $dato): ?>
                                                <?php if ($label !== 'Categoría'): ?>
                                                    <div class="dato-item">
                                                        <div class="dato-label">
                                                            <i class="fas fa-info-circle"></i> <?= htmlspecialchars($label) ?>
                                                        </div>
                                                        <div class="dato-valor">
                                                            <?= htmlspecialchars($dato['valor']) ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            
                                            <?php if ($puedeEditar): ?>
                                            <div class="acciones-container">
                                                <button class="btn btn-edit" onclick="openEditModal(<?= $variante_display['id'] ?>, '<?= htmlspecialchars($variante_display['nombre_interno_variante'], ENT_QUOTES) ?>', '<?= htmlspecialchars($variante_display['nombre_visualizacion_variante'] ?? '', ENT_QUOTES) ?>')">
                                                    <i class="fas fa-pencil-alt"></i> Editar
                                                </button>
                                                <form method="POST" class="acciones-form">
                                                    <input type="hidden" name="producto_id" value="<?= $variante_display['id'] ?>">
                                                    <button type="submit" name="duplicar_producto" class="btn btn-duplicate">
                                                        <i class="fas fa-clone"></i> Duplicar
                                                    </button>
                                                </form>
                                                <form method="POST" class="acciones-form" onsubmit="return confirm('¿Estás seguro de eliminar este producto (ID: <?= $variante_display['id'] ?>)? Esta acción no se puede deshacer.');">
                                                    <input type="hidden" name="producto_id" value="<?= $variante_display['id'] ?>">
                                                    <button type="submit" name="eliminar_producto" class="btn btn-delete">
                                                        <i class="fas fa-trash-alt"></i> Eliminar
                                                    </button>
                                                </form>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if ($puedeAgregar): ?>
            <div class="acciones-footer">
                <a href="mj_alta_productos.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Agregar Nuevo Producto
                </a>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($puedeEditar): ?>
        <!-- Modal de Edición -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <div class="modal-header-bar">
                    <h2><i class="fas fa-edit"></i> Editar Producto</h2>
                    <span class="close-modal-btn" onclick="closeEditModal()">×</span>
                </div>
                <div class="modal-body">
                    <form id="editForm" method="POST" action="actualizar_producto.php">
                        <input type="hidden" id="edit_producto_id" name="producto_id">
                        <div class="form-group">
                            <label for="edit_nombre_interno"><i class="fas fa-signature"></i> Nombre Interno:</label>
                            <input type="text" id="edit_nombre_interno" name="nombre_interno" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_nombre_visualizacion"><i class="fas fa-eye"></i> Nombre de Visualización:</label>
                            <input type="text" id="edit_nombre_visualizacion" name="nombre_visualizacion" class="form-input">
                            <small>Si se deja vacío, se usará el nombre interno para visualización.</small>
                        </div>
                        <div class="form-group">
                            <label for="edit_categoria_id"><i class="fas fa-folder-open"></i> Categoría:</label>
                            <select id="edit_categoria_id" name="categoria_id" class="form-input" required>
                                <?php foreach ($categorias_db as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <h4><i class="fas fa-cogs"></i> Campos Personalizados</h4>
                        <div id="campos-personalizados-container" class="campos-dinamicos-modal">
                            <!-- Los campos personalizados se cargarán aquí dinámicamente -->
                        </div>
                        <!-- Los botones de acción están ahora en modal-footer-bar -->
                    </form>
                </div>
                <div class="modal-footer-bar">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancelar</button>
                    <button type="submit" form="editForm" class="btn btn-success">
                        <i class="fas fa-save"></i> Guardar Cambios
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    <?php if ($puedeEditar): ?>
    function openEditModal(productoId, nombreInterno, nombreVisualizacion) {
        document.getElementById('edit_producto_id').value = productoId;
        document.getElementById('edit_nombre_interno').value = nombreInterno;
        document.getElementById('edit_nombre_visualizacion').value = nombreVisualizacion || '';
        
        const modal = document.getElementById('editModal');
        const container = document.getElementById('campos-personalizados-container');
        container.innerHTML = '<p>Cargando campos...</p>';

        fetch('obtener_producto.php?id=' + productoId)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error en la respuesta de la red: ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    alert('Error: ' + data.error);
                    container.innerHTML = `<p class="text-danger">${data.error}</p>`;
                    return;
                }
                document.getElementById('edit_categoria_id').value = data.categoria_id;
                
                container.innerHTML = '';
                const todosLosCamposDefinidos = <?php echo json_encode($campos_personalizados_db); ?>;

                todosLosCamposDefinidos.forEach(campoDef => {
                    const div = document.createElement('div');
                    div.className = 'form-group';
                    
                    const label = document.createElement('label');
                    label.htmlFor = `edit_campo_${campoDef.nombre.replace(/\s+/g, '_')}`;
                    label.innerHTML = `<i class="fas fa-info-circle"></i> ${campoDef.nombre} <span class="field-type">(${campoDef.tipo})</span>`;
                    
                    let input;
                    const valorActual = data.campos_personalizados[campoDef.nombre] !== undefined ? data.campos_personalizados[campoDef.nombre] : '';

                    if (campoDef.tipo === 'select') {
                        input = document.createElement('select');
                        input.className = 'form-input';
                        const emptyOpt = document.createElement('option');
                        emptyOpt.value = "";
                        emptyOpt.text = "-- Seleccione --";
                        input.appendChild(emptyOpt);
                        if (campoDef.opciones) {
                            const opcionesArray = campoDef.opciones.split(',');
                            opcionesArray.forEach(opt => {
                                const optionEl = document.createElement('option');
                                optionEl.value = opt.trim();
                                optionEl.text = opt.trim();
                                if (opt.trim() === valorActual) {
                                    optionEl.selected = true;
                                }
                                input.appendChild(optionEl);
                            });
                        }
                    } else if (campoDef.tipo === 'color') {
                        input = document.createElement('input');
                        input.type = 'color';
                        input.value = valorActual || '#ffffff';
                        input.className = 'form-input-color';
                    } else if (campoDef.tipo === 'fecha') {
                        input = document.createElement('input');
                        input.type = 'date';
                        input.value = valorActual;
                        input.className = 'form-input';
                    } else if (campoDef.tipo === 'numero') {
                        input = document.createElement('input');
                        input.type = 'number';
                        input.value = valorActual;
                        input.className = 'form-input';
                        input.step = 'any';
                    }
                    else {
                        input = document.createElement('input');
                        input.type = 'text';
                        input.value = valorActual;
                        input.className = 'form-input';
                    }
                    
                    input.id = `edit_campo_${campoDef.nombre.replace(/\s+/g, '_')}`;
                    input.name = `campos[${campoDef.nombre}]`;
                    
                    div.appendChild(label);
                    div.appendChild(input);
                    container.appendChild(div);
                });
                
                modal.style.display = 'flex';
            })
            .catch(error => {
                console.error('Error al cargar datos para edición:', error);
                alert('Error al cargar los datos del producto para editar. Ver consola para detalles.');
                container.innerHTML = `<p class="text-danger">No se pudieron cargar los campos personalizados: ${error.message}</p>`;
            });
    }
    
    function closeEditModal() {
        const modal = document.getElementById('editModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('editModal');
        if (event.target == modal) {
            closeEditModal();
        }
    }
    <?php endif; ?>
    </script>
</body>
</html>