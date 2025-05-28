<?php
require_once 'config.php';

// Actualizar stock disponible basado en compras existentes
$productos = $conn->query("SELECT id FROM productos")->fetchAll(PDO::FETCH_ASSOC);

foreach ($productos as $producto) {
    $stock = $conn->query("
        SELECT IFNULL(SUM(cantidad), 0) 
        FROM compras_detalle 
        WHERE producto_id = {$producto['id']}
    ")->fetchColumn();
    
    $conn->exec("UPDATE productos SET stock_disponible = $stock WHERE id = {$producto['id']}");
}

echo "Stock actualizado correctamente";
