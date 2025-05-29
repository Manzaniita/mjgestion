<?php
session_start();
require_once 'config.php';
requireRole('admin');
include 'header.php';

$mensaje = '';
$error = '';

// Procesar actualización de comisión
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_comision'])) {
    $venta_id_actualizar = $_POST['venta_id'];
    $comision_manual = trim($_POST['comision_final_manual_admin']);
    $comision_notas = trim($_POST['comision_admin_notas']);

    if ($comision_manual === '' || !is_numeric($comision_manual) || $comision_manual < 0) {
        $comision_manual_db = null;
    } else {
        $comision_manual_db = (float)$comision_manual;
    }

    try {
        $stmt = $conn->prepare("UPDATE ventas_maestro SET comision_final_manual_admin = ?, comision_admin_notas = ? WHERE id = ?");
        $stmt->execute([$comision_manual_db, $comision_notas, $venta_id_actualizar]);
        $mensaje = "Comisión para la venta ID: $venta_id_actualizar actualizada.";
    } catch (PDOException $e) {
        $error = "Error al actualizar comisión: " . $e->getMessage();
    }
}

// Obtener todas las ventas de revendedores que podrían generar comisión
$ventas_revendedores = [];
try {
    // NUEVA CONSULTA: muestra TODAS las ventas de revendedores no canceladas
    $stmt_ventas = $conn->prepare("
        SELECT 
            vm.id AS venta_id, 
            vm.fecha_venta, 
            vm.total AS total_venta_cobrado_reventa, 
            vm.estado_pago, 
            vm.estado_envio, 
            vm.es_solicitud_reventa, 
            vm.es_cancelada,
            vm.comision_final_manual_admin,
            vm.comision_admin_notas,
            c.nombre AS cliente_nombre, 
            u.nombre_completo AS revendedor_nombre, 
            u.username AS revendedor_username
        FROM ventas_maestro vm
        JOIN clientes c ON vm.cliente_id = c.id
        JOIN usuarios u ON vm.usuario_id = u.id
        WHERE u.rol = 'reventa'
          AND vm.es_cancelada = FALSE
        ORDER BY vm.fecha_venta DESC
    ");
    $stmt_ventas->execute();
    $maestros_venta = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);

    foreach ($maestros_venta as $key => $maestro) {
        if ($maestro['es_cancelada']) {
            $estado_display = 'Cancelada';
        } elseif ($maestro['es_solicitud_reventa']) {
            $estado_display = 'Solicitud Reventa (Pend. Aprobación)';
        } else {
            $estado_display = getNombreEstadoPago($maestro['estado_pago']) . ' / ' . getNombreEstadoEnvio($maestro['estado_envio']);
        }
        $maestros_venta[$key]['estado_display_compuesto'] = $estado_display;
        $maestros_venta[$key]['comision_calculada'] = calcularComisionVenta($maestro['venta_id'], $conn);
        if ($maestro['comision_final_manual_admin'] !== null) {
            $maestros_venta[$key]['comision_final_efectiva'] = $maestro['comision_final_manual_admin'];
        } else {
            $maestros_venta[$key]['comision_final_efectiva'] = $maestros_venta[$key]['comision_calculada'];
        }
    }
    $ventas_revendedores = $maestros_venta;

} catch (PDOException $e) {
    $error_db = "Error al cargar datos de comisiones: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Comisiones de Revendedores | MJ Gestión</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .container { max-width: 1200px; margin: 20px auto; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { border-bottom: 2px solid #3498db; padding-bottom: 10px; margin-bottom: 20px; font-size: 1.8em; }
        .table-responsive { overflow-x: auto; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #f8f9fa; font-weight: 600; color: #454545; }
        tr:hover { background-color: #f1f8ff; }
        .form-control-sm { padding: 0.25rem 0.5rem; font-size: .875rem; line-height: 1.5; border-radius: 0.2rem; width: 80px; }
        .textarea-sm { padding: 0.25rem 0.5rem; font-size: .875rem; line-height: 1.5; border-radius: 0.2rem; width: 150px; min-height: 30px; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: .875rem; line-height: 1.5; border-radius: 0.2rem; }
        .btn-success { background-color: #28a745; color: white; border: none; cursor: pointer; }
        .btn-success:hover { background-color: #218838; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .estado-venta { padding: 4px 8px; border-radius: 4px; color: white; font-size: 0.85em; text-align: center; display: inline-block; min-width: 80px;}
        .estado-solicitud-reventa { background-color: #ffc107; color: #333; }
        .estado-pagada, .estado-completada, .estado-enviada { background-color: #28a745; }
        .total-col, .comision-col { font-weight: bold; }
        .comision-manual { background-color: #fff3cd; }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-hand-holding-usd"></i> Gestión de Comisiones de Revendedores</h1>

        <?php if ($mensaje): ?><div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (isset($error_db)): ?><div class="alert alert-error"><?= htmlspecialchars($error_db) ?></div><?php endif; ?>

        <?php if (empty($ventas_revendedores) && !isset($error_db)): ?>
            <p>No hay ventas de revendedores que requieran gestión de comisión en este momento.</p>
        <?php elseif (!empty($ventas_revendedores)): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID Venta</th>
                            <th>Fecha</th>
                            <th>Revendedor</th>
                            <th>Cliente</th>
                            <th>Estado</th>
                            <th style="text-align:right;">Total Venta (Costo Rev.)</th>
                            <th style="text-align:right;">Comisión Calculada</th>
                            <th style="text-align:right;">Comisión Manual (ARS)</th>
                            <th>Notas Admin</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ventas_revendedores as $venta): ?>
                        <tr class="<?= $venta['comision_final_manual_admin'] !== null ? 'comision-manual' : '' ?>">
                            <td>#<?= htmlspecialchars($venta['venta_id']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($venta['fecha_venta'])) ?></td>
                            <td><?= htmlspecialchars($venta['revendedor_nombre'] ?: $venta['revendedor_username']) ?></td>
                            <td><?= htmlspecialchars($venta['cliente_nombre']) ?></td>
                            <td>
                                <span class="estado-venta estado-<?= strtolower(str_replace([' ', '/'], '-', $venta['estado_display_compuesto'])) ?>">
                                    <?= htmlspecialchars($venta['estado_display_compuesto']) ?>
                                </span>
                            </td>
                            <td style="text-align:right;" class="total-col">ARS <?= number_format($venta['total_venta_cobrado_reventa'], 2) ?></td>
                            <td style="text-align:right;" class="comision-col">ARS <?= number_format($venta['comision_calculada'], 2) ?></td>
                            <form method="POST">
                                <input type="hidden" name="venta_id" value="<?= $venta['venta_id'] ?>">
                                <td style="text-align:right;">
                                    <input type="number" step="0.01" name="comision_final_manual_admin" class="form-control-sm" 
                                           value="<?= htmlspecialchars($venta['comision_final_manual_admin'] ?? '') ?>" 
                                           placeholder="ej: <?= number_format($venta['comision_calculada'], 0) ?>">
                                </td>
                                <td>
                                    <textarea name="comision_admin_notas" class="textarea-sm" placeholder="Motivo del ajuste..."><?= htmlspecialchars($venta['comision_admin_notas'] ?? '') ?></textarea>
                                </td>
                                <td>
                                    <button type="submit" name="actualizar_comision" class="btn-sm btn-success" title="Guardar cambios de comisión"><i class="fas fa-save"></i></button>
                                </td>
                            </form>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
