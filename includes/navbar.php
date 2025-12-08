<nav class="navbar">
    <div class="navbar-left">
        <a href="#" class="logo">Admin Panel</a>
    </div>
    <div class="navbar-right">
        <div class="user-menu">
            <span class="username"><?php echo htmlspecialchars($user_name); ?></span>
            <div class="dropdown">
                <a href="#" class="dropdown-toggle">
                    <i class="fas fa-user-circle"></i>
                </a>
                <div class="dropdown-menu">
                    <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                    <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                    <div class="divider"></div>
                    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>
