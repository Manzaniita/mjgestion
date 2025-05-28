<?php
session_start();
require_once 'config.php';
requireRole('admin'); // Solo administradores pueden editar ventas
include 'header.php';

$mensaje = '';
$error = '';
$venta_id = $_GET['id'] ?? null;
$venta = null;
$detalles_venta = [];
$pagos_venta = [];

if (!$venta_id || !is_numeric($venta_id)) {
    header("Location: mj_registro_tienda.php?mensaje=ID de venta no válido.&tipo_mensaje=error");
    exit;
}

// Obtener datos de la venta para el formulario
try {
    $stmt_venta = $conn->prepare("
        SELECT vm.*, c.nombre as cliente_nombre, c.red_social as cliente_red_social, 
               u.id as usuario_registrador_id
        FROM ventas_maestro vm
        JOIN clientes c ON vm.cliente_id = c.id
        LEFT JOIN usuarios u ON vm.usuario_id = u.id
        WHERE vm.id = ?
    ");
    $stmt_venta->execute([$venta_id]);
    $venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        header("Location: mj_registro_tienda.php?mensaje=Venta no encontrada.&tipo_mensaje=error");
        exit;
    }

    // Obtener detalles de productos
    $stmt_detalles = $conn->prepare("
        SELECT vd.*, p.nombre as producto_nombre_interno, p.nombre_visualizacion
        FROM ventas_detalle vd
        JOIN productos p ON vd.producto_id = p.id
        WHERE vd.venta_id = ?
    ");
    $stmt_detalles->execute([$venta_id]);
    $detalles_venta_raw = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
    foreach($detalles_venta_raw as $d_raw) {
        $d_raw['nombre_display_producto'] = !empty(trim($d_raw['nombre_visualizacion']))
            ? $d_raw['nombre_visualizacion']
            : $d_raw['producto_nombre_interno'];
        $detalles_venta[] = $d_raw;
    }

    // Obtener pagos
    $stmt_pagos = $conn->prepare("SELECT * FROM ventas_pagos WHERE venta_id = ?");
    $stmt_pagos->execute([$venta_id]);
    $pagos_venta = $stmt_pagos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Error al cargar datos de la venta: " . $e->getMessage();
}

// Procesar el formulario de actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_venta'])) {
    if (!$venta || $venta['es_cancelada']) {
        $error = "La venta está cancelada o no se pudo cargar y no puede ser modificada.";
    } else {
        $cliente_id = $_POST['cliente_id'];
        $usuario_id_venta = $_POST['venta_usuario_id'] ?: null;
        $estado_pago_nuevo = $_POST['estado_pago_venta'];
        $estado_envio_nuevo = $_POST['estado_envio_venta'];
        $fecha_venta = $_POST['fecha_venta'];

        // Productos
        $productos_post = $_POST['productos'] ?? [];
        $pagos_post = $_POST['pagos'] ?? [];
        
        $nuevo_total_venta = 0;
        foreach ($productos_post as $prod_data) {
            if (isset($prod_data['cantidad'], $prod_data['precio_unitario']) && is_numeric($prod_data['cantidad']) && is_numeric($prod_data['precio_unitario'])) {
                $nuevo_total_venta += (float)$prod_data['cantidad'] * (float)$prod_data['precio_unitario'];
            }
        }

        if (empty($cliente_id) || empty($productos_post)) {
            $error = "El cliente y al menos un producto son obligatorios.";
        } else {
            try {
                $conn->beginTransaction();
                $stmt_update_maestro = $conn->prepare("
                    UPDATE ventas_maestro 
                    SET cliente_id = ?, usuario_id = ?, fecha_venta = ?, total = ?,
                        estado_pago = ?, estado_envio = ?
                    WHERE id = ? AND es_cancelada = FALSE
                ");
                $stmt_update_maestro->execute([
                    $cliente_id, $usuario_id_venta, $fecha_venta, $nuevo_total_venta, 
                    $estado_pago_nuevo, $estado_envio_nuevo, 
                    $venta_id
                ]);
                if ($stmt_update_maestro->rowCount() > 0) {
                    // 2. Actualizar ventas_detalle (borrar existentes y reinsertar)
                    // ¡¡¡ADVERTENCIA DE STOCK!!! Este paso NO actualiza el stock.
                    $stmt_delete_detalles = $conn->prepare("DELETE FROM ventas_detalle WHERE venta_id = ?");
                    $stmt_delete_detalles->execute([$venta_id]);

                    $stmt_insert_detalle = $conn->prepare("
                        INSERT INTO ventas_detalle (venta_id, producto_id, cantidad, precio_unitario) 
                        VALUES (?, ?, ?, ?)
                    ");
                    foreach ($productos_post as $prod_data) {
                        if (empty($prod_data['producto_id']) || !isset($prod_data['cantidad']) || !isset($prod_data['precio_unitario']) ||
                            !is_numeric($prod_data['cantidad']) || !is_numeric($prod_data['precio_unitario']) || $prod_data['cantidad'] <= 0) {
                            continue; // Saltar producto inválido
                        }
                        $stmt_insert_detalle->execute([
                            $venta_id,
                            $prod_data['producto_id'],
                            $prod_data['cantidad'],
                            $prod_data['precio_unitario']
                        ]);
                    }
                    
                    // 3. Actualizar ventas_pagos (borrar existentes y reinsertar)
                    $stmt_delete_pagos = $conn->prepare("DELETE FROM ventas_pagos WHERE venta_id = ?");
                    $stmt_delete_pagos->execute([$venta_id]);

                    $stmt_insert_pago = $conn->prepare("
                        INSERT INTO ventas_pagos (venta_id, metodo_pago, monto, referencia) 
                        VALUES (?, ?, ?, ?)
                    ");
                    foreach ($pagos_post as $pago_data) {
                        if (empty($pago_data['metodo']) || !isset($pago_data['monto']) || !is_numeric($pago_data['monto']) || $pago_data['monto'] <= 0) {
                            continue; // Saltar pago inválido
                        }
                        $stmt_insert_pago->execute([
                            $venta_id,
                            $pago_data['metodo'],
                            $pago_data['monto'],
                            $pago_data['referencia'] ?? $pago_data['referencia_general'] ?? null
                        ]);
                    }

                    $conn->commit();
                    $mensaje = "Venta ID: $venta_id actualizada.";
                    // Recargar datos para reflejar cambios
                    $stmt_venta->execute([$venta_id]);
                    $venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);
                    $stmt_detalles->execute([$venta_id]);
                    $detalles_venta_raw = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
                    $detalles_venta = []; // Limpiar antes de rellenar
                    foreach($detalles_venta_raw as $d_raw) {
                        $d_raw['nombre_display_producto'] = !empty(trim($d_raw['nombre_visualizacion']))
                            ? $d_raw['nombre_visualizacion']
                            : $d_raw['producto_nombre_interno'];
                        $detalles_venta[] = $d_raw;
                    }
                    $stmt_pagos->execute([$venta_id]);
                    $pagos_venta = $stmt_pagos->fetchAll(PDO::FETCH_ASSOC);

                } else {
                    $conn->rollBack();
                    $error = "No se pudo actualizar la venta. Puede que haya sido cancelada mientras tanto.";
                }
            } catch (PDOException $e) {
                $conn->rollBack();
                $error = "Error al actualizar la venta: " . $e->getMessage();
            }
        }
    }
}

// Obtener listas para selectores
$clientes_db = $conn->query("SELECT id, nombre, red_social FROM clientes ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$usuarios_db = $conn->query("SELECT id, username, nombre_completo FROM usuarios WHERE activo = TRUE ORDER BY nombre_completo ASC")->fetchAll(PDO::FETCH_ASSOC);
$productos_db = $conn->query("
    SELECT p.id, p.nombre AS producto_nombre_interno, p.nombre_visualizacion, c.nombre as categoria_nombre 
    FROM productos p JOIN categorias c ON p.categoria_id = c.id 
    ORDER BY c.nombre, p.nombre
")->fetchAll(PDO::FETCH_ASSOC);
foreach($productos_db as &$prod_db_item){
    $prod_db_item['nombre_display'] = !empty(trim($prod_db_item['nombre_visualizacion']))
        ? $prod_db_item['nombre_visualizacion']
        : $prod_db_item['producto_nombre_interno'];
}
unset($prod_db_item);

$estados_venta_definidos = [
    ESTADO_VENTA_PENDIENTE_PAGO => getNombreEstadoVenta(ESTADO_VENTA_PENDIENTE_PAGO),
    ESTADO_VENTA_PAGADA => getNombreEstadoVenta(ESTADO_VENTA_PAGADA),
    ESTADO_VENTA_CANCELADA => getNombreEstadoVenta(ESTADO_VENTA_CANCELADA),
    ESTADO_VENTA_ENVIADA => getNombreEstadoVenta(ESTADO_VENTA_ENVIADA),
    ESTADO_VENTA_COMPLETADA => getNombreEstadoVenta(ESTADO_VENTA_COMPLETADA),
    ESTADO_VENTA_SOLICITUD_REVENTA => getNombreEstadoVenta(ESTADO_VENTA_SOLICITUD_REVENTA),
    ESTADO_VENTA_SOLICITUD_RECHAZADA => getNombreEstadoVenta(ESTADO_VENTA_SOLICITUD_RECHAZADA),
];
$estados_pago_definidos = [
    ESTADO_PAGO_FALTA_PAGAR => getNombreEstadoPago(ESTADO_PAGO_FALTA_PAGAR),
    ESTADO_PAGO_PAGADO => getNombreEstadoPago(ESTADO_PAGO_PAGADO),
    ESTADO_PAGO_A_PAGAR => getNombreEstadoPago(ESTADO_PAGO_A_PAGAR),
];
$estados_envio_definidos = [
    ESTADO_ENVIO_PENDIENTE => getNombreEstadoEnvio(ESTADO_ENVIO_PENDIENTE),
    ESTADO_ENVIO_ENTREGADO => getNombreEstadoEnvio(ESTADO_ENVIO_ENTREGADO),
    ESTADO_ENVIO_RETIRA_PUNTO_ENCUENTRO => getNombreEstadoEnvio(ESTADO_ENVIO_RETIRA_PUNTO_ENCUENTRO),
    ESTADO_ENVIO_EN_CAMINO => getNombreEstadoEnvio(ESTADO_ENVIO_EN_CAMINO),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Venta | MJ Gestión</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .container { max-width: 900px; margin: 20px auto; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-section { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        .form-section:last-child { border-bottom: none; margin-bottom:0; padding-bottom: 0;}
        .form-section h3 { color: #3498db; margin-bottom: 15px; font-size: 1.3em; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;
        }
        .btn { padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.95em; }
        .btn-primary { background-color: #28a745; color: white; }
        .btn-primary:hover { background-color: #218838; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-secondary:hover { background-color: #5a6268; }
        .btn-danger-sm { background-color: #dc3545; color: white; padding: 5px 8px; font-size:0.8em; }
        .btn-info-sm { background-color: #17a2b8; color: white; padding: 5px 8px; font-size:0.8em; }

        .item-row { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; padding:10px; background-color:#f9f9f9; border-radius:4px;}
        .item-row .form-group { margin-bottom: 0; flex:1; }
        .item-row input[type="number"] { width: 80px; }
        .item-row select { min-width: 150px; }
        .acciones-fila { flex-shrink: 0; }

        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .total-final-venta { font-size: 1.2em; font-weight: bold; text-align: right; margin-top:15px; padding-top:15px; border-top:1px solid #eee;}
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-edit"></i> Editar Venta ID: <?= htmlspecialchars($venta_id) ?></h1>
        <?php if ($mensaje): ?><div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if ($venta): ?>
            <?php if ($venta['es_cancelada']): ?>
                <div class="alert alert-error"><strong>¡Atención!</strong> Esta venta está marcada como CANCELADA. La mayoría de los campos no se pueden editar. Para reactivarla, use la opción en el Registro de Ventas.</div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="actualizar_venta" value="1">
                <div class="form-section">
                    <h3><i class="fas fa-user-tag"></i> Datos Generales de la Venta</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="cliente_id">Cliente:</label>
                            <select id="cliente_id" name="cliente_id" required <?= $venta['es_cancelada'] ? 'disabled' : '' ?>>
                                <?php foreach ($clientes_db as $cli): ?>
                                    <option value="<?= $cli['id'] ?>" <?= $venta['cliente_id'] == $cli['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cli['nombre']) ?> (<?= htmlspecialchars($cli['red_social']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="venta_usuario_id">Venta registrada por:</label>
                            <select id="venta_usuario_id" name="venta_usuario_id" <?= $venta['es_cancelada'] ? 'disabled' : '' ?>>
                                <option value="">-- Sistema (Automático) --</option>
                                <?php foreach ($usuarios_db as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= $venta['usuario_registrador_id'] == $u['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['nombre_completo'] ?: $u['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fecha_venta">Fecha de Venta:</label>
                            <input type="datetime-local" id="fecha_venta" name="fecha_venta" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($venta['fecha_venta']))) ?>" required <?= $venta['es_cancelada'] ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-group">
                            <label for="estado_pago_venta">Estado de Pago:</label>
                            <select id="estado_pago_venta" name="estado_pago_venta" required <?= $venta['es_cancelada'] ? 'disabled' : '' ?>>
                                <?php foreach ($estados_pago_definidos as $id_ep => $nombre_ep): ?>
                                    <option value="<?= $id_ep ?>" <?= $venta['estado_pago'] == $id_ep ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($nombre_ep) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="estado_envio_venta">Estado de Envío:</label>
                            <select id="estado_envio_venta" name="estado_envio_venta" required <?= $venta['es_cancelada'] ? 'disabled' : '' ?>>
                                <?php foreach ($estados_envio_definidos as $id_ee => $nombre_ee): ?>
                                    <option value="<?= $id_ee ?>" <?= $venta['estado_envio'] == $id_ee ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($nombre_ee) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-section">
                    <h3><i class="fas fa-boxes"></i> Productos Vendidos</h3>
                    <?php if ($venta['es_cancelada']): ?>
                        <p class="alert alert-info">Los productos no se pueden modificar en una venta cancelada.</p>
                    <?php endif; ?>
                    <div id="productos-container">
                        <?php foreach ($detalles_venta as $index => $detalle): ?>
                        <div class="item-row producto-item">
                            <input type="hidden" name="productos[<?= $index ?>][detalle_id]" value="<?= $detalle['id'] ?>">
                            <div class="form-group">
                                <label>Producto:</label>
                                <select name="productos[<?= $index ?>][producto_id]" class="producto-select" required <?= $venta['es_cancelada'] ? 'disabled' : '' ?>>
                                    <?php foreach ($productos_db as $prod_opt): ?>
                                        <option value="<?= $prod_opt['id'] ?>" <?= $detalle['producto_id'] == $prod_opt['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($prod_opt['nombre_display']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Cantidad:</label>
                                <input type="number" name="productos[<?= $index ?>][cantidad]" class="cantidad-input" value="<?= htmlspecialchars($detalle['cantidad']) ?>" min="1" required onchange="actualizarTotalVenta()" <?= $venta['es_cancelada'] ? 'disabled' : '' ?>>
                            </div>
                            <div class="form-group">
                                <label>Precio Unitario (Cobrado):</label>
                                <input type="number" step="0.01" name="productos[<?= $index ?>][precio_unitario]" class="precio-input" value="<?= htmlspecialchars($detalle['precio_unitario']) ?>" min="0" required onchange="actualizarTotalVenta()" <?= $venta['es_cancelada'] ? 'disabled' : '' ?>>
                            </div>
                            <div class="acciones-fila">
                                <?php if (!$venta['es_cancelada']): ?>
                                <button type="button" class="btn btn-danger-sm btn-remove-item" onclick="this.closest('.producto-item').remove(); actualizarTotalVenta();"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$venta['es_cancelada']): ?>
                    <button type="button" id="btn-agregar-producto" class="btn btn-info-sm"><i class="fas fa-plus"></i> Agregar Producto</button>
                    <?php endif; ?>
                    <div class="total-final-venta">Total Venta Calculado: ARS <span id="total-venta-display"><?= number_format($venta['total'], 2) ?></span></div>
                </div>
                <div class="form-section">
                     <h3><i class="fas fa-money-check-alt"></i> Pagos Realizados</h3>
                     <?php if ($venta['es_cancelada']): ?>
                        <p class="alert alert-info">Los pagos no se pueden modificar en una venta cancelada.</p>
                    <?php endif; ?>
                    <div id="pagos-container">
                        <?php foreach ($pagos_venta as $index_pago => $pago): ?>
                        <div class="item-row pago-item">
                            <input type="hidden" name="pagos[<?= $index_pago ?>][pago_id]" value="<?= $pago['id'] ?>">
                            <div class="form-group">
                                <label>Método:</label>
                                <select name="pagos[<?= $index_pago ?>][metodo]" class="metodo-pago-select" required <?= $venta['es_cancelada'] ? 'disabled' : '' ?>>
                                    <?php foreach ($metodos_pago_definidos as $val_metodo => $nombre_metodo): ?>
                                    <option value="<?= $val_metodo ?>" <?= $pago['metodo_pago'] == $val_metodo ? 'selected' : '' ?>><?= htmlspecialchars($nombre_metodo) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                             <div class="form-group pago-personalizado-container" style="<?= $pago['metodo_pago'] == 'otro' ? '' : 'display:none;' ?>">
                                <label>Especificar Otro:</label>
                                <input type="text" name="pagos[<?= $index_pago ?>][referencia]" class="pago-referencia" value="<?= $pago['metodo_pago'] == 'otro' ? htmlspecialchars($pago['referencia']) : '' ?>" placeholder="Especifique método">
                            </div>
                            <div class="form-group">
                                <label>Monto ARS:</label>
                                <input type="number" step="0.01" name="pagos[<?= $index_pago ?>][monto]" class="monto-pago-input" value="<?= htmlspecialchars($pago['monto']) ?>" min="0.01" required>
                            </div>
                            <div class="form-group pago-referencia-container" style="<?= $pago['metodo_pago'] != 'otro' ? '' : 'display:none;' ?>">
                                <label>Referencia (Opcional):</label>
                                <input type="text" name="pagos[<?= $index_pago ?>][referencia_general]" class="pago-referencia-general" value="<?= $pago['metodo_pago'] != 'otro' ? htmlspecialchars($pago['referencia']) : '' ?>" placeholder="Ej: N° Op, CBU">
                            </div>
                            <div class="acciones-fila">
                                <?php if (!$venta['es_cancelada']): ?>
                                <button type="button" class="btn btn-danger-sm btn-remove-item" onclick="this.closest('.pago-item').remove();"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$venta['es_cancelada']): ?>
                    <button type="button" id="btn-agregar-pago" class="btn btn-info-sm"><i class="fas fa-plus"></i> Agregar Método de Pago</button>
                    <?php endif; ?>
                </div>
                <div class="form-group text-center" style="margin-top:30px;">
                    <?php if (!$venta['es_cancelada']): ?>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>
                    <?php endif; ?>
                    <a href="mj_registro_tienda.php?venta_id=<?= $venta_id ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Volver al Registro</a>
                </div>
            </form>
        <?php else: ?>
            <p>No se pudo cargar la información de la venta.</p>
        <?php endif; ?>
    </div>
<script>
let productoIndex = <?= count($detalles_venta) ?>;
let pagoIndex = <?= count($pagos_venta) ?>;

const productosDisponibles = <?= json_encode($productos_db) ?>;
const metodosPagoDisponibles = <?= json_encode($metodos_pago_definidos) ?>;

function actualizarTotalVenta() {
    let total = 0;
    document.querySelectorAll('#productos-container .producto-item').forEach(itemRow => {
        const cantidad = parseFloat(itemRow.querySelector('.cantidad-input').value) || 0;
        const precio = parseFloat(itemRow.querySelector('.precio-input').value) || 0;
        total += cantidad * precio;
    });
    document.getElementById('total-venta-display').textContent = total.toFixed(2);
}

document.addEventListener('DOMContentLoaded', function() {
    // --- Lógica para Productos ---
    const productosContainer = document.getElementById('productos-container');
    document.getElementById('btn-agregar-producto').addEventListener('click', function() {
        productoIndex++;
        const newProductoRow = document.createElement('div');
        newProductoRow.classList.add('item-row', 'producto-item');
        
        let optionsHtml = '';
        productosDisponibles.forEach(p => {
            optionsHtml += `<option value="${p.id}">${p.nombre_display.replace(/'/g, "'").replace(/"/g, "")}</option>`;
        });

        newProductoRow.innerHTML = `
            <div class="form-group">
                <label>Producto:</label>
                <select name="productos[${productoIndex}][producto_id]" class="producto-select" required>${optionsHtml}</select>
            </div>
            <div class="form-group">
                <label>Cantidad:</label>
                <input type="number" name="productos[${productoIndex}][cantidad]" class="cantidad-input" value="1" min="1" required onchange="actualizarTotalVenta()">
            </div>
            <div class="form-group">
                <label>Precio Unitario (Cobrado):</label>
                <input type="number" step="0.01" name="productos[${productoIndex}][precio_unitario]" class="precio-input" value="0.00" min="0" required onchange="actualizarTotalVenta()">
            </div>
            <div class="acciones-fila">
                <button type="button" class="btn btn-danger-sm btn-remove-item"><i class="fas fa-trash"></i></button>
            </div>
        `;
        productosContainer.appendChild(newProductoRow);
        newProductoRow.querySelector('.btn-remove-item').addEventListener('click', function() {
            this.closest('.producto-item').remove();
            actualizarTotalVenta();
        });
    });

    // Event listener para los botones de eliminar productos ya existentes
    document.querySelectorAll('#productos-container .btn-remove-item').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.producto-item').remove();
            actualizarTotalVenta();
        });
    });
    // Event listener para cambios en cantidad/precio de productos ya existentes
    document.querySelectorAll('#productos-container .cantidad-input, #productos-container .precio-input').forEach(input => {
        input.addEventListener('change', actualizarTotalVenta);
    });

    // --- Lógica para Pagos ---
    const pagosContainer = document.getElementById('pagos-container');
    document.getElementById('btn-agregar-pago').addEventListener('click', function() {
        pagoIndex++;
        const newPagoRow = document.createElement('div');
        newPagoRow.classList.add('item-row', 'pago-item');

        let metodosOptionsHtml = '';
        for (const [val, name] of Object.entries(metodosPagoDisponibles)) {
            metodosOptionsHtml += `<option value="${val}">${name.replace(/'/g, "'").replace(/"/g, "")}</option>`;
        }

        newPagoRow.innerHTML = `
            <div class="form-group">
                <label>Método:</label>
                <select name="pagos[${pagoIndex}][metodo]" class="metodo-pago-select" required>${metodosOptionsHtml}</select>
            </div>
            <div class="form-group pago-personalizado-container" style="display:none;">
                <label>Especificar Otro:</label>
                <input type="text" name="pagos[${pagoIndex}][referencia]" class="pago-referencia" placeholder="Especifique método">
            </div>
            <div class="form-group">
                <label>Monto ARS:</label>
                <input type="number" step="0.01" name="pagos[${pagoIndex}][monto]" class="monto-pago-input" value="0.00" min="0.01" required>
            </div>
            <div class="form-group pago-referencia-container">
                <label>Referencia (Opcional):</label>
                <input type="text" name="pagos[${pagoIndex}][referencia_general]" class="pago-referencia-general" placeholder="Ej: N° Op, CBU">
            </div>
            <div class="acciones-fila">
                <button type="button" class="btn btn-danger-sm btn-remove-item"><i class="fas fa-trash"></i></button>
            </div>
        `;
        pagosContainer.appendChild(newPagoRow);
        newPagoRow.querySelector('.btn-remove-item').addEventListener('click', function() {
            this.closest('.pago-item').remove();
        });
        // Add event listener for 'otro' method selection on newly added payment row
        newPagoRow.querySelector('.metodo-pago-select').addEventListener('change', handleMetodoPagoChange);
    });

    // Event listener para los botones de eliminar pagos ya existentes
    document.querySelectorAll('#pagos-container .btn-remove-item').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.pago-item').remove();
        });
    });
    // Add event listener for 'otro' method selection on existing payment rows
    document.querySelectorAll('#pagos-container .metodo-pago-select').forEach(select => {
        select.addEventListener('change', handleMetodoPagoChange);
        // Trigger change on load to set initial state
        handleMetodoPagoChange.call(select); 
    });
});

function handleMetodoPagoChange() {
    const row = this.closest('.item-row');
    const personalizadoContainer = row.querySelector('.pago-personalizado-container');
    const referenciaContainer = row.querySelector('.pago-referencia-container');
    const personalizadoInput = row.querySelector('.pago-referencia');
    const referenciaGeneralInput = row.querySelector('.pago-referencia-general');

    if (this.value === 'otro') {
        if(personalizadoContainer) personalizadoContainer.style.display = 'block';
        if(referenciaContainer) referenciaContainer.style.display = 'none';
        if(personalizadoInput) personalizadoInput.required = true;
        if(referenciaGeneralInput) referenciaGeneralInput.required = false;
    } else {
        if(personalizadoContainer) personalizadoContainer.style.display = 'none';
        if(referenciaContainer) referenciaContainer.style.display = 'block';
        if(personalizadoInput) personalizadoInput.required = false;
    }
}
// Llamada inicial para asegurar que el total se muestre correctamente al cargar
actualizarTotalVenta();
</script>
</body>
</html>
