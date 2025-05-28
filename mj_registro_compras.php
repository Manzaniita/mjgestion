<?php
session_start();
require_once 'config.php';
requireRole(['admin', 'supervisor']); // Admin y Supervisor pueden ver registro de compras
include 'header.php'; 

$puedeEditarEliminar = hasRole('admin'); // Solo Admin puede editar/eliminar compras

// Obtener todas las compras con resumen de productos
$compras = $conn->query("
    SELECT 
        cm.id, cm.numero_pedido, cm.fecha_compra, cm.created_at,
        COUNT(cd.id) AS total_productos,
        SUM(cd.cantidad) AS total_unidades,
        SUM(cd.costo_articulo_ars * cd.cantidad) AS subtotal_ars,
        cm.costo_importacion_ars,
        cm.costo_envio_ars,
        (SUM(cd.costo_articulo_ars * cd.cantidad) + cm.costo_importacion_ars + cm.costo_envio_ars) AS total_ars,
        cm.observaciones,
        GROUP_CONCAT(DISTINCT p.nombre SEPARATOR ', ') AS productos_nombres
    FROM compras_maestro cm
    JOIN compras_detalle cd ON cm.id = cd.compra_id
    JOIN productos p ON cd.producto_id = p.id
    GROUP BY cm.id
    ORDER BY cm.fecha_compra DESC, cm.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Compras</title>
    <link rel="stylesheet" href="css/registro_compras.css"> <!-- CSS específico de página después -->
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
<div class="compras-page-content"> <!-- Contenedor específico con la clase correcta -->
    <h1>Registro de Compras</h1>
    <div class="panel">
        <h2>Listado de Pedidos</h2>
        <?php if (empty($compras)): ?>
            <p>No hay compras registradas aún.</p>
        <?php else: ?>
            <div id="lista-compras">
                <?php foreach ($compras as $compra): ?>
                    <div class="tarjeta-compra">
                        <div class="tarjeta-header" onclick="toggleDetalle('detalle-<?= $compra['id'] ?>')">
                            <div>
                                <div class="tarjeta-titulo">
                                    <?= htmlspecialchars($compra['numero_pedido']) ?>
                                    <span class="badge badge-primary"><?= $compra['total_productos'] ?> productos</span>
                                    <span class="badge badge-success"><?= $compra['total_unidades'] ?> unidades</span>
                                </div>
                                <div class="tarjeta-subtitulo">
                                    <?= date('d/m/Y', strtotime($compra['fecha_compra'])) ?> - 
                                    <?= substr($compra['productos_nombres'], 0, 50) ?>...
                                </div>
                            </div>
                            <div>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>
                        
                        <div class="tarjeta-resumen">
                            <div class="resumen-item">
                                <h4>Subtotal Artículos</h4>
                                <p>ARS <?= number_format($compra['subtotal_ars'], 2) ?></p>
                            </div>
                            <div class="resumen-item">
                                <h4>Costo Importación</h4>
                                <p>ARS <?= number_format($compra['costo_importacion_ars'], 2) ?></p>
                            </div>
                            <div class="resumen-item">
                                <h4>Costo Envío</h4>
                                <p>ARS <?= number_format($compra['costo_envio_ars'], 2) ?></p>
                            </div>
                            <div class="resumen-item">
                                <h4>Total Pedido</h4>
                                <p>ARS <?= number_format($compra['total_ars'], 2) ?></p>
                            </div>
                        </div>
                        
                        <div class="tarjeta-detalle" id="detalle-<?= $compra['id'] ?>">
                            <?php
                            $margen = $_GET['margen'] ?? 1.40; // Default margin multiplier (40% profit)

                            // Obtener detalles completos de esta compra
                            $detalles = $conn->query("
                                SELECT
                                    cd.*,
                                    p.nombre AS producto_nombre,
                                    c.nombre AS categoria_nombre,
                                    ({$compra['costo_importacion_ars']}/{$compra['total_unidades']}) AS costo_importacion_unitario,
                                    ({$compra['costo_envio_ars']}/{$compra['total_unidades']}) AS costo_envio_unitario,
                                    (cd.costo_articulo_ars + 
                                     ({$compra['costo_importacion_ars']}/{$compra['total_unidades']}) +
                                     ({$compra['costo_envio_ars']}/{$compra['total_unidades']})) AS costo_total_unitario,
                                    ((cd.costo_articulo_ars + 
                                      ({$compra['costo_importacion_ars']}/{$compra['total_unidades']}) +
                                      ({$compra['costo_envio_ars']}/{$compra['total_unidades']})) * {$margen}) AS precio_venta_sugerido,
                                    ((cd.costo_articulo_ars + 
                                      ({$compra['costo_importacion_ars']}/{$compra['total_unidades']}) +
                                      ({$compra['costo_envio_ars']}/{$compra['total_unidades']})) * cd.cantidad) AS costo_total_linea
                                FROM compras_detalle cd
                                JOIN productos p ON cd.producto_id = p.id
                                JOIN categorias c ON p.categoria_id = c.id
                                WHERE cd.compra_id = {$compra['id']}
                            ")->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            
                            <table class="tabla-detalle">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Cantidad</th>
                                        <th>Costo Unitario</th>
                                        <th>Importación</th>
                                        <th>Envío</th>
                                        <th>Subtotal</th>
                                        <th>Precio Venta Sugerido</th>
                                        <th>Total Línea</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detalles as $detalle): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($detalle['categoria_nombre']) ?> - 
                                            <?= htmlspecialchars($detalle['producto_nombre']) ?>
                                        </td>
                                        <td><?= $detalle['cantidad'] ?></td>
                                        <td>
                                            <span class="moneda-badge ars">ARS <?= number_format($detalle['costo_articulo_ars'], 2) ?></span>
                                        </td>
                                        <td>
                                            <span class="moneda-badge ars">ARS <?= number_format($detalle['costo_importacion_unitario'], 2) ?></span>
                                        </td>
                                        <td>
                                            <span class="moneda-badge ars">ARS <?= number_format($detalle['costo_envio_unitario'], 2) ?></span>
                                        </td>
                                        <td>
                                            <span class="moneda-badge ars">ARS <?= number_format($detalle['costo_articulo_ars'] + $detalle['costo_importacion_unitario'] + $detalle['costo_envio_unitario'], 2) ?></span>
                                        </td>
                                        <td>
                                            <span class="moneda-badge ars">ARS <?= number_format($detalle['precio_venta_sugerido'], 2) ?></span>
                                        </td>
                                        <td>
                                            <span class="moneda-badge ars">ARS <?= number_format($detalle['costo_total_linea'], 2) ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr style="font-weight: bold; background-color: #f8f9fa;">
                                        <td colspan="7" style="text-align: right;">Subtotal Productos:</td>
                                        <td>ARS <?= number_format($compra['subtotal_ars'], 2) ?></td>
                                    </tr>
                                    <tr style="font-weight: bold; background-color: #f8f9fa;">
                                        <td colspan="7" style="text-align: right;">Costo Importación:</td>
                                        <td>ARS <?= number_format($compra['costo_importacion_ars'], 2) ?></td>
                                    </tr>
                                    <tr style="font-weight: bold; background-color: #f8f9fa;">
                                        <td colspan="7" style="text-align: right;">Costo Envío:</td>
                                        <td>ARS <?= number_format($compra['costo_envio_ars'], 2) ?></td>
                                    </tr>
                                    <tr style="font-weight: bold; background-color: #e9ecef;">
                                        <td colspan="7" style="text-align: right;">Total Pedido:</td>
                                        <td>ARS <?= number_format($compra['total_ars'], 2) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            
                            <?php if (!empty($compra['observaciones'])): ?>
                                <div style="margin-top: 15px;">
                                    <h4>Observaciones:</h4>
                                    <p><?= nl2br(htmlspecialchars($compra['observaciones'])) ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($puedeEditarEliminar): ?>
                            <div class="acciones">
                                <a href="mj_compras_productos.php?editar=<?= $compra['id'] ?>" class="btn">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <form method="POST" action="eliminar_compra.php" style="display:inline;" onsubmit="return confirm('¿Estás seguro de eliminar esta compra?');">
                                    <input type="hidden" name="compra_id" value="<?= $compra['id'] ?>">
                                    <button type="submit" class="btn btn-delete">
                                        <i class="fas fa-trash"></i> Eliminar
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <script>
        function toggleDetalle(id) {
            const detalle = document.getElementById(id);
            detalle.classList.toggle('abierto');
            
            // Rotar el ícono
            const icono = detalle.previousElementSibling.querySelector('i');
            icono.classList.toggle('fa-chevron-down');
            icono.classList.toggle('fa-chevron-up');
        }
    </script>
</div> <!-- Cierre del contenedor específico -->
</body>
</html>