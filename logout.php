<?php
session_start();

// Hapus semua session variables
$_SESSION = array();

// Hapus session cookie jika ada
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Destroy session
session_destroy();

// Redirect ke index.php
header("Location: index.php");
exit();
?>