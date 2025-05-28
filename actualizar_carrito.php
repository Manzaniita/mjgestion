<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cantidades'])) {
    foreach ($_POST['cantidades'] as $producto_id => $cantidad) {
        $cantidad = intval($cantidad);
        if ($cantidad > 0) {
            $_SESSION['carrito'][$producto_id] = $cantidad;
        } else {
            unset($_SESSION['carrito'][$producto_id]);
        }
    }
    $_SESSION['mensaje'] = "Carrito actualizado correctamente";
} else {
    $_SESSION['error'] = "Error al actualizar el carrito";
}

header('Location: mj_tienda.php');
exit;
?>
