<?php
// Activar el reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';
requireRole(['admin', 'supervisor', 'reventa']); // Todos los roles pueden ver la tienda
include 'header.php';

// Obtener productos con stock y precio de venta sugerido y datos personalizados
$productos = $conn->query("
    SELECT 
        p.id,
        p.nombre AS producto_nombre_interno,
        p.nombre_visualizacion,
        p.precio_venta_real,
        (SELECT IFNULL(SUM(cd.cantidad), 0) FROM compras_detalle cd WHERE cd.producto_id = p.id) AS stock_comprado,
        (SELECT IFNULL(SUM(vd.cantidad), 0) FROM ventas_detalle vd
            JOIN ventas_maestro vm ON vd.venta_id = vm.id
            WHERE vd.producto_id = p.id 
              AND vm.es_cancelada = FALSE
        ) AS stock_vendido,
        (
            (SELECT IFNULL(SUM(cd.cantidad), 0) FROM compras_detalle cd WHERE cd.producto_id = p.id) - 
            (SELECT IFNULL(SUM(vd.cantidad), 0) FROM ventas_detalle vd
                JOIN ventas_maestro vm ON vd.venta_id = vm.id
                WHERE vd.producto_id = p.id 
                  AND vm.es_cancelada = FALSE
            )
        ) AS stock_disponible,
        c.nombre AS categoria_nombre,
        ROUND(
            AVG(
                cd.costo_articulo_ars + 
                IFNULL(
                    (cm.costo_importacion_ars + cm.costo_envio_ars) / 
                    NULLIF((SELECT SUM(cd2.cantidad) FROM compras_detalle cd2 WHERE cd2.compra_id = cm.id), 0)
                , 0)
            ) * (1 + " . MARGEN_VENTA_SUGERIDO . ")
        , 2) AS precio_venta_sugerido,
        ROUND(
            AVG(
                cd.costo_articulo_ars + 
                IFNULL(
                    (cm.costo_importacion_ars + cm.costo_envio_ars) / 
                    NULLIF((SELECT SUM(cd2.cantidad) FROM compras_detalle cd2 WHERE cd2.compra_id = cm.id), 0)
                , 0)
            )
        , 2) AS costo_total_promedio,
        ROUND(AVG(cd.costo_articulo_ars), 2) AS valor_articulo,
        (
            SELECT GROUP_CONCAT(CONCAT(pd.campo_nombre, ': ', pd.valor) SEPARATOR ', ')
            FROM producto_datos pd
            WHERE pd.producto_id = p.id
        ) AS datos_personalizados
    FROM productos p
    JOIN categorias c ON p.categoria_id = c.id
    JOIN compras_detalle cd ON p.id = cd.producto_id
    JOIN compras_maestro cm ON cd.compra_id = cm.id
    GROUP BY p.id, p.nombre, p.nombre_visualizacion, c.nombre, p.precio_venta_real
    ORDER BY p.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Procesar los nombres de productos para usar el de visualización si existe
foreach ($productos as &$producto) {
    $producto['nombre_display'] = (!empty(trim($producto['nombre_visualizacion'])))
        ? $producto['nombre_visualizacion']
        : $producto['producto_nombre_interno'];
}
unset($producto);

$puedeEditarPrecios = hasRole('admin'); // Solo admin puede editar precios
$puedeFinalizarCompra = hasRole(['admin', 'supervisor', 'reventa']); // Todos pueden intentar finalizar

$esAdmin = hasRole('admin');
$esReventa = hasRole('reventa');

// Obtener lista de usuarios activos para el selector (si es admin)
$lista_usuarios_venta = [];
if ($esAdmin) {
    $stmt_usuarios = $conn->query("SELECT id, nombre_completo, username FROM usuarios WHERE activo = TRUE ORDER BY nombre_completo ASC");
    $lista_usuarios_venta = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tienda | MJ Importaciones</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/tienda.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="tienda">
    <div class="carrito-container" style="position:fixed;top:100px;left:20px;bottom:20px;width:370px;z-index:100;">
        <div class="carrito" style="height:100%;display:flex;flex-direction:column;">
            <div class="carrito-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h2 style="font-size:1.3rem;margin:0;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-shopping-basket" style="color:#2d7be5;"></i> Tu Carrito
                </h2>
                <?php if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito'])): ?>
                    <a href="vaciar_carrito.php" class="btn-vaciar" style="color:#e74c3c;font-size:1.1rem;text-decoration:none;display:flex;align-items:center;gap:4px;">
                        <i class="fas fa-trash-alt"></i> Vaciar
                    </a>
                <?php endif; ?>
            </div>
            <div class="carrito-items-container" style="flex:1;overflow-y:auto;margin:18px 0 0 0;">
                <?php if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito'])): ?>
                    <form method="POST" action="actualizar_carrito.php" id="carrito-form">
                        <?php 
                        $totalItems = 0;
                        $totalCompra = 0;
                        foreach ($_SESSION['carrito'] as $producto_id => $cantidad): 
                            // Buscar el producto en la lista de productos
                            $producto = array_filter($productos, function($p) use ($producto_id) {
                                return $p['id'] == $producto_id;
                            });
                            $producto = current($producto);
                            if (!$producto) continue;

                            // Lógica de precio según rol
                            $precio_base = !empty($producto['precio_venta_real']) ? $producto['precio_venta_real'] : $producto['precio_venta_sugerido'];
                            if ($esReventa) {
                                $costo_reventa_bruto = $precio_base * (1 - 0.15);
                                $precio = ceil($costo_reventa_bruto / 100) * 100;
                            } else {
                                $precio = $precio_base;
                            }
                            $subtotal = $precio * $cantidad;
                            $totalCompra += $subtotal;
                            $totalItems += $cantidad;
                        ?>
                            <div class="carrito-item" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;padding:10px 8px;background:var(--color-bg-lighter);border-radius:7px;">
                                <div class="producto-info" style="display:flex;align-items:center;gap:10px;">
                                    <div class="producto-img" style="font-size:1.5rem;color:#2d7be5;">
                                        <i class="fas fa-box-open"></i>
                                    </div>
                                    <div class="producto-details" style="display:flex;flex-direction:column;">
                                        <h4 style="margin:0;font-size:1rem;font-weight:600;"><?= htmlspecialchars($producto['nombre_display']) ?></h4>
                                        <?php if (!empty($producto['categoria_nombre'])): ?>
                                            <span class="producto-categoria" style="font-size:0.9em;color:#888;"><?= htmlspecialchars($producto['categoria_nombre']) ?></span>
                                        <?php endif; ?>
                                        <div class="producto-precio" style="font-size:0.95em;color:#2d7be5;">
                                            <span>ARS <?= number_format($precio, 2) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="item-actions" style="display:flex;flex-direction:column;align-items:end;gap:7px;">
                                    <div class="cantidad-control" style="display:flex;align-items:center;gap:4px;">
                                        <a href="eliminar_unidad.php?id=<?= $producto_id ?>" class="btn-cantidad" title="Eliminar una unidad" style="color:#e67e22;padding:2px 6px;"><i class="fas fa-minus"></i></a>
                                        <input type="number" name="cantidades[<?= $producto_id ?>]" value="<?= $cantidad ?>" min="1" max="<?= $producto['stock_disponible'] ?>" style="width:38px;text-align:center;">
                                        <a href="agregar_al_carrito.php?productos[<?= $producto_id ?>]=1" class="btn-cantidad" title="Agregar una unidad" style="color:#27ae60;padding:2px 6px;"><i class="fas fa-plus"></i></a>
                                    </div>
                                    <div class="subtotal" style="display:flex;align-items:center;gap:8px;">
                                        <span style="font-weight:600;color:#222;">ARS <?= number_format($subtotal, 2) ?></span>
                                        <a href="eliminar_producto.php?id=<?= $producto_id ?>" class="btn-eliminar" title="Eliminar producto" style="color:#e74c3c;"><i class="fas fa-times"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </form>
                <?php else: ?>
                    <div class="carrito-vacio" style="text-align:center;padding:40px 0;color:#aaa;">
                        <i class="fas fa-shopping-basket fa-3x"></i>
                        <p style="margin:10px 0 0 0;font-size:1.1em;">Tu carrito está vacío</p>
                        <small>Agrega productos para comenzar</small>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito']) && $puedeFinalizarCompra): ?>
                <div class="carrito-footer" style="margin-top:10px;">
                    <div class="resumen-compra" style="display:flex;flex-direction:column;gap:4px;">
                        <div class="resumen-item" style="display:flex;justify-content:space-between;">
                            <span>Productos</span>
                            <span><?= $totalItems ?></span>
                        </div>
                        <div class="resumen-item total" style="display:flex;justify-content:space-between;font-weight:700;font-size:1.1em;">
                            <span>Total</span>
                            <span>ARS <?= number_format($totalCompra, 2) ?></span>
                        </div>
                    </div>
                    <div class="carrito-buttons" style="display:flex;gap:10px;margin-top:14px;">
                        <button type="submit" form="carrito-form" class="btn-actualizar" style="flex:1;"><i class="fas fa-sync-alt"></i> Actualizar</button>
                        <a href="#" class="btn-finalizar" style="flex:1;"><i class="fas fa-credit-card"></i> Finalizar Compra</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="productos-container" style="margin-left:410px;padding:120px 32px 32px 32px;width:calc(100% - 410px);">
        <div class="productos-filters" style="display:flex;align-items:center;gap:18px;margin-bottom:22px;">
            <div class="search-box" style="position:relative;flex:1;">
                <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#bbb;"></i>
                <input type="text" placeholder="Buscar productos..." id="search-input" style="width:100%;padding:8px 8px 8px 32px;border-radius:6px;border:1px solid #ddd;">
            </div>
            <div class="filters" style="display:flex;gap:10px;">
                <select id="categoria-filter" style="padding:7px 12px;border-radius:6px;border:1px solid #ddd;">
                    <option value="">Todas las categorías</option>
                    <?php
                    $categorias = $conn->query("SELECT DISTINCT c.id, c.nombre FROM categorias c JOIN productos p ON c.id = p.categoria_id ORDER BY c.nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($categorias as $categoria): ?>
                        <option value="<?= htmlspecialchars($categoria['nombre']) ?>"><?= htmlspecialchars($categoria['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="stock-filter" style="padding:7px 12px;border-radius:6px;border:1px solid #ddd;">
                    <option value="">Todo el stock</option>
                    <option value="disponible">Solo disponibles</option>
                    <option value="agotado">Agotados</option>
                </select>
            </div>
        </div>
        <div class="productos-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:22px;">
            <?php foreach ($productos as $producto): ?>
                <div class="producto-card" data-categoria="<?= htmlspecialchars($producto['categoria_nombre']) ?>" data-stock="<?= $producto['stock_disponible'] > 0 ? 'disponible' : 'agotado' ?>" style="background:white;border-radius:13px;box-shadow:0 4px 18px rgba(0,0,0,0.08);padding:22px 18px 18px 18px;display:flex;flex-direction:column;position:relative;transition:box-shadow 0.2s,transform 0.2s;">
                    <div class="producto-badge" style="position:absolute;top:12px;right:12px;">
                        <?php if ($producto['stock_disponible'] <= 0): ?>
                            <span class="badge agotado" style="background:#e74c3c;color:white;padding:3px 10px;border-radius:8px;font-size:0.85em;font-weight:600;">AGOTADO</span>
                        <?php elseif ($producto['stock_disponible'] < 2): ?>
                            <span class="badge stock-bajo" style="background:#f39c12;color:white;padding:3px 10px;border-radius:8px;font-size:0.85em;font-weight:600;">ÚLTIMAS UNIDADES</span>
                        <?php endif; ?>
                    </div>
                    <div class="producto-img" style="font-size:2.2rem;color:#2d7be5;text-align:center;margin-bottom:10px;">
                        <i class="fas fa-box-open"></i>
                    </div>
                    <div class="producto-content" style="flex:1;">
                        <h3 style="font-size:1.18rem;font-weight:700;margin:0 0 4px 0;"><?= htmlspecialchars($producto['nombre_display']) ?></h3>
                        <?php if ($producto['nombre_display'] !== $producto['producto_nombre_interno']): ?>
                            <small class="nombre-interno-tienda" style="font-size:0.8em; color:#999; display:block; margin-bottom:3px;">(Ref: <?= htmlspecialchars($producto['producto_nombre_interno']) ?>)</small>
                        <?php endif; ?>
                        <span class="producto-categoria" style="font-size:0.98em;color:#888;"><?= htmlspecialchars($producto['categoria_nombre']) ?></span>
                        <?php if (!empty($producto['datos_personalizados'])): ?>
                            <div class="producto-datos" style="margin:7px 0 0 0;">
                                <?php 
                                $datos = explode(', ', $producto['datos_personalizados']);
                                foreach ($datos as $dato): 
                                    if (!empty(trim($dato))):
                                ?>
                                    <span style="display:inline-block;font-size:0.92em;color:#aaa;margin-right:7px;"><i class="fas fa-circle" style="font-size:0.5em;margin-right:3px;"></i> <?= htmlspecialchars($dato) ?></span>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        <?php endif; ?>
                        <div class="producto-stock" style="margin-top:8px;display:flex;align-items:center;gap:12px;">
                            <span style="font-size:0.98em;">Disponibles: <strong><?= number_format($producto['stock_disponible'], 0) ?></strong></span>
                            <?php if (hasRole('admin')): // Solo admin ve el costo ?>
                            <span class="producto-costo" style="font-size:0.93em;color:#888;">Costo: ARS <?= number_format($producto['costo_total_promedio'], 2) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="producto-precio" style="margin-top:10px;">
                        <?php 
                        $precio_a_mostrar = !empty($producto['precio_venta_real']) ? $producto['precio_venta_real'] : $producto['precio_venta_sugerido'];
                        if ($esReventa) {
                            $costo_reventa_bruto = $precio_a_mostrar * (1 - 0.15);
                            $costo_reventa = ceil($costo_reventa_bruto / 100) * 100;
                        ?>
                            <div class="precio-display" style="display:flex;align-items:center;gap:8px;">
                                <span class="precio" style="font-weight:700;color:#2d7be5;font-size:1.18rem;">ARS <?= number_format($costo_reventa, 2) ?></span>
                                <span class="precio-tag" style="background:#5cb85c;color:white;padding:2px 7px;border-radius:5px;font-size:0.85em;font-weight:600;">COSTO REVENTA</span>
                            </div>
                            <div class="precio-sugerido" style="font-size:0.92em;color:#888;margin-top:2px;">
                                <small>Precio Público Sugerido: ARS <?= number_format($precio_a_mostrar, 2) ?></small>
                            </div>
                        <?php
                        } else {
                            $etiqueta_precio = !empty($producto['precio_venta_real']) ? 'CORREGIDO' : 'SUGERIDO';
                            $color_etiqueta = !empty($producto['precio_venta_real']) ? '#27ae60' : '#f39c12';
                        ?>
                            <div class="precio-display" style="display:flex;align-items:center;gap:8px;">
                                <span class="precio" style="font-weight:700;color:#2d7be5;font-size:1.18rem;">ARS <?= number_format($precio_a_mostrar, 2) ?></span>
                                <span class="precio-tag" style="background:<?= $color_etiqueta ?>;color:white;padding:2px 7px;border-radius:5px;font-size:0.85em;font-weight:600;"><?= $etiqueta_precio ?></span>
                            </div>
                            <?php if (!empty($producto['precio_venta_real']) && $producto['precio_venta_real'] != $producto['precio_venta_sugerido']): ?>
                                <div class="precio-sugerido" style="font-size:0.92em;color:#888;margin-top:2px;">
                                    <small>Sugerido Original: ARS <?= number_format($producto['precio_venta_sugerido'], 2) ?></small>
                                </div>
                            <?php endif; ?>
                        <?php
                        }
                        ?>
                    </div>
                    <?php if ($puedeEditarPrecios && !$esReventa): // Solo admin puede editar precios y no para reventa ?>
                    <div class="price-edit" style="margin-top:7px;">
                        <form method="POST" action="actualizar_precio.php">
                            <input type="hidden" name="producto_id" value="<?= $producto['id'] ?>">
                            <div class="input-group" style="display:flex;gap:5px;">
                                <input type="number" name="precio_venta_real" value="<?= $precio_a_mostrar ?>" step="0.01" min="0" placeholder="Nuevo precio" style="width:90px;padding:5px;">
                                <button type="submit" class="btn-save" style="background:#27ae60;color:white;border:none;padding:5px 10px;border-radius:4px;cursor:pointer;"><i class="fas fa-check"></i></button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    <form method="POST" action="agregar_al_carrito.php" class="producto-actions" style="margin-top:12px;">
                        <input type="hidden" name="productos[<?= $producto['id'] ?>]" value="1">
                        <button type="submit" name="agregar" value="<?= $producto['id'] ?>" class="btn-agregar" style="width:100%;background:#2d7be5;color:white;border:none;padding:10px 0;border-radius:7px;font-weight:700;font-size:1em;cursor:pointer;transition:background 0.2s;" <?= $producto['stock_disponible'] <= 0 ? 'disabled style="background:#ccc;cursor:not-allowed;"' : '' ?>>
                            <i class="fas fa-cart-plus"></i> Agregar
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="modalFinalizar" class="modal" style="display:none;">
        <div class="modal-overlay" style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:1001;"></div>
        <div class="modal-container" style="background:white;border-radius:12px;max-width:480px;width:95vw;padding:32px 28px 22px 28px;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:1002;box-shadow:0 8px 32px rgba(0,0,0,0.18);">
            <div class="modal-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
                <h3 style="font-size:1.25rem;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-credit-card" style="color:#2d7be5;"></i> Finalizar Compra
                </h3>
                <span class="close-modal" style="font-size:1.6rem;cursor:pointer;color:#aaa;"><i class="fas fa-times"></i></span>
            </div>
            <div class="modal-tabs" style="display:flex;gap:8px;margin-bottom:18px;">
                <button class="tab-btn active" data-tab="tab-cliente-existente" style="flex:1;padding:10px 0;background:none;border:none;font-weight:700;color:#2d7be5;border-bottom:2px solid #2d7be5;cursor:pointer;display:flex;align-items:center;gap:7px;">
                    <i class="fas fa-user"></i> Cliente Existente
                </button>
                <button class="tab-btn" data-tab="tab-nuevo-cliente" style="flex:1;padding:10px 0;background:none;border:none;font-weight:700;color:#888;border-bottom:2px solid transparent;cursor:pointer;display:flex;align-items:center;gap:7px;">
                    <i class="fas fa-user-plus"></i> Nuevo Cliente
                </button>
            </div>
            <div id="tab-cliente-existente" class="tab-content active">
                <form id="form-cliente-existente">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-weight:600;"><i class="fas fa-user-tag"></i> Seleccionar Cliente:</label>
                        <select name="cliente_id" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #ddd;margin-top:4px;">
                            <option value="">-- Venta como Invitado --</option>
                            <?php
                            // Si tienes un cliente "Invitado", puedes excluirlo aquí si lo deseas
                            $clientes = $conn->query("SELECT id, nombre, red_social FROM clientes ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($clientes as $cliente): ?>
                                <option value="<?= $cliente['id'] ?>">
                                    <?= htmlspecialchars($cliente['nombre']) ?> (<?= htmlspecialchars($cliente['red_social']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-weight:600;"><i class="fas fa-money-bill-wave"></i> Métodos de Pago:</label>
                        <div id="pagos-container-existente" class="pagos-container"></div>
                        <div style="margin-top:7px; display:flex; gap:10px;">
                            <button type="button" class="btn-agregar-pago" data-container="pagos-container-existente" style="background:#2d7be5;color:white;border:none;padding:7px 12px;border-radius:5px;font-weight:600;cursor:pointer;flex:1;">
                                <i class="fas fa-plus-circle"></i> Agregar Método
                            </button>
                            <button type="button" class="btn-autocompletar-pagos" data-container="pagos-container-existente" style="background:#1abc9c;color:white;border:none;padding:7px 12px;border-radius:5px;font-weight:600;cursor:pointer;flex:1;">
                                <i class="fas fa-magic"></i> Autocompletar
                            </button>
                        </div>
                    </div>
                    <!-- Resumen de pagos -->
                    <div class="pago-resumen" style="margin: 15px 0; padding: 12px; background: #f8f9fa; border-radius: 8px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="font-weight: 600;">Total a pagar:</span>
                            <span id="total-a-pagar" style="font-weight: 700; color: #2d7be5;">ARS <?= number_format($totalCompra, 2) ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="font-weight: 600;">Total abonado:</span>
                            <span id="total-abonado" style="font-weight: 700;">ARS 0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="font-weight: 600;" id="saldo-label">Falta abonar:</span>
                            <span id="saldo-vuelto" style="font-weight: 700; color: #e74c3c;">ARS <?= number_format($totalCompra, 2) ?></span>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-weight:600;"><i class="fas fa-user-tie"></i> Venta registrada por:</label>
                        <select name="venta_usuario_id" class="form-input" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #ddd;margin-top:4px;">
                            <option value="<?= $_SESSION['user_id'] ?>">Yo mismo (<?= htmlspecialchars($_SESSION['user_nombre_completo'] ?? $_SESSION['user_username']) ?>)</option>
                            <?php foreach ($lista_usuarios_venta as $u_venta): ?>
                                <?php if ($u_venta['id'] != $_SESSION['user_id']): ?>
                                <option value="<?= $u_venta['id'] ?>">
                                    <?= htmlspecialchars($u_venta['nombre_completo']) ?> (<?= htmlspecialchars($u_venta['username']) ?>)
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small>Seleccione qué usuario figurará como el vendedor de esta operación.</small>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-weight:600;"><i class="fas fa-credit-card"></i> Estado de Pago:</label>
                        <select name="estado_pago" class="form-input" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #ddd;margin-top:4px;" required>
                            <option value="<?= ESTADO_PAGO_FALTA_PAGAR ?>" selected><?= getNombreEstadoPago(ESTADO_PAGO_FALTA_PAGAR) ?></option>
                            <option value="<?= ESTADO_PAGO_PAGADO ?>"><?= getNombreEstadoPago(ESTADO_PAGO_PAGADO) ?></option>
                            <option value="<?= ESTADO_PAGO_A_PAGAR ?>"><?= getNombreEstadoPago(ESTADO_PAGO_A_PAGAR) ?></option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-weight:600;"><i class="fas fa-truck"></i> Estado de Envío:</label>
                        <select name="estado_envio" class="form-input" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #ddd;margin-top:4px;" required>
                            <option value="<?= ESTADO_ENVIO_PENDIENTE ?>" selected><?= getNombreEstadoEnvio(ESTADO_ENVIO_PENDIENTE) ?></option>
                            <option value="<?= ESTADO_ENVIO_RETIRA_PUNTO_ENCUENTRO ?>"><?= getNombreEstadoEnvio(ESTADO_ENVIO_RETIRA_PUNTO_ENCUENTRO) ?></option>
                            <option value="<?= ESTADO_ENVIO_EN_CAMINO ?>"><?= getNombreEstadoEnvio(ESTADO_ENVIO_EN_CAMINO) ?></option>
                            <option value="<?= ESTADO_ENVIO_ENTREGADO ?>"><?= getNombreEstadoEnvio(ESTADO_ENVIO_ENTREGADO) ?></option>
                        </select>
                    </div>
                    <!-- BOTÓN DE CONFIRMAR VENTA PARA CLIENTE EXISTENTE -->
                    <div class="form-actions" style="margin-top:18px;">
                        <button type="submit" class="btn-confirmar-existente" style="width:100%;background:#27ae60;color:white;border:none;padding:12px 0;border-radius:7px;font-weight:700;font-size:1em;cursor:pointer;">
                            <i class="fas fa-check-circle"></i> Confirmar Venta
                        </button>
                    </div>
                </form>
            </div>
            <div id="tab-nuevo-cliente" class="tab-content" style="display:none;">
                <form id="form-nuevo-cliente">
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600;"><i class="fas fa-user"></i> Nombre*:</label>
                        <input type="text" name="nombre" required placeholder="Nombre completo del cliente" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #ddd;margin-top:4px;">
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600;"><i class="fas fa-hashtag"></i> Red Social*:</label>
                        <input type="text" name="red_social" required placeholder="Instagram, WhatsApp, etc." style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #ddd;margin-top:4px;">
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600;"><i class="fas fa-info-circle"></i> Información Adicional:</label>
                        <textarea name="info_adicional" placeholder="Notas sobre el cliente" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #ddd;margin-top:4px;"></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-weight:600;"><i class="fas fa-money-bill-wave"></i> Métodos de Pago:</label>
                        <div id="pagos-container-nuevo" class="pagos-container"></div>
                        <div style="margin-top:7px; display:flex; gap:10px;">
                            <button type="button" class="btn-agregar-pago" data-container="pagos-container-nuevo" style="background:#2d7be5;color:white;border:none;padding:7px 12px;border-radius:5px;font-weight:600;cursor:pointer;flex:1;">
                                <i class="fas fa-plus-circle"></i> Agregar Método
                            </button>
                            <button type="button" class="btn-autocompletar-pagos" data-container="pagos-container-nuevo" style="background:#1abc9c;color:white;border:none;padding:7px 12px;border-radius:5px;font-weight:600;cursor:pointer;flex:1;">
                                <i class="fas fa-magic"></i> Autocompletar
                            </button>
                        </div>
                    </div>
                    <!-- Resumen de pagos -->
                    <div class="pago-resumen" style="margin: 15px 0; padding: 12px; background: #f8f9fa; border-radius: 8px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="font-weight: 600;">Total a pagar:</span>
                            <span id="total-a-pagar" style="font-weight: 700; color: #2d7be5;">ARS <?= number_format($totalCompra, 2) ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="font-weight: 600;">Total abonado:</span>
                            <span id="total-abonado" style="font-weight: 700;">ARS 0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="font-weight: 600;" id="saldo-label">Falta abonar:</span>
                            <span id="saldo-vuelto" style="font-weight: 700; color: #e74c3c;">ARS <?= number_format($totalCompra, 2) ?></span>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-weight:600;"><i class="fas fa-user-tie"></i> Venta registrada por:</label>
                        <select name="venta_usuario_id" class="form-input" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #ddd;margin-top:4px;">
                            <option value="<?= $_SESSION['user_id'] ?>">Yo mismo (<?= htmlspecialchars($_SESSION['user_nombre_completo'] ?? $_SESSION['user_username']) ?>)</option>
                            <?php foreach ($lista_usuarios_venta as $u_venta): ?>
                                <?php if ($u_venta['id'] != $_SESSION['user_id']): ?>
                                <option value="<?= $u_venta['id'] ?>">
                                    <?= htmlspecialchars($u_venta['nombre_completo']) ?> (<?= htmlspecialchars($u_venta['username']) ?>)
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small>Seleccione qué usuario figurará como el vendedor de esta operación.</small>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-weight:600;"><i class="fas fa-credit-card"></i> Estado de Pago:</label>
                        <select name="estado_pago" class="form-input" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #ddd;margin-top:4px;" required>
                            <option value="<?= ESTADO_PAGO_FALTA_PAGAR ?>" selected><?= getNombreEstadoPago(ESTADO_PAGO_FALTA_PAGAR) ?></option>
                            <option value="<?= ESTADO_PAGO_PAGADO ?>"><?= getNombreEstadoPago(ESTADO_PAGO_PAGADO) ?></option>
                            <option value="<?= ESTADO_PAGO_A_PAGAR ?>"><?= getNombreEstadoPago(ESTADO_PAGO_A_PAGAR) ?></option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-weight:600;"><i class="fas fa-truck"></i> Estado de Envío:</label>
                        <select name="estado_envio" class="form-input" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #ddd;margin-top:4px;" required>
                            <option value="<?= ESTADO_ENVIO_PENDIENTE ?>" selected><?= getNombreEstadoEnvio(ESTADO_ENVIO_PENDIENTE) ?></option>
                            <option value="<?= ESTADO_ENVIO_RETIRA_PUNTO_ENCUENTRO ?>"><?= getNombreEstadoEnvio(ESTADO_ENVIO_RETIRA_PUNTO_ENCUENTRO) ?></option>
                            <option value="<?= ESTADO_ENVIO_EN_CAMINO ?>"><?= getNombreEstadoEnvio(ESTADO_ENVIO_EN_CAMINO) ?></option>
                            <option value="<?= ESTADO_ENVIO_ENTREGADO ?>"><?= getNombreEstadoEnvio(ESTADO_ENVIO_ENTREGADO) ?></option>
                        </select>
                    </div>
                    <div class="form-actions" style="margin-top:18px;">
                        <button type="submit" class="btn-confirmar" style="width:100%;background:#27ae60;color:white;border:none;padding:12px 0;border-radius:7px;font-weight:700;font-size:1em;cursor:pointer;">
                            <i class="fas fa-user-plus"></i> Crear Cliente y Confirmar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
    $(document).ready(function() {
        // Filtros de productos
        $('#search-input').on('keyup', function() {
            const searchText = $(this).val().toLowerCase();
            $('.producto-card').each(function() {
                const productText = $(this).text().toLowerCase();
                $(this).toggle(productText.includes(searchText));
            });
        });

        $('#categoria-filter').change(function() {
            const categoria = $(this).val();
            $('.producto-card').each(function() {
                const cardCategoria = $(this).data('categoria');
                if (categoria === '' || cardCategoria === categoria) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        $('#stock-filter').change(function() {
            const stockFilter = $(this).val();
            $('.producto-card').each(function() {
                const cardStock = $(this).data('stock');
                if (stockFilter === '' || cardStock === stockFilter) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Modal handling
        const modal = $('#modalFinalizar');
        const btnFinalizar = $('.btn-finalizar:not(.vaciar)');
        const closeModal = $('.close-modal');
        
        btnFinalizar.on('click', function(e) {
            e.preventDefault();
            // Actualizar el total a pagar en el modal
            const total = <?= isset($totalCompra) ? $totalCompra : 0 ?>;
            $('#total-a-pagar').text('ARS ' + total.toFixed(2));
            $('#total-abonado').text('ARS 0.00');
            $('#saldo-vuelto').text('ARS ' + total.toFixed(2)).css('color', '#e74c3c');
            $('#saldo-label').text('Falta abonar:');
            modal.fadeIn();
        });
        
        closeModal.on('click', function() {
            modal.fadeOut();
        });
        $('.modal-overlay').on('click', function() {
            modal.fadeOut();
        });

        // Tab switching
        $('.tab-btn').on('click', function() {
            const tabId = $(this).data('tab');
            $('.tab-btn').removeClass('active').css({'color':'#888','border-bottom':'2px solid transparent'});
            $(this).addClass('active').css({'color':'#2d7be5','border-bottom':'2px solid #2d7be5'});
            $('.tab-content').removeClass('active').hide();
            $('#' + tabId).addClass('active').show();
        });

        // Form submission
        $('#form-cliente-existente').on('submit', function(e) {
            e.preventDefault();
            procesarVenta(this);
        });
        $('#form-nuevo-cliente').on('submit', function(e) {
            e.preventDefault();
            procesarVenta(this, true);
        });

        // Métodos de pago disponibles
        const metodosPago = [
            { value: 'efectivo', label: 'Efectivo' },
            { value: 'transferencia_joaco', label: 'Transferencia Joaco' },
            { value: 'transferencia', label: 'Transferencia Bancaria' },
            { value: 'mercado_pago', label: 'Mercado Pago' },
            { value: 'otro', label: 'Otro método' }
        ];

        // Función para agregar un método de pago
        function agregarPago(containerId) {
            const container = $('#' + containerId);
            const index = container.children().length;
            const pagoDiv = $('<div class="pago-item" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;"></div>');
            let selectHtml = `<select name="pagos[${index}][metodo]" class="metodo-pago-select" required style="padding:5px 8px;border-radius:4px;border:1px solid #ccc;">
                <option value="">-- Seleccione --</option>`;
            metodosPago.forEach(mp => {
                selectHtml += `<option value="${mp.value}">${mp.label}</option>`;
            });
            selectHtml += `</select>`;
            pagoDiv.html(`
                <div class="pago-metodo">${selectHtml}</div>
                <div class="pago-monto" style="display:flex;align-items:center;gap:3px;">
                    <span style="color:#888;">ARS</span>
                    <input type="number" name="pagos[${index}][monto]" min="0.01" step="0.01" placeholder="Monto" required style="width:80px;padding:5px;border-radius:4px;border:1px solid #ccc;">
                </div>
                <div class="pago-personalizado" style="display:none;">
                    <input type="text" name="pagos[${index}][personalizado]" placeholder="Especifique método" required style="padding:5px;border-radius:4px;border:1px solid #ccc;">
                </div>
                <button type="button" class="btn-eliminar-pago" style="background:#e74c3c;color:white;border:none;border-radius:3px;padding:4px 8px;cursor:pointer;font-size:1em;"><i class="fas fa-times"></i></button>
            `);
            container.append(pagoDiv);

            // Eventos para calcular en vivo
            pagoDiv.find('input[type="number"]').on('input', function() {
                calcularTotales(containerId);
            });
            pagoDiv.find('.metodo-pago-select').on('change', function() {
                calcularTotales(containerId);
                const inputPersonalizado = pagoDiv.find('.pago-personalizado');
                if ($(this).val() === 'otro') {
                    inputPersonalizado.show();
                    inputPersonalizado.find('input').prop('required', true);
                } else {
                    inputPersonalizado.hide();
                    inputPersonalizado.find('input').prop('required', false);
                }
            });
            pagoDiv.find('.btn-eliminar-pago').on('click', function() {
                pagoDiv.remove();
                calcularTotales(containerId);
            });

            // Llamar al cálculo inicial
            calcularTotales(containerId);
        }

        // Agregar estas funciones para calcular los totales
        function calcularTotales(containerId) {
            let totalAbonado = 0;
            $(`#${containerId} .pago-item`).each(function() {
                const monto = parseFloat($(this).find('input[type="number"]').val()) || 0;
                totalAbonado += monto;
            });
            
            const totalAPagar = <?= isset($totalCompra) ? $totalCompra : 0 ?>;
            const diferencia = totalAPagar - totalAbonado;
            
            $('#total-abonado').text('ARS ' + totalAbonado.toFixed(2));
            
            if (diferencia > 0) {
                $('#saldo-label').text('Falta abonar:');
                $('#saldo-vuelto').text('ARS ' + Math.abs(diferencia).toFixed(2)).css('color', '#e74c3c');
            } else if (diferencia < 0) {
                $('#saldo-label').text('Vuelto:');
                $('#saldo-vuelto').text('ARS ' + Math.abs(diferencia).toFixed(2)).css('color', '#27ae60');
            } else {
                $('#saldo-label').text('Pagado completo');
                $('#saldo-vuelto').text('ARS 0.00').css('color', '#27ae60');
            }
        }

        // Inicializar con un método de pago por defecto
        agregarPago('pagos-container-existente');
        agregarPago('pagos-container-nuevo');

        // Botón para agregar más métodos de pago
        $('.btn-agregar-pago').on('click', function(e) {
            e.preventDefault();
            agregarPago($(this).data('container'));
        });

        const totalCompraGlobal = <?= isset($totalCompra) ? $totalCompra : 0 ?>;

        function autocompletarPagos(containerId) {
            const container = $('#' + containerId);
            const pagoItems = container.children('.pago-item');
            const numPagos = pagoItems.length;

            if (numPagos === 0) {
                alert('Primero agregue al menos un método de pago.');
                return;
            }

            const montoPorPago = parseFloat((totalCompraGlobal / numPagos).toFixed(2));
            let montoAcumulado = 0;

            pagoItems.each(function(index) {
                const inputMonto = $(this).find('input[type="number"][name*="[monto]"]');
                if (index === numPagos - 1) {
                    inputMonto.val(parseFloat((totalCompraGlobal - montoAcumulado).toFixed(2)));
                } else {
                    inputMonto.val(montoPorPago);
                    montoAcumulado += montoPorPago;
                }
                inputMonto.trigger('input');
            });

            calcularTotales(containerId);
        }

        $('.btn-autocompletar-pagos').on('click', function(e) {
            e.preventDefault();
            const containerId = $(this).data('container');
            autocompletarPagos(containerId);
        });

        function procesarVenta(form, esNuevoCliente = false) {
            const $submitButton = $(form).find('button[type="submit"]');
            $submitButton.html('<i class="fas fa-spinner fa-spin"></i> Procesando...').prop('disabled', true);
            const formData = new FormData(form);
            const carritoData = <?php echo json_encode($_SESSION['carrito'] ?? []); ?>;
            if (Object.keys(carritoData).length === 0) {
                alert('El carrito está vacío');
                return;
            }
            // Recolectar métodos de pago
            const pagos = [];
            let totalPagos = 0;
            let hasErrors = false;
            $(form).find('.pago-item').each(function() {
                const metodo = $(this).find('.metodo-pago-select').val();
                const monto = parseFloat($(this).find('input[type="number"]').val());
                if (!metodo || isNaN(monto) || monto <= 0) {
                    hasErrors = true;
                    return false;
                }
                let personalizado = '';
                if (metodo === 'otro') {
                    personalizado = $(this).find('.pago-personalizado input').val().trim();
                    if (!personalizado) {
                        hasErrors = true;
                        return false;
                    }
                }
                pagos.push({ metodo, monto, personalizado });
                totalPagos += monto;
            });
            if (hasErrors) {
                alert('Por favor complete correctamente todos los métodos de pago.');
                return;
            }
            if (pagos.length === 0) {
                alert('Debe ingresar al menos un método de pago.');
                return;
            }
            const totalCarrito = <?php echo isset($totalCompra) ? $totalCompra : 0; ?>;
            if (Math.abs(totalPagos - totalCarrito) > 0.01) {
                if (!confirm(`El total de pagos (ARS ${totalPagos.toFixed(2)}) no coincide con el total del carrito (ARS ${totalCarrito.toFixed(2)}). ¿Desea continuar de todas formas?`)) {
                    return;
                }
            }
            formData.append('pagos', JSON.stringify(pagos));
            formData.append('carrito', JSON.stringify(carritoData));
            formData.append('es_nuevo_cliente', esNuevoCliente ? '1' : '0');
            fetch('procesar_venta.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = 'mj_registro_tienda.php?venta_id=' + data.venta_id;
                } else {
                    alert(data.message || 'Error al procesar la venta');
                    // Restaurar el texto original del botón según si es nuevo cliente o existente
                    if (esNuevoCliente) {
                        $submitButton.html('<i class="fas fa-user-plus"></i> Crear Cliente y Confirmar').prop('disabled', false);
                    } else {
                        $submitButton.html('<i class="fas fa-check-circle"></i> Confirmar Venta').prop('disabled', false);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al procesar la venta');
                if (esNuevoCliente) {
                    $submitButton.html('<i class="fas fa-user-plus"></i> Crear Cliente y Confirmar').prop('disabled', false);
                } else {
                    $submitButton.html('<i class="fas fa-check-circle"></i> Confirmar Venta').prop('disabled', false);
                }
            });
        }
    });
    </script>
</body>
</html>

