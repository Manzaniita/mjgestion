<?php
require_once 'config.php';
session_start();
requireRole('admin'); // Solo admin puede obtener datos para edición
header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['error' => 'ID de producto inválido.']);
    exit;
}

$producto_id = intval($_GET['id']);
$response = [];

try {
    $stmt = $conn->prepare("SELECT id, nombre AS nombre_interno, nombre_visualizacion, categoria_id FROM productos WHERE id = ?");
    $stmt->execute([$producto_id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        echo json_encode(['error' => 'Producto no encontrado.']);
        exit;
    }

    $response = $producto;
    $response['campos_personalizados'] = [];

    $stmt_datos = $conn->prepare("SELECT campo_nombre, valor FROM producto_datos WHERE producto_id = ?");
    $stmt_datos->execute([$producto_id]);
    $datos_person = $stmt_datos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($datos_person as $dato) {
        $response['campos_personalizados'][$dato['campo_nombre']] = $dato['valor'];
    }

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Error en obtener_producto.php: " . $e->getMessage());
    echo json_encode(['error' => 'Error al obtener datos del producto: ' . $e->getMessage()]);
}
?>