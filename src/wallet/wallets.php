<?php
require_once '../../config/database.php';
require_once '../../includes/header.php';

// Get database connection
$conn = getDBConnection();
$user_id = getCurrentUserId();

// Initialize variables
$success_message = "";
$error_message = "";
$form_data = [
    'wallet_name' => '',
    'wallet_type_id' => '',
    'description' => '',
    'initial_balance' => '0.00',
    'account_number' => '',
    'bank_name' => '',
    'card_last_four' => '',
    'card_expiry_date' => '',
    'credit_limit' => '0.00',
    'color_code' => '#3498db',
    'is_default' => 0
];

// Get wallet types
$wallet_types = getWalletTypes();

// Function to get user wallets
function getUserWalletsList($conn, $user_id) {
    $wallets_query = "SELECT w.*, wt.type_name, wt.icon_class 
                      FROM wallets w 
                      JOIN wallet_types wt ON w.wallet_type_id = wt.id 
                      WHERE w.user_id = ? 
                      ORDER BY w.is_default DESC, w.created_at DESC";
    $stmt = $conn->prepare($wallets_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $wallets_result = $stmt->get_result();
    $user_wallets = [];
    while ($row = $wallets_result->fetch_assoc()) {
        $user_wallets[] = $row;
    }
    $stmt->close();
    return $user_wallets;
}

// Get initial user wallets
$user_wallets = getUserWalletsList($conn, $user_id);

// Process form submission for new wallet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_wallet') {
    // Validate and sanitize input
    $wallet_name = trim($_POST['wallet_name'] ?? '');
    $wallet_type_id = intval($_POST['wallet_type_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $initial_balance = floatval($_POST['initial_balance'] ?? 0);
    $account_number = trim($_POST['account_number'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $card_last_four = substr(trim($_POST['card_last_four'] ?? ''), -4);
    $card_expiry_date = $_POST['card_expiry_date'] ?? null;
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $color_code = $_POST['color_code'] ?? '#3498db';
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    
    // Validate required fields
    $errors = [];
    
    if (empty($wallet_name)) {
        $errors[] = "Wallet name is required.";
    }
    
    if (strlen($wallet_name) > 100) {
        $errors[] = "Wallet name must be less than 100 characters.";
    }
    
    if ($wallet_type_id <= 0) {
        $errors[] = "Please select a wallet type.";
    }
    
    // Check if wallet name already exists for this user
    $check_query = "SELECT id FROM wallets WHERE user_id = ? AND wallet_name = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("is", $user_id, $wallet_name);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        $errors[] = "A wallet with this name already exists.";
    }
    $check_stmt->close();
    
    // If setting as default, unset other default wallets
    if ($is_default) {
        $unset_default = "UPDATE wallets SET is_default = FALSE WHERE user_id = ?";
        $unset_stmt = $conn->prepare($unset_default);
        $unset_stmt->bind_param("i", $user_id);
        $unset_stmt->execute();
        $unset_stmt->close();
    }
    
    // If no errors, insert into database
    if (empty($errors)) {
        $insert_query = "INSERT INTO wallets 
                         (user_id, wallet_type_id, wallet_name, description, balance, 
                          account_number, bank_name, card_last_four, card_expiry_date, 
                          credit_limit, color_code, is_default) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("iissdssssdss", 
            $user_id, $wallet_type_id, $wallet_name, $description, $initial_balance,
            $account_number, $bank_name, $card_last_four, $card_expiry_date,
            $credit_limit, $color_code, $is_default
        );
        
        if ($stmt->execute()) {
            $wallet_id = $stmt->insert_id;
            $stmt->close();
            
            // If initial balance > 0, create an adjustment transaction
            if ($initial_balance > 0) {
                $adjustment_query = "INSERT INTO wallet_transactions_history 
                                    (wallet_id, previous_balance, new_balance, 
                                     change_amount, change_type, description) 
                                    VALUES (?, 0, ?, ?, 'adjustment', 'Initial balance')";
                $adj_stmt = $conn->prepare($adjustment_query);
                $adj_stmt->bind_param("idd", $wallet_id, $initial_balance, $initial_balance);
                $adj_stmt->execute();
                $adj_stmt->close();
            }
            
            $success_message = "Wallet created successfully!";
            
            // Clear form data
            $form_data = [
                'wallet_name' => '',
                'wallet_type_id' => '',
                'description' => '',
                'initial_balance' => '0.00',
                'account_number' => '',
                'bank_name' => '',
                'card_last_four' => '',
                'card_expiry_date' => '',
                'credit_limit' => '0.00',
                'color_code' => '#3498db',
                'is_default' => 0
            ];
            
            // Refresh wallets list
            $user_wallets = getUserWalletsList($conn, $user_id);
            
        } else {
            $error_message = "Error creating wallet: " . $stmt->error;
            $stmt->close();
        }
    } else {
        $error_message = implode("<br>", $errors);
        // Keep form data for re-filling
        $form_data = [
            'wallet_name' => $_POST['wallet_name'] ?? '',
            'wallet_type_id' => $_POST['wallet_type_id'] ?? '',
            'description' => $_POST['description'] ?? '',
            'initial_balance' => $_POST['initial_balance'] ?? '0.00',
            'account_number' => $_POST['account_number'] ?? '',
            'bank_name' => $_POST['bank_name'] ?? '',
            'card_last_four' => $_POST['card_last_four'] ?? '',
            'card_expiry_date' => $_POST['card_expiry_date'] ?? '',
            'credit_limit' => $_POST['credit_limit'] ?? '0.00',
            'color_code' => $_POST['color_code'] ?? '#3498db',
            'is_default' => isset($_POST['is_default']) ? 1 : 0
        ];
    }
}

// Process wallet deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $wallet_id = intval($_GET['delete']);
    
    // Check if wallet belongs to user
    $check_query = "SELECT id, balance FROM wallets WHERE id = ? AND user_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ii", $wallet_id, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $wallet = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if ($wallet) {
        // Check if wallet has transactions
        $transactions_query = "SELECT COUNT(*) as count FROM transactions 
                              WHERE (wallet_id = ? OR from_wallet_id = ? OR to_wallet_id = ?)";
        $trans_stmt = $conn->prepare($transactions_query);
        $trans_stmt->bind_param("iii", $wallet_id, $wallet_id, $wallet_id);
        $trans_stmt->execute();
        $trans_result = $trans_stmt->get_result();
        $transaction_count = $trans_result->fetch_assoc()['count'];
        $trans_stmt->close();
        
        if ($transaction_count > 0) {
            $error_message = "Cannot delete wallet with transactions. Deactivate it instead.";
        } elseif ($wallet['balance'] != 0) {
            $error_message = "Cannot delete wallet with non-zero balance. Transfer funds first.";
        } else {
            $delete_query = "DELETE FROM wallets WHERE id = ? AND user_id = ?";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bind_param("ii", $wallet_id, $user_id);
            
            if ($delete_stmt->execute()) {
                $success_message = "Wallet deleted successfully!";
                // Refresh wallets list
                $user_wallets = getUserWalletsList($conn, $user_id);
            } else {
                $error_message = "Error deleting wallet: " . $delete_stmt->error;
            }
            $delete_stmt->close();
        }
    } else {
        $error_message = "Wallet not found or access denied.";
    }
}

// Process wallet activation/deactivation
if (isset($_GET['toggle_active']) && is_numeric($_GET['toggle_active'])) {
    $wallet_id = intval($_GET['toggle_active']);
    
    // Check current status
    $status_query = "SELECT is_active FROM wallets WHERE id = ? AND user_id = ?";
    $status_stmt = $conn->prepare($status_query);
    $status_stmt->bind_param("ii", $wallet_id, $user_id);
    $status_stmt->execute();
    $status_result = $status_stmt->get_result();
    $current_status = $status_result->fetch_assoc()['is_active'];
    $status_stmt->close();
    
    $new_status = $current_status ? 0 : 1;
    
    $toggle_query = "UPDATE wallets SET is_active = ? WHERE id = ? AND user_id = ?";
    $toggle_stmt = $conn->prepare($toggle_query);
    $toggle_stmt->bind_param("iii", $new_status, $wallet_id, $user_id);
    
    if ($toggle_stmt->execute()) {
        $action = $new_status ? "activated" : "deactivated";
        $success_message = "Wallet {$action} successfully!";
        // Refresh wallets list
        $user_wallets = getUserWalletsList($conn, $user_id);
    } else {
        $error_message = "Error updating wallet status.";
    }
    $toggle_stmt->close();
}

// Process set as default
if (isset($_GET['set_default']) && is_numeric($_GET['set_default'])) {
    $wallet_id = intval($_GET['set_default']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Unset all other default wallets
        $unset_query = "UPDATE wallets SET is_default = FALSE WHERE user_id = ?";
        $unset_stmt = $conn->prepare($unset_query);
        $unset_stmt->bind_param("i", $user_id);
        $unset_stmt->execute();
        $unset_stmt->close();
        
        // Set new default
        $set_query = "UPDATE wallets SET is_default = TRUE WHERE id = ? AND user_id = ?";
        $set_stmt = $conn->prepare($set_query);
        $set_stmt->bind_param("ii", $wallet_id, $user_id);
        $set_stmt->execute();
        $set_stmt->close();
        
        $conn->commit();
        $success_message = "Default wallet updated successfully!";
        // Refresh wallets list
        $user_wallets = getUserWalletsList($conn, $user_id);
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error setting default wallet: " . $e->getMessage();
    }
}

$conn->close();
?>

<div class="row">
    <!-- Left Column: Wallet List -->
    <div class="col-lg-8 mb-4">
        <div class="card finance-card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h4 class="mb-0">
                    <i class="fas fa-wallet me-2"></i>My Wallets
                </h4>
                <span class="badge bg-light text-dark">
                    <?php echo count($user_wallets); ?> wallets
                </span>
            </div>
            <div class="card-body">
                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($user_wallets)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-wallet fa-4x text-muted mb-4"></i>
                        <h4 class="text-muted">No wallets yet</h4>
                        <p class="text-muted">Create your first wallet to start managing your finances</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWalletModal">
                            <i class="fas fa-plus me-1"></i>Create First Wallet
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Wallet</th>
                                    <th>Type</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_wallets as $wallet): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="wallet-color-indicator me-2" 
                                                     style="background-color: <?php echo $wallet['color_code']; ?>; 
                                                            width: 12px; height: 12px; border-radius: 50%;"></div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($wallet['wallet_name']); ?></strong>
                                                    <?php if ($wallet['is_default']): ?>
                                                        <span class="badge bg-primary ms-2">Default</span>
                                                    <?php endif; ?>
                                                    <?php if ($wallet['bank_name']): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($wallet['bank_name']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <i class="<?php echo $wallet['icon_class']; ?> me-1"></i>
                                            <?php echo htmlspecialchars($wallet['type_name']); ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo $wallet['balance'] >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold">
                                                $<?php echo number_format($wallet['balance'], 2); ?>
                                            </span>
                                            <?php if ($wallet['credit_limit'] > 0): ?>
                                                <br><small class="text-muted">Limit: $<?php echo number_format($wallet['credit_limit'], 2); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($wallet['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="wallet_details.php?id=<?php echo $wallet['id']; ?>" 
                                                   class="btn btn-outline-info" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="wallet_transfer.php?from=<?php echo $wallet['id']; ?>" 
                                                   class="btn btn-outline-warning" title="Transfer">
                                                    <i class="fas fa-exchange-alt"></i>
                                                </a>
                                                <?php if (!$wallet['is_default']): ?>
                                                    <a href="?set_default=<?php echo $wallet['id']; ?>" 
                                                       class="btn btn-outline-primary" title="Set as Default">
                                                        <i class="fas fa-star"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="?toggle_active=<?php echo $wallet['id']; ?>" 
                                                   class="btn btn-outline-<?php echo $wallet['is_active'] ? 'warning' : 'success'; ?>" 
                                                   title="<?php echo $wallet['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                    <i class="fas fa-<?php echo $wallet['is_active'] ? 'ban' : 'check'; ?>"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="confirmDelete(<?php echo $wallet['id']; ?>, '<?php echo htmlspecialchars($wallet['wallet_name']); ?>')"
                                                        title="Delete" <?php echo ($wallet['balance'] != 0) ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Total Balance Summary -->
                    <div class="row mt-4">
                        <div class="col-md-4">
                            <div class="card border-0 bg-light">
                                <div class="card-body text-center">
                                    <h6 class="text-muted">Total Balance</h6>
                                    <h3 class="text-primary">
                                        $<?php 
                                            $total_balance = array_sum(array_column($user_wallets, 'balance'));
                                            echo number_format($total_balance, 2); 
                                        ?>
                                    </h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0 bg-light">
                                <div class="card-body text-center">
                                    <h6 class="text-muted">Active Wallets</h6>
                                    <h3 class="text-success">
                                        <?php 
                                            $active_count = count(array_filter($user_wallets, function($w) { 
                                                return $w['is_active']; 
                                            }));
                                            echo $active_count;
                                        ?>
                                    </h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0 bg-light">
                                <div class="card-body text-center">
                                    <h6 class="text-muted">Credit Available</h6>
                                    <h3 class="text-info">
                                        $<?php 
                                            $total_credit = array_sum(array_column($user_wallets, 'credit_limit'));
                                            echo number_format($total_credit, 2); 
                                        ?>
                                    </h3>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Quick Actions & Stats -->
    <div class="col-lg-4 mb-4">
        <!-- Add New Wallet Button -->
        <div class="card finance-card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="fas fa-plus-circle me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addWalletModal">
                        <i class="fas fa-plus me-2"></i>Add New Wallet
                    </button>
                    <a href="wallet_transfer.php" class="btn btn-warning">
                        <i class="fas fa-exchange-alt me-2"></i>Transfer Between Wallets
                    </a>
                    <a href="add_income.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-circle-up me-2"></i>Add Income
                    </a>
                    <a href="add_expense.php" class="btn btn-outline-danger">
                        <i class="fas fa-arrow-circle-down me-2"></i>Add Expense
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Wallet Types Summary -->
        <div class="card finance-card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-chart-pie me-2"></i>Balance by Wallet Type
                </h5>
            </div>
            <div class="card-body">
                <?php 
                // Calculate balance by type
                $balance_by_type = [];
                foreach ($user_wallets as $wallet) {
                    if (!isset($balance_by_type[$wallet['type_name']])) {
                        $balance_by_type[$wallet['type_name']] = [
                            'balance' => 0,
                            'count' => 0,
                            'icon' => $wallet['icon_class']
                        ];
                    }
                    $balance_by_type[$wallet['type_name']]['balance'] += $wallet['balance'];
                    $balance_by_type[$wallet['type_name']]['count']++;
                }
                
                if (!empty($balance_by_type)):
                ?>
                    <div class="wallet-type-breakdown">
                        <?php foreach ($balance_by_type as $type_name => $data): 
                            $percentage = ($total_balance > 0) ? ($data['balance'] / $total_balance) * 100 : 0;
                        ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>
                                        <i class="<?php echo $data['icon']; ?> me-2"></i>
                                        <?php echo htmlspecialchars($type_name); ?>
                                        <small class="text-muted">(<?php echo $data['count']; ?>)</small>
                                    </span>
                                    <span class="<?php echo $data['balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        $<?php echo number_format($data['balance'], 2); ?>
                                    </span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-info" role="progressbar" 
                                         style="width: <?php echo min($percentage, 100); ?>%">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-chart-pie fa-2x mb-2"></i>
                        <p>No wallet data available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Wallet Modal -->
<div class="modal fade" id="addWalletModal" tabindex="-1" aria-labelledby="addWalletModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="addWalletModalLabel">
                    <i class="fas fa-plus-circle me-2"></i>Add New Wallet
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_wallet">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="wallet_name" class="form-label required-field">Wallet Name</label>
                            <input type="text" class="form-control" id="wallet_name" name="wallet_name" 
                                   value="<?php echo htmlspecialchars($form_data['wallet_name']); ?>" 
                                   required maxlength="100" placeholder="e.g., Chase Debit Card">
                            <div class="form-text">Give your wallet a descriptive name</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="wallet_type_id" class="form-label required-field">Wallet Type</label>
                            <select class="form-select" id="wallet_type_id" name="wallet_type_id" required>
                                <option value="">-- Select Type --</option>
                                <?php foreach ($wallet_types as $type): ?>
                                    <option value="<?php echo $type['id']; ?>" 
                                        <?php echo ($form_data['wallet_type_id'] == $type['id']) ? 'selected' : ''; ?>
                                        data-icon="<?php echo $type['icon_class']; ?>">
                                        <i class="<?php echo $type['icon_class']; ?> me-2"></i>
                                        <?php echo htmlspecialchars($type['type_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2" 
                                  placeholder="Optional description"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="initial_balance" class="form-label">Initial Balance</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="initial_balance" name="initial_balance" 
                                       step="0.01" min="0" value="<?php echo $form_data['initial_balance']; ?>" 
                                       placeholder="0.00">
                            </div>
                            <div class="form-text">Starting balance for this wallet</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="color_code" class="form-label">Color</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color" id="color_code" 
                                       name="color_code" value="<?php echo $form_data['color_code']; ?>" 
                                       title="Choose wallet color">
                                <span class="input-group-text" id="colorPreview" 
                                      style="background-color: <?php echo $form_data['color_code']; ?>;"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="bank_name" class="form-label">Bank Name</label>
                            <input type="text" class="form-control" id="bank_name" name="bank_name" 
                                   value="<?php echo htmlspecialchars($form_data['bank_name']); ?>" 
                                   placeholder="e.g., Chase Bank">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="account_number" class="form-label">Account Number (Last 4)</label>
                            <input type="text" class="form-control" id="account_number" name="account_number" 
                                   value="<?php echo htmlspecialchars($form_data['account_number']); ?>" 
                                   maxlength="4" pattern="\d{4}" placeholder="1234">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="card_last_four" class="form-label">Card Last 4 Digits</label>
                            <input type="text" class="form-control" id="card_last_four" name="card_last_four" 
                                   value="<?php echo htmlspecialchars($form_data['card_last_four']); ?>" 
                                   maxlength="4" pattern="\d{4}" placeholder="4321">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="card_expiry_date" class="form-label">Card Expiry Date</label>
                            <input type="month" class="form-control" id="card_expiry_date" name="card_expiry_date" 
                                   value="<?php echo htmlspecialchars($form_data['card_expiry_date']); ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="credit_limit" class="form-label">Credit Limit</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="credit_limit" name="credit_limit" 
                                   step="0.01" min="0" value="<?php echo $form_data['credit_limit']; ?>" 
                                   placeholder="0.00">
                        </div>
                        <div class="form-text">For credit cards only</div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_default" name="is_default" 
                                   value="1" <?php echo $form_data['is_default'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_default">
                                Set as default wallet for new transactions
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Create Wallet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Color picker preview
    const colorInput = document.getElementById('color_code');
    const colorPreview = document.getElementById('colorPreview');
    
    if (colorInput && colorPreview) {
        colorInput.addEventListener('input', function() {
            colorPreview.style.backgroundColor = this.value;
        });
    }
    
    // Form validation for card numbers
    const cardLastFour = document.getElementById('card_last_four');
    const accountNumber = document.getElementById('account_number');
    
    [cardLastFour, accountNumber].forEach(input => {
        if (input) {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 4);
            });
        }
    });
    
    // Auto-select icon based on wallet type
    const walletTypeSelect = document.getElementById('wallet_type_id');
    const walletNameInput = document.getElementById('wallet_name');
    
    if (walletTypeSelect && walletNameInput) {
        walletTypeSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const iconClass = selectedOption.getAttribute('data-icon');
            
            // Auto-suggest wallet name based on type
            if (walletNameInput.value === '' || walletNameInput.value.startsWith('My ')) {
                const typeName = selectedOption.text.replace(/<[^>]*>/g, '').trim();
                walletNameInput.value = 'My ' + typeName;
            }
        });
    }
    
    // Show credit limit field only for credit cards
    const creditLimitInput = document.getElementById('credit_limit');
    if (creditLimitInput && walletTypeSelect) {
        const creditLimitGroup = creditLimitInput.closest('.mb-3');
        
        function toggleCreditLimit() {
            const selectedOption = walletTypeSelect.options[walletTypeSelect.selectedIndex];
            const typeText = selectedOption.text.toLowerCase();
            
            if (typeText.includes('credit')) {
                creditLimitGroup.style.display = 'block';
            } else {
                creditLimitGroup.style.display = 'none';
                creditLimitInput.value = '0.00';
            }
        }
        
        walletTypeSelect.addEventListener('change', toggleCreditLimit);
        toggleCreditLimit(); // Initial check
    }
});

// Confirm wallet deletion
function confirmDelete(walletId, walletName) {
    if (confirm(`Are you sure you want to delete "${walletName}"?\n\nThis action cannot be undone.`)) {
        window.location.href = `?delete=${walletId}`;
    }
}

// Auto-format initial balance
const initialBalanceInput = document.getElementById('initial_balance');
if (initialBalanceInput) {
    initialBalanceInput.addEventListener('blur', function() {
        if (this.value) {
            this.value = parseFloat(this.value).toFixed(2);
        }
    });
}
</script>

<style>
.wallet-color-indicator {
    transition: transform 0.3s ease;
}

.wallet-color-indicator:hover {
    transform: scale(1.3);
}

.table-hover tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.02);
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
}

.modal-body .required-field::after {
    content: " *";
    color: #dc3545;
}

#colorPreview {
    min-width: 40px;
    border: 1px solid #dee2e6;
}
</style>