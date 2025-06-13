<?php
// Plik sprawdzający autoryzację użytkownika
session_start();

if (!file_exists('config.php')) {
    header('Location: install.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config.php';

// Funkcja sprawdzająca czy użytkownik ma uprawnienia admina
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Funkcja sprawdzająca uprawnienia admina i przekierowująca jeśli nie ma uprawnień
function requireAdmin() {
    if (!isAdmin()) {
        header('Location: dashboard.php');
        exit;
    }
}
?>