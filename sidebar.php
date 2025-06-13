<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
    <div class="position-sticky pt-3">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'projects.php' ? 'active' : '' ?>" href="projects.php">
                    <i class="fas fa-folder"></i> Projekty
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'tasks.php' ? 'active' : '' ?>" href="tasks.php">
                    <i class="fas fa-tasks"></i> Zadania
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'queue.php' ? 'active' : '' ?>" href="queue.php">
                    <i class="fas fa-clock"></i> Kolejka
                </a>
            </li>
        </ul>
        
        <?php if (isAdmin()): ?>
        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
            <span>Administracja</span>
        </h6>
        <ul class="nav flex-column mb-2">
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_users.php' ? 'active' : '' ?>" href="admin_users.php">
                    <i class="fas fa-users"></i> Użytkownicy
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_content_types.php' ? 'active' : '' ?>" href="admin_content_types.php">
                    <i class="fas fa-list"></i> Typy treści
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_prompts.php' ? 'active' : '' ?>" href="admin_prompts.php">
                    <i class="fas fa-code"></i> Prompty
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_settings.php' ? 'active' : '' ?>" href="admin_settings.php">
                    <i class="fas fa-cog"></i> Ustawienia
                </a>
            </li>
        </ul>
        <?php endif; ?>
    </div>
</nav>