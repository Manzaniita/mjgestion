<?php
session_start();
require_once 'config.php';
requireRole('admin'); // Solo Admin puede registrar/editar compras
include 'header.php';

// Verificar y actualizar estructura de la tabla si es necesario
$conn->exec("
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
");

if (isset($_GET['editar'])) {
    $compra_id = $_GET['editar'];
    $compra = $conn->query("SELECT * FROM compras_maestro WHERE id = $compra_id")->fetch(PDO::FETCH_ASSOC);
    $detalles = $conn->query("SELECT * FROM compras_detalle WHERE compra_id = $compra_id")->fetchAll(PDO::FETCH_ASSOC);
}

// Procesar el formulario de compra
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_compra'])) {
    try {
        $conn->beginTransaction();
        
        // Obtener o generar número de pedido
        $numero_pedido = trim($_POST['numero_pedido']);
        if (empty($numero_pedido)) {
            // Verificar si hay pedidos existentes
            $stmt = $conn->query("SELECT COUNT(*) as total FROM compras_maestro");
            $total_pedidos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($total_pedidos > 0) {
                $ultimo_pedido = $conn->query("SELECT numero_pedido FROM compras_maestro ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $ultimo_numero = (int)preg_replace('/[^0-9]/', '', $ultimo_pedido['numero_pedido']);
                $numero_pedido = 'PED-' . str_pad($ultimo_numero + 1, 5, '0', STR_PAD_LEFT);
            } else {
                $numero_pedido = 'PED-00001';
            }
        }

        // Validar que el número de pedido no exista
        $stmt = $conn->prepare("SELECT id FROM compras_maestro WHERE numero_pedido = ?");
        $stmt->execute([$numero_pedido]);
        if ($stmt->fetch()) {
            throw new Exception("El número de pedido $numero_pedido ya existe");
        }

        // Validar tasas generales
        $tasa_importacion = (float)$_POST['tasa_usd_importacion'];
        $tasa_envio = (float)$_POST['tasa_usd_envio'];
        if ($tasa_importacion <= 0 || $tasa_envio <= 0) {
            throw new Exception("Todas las tasas USD deben ser mayores a cero");
        }

        // Calcular costos generales
        $costo_importacion_ars = ($_POST['moneda_importacion'] === 'ARS') ? 
            (float)$_POST['costo_importacion'] : 
            (float)$_POST['costo_importacion'] * $tasa_importacion;
        
        $costo_importacion_usd = ($_POST['moneda_importacion'] === 'USD') ? 
            (float)$_POST['costo_importacion'] : 
            $costo_importacion_ars / $tasa_importacion;

        $costo_envio_ars = ($_POST['moneda_envio'] === 'ARS') ? 
            (float)$_POST['costo_envio'] : 
            (float)$_POST['costo_envio'] * $tasa_envio;
        
        $costo_envio_usd = ($_POST['moneda_envio'] === 'USD') ? 
            (float)$_POST['costo_envio'] : 
            $costo_envio_ars / $tasa_envio;

        // Insertar en compras_maestro
        $stmt = $conn->prepare("
            INSERT INTO compras_maestro (
                numero_pedido, fecha_compra, costo_envio_ars, costo_envio_usd, tasa_envio,
                costo_importacion_ars, costo_importacion_usd, tasa_importacion, observaciones
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $numero_pedido,
            $_POST['fecha_compra'], 
            $costo_envio_ars, 
            $costo_envio_usd, 
            $tasa_envio,
            $costo_importacion_ars, 
            $costo_importacion_usd, 
            $tasa_importacion,
            $_POST['observaciones'] ?? null
        ]);
        $compra_id = $conn->lastInsertId();

        // Insertar productos en compras_detalle
        if (!empty($_POST['productos'])) {
            foreach ($_POST['productos'] as $producto) {
                if (!isset($producto['producto_id'], $producto['cantidad'], $producto['costo_articulo'], $producto['moneda_articulo'], $producto['tasa_usd_articulo'])) {
                    throw new Exception("Faltan datos requeridos para uno de los productos");
                }

                $tasa_articulo = (float)$producto['tasa_usd_articulo'];
                if ($tasa_articulo <= 0) {
                    throw new Exception("La tasa USD para el producto debe ser mayor a cero");
                }

                $costo_articulo_ars = ($producto['moneda_articulo'] === 'ARS') ? 
                    (float)$producto['costo_articulo'] : 
                    (float)$producto['costo_articulo'] * $tasa_articulo;
                
                $costo_articulo_usd = ($producto['moneda_articulo'] === 'USD') ? 
                    (float)$producto['costo_articulo'] : 
                    $costo_articulo_ars / $tasa_articulo;

                $stmt = $conn->prepare("
                    INSERT INTO compras_detalle (
                        compra_id, producto_id, cantidad, 
                        costo_articulo_ars, costo_articulo_usd, tasa_articulo
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $compra_id, 
                    $producto['producto_id'], 
                    $producto['cantidad'],
                    $costo_articulo_ars, 
                    $costo_articulo_usd, 
                    $tasa_articulo
                ]);

                // Actualizar stock disponible
                $stmt = $conn->prepare("UPDATE productos SET stock_disponible = stock_disponible + ? WHERE id = ?");
                $stmt->execute([$producto['cantidad'], $producto['producto_id']]);
            }
        } else {
            throw new Exception("Debe agregar al menos un producto");
        }

        $conn->commit();
        $mensaje = "Compra registrada correctamente con número de pedido: $numero_pedido";
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error al registrar la compra: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compra_id'])) {
    try {
        $conn->beginTransaction();

        // Validar tasas generales
        $tasa_importacion = (float)$_POST['tasa_usd_importacion'];
        $tasa_envio = (float)$_POST['tasa_usd_envio'];
        if ($tasa_importacion <= 0 || $tasa_envio <= 0) {
            throw new Exception("Todas las tasas USD deben ser mayores a cero");
        }

        // Calcular costos generales
        $costo_importacion_ars = ($_POST['moneda_importacion'] === 'ARS') ? 
            (float)$_POST['costo_importacion'] : 
            (float)$_POST['costo_importacion'] * $tasa_importacion;
        
        $costo_importacion_usd = ($_POST['moneda_importacion'] === 'USD') ? 
            (float)$_POST['costo_importacion'] : 
            $costo_importacion_ars / $tasa_importacion;

        $costo_envio_ars = ($_POST['moneda_envio'] === 'ARS') ? 
            (float)$_POST['costo_envio'] : 
            (float)$_POST['costo_envio'] * $tasa_envio;
        
        $costo_envio_usd = ($_POST['moneda_envio'] === 'USD') ? 
            (float)$_POST['costo_envio'] : 
            $costo_envio_ars / $tasa_envio;

        // Update purchase master record
        $stmt = $conn->prepare("UPDATE compras_maestro SET numero_pedido = ?, fecha_compra = ?, costo_envio_ars = ?, costo_envio_usd = ?, tasa_envio = ?, costo_importacion_ars = ?, costo_importacion_usd = ?, tasa_importacion = ?, observaciones = ? WHERE id = ?");
        $stmt->execute([
            $_POST['numero_pedido'], $_POST['fecha_compra'], $costo_envio_ars, $costo_envio_usd, $tasa_envio,
            $costo_importacion_ars, $costo_importacion_usd, $tasa_importacion, $_POST['observaciones'], $_POST['compra_id']
        ]);

        // Delete existing purchase details
        $stmt = $conn->prepare("DELETE FROM compras_detalle WHERE compra_id = ?");
        $stmt->execute([$_POST['compra_id']]);

        // Insert updated purchase details
        foreach ($_POST['productos'] as $producto) {
            if (!isset($producto['producto_id'], $producto['cantidad'], $producto['costo_articulo'], $producto['moneda_articulo'], $producto['tasa_usd_articulo'])) {
                throw new Exception("Faltan datos requeridos para uno de los productos");
            }

            $tasa_articulo = (float)$producto['tasa_usd_articulo'];
            if ($tasa_articulo <= 0) {
                throw new Exception("La tasa USD para el producto debe ser mayor a cero");
            }

            $costo_articulo_ars = ($producto['moneda_articulo'] === 'ARS') ? 
                (float)$producto['costo_articulo'] : 
                (float)$producto['costo_articulo'] * $tasa_articulo;
            
            $costo_articulo_usd = ($producto['moneda_articulo'] === 'USD') ? 
                (float)$producto['costo_articulo'] : 
                $costo_articulo_ars / $tasa_articulo;

            $stmt = $conn->prepare("
                INSERT INTO compras_detalle (
                    compra_id, producto_id, cantidad, 
                    costo_articulo_ars, costo_articulo_usd, tasa_articulo
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['compra_id'], 
                $producto['producto_id'], 
                $producto['cantidad'],
                $costo_articulo_ars, 
                $costo_articulo_usd, 
                $tasa_articulo
            ]);
        }

        $conn->commit();
        $mensaje = "Compra actualizada correctamente.";
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error al actualizar la compra: " . $e->getMessage();
    }
}

// Obtener todos los productos para el selector
$productos = $conn->query("
    SELECT 
        p.id, 
        p.nombre AS producto_nombre, 
        c.nombre AS categoria_nombre,
        GROUP_CONCAT(
            CONCAT(pd.campo_nombre, ': ', pd.valor) 
            SEPARATOR ', '
        ) AS datos_personalizados
    FROM productos p
    JOIN categorias c ON p.categoria_id = c.id
    LEFT JOIN producto_datos pd ON p.id = pd.producto_id
    GROUP BY p.id
    ORDER BY c.nombre, p.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// Obtener historial de compras
$compras = $conn->query("
    SELECT cm.*, cd.*, p.nombre AS producto_nombre, c.nombre AS categoria_nombre
    FROM compras_maestro cm
    JOIN compras_detalle cd ON cm.id = cd.compra_id
    JOIN productos p ON cd.producto_id = p.id
    JOIN categorias c ON p.categoria_id = c.id
    ORDER BY cm.fecha_compra DESC, cm.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro Compras - Sistema de Inventario</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/compras.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-shopping-cart"></i> Registro de Compras</h1>
        </div>
        
        <?php if (isset($mensaje)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= $mensaje ?>
                <button type="button" class="close-alert">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
                <button type="button" class="close-alert">&times;</button>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-plus-circle"></i> <?= isset($compra) ? 'Editar Compra' : 'Nueva Compra' ?></h2>
            </div>
            <div class="card-body">
                <form method="post" id="form-compra">
                    <input type="hidden" name="compra_id" value="<?= $compra['id'] ?? '' ?>">
                    
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label for="fecha_compra"><i class="far fa-calendar-alt"></i> Fecha de Compra</label>
                            <input type="date" id="fecha_compra" name="fecha_compra" class="form-control" required value="<?= $compra['fecha_compra'] ?? date('Y-m-d') ?>">
                        </div>
                        
                        <div class="form-group col-md-3">
                            <label for="numero_pedido"><i class="fas fa-hashtag"></i> Número de Pedido</label>
                            <input type="text" id="numero_pedido" name="numero_pedido" class="form-control" value="<?= $compra['numero_pedido'] ?? '' ?>" placeholder="Ej: PED-00001">
                            <small class="form-text text-muted">Si se deja vacío, se generará automáticamente</small>
                        </div>
                        
                        <div class="form-group col-md-6">
                            <label for="observaciones"><i class="far fa-comment-alt"></i> Observaciones</label>
                            <textarea id="observaciones" name="observaciones" class="form-control" rows="1"><?= $compra['observaciones'] ?? '' ?></textarea>
                        </div>
                    </div>
                    
                    <div class="section-title">
                        <h3><i class="fas fa-dollar-sign"></i> Costos Generales</h3>
                        <div class="section-divider"></div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label><i class="fas fa-boxes"></i> Costo de Importación</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <select name="moneda_importacion" class="currency-select" required>
                                        <option value="ARS" <?= isset($compra['costo_importacion_ars']) ? 'selected' : '' ?>>ARS</option>
                                        <option value="USD" <?= isset($compra['costo_importacion_usd']) ? 'selected' : '' ?>>USD</option>
                                    </select>
                                </div>
                                <input type="number" name="costo_importacion" class="form-control" step="0.01" min="0" placeholder="Monto" required value="<?= $compra['costo_importacion_ars'] ?? $compra['costo_importacion_usd'] ?? '' ?>">
                            </div>
                            <div class="rate-box">
                                <label for="tasa_usd_importacion"><i class="fas fa-exchange-alt"></i> Tasa USD (1 USD = X ARS)</label>
                                <input type="number" id="tasa_usd_importacion" name="tasa_usd_importacion" class="form-control" step="0.01" min="0.01" required value="<?= $compra['tasa_importacion'] ?? '1' ?>">
                            </div>
                        </div>
                        <div class="form-group col-md-6">
                            <label><i class="fas fa-truck"></i> Costo de Envío</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <select name="moneda_envio" class="currency-select" required>
                                        <option value="ARS" <?= isset($compra['costo_envio_ars']) ? 'selected' : '' ?>>ARS</option>
                                        <option value="USD" <?= isset($compra['costo_envio_usd']) ? 'selected' : '' ?>>USD</option>
                                    </select>
                                </div>
                                <input type="number" name="costo_envio" class="form-control" step="0.01" min="0" placeholder="Monto" required value="<?= $compra['costo_envio_ars'] ?? $compra['costo_envio_usd'] ?? '' ?>">
                            </div>
                            <div class="rate-box">
                                <label for="tasa_usd_envio"><i class="fas fa-exchange-alt"></i> Tasa USD (1 USD = X ARS)</label>
                                <input type="number" id="tasa_usd_envio" name="tasa_usd_envio" class="form-control" step="0.01" min="0.01" required value="<?= $compra['tasa_envio'] ?? '1' ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="section-title">
                        <h3><i class="fas fa-box-open"></i> Productos</h3>
                        <div class="section-divider"></div>
                    </div>
                    
                    <div id="productos-container" class="products-container">
                        <?php if (isset($detalles)): ?>
                            <?php foreach ($detalles as $index => $detalle): ?>
                                <div class="product-item">
                                    <div class="product-select">
                                        <select name="productos[<?= $index ?>][producto_id]" class="form-control" required>
                                            <option value="">Seleccione un producto</option>
                                            <?php foreach ($productos as $producto): ?>
                                                <option value="<?= $producto['id'] ?>" <?= $producto['id'] == $detalle['producto_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($producto['categoria_nombre']) ?> - <?= htmlspecialchars($producto['producto_nombre']) ?>
                                                    <?php if (!empty($producto['datos_personalizados'])): ?>
                                                        (<?= htmlspecialchars($producto['datos_personalizados']) ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="product-quantity">
                                        <input type="number" name="productos[<?= $index ?>][cantidad]" class="form-control" min="1" required placeholder="Cantidad" value="<?= $detalle['cantidad'] ?>">
                                    </div>
                                    <div class="product-cost">
                                        <input type="number" name="productos[<?= $index ?>][costo_articulo]" class="form-control" step="0.01" required placeholder="Costo" value="<?= $detalle['costo_articulo_ars'] ?? $detalle['costo_articulo_usd'] ?>">
                                    </div>
                                    <div class="product-currency">
                                        <select name="productos[<?= $index ?>][moneda_articulo]" class="form-control" required>
                                            <option value="ARS" <?= isset($detalle['costo_articulo_ars']) ? 'selected' : '' ?>>ARS</option>
                                            <option value="USD" <?= isset($detalle['costo_articulo_usd']) ? 'selected' : '' ?>>USD</option>
                                        </select>
                                    </div>
                                    <div class="product-rate">
                                        <input type="number" name="productos[<?= $index ?>][tasa_usd_articulo]" class="form-control" step="0.01" min="0.01" required placeholder="Tasa USD" value="<?= $detalle['tasa_articulo'] ?? '1' ?>">
                                    </div>
                                    <div class="product-actions">
                                        <button type="button" class="btn btn-danger btn-sm delete-product">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group text-right">
                        <button type="button" id="agregar-producto" class="btn btn-secondary">
                            <i class="fas fa-plus"></i> Agregar Producto
                        </button>
                    </div>
                    
                    <div class="form-submit">
                        <button type="submit" name="registrar_compra" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?= isset($compra) ? 'Actualizar Compra' : 'Registrar Compra' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-history"></i> Historial de Compras Recientes</h2>
                <div class="card-actions">
                    <button class="btn btn-sm btn-outline-secondary" id="refresh-table">
                        <i class="fas fa-sync-alt"></i> Actualizar
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($compras)): ?>
                    <div class="empty-state">
                        <i class="fas fa-shopping-cart fa-3x"></i>
                        <p>No hay compras registradas aún.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>N° Pedido</th>
                                    <th>Producto</th>
                                    <th class="text-center">Cantidad</th>
                                    <th class="text-right">Costo Unitario</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($compras as $compra): ?>
                                    <tr>
                                        <td><?= date('d/m/Y', strtotime($compra['fecha_compra'])) ?></td>
                                        <td>
                                            <span class="badge badge-pedido">
                                                <?= htmlspecialchars($compra['numero_pedido']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="product-info">
                                                <span class="product-category"><?= htmlspecialchars($compra['categoria_nombre']) ?></span>
                                                <span class="product-name"><?= htmlspecialchars($compra['producto_nombre']) ?></span>
                                            </div>
                                        </td>
                                        <td class="text-center"><?= $compra['cantidad'] ?></td>
                                        <td class="text-right">
                                            <div class="currency-display">
                                                <span class="currency-ars">ARS <?= number_format($compra['costo_articulo_ars'], 2) ?></span>
                                                <span class="currency-usd">USD <?= number_format($compra['costo_articulo_usd'], 2) ?></span>
                                            </div>
                                        </td>
                                        <td class="text-right">
                                            <?php 
                                            $total_ars = $compra['costo_articulo_ars'] * $compra['cantidad'];
                                            $total_usd = $compra['costo_articulo_usd'] * $compra['cantidad'];
                                            ?>
                                            <div class="currency-display">
                                                <span class="currency-ars">ARS <?= number_format($total_ars, 2) ?></span>
                                                <span class="currency-usd">USD <?= number_format($total_usd, 2) ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        let productoCounter = <?= isset($detalles) ? count($detalles) : 0 ?>;

        document.getElementById('agregar-producto').addEventListener('click', function() {
            const container = document.getElementById('productos-container');
            const index = productoCounter++;

            const template = `
                <div class="product-item">
                    <div class="product-select">
                        <select name="productos[${index}][producto_id]" class="form-control" required>
                            <option value="">Seleccione un producto</option>
                            <?php foreach ($productos as $producto): ?>
                                <option value="<?= $producto['id'] ?>">
                                    <?= htmlspecialchars($producto['categoria_nombre']) ?> - <?= htmlspecialchars($producto['producto_nombre']) ?>
                                    <?php if (!empty($producto['datos_personalizados'])): ?>
                                        (<?= htmlspecialchars($producto['datos_personalizados']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="product-quantity">
                        <input type="number" name="productos[${index}][cantidad]" class="form-control" min="1" required placeholder="Cantidad">
                    </div>
                    <div class="product-cost">
                        <input type="number" name="productos[${index}][costo_articulo]" class="form-control" step="0.01" required placeholder="Costo">
                    </div>
                    <div class="product-currency">
                        <select name="productos[${index}][moneda_articulo]" class="form-control" required>
                            <option value="ARS">ARS</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                    <div class="product-rate">
                        <input type="number" name="productos[${index}][tasa_usd_articulo]" class="form-control" step="0.01" min="0.01" required placeholder="Tasa USD" value="1">
                    </div>
                    <div class="product-actions">
                        <button type="button" class="btn btn-danger btn-sm delete-product">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>`;
            
            container.insertAdjacentHTML('beforeend', template);

            container.lastElementChild.querySelector('.delete-product').addEventListener('click', function() {
                this.parentElement.remove();
            });
        });

        // Agregar un producto por defecto al cargar la página
        window.addEventListener('load', function() {
            if (!<?= isset($detalles) ? 'true' : 'false' ?>) {
                document.getElementById('agregar-producto').click();
            }
        });
    </script>
</body>
</html>