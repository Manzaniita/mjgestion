<?php
// config.php
$host = 'localhost';
$dbname = 'c2231876_miweb';
$username = 'c2231876_miweb';
$password = 'Jesus2025';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Crear tablas si no existen
    $sql = "
    CREATE TABLE IF NOT EXISTS categorias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS productos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        categoria_id INT NOT NULL,
        nombre VARCHAR(255) NOT NULL, 
        nombre_visualizacion VARCHAR(255) NULL, 
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        precio_venta_real DECIMAL(12,2) NULL,
        stock_disponible INT DEFAULT 0,
        FOREIGN KEY (categoria_id) REFERENCES categorias(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS campos_personalizados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL UNIQUE,
        tipo VARCHAR(20) NOT NULL DEFAULT 'texto',
        opciones TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS producto_datos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        producto_id INT NOT NULL,
        campo_nombre VARCHAR(100) NOT NULL,
        valor TEXT NOT NULL,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS compras_maestro (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero_pedido VARCHAR(20) UNIQUE,
        fecha_compra DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        costo_envio_ars DECIMAL(12,2) NOT NULL,
        costo_envio_usd DECIMAL(12,2) NOT NULL,
        tasa_envio DECIMAL(12,2) NOT NULL,
        costo_importacion_ars DECIMAL(12,2) NOT NULL,
        costo_importacion_usd DECIMAL(12,2) NOT NULL,
        tasa_importacion DECIMAL(12,2) NOT NULL,
        observaciones TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS compras_detalle (
        id INT AUTO_INCREMENT PRIMARY KEY,
        compra_id INT NOT NULL,
        producto_id INT NOT NULL,
        cantidad INT NOT NULL,
        costo_articulo_ars DECIMAL(12,2) NOT NULL,
        costo_articulo_usd DECIMAL(12,2) NOT NULL,
        tasa_articulo DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (compra_id) REFERENCES compras_maestro(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS clientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        red_social VARCHAR(100) NOT NULL,
        info_adicional TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS ventas_maestro (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        usuario_id INT NULL, 
        fecha_venta DATETIME NOT NULL,
        total DECIMAL(12,2) NOT NULL,
        estado_pago TINYINT NULL DEFAULT 0,
        estado_envio TINYINT NULL DEFAULT 0,
        es_solicitud_reventa BOOLEAN DEFAULT FALSE,
        es_cancelada BOOLEAN DEFAULT FALSE,
        comision_final_manual_admin DECIMAL(10,2) NULL DEFAULT NULL, 
        comision_admin_notas TEXT NULL, 
        FOREIGN KEY (cliente_id) REFERENCES clientes(id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS ventas_detalle (
        id INT AUTO_INCREMENT PRIMARY KEY,
        venta_id INT NOT NULL,
        producto_id INT NOT NULL,
        cantidad INT NOT NULL,
        precio_unitario DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (venta_id) REFERENCES ventas_maestro(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS ventas_pagos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        venta_id INT NOT NULL,
        metodo_pago VARCHAR(50) NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        referencia VARCHAR(255) NULL,
        FOREIGN KEY (venta_id) REFERENCES ventas_maestro(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        nombre_completo VARCHAR(100),
        rol ENUM('admin', 'supervisor', 'reventa') NOT NULL,
        activo BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    
    $conn->exec($sql);

    // Crear usuario admin por defecto si no existe
    $stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE username = 'admin'");
    if ($stmt->fetchColumn() == 0) {
        $admin_pass = password_hash('admin123', PASSWORD_DEFAULT);
        $conn->exec("INSERT INTO usuarios (username, password_hash, nombre_completo, rol) VALUES ('admin', '$admin_pass', 'Administrador Principal', 'admin')");
    }

    // --- NUEVO: Añadir columnas a ventas_maestro y productos si no existen ---
    $columns_vm = $conn->query("DESCRIBE ventas_maestro")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('estado_pago', $columns_vm)) {
        $conn->exec("ALTER TABLE ventas_maestro ADD COLUMN estado_pago TINYINT NULL DEFAULT 0 AFTER total;");
    }
    if (!in_array('estado_envio', $columns_vm)) {
        $conn->exec("ALTER TABLE ventas_maestro ADD COLUMN estado_envio TINYINT NULL DEFAULT 0 AFTER estado_pago;");
    }
    if (!in_array('es_solicitud_reventa', $columns_vm)) {
        $conn->exec("ALTER TABLE ventas_maestro ADD COLUMN es_solicitud_reventa BOOLEAN DEFAULT FALSE AFTER estado_envio;");
    }
    if (!in_array('es_cancelada', $columns_vm)) {
        $conn->exec("ALTER TABLE ventas_maestro ADD COLUMN es_cancelada BOOLEAN DEFAULT FALSE AFTER es_solicitud_reventa;");
    }
    // Si quieres eliminar la columna 'estado' definitivamente, descomenta:
    // if (in_array('estado', $columns_vm)) {
    //     $conn->exec("ALTER TABLE ventas_maestro DROP COLUMN estado;");
    // }
    $columns_p = $conn->query("DESCRIBE productos")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stock_disponible', $columns_p)) {
        $conn->exec("ALTER TABLE productos ADD COLUMN stock_disponible INT DEFAULT 0 AFTER precio_venta_real;");
    }
    // --- FIN NUEVO ---

} catch(PDOException $e) {
    die("Error de conexión o configuración de BD: " . $e->getMessage());
}

define('MARGEN_VENTA_SUGERIDO', 0.40); 
define('DESCUENTO_REVENTA', 0.15); // 15% de descuento

// ESTADO GENERAL DE VENTA (EXISTENTE) - ASEGÚRATE DE QUE ESTÉ DESCOMENTADO POR AHORA
define('ESTADO_VENTA_PENDIENTE_PAGO', 0);
define('ESTADO_VENTA_PAGADA', 1);
define('ESTADO_VENTA_CANCELADA', 2);
define('ESTADO_VENTA_ENVIADA', 3);
define('ESTADO_VENTA_COMPLETADA', 4);
define('ESTADO_VENTA_SOLICITUD_REVENTA', 5);
define('ESTADO_VENTA_SOLICITUD_RECHAZADA', 6);

function getNombreEstadoVenta($estado_id) {
    $estados = [
        ESTADO_VENTA_PENDIENTE_PAGO => 'Pendiente de Pago',
        ESTADO_VENTA_PAGADA => 'Pagada',
        ESTADO_VENTA_CANCELADA => 'Cancelada',
        ESTADO_VENTA_ENVIADA => 'Enviada',
        ESTADO_VENTA_COMPLETADA => 'Completada',
        ESTADO_VENTA_SOLICITUD_REVENTA => 'Solicitud Reventa',
        ESTADO_VENTA_SOLICITUD_RECHAZADA => 'Solicitud Rechazada'
    ];
    return $estados[$estado_id] ?? 'Desconocido (' . $estado_id . ')';
}

// NUEVAS CONSTANTES Y FUNCIONES PARA ESTADO DE PAGO
define('ESTADO_PAGO_FALTA_PAGAR', 0);
define('ESTADO_PAGO_PAGADO', 1);
define('ESTADO_PAGO_A_PAGAR', 2);

function getNombreEstadoPago($estado_pago_id) {
    $estados = [
        ESTADO_PAGO_FALTA_PAGAR => 'Falta Pagar',
        ESTADO_PAGO_PAGADO => 'Pagado',
        ESTADO_PAGO_A_PAGAR => 'A Pagar (Parcial/Crédito)',
    ];
    return $estados[$estado_pago_id] ?? 'Pago Desconocido (' . $estado_pago_id . ')';
}

// NUEVAS CONSTANTES Y FUNCIONES PARA ESTADO DE ENVÍO
define('ESTADO_ENVIO_PENDIENTE', 0);
define('ESTADO_ENVIO_ENTREGADO', 1);
define('ESTADO_ENVIO_RETIRA_PUNTO_ENCUENTRO', 2);
define('ESTADO_ENVIO_EN_CAMINO', 3);

function getNombreEstadoEnvio($estado_envio_id) {
    $estados = [
        ESTADO_ENVIO_PENDIENTE => 'Envío Pendiente',
        ESTADO_ENVIO_ENTREGADO => 'Entregado',
        ESTADO_ENVIO_RETIRA_PUNTO_ENCUENTRO => 'Retira Punto de Encuentro',
        ESTADO_ENVIO_EN_CAMINO => 'En Envío',
    ];
    return $estados[$estado_envio_id] ?? 'Envío Desconocido (' . $estado_envio_id . ')';
}

// --- Funciones de Autenticación y Roles ---
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: mj_login.php");
        exit;
    }
}

function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    return in_array($_SESSION['user_rol'], $roles);
}

function requireRole($roles) {
    requireLogin();
    if (!hasRole($roles)) {
        $_SESSION['login_error'] = "No tiene permisos para acceder a esta página.";
        header("Location: mj_login.php");
        exit;
    }
}
// --- Fin Funciones de Autenticación y Roles ---

// --- NUEVA: Función para calcular comisión de una venta (para reutilizar) ---
function calcularComisionVenta($venta_id, PDO $conn) {
    $stmt_detalles_comision = $conn->prepare("
        SELECT 
            vd.cantidad, 
            vd.precio_unitario AS precio_unitario_cobrado_reventa,
            p.precio_venta_real AS producto_precio_venta_real,
            COALESCE(p.precio_venta_real, 
               ROUND(AVG((cd_costo.costo_articulo_ars + 
                   IFNULL(
                       (cm_costo.costo_importacion_ars + cm_costo.costo_envio_ars) / 
                       NULLIF((SELECT SUM(cd2.cantidad) FROM compras_detalle cd2 WHERE cd2.compra_id = cm_costo.id), 0)
                   , 0)
               )) * (1 + :margen_sugerido) 
               ), 0) AS producto_precio_publico_calculado
        FROM ventas_detalle vd
        JOIN productos p ON vd.producto_id = p.id
        LEFT JOIN compras_detalle cd_costo ON p.id = cd_costo.producto_id
        LEFT JOIN compras_maestro cm_costo ON cd_costo.compra_id = cm_costo.id
        WHERE vd.venta_id = :venta_id
        GROUP BY vd.id, p.id, p.precio_venta_real
    ");
    $stmt_detalles_comision->bindValue(':margen_sugerido', MARGEN_VENTA_SUGERIDO, PDO::PARAM_STR);
    $stmt_detalles_comision->bindValue(':venta_id', $venta_id, PDO::PARAM_INT);
    $stmt_detalles_comision->execute();
    $detalles_productos = $stmt_detalles_comision->fetchAll(PDO::FETCH_ASSOC);

    $comision_calculada = 0;
    if (!empty($detalles_productos)) {
        foreach ($detalles_productos as $detalle) {
            $precio_publico_del_producto = !empty($detalle['producto_precio_venta_real'])
                ? $detalle['producto_precio_venta_real']
                : $detalle['producto_precio_publico_calculado'];
            $comision_item = ($precio_publico_del_producto - $detalle['precio_unitario_cobrado_reventa']);
            if ($comision_item > 0) {
                $comision_calculada += $comision_item * $detalle['cantidad'];
            }
        }
    }
    return $comision_calculada;
}
// --- Fin función calcular comisión ---
?>