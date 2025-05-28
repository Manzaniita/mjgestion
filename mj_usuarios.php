<?php
session_start();
require_once 'config.php';
requireRole('admin'); // Solo administradores pueden acceder
include 'header.php';

$mensaje = '';
$error = '';

// --- PROCESAMIENTO DE ACCIONES POST ---

// Procesar alta de usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_usuario'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $nombre_completo = trim($_POST['nombre_completo']);
    $rol = $_POST['rol'];

    if (empty($username) || empty($password) || empty($rol) || empty($nombre_completo)) {
        $error = "Todos los campos son obligatorios.";
    } elseif ($password !== $confirm_password) {
        $error = "Las contraseñas no coinciden.";
    } elseif (strlen($password) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres.";
    } else {
        // Verificar si el usuario ya existe
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = "El nombre de usuario '$username' ya está en uso.";
        } else {
            try {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO usuarios (username, password_hash, nombre_completo, rol) VALUES (?, ?, ?, ?)");
                $stmt->execute([$username, $password_hash, $nombre_completo, $rol]);
                $mensaje = "Usuario '$username' creado exitosamente.";
            } catch (PDOException $e) {
                $error = "Error al crear usuario: " . $e->getMessage();
            }
        }
    }
}

// Procesar cambio de estado (activar/desactivar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado_usuario'])) {
    $user_id_estado = $_POST['user_id_estado'];
    $nuevo_estado = $_POST['nuevo_estado']; // 1 para activo, 0 para inactivo

    // No permitir desactivar el usuario admin actual si es el único admin activo
    if ($_SESSION['user_id'] == $user_id_estado && $nuevo_estado == 0) {
        $stmt_check_admins = $conn->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = TRUE AND id != " . (int)$user_id_estado);
        if ($stmt_check_admins->fetchColumn() == 0) {
            $error = "No puede desactivar al único administrador activo.";
        }
    }

    if (empty($error)) {
        try {
            $stmt = $conn->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
            $stmt->execute([$nuevo_estado, $user_id_estado]);
            $mensaje = "Estado del usuario actualizado.";
        } catch (PDOException $e) {
            $error = "Error al cambiar estado: " . $e->getMessage();
        }
    }
}

// NUEVO: Procesar cambio de contraseña de usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_password_usuario'])) {
    $user_id_pass = $_POST['user_id_password'];
    $new_password = $_POST['new_password'];
    $confirm_new_password = $_POST['confirm_new_password'];

    if (empty($new_password) || empty($confirm_new_password)) {
        $error = "Todos los campos de contraseña son obligatorios para el cambio.";
    } elseif ($new_password !== $confirm_new_password) {
        $error = "Las nuevas contraseñas no coinciden.";
    } elseif (strlen($new_password) < 6) {
        $error = "La nueva contraseña debe tener al menos 6 caracteres.";
    } else {
        try {
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
            $stmt->execute([$new_password_hash, $user_id_pass]);
            $mensaje = "Contraseña del usuario ID: $user_id_pass actualizada exitosamente.";
        } catch (PDOException $e) {
            $error = "Error al actualizar la contraseña: " . $e->getMessage();
        }
    }
}

// NUEVO: Procesar eliminación de usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_usuario'])) {
    $user_id_eliminar = $_POST['user_id_eliminar'];
    $can_delete = true;

    if ($_SESSION['user_id'] == $user_id_eliminar) {
        $error = "No puede eliminarse a sí mismo.";
        $can_delete = false;
    }

    if ($can_delete) {
        $stmt_user_role = $conn->prepare("SELECT rol FROM usuarios WHERE id = ?");
        $stmt_user_role->execute([$user_id_eliminar]);
        $user_to_delete_role = $stmt_user_role->fetchColumn();

        if ($user_to_delete_role === 'admin') {
            $stmt_check_admins = $conn->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = TRUE");
            if ($stmt_check_admins->fetchColumn() <= 1) {
                $error = "No puede eliminar al único administrador activo.";
                $can_delete = false;
            }
        }
    }

    if ($can_delete) {
        try {
            $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id_eliminar]);
            $mensaje = "Usuario ID: $user_id_eliminar eliminado exitosamente.";
        } catch (PDOException $e) {
            $error = "Error al eliminar usuario: " . $e->getMessage();
        }
    }
}

// NUEVO: Procesar actualización de cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_cliente'])) {
    $cliente_id_actualizar = $_POST['edit_cliente_id'];
    $nombre_cliente = trim($_POST['edit_cliente_nombre']);
    $red_social_cliente = trim($_POST['edit_cliente_red_social']);
    $info_adicional_cliente = trim($_POST['edit_cliente_info_adicional']);

    if (empty($nombre_cliente) || empty($red_social_cliente)) {
        $error = "El nombre y la red social del cliente son obligatorios.";
    } else {
        try {
            $stmt = $conn->prepare("UPDATE clientes SET nombre = ?, red_social = ?, info_adicional = ? WHERE id = ?");
            $stmt->execute([$nombre_cliente, $red_social_cliente, $info_adicional_cliente, $cliente_id_actualizar]);
            $mensaje = "Cliente ID: $cliente_id_actualizar actualizado exitosamente.";
        } catch (PDOException $e) {
            $error = "Error al actualizar cliente: " . $e->getMessage();
        }
    }
}

// NUEVO: Procesar eliminación de cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_cliente'])) {
    $cliente_id_eliminar = $_POST['cliente_id_eliminar'];
    
    $stmt_check_ventas = $conn->prepare("SELECT COUNT(*) FROM ventas_maestro WHERE cliente_id = ?");
    $stmt_check_ventas->execute([$cliente_id_eliminar]);
    if ($stmt_check_ventas->fetchColumn() > 0) {
        $error = "No se puede eliminar el cliente ID: $cliente_id_eliminar porque tiene ventas asociadas. Considere editar sus datos o contactar al soporte.";
    } else {
        try {
            $stmt = $conn->prepare("DELETE FROM clientes WHERE id = ?");
            $stmt->execute([$cliente_id_eliminar]);
            $mensaje = "Cliente ID: $cliente_id_eliminar eliminado exitosamente.";
        } catch (PDOException $e) {
            $error = "Error al eliminar cliente: " . $e->getMessage();
        }
    }
}

// --- OBTENCIÓN DE DATOS (después de procesar POST para reflejar cambios) ---
$usuarios = $conn->query("SELECT id, username, nombre_completo, rol, activo, created_at FROM usuarios ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

$clientes_registrados = [];
try {
    $clientes_registrados = $conn->query("SELECT id, nombre, red_social, info_adicional, created_at FROM clientes ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_clientes = "Error al cargar clientes: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios y Clientes | MJ Gestión</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Estilos básicos para mj_usuarios.php */
        .container { max-width: 1000px; margin: 20px auto; padding: 20px; }
        .user-management-grid { display: grid; grid-template-columns: 1fr; gap: 30px; }
        @media (min-width: 768px) {
            .user-management-grid { grid-template-columns: 300px 1fr; }
        }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { padding: 15px 20px; border-bottom: 1px solid #eee; font-size: 1.2em; font-weight: 600; }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.95em;
            transition: background-color 0.2s;
        }
        .btn-primary { background-color: #3498db; color: white; }
        .btn-primary:hover { background-color: #2980b9; }
        .btn-success { background-color: #2ecc71; color: white; }
        .btn-success:hover { background-color: #27ae60; }
        .btn-danger { background-color: #e74c3c; color: white; }
        .btn-danger:hover { background-color: #c0392b; }
        .btn-warning { background-color: #f39c12; color: white; }
        .btn-warning:hover { background-color: #e67e22; }
        .btn-info { background-color: #17a2b8; color: white; }
        .btn-info:hover { background-color: #138496; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #f8f9fa; font-weight: 600; }
        .badge { padding: 3px 8px; border-radius: 12px; font-size: 0.8em; color: white; }
        .badge-admin { background-color: #e74c3c; }
        .badge-supervisor { background-color: #f39c12; }
        .badge-reventa { background-color: #3498db; }
        .status-active { color: #2ecc71; font-weight: bold; }
        .status-inactive { color: #e74c3c; font-weight: bold; }

        /* Estilos para Pestañas */
        .tab-container {
            overflow: hidden;
            border-bottom: 1px solid #ccc;
            margin-bottom: 20px;
        }
        .tab-container button {
            background-color: inherit;
            float: left;
            border: none;
            outline: none;
            cursor: pointer;
            padding: 14px 16px;
            transition: 0.3s;
            font-size: 1.1em;
            font-weight: 500;
            color: #555;
            border-bottom: 3px solid transparent;
        }
        .tab-container button:hover {
            background-color: #f1f1f1;
            border-bottom: 3px solid #ddd;
        }
        .tab-container button.active {
            background-color: #fff;
            color: #3498db;
            border-bottom: 3px solid #3498db;
            font-weight: 600;
        }
        .tab-content {
            display: none;
            padding: 6px 0;
        }
        .tab-content.active {
            display: block;
        }
        .table-actions button, .table-actions a {
            margin-right: 5px;
            padding: 5px 8px;
            font-size: 0.9em;
        }

        /* Estilos para Modales */
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6);
            align-items: center; justify-content: center;
        }
        .modal-content {
            background-color: #fefefe; margin: auto; padding: 25px; border: 1px solid #888;
            width: 90%; max-width: 500px; border-radius: 8px; position: relative;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .modal-content h2 {
            margin-top: 0; color: #333; border-bottom: 1px solid #eee;
            padding-bottom: 10px; margin-bottom: 20px;
        }
        .close-modal-btn {
            color: #aaa; float: right; font-size: 28px; font-weight: bold;
            position: absolute; top: 10px; right: 15px;
        }
        .close-modal-btn:hover, .close-modal-btn:focus {
            color: black; text-decoration: none; cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-cogs"></i> Administración</h1>

        <?php if ($mensaje): ?><div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (isset($error_clientes)): ?><div class="alert alert-error"><?= htmlspecialchars($error_clientes) ?></div><?php endif; ?>

        <!-- Contenedor de Pestañas -->
        <div class="tab-container">
            <button class="tab-link active" onclick="openTab(event, 'gestionUsuarios')"><i class="fas fa-users-cog"></i> Gestión de Usuarios</button>
            <button class="tab-link" onclick="openTab(event, 'listaClientes')"><i class="fas fa-address-book"></i> Lista de Clientes</button>
        </div>

        <!-- Pestaña: Gestión de Usuarios -->
        <div id="gestionUsuarios" class="tab-content active">
            <div class="user-management-grid">
                <div class="card">
                    <div class="card-header"><i class="fas fa-user-plus"></i> Crear Nuevo Usuario</div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="form-group">
                                <label for="nombre_completo">Nombre Completo:</label>
                                <input type="text" id="nombre_completo" name="nombre_completo" required>
                            </div>
                            <div class="form-group">
                                <label for="username">Nombre de Usuario:</label>
                                <input type="text" id="username" name="username" required>
                            </div>
                            <div class="form-group">
                                <label for="password">Contraseña:</label>
                                <input type="password" id="password" name="password" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirmar Contraseña:</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                            <div class="form-group">
                                <label for="rol">Rol:</label>
                                <select id="rol" name="rol" required>
                                    <option value="reventa">Reventa</option>
                                    <option value="supervisor">Supervisor</option>
                                    <option value="admin">Administrador</option>
                                </select>
                            </div>
                            <button type="submit" name="crear_usuario" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Crear Usuario</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><i class="fas fa-list-ul"></i> Lista de Usuarios</div>
                    <div class="card-body">
                        <?php if (empty($usuarios)): ?>
                            <p>No hay usuarios registrados.</p>
                        <?php else: ?>
                            <div style="overflow-x:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Usuario</th>
                                        <th>Nombre Completo</th>
                                        <th>Rol</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($usuarios as $usuario): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($usuario['username']) ?></td>
                                        <td><?= htmlspecialchars($usuario['nombre_completo']) ?></td>
                                        <td>
                                            <span class="badge badge-<?= htmlspecialchars($usuario['rol']) ?>">
                                                <?= ucfirst(htmlspecialchars($usuario['rol'])) ?>
                                            </span>
                                        </td>
                                        <td class="<?= $usuario['activo'] ? 'status-active' : 'status-inactive' ?>">
                                            <?= $usuario['activo'] ? 'Activo' : 'Inactivo' ?>
                                        </td>
                                        <td class="table-actions">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="user_id_estado" value="<?= $usuario['id'] ?>">
                                                <?php if ($usuario['activo']): ?>
                                                    <input type="hidden" name="nuevo_estado" value="0">
                                                    <button type="submit" name="cambiar_estado_usuario" class="btn btn-warning btn-sm" title="Desactivar">
                                                        <i class="fas fa-user-slash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <input type="hidden" name="nuevo_estado" value="1">
                                                    <button type="submit" name="cambiar_estado_usuario" class="btn btn-success btn-sm" title="Activar">
                                                        <i class="fas fa-user-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                            <button type="button" class="btn btn-info btn-sm" onclick="openChangePasswordModal(<?= $usuario['id'] ?>, '<?= htmlspecialchars($usuario['username'], ENT_QUOTES) ?>')" title="Cambiar Contraseña"><i class="fas fa-key"></i></button>
                                            <?php if ($_SESSION['user_id'] != $usuario['id']): ?>
                                            <form method="POST" onsubmit="return confirm('¿Está seguro de eliminar al usuario \'<?= htmlspecialchars($usuario['username'], ENT_QUOTES) ?>\' (ID: <?= $usuario['id'] ?>)? Esta acción es irreversible.')" style="display:inline;">
                                                <input type="hidden" name="user_id_eliminar" value="<?= $usuario['id'] ?>">
                                                <button type="submit" name="eliminar_usuario" class="btn btn-danger btn-sm" title="Eliminar Usuario"><i class="fas fa-user-times"></i></button>
                                            </form>
                                            <?php endif; ?>
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
        </div>

        <!-- Pestaña: Lista de Clientes -->
        <div id="listaClientes" class="tab-content">
            <div class="card">
                <div class="card-header"><i class="fas fa-address-book"></i> Lista de Clientes Registrados</div>
                <div class="card-body">
                    <?php if (empty($clientes_registrados) && !isset($error_clientes)): ?>
                        <p>No hay clientes registrados.</p>
                    <?php elseif (isset($error_clientes)): ?>
                        <p>Error al cargar la lista de clientes.</p>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nombre</th>
                                    <th>Red Social</th>
                                    <th>Info Adicional</th>
                                    <th>Registrado el</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clientes_registrados as $cliente): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cliente['id']) ?></td>
                                    <td><?= htmlspecialchars($cliente['nombre']) ?></td>
                                    <td><?= htmlspecialchars($cliente['red_social']) ?></td>
                                    <td><?= nl2br(htmlspecialchars($cliente['info_adicional'] ?? 'N/A')) ?></td>
                                    <td><?= isset($cliente['created_at']) ? date('d/m/Y H:i', strtotime($cliente['created_at'])) : 'N/A' ?></td>
                                    <td class="table-actions">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openEditClienteModal(<?= $cliente['id'] ?>, '<?= htmlspecialchars($cliente['nombre'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cliente['red_social'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cliente['info_adicional'] ?? '', ENT_QUOTES) ?>')" title="Editar Cliente"><i class="fas fa-edit"></i></button>
                                        <form method="POST" onsubmit="return confirm('¿Está seguro de eliminar al cliente \'<?= htmlspecialchars($cliente['nombre'], ENT_QUOTES) ?>\' (ID: <?= $cliente['id'] ?>)? Si tiene ventas asociadas, no se podrá eliminar.')" style="display:inline;">
                                            <input type="hidden" name="cliente_id_eliminar" value="<?= $cliente['id'] ?>">
                                            <button type="submit" name="eliminar_cliente" class="btn btn-danger btn-sm" title="Eliminar Cliente"><i class="fas fa-trash"></i></button>
                                        </form>
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
    </div>

    <!-- Modal Editar Cliente -->
    <div id="editClienteModal" class="modal">
        <div class="modal-content">
            <span class="close-modal-btn" onclick="closeEditClienteModal()">×</span>
            <h2><i class="fas fa-user-edit"></i> Editar Cliente</h2>
            <form method="POST">
                <input type="hidden" id="edit_cliente_id" name="edit_cliente_id">
                <div class="form-group">
                    <label for="edit_cliente_nombre">Nombre:</label>
                    <input type="text" id="edit_cliente_nombre" name="edit_cliente_nombre" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="edit_cliente_red_social">Red Social:</label>
                    <input type="text" id="edit_cliente_red_social" name="edit_cliente_red_social" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="edit_cliente_info_adicional">Información Adicional:</label>
                    <textarea id="edit_cliente_info_adicional" name="edit_cliente_info_adicional" class="form-control" rows="3"></textarea>
                </div>
                <button type="submit" name="actualizar_cliente" class="btn btn-success"><i class="fas fa-save"></i> Guardar Cambios</button>
            </form>
        </div>
    </div>

    <!-- Modal Cambiar Contraseña Usuario -->
    <div id="changePasswordModal" class="modal">
        <div class="modal-content">
            <span class="close-modal-btn" onclick="closeChangePasswordModal()">×</span>
            <h2><i class="fas fa-key"></i> Cambiar Contraseña para <span id="usernameForPasswordChange" style="font-weight:normal;"></span></h2>
            <form method="POST">
                <input type="hidden" id="user_id_password" name="user_id_password">
                <div class="form-group">
                    <label for="new_password">Nueva Contraseña:</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="confirm_new_password">Confirmar Nueva Contraseña:</label>
                    <input type="password" id="confirm_new_password" name="confirm_new_password" class="form-control" required>
                </div>
                <button type="submit" name="cambiar_password_usuario" class="btn btn-success"><i class="fas fa-save"></i> Cambiar Contraseña</button>
            </form>
        </div>
    </div>

    <script>
    function openTab(evt, tabName) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) {
            tabcontent[i].style.display = "none";
            tabcontent[i].classList.remove("active");
        }
        tablinks = document.getElementsByClassName("tab-link");
        for (i = 0; i < tablinks.length; i++) {
            tablinks[i].classList.remove("active");
        }
        document.getElementById(tabName).style.display = "block";
        document.getElementById(tabName).classList.add("active");
        evt.currentTarget.classList.add("active");
    }

    // Funciones para Modales de Clientes
    function openEditClienteModal(id, nombre, red, info) {
        document.getElementById('edit_cliente_id').value = id;
        document.getElementById('edit_cliente_nombre').value = nombre;
        document.getElementById('edit_cliente_red_social').value = red;
        document.getElementById('edit_cliente_info_adicional').value = info;
        document.getElementById('editClienteModal').style.display = 'flex';
    }
    function closeEditClienteModal() {
        document.getElementById('editClienteModal').style.display = 'none';
    }

    // Funciones para Modales de Usuarios (Contraseña)
    function openChangePasswordModal(userId, username) {
        document.getElementById('user_id_password').value = userId;
        document.getElementById('usernameForPasswordChange').textContent = username;
        document.getElementById('new_password').value = '';
        document.getElementById('confirm_new_password').value = '';
        document.getElementById('changePasswordModal').style.display = 'flex';
    }
    function closeChangePasswordModal() {
        document.getElementById('changePasswordModal').style.display = 'none';
    }

    // Cerrar modales si se hace clic fuera del contenido del modal
    window.onclick = function(event) {
        const editClienteModal = document.getElementById('editClienteModal');
        const changePasswordModal = document.getElementById('changePasswordModal');
        if (event.target == editClienteModal) {
            closeEditClienteModal();
        }
        if (event.target == changePasswordModal) {
            closeChangePasswordModal();
        }
    }
    </script>
<?php include 'footer.php'; ?>
</body>
</html>