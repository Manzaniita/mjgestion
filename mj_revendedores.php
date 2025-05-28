<?php
session_start();
require_once 'config.php';
requireRole('reventa'); // Solo 'reventa' puede acceder
include 'header.php'; // Asumiendo que tienes un header.php

$revendedor_id = $_SESSION['user_id'];
$revendedor_nombre = $_SESSION['user_nombre_completo'] ?? $_SESSION['user_username'];

$ventas_revendedor = [];
$total_monto_ventas_reventa = 0;
$total_comisiones_efectivas = 0; // Cambiado para reflejar la comisión final

try {
    $stmt_ventas = $conn->prepare("
        SELECT 
            vm.id AS venta_id, vm.fecha_venta, vm.total AS total_venta, 
            vm.estado_pago, vm.estado_envio, vm.es_solicitud_reventa, vm.es_cancelada,
            c.nombre AS cliente_nombre, u.nombre_completo AS usuario_nombre, u.username AS usuario_username
        FROM ventas_maestro vm
        JOIN clientes c ON vm.cliente_id = c.id
        JOIN usuarios u ON vm.usuario_id = u.id
        WHERE u.rol = 'reventa'
          AND vm.es_cancelada = FALSE
          AND (vm.es_solicitud_reventa = TRUE OR vm.estado_pago = :estado_pagado)
        ORDER BY vm.fecha_venta DESC
    ");
    $stmt_ventas->bindValue(':estado_pagado', ESTADO_PAGO_PAGADO, PDO::PARAM_INT);
    $stmt_ventas->execute();
    $maestros_venta = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);

    $total_comisiones_efectivas = 0;
    foreach ($maestros_venta as $key => $maestro) {
        if ($maestro['es_solicitud_reventa']) {
            $estado_display = 'Solicitud Reventa';
        } elseif ($maestro['estado_pago'] == ESTADO_PAGO_PAGADO) {
            $estado_display = getNombreEstadoPago($maestro['estado_pago']) . ' / ' . getNombreEstadoEnvio($maestro['estado_envio']);
        } else {
            $estado_display = getNombreEstadoPago($maestro['estado_pago']);
        }
        $maestros_venta[$key]['estado_display_compuesto'] = $estado_display;

        // Sumar solo si pagada, no cancelada y comision > 0
        $comision_efectiva_esta_venta = calcularComisionVenta($maestro['venta_id'], $conn);
        if ($maestro['estado_pago'] == ESTADO_PAGO_PAGADO && !$maestro['es_cancelada'] && $comision_efectiva_esta_venta > 0) {
            $total_comisiones_efectivas += $comision_efectiva_esta_venta;
        }
        $maestros_venta[$key]['comision_efectiva'] = $comision_efectiva_esta_venta;
    }
    $ventas_revendedor = $maestros_venta;

} catch (PDOException $e) {
    $error_db = "Error al cargar datos: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Ventas y Comisiones | MJ Gestión</title>
    <link rel="stylesheet" href="css/global.css"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; color: #333; }
        .container { max-width: 1100px; margin: 20px auto; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #2c3e50; }
        h1 { border-bottom: 2px solid #3498db; padding-bottom: 10px; margin-bottom: 20px; font-size: 1.8em; }
        h2 { font-size: 1.5em; margin-top: 30px; margin-bottom: 15px; color: #3498db; }
        
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #f8f9fa; font-weight: 600; color: #454545; }
        tr:hover { background-color: #f1f8ff; }
        
        .estado-venta { padding: 4px 8px; border-radius: 4px; color: white; font-size: 0.85em; text-align: center; display: inline-block; min-width: 80px;}
        .estado-solicitud-reventa { background-color: #ffc107; color: #333; }
        .estado-pagada, .estado-completada, .estado-enviada { background-color: #28a745; }
        .estado-cancelada, .estado-solicitud-rechazada { background-color: #dc3545; }
        .estado-pendiente-de-pago { background-color: #17a2b8; } /* O el color que uses */

        .summary-section {
            margin-top: 30px;
            padding: 20px;
            background-color: #e9ecef;
            border-radius: 6px;
        }
        .summary-section h2 { margin-top: 0; color: #34495e; }
        .summary-item { font-size: 1.1em; margin-bottom: 8px; }
        .summary-item strong { color: #2980b9; }
        .alert-info { background-color: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 4px; margin-bottom:20px; border: 1px solid #bee5eb; }
        .alert-danger { background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin-bottom:20px; border: 1px solid #f5c6cb; }
        .no-ventas { text-align: center; padding: 30px; font-size: 1.2em; color: #777; }
        .comision-col { color: #28a745; font-weight: bold; }
        .total-col { font-weight: bold; }
        .comision-ajustada { background-color: #fff3cd; } /* Fondo amarillo claro para filas con comisión ajustada */
    </style>
</head>
<body>
    <?php include_once 'header_revendedores.php'; // Podrías tener un header específico para revendedores si es diferente ?>
    
    <div class="container">
        <h1><i class="fas fa-chart-line"></i> Mis Ventas y Comisiones</h1>
        <p class="alert-info">Bienvenido/a, <strong><?= htmlspecialchars($revendedor_nombre) ?></strong>. Aquí puedes ver un resumen de tus ventas registradas y las comisiones generadas.</p>

        <?php if (isset($error_db)): ?>
            <p class="alert-danger"><?= htmlspecialchars($error_db) ?></p>
        <?php endif; ?>

        <h2><i class="fas fa-list-alt"></i> Detalle de Ventas Registradas</h2>
        <?php if (empty($ventas_revendedor) && !isset($error_db)): ?>
            <p class="no-ventas"><i class="fas fa-info-circle"></i> Aún no has registrado ninguna venta.</p>
        <?php elseif (!empty($ventas_revendedor)): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID Venta</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Estado</th>
                            <th style="text-align:right;">Tu Costo Total (Venta)</th>
                            <th style="text-align:right;">Comisión Generada</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ventas_revendedor as $venta): ?>
                        <tr class="<?= $venta['comision_fue_ajustada'] ? 'comision-ajustada' : '' ?>">
                            <td>#<?= htmlspecialchars($venta['venta_id']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($venta['fecha_venta'])) ?></td>
                            <td><?= htmlspecialchars($venta['cliente_nombre']) ?> (<?= htmlspecialchars($venta['cliente_red_social']) ?>)</td>
                            <td>
                                <span class="estado-venta estado-<?= strtolower(str_replace([' ', '/'], '-', $venta['estado_display_compuesto'])) ?>">
                                    <?= htmlspecialchars($venta['estado_display_compuesto']) ?>
                                </span>
                            </td>
                            <td style="text-align:right;" class="total-col">ARS <?= number_format($venta['total_venta_cobrado_reventa'], 2) ?></td>
                            <td style="text-align:right;" class="comision-col">
                                ARS <?= number_format($venta['comision_efectiva'], 2) ?>
                                <?php if ($venta['comision_fue_ajustada']): ?>
                                    <i class="fas fa-info-circle" title="Comisión ajustada por administración"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="summary-section">
            <h2><i class="fas fa-calculator"></i> Resumen General</h2>
            <div class="summary-item">
                Total Vendido (a tu costo de reventa): <strong>ARS <?= number_format($total_monto_ventas_reventa, 2) ?></strong>
            </div>
            <div class="summary-item">
                Total Comisiones Generadas (de ventas aprobadas y/o ajustadas): <strong>ARS <?= number_format($total_comisiones_efectivas, 2) ?></strong>
            </div>
        </div>
    </div>
</body>
</html>