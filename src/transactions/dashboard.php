<?php
require_once '../../config/database.php';
require_once '../../includes/header.php';

// Get database connection
$conn = getDBConnection();
$user_id = getCurrentUserId();

// Get current date information
$current_month = date('m');
$current_year = date('Y');
$current_date = date('Y-m-d');

// ==================== CALCULATE KEY METRICS ====================


// 1. Total Balance (Income - Expenses)
$balance_query = "SELECT 
    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expense
FROM transactions 
WHERE user_id = ?";
$stmt = $conn->prepare($balance_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$balance_result = $stmt->get_result();
$balance_data = $balance_result->fetch_assoc();
$stmt->close();

$total_income = $balance_data['total_income'];
$total_expense = $balance_data['total_expense'];
$total_balance = $total_income - $total_expense;

// 2. Current Month Metrics
$monthly_query = "SELECT 
    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as monthly_income,
    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as monthly_expense
FROM transactions 
WHERE user_id = ? 
    AND MONTH(transaction_date) = ? 
    AND YEAR(transaction_date) = ?";
$stmt = $conn->prepare($monthly_query);
$stmt->bind_param("iii", $user_id, $current_month, $current_year);
$stmt->execute();
$monthly_result = $stmt->get_result();
$monthly_data = $monthly_result->fetch_assoc();
$stmt->close();

$monthly_income = $monthly_data['monthly_income'];
$monthly_expense = $monthly_data['monthly_expense'];
$monthly_balance = $monthly_income - $monthly_expense;

// 3. Savings Goals Progress
$savings_query = "SELECT 
    COUNT(*) as total_goals,
    SUM(target_amount) as total_target,
    SUM(current_amount) as total_saved,
    SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) as completed_goals
