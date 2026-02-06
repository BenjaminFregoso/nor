<?php
require_once '../../config/database.php';
require_once '../../includes/header.php';

// Get database connection
$conn = getDBConnection();
$user_id = getCurrentUserId();

// Check if wallet ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: wallets.php');
    exit();
}

$wallet_id = intval($_GET['id']);

// Get wallet details
$wallet_query = "SELECT 
    w.*, 
    wt.type_name, 
    wt.icon_class,
    (SELECT COUNT(*) FROM transactions WHERE wallet_id = w.id) as transaction_count,
    (SELECT COUNT(*) FROM transactions WHERE from_wallet_id = w.id OR to_wallet_id = w.id) as transfer_count
FROM wallets w 
JOIN wallet_types wt ON w.wallet_type_id = wt.id 
WHERE w.id = ? AND w.user_id = ?";
$stmt = $conn->prepare($wallet_query);
$stmt->bind_param("ii", $wallet_id, $user_id);
$stmt->execute();
$wallet_result = $stmt->get_result();
$wallet = $wallet_result->fetch_assoc();
$stmt->close();

if (!$wallet) {
    echo '<div class="alert alert-danger">Wallet not found or access denied.</div>';
    require_once '../../includes/footer.php';
    exit();
}

// Get wallet balance history (last 30 days)
$history_query = "SELECT 
    DATE(created_at) as date,
    previous_balance,
    new_balance,
    change_amount,
    change_type,
    description,
    transaction_id,
    transfer_id
FROM wallet_transactions_history 
WHERE wallet_id = ?
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
ORDER BY created_at DESC";
$stmt = $conn->prepare($history_query);
$stmt->bind_param("i", $wallet_id);
$stmt->execute();
$history_result = $stmt->get_result();
$balance_history = [];
while ($row = $history_result->fetch_assoc()) {
    $balance_history[] = $row;
}
$stmt->close();

// Get recent transactions for this wallet
$transactions_query = "SELECT 
    t.*,
    c.category_name,
    c.category_type
FROM transactions t
LEFT JOIN transaction_categories c ON t.category_id = c.id
WHERE (t.wallet_id = ? OR t.from_wallet_id = ? OR t.to_wallet_id = ?)
    AND t.is_transfer = FALSE
ORDER BY t.transaction_date DESC, t.created_at DESC
LIMIT 10";
$stmt = $conn->prepare($transactions_query);
$stmt->bind_param("iii", $wallet_id, $wallet_id, $wallet_id);
$stmt->execute();
$transactions_result = $stmt->get_result();
$recent_transactions = [];
while ($row = $transactions_result->fetch_assoc()) {
    $recent_transactions[] = $row;
}
$stmt->close();

// Get recent transfers involving this wallet
$transfers_query = "SELECT 
    wt.*,
    CASE 
        WHEN wt.from_wallet_id = ? THEN 'outgoing'
        WHEN wt.to_wallet_id = ? THEN 'incoming'
    END as transfer_direction,
    fw.wallet_name as from_wallet_name,
    fw.color_code as from_color,
    tw.wallet_name as to_wallet_name,
    tw.color_code as to_color
FROM wallet_transfers wt
JOIN wallets fw ON wt.from_wallet_id = fw.id
JOIN wallets tw ON wt.to_wallet_id = tw.id
WHERE (wt.from_wallet_id = ? OR wt.to_wallet_id = ?)
ORDER BY wt.transfer_date DESC, wt.created_at DESC
LIMIT 10";
$stmt = $conn->prepare($transfers_query);
$stmt->bind_param("iiii", $wallet_id, $wallet_id, $wallet_id, $wallet_id);
$stmt->execute();
$transfers_result = $stmt->get_result();
$recent_transfers = [];
while ($row = $transfers_result->fetch_assoc()) {
    $recent_transfers[] = $row;
}
$stmt->close();

// Get monthly statistics
$monthly_stats_query = "SELECT 
    DATE_FORMAT(t.transaction_date, '%Y-%m') as month_year,
    SUM(CASE WHEN t.transaction_type = 'income' THEN t.amount ELSE 0 END) as monthly_income,
    SUM(CASE WHEN t.transaction_type = 'expense' THEN t.amount ELSE 0 END) as monthly_expense,
    COUNT(t.id) as transaction_count
FROM transactions t
WHERE t.wallet_id = ?
    AND t.is_transfer = FALSE
    AND t.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY DATE_FORMAT(t.transaction_date, '%Y-%m')
ORDER BY month_year DESC";
$stmt = $conn->prepare($monthly_stats_query);
$stmt->bind_param("i", $wallet_id);
$stmt->execute();
$stats_result = $stmt->get_result();
$monthly_stats = [];
while ($row = $stats_result->fetch_assoc()) {
    $monthly_stats[] = $row;
}
$stmt->close();

// Calculate wallet metrics
$total_income = 0;
$total_expense = 0;
foreach ($recent_transactions as $transaction) {
    if ($transaction['transaction_type'] === 'income') {
        $total_income += $transaction['amount'];
    } else {
        $total_expense += $transaction['amount'];
    }
}

$net_flow = $total_income - $total_expense;

$conn->close();
?>

<div class="row">
    <!-- Wallet Header -->
    <div class="col-12 mb-4">
        <div class="card finance-card" style="border-left: 8px solid <?php echo $wallet['color_code']; ?>;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">
                            <i class="<?php echo $wallet['icon_class']; ?> me-3" 
                               style="color: <?php echo $wallet['color_code']; ?>;"></i>
                            <?php echo htmlspecialchars($wallet['wallet_name']); ?>
                            <?php if ($wallet['is_default']): ?>
                                <span class="badge bg-primary ms-2">Default</span>
                            <?php endif; ?>
                            <?php if (!$wallet['is_active']): ?>
                                <span class="badge bg-secondary ms-2">Inactive</span>
                            <?php endif; ?>
                        </h2>
                        <p class="text-muted mb-0">
                            <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($wallet['type_name']); ?>
                            <?php if ($wallet['bank_name']): ?>
                                | <i class="fas fa-university me-1"></i><?php echo htmlspecialchars($wallet['bank_name']); ?>
                            <?php endif; ?>
                            <?php if ($wallet['card_last_four']): ?>
                                | <i class="fas fa-credit-card me-1"></i>**** <?php echo htmlspecialchars($wallet['card_last_four']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="text-end">
                        <h1 class="<?php echo $wallet['balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            $<?php echo number_format($wallet['balance'], 2); ?>
                        </h1>
                        <?php if ($wallet['credit_limit'] > 0): ?>
                            <small class="text-muted">
                                Credit Limit: $<?php echo number_format($wallet['credit_limit'], 2); ?>
                                | Available: $<?php echo number_format($wallet['credit_limit'] - $wallet['balance'], 2); ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Left Column: Wallet Details & Actions -->
    <div class="col-lg-4 mb-4">
        <!-- Wallet Information -->
        <div class="card finance-card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-info-circle me-2"></i>Wallet Information
                </h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <small class="text-muted">Description:</small>
                    <p class="mb-0"><?php echo $wallet['description'] ? htmlspecialchars($wallet['description']) : '<em class="text-muted">No description</em>'; ?></p>
                </div>
                
                <div class="row">
                    <div class="col-6 mb-2">
                        <small class="text-muted">Account Number:</small>
                        <p class="mb-0"><?php echo $wallet['account_number'] ? '****' . htmlspecialchars($wallet['account_number']) : '<em class="text-muted">Not set</em>'; ?></p>
                    </div>
                    <div class="col-6 mb-2">
                        <small class="text-muted">Expiry Date:</small>
                        <p class="mb-0"><?php echo $wallet['card_expiry_date'] ? date('m/Y', strtotime($wallet['card_expiry_date'])) : '<em class="text-muted">Not set</em>'; ?></p>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-6 mb-2">
                        <small class="text-muted">Created:</small>
                        <p class="mb-0"><?php echo date('M d, Y', strtotime($wallet['created_at'])); ?></p>
                    </div>
                    <div class="col-6 mb-2">
                        <small class="text-muted">Last Updated:</small>
                        <p class="mb-0"><?php echo date('M d, Y', strtotime($wallet['updated_at'])); ?></p>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-6">
                        <small class="text-muted">Transactions:</small>
                        <h5 class="mb-0"><?php echo $wallet['transaction_count']; ?></h5>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Transfers:</small>
                        <h5 class="mb-0"><?php echo $wallet['transfer_count']; ?></h5>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card finance-card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="add_income.php?wallet=<?php echo $wallet_id; ?>" class="btn btn-success">
                        <i class="fas fa-plus-circle me-2"></i>Add Income
                    </a>
                    <a href="add_expense.php?wallet=<?php echo $wallet_id; ?>" class="btn btn-danger">
                        <i class="fas fa-minus-circle me-2"></i>Add Expense
                    </a>
                    <a href="wallet_transfer.php?from=<?php echo $wallet_id; ?>" class="btn btn-warning">
                        <i class="fas fa-exchange-alt me-2"></i>Transfer From This Wallet
                    </a>
                    <a href="wallet_transfer.php?to=<?php echo $wallet_id; ?>" class="btn btn-info">
                        <i class="fas fa-exchange-alt me-2"></i>Transfer To This Wallet
                    </a>
                    <?php if (!$wallet['is_default']): ?>
                        <a href="wallets.php?set_default=<?php echo $wallet_id; ?>" class="btn btn-outline-primary">
                            <i class="fas fa-star me-2"></i>Set as Default
                        </a>
                    <?php endif; ?>
                    <a href="wallets.php?toggle_active=<?php echo $wallet_id; ?>" 
                       class="btn btn-outline-<?php echo $wallet['is_active'] ? 'warning' : 'success'; ?>">
                        <i class="fas fa-<?php echo $wallet['is_active'] ? 'ban' : 'check'; ?> me-2"></i>
                        <?php echo $wallet['is_active'] ? 'Deactivate' : 'Activate'; ?>
                    </a>
                    <a href="wallets.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Wallets
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Balance History Chart -->
        <div class="card finance-card">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-chart-line me-2"></i>Balance Trend (30 Days)
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($balance_history)): ?>
                    <div style="height: 200px;">
                        <canvas id="balanceChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-chart-line fa-3x mb-3"></i>
                        <p>No balance history available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Middle Column: Recent Transactions -->
    <div class="col-lg-4 mb-4">
        <div class="card finance-card h-100">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-receipt me-2"></i>Recent Transactions
                </h5>
                <a href="transactions.php?wallet=<?php echo $wallet_id; ?>" class="btn btn-sm btn-light">
                    View All
                </a>
            </div>
            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                <?php if (empty($recent_transactions)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-exchange-alt fa-3x mb-3"></i>
                        <p>No transactions yet</p>
                        <a href="add_income.php?wallet=<?php echo $wallet_id; ?>" class="btn btn-success btn-sm">
                            <i class="fas fa-plus me-1"></i>Add First Transaction
                        </a>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_transactions as $transaction): 
                            $icon = getCategoryIcon($transaction['category_name'] ?? 'Other');
                            $type_class = $transaction['transaction_type'] === 'income' ? 'success' : 'danger';
                            $amount_prefix = $transaction['transaction_type'] === 'income' ? '+' : '-';
                        ?>
                            <div class="list-group-item border-0 px-0 py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($transaction['description']); ?></h6>
                                        <small class="text-muted">
                                            <i class="<?php echo $icon; ?> me-1"></i>
                                            <?php echo htmlspecialchars($transaction['category_name'] ?? 'Uncategorized'); ?>
                                            • <?php echo date('M d', strtotime($transaction['transaction_date'])); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-<?php echo $type_class; ?> rounded-pill">
                                            <?php echo $amount_prefix; ?>
                                            $<?php echo number_format($transaction['amount'], 2); ?>
                                        </span>
                                        <div>
                                            <small class="text-muted">
                                                <?php echo ucfirst($transaction['transaction_type']); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Transaction Summary -->
                <div class="mt-4 pt-3 border-top">
                    <div class="row text-center">
                        <div class="col-6">
                            <small class="text-muted">Total Income</small>
                            <h5 class="text-success">$<?php echo number_format($total_income, 2); ?></h5>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">Total Expense</small>
                            <h5 class="text-danger">$<?php echo number_format($total_expense, 2); ?></h5>
                        </div>
                        <div class="col-12 mt-2">
                            <small class="text-muted">Net Flow</small>
                            <h4 class="<?php echo $net_flow >= 0 ? 'text-success' : 'text-danger'; ?>">
                                $<?php echo number_format($net_flow, 2); ?>
                            </h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Recent Transfers & Monthly Stats -->
    <div class="col-lg-4 mb-4">
        <!-- Recent Transfers -->
        <div class="card finance-card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="fas fa-exchange-alt me-2"></i>Recent Transfers
                </h5>
            </div>
            <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                <?php if (empty($recent_transfers)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-exchange-alt fa-3x mb-3"></i>
                        <p>No transfers yet</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_transfers as $transfer): 
                            $is_incoming = $transfer['transfer_direction'] === 'incoming';
                            $other_wallet = $is_incoming ? $transfer['from_wallet_name'] : $transfer['to_wallet_name'];
                            $other_color = $is_incoming ? $transfer['from_color'] : $transfer['to_color'];
                        ?>
                            <div class="list-group-item border-0 px-0 py-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($transfer['description'] ?: 'Wallet Transfer'); ?></h6>
                                        <small class="text-muted">
                                            <?php echo date('M d', strtotime($transfer['transfer_date'])); ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-<?php echo $is_incoming ? 'success' : 'danger'; ?> rounded-pill">
                                        <?php echo $is_incoming ? '+' : '-'; ?>
                                        $<?php echo number_format($transfer['amount'], 2); ?>
                                    </span>
                                </div>
                                <div class="d-flex align-items-center">
                                    <div class="wallet-color-indicator me-2" 
                                         style="background-color: <?php echo $other_color; ?>;"></div>
                                    <small><?php echo htmlspecialchars($other_wallet); ?></small>
                                    <i class="fas fa-arrow-<?php echo $is_incoming ? 'right text-success' : 'left text-danger'; ?> mx-2"></i>
                                    <div class="wallet-color-indicator me-2" 
                                         style="background-color: <?php echo $wallet['color_code']; ?>;"></div>
                                    <small><?php echo htmlspecialchars($wallet['wallet_name']); ?></small>
                                </div>
                                <?php if ($transfer['fees'] > 0): ?>
                                    <small class="text-danger mt-1">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Fees: $<?php echo number_format($transfer['fees'], 2); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Monthly Statistics -->
        <div class="card finance-card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar me-2"></i>Monthly Statistics
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($monthly_stats)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th class="text-end">Income</th>
                                    <th class="text-end">Expense</th>
                                    <th class="text-end">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthly_stats as $stat): 
                                    $month_name = date('M Y', strtotime($stat['month_year'] . '-01'));
                                    $net = $stat['monthly_income'] - $stat['monthly_expense'];
                                ?>
                                    <tr>
                                        <td><?php echo $month_name; ?></td>
                                        <td class="text-end text-success">$<?php echo number_format($stat['monthly_income'], 2); ?></td>
                                        <td class="text-end text-danger">$<?php echo number_format($stat['monthly_expense'], 2); ?></td>
                                        <td class="text-end <?php echo $net >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            $<?php echo number_format($net, 2); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-chart-bar fa-3x mb-3"></i>
                        <p>No monthly statistics available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php 
// Helper function to get category icons
function getCategoryIcon($category_name) {
    $icons = [
        'Food' => 'fas fa-utensils',
        'Dining' => 'fas fa-utensil-spoon',
        'Transportation' => 'fas fa-car',
        'Housing' => 'fas fa-home',
        'Entertainment' => 'fas fa-film',
        'Shopping' => 'fas fa-shopping-bag',
        'Healthcare' => 'fas fa-heartbeat',
        'Education' => 'fas fa-graduation-cap',
        'Other' => 'fas fa-receipt',
        'Salary' => 'fas fa-money-check',
        'Freelance' => 'fas fa-laptop-code',
        'Investment' => 'fas fa-chart-line',
        'Gift' => 'fas fa-gift',
        'Bank Fees' => 'fas fa-university'
    ];
    
    foreach ($icons as $key => $icon) {
        if (stripos($category_name, $key) !== false) {
            return $icon;
        }
    }
    
    return 'fas fa-receipt';
}
?>

<?php require_once '../../includes/footer.php'; ?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Balance History Chart
    <?php if (!empty($balance_history)): 
        // Prepare data for chart
        $dates = [];
        $balances = [];
        
        // Get last 30 days of balance data
        $history_by_date = [];
        foreach ($balance_history as $record) {
            $date = $record['date'];
            if (!isset($history_by_date[$date])) {
                $history_by_date[$date] = [];
            }
            $history_by_date[$date][] = $record['new_balance'];
        }
        
        // Sort dates and get latest balance for each day
        krsort($history_by_date);
        $sorted_dates = array_keys($history_by_date);
        $sorted_dates = array_slice($sorted_dates, 0, 30); // Last 30 days
        
        // Get balances for each day
        foreach ($sorted_dates as $date) {
            $dates[] = date('M d', strtotime($date));
            $balances[] = end($history_by_date[$date]); // Get last balance of the day
        }
        
        // Reverse to show chronological order
        $dates = array_reverse($dates);
        $balances = array_reverse($balances);
    ?>
    const balanceCtx = document.getElementById('balanceChart').getContext('2d');
    const balanceChart = new Chart(balanceCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($dates); ?>,
            datasets: [{
                label: 'Balance',
                data: <?php echo json_encode($balances); ?>,
                borderColor: '<?php echo $wallet['color_code']; ?>',
                backgroundColor: '<?php echo $wallet['color_code']; ?>20',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Balance: $' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                x: {
                    display: true,
                    grid: {
                        display: false
                    }
                },
                y: {
                    display: true,
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    },
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toFixed(2);
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    // Print wallet details
    window.printWalletDetails = function() {
        const printContent = `
            <html>
            <head>
                <title>Wallet Details - <?php echo htmlspecialchars($wallet['wallet_name']); ?></title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                    .wallet-info { margin-bottom: 20px; }
                    .section { margin-bottom: 30px; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                    th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
                    th { background-color: #f5f5f5; }
                    .positive { color: green; }
                    .negative { color: red; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1><?php echo htmlspecialchars($wallet['wallet_name']); ?></h1>
                    <h2>Balance: $<?php echo number_format($wallet['balance'], 2); ?></h2>
                    <p>Generated on: <?php echo date('F j, Y, g:i a'); ?></p>
                </div>
                
                <div class="wallet-info">
                    <h3>Wallet Information</h3>
                    <p><strong>Type:</strong> <?php echo htmlspecialchars($wallet['type_name']); ?></p>
                    <p><strong>Bank:</strong> <?php echo $wallet['bank_name'] ? htmlspecialchars($wallet['bank_name']) : 'N/A'; ?></p>
                    <p><strong>Description:</strong> <?php echo $wallet['description'] ? htmlspecialchars($wallet['description']) : 'N/A'; ?></p>
                    <p><strong>Status:</strong> <?php echo $wallet['is_active'] ? 'Active' : 'Inactive'; ?></p>
                    <p><strong>Default:</strong> <?php echo $wallet['is_default'] ? 'Yes' : 'No'; ?></p>
                </div>
                
                <div class="section">
                    <h3>Transaction Summary</h3>
                    <table>
                        <tr>
                            <th>Total Income</th>
                            <td class="positive">$<?php echo number_format($total_income, 2); ?></td>
                        </tr>
                        <tr>
                            <th>Total Expense</th>
                            <td class="negative">$<?php echo number_format($total_expense, 2); ?></td>
                        </tr>
                        <tr>
                            <th>Net Flow</th>
                            <td class="<?php echo $net_flow >= 0 ? 'positive' : 'negative'; ?>">
                                $<?php echo number_format($net_flow, 2); ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php if (!empty($recent_transactions)): ?>
                <div class="section">
                    <h3>Recent Transactions</h3>
                    <table>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Type</th>
                            <th>Amount</th>
                        </tr>
                        <?php foreach ($recent_transactions as $transaction): ?>
                        <tr>
                            <td><?php echo date('M d', strtotime($transaction['transaction_date'])); ?></td>
                            <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                            <td><?php echo htmlspecialchars($transaction['category_name'] ?? 'Uncategorized'); ?></td>
                            <td><?php echo ucfirst($transaction['transaction_type']); ?></td>
                            <td class="<?php echo $transaction['transaction_type'] === 'income' ? 'positive' : 'negative'; ?>">
                                <?php echo $transaction['transaction_type'] === 'income' ? '+' : '-'; ?>
                                $<?php echo number_format($transaction['amount'], 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endif; ?>
            </body>
            </html>
        `;
        
        const printWindow = window.open('', '_blank');
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.focus();
        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 250);
    };
    
    // Add print button to page
    const printButton = document.createElement('button');
    printButton.className = 'btn btn-outline-secondary btn-sm';
    printButton.innerHTML = '<i class="fas fa-print me-1"></i>Print Details';
    printButton.onclick = printWalletDetails;
    
    const cardHeader = document.querySelector('.card-header.bg-info.text-white');
    if (cardHeader) {
        const headerContent = cardHeader.querySelector('.d-flex');
        if (headerContent) {
            headerContent.querySelector('.btn').before(printButton);
        }
    }
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
    transition: background-color 0.3s ease;
}

.card-header.bg-info {
    background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
}

.card-header.bg-success {
    background: linear-gradient(135deg, #28a745 0%, #218838 100%);
}

.card-header.bg-warning {
    background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
}

.table-sm th, .table-sm td {
    padding: 0.5rem;
}

@media (max-width: 768px) {
    .card-body {
        padding: 1rem;
    }
    
    .list-group-item {
        padding-left: 0;
        padding-right: 0;
    }
}
</style>