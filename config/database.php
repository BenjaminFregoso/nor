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


function getUserWallets($user_id) {
    $conn = getDBConnection();
    $query = "SELECT w.*, wt.type_name, wt.icon_class 
              FROM wallets w 
              JOIN wallet_types wt ON w.wallet_type_id = wt.id 
              WHERE w.user_id = ? AND w.is_active = TRUE 
              ORDER BY w.is_default DESC, w.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $wallets = [];
    while ($row = $result->fetch_assoc()) {
        $wallets[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    return $wallets;
}

function getWalletById($wallet_id, $user_id = null) {
    $conn = getDBConnection();
    
    if ($user_id) {
        $query = "SELECT w.*, wt.type_name, wt.icon_class 
                  FROM wallets w 
                  JOIN wallet_types wt ON w.wallet_type_id = wt.id 
                  WHERE w.id = ? AND w.user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $wallet_id, $user_id);
    } else {
        $query = "SELECT w.*, wt.type_name, wt.icon_class 
                  FROM wallets w 
                  JOIN wallet_types wt ON w.wallet_type_id = wt.id 
                  WHERE w.id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $wallet_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $wallet = $result->fetch_assoc();
    
    $stmt->close();
    $conn->close();
    
    return $wallet;
}

function getWalletTypes() {
    $conn = getDBConnection();
    $query = "SELECT * FROM wallet_types WHERE is_active = TRUE ORDER BY type_name";
    
    $result = $conn->query($query);
    $types = [];
    while ($row = $result->fetch_assoc()) {
        $types[] = $row;
    }
    
    $conn->close();
    return $types;
}

function updateWalletBalance($wallet_id, $amount, $is_income = true) {
    $conn = getDBConnection();
    
    if ($is_income) {
        $query = "UPDATE wallets SET balance = balance + ? WHERE id = ?";
    } else {
        $query = "UPDATE wallets SET balance = balance - ? WHERE id = ?";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("di", $amount, $wallet_id);
    $result = $stmt->execute();
    
    $stmt->close();
    $conn->close();
    
    return $result;
}

function getTotalBalanceAllWallets($user_id) {
    $conn = getDBConnection();
    $query = "SELECT SUM(balance) as total_balance FROM wallets WHERE user_id = ? AND is_active = TRUE";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $total = $result->fetch_assoc()['total_balance'] ?? 0;
    
    $stmt->close();
    $conn->close();
    
    return $total;
}

function getWalletBalanceHistory($wallet_id, $days = 30) {
    $conn = getDBConnection();
    $query = "CALL GetWalletBalanceHistory(?, DATE_SUB(CURDATE(), INTERVAL ? DAY), CURDATE())";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $wallet_id, $days);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    return $history;
}

?>