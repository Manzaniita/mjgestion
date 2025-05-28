<?php
require_once 'config.php';
session_start();
requireRole('admin'); // Solo admin puede eliminar compras

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compra_id'])) {
    try {
        $compra_id = $_POST['compra_id'];
        $conn->beginTransaction();

        // Delete purchase details first
        $stmt = $conn->prepare("DELETE FROM compras_detalle WHERE compra_id = ?");
        $stmt->execute([$compra_id]);

        // Delete the purchase master record
        $stmt = $conn->prepare("DELETE FROM compras_maestro WHERE id = ?");
        $stmt->execute([$compra_id]);

        $conn->commit();
        $_SESSION['mensaje'] = "Compra eliminada correctamente.";
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error al eliminar la compra: " . $e->getMessage();
    }
}

header('Location: mj_registro_compras.php');
exit;
