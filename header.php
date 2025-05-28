<?php
// session_start() ya debería estar en las páginas que incluyen este header,
// o en config.php si se incluye antes que el header.
// Por seguridad, si no está, lo iniciamos.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
<?php if (function_exists('isLoggedIn') && isLoggedIn()): ?>
<header class="navbar">
    <div class="nav-container">
        <a href="mj_productos.php" class="nav-logo">
            <i class="fas fa-boxes"></i>
            <span>MJ Gestión</span>
        </a>

        <nav>
            <ul class="nav-menu">
                <?php if (hasRole(['admin', 'supervisor', 'reventa'])): ?>
                    <li class="nav-item">
                        <a href="mj_productos.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mj_productos.php' ? 'active' : '' ?>">
                            <i class="fas fa-list"></i><span>Productos</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasRole('admin')): ?>
                    <li class="nav-item">
                        <a href="mj_alta_productos.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mj_alta_productos.php' ? 'active' : '' ?>">
                            <i class="fas fa-plus-circle"></i><span>Alta Prod.</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="mj_compras_productos.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mj_compras_productos.php' ? 'active' : '' ?>">
                            <i class="fas fa-shopping-cart"></i><span>Compras</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasRole(['admin', 'supervisor'])): ?>
                    <li class="nav-item">
                        <a href="mj_registro_compras.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mj_registro_compras.php' ? 'active' : '' ?>">
                            <i class="fas fa-clipboard-list"></i><span>Reg. Compras</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasRole(['admin', 'supervisor', 'reventa'])): ?>
                    <li class="nav-item">
                        <a href="mj_tienda.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mj_tienda.php' ? 'active' : '' ?>">
                            <i class="fas fa-store"></i><span>Tienda</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasRole(['admin', 'supervisor'])): ?>
                    <li class="nav-item">
                        <a href="mj_registro_tienda.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mj_registro_tienda.php' ? 'active' : '' ?>">
                            <i class="fas fa-file-alt"></i><span>Reg. Ventas</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasRole('admin')): ?>
                    <li class="nav-item">
                        <a href="mj_usuarios.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mj_usuarios.php' ? 'active' : '' ?>">
                            <i class="fas fa-users-cog"></i><span>Usuarios</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="mj_admin_comisiones.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mj_admin_comisiones.php' ? 'active' : '' ?>">
                            <i class="fas fa-donate"></i><span>Comisiones Revendedores</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasRole('reventa')): ?>
                    <li class="nav-item">
                        <a href="mj_revendedores.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mj_revendedores.php' ? 'active' : '' ?>">
                            <i class="fas fa-chart-line"></i><span>Mis Ventas</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>

        <div class="nav-user-info">
            <span class="user-name">
                <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['user_nombre_completo'] ?? $_SESSION['user_username']) ?> (<?= htmlspecialchars($_SESSION['user_rol']) ?>)
            </span>
            <a href="mj_logout.php" class="nav-link logout-link">
                <i class="fas fa-sign-out-alt"></i><span>Salir</span>
            </a>
        </div>
    </div>
</header>
<?php endif; ?>
<main>
    <!-- El contenido de cada página irá aquí -->