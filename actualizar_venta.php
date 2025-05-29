<?php
// ...existing code...
foreach ($pagos_post as $pago_data) {
    $metodo_final_pago = $pago_data['metodo'];
    $referencia_final_pago = $pago_data['referencia_pago'] ?? null;
    if ($pago_data['metodo'] === 'otro') {
        $metodo_final_pago = trim($pago_data['metodo_otro_descripcion']);
        if (empty($metodo_final_pago)) {
            $metodo_final_pago = 'Otro (detalle en referencia)';
        }
    }
    $stmt_insert_pago->execute([
        $venta_id,
        $metodo_final_pago,
        $pago_data['monto'],
        $referencia_final_pago
    ]);
}
// ...existing code...