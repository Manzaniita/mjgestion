<?php
require_once 'config.php';
session_start();
requireRole('admin'); // Solo admin puede actualizar productos

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['producto_id'], $_POST['nombre_interno'], $_POST['categoria_id'])) {
        $producto_id = intval($_POST['producto_id']);
        $nombre_interno = trim($_POST['nombre_interno']);
        $nombre_visualizacion = isset($_POST['nombre_visualizacion']) ? trim($_POST['nombre_visualizacion']) : null;
        $categoria_id = intval($_POST['categoria_id']);
        $campos_personalizados = $_POST['campos'] ?? [];

        if (empty($nombre_interno) || empty($categoria_id)) {
            header('Location: mj_productos.php?error=Nombre interno y categoría son obligatorios.');
            exit;
        }
        if (empty($nombre_visualizacion)) {
            $nombre_visualizacion = $nombre_interno;
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("UPDATE productos SET nombre = ?, nombre_visualizacion = ?, categoria_id = ? WHERE id = ?");
            $stmt->execute([$nombre_interno, $nombre_visualizacion, $categoria_id, $producto_id]);

            $stmt_delete_datos = $conn->prepare("DELETE FROM producto_datos WHERE producto_id = ?");
            $stmt_delete_datos->execute([$producto_id]);

            if (!empty($campos_personalizados)) {
                foreach ($campos_personalizados as $nombre_campo => $valor) {
                    if ($valor !== '' && $valor !== null) {
                        $stmt_insert_datos = $conn->prepare("INSERT INTO producto_datos (producto_id, campo_nombre, valor) VALUES (?, ?, ?)");
                        $stmt_insert_datos->execute([$producto_id, $nombre_campo, $valor]);
                    }
                }
            }
            
            $conn->commit();
            header('Location: mj_productos.php?mensaje=Producto ID ' . $producto_id . ' actualizado correctamente.');
            exit;

        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error al actualizar producto: " . $e->getMessage());
            header('Location: mj_productos.php?error=Error al actualizar producto: ' . $e->getMessage());
            exit;
        }
    } else {
        header('Location: mj_productos.php?error=Datos incompletos para actualizar.');
        exit;
    }
} else {
    header('Location: mj_productos.php');
    exit;
}
?>