<?php
session_start();
require_once 'config.php';
requireRole('admin'); // Solo Admin puede acceder a esta página
include 'header.php';

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Crear nueva categoría
    if (isset($_POST['nueva_categoria'])) {
        $nombre = trim($_POST['nueva_categoria']);
        if (!empty($nombre)) {
            try {
                $stmt = $conn->prepare("INSERT INTO categorias (nombre) VALUES (?)");
                $stmt->execute([$nombre]);
                $categoria_msg = "Categoría '$nombre' creada!";
            } catch (PDOException $e) {
                $categoria_error = "Error: La categoría ya existe o hay un problema con la base de datos";
            }
        }
    }
    
    // Agregar nuevo campo personalizado
    if (isset($_POST['nuevo_campo']) && isset($_POST['tipo_campo'])) {
        $nombre = trim($_POST['nuevo_campo']);
        $tipo = $_POST['tipo_campo'];
        $opciones_campo = ($tipo === 'select' && isset($_POST['opciones'])) ? trim($_POST['opciones']) : null;

        if (!empty($nombre)) {
            try {
                // Asegúrate de que tu tabla campos_personalizados tenga una columna 'opciones'
                // Si no la tiene, agrégala: ALTER TABLE campos_personalizados ADD COLUMN opciones TEXT NULL;
                $stmt = $conn->prepare("INSERT INTO campos_personalizados (nombre, tipo, opciones) VALUES (?, ?, ?)");
                $stmt->execute([$nombre, $tipo, $opciones_campo]);
                $campo_msg = "Campo '$nombre' agregado!";
            } catch (PDOException $e) {
                // $campo_error = "Error: El campo ya existe o hay un problema con la base de datos. " . $e->getMessage(); // Para depuración
                $campo_error = "Error: El campo ya existe o hay un problema con la base de datos";
            }
        }
    }
    
    // Registrar nuevo producto
    if (isset($_POST['registrar_producto'])) {
        try {
            $conn->beginTransaction();
            
            // Insertar producto básico
            $nombre_producto_interno = $_POST['nombre_producto'];
            $nombre_producto_visualizacion = !empty(trim($_POST['nombre_visualizacion'])) ? trim($_POST['nombre_visualizacion']) : $nombre_producto_interno; // Usa el interno si el de visualización está vacío

            $stmt = $conn->prepare("INSERT INTO productos (categoria_id, nombre, nombre_visualizacion) VALUES (?, ?, ?)");
            $stmt->execute([
                $_POST['categoria_id'],
                $nombre_producto_interno,
                $nombre_producto_visualizacion
            ]);
            $producto_id = $conn->lastInsertId();
            
            // Insertar campos personalizados
            if (isset($_POST['campos'])) {
                foreach ($_POST['campos'] as $nombre_campo => $valor) {
                    if (!empty($valor)) {
                        $stmt = $conn->prepare("INSERT INTO producto_datos (producto_id, campo_nombre, valor) VALUES (?, ?, ?)");
                        $stmt->execute([$producto_id, $nombre_campo, $valor]);
                    }
                }
            }
            
            $conn->commit();
            $producto_msg = "Producto registrado correctamente (ID: $producto_id)!";
        } catch (PDOException $e) {
            $conn->rollBack();
            $producto_error = "Error al registrar producto: " . $e->getMessage();
        }
    }
}

// Obtener datos para los selectores
$categorias = $conn->query("SELECT * FROM categorias ORDER BY nombre")->fetchAll();
$campos = $conn->query("SELECT * FROM campos_personalizados ORDER BY nombre")->fetchAll();

