<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar'])) {
    $producto_id = $_POST['agregar'];
    
    if (!isset($_SESSION['carrito'])) {
        $_SESSION['carrito'] = [];
    }

    if (isset($_SESSION['carrito'][$producto_id])) {
        $_SESSION['carrito'][$producto_id] += 1;
    } else {
        $_SESSION['carrito'][$producto_id] = 1;
    }
}

header('Location: mj_tienda.php');
exit;
?>