FROM savings_goals 
WHERE user_id = ?";
$stmt = $conn->prepare($savings_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$savings_result = $stmt->get_result();
$savings_data = $savings_result->fetch_assoc();
$stmt->close();

$total_savings_goals = $savings_data['total_goals'] ?? 0;
$total_savings_target = $savings_data['total_target'] ?? 0;
$total_savings_current = $savings_data['total_saved'] ?? 0;
$completed_savings_goals = $savings_data['completed_goals'] ?? 0;
$savings_percentage = $total_savings_target > 0 ? ($total_savings_current / $total_savings_target) * 100 : 0;

// 4. Recent Transactions (Last 10)
$recent_query = "SELECT 
    t.*,
    c.category_name,
    c.category_type
FROM transactions t
JOIN transaction_categories c ON t.category_id = c.id
WHERE t.user_id = ?
ORDER BY t.transaction_date DESC, t.created_at DESC
LIMIT 10";
$stmt = $conn->prepare($recent_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_result = $stmt->get_result();
$recent_transactions = [];
while ($row = $recent_result->fetch_assoc()) {
    $recent_transactions[] = $row;
}
$stmt->close();

// Get wallets summary
$wallets_summary = [];
$wallets_query = "SELECT * FROM wallet_summary WHERE user_id = ? ORDER BY is_default DESC, balance DESC";
$stmt = $conn->prepare($wallets_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$wallets_result = $stmt->get_result();
while ($row = $wallets_result->fetch_assoc()) {
    $wallets_summary[] = $row;
}
$stmt->close();

// 5. Top Expense Categories This Month
$top_categories_query = "SELECT 
    c.category_name,
    SUM(t.amount) as total_amount,
    COUNT(t.id) as transaction_count
FROM transactions t
JOIN transaction_categories c ON t.category_id = c.id
WHERE t.user_id = ?
    AND t.transaction_type = 'expense'
    AND MONTH(t.transaction_date) = ?
    AND YEAR(t.transaction_date) = ?
GROUP BY c.category_name
ORDER BY total_amount DESC
LIMIT 5";
$stmt = $conn->prepare($top_categories_query);
$stmt->bind_param("iii", $user_id, $current_month, $current_year);
$stmt->execute();
$categories_result = $stmt->get_result();
$top_categories = [];
while ($row = $categories_result->fetch_assoc()) {
    $top_categories[] = $row;
}
$stmt->close();

// 6. Monthly Income vs Expense Trend (Last 6 months)
$trend_query = "SELECT 
    DATE_FORMAT(transaction_date, '%Y-%m') as month_year,
    SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as monthly_income,
    SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as monthly_expense
FROM transactions 
WHERE user_id = ?
    AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
ORDER BY month_year DESC
LIMIT 6";
$stmt = $conn->prepare($trend_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$trend_result = $stmt->get_result();
$monthly_trends = [];
while ($row = $trend_result->fetch_assoc()) {
    $monthly_trends[] = $row;
}
$stmt->close();

// 7. Active Savings Goals
$active_savings_query = "SELECT 
    goal_name,
    target_amount,
    current_amount,
    deadline_date,
    is_completed,
    ROUND((current_amount / target_amount) * 100, 1) as progress_percentage,
    DATEDIFF(deadline_date, CURDATE()) as days_remaining
FROM savings_goals 
WHERE user_id = ? 
    AND is_completed = 0
ORDER BY deadline_date ASC
LIMIT 3";
$stmt = $conn->prepare($active_savings_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$active_savings_result = $stmt->get_result();
$active_savings = [];
while ($row = $active_savings_result->fetch_assoc()) {
    $active_savings[] = $row;
}
$stmt->close();

// 8. Budget Status for Current Month
$budget_status_query = "SELECT 
    c.category_name,
    b.budget_amount,
    COALESCE(SUM(t.amount), 0) as spent_amount,
    ROUND((COALESCE(SUM(t.amount), 0) / b.budget_amount) * 100, 1) as usage_percentage
FROM monthly_budgets b
JOIN transaction_categories c ON b.category_id = c.id
LEFT JOIN transactions t ON b.category_id = t.category_id 
    AND t.user_id = b.user_id 
    AND t.transaction_type = 'expense'
    AND MONTH(t.transaction_date) = MONTH(CURDATE())
    AND YEAR(t.transaction_date) = YEAR(CURDATE())
WHERE b.user_id = ?
    AND MONTH(b.month_year) = MONTH(CURDATE())
    AND YEAR(b.month_year) = YEAR(CURDATE())
GROUP BY b.id, c.category_name, b.budget_amount
ORDER BY usage_percentage DESC
LIMIT 5";
$stmt = $conn->prepare($budget_status_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$budget_status_result = $stmt->get_result();
$budget_status = [];
while ($row = $budget_status_result->fetch_assoc()) {
    $budget_status[] = $row;
}
$stmt->close();

// 9. Today's Transactions
$today_query = "SELECT 
    COUNT(*) as today_count,
    SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as today_income,
    SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as today_expense
FROM transactions 
WHERE user_id = ?
    AND DATE(transaction_date) = CURDATE()";
$stmt = $conn->prepare($today_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$today_result = $stmt->get_result();
$today_data = $today_result->fetch_assoc();
$stmt->close();

$today_count = $today_data['today_count'] ?? 0;
$today_income = $today_data['today_income'] ?? 0;
$today_expense = $today_data['today_expense'] ?? 0;

// 10. Calculate Average Daily Expense for Current Month
$avg_daily_query = "SELECT 
    AVG(daily_total) as avg_daily_expense
FROM (
    SELECT transaction_date, SUM(amount) as daily_total
    FROM transactions
    WHERE user_id = ?
        AND transaction_type = 'expense'
        AND MONTH(transaction_date) = MONTH(CURDATE())
        AND YEAR(transaction_date) = YEAR(CURDATE())
    GROUP BY transaction_date
) as daily_totals";
$stmt = $conn->prepare($avg_daily_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$avg_result = $stmt->get_result();
$avg_daily_expense = $avg_result->fetch_assoc()['avg_daily_expense'] ?? 0;
$stmt->close();

$conn->close();
?>

<div class="dashboard">
    <!-- Welcome Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card finance-card">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1">
                                <i class="fas fa-tachometer-alt text-primary me-2"></i>
                                Financial Dashboard
                            </h3>
                            <p class="text-muted mb-0">
                                <i class="fas fa-calendar me-1"></i>
                                <?php echo date('F d, Y'); ?> | 
                                <i class="fas fa-user me-1 ms-2"></i>
                                Welcome back!
                            </p>
                        </div>
                        <div class="text-end">
                            <button class="btn btn-primary" onclick="refreshDashboard()">
                                <i class="fas fa-sync-alt me-1"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== KEY METRICS CARDS ==================== -->
    <div class="row mb-4">
        <!-- Total Balance Card -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card finance-card border-start border-4 <?php echo $total_balance >= 0 ? 'border-success' : 'border-danger'; ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted fw-normal mb-2">
                                <i class="fas fa-wallet me-1"></i> Total Balance
                            </h6>
                            <h3 class="mb-0 <?php echo $total_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                $<?php echo number_format($total_balance, 2); ?>
                            </h3>
                            <small class="text-muted">All Time</small>
                        </div>
                        <div class="icon-shape <?php echo $total_balance >= 0 ? 'bg-success' : 'bg-danger'; ?> text-white rounded-circle p-3">
                            <i class="fas fa-balance-scale fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Income Card -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card finance-card border-start border-4 border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted fw-normal mb-2">
                                <i class="fas fa-arrow-circle-up me-1"></i> Monthly Income
                            </h6>
                            <h3 class="mb-0 text-success">
                                $<?php echo number_format($monthly_income, 2); ?>
                            </h3>
                            <small class="text-muted"><?php echo date('F Y'); ?></small>
                        </div>
                        <div class="icon-shape bg-success text-white rounded-circle p-3">
                            <i class="fas fa-money-bill-wave fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Expense Card -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card finance-card border-start border-4 border-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted fw-normal mb-2">
                                <i class="fas fa-arrow-circle-down me-1"></i> Monthly Expense
                            </h6>
                            <h3 class="mb-0 text-danger">
                                $<?php echo number_format($monthly_expense, 2); ?>
                            </h3>
                            <small class="text-muted"><?php echo date('F Y'); ?></small>
                        </div>
                        <div class="icon-shape bg-danger text-white rounded-circle p-3">
                            <i class="fas fa-shopping-cart fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Savings Progress Card -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card finance-card border-start border-4 border-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted fw-normal mb-2">
                                <i class="fas fa-piggy-bank me-1"></i> Savings Progress
                            </h6>
                            <h3 class="mb-0 text-info">
                                $<?php echo number_format($total_savings_current, 2); ?>
                                <small class="text-muted fs-6">/ $<?php echo number_format($total_savings_target, 2); ?></small>
                            </h3>
                            <small class="text-muted"><?php echo number_format($savings_percentage, 1); ?>% Complete</small>
                        </div>
                        <div class="icon-shape bg-info text-white rounded-circle p-3">
                            <i class="fas fa-bullseye fa-2x"></i>
                        </div>
                    </div>
                    <div class="progress mt-3" style="height: 8px;">
                        <div class="progress-bar bg-info" role="progressbar" 
                             style="width: <?php echo min($savings_percentage, 100); ?>%">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== CHARTS & VISUALIZATION ==================== -->
    <div class="row mb-4">
        <!-- Monthly Trend Chart -->
        <div class="col-xl-8 mb-4">
            <div class="card finance-card h-100">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line me-2"></i> Income vs Expense Trend (Last 6 Months)
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($monthly_trends)): ?>
                        <div class="chart-container" style="height: 300px;">
                            <canvas id="trendChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No trend data available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Expense Categories -->
        <div class="col-xl-4 mb-4">
            <div class="card finance-card h-100">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie me-2"></i> Top Expense Categories
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($top_categories)): ?>
                        <div class="categories-list">
                            <?php foreach ($top_categories as $category): 
                                $percentage = ($category['total_amount'] / $monthly_expense) * 100;
                                $icon = getCategoryIcon($category['category_name']);
                            ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>
                                            <i class="<?php echo $icon; ?> me-2"></i>
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </span>
                                        <span class="text-danger">
                                            $<?php echo number_format($category['total_amount'], 2); ?>
                                        </span>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-danger" role="progressbar" 
                                             style="width: <?php echo min($percentage, 100); ?>%">
                                        </div>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo number_format($percentage, 1); ?>% • 
                                        <?php echo $category['transaction_count']; ?> transaction(s)
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-chart-pie fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No expense data for this month</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== SAVINGS GOALS & BUDGETS ==================== -->
    <div class="row mb-4">
        <!-- Active Savings Goals -->
        <div class="col-xl-6 mb-4">
            <div class="card finance-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-bullseye me-2"></i> Active Savings Goals
                    </h5>
                    <a href="savings.php" class="btn btn-sm btn-outline-info">
                        <i class="fas fa-plus me-1"></i> Add Goal
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($active_savings)): ?>
                        <div class="savings-goals">
                            <?php foreach ($active_savings as $goal): 
                                $progress = $goal['progress_percentage'];
                                $days_left = $goal['days_remaining'];
                                $progress_class = $progress >= 100 ? 'bg-success' : 
                                                ($days_left < 0 ? 'bg-danger' : 
                                                ($days_left < 30 ? 'bg-warning' : 'bg-info'));
                            ?>
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between mb-2">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($goal['goal_name']); ?></h6>
                                        <span class="<?php echo $days_left < 0 ? 'text-danger' : 'text-muted'; ?>">
                                            <?php echo $days_left < 0 ? 'Overdue' : $days_left . ' days left'; ?>
                                        </span>
                                    </div>
                                    <div class="progress mb-2" style="height: 12px;">
                                        <div class="progress-bar <?php echo $progress_class; ?>" role="progressbar" 
                                             style="width: <?php echo min($progress, 100); ?>%">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <small class="text-muted">
                                            $<?php echo number_format($goal['current_amount'], 2); ?> / 
                                            $<?php echo number_format($goal['target_amount'], 2); ?>
                                        </small>
                                        <small class="fw-bold <?php echo $progress_class; ?>">
                                            <?php echo number_format($progress, 1); ?>%
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center mt-3">
                            <a href="savings.php" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-eye me-1"></i> View All Goals
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-bullseye fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No active savings goals</p>
                            <a href="savings.php" class="btn btn-info">
                                <i class="fas fa-plus me-1"></i> Create Your First Goal
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Budget Status -->
        <div class="col-xl-6 mb-4">
            <div class="card finance-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-bar me-2"></i> Budget Status
                    </h5>
                    <a href="budgets.php" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-cog me-1"></i> Manage
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($budget_status)): ?>
                        <div class="budget-list">
                            <?php foreach ($budget_status as $budget): 
                                $usage = $budget['usage_percentage'];
                                $remaining = $budget['budget_amount'] - $budget['spent_amount'];
                                $status_class = $usage >= 100 ? 'danger' : 
                                             ($usage >= 80 ? 'warning' : 'success');
                            ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span><?php echo htmlspecialchars($budget['category_name']); ?></span>
                                        <span class="text-<?php echo $status_class; ?>">
                                            <?php echo number_format($usage, 1); ?>%
                                        </span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-<?php echo $status_class; ?>" 
                                             role="progressbar" 
                                             style="width: <?php echo min($usage, 100); ?>%">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <small class="text-muted">
                                            Spent: $<?php echo number_format($budget['spent_amount'], 2); ?>
                                        </small>
                                        <small class="text-muted">
                                            Left: $<?php echo number_format($remaining, 2); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No budgets set for this month</p>
                            <a href="budgets.php" class="btn btn-warning">
                                <i class="fas fa-plus me-1"></i> Set Up Budgets
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== RECENT TRANSACTIONS & QUICK STATS ==================== -->
    <div class="row">
        <!-- Recent Transactions -->
        <div class="col-xl-8 mb-4">
            <div class="card finance-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i> Recent Transactions
                    </h5>
                    <div>
                        <span class="badge bg-light text-dark me-2">
                            Today: <?php echo $today_count; ?> transactions
                        </span>
                        <a href="transactions.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-list me-1"></i> View All
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($recent_transactions)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_transactions as $transaction): 
                                        $icon = getCategoryIcon($transaction['category_name']);
                                        $type_class = $transaction['transaction_type'] === 'income' ? 'success' : 'danger';
                                        $amount_prefix = $transaction['transaction_type'] === 'income' ? '+' : '-';
                                    ?>
                                        <tr>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('M d', strtotime($transaction['transaction_date'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <i class="<?php echo $icon; ?> me-2 text-muted"></i>
                                                    <span><?php echo htmlspecialchars($transaction['description']); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?php echo htmlspecialchars($transaction['category_name']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $type_class; ?>">
                                                    <?php echo ucfirst($transaction['transaction_type']); ?>
                                                </span>
                                            </td>
                                            <td class="text-<?php echo $type_class; ?> fw-bold">
                                                <?php echo $amount_prefix; ?>
                                                $<?php echo number_format($transaction['amount'], 2); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No transactions yet</p>
                            <div class="d-flex justify-content-center gap-2">
                                <a href="add_income.php" class="btn btn-success">
                                    <i class="fas fa-plus-circle me-1"></i> Add Income
                                </a>
                                <a href="add_expense.php" class="btn btn-danger">
                                    <i class="fas fa-minus-circle me-1"></i> Add Expense
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Stats & Actions -->
        <div class="col-xl-4 mb-4">
            <!-- Today's Summary -->
            <div class="card finance-card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-day me-2"></i> Today's Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="p-3 border rounded bg-success bg-opacity-10">
                                <h3 class="text-success mb-0">$<?php echo number_format($today_income, 2); ?></h3>
                                <small class="text-muted">Income</small>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="p-3 border rounded bg-danger bg-opacity-10">
                                <h3 class="text-danger mb-0">$<?php echo number_format($today_expense, 2); ?></h3>
                                <small class="text-muted">Expense</small>
                            </div>
                        </div>
                    </div>
                    <div class="text-center">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo $today_count; ?> transactions today
                        </small>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card finance-card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-bolt me-2"></i> Quick Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="add_income.php" class="btn btn-success">
                            <i class="fas fa-plus-circle me-2"></i> Add Income
                        </a>
                        <a href="add_expense.php" class="btn btn-danger">
                            <i class="fas fa-minus-circle me-2"></i> Add Expense
                        </a>
                        <a href="transactions.php" class="btn btn-primary">
                            <i class="fas fa-list me-2"></i> View Transactions
                        </a>
                        <a href="reports.php" class="btn btn-info">
                            <i class="fas fa-chart-bar me-2"></i> Generate Reports
                        </a>
                    </div>
                </div>
            </div>

            <!-- Financial Health -->
            <div class="card finance-card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-heartbeat me-2"></i> Financial Health
                    </h5>
                </div>
                <div class="card-body">
                    <?php
                    // Calculate financial health metrics
                    $savings_rate = $monthly_income > 0 ? ($monthly_balance / $monthly_income) * 100 : 0;
                    $expense_to_income = $monthly_income > 0 ? ($monthly_expense / $monthly_income) * 100 : 100;
                    
                    // Determine health status
                    if ($savings_rate >= 20) {
                        $health_status = 'Excellent';
                        $health_class = 'success';
                        $health_icon = 'fas fa-check-circle';
                    } elseif ($savings_rate >= 10) {
                        $health_status = 'Good';
                        $health_class = 'info';
                        $health_icon = 'fas fa-thumbs-up';
                    } elseif ($savings_rate >= 0) {
                        $health_status = 'Fair';
                        $health_class = 'warning';
                        $health_icon = 'fas fa-exclamation-triangle';
                    } else {
                        $health_status = 'Poor';
                        $health_class = 'danger';
                        $health_icon = 'fas fa-exclamation-circle';
                    }
                    ?>
                    <div class="text-center mb-3">
                        <div class="health-indicator mb-2">
                            <i class="<?php echo $health_icon; ?> fa-3x text-<?php echo $health_class; ?>"></i>
                        </div>
                        <h4 class="text-<?php echo $health_class; ?>"><?php echo $health_status; ?></h4>
                    </div>
                    
                    <div class="health-metrics">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Savings Rate:</span>
                            <span class="fw-bold text-<?php echo $savings_rate >= 0 ? 'success' : 'danger'; ?>">
                                <?php echo number_format($savings_rate, 1); ?>%
                            </span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Expense/Income Ratio:</span>
                            <span class="fw-bold"><?php echo number_format($expense_to_income, 1); ?>%</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Avg Daily Expense:</span>
                            <span class="fw-bold">$<?php echo number_format($avg_daily_expense, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Active Goals:</span>
                            <span class="fw-bold"><?php echo count($active_savings); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Wallets Summary -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card finance-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-wallet me-2"></i> My Wallets
                </h5>
                <a href="wallets.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-cog me-1"></i> Manage Wallets
                </a>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($wallets_summary as $wallet): ?>
                        <div class="col-md-4 mb-3">
                            <div class="card wallet-card" 
                                 style="border-left: 5px solid <?php echo $wallet['color_code']; ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">
                                                <i class="<?php echo $wallet['icon_class']; ?> me-2"></i>
                                                <?php echo htmlspecialchars($wallet['wallet_name']); ?>
                                            </h6>
                                            <small class="text-muted"><?php echo $wallet['type_name']; ?></small>
                                        </div>
                                        <?php if ($wallet['is_default']): ?>
                                            <span class="badge bg-primary">Default</span>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="mt-3 mb-0 <?php echo $wallet['balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        $<?php echo number_format($wallet['balance'], 2); ?>
                                    </h3>
                                    <div class="mt-2 d-flex justify-content-between">
                                        <small class="text-muted">
                                            <i class="fas fa-arrow-up text-success me-1"></i>
                                            $<?php echo number_format($wallet['total_income'], 2); ?>
                                        </small>
                                        <small class="text-muted">
                                            <i class="fas fa-arrow-down text-danger me-1"></i>
                                            $<?php echo number_format($wallet['total_expense'], 2); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
// Helper function to get icons for categories
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
        'Utilities' => 'fas fa-bolt',
        'Groceries' => 'fas fa-shopping-cart',
        'Restaurants' => 'fas fa-utensils',
        'Travel' => 'fas fa-plane',
        'Subscriptions' => 'fas fa-newspaper',
        'Personal Care' => 'fas fa-bath',
        'Gifts' => 'fas fa-gift',
        'Insurance' => 'fas fa-shield-alt',
        'Salary' => 'fas fa-money-check',
        'Freelance' => 'fas fa-laptop-code',
        'Investment' => 'fas fa-chart-line',
        'Gift' => 'fas fa-gift'
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

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ==================== TREND CHART ====================
    <?php if (!empty($monthly_trends)): 
        $trend_labels = [];
        $income_data = [];
        $expense_data = [];
        
        // Reverse to show chronological order
        $reversed_trends = array_reverse($monthly_trends);
        foreach ($reversed_trends as $trend) {
            $trend_labels[] = date('M Y', strtotime($trend['month_year'] . '-01'));
            $income_data[] = $trend['monthly_income'];
            $expense_data[] = $trend['monthly_expense'];
        }
    ?>
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($trend_labels); ?>,
            datasets: [
                {
                    label: 'Income',
                    data: <?php echo json_encode($income_data); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Expense',
                    data: <?php echo json_encode($expense_data); ?>,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': $' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
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

    // ==================== DASHBOARD FUNCTIONS ====================
    
    // Refresh Dashboard
    window.refreshDashboard = function() {
        const refreshBtn = document.querySelector('button[onclick="refreshDashboard()"]');
        const originalHTML = refreshBtn.innerHTML;
        
        // Show loading state
        refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Refreshing...';
        refreshBtn.disabled = true;
        
        // Simulate refresh
        setTimeout(() => {
            location.reload();
        }, 500);
    };
    
    // Auto-refresh every 5 minutes (optional)
    // setInterval(refreshDashboard, 300000);
    
    // Animate numbers on page load
    function animateValue(element, start, end, duration) {
        if (start === end) return;
        
        const range = end - start;
        const increment = end > start ? 1 : -1;
        const stepTime = Math.abs(Math.floor(duration / range));
        let current = start;
        
        const timer = setInterval(function() {
            current += increment;
            element.textContent = '$' + current.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            
            if (current === end) {
                clearInterval(timer);
            }
        }, stepTime);
    }
    
    // Animate key metrics
    const metricsToAnimate = [
        { id: 'totalBalance', value: <?php echo $total_balance; ?> },
        { id: 'monthlyIncome', value: <?php echo $monthly_income; ?> },
        { id: 'monthlyExpense', value: <?php echo $monthly_expense; ?> }
    ];
    
    // Add IDs to metrics elements (you need to add these IDs in the HTML)
    // Or animate directly by selecting the elements
    
    // Quick stats hover effects
    const statCards = document.querySelectorAll('.finance-card');
    statCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
            this.style.transition = 'transform 0.3s ease';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Today's date in header
    function updateDateTime() {
        const now = new Date();
        const options = { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        };
        const dateTimeString = now.toLocaleDateString('en-US', options);
        
        const dateElement = document.querySelector('.dashboard-date');
        if (dateElement) {
            dateElement.textContent = dateTimeString;
        }
    }
    
    // Update time every minute
    updateDateTime();
    setInterval(updateDateTime, 60000);
    
    // Add CSS for animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        
        .icon-shape {
            transition: all 0.3s ease;
        }
        
        .icon-shape:hover {
            transform: rotate(15deg);
        }
        
        .progress-bar {
            transition: width 1s ease-in-out;
        }
        
        .health-indicator {
            animation: pulse 3s infinite;
        }
    `;
    document.head.appendChild(style);
    
    // Add pulse animation to important metrics
    const importantMetrics = document.querySelectorAll('.icon-shape');
    importantMetrics.forEach(icon => {
        icon.addEventListener('mouseenter', function() {
            this.classList.add('pulse-animation');
        });
        
        icon.addEventListener('mouseleave', function() {
            this.classList.remove('pulse-animation');
        });
    });
});
</script>

<style>
.dashboard {
    padding-bottom: 2rem;
}

.icon-shape {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.health-indicator {
    font-size: 3rem;
}

.table-hover tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.02);
    transform: translateX(5px);
    transition: all 0.3s ease;
}

.chart-container {
    position: relative;
}

.budget-list .progress, 
.savings-goals .progress {
    border-radius: 10px;
    overflow: hidden;
}

.budget-list .progress-bar,
.savings-goals .progress-bar {
    border-radius: 10px;
}

.quick-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.stat-card {
    padding: 1.5rem;
    border-radius: 10px;
    text-align: center;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}

.health-metrics {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 1rem;
    border-radius: 10px;
    margin-top: 1rem;
}

@media (max-width: 768px) {
    .icon-shape {
        width: 50px;
        height: 50px;
        padding: 0.5rem !important;
    }
    
    .icon-shape i {
        font-size: 1.5rem !important;
    }
    
    .table-responsive {
        font-size: 0.9rem;
    }
}
</style>