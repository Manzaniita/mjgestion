<?php
session_start();
require_once 'config.php';
requireRole('admin'); // Solo administradores pueden eliminar ventas definitivamente

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

    // 1. Verificar si la venta existe antes de intentar eliminar
    $stmt_check = $conn->prepare("SELECT id, es_cancelada FROM ventas_maestro WHERE id = ?");
    $stmt_check->execute([$venta_id]);
    $venta_existente = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$venta_existente) {
        $conn->rollBack();
        $mensaje = "La venta con ID $venta_id no fue encontrada.";
        header('Location: mj_registro_tienda.php?mensaje=' . urlencode($mensaje) . '&tipo_mensaje=' . $tipo_mensaje);
        exit;
    }

    // 2. Restaurar el stock de los productos SOLO SI LA VENTA NO ESTABA YA CANCELADA
    if (!$venta_existente['es_cancelada']) {
        $stmt_get_detalles = $conn->prepare("SELECT producto_id, cantidad FROM ventas_detalle WHERE venta_id = ?");
        $stmt_get_detalles->execute([$venta_id]);
        $detalles_venta = $stmt_get_detalles->fetchAll(PDO::FETCH_ASSOC);

        foreach ($detalles_venta as $detalle) {
            $stmt_update_stock = $conn->prepare("UPDATE productos SET stock_disponible = stock_disponible + ? WHERE id = ?");
            $stmt_update_stock->execute([$detalle['cantidad'], $detalle['producto_id']]);
        }
        $stock_mensaje_parte = " y stock restaurado";
    } else {
        $stock_mensaje_parte = " (el stock ya había sido restaurado al cancelarse previamente)";
    }

    // 3. Eliminar pagos asociados (si existen)
    $stmt_pagos = $conn->prepare("DELETE FROM ventas_pagos WHERE venta_id = ?");
    $stmt_pagos->execute([$venta_id]);

    // 4. Eliminar detalles de la venta (productos)
    $stmt_detalles_delete = $conn->prepare("DELETE FROM ventas_detalle WHERE venta_id = ?");
    $stmt_detalles_delete->execute([$venta_id]);

    // 5. Eliminar la venta maestra
    $stmt_maestro = $conn->prepare("DELETE FROM ventas_maestro WHERE id = ?");
    $stmt_maestro->execute([$venta_id]);

    $conn->commit();
    $mensaje = 'Venta ID ' . $venta_id . ' eliminada permanentemente' . $stock_mensaje_parte . '.';
    $tipo_mensaje = 'exito';

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Error PDO al eliminar definitivamente venta ID $venta_id: " . $e->getMessage());
    $mensaje = 'Error de base de datos al eliminar la venta.';
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Error general al eliminar definitivamente venta ID $venta_id: " . $e->getMessage());
    $mensaje = 'Error general al eliminar la venta.';
}

header('Location: mj_registro_tienda.php?mensaje=' . urlencode($mensaje) . '&tipo_mensaje=' . $tipo_mensaje);
exit;
?>
