<?php
session_start();

if (isset($_GET['id'])) {
    $producto_id = $_GET['id'];
    if (isset($_SESSION['carrito'][$producto_id])) {
        unset($_SESSION['carrito'][$producto_id]);
    }
}

header('Location: mj_tienda.php');
exit;
?>
