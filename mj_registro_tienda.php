<?php
session_start();
require_once 'config.php';
requireRole(['admin', 'supervisor']); // Admin y Supervisor pueden ver el registro de ventas
include 'header.php';

$puedeEditarEliminar = hasRole('admin'); // Solo Admin puede editar/eliminar ventas
$puedeGestionarSolicitudes = hasRole(['admin', 'supervisor']);
$mensaje = $_GET['mensaje'] ?? null;
$tipo_mensaje = $_GET['tipo_mensaje'] ?? 'exito';

// Procesar acciones de Confirmar/Rechazar Solicitud
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionarSolicitudes) {
    // Aprobar solicitud de reventa
    if (isset($_POST['aprobar_solicitud_reventa'])) {
        $venta_id_aprobar = $_POST['venta_id'];
        try {
            $stmt = $conn->prepare("UPDATE ventas_maestro 
                                    SET es_solicitud_reventa = FALSE, 
                                        estado_pago = :estado_pago_defecto 
                                    WHERE id = :venta_id 
                                      AND es_solicitud_reventa = TRUE 
                                      AND es_cancelada = FALSE");
            $stmt->bindValue(':estado_pago_defecto', ESTADO_PAGO_FALTA_PAGAR, PDO::PARAM_INT);
            $stmt->bindValue(':venta_id', $venta_id_aprobar, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $mensaje = "Solicitud de reventa ID: $venta_id_aprobar aprobada.";
                $tipo_mensaje = 'exito';
            } else {
                $mensaje = "No se pudo aprobar la solicitud ID: $venta_id_aprobar.";
                $tipo_mensaje = 'error';
            }
        } catch (PDOException $e) {
            $mensaje = "Error al aprobar solicitud: " . $e->getMessage();
            $tipo_mensaje = 'error';
        }
        $venta_resaltada_id = $venta_id_aprobar;

    // Rechazar solicitud de reventa
    } elseif (isset($_POST['rechazar_solicitud_reventa'])) {
        $venta_id_rechazar_sr = $_POST['venta_id'];
        try {
            $stmt_detalles = $conn->prepare("SELECT producto_id, cantidad FROM ventas_detalle WHERE venta_id = ?");
            $stmt_detalles->execute([$venta_id_rechazar_sr]);
            $detalles_para_revertir = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

            $conn->beginTransaction();
            foreach ($detalles_para_revertir as $detalle) {
                $stmt_stock = $conn->prepare("UPDATE productos SET stock_disponible = stock_disponible + ? WHERE id = ?");
                $stmt_stock->execute([$detalle['cantidad'], $detalle['producto_id']]);
            }

            $stmt = $conn->prepare("UPDATE ventas_maestro 
                                    SET es_cancelada = TRUE, 
                                        es_solicitud_reventa = FALSE
                                    WHERE id = :venta_id 
                                      AND es_solicitud_reventa = TRUE 
                                      AND es_cancelada = FALSE");
            $stmt->bindValue(':venta_id', $venta_id_rechazar_sr, PDO::PARAM_INT);
            $stmt->execute();
            $conn->commit();

            if ($stmt->rowCount() > 0) {
                $mensaje = "Solicitud de reventa ID: $venta_id_rechazar_sr rechazada y venta cancelada. Stock restaurado.";
                $tipo_mensaje = 'exito';
            } else {
                $mensaje = "No se pudo rechazar la solicitud ID: $venta_id_rechazar_sr.";
                $tipo_mensaje = 'error';
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $mensaje = "Error al rechazar solicitud: " . $e->getMessage();
            $tipo_mensaje = 'error';
        }
        $venta_resaltada_id = $venta_id_rechazar_sr;

    // Cancelar venta
    } elseif (isset($_POST['cancelar_venta'])) {
        $venta_id_cancelar = $_POST['venta_id'];
        try {
            $stmt_check_cancel = $conn->prepare("SELECT es_cancelada FROM ventas_maestro WHERE id = ?");
            $stmt_check_cancel->execute([$venta_id_cancelar]);
            $ya_cancelada = $stmt_check_cancel->fetchColumn();

            if ($ya_cancelada) {
                $mensaje = "La venta ID: $venta_id_cancelar ya está cancelada.";
                $tipo_mensaje = 'info';
            } else {
                $stmt_detalles = $conn->prepare("SELECT producto_id, cantidad FROM ventas_detalle WHERE venta_id = ?");
                $stmt_detalles->execute([$venta_id_cancelar]);
                $detalles_para_revertir = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

                $conn->beginTransaction();
                foreach ($detalles_para_revertir as $detalle) {
                    $stmt_stock = $conn->prepare("UPDATE productos SET stock_disponible = stock_disponible + ? WHERE id = ?");
                    $stmt_stock->execute([$detalle['cantidad'], $detalle['producto_id']]);
                }

                $stmt = $conn->prepare("UPDATE ventas_maestro SET es_cancelada = TRUE WHERE id = :venta_id AND es_cancelada = FALSE");
                $stmt->bindValue(':venta_id', $venta_id_cancelar, PDO::PARAM_INT);
                $stmt->execute();
                $conn->commit();

                if ($stmt->rowCount() > 0) {
                    $mensaje = "Venta ID: $venta_id_cancelar cancelada. Stock restaurado.";
                    $tipo_mensaje = 'exito';
                } else {
                    $mensaje = "No se pudo cancelar la venta ID: $venta_id_cancelar.";
                    $tipo_mensaje = 'error';
                }
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $mensaje = "Error al cancelar venta: " . $e->getMessage();
            $tipo_mensaje = 'error';
        }
        $venta_resaltada_id = $venta_id_cancelar;

    // Reactivar venta
    } elseif (isset($_POST['reactivar_venta'])) {
        $venta_id_reactivar = $_POST['venta_id'];
        try {
            $stmt_check_reactivar = $conn->prepare("SELECT es_cancelada FROM ventas_maestro WHERE id = ?");
            $stmt_check_reactivar->execute([$venta_id_reactivar]);
            $esta_cancelada = $stmt_check_reactivar->fetchColumn();

            if (!$esta_cancelada) {
                $mensaje = "La venta ID: $venta_id_reactivar no está cancelada.";
                $tipo_mensaje = 'info';
            } else {
                $stmt_detalles = $conn->prepare("SELECT producto_id, cantidad FROM ventas_detalle WHERE venta_id = ?");
                $stmt_detalles->execute([$venta_id_reactivar]);
                $detalles_para_descontar = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

                $conn->beginTransaction();
                foreach ($detalles_para_descontar as $detalle) {
                    $stmt_check_stock = $conn->prepare("SELECT stock_disponible FROM productos WHERE id = ?");
                    $stmt_check_stock->execute([$detalle['producto_id']]);
                    $stock_actual_prod = $stmt_check_stock->fetchColumn();
                    if ($stock_actual_prod < $detalle['cantidad']) {
                        throw new PDOException("No hay stock suficiente para reactivar el producto ID: " . $detalle['producto_id']);
                    }
                    $stmt_stock = $conn->prepare("UPDATE productos SET stock_disponible = stock_disponible - ? WHERE id = ?");
                    $stmt_stock->execute([$detalle['cantidad'], $detalle['producto_id']]);
                }

                $stmt = $conn->prepare("UPDATE ventas_maestro SET es_cancelada = FALSE WHERE id = :venta_id AND es_cancelada = TRUE");
                $stmt->bindValue(':venta_id', $venta_id_reactivar, PDO::PARAM_INT);
                $stmt->execute();
                $conn->commit();

                if ($stmt->rowCount() > 0) {
                    $mensaje = "Venta ID: $venta_id_reactivar reactivada. Stock descontado.";
                    $tipo_mensaje = 'exito';
                } else {
                    $mensaje = "No se pudo reactivar la venta ID: $venta_id_reactivar.";
                    $tipo_mensaje = 'error';
                }
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $mensaje = "Error al reactivar venta: " . $e->getMessage();
            $tipo_mensaje = 'error';
        }
        $venta_resaltada_id = $venta_id_reactivar;
    }

    // Redirigir con mensaje y venta_id
    if(isset($venta_resaltada_id)){
        header("Location: mj_registro_tienda.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . urlencode($tipo_mensaje) . "&venta_id=" . $venta_resaltada_id);
        exit;
    }
}

// Obtener todas las ventas con información del cliente y agrupar detalles y pagos
$ventas_completas = [];
try {
    $stmt_ventas_maestro = $conn->query("
        SELECT 
            vm.id AS venta_id, vm.fecha_venta, vm.total AS total_venta, 
            vm.estado_pago, vm.estado_envio, 
            vm.es_solicitud_reventa, vm.es_cancelada,
            vm.comision_final_manual_admin, vm.comision_admin_notas,
            c.nombre AS cliente_nombre, c.red_social AS cliente_red_social, c.info_adicional AS cliente_info,
            u.nombre_completo AS usuario_nombre_venta, u.username AS usuario_username_venta,
            vm.usuario_id
        FROM ventas_maestro vm
        JOIN clientes c ON vm.cliente_id = c.id
        LEFT JOIN usuarios u ON vm.usuario_id = u.id
        ORDER BY vm.fecha_venta DESC
    ");
    $maestros = $stmt_ventas_maestro->fetchAll(PDO::FETCH_ASSOC);

    foreach ($maestros as &$maestro) {
        // Detalles de la venta (productos)
        $stmt_detalles = $conn->prepare("
            SELECT 
                vd.producto_id,
                p.nombre AS producto_nombre_interno,
                p.nombre_visualizacion,
                p.precio_venta_real AS producto_precio_venta_real,
                COALESCE(p.precio_venta_real, 
                   ROUND(AVG((cd_costo.costo_articulo_ars + 
                       IFNULL(
                           (cm_costo.costo_importacion_ars + cm_costo.costo_envio_ars) / 
                           NULLIF((SELECT SUM(cd2.cantidad) FROM compras_detalle cd2 WHERE cd2.compra_id = cm_costo.id), 0)
                       , 0)
                   )) * (1 + :margen_sugerido) 
               ), 0) AS producto_precio_publico_calculado,
                vd.cantidad, 
                vd.precio_unitario AS precio_unitario_cobrado,
                (vd.cantidad * vd.precio_unitario) AS subtotal_producto_cobrado
            FROM ventas_detalle vd
            JOIN productos p ON vd.producto_id = p.id
            LEFT JOIN compras_detalle cd_costo ON p.id = cd_costo.producto_id
            LEFT JOIN compras_maestro cm_costo ON cd_costo.compra_id = cm_costo.id
            WHERE vd.venta_id = :venta_id
            GROUP BY vd.producto_id, p.nombre, p.nombre_visualizacion, p.precio_venta_real, vd.cantidad, vd.precio_unitario
        ");
        $stmt_detalles->bindValue(':margen_sugerido', MARGEN_VENTA_SUGERIDO, PDO::PARAM_STR);
        $stmt_detalles->bindValue(':venta_id', $maestro['venta_id'], PDO::PARAM_INT);
        $stmt_detalles->execute();
        $detalles_productos_raw = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

        // NUEVO: construir resumen de productos para el sumario
        $nombres_productos_sumario_array = [];
        $maestro['detalles_productos'] = array_map(function($detalle_prod) use (&$nombres_productos_sumario_array) {
            $nombre_display = !empty(trim($detalle_prod['nombre_visualizacion']))
                ? $detalle_prod['nombre_visualizacion']
                : $detalle_prod['producto_nombre_interno'];
            $detalle_prod['nombre_display_producto'] = $nombre_display;
            $nombres_productos_sumario_array[] = $detalle_prod['cantidad'] . "x " . $nombre_display;
            return $detalle_prod;
        }, $detalles_productos_raw);

        if (!empty($nombres_productos_sumario_array)) {
            $maestro['productos_sumario_str'] = implode(', ', array_slice($nombres_productos_sumario_array, 0, 2));
            if (count($nombres_productos_sumario_array) > 2) {
                $maestro['productos_sumario_str'] .= '...';
            }
        } else {
            $maestro['productos_sumario_str'] = 'Sin productos detallados';
        }

        // Pagos de la venta
        $stmt_pagos = $conn->prepare("
            SELECT metodo_pago, monto, referencia
            FROM ventas_pagos 
            WHERE venta_id = ?
        ");
        $stmt_pagos->execute([$maestro['venta_id']]);
        $maestro['pagos_realizados'] = $stmt_pagos->fetchAll(PDO::FETCH_ASSOC);
        
        $maestro['estado_pago_nombre'] = getNombreEstadoPago($maestro['estado_pago']);
        $maestro['estado_envio_nombre'] = getNombreEstadoEnvio($maestro['estado_envio']);

        $ventas_completas[] = $maestro;
    }
    unset($maestro);
} catch (PDOException $e) {
    die("Error al obtener ventas: " . $e->getMessage());
}

$venta_resaltada_id = $_GET['venta_id'] ?? null; // Para resaltar la última venta
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Ventas | MJ Importaciones</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/registro_tienda.css"> <!-- Nuevo CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
    /* NUEVO: Estilo para la lista de productos en el sumario */
    .sumario-info .productos-sumario-lista {
        color: #777;
        font-style: italic;
        max-width: 300px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .estado-venta {
        padding: 3px 7px;
        border-radius: 4px;
        color: white;
        font-size: 0.80em;
        text-align: center;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        min-width: 90px;
        margin-right: 5px;
    }
    .estado-venta i {
        font-size: 0.9em;
    }
    .estado-general-0 { background-color: #ffc107; color: #333; }
    .estado-general-1 { background-color: #28a745; }
    .estado-general-2 { background-color: #dc3545; }
    .estado-general-3 { background-color: #3498db; }
    .estado-general-4 { background-color: #1abc9c; }
    .estado-general-5 { background-color: #6f42c1; }
    .estado-general-6 { background-color: #95a5a6; }
    .estado-pago-0 { background-color: #e74c3c; }
    .estado-pago-1 { background-color: #2ecc71; }
    .estado-pago-2 { background-color: #f39c12; }
    .estado-envio-0 { background-color: #7f8c8d; }
    .estado-envio-1 { background-color: #3498db; }
    .estado-envio-2 { background-color: #9b59b6; }
    .estado-envio-3 { background-color: #1abc9c; }
    .sumario-total-estado { flex-wrap: nowrap; }
    .estado-venta.cancelada { background-color: #777; text-decoration: line-through; }
    .estado-venta.solicitud-reventa { background-color: #6f42c1; }
    .venta-item.cancelada-item .venta-sumario,
    .venta-item.cancelada-item .venta-detalle-completo {
        background-color: #f8d7da !important;
        opacity: 0.7;
    }
    .gestion-botones form { display: inline-block; margin-right: 5px;}
    </style>
</head>
<body class="registro-ventas-page">
    <div class="container">
        <?php if ($mensaje): ?>
            <div class="mensaje <?= $tipo_mensaje == 'error' ? 'error' : 'exito' ?>">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($ventas_completas)): ?>
            <p class="no-ventas">No hay ventas registradas todavía.</p>
        <?php else: ?>
            <div class="filtros-ventas">
                <input type="text" id="filtro-nombre-cliente" placeholder="Buscar por cliente...">
                <input type="text" id="filtro-id-venta" placeholder="Buscar por ID Venta...">
                <select id="filtro-estado-venta">
                    <option value="">Todos los Estados</option>
                    <option value="0">Pendiente</option>
                    <option value="1">Pagada</option>
                    <option value="2">Cancelada</option>
                    <option value="3">Enviada</option>
                    <option value="4">Completada</option>
                </select>
            </div>
            <div class="ventas-lista">
                <?php foreach ($ventas_completas as $venta): ?>
                <div class="venta-item <?= $venta['venta_id'] == $venta_resaltada_id ? 'resaltada' : '' ?> <?= $venta['es_cancelada'] ? 'cancelada-item' : '' ?>" 
                     data-cliente="<?= htmlspecialchars(strtolower($venta['cliente_nombre'])) ?>"
                     data-id-venta="<?= $venta['venta_id'] ?>"
                     data-estado-pago="<?= $venta['estado_pago'] ?>"
                     data-estado-envio="<?= $venta['estado_envio'] ?>">
                    <div class="venta-sumario" onclick="toggleDetalle(this)">
                        <div class="sumario-info">
                            <span class="venta-id">ID: #<?= htmlspecialchars($venta['venta_id']) ?></span>
                            <span class="fecha-venta"><i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($venta['fecha_venta'])) ?></span>
                            <span class="cliente-nombre"><i class="fas fa-user"></i> <?= htmlspecialchars($venta['cliente_nombre']) ?> (<?= htmlspecialchars($venta['cliente_red_social']) ?>)</span>
                            <span class="productos-sumario-lista"><i class="fas fa-boxes"></i> <?= htmlspecialchars($venta['productos_sumario_str']) ?></span>
                            <?php if ($venta['es_solicitud_reventa'] && !$venta['es_cancelada']): ?>
                                <span class="estado-venta solicitud-reventa"><i class="fas fa-star"></i> Solicitud Reventa</span>
                            <?php endif; ?>
                            <?php if ($venta['es_cancelada']): ?>
                                <span class="estado-venta cancelada"><i class="fas fa-ban"></i> CANCELADA</span>
                            <?php endif; ?>
                            <?php if (!empty($venta['usuario_nombre_venta'])): ?>
                                <span class="vendedor-nombre" title="Vendido por <?= htmlspecialchars($venta['usuario_username_venta']) ?>">
                                    <i class="fas fa-user-tag"></i> <?= htmlspecialchars($venta['usuario_nombre_venta']) ?>
                            </span>
                            <?php elseif (!empty($venta['usuario_username_venta'])): ?>
                                <span class="vendedor-nombre">
                                    <i class="fas fa-user-tag"></i> <?= htmlspecialchars($venta['usuario_username_venta']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="sumario-total-estado">
                             <span class="estado-venta estado-pago-<?= $venta['estado_pago'] ?>">
                                <i class="fas fa-money-bill-wave"></i> <?= htmlspecialchars($venta['estado_pago_nombre']) ?>
                             </span>
                             <span class="estado-venta estado-envio-<?= $venta['estado_envio'] ?>">
                                <i class="fas fa-truck"></i> <?= htmlspecialchars($venta['estado_envio_nombre']) ?>
                             </span>
                            <span class="total-venta">ARS <?= number_format($venta['total_venta'], 2) ?></span>
                            <i class="fas fa-chevron-down toggle-icon"></i>
                        </div>
                    </div>
                    <div class="venta-detalle-completo" style="display: none;">
                        <h4><i class="fas fa-user-circle"></i> Detalles del Cliente</h4>
                        <p><strong>Nombre:</strong> <?= htmlspecialchars($venta['cliente_nombre']) ?></p>
                        <p><strong>Red Social:</strong> <?= htmlspecialchars($venta['cliente_red_social']) ?></p>
                        <?php if (!empty($venta['cliente_info'])): ?>
                        <p><strong>Info Adicional:</strong> <?= nl2br(htmlspecialchars($venta['cliente_info'])) ?></p>
                        <?php endif; ?>

                        <h4><i class="fas fa-boxes"></i> Productos Vendidos (<?= count($venta['detalles_productos']) ?>)</h4>
                        <?php if (!empty($venta['detalles_productos'])): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Cantidad</th>
                                    <th>Precio Unit. (Cobrado)</th>
                                    <th>Subtotal (Cobrado)</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($venta['detalles_productos'] as $detalle): ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($detalle['nombre_display_producto']) ?>
                                        <?php if ($detalle['nombre_display_producto'] !== $detalle['producto_nombre_interno']): ?>
                                            <small class="text-muted d-block">(Ref: <?= htmlspecialchars($detalle['producto_nombre_interno']) ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($detalle['cantidad']) ?></td>
                                    <td>ARS <?= number_format($detalle['precio_unitario_cobrado'], 2) ?></td>
                                    <td>ARS <?= number_format($detalle['subtotal_producto_cobrado'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                            <p>No hay productos detallados para esta venta.</p>
                        <?php endif; ?>
                        
                        <h4><i class="fas fa-money-check-alt"></i> Pagos Realizados (<?= count($venta['pagos_realizados']) ?>)</h4>
                        <?php if (!empty($venta['pagos_realizados'])): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Método</th>
                                    <th>Monto</th>
                                    <th>Referencia</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
                            $totalPagado = 0;
                            foreach ($venta['pagos_realizados'] as $pago): 
                                $totalPagado += $pago['monto'];
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($pago['metodo_pago']) ?></td>
                                    <td>ARS <?= number_format($pago['monto'], 2) ?></td>
                                    <td><?= htmlspecialchars($pago['referencia'] ?? 'N/A') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="1">Total Pagado:</th>
                                    <th colspan="2">ARS <?= number_format($totalPagado, 2) ?></th>
                                </tr>
                                <?php if (abs($totalPagado - $venta['total_venta']) > 0.01): 
                                    $diferencia = $venta['total_venta'] - $totalPagado;
                                ?>
                                <tr>
                                    <th colspan="1"><?= $diferencia > 0 ? 'Saldo Pendiente:' : 'Vuelto:' ?></th>
                                    <th colspan="2" style="color: <?= $diferencia > 0 ? '#e74c3c' : '#27ae60' ?>">
                                        ARS <?= number_format(abs($diferencia), 2) ?>
                                    </th>
                                </tr>
                                <?php endif; ?>
                            </tfoot>
                        </table>
                        <?php else: ?>
                            <p>No hay pagos registrados para esta venta.</p>
                        <?php endif; ?>

                        <h4><i class="fas fa-info-circle"></i> Detalles de la Venta</h4>
                        <p><strong>Estado de Pago:</strong> 
                           <span class="estado-venta estado-pago-<?= $venta['estado_pago'] ?>">
                                <?= htmlspecialchars($venta['estado_pago_nombre']) ?>
                           </span>
                        </p>
                        <p><strong>Estado de Envío:</strong> 
                           <span class="estado-venta estado-envio-<?= $venta['estado_envio'] ?>">
                                <?= htmlspecialchars($venta['estado_envio_nombre']) ?>
                           </span>
                        </p>
                        <?php if (!empty($venta['usuario_nombre_venta'])): ?>
                            <p><strong>Vendido por:</strong> <?= htmlspecialchars($venta['usuario_nombre_venta']) ?> (<?= htmlspecialchars($venta['usuario_username_venta']) ?>)</p>
                        <?php elseif (!empty($venta['usuario_username_venta'])): ?>
                            <p><strong>Vendido por:</strong> <?= htmlspecialchars($venta['usuario_username_venta']) ?></p>
                        <?php else: ?>
                            <p><strong>Vendido por:</strong> <em>No especificado</em></p>
                        <?php endif; ?>

                        <div class="gestion-botones" style="margin-top:15px;">
                            <?php if ($puedeGestionarSolicitudes): ?>
                                <?php if ($venta['es_solicitud_reventa'] && !$venta['es_cancelada']): ?>
                                    <form method="POST" onsubmit="return confirm('¿Aprobar esta solicitud de reventa? La venta se tratará como normal.')">
                                        <input type="hidden" name="venta_id" value="<?= $venta['venta_id'] ?>">
                                        <button type="submit" name="aprobar_solicitud_reventa" class="btn-accion confirmar"><i class="fas fa-check"></i> Aprobar Solicitud</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('¿Rechazar y cancelar esta solicitud de reventa?')">
                                        <input type="hidden" name="venta_id" value="<?= $venta['venta_id'] ?>">
                                        <button type="submit" name="rechazar_solicitud_reventa" class="btn-accion rechazar"><i class="fas fa-times"></i> Rechazar y Cancelar Solicitud</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!$venta['es_cancelada']): ?>
                                    <form method="POST" onsubmit="return confirm('¿Está seguro de cancelar esta venta? Esta acción puede ser irreversible.')">
                                        <input type="hidden" name="venta_id" value="<?= $venta['venta_id'] ?>">
                                        <button type="submit" name="cancelar_venta" class="btn-accion eliminar"><i class="fas fa-ban"></i> Cancelar Venta</button>
                                    </form>
                                <?php else: ?>
                                    <?php if ($puedeEditarEliminar): ?>
                                    <form method="POST" onsubmit="return confirm('¿Reactivar esta venta cancelada?')">
                                        <input type="hidden" name="venta_id" value="<?= $venta['venta_id'] ?>">
                                        <button type="submit" name="reactivar_venta" class="btn-accion confirmar"><i class="fas fa-undo"></i> Reactivar Venta</button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($puedeEditarEliminar && !$venta['es_cancelada']): ?>
                            <a href="editar_venta.php?id=<?= $venta['venta_id'] ?>" class="btn-accion editar"><i class="fas fa-edit"></i> Editar Venta</a>
                            <?php endif; ?>
                            <?php if ($puedeEditarEliminar): ?>
                            <button class="btn-accion eliminar" onclick="confirmarEliminacionVentaMaestro(<?= $venta['venta_id'] ?>)"><i class="fas fa-trash-alt"></i> Eliminar Registro Venta</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <script>
    function confirmarEliminacionVentaMaestro(ventaId) {
        <?php if (!$puedeEditarEliminar): ?>
        alert('No tiene permisos para eliminar ventas.');
        <?php else: ?>
        if (confirm('¿Estás seguro de que deseas ELIMINAR PERMANENTEMENTE el registro de esta venta (ID: ' + ventaId + ')? Esta acción no se puede deshacer y borrará todos sus datos asociados.')) {
            window.location.href = 'eliminar_venta_definitivo.php?id=' + ventaId;
        }
        <?php endif; ?>
    }
    
    function toggleDetalle(element) {
        const detalleCompleto = element.nextElementSibling;
        const icon = element.querySelector('.toggle-icon');
        if (detalleCompleto.style.display === 'none' || detalleCompleto.style.display === '') {
            detalleCompleto.style.display = 'block';
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
            element.parentElement.classList.add('abierto');
        } else {
            detalleCompleto.style.display = 'none';
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
            element.parentElement.classList.remove('abierto');
        }
    }

    function confirmarEliminacion(ventaId) {
        <?php if (!$puedeEditarEliminar): ?>
        alert('No tiene permisos para eliminar ventas.');
        <?php else: ?>
        if (confirm('¿Estás seguro de que deseas eliminar esta venta? Esta acción no se puede deshacer.')) {
            window.location.href = 'eliminar_venta.php?id=' + ventaId;
        }
        <?php endif; ?>
    }
    
    // Scroll to highlighted sale if present
    document.addEventListener('DOMContentLoaded', function() {
        const highlightedSale = document.querySelector('.venta-item.resaltada');
        if (highlightedSale) {
            highlightedSale.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Opcional: expandir automáticamente
            // const sumario = highlightedSale.querySelector('.venta-sumario');
            // if(sumario) toggleDetalle(sumario);
        }

        const filtroNombre = document.getElementById('filtro-nombre-cliente');
        const filtroId = document.getElementById('filtro-id-venta');
        const filtroEstado = document.getElementById('filtro-estado-venta');
        const ventasItems = document.querySelectorAll('.venta-item');

        function aplicarFiltros() {
            const nombreQuery = filtroNombre.value.toLowerCase();
            const idQuery = filtroId.value;
            const estadoQuery = filtroEstado.value;

            ventasItems.forEach(item => {
                const nombreCliente = item.dataset.cliente;
                const idVenta = item.dataset.idVenta;
                const estadoVenta = item.dataset.estado;

                const matchNombre = nombreQuery === '' || nombreCliente.includes(nombreQuery);
                const matchId = idQuery === '' || idVenta.includes(idQuery);
                const matchEstado = estadoQuery === '' || estadoVenta === estadoQuery;
                
                if (matchNombre && matchId && matchEstado) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        filtroNombre.addEventListener('keyup', aplicarFiltros);
        filtroId.addEventListener('keyup', aplicarFiltros);
        filtroEstado.addEventListener('change', aplicarFiltros);
    });
    </script>
</body>
</html>