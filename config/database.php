<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'personal_finance_db');

// Create database connection
function getDBConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        
        $conn->set_charset("utf8mb4");
        return $conn;
    } catch (Exception $e) {
        die("Database connection error: " . $e->getMessage());
    }
}

// Helper function to get user ID from session (you need to implement login system)
function getCurrentUserId() {
    // This is a placeholder - implement according to your authentication system
    session_start();
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1; // Default to 1 for testing
}
?>