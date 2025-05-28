<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['producto_id']) && isset($_POST['precio_venta_real'])) {
    $producto_id = intval($_POST['producto_id']);
    $precio_venta_real = trim($_POST['precio_venta_real']);

    // Si el campo está vacío, guardar NULL (eliminar precio real)
    if ($precio_venta_real === '' || !is_numeric($precio_venta_real)) {
        $precio_venta_real = null;
    } else {
        $precio_venta_real = floatval($precio_venta_real);
    }

    try {
        if ($precio_venta_real === null) {
            $stmt = $conn->prepare("UPDATE productos SET precio_venta_real = NULL WHERE id = ?");
            $stmt->execute([$producto_id]);
        } else {
            $stmt = $conn->prepare("UPDATE productos SET precio_venta_real = ? WHERE id = ?");
            $stmt->execute([$precio_venta_real, $producto_id]);
        }
        $_SESSION['mensaje'] = "Precio actualizado correctamente";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al actualizar el precio: " . $e->getMessage();
    }
    header("Location: mj_tienda.php");
    exit();
} else {
    header("Location: mj_tienda.php");
    exit();
}
?>
