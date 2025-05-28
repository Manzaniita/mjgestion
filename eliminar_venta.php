<?php
session_start();
require_once 'config.php';
requireRole('admin'); // Solo admin puede eliminar ventas

$venta_id = $_GET['id'] ?? null;
$mensaje = '';
$tipo_mensaje = 'error'; // Por defecto error

if (!$venta_id || !filter_var($venta_id, FILTER_VALIDATE_INT)) {
    $mensaje = 'ID de venta inválido.';
    header('Location: mj_registro_tienda.php?mensaje=' . urlencode($mensaje) . '&tipo_mensaje=' . $tipo_mensaje);
    exit;
}

try {
    $conn->beginTransaction();

    // Verificar si la venta existe antes de intentar eliminar
    $stmt_check = $conn->prepare("SELECT id FROM ventas_maestro WHERE id = ?");
    $stmt_check->execute([$venta_id]);
    if (!$stmt_check->fetch()) {
        $conn->rollBack();
        $mensaje = "La venta con ID $venta_id no fue encontrada.";
        header('Location: mj_registro_tienda.php?mensaje=' . urlencode($mensaje) . '&tipo_mensaje=' . $tipo_mensaje);
        exit;
    }

    // 1. Eliminar pagos asociados (si existen)
    $stmt_pagos = $conn->prepare("DELETE FROM ventas_pagos WHERE venta_id = ?");
    $stmt_pagos->execute([$venta_id]);

    // 2. Eliminar detalles de la venta (productos)
    // ANTES DE ELIMINAR, DEVOLVER EL STOCK
    $stmt_get_detalles = $conn->prepare("SELECT producto_id, cantidad FROM ventas_detalle WHERE venta_id = ?");
    $stmt_get_detalles->execute([$venta_id]);
    $detalles_venta = $stmt_get_detalles->fetchAll(PDO::FETCH_ASSOC);

    foreach ($detalles_venta as $detalle) {
        $stmt_update_stock = $conn->prepare("UPDATE productos SET stock_disponible = stock_disponible + ? WHERE id = ?");
        $stmt_update_stock->execute([$detalle['cantidad'], $detalle['producto_id']]);
    }
    // FIN DEVOLVER STOCK

    $stmt_detalles = $conn->prepare("DELETE FROM ventas_detalle WHERE venta_id = ?");
    $stmt_detalles->execute([$venta_id]);

    // 3. Eliminar la venta maestra
    $stmt_maestro = $conn->prepare("DELETE FROM ventas_maestro WHERE id = ?");
    $stmt_maestro->execute([$venta_id]);

    $conn->commit();
    $mensaje = 'Venta ID ' . $venta_id . ' eliminada exitosamente y stock restaurado.';
    $tipo_mensaje = 'exito';

} catch (PDOException $e) {
    $conn->rollBack();
    error_log("Error PDO al eliminar venta ID $venta_id: " . $e->getMessage());
    $mensaje = 'Error de base de datos al eliminar la venta.';
} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error general al eliminar venta ID $venta_id: " . $e->getMessage());
    $mensaje = 'Error general al eliminar la venta.';
}

// Redirigir de vuelta a mj_registro_tienda.php con un mensaje
header('Location: mj_registro_tienda.php?mensaje=' . urlencode($mensaje) . '&tipo_mensaje=' . $tipo_mensaje);
exit;
?>