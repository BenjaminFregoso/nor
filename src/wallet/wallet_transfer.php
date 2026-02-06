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
    'from_wallet_id' => $_GET['from'] ?? '',
    'to_wallet_id' => '',
    'amount' => '',
    'transfer_date' => date('Y-m-d'),
    'description' => '',
    'fees' => '0.00'
];

// Get user's active wallets
$wallets_query = "SELECT w.*, wt.type_name, wt.icon_class 
                  FROM wallets w 
                  JOIN wallet_types wt ON w.wallet_type_id = wt.id 
                  WHERE w.user_id = ? AND w.is_active = TRUE 
                  ORDER BY w.is_default DESC, w.wallet_name";
$stmt = $conn->prepare($wallets_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$wallets_result = $stmt->get_result();
$user_wallets = [];
while ($row = $wallets_result->fetch_assoc()) {
    $user_wallets[] = $row;
}
$stmt->close();

// Get recent transfers
$recent_transfers_query = "SELECT 
    wt.*,
    fw.wallet_name as from_wallet_name,
    fw.color_code as from_color,
    tw.wallet_name as to_wallet_name,
    tw.color_code as to_color
FROM wallet_transfers wt
JOIN wallets fw ON wt.from_wallet_id = fw.id
JOIN wallets tw ON wt.to_wallet_id = tw.id
WHERE wt.user_id = ?
ORDER BY wt.transfer_date DESC, wt.created_at DESC
LIMIT 10";
$stmt = $conn->prepare($recent_transfers_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$transfers_result = $stmt->get_result();
$recent_transfers = [];
while ($row = $transfers_result->fetch_assoc()) {
    $recent_transfers[] = $row;
}
$stmt->close();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $from_wallet_id = intval($_POST['from_wallet_id'] ?? 0);
    $to_wallet_id = intval($_POST['to_wallet_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');
    $fees = floatval($_POST['fees'] ?? 0);
    $total_amount = $amount + $fees;
    
    // Validate required fields
    $errors = [];
    
    if ($from_wallet_id <= 0 || $to_wallet_id <= 0) {
        $errors[] = "Please select both source and destination wallets.";
    }
    
    if ($from_wallet_id === $to_wallet_id) {
        $errors[] = "Source and destination wallets cannot be the same.";
    }
    
    if ($amount <= 0) {
        $errors[] = "Please enter a valid amount greater than 0.";
    }
    
    if ($fees < 0) {
        $errors[] = "Fees cannot be negative.";
    }
    
    if (!strtotime($transfer_date)) {
        $errors[] = "Please enter a valid date.";
    }
    
    // Check wallet balances and existence
    if (empty($errors)) {
        // Get source wallet info
        $from_wallet = null;
        foreach ($user_wallets as $wallet) {
            if ($wallet['id'] == $from_wallet_id) {
                $from_wallet = $wallet;
                break;
            }
        }
        
        // Get destination wallet info
        $to_wallet = null;
        foreach ($user_wallets as $wallet) {
            if ($wallet['id'] == $to_wallet_id) {
                $to_wallet = $wallet;
                break;
            }
        }
        
        if (!$from_wallet) {
            $errors[] = "Source wallet not found or not active.";
        }
        
        if (!$to_wallet) {
            $errors[] = "Destination wallet not found or not active.";
        }
        
        // Check if source wallet has sufficient balance
        if ($from_wallet && $from_wallet['balance'] < $total_amount) {
            $errors[] = "Insufficient balance in source wallet. Available: $" . 
                       number_format($from_wallet['balance'], 2) . 
                       ", Required: $" . number_format($total_amount, 2);
        }
    }
    
    // If no errors, process transfer
    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Call stored procedure for transfer
            $procedure_query = "CALL TransferBetweenWallets(?, ?, ?, ?, ?, ?)";
            $procedure_stmt = $conn->prepare($procedure_query);
            $procedure_stmt->bind_param("iiidss", 
                $user_id, $from_wallet_id, $to_wallet_id, 
                $amount, $transfer_date, $description
            );
            
            if ($procedure_stmt->execute()) {
                // If there are fees, create a separate expense transaction
                if ($fees > 0) {
                    $fees_description = "Transfer fees: " . ($description ? $description : "Wallet transfer");
                    
                    // Find fees category (create if doesn't exist)
                    $fees_category_query = "SELECT id FROM transaction_categories 
                                           WHERE category_name = 'Bank Fees' 
                                           AND category_type = 'expense' 
                                           AND (user_id = ? OR user_id IS NULL) 
                                           LIMIT 1";
                    $cat_stmt = $conn->prepare($fees_category_query);
                    $cat_stmt->bind_param("i", $user_id);
                    $cat_stmt->execute();
                    $cat_result = $cat_stmt->get_result();
                    
                    if ($cat_result->num_rows > 0) {
                        $fees_category_id = $cat_result->fetch_assoc()['id'];
                    } else {
                        // Create bank fees category
                        $create_cat_query = "INSERT INTO transaction_categories 
                                            (user_id, category_name, category_type, description) 
                                            VALUES (?, 'Bank Fees', 'expense', 'Bank fees and charges')";
                        $create_stmt = $conn->prepare($create_cat_query);
                        $create_stmt->bind_param("i", $user_id);
                        $create_stmt->execute();
                        $fees_category_id = $create_stmt->insert_id;
                        $create_stmt->close();
                    }
                    $cat_stmt->close();
                    
                    // Create fees transaction
                    $fees_query = "INSERT INTO transactions 
                                  (user_id, wallet_id, category_id, transaction_type, 
                                   amount, description, transaction_date) 
                                  VALUES (?, ?, ?, 'expense', ?, ?, ?)";
                    $fees_stmt = $conn->prepare($fees_query);
                    $fees_stmt->bind_param("iiisss", 
                        $user_id, $from_wallet_id, $fees_category_id,
                        $fees, $fees_description, $transfer_date
                    );
                    $fees_stmt->execute();
                    $fees_stmt->close();
                }
                
                $conn->commit();
                $success_message = "Transfer completed successfully!";
                
                if ($fees > 0) {
                    $success_message .= "<br>Transfer fees of $" . number_format($fees, 2) . " were deducted.";
                }
                
                // Clear form data
                $form_data = [
                    'from_wallet_id' => '',
                    'to_wallet_id' => '',
                    'amount' => '',
                    'transfer_date' => date('Y-m-d'),
                    'description' => '',
                    'fees' => '0.00'
                ];
                
                // Refresh recent transfers
                $stmt = $conn->prepare($recent_transfers_query);
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $transfers_result = $stmt->get_result();
                $recent_transfers = [];
                while ($row = $transfers_result->fetch_assoc()) {
                    $recent_transfers[] = $row;
                }
                $stmt->close();
                
            } else {
                throw new Exception("Error executing transfer: " . $procedure_stmt->error);
            }
            
            $procedure_stmt->close();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Transfer failed: " . $e->getMessage();
            // Keep form data for re-filling
            $form_data = [
                'from_wallet_id' => $_POST['from_wallet_id'] ?? '',
                'to_wallet_id' => $_POST['to_wallet_id'] ?? '',
                'amount' => $_POST['amount'] ?? '',
                'transfer_date' => $_POST['transfer_date'] ?? date('Y-m-d'),
                'description' => $_POST['description'] ?? '',
                'fees' => $_POST['fees'] ?? '0.00'
            ];
        }
    } else {
        $error_message = implode("<br>", $errors);
        // Keep form data for re-filling
        $form_data = [
            'from_wallet_id' => $_POST['from_wallet_id'] ?? '',
            'to_wallet_id' => $_POST['to_wallet_id'] ?? '',
            'amount' => $_POST['amount'] ?? '',
            'transfer_date' => $_POST['transfer_date'] ?? date('Y-m-d'),
            'description' => $_POST['description'] ?? '',
            'fees' => $_POST['fees'] ?? '0.00'
        ];
    }
}

$conn->close();

// Get default wallet if from parameter not set
if (empty($form_data['from_wallet_id']) && !empty($user_wallets)) {
    foreach ($user_wallets as $wallet) {
        if ($wallet['is_default']) {
            $form_data['from_wallet_id'] = $wallet['id'];
            break;
        }
    }
    if (empty($form_data['from_wallet_id'])) {
        $form_data['from_wallet_id'] = $user_wallets[0]['id'];
    }
}
?>

<div class="row">
    <!-- Left Column: Transfer Form -->
    <div class="col-lg-7 mb-4">
        <div class="card finance-card">
            <div class="card-header bg-warning text-dark">
                <h4 class="mb-0">
                    <i class="fas fa-exchange-alt me-2"></i>Transfer Between Wallets
                </h4>
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
                
                <?php if (count($user_wallets) < 2): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-exchange-alt fa-4x text-muted mb-4"></i>
                        <h4 class="text-muted">Need More Wallets</h4>
                        <p class="text-muted">You need at least 2 active wallets to make transfers</p>
                        <a href="wallets.php" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i>Add Another Wallet
                        </a>
                    </div>
                <?php else: ?>
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="transferForm">
                        <div class="row">
                            <!-- From Wallet -->
                            <div class="col-md-6 mb-3">
                                <label for="from_wallet_id" class="form-label required-field">From Wallet</label>
                                <select class="form-select" id="from_wallet_id" name="from_wallet_id" required>
                                    <option value="">-- Select Source Wallet --</option>
                                    <?php foreach ($user_wallets as $wallet): 
                                        $balance_display = "$" . number_format($wallet['balance'], 2);
                                    ?>
                                        <option value="<?php echo $wallet['id']; ?>" 
                                            <?php echo ($form_data['from_wallet_id'] == $wallet['id']) ? 'selected' : ''; ?>
                                            data-balance="<?php echo $wallet['balance']; ?>"
                                            data-color="<?php echo $wallet['color_code']; ?>">
                                            <i class="<?php echo $wallet['icon_class']; ?> me-2"></i>
                                            <?php echo htmlspecialchars($wallet['wallet_name']); ?>
                                            <small class="text-muted">(<?php echo $balance_display; ?>)</small>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="fromWalletBalance"></div>
                            </div>
                            
                            <!-- To Wallet -->
                            <div class="col-md-6 mb-3">
                                <label for="to_wallet_id" class="form-label required-field">To Wallet</label>
                                <select class="form-select" id="to_wallet_id" name="to_wallet_id" required>
                                    <option value="">-- Select Destination Wallet --</option>
                                    <?php foreach ($user_wallets as $wallet): ?>
                                        <option value="<?php echo $wallet['id']; ?>" 
                                            <?php echo ($form_data['to_wallet_id'] == $wallet['id']) ? 'selected' : ''; ?>
                                            data-color="<?php echo $wallet['color_code']; ?>">
                                            <i class="<?php echo $wallet['icon_class']; ?> me-2"></i>
                                            <?php echo htmlspecialchars($wallet['wallet_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="toWalletInfo"></div>
                            </div>
                        </div>
                        
                        <!-- Amount and Date -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="amount" class="form-label required-field">Transfer Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" id="amount" name="amount" 
                                           step="0.01" min="0.01" value="<?php echo htmlspecialchars($form_data['amount']); ?>" 
                                           required placeholder="0.00">
                                </div>
                                <div class="form-text">Amount to transfer (excluding fees)</div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="transfer_date" class="form-label required-field">Transfer Date</label>
                                <input type="date" class="form-control" id="transfer_date" name="transfer_date" 
                                       value="<?php echo htmlspecialchars($form_data['transfer_date']); ?>" required>
                            </div>
                        </div>
                        
                        <!-- Fees -->
                        <div class="mb-3">
                            <label for="fees" class="form-label">Transfer Fees</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="fees" name="fees" 
                                       step="0.01" min="0" value="<?php echo htmlspecialchars($form_data['fees']); ?>" 
                                       placeholder="0.00">
                            </div>
                            <div class="form-text">Any fees associated with this transfer (optional)</div>
                        </div>
                        
                        <!-- Description -->
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2" 
                                      placeholder="Optional description for this transfer"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                        </div>
                        
                        <!-- Transfer Summary -->
                        <div class="card border-info mb-3">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-calculator me-2"></i>Transfer Summary
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Transfer Amount:</small>
                                        <div class="fw-bold" id="summaryAmount">$0.00</div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Fees:</small>
                                        <div class="fw-bold" id="summaryFees">$0.00</div>
                                    </div>
                                    <div class="col-12 mt-2 pt-2 border-top">
                                        <small class="text-muted">Total Deducted:</small>
                                        <div class="fw-bold text-danger" id="summaryTotal">$0.00</div>
                                    </div>
                                    <div class="col-12 mt-2">
                                        <small class="text-muted">Remaining Balance:</small>
                                        <div class="fw-bold" id="summaryRemaining">$0.00</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="fas fa-exchange-alt me-2"></i>Execute Transfer
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Recent Transfers & Quick Actions -->
    <div class="col-lg-5 mb-4">
        <!-- Recent Transfers -->
        <div class="card finance-card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-history me-2"></i>Recent Transfers
                </h5>
            </div>
            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                <?php if (empty($recent_transfers)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-exchange-alt fa-3x mb-3"></i>
                        <p>No recent transfers</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_transfers as $transfer): ?>
                            <div class="list-group-item border-0 px-0 py-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($transfer['description'] ?: 'Wallet Transfer'); ?></h6>
                                        <small class="text-muted">
                                            <?php echo date('M d, Y', strtotime($transfer['transfer_date'])); ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-warning rounded-pill">
                                        $<?php echo number_format($transfer['amount'], 2); ?>
                                    </span>
                                </div>
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-1">
                                            <div class="wallet-color-indicator me-2" 
                                                 style="background-color: <?php echo $transfer['from_color']; ?>;"></div>
                                            <small class="text-truncate" style="max-width: 120px;">
                                                <?php echo htmlspecialchars($transfer['from_wallet_name']); ?>
                                            </small>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <div class="wallet-color-indicator me-2" 
                                                 style="background-color: <?php echo $transfer['to_color']; ?>;"></div>
                                            <small class="text-truncate" style="max-width: 120px;">
                                                <?php echo htmlspecialchars($transfer['to_wallet_name']); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <i class="fas fa-arrow-right text-muted ms-3"></i>
                                </div>
                                <?php if ($transfer['fees'] > 0): ?>
                                    <small class="text-danger mt-2">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Fees: $<?php echo number_format($transfer['fees'], 2); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-center mt-3">
                        <a href="transactions.php?type=transfer" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-eye me-1"></i>View All Transfers
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Transfer Templates -->
        <div class="card finance-card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="fas fa-bolt me-2"></i>Quick Transfers
                </h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Common transfer amounts:</p>
                <div class="row g-2 mb-3">
                    <?php $quick_amounts = [10, 20, 50, 100, 200, 500]; ?>
                    <?php foreach ($quick_amounts as $amount): ?>
                        <div class="col-4">
                            <button type="button" class="btn btn-outline-secondary w-100" 
                                    onclick="setTransferAmount(<?php echo $amount; ?>)">
                                $<?php echo $amount; ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="d-grid gap-2">
                    <a href="wallets.php" class="btn btn-outline-primary">
                        <i class="fas fa-wallet me-2"></i>Manage Wallets
                    </a>
                    <a href="transactions.php" class="btn btn-outline-info">
                        <i class="fas fa-list me-2"></i>View All Transactions
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fromWalletSelect = document.getElementById('from_wallet_id');
    const toWalletSelect = document.getElementById('to_wallet_id');
    const amountInput = document.getElementById('amount');
    const feesInput = document.getElementById('fees');
    const transferForm = document.getElementById('transferForm');
    
    // Update wallet info displays
    function updateWalletInfo() {
        const fromOption = fromWalletSelect.options[fromWalletSelect.selectedIndex];
        const toOption = toWalletSelect.options[toWalletSelect.selectedIndex];
        
        // Update from wallet balance display
        if (fromOption.value) {
            const balance = parseFloat(fromOption.getAttribute('data-balance')) || 0;
            const color = fromOption.getAttribute('data-color') || '#3498db';
            document.getElementById('fromWalletBalance').innerHTML = `
                <i class="fas fa-circle" style="color: ${color}; font-size: 0.8em;"></i>
                Available balance: <strong>$${balance.toFixed(2)}</strong>
            `;
        } else {
            document.getElementById('fromWalletBalance').textContent = '';
        }
        
        // Update to wallet info
        if (toOption.value) {
            const color = toOption.getAttribute('data-color') || '#3498db';
            document.getElementById('toWalletInfo').innerHTML = `
                <i class="fas fa-circle" style="color: ${color}; font-size: 0.8em;"></i>
                Destination wallet
            `;
        } else {
            document.getElementById('toWalletInfo').textContent = '';
        }
        
        updateTransferSummary();
    }
    
    // Update transfer summary
    function updateTransferSummary() {
        const amount = parseFloat(amountInput.value) || 0;
        const fees = parseFloat(feesInput.value) || 0;
        const total = amount + fees;
        
        const fromOption = fromWalletSelect.options[fromWalletSelect.selectedIndex];
        const fromBalance = parseFloat(fromOption.getAttribute('data-balance')) || 0;
        const remaining = fromBalance - total;
        
        document.getElementById('summaryAmount').textContent = `$${amount.toFixed(2)}`;
        document.getElementById('summaryFees').textContent = `$${fees.toFixed(2)}`;
        document.getElementById('summaryTotal').textContent = `$${total.toFixed(2)}`;
        document.getElementById('summaryRemaining').textContent = `$${remaining.toFixed(2)}`;
        
        // Highlight insufficient funds
        if (remaining < 0) {
            document.getElementById('summaryRemaining').classList.add('text-danger');
        } else {
            document.getElementById('summaryRemaining').classList.remove('text-danger');
        }
    }
    
    // Set quick transfer amount
    window.setTransferAmount = function(amount) {
        amountInput.value = amount.toFixed(2);
        amountInput.focus();
        updateTransferSummary();
    };
    
    // Event listeners
    fromWalletSelect.addEventListener('change', updateWalletInfo);
    toWalletSelect.addEventListener('change', updateWalletInfo);
    amountInput.addEventListener('input', updateTransferSummary);
    feesInput.addEventListener('input', updateTransferSummary);
    
    // Auto-format amounts on blur
    [amountInput, feesInput].forEach(input => {
        input.addEventListener('blur', function() {
            if (this.value) {
                this.value = parseFloat(this.value).toFixed(2);
                updateTransferSummary();
            }
        });
    });
    
    // Form validation
    transferForm.addEventListener('submit', function(event) {
        const fromWallet = fromWalletSelect.value;
        const toWallet = toWalletSelect.value;
        const amount = parseFloat(amountInput.value) || 0;
        const fees = parseFloat(feesInput.value) || 0;
        const total = amount + fees;
        
        const fromOption = fromWalletSelect.options[fromWalletSelect.selectedIndex];
        const fromBalance = parseFloat(fromOption.getAttribute('data-balance')) || 0;
        
        let errors = [];
        
        if (!fromWallet) {
            errors.push('Please select source wallet.');
            fromWalletSelect.focus();
        }
        
        if (!toWallet) {
            errors.push('Please select destination wallet.');
            toWalletSelect.focus();
        }
        
        if (fromWallet && toWallet && fromWallet === toWallet) {
            errors.push('Source and destination wallets cannot be the same.');
            toWalletSelect.focus();
        }
        
        if (amount <= 0) {
            errors.push('Please enter a valid transfer amount.');
            amountInput.focus();
        }
        
        if (fees < 0) {
            errors.push('Fees cannot be negative.');
            feesInput.focus();
        }
        
        if (total > fromBalance) {
            errors.push(`Insufficient funds. Available: $${fromBalance.toFixed(2)}, Required: $${total.toFixed(2)}`);
            amountInput.focus();
        }
        
        if (errors.length > 0) {
            event.preventDefault();
            alert(errors.join('\n'));
        }
    });
    
    // Initialize wallet info
    updateWalletInfo();
    
    // Set max date to today
    const dateInput = document.getElementById('transfer_date');
    dateInput.max = new Date().toISOString().split('T')[0];
});
</script>

<style>
.wallet-color-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
}

.list-group-item:hover {
    background-color: rgba(0, 0, 0, 0.02);
    transform: translateX(5px);
    transition: all 0.3s ease;
}

.btn-outline-secondary:hover {
    background-color: #6c757d;
    color: white;
}

#summaryRemaining.text-danger {
    animation: pulse 1s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }
}

.card-header.bg-warning {
    background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
}

.btn-warning {
    background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
    border: none;
    color: white;
}

.btn-warning:hover {
    background: linear-gradient(135deg, #ff9800 0%, #ff5722 100%);
    color: white;
}
</style>