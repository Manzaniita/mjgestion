<?php
session_start();
require_once 'config.php';
requireRole(['admin', 'supervisor', 'reventa']); // Roles que pueden procesar una venta

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$esReventa = hasRole('reventa');
$esAdminOSupervisor = hasRole(['admin', 'supervisor']);

try {
    $conn->beginTransaction();

    // 1. Determinar el ID del usuario que registra la venta
    $usuario_que_registra_id = $_SESSION['user_id']; // Por defecto, el usuario logueado
    if (hasRole('admin') && isset($_POST['venta_usuario_id']) && !empty($_POST['venta_usuario_id'])) {
        $usuario_que_registra_id = (int)$_POST['venta_usuario_id'];
    }

    // 1. Manejar cliente (nuevo o existente)
    $cliente_id = null;
    if (isset($_POST['es_nuevo_cliente']) && $_POST['es_nuevo_cliente'] === '1') {
        if (empty($_POST['nombre']) || empty($_POST['red_social'])) {
            throw new Exception("Nombre y Red Social son obligatorios para nuevos clientes.");
        }
        $stmt = $conn->prepare("INSERT INTO clientes (nombre, red_social, info_adicional) VALUES (?, ?, ?)");
        $stmt->execute([
            $_POST['nombre'],
            $_POST['red_social'],
            $_POST['info_adicional'] ?? null
        ]);
        $cliente_id = $conn->lastInsertId();
    } else {
        // Procesar cliente existente o invitado
        $cliente_id_recibido = $_POST['cliente_id'] ?? '';
        if (empty($cliente_id_recibido)) {
            // Buscar cliente "Invitado"
            $stmt_invitado = $conn->prepare("SELECT id FROM clientes WHERE nombre = 'Cliente Invitado' LIMIT 1");
            $stmt_invitado->execute();
            $cliente_invitado_data = $stmt_invitado->fetch(PDO::FETCH_ASSOC);
            if ($cliente_invitado_data) {
                $cliente_id = $cliente_invitado_data['id'];
            } else {
                // Crear cliente "Invitado" si no existe
                $stmt_crear_invitado = $conn->prepare("INSERT INTO clientes (nombre, red_social, info_adicional) VALUES ('Cliente Invitado', 'N/A', 'Cliente por defecto para ventas rápidas')");
                $stmt_crear_invitado->execute();
                $cliente_id = $conn->lastInsertId();
            }
        } else {
            $cliente_id = (int)$cliente_id_recibido;
        }
    }

    // 2. Calcular totales y preparar detalles de la venta
    $totalVentaFinal = 0;
    $detallesParaInsertar = [];

    $carritoData = isset($_POST['carrito']) ? json_decode($_POST['carrito'], true) : null;
    if (!$carritoData || empty($carritoData)) {
        throw new Exception("El carrito está vacío.");
    }
    $idsProductosCarrito = array_keys($carritoData);
    if (empty($idsProductosCarrito)) {
        throw new Exception("El carrito está vacío.");
    }
    $placeholders = implode(',', array_fill(0, count($idsProductosCarrito), '?'));
    $margen_sugerido_factor = 1 + MARGEN_VENTA_SUGERIDO;

    $sqlProd = "
        SELECT p.id, 
               COALESCE(p.precio_venta_real, 
                   ROUND(AVG(
                       cd.costo_articulo_ars + 
                       IFNULL(
                           (cm.costo_importacion_ars + cm.costo_envio_ars) / 
                           NULLIF((SELECT SUM(cd2.cantidad) FROM compras_detalle cd2 WHERE cd2.compra_id = cm.id), 0)
                       , 0)
                   ) * {$margen_sugerido_factor}, 2)
               , 0) AS precio_base_publico
        FROM productos p
        LEFT JOIN compras_detalle cd ON p.id = cd.producto_id
        LEFT JOIN compras_maestro cm ON cd.compra_id = cm.id
        WHERE p.id IN ({$placeholders})
        GROUP BY p.id, p.precio_venta_real
    ";
    $stmtProd = $conn->prepare($sqlProd);
    $stmtProd->execute($idsProductosCarrito);
    $productosInfo = [];
    while ($row = $stmtProd->fetch(PDO::FETCH_ASSOC)) {
        $productosInfo[$row['id']] = $row['precio_base_publico'];
    }

    foreach ($carritoData as $producto_id => $cantidad_item) {
        if (!isset($productosInfo[$producto_id])) {
            $stmtProdNombre = $conn->prepare("SELECT nombre FROM productos WHERE id = ?");
            $stmtProdNombre->execute([$producto_id]);
            $nombreProdError = $stmtProdNombre->fetchColumn();
            $errorMsg = "Producto ID $producto_id" . ($nombreProdError ? " ('" . htmlspecialchars($nombreProdError) . "')" : "") . " no encontrado o sin datos de precio suficientes (ej. sin compras registradas para calcular costo/sugerido).";
            throw new Exception($errorMsg);
        }
        $precio_base_publico = $productosInfo[$producto_id];
        if ($esReventa) {
            $costo_reventa_bruto = $precio_base_publico * (1 - DESCUENTO_REVENTA);
            $precioUnitarioParaGuardar = ceil($costo_reventa_bruto / 100) * 100;
        } else {
            $precioUnitarioParaGuardar = $precio_base_publico;
        }
        $totalVentaFinal += $precioUnitarioParaGuardar * $cantidad_item;
        $detallesParaInsertar[] = [
            'producto_id' => $producto_id,
            'cantidad' => $cantidad_item,
            'precio_unitario' => $precioUnitarioParaGuardar
        ];
    }

    // 3. Determinar estado de la venta
    $estado_pago_venta = $_POST['estado_pago'] ?? ESTADO_PAGO_FALTA_PAGAR;
    $estado_envio_venta = $_POST['estado_envio'] ?? ESTADO_ENVIO_PENDIENTE;
    $es_solicitud_reventa_venta = hasRole('reventa') ? true : false;

    // 4. Insertar en ventas_maestro
    $stmt_venta_maestro = $conn->prepare(
        "INSERT INTO ventas_maestro (cliente_id, usuario_id, fecha_venta, total, estado_pago, estado_envio, es_solicitud_reventa, es_cancelada) 
         VALUES (?, ?, NOW(), ?, ?, ?, ?, FALSE)"
    );
    $stmt_venta_maestro->execute([
        $cliente_id, 
        $usuario_que_registra_id, 
        $totalVentaFinal, 
        $estado_pago_venta,
        $estado_envio_venta,
        $es_solicitud_reventa_venta
    ]);
    $venta_id = $conn->lastInsertId();

    // 5. Insertar en ventas_detalle
    $stmtDetail = $conn->prepare("INSERT INTO ventas_detalle (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
    foreach ($detallesParaInsertar as $detalle) {
        $stmtDetail->execute([$venta_id, $detalle['producto_id'], $detalle['cantidad'], $detalle['precio_unitario']]);
    }

    // 6. Insertar en ventas_pagos
    $pagosData = isset($_POST['pagos']) ? json_decode($_POST['pagos'], true) : [];
    $stmtPagos = $conn->prepare("INSERT INTO ventas_pagos (venta_id, metodo_pago, monto, referencia) VALUES (?, ?, ?, ?)");
    foreach ($pagosData as $pago) {
        $metodo = $pago['metodo'];
        if ($metodo === 'otro') {
            $metodo = $pago['personalizado'] ?? 'Otro (no especificado)';
        }
        $stmtPagos->execute([$venta_id, $metodo, $pago['monto'], null]);
    }

    $conn->commit();
    unset($_SESSION['carrito']);
    echo json_encode(['success' => true, 'venta_id' => $venta_id, 'message' => 'Venta registrada exitosamente.']);

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error al procesar la venta: ' . $e->getMessage()]);
}
?>
