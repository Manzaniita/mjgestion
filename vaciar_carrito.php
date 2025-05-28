<?php
session_start();

unset($_SESSION['carrito']);

header('Location: mj_tienda.php');
exit;
?>