// Función PHP para iconos según tipo de campo
function getFieldIcon($type) {
    $icons = [
        'texto' => 'font',
        'numero' => 'calculator',
        'color' => 'palette',
        'fecha' => 'calendar',
        'select' => 'list-ul' // Cambiado para que se vea mejor
    ];
    return $icons[$type] ?? 'pencil-alt';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Compras - Productos</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/alta_productos.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header-section">
            <h1><i class="fas fa-boxes"></i> Gestión de Compras - Productos</h1>
            <p class="subtitle">Administración completa del catálogo de productos</p>
        </div>
        
        <div class="dashboard-grid">
            <!-- Panel para crear categorías -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h2><i class="fas fa-tags"></i> Nueva Categoría</h2>
                </div>
                <div class="card-body">
                    <?php if (isset($categoria_msg)): ?>
                        <div class="alert alert-success"><?= $categoria_msg ?></div>
                    <?php elseif (isset($categoria_error)): ?>
                        <div class="alert alert-error"><?= $categoria_error ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" class="form-elegant">
                        <div class="form-group">
                            <label for="nueva_categoria"><i class="fas fa-tag"></i> Nombre de la categoría</label>
                            <input type="text" id="nueva_categoria" name="nueva_categoria" class="form-input" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Crear Categoría
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Panel para agregar campos personalizados -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h2><i class="fas fa-list-alt"></i> Campos Personalizados</h2>
                </div>
                <div class="card-body">
                    <?php if (isset($campo_msg)): ?>
                        <div class="alert alert-success"><?= $campo_msg ?></div>
                    <?php elseif (isset($campo_error)): ?>
                        <div class="alert alert-error"><?= $campo_error ?></div>
                    <?php endif; ?>

                    <form method="POST" class="form-elegant">
                        <div class="form-group">
                            <label for="nuevo_campo"><i class="fas fa-pencil-alt"></i> Nombre del campo</label>
                            <input type="text" id="nuevo_campo" name="nuevo_campo" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label for="tipo_campo"><i class="fas fa-cog"></i> Tipo de campo</label>
                            <select id="tipo_campo" name="tipo_campo" class="form-input" required>
                                <option value="texto">Texto</option>
                                <option value="numero">Número</option>
                                <option value="color">Color</option>
                                <option value="fecha">Fecha</option>
                                <option value="select">Lista desplegable</option>
                            </select>
                        </div>
                        <div class="form-group" id="opciones-container" style="display: none;">
                            <label for="opciones"><i class="fas fa-list-ol"></i> Opciones (separadas por comas)</label>
                            <input type="text" id="opciones" name="opciones" class="form-input" placeholder="Ej: Opción1,Opción2,Opción3">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Agregar Campo
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Panel para registrar productos -->
            <div class="dashboard-card wide-card">
                <div class="card-header">
                    <h2><i class="fas fa-box-open"></i> Registrar Nuevo Producto</h2>
                </div>
                <div class="card-body">
                    <?php if (isset($producto_msg)): ?>
                        <div class="alert alert-success"><?= $producto_msg ?></div>
                    <?php elseif (isset($producto_error)): ?>
                        <div class="alert alert-error"><?= $producto_error ?></div>
                    <?php endif; ?>

                    <form method="POST" class="form-elegant">
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label for="nombre_producto"><i class="fas fa-signature"></i> Nombre Interno del Producto</label>
                                <input type="text" id="nombre_producto" name="nombre_producto" class="form-input" required placeholder="Ej: Remera Algodón S Rojo Mod XYZ">
                            </div>
                            <div class="form-group col-md-4">
                                <label for="nombre_visualizacion"><i class="fas fa-eye"></i> Nombre de Visualización (Público)</label>
                                <input type="text" id="nombre_visualizacion" name="nombre_visualizacion" class="form-input" placeholder="Ej: Remera Roja de Algodón">
                                <small class="form-text">Si se deja vacío, se usará el nombre interno.</small>
                            </div>
                            <div class="form-group col-md-4">
                                <label for="categoria_id"><i class="fas fa-folder-open"></i> Categoría</label>
                                <select id="categoria_id" name="categoria_id" class="form-input" required>
                                    <option value="">Seleccione una categoría</option>
                                    <?php foreach ($categorias as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="section-divider">
                            <span>Campos Personalizados</span>
                        </div>

                        <div class="campos-dinamicos">
                            <?php if (empty($campos)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-info-circle"></i>
                                    <p>No hay campos personalizados definidos. Agrega algunos en el panel de la izquierda.</p>
                                </div>
                            <?php else: ?>
                                <div class="fields-grid">
                                    <?php foreach ($campos as $campo): ?>
                                        <div class="field-item">
                                            <label for="campo_<?= htmlspecialchars($campo['nombre']) ?>">
                                                <i class="fas fa-<?= getFieldIcon($campo['tipo']) ?>"></i> 
                                                <?= htmlspecialchars($campo['nombre']) ?>
                                                <span class="field-type">(<?= htmlspecialchars($campo['tipo']) ?>)</span>
                                            </label>
                                            <?php if ($campo['tipo'] === 'texto'): ?>
                                                <input type="text" name="campos[<?= htmlspecialchars($campo['nombre']) ?>]" 
                                                       id="campo_<?= htmlspecialchars($campo['nombre']) ?>" class="form-input">
                                            <?php elseif ($campo['tipo'] === 'numero'): ?>
                                                <input type="number" name="campos[<?= htmlspecialchars($campo['nombre']) ?>]" 
                                                       id="campo_<?= htmlspecialchars($campo['nombre']) ?>" class="form-input" step="any">
                                            <?php elseif ($campo['tipo'] === 'color'): ?>
                                                <div class="color-picker-container">
                                                    <input type="color" name="campos[<?= htmlspecialchars($campo['nombre']) ?>]" 
                                                           id="campo_<?= htmlspecialchars($campo['nombre']) ?>" class="color-picker" value="#ffffff">
                                                </div>
                                            <?php elseif ($campo['tipo'] === 'fecha'): ?>
                                                <input type="date" name="campos[<?= htmlspecialchars($campo['nombre']) ?>]" 
                                                       id="campo_<?= htmlspecialchars($campo['nombre']) ?>" class="form-input">
                                            <?php elseif ($campo['tipo'] === 'select'): ?>
                                                <select name="campos[<?= htmlspecialchars($campo['nombre']) ?>]" 
                                                        id="campo_<?= htmlspecialchars($campo['nombre']) ?>" class="form-input">
                                                    <option value="">-- Seleccione --</option>
                                                    <?php 
                                                    if (!empty($campo['opciones'])):
                                                        $opciones_select = explode(',', $campo['opciones']);
                                                        foreach ($opciones_select as $opcion): 
                                                            $opcion_val = trim($opcion);
                                                            if (!empty($opcion_val)): ?>
                                                                <option value="<?= htmlspecialchars($opcion_val) ?>"><?= htmlspecialchars($opcion_val) ?></option>
                                                            <?php endif;
                                                        endforeach; 
                                                    endif;
                                                    ?>
                                                </select>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="registrar_producto" class="btn btn-success">
                                <i class="fas fa-save"></i> Registrar Producto
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tipoCampoSelect = document.getElementById('tipo_campo');
            const opcionesContainer = document.getElementById('opciones-container');
            const opcionesInput = document.getElementById('opciones');

            if (tipoCampoSelect) {
                tipoCampoSelect.addEventListener('change', function() {
                    if (this.value === 'select') {
                        opcionesContainer.style.display = 'block';
                        opcionesInput.required = true;
                    } else {
                        opcionesContainer.style.display = 'none';
                        opcionesInput.required = false;
                    }
                });
                // Trigger change on load in case 'select' is pre-selected
                if (tipoCampoSelect.value === 'select') {
                    opcionesContainer.style.display = 'block';
                    opcionesInput.required = true;
                }
            }
        });
    </script>
</body>
</html>