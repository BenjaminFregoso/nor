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
    'amount' => '',
    'description' => '',
    'transaction_date' => date('Y-m-d'),
    'category_id' => ''
];

// Get expense categories for dropdown
$categories = [];
$category_query = "SELECT id, category_name, description FROM transaction_categories 
                   WHERE category_type = 'expense' 
                   ORDER BY category_name";
$stmt = $conn->prepare($category_query);
//$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}
$stmt->close();

// Get monthly budgets for warning system
$budgets = [];
$budget_query = "SELECT c.category_name, b.budget_amount, 
                 COALESCE(SUM(t.amount), 0) as spent_amount,
                 MONTH(b.month_year) as budget_month,
                 YEAR(b.month_year) as budget_year
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
                 GROUP BY b.id, c.category_name, b.budget_amount";
$stmt = $conn->prepare($budget_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$budget_result = $stmt->get_result();
while ($row = $budget_result->fetch_assoc()) {
    $budgets[] = $row;
}
$stmt->close();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
    $is_essential = isset($_POST['is_essential']) ? 1 : 0;
    
    // Validate required fields
    $errors = [];
    
    if ($amount <= 0) {
        $errors[] = "Please enter a valid amount greater than 0.";
    }
    
    if (empty($description)) {
        $errors[] = "Please enter a description.";
    }
    
    if ($category_id <= 0) {
        $errors[] = "Please select a category.";
    }
    
    // Validate date
    if (!strtotime($transaction_date)) {
        $errors[] = "Please enter a valid date.";
    }
    
    // Check if expense exceeds budget (warning only)
    $budget_warning = "";
    foreach ($budgets as $budget) {
        $category_query = "SELECT category_name FROM transaction_categories WHERE id = ?";
        $cat_stmt = $conn->prepare($category_query);
        $cat_stmt->bind_param("i", $category_id);
        $cat_stmt->execute();
        $cat_result = $cat_stmt->get_result();
        $current_category = $cat_result->fetch_assoc();
        $cat_stmt->close();
        
        if ($current_category && $budget['category_name'] === $current_category['category_name']) {
            $new_total = $budget['spent_amount'] + $amount;
            $remaining = $budget['budget_amount'] - $new_total;
            
            if ($new_total > $budget['budget_amount']) {
                $budget_warning = "<div class='alert alert-warning'>
                    <i class='fas fa-exclamation-triangle'></i> 
                    <strong>Budget Warning:</strong> This expense will exceed your monthly budget for '{$budget['category_name']}'. 
                    Remaining budget after this expense: $" . number_format($remaining, 2) . "
                </div>";
            }
            break;
        }
    }
    
    // If no errors, insert into database
    if (empty($errors)) {
        // Start transaction for data consistency
        $conn->begin_transaction();
        
        try {
            // Insert expense transaction
            $insert_query = "INSERT INTO transactions 
                             (user_id, category_id, transaction_type, amount, description, transaction_date) 
                             VALUES (?, ?, 'expense', ?, ?, ?)";
            
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("iidss", $user_id, $category_id, $amount, $description, $transaction_date);
            
            if (!$stmt->execute()) {
                throw new Exception("Error adding expense: " . $stmt->error);
            }
            
            $transaction_id = $stmt->insert_id;
            $stmt->close();
            
            // If this is an essential expense (needs), update a special tracking table if exists
            // You can extend this functionality as needed
            
            $conn->commit();
            
            $success_message = "Expense successfully added!" . $budget_warning;
            
            // Clear form data
            $form_data = [
                'amount' => '',
                'description' => '',
                'transaction_date' => date('Y-m-d'),
                'category_id' => ''
            ];
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
            // Keep form data for re-filling
            $form_data = [
                'amount' => $_POST['amount'] ?? '',
                'description' => $_POST['description'] ?? '',
                'transaction_date' => $_POST['transaction_date'] ?? date('Y-m-d'),
                'category_id' => $_POST['category_id'] ?? ''
            ];
        }
    } else {
        $error_message = implode("<br>", $errors);
        // Keep form data for re-filling
        $form_data = [
            'amount' => $_POST['amount'] ?? '',
            'description' => $_POST['description'] ?? '',
            'transaction_date' => $_POST['transaction_date'] ?? date('Y-m-d'),
            'category_id' => $_POST['category_id'] ?? ''
        ];
    }
}

// Get recent expense transactions
$recent_expenses = [];
$recent_query = "SELECT t.amount, t.description, t.transaction_date, c.category_name 
                 FROM transactions t 
                 JOIN transaction_categories c ON t.category_id = c.id 
                 WHERE t.user_id = ? AND t.transaction_type = 'expense' 
                 ORDER BY t.transaction_date DESC, t.created_at DESC 
                 LIMIT 5";
$stmt = $conn->prepare($recent_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_result = $stmt->get_result();
while ($row = $recent_result->fetch_assoc()) {
    $recent_expenses[] = $row;
}
$stmt->close();

// Get current month's total expenses by category
$category_expenses_query = "SELECT c.category_name, 
                           COALESCE(SUM(t.amount), 0) as total_spent,
                           COUNT(t.id) as transaction_count
                           FROM transaction_categories c
                           LEFT JOIN transactions t ON c.id = t.category_id 
                               AND t.user_id = ? 
                               AND t.transaction_type = 'expense'
                               AND MONTH(t.transaction_date) = MONTH(CURDATE())
                               AND YEAR(t.transaction_date) = YEAR(CURDATE())
                           WHERE c.category_type = 'expense'
                               AND (c.user_id = ? OR c.user_id IS NULL)
                           GROUP BY c.id, c.category_name
                           ORDER BY total_spent DESC";
$stmt = $conn->prepare($category_expenses_query);
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$category_expenses_result = $stmt->get_result();
$category_expenses = [];
while ($row = $category_expenses_result->fetch_assoc()) {
    $category_expenses[] = $row;
}
$stmt->close();

// Get current month's total expense
$monthly_expense_query = "SELECT COALESCE(SUM(amount), 0) as total_expense 
                         FROM transactions 
                         WHERE user_id = ? 
                         AND transaction_type = 'expense' 
                         AND MONTH(transaction_date) = MONTH(CURDATE()) 
                         AND YEAR(transaction_date) = YEAR(CURDATE())";
$stmt = $conn->prepare($monthly_expense_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$monthly_result = $stmt->get_result();
$monthly_expense = $monthly_result->fetch_assoc()['total_expense'];
$stmt->close();

// Get average daily expense for current month
$avg_daily_query = "SELECT AVG(daily_total) as avg_daily_expense
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

<div class="row">
    <!-- Left Column: Add Expense Form -->
    <div class="col-md-8">
        <div class="card finance-card expense-color mb-4">
            <div class="card-header bg-danger text-white">
                <h4 class="mb-0">
                    <i class="fas fa-minus-circle me-2"></i>Add New Expense
                </h4>
            </div>
            <div class="card-body">
                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                    <div class="alert-container">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $success_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Expense Form -->
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="expenseForm">
                    <div class="row">
                        <!-- Amount -->
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label required-field">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-dollar-sign"></i>
                                </span>
                                <input type="number" 
                                       class="form-control" 
                                       id="amount" 
                                       name="amount" 
                                       step="0.01" 
                                       min="0.01" 
                                       value="<?php echo htmlspecialchars($form_data['amount']); ?>" 
                                       required 
                                       placeholder="0.00">
                            </div>
                            <div class="form-text">Enter the expense amount</div>
                        </div>
                        
                        <!-- Date -->
                        <div class="col-md-6 mb-3">
                            <label for="transaction_date" class="form-label required-field">Date</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-calendar"></i>
                                </span>
                                <input type="date" 
                                       class="form-control" 
                                       id="transaction_date" 
                                       name="transaction_date" 
                                       value="<?php echo htmlspecialchars($form_data['transaction_date']); ?>" 
                                       required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Category with icons -->
                    <div class="mb-3">
                        <label for="category_id" class="form-label required-field">Category</label>
                        <select class="form-select" id="category_id" name="category_id" required onchange="showCategoryDescription()">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $category): 
                                $icon = getCategoryIcon($category['category_name']);
                            ?>
                                <option value="<?php echo $category['id']; ?>" 
                                    data-description="<?php echo htmlspecialchars($category['description']); ?>"
                                    <?php echo ($form_data['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                    <i class="<?php echo $icon; ?> me-2"></i>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="categoryDescription" class="form-text mt-2 text-muted"></div>
                    </div>
                    
                    <!-- Essential Expense Checkbox -->
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_essential" name="is_essential" value="1">
                            <label class="form-check-label" for="is_essential">
                                <i class="fas fa-exclamation-circle text-warning me-1"></i>
                                This is an essential expense (need)
                            </label>
                            <div class="form-text">Essential expenses are necessary costs like rent, utilities, groceries</div>
                        </div>
                    </div>
                    
                    <!-- Description -->
                    <div class="mb-3">
                        <label for="description" class="form-label required-field">Description</label>
                        <textarea class="form-control" 
                                  id="description" 
                                  name="description" 
                                  rows="3" 
                                  required 
                                  placeholder="Enter description (e.g., Groceries at Walmart, Gas for car, etc.)"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                        <div class="form-text">Be specific to track spending patterns</div>
                    </div>
                    
                    <!-- Budget Warning Display (dynamic) -->
                    <div id="budgetWarning" class="alert alert-warning d-none">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span id="warningText"></span>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="reset" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-redo me-1"></i>Clear Form
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-save me-1"></i>Add Expense
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Monthly Expense Stats -->
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="card finance-card">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">
                            <i class="fas fa-calendar-alt me-1"></i>This Month's Expenses
                        </h6>
                        <h3 class="card-title text-danger amount-display">
                            $<?php echo number_format($monthly_expense, 2); ?>
                        </h3>
                        <small class="text-muted">Total spent this month</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card finance-card">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">
                            <i class="fas fa-chart-line me-1"></i>Avg Daily Expense
                        </h6>
                        <h3 class="card-title text-warning amount-display">
                            $<?php echo number_format($avg_daily_expense, 2); ?>
                        </h3>
                        <small class="text-muted">Average per day this month</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card finance-card">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">
                            <i class="fas fa-tags me-1"></i>Categories Used
                        </h6>
                        <h3 class="card-title text-info amount-display">
                            <?php echo count($category_expenses); ?>
                        </h3>
                        <small class="text-muted">Active categories this month</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Category Breakdown (Mini Chart) -->
        <div class="card finance-card mt-3">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-chart-pie me-2"></i>Monthly Spending by Category
                </h5>
            </div>
            <div class="card-body">
                <?php if (array_sum(array_column($category_expenses, 'total_spent')) > 0): ?>
                    <div class="category-breakdown">
                        <?php foreach ($category_expenses as $expense): 
                            if ($expense['total_spent'] > 0):
                                $percentage = ($expense['total_spent'] / $monthly_expense) * 100;
                        ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between">
                                    <span>
                                        <i class="<?php echo getCategoryIcon($expense['category_name']); ?> me-2"></i>
                                        <?php echo htmlspecialchars($expense['category_name']); ?>
                                    </span>
                                    <span class="text-danger">
                                        $<?php echo number_format($expense['total_spent'], 2); ?>
                                        <small class="text-muted">(<?php echo number_format($percentage, 1); ?>%)</small>
                                    </span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-danger" 
                                         role="progressbar" 
                                         style="width: <?php echo min($percentage, 100); ?>%">
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <?php echo $expense['transaction_count']; ?> transaction(s)
                                </small>
                            </div>
                        <?php endif; 
                        endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-chart-pie fa-2x mb-2"></i>
                        <p>No expense data for this month yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Recent Expenses & Budgets -->
    <div class="col-md-4">
        <!-- Recent Expense Transactions -->
        <div class="card finance-card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-history me-2"></i>Recent Expenses
                </h5>
            </div>
            <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                <?php if (empty($recent_expenses)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-receipt fa-2x mb-2"></i>
                        <p>No recent expenses</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_expenses as $expense): 
                            $icon = getCategoryIcon($expense['category_name']);
                        ?>
                            <div class="list-group-item border-0 px-0 py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div style="max-width: 60%;">
                                        <h6 class="mb-1 text-truncate" title="<?php echo htmlspecialchars($expense['description']); ?>">
                                            <i class="<?php echo $icon; ?> me-1"></i>
                                            <?php echo htmlspecialchars($expense['description']); ?>
                                        </h6>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($expense['category_name']); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-danger rounded-pill">
                                            $<?php echo number_format($expense['amount'], 2); ?>
                                        </span>
                                        <div class="text-muted small">
                                            <?php echo date('M d', strtotime($expense['transaction_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 text-center">
                        <a href="transactions.php?type=expense" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye me-1"></i>View All Expenses
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Budget Status -->
        <div class="card finance-card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar me-2"></i>Budget Status
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($budgets)): ?>
                    <?php foreach ($budgets as $budget): 
                        $percentage = ($budget['spent_amount'] / $budget['budget_amount']) * 100;
                        $progress_class = $percentage >= 100 ? 'bg-danger' : ($percentage >= 80 ? 'bg-warning' : 'bg-success');
                    ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small><?php echo htmlspecialchars($budget['category_name']); ?></small>
                                <small>
                                    $<?php echo number_format($budget['spent_amount'], 2); ?> / 
                                    $<?php echo number_format($budget['budget_amount'], 2); ?>
                                </small>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar <?php echo $progress_class; ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo min($percentage, 100); ?>%">
                                </div>
                            </div>
                            <small class="text-muted">
                                <?php echo number_format($budget['budget_amount'] - $budget['spent_amount'], 2); ?> remaining
                            </small>
                        </div>
                    <?php endforeach; ?>
                    <div class="text-center mt-2">
                        <a href="budgets.php" class="btn btn-outline-warning btn-sm">
                            <i class="fas fa-cog me-1"></i>Manage Budgets
                        </a>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-chart-bar fa-2x mb-2"></i>
                        <p>No budgets set for this month</p>
                        <a href="budgets.php" class="btn btn-outline-warning btn-sm">
                            <i class="fas fa-plus me-1"></i>Create Budget
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card finance-card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="add_income.php" class="btn btn-outline-success">
                        <i class="fas fa-plus-circle me-1"></i>Add Income
                    </a>
                    <a href="transactions.php" class="btn btn-outline-primary">
                        <i class="fas fa-list me-1"></i>View All Transactions
                    </a>
                    <a href="reports.php" class="btn btn-outline-info">
                        <i class="fas fa-chart-pie me-1"></i>Spending Reports
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
// Helper function to get icons for categories
function getCategoryIcon($category_name) {
    $icons = [
        'Food & Dining' => 'fas fa-utensils',
        'Transportation' => 'fas fa-car',
        'Housing' => 'fas fa-home',
        'Entertainment' => 'fas fa-film',
        'Shopping' => 'fas fa-shopping-bag',
        'Healthcare' => 'fas fa-heartbeat',
        'Education' => 'fas fa-graduation-cap',
        'Other Expense' => 'fas fa-receipt',
        'Utilities' => 'fas fa-bolt',
        'Groceries' => 'fas fa-shopping-cart',
        'Restaurants' => 'fas fa-utensil-spoon',
        'Travel' => 'fas fa-plane',
        'Subscriptions' => 'fas fa-newspaper',
        'Personal Care' => 'fas fa-bath',
        'Gifts' => 'fas fa-gift',
        'Insurance' => 'fas fa-shield-alt'
    ];
    
    foreach ($icons as $key => $icon) {
        if (stripos($category_name, $key) !== false) {
            return $icon;
        }
    }
    
    return 'fas fa-receipt'; // Default icon
}
?>

<?php require_once '../../includes/footer.php'; ?>

<!-- Additional JavaScript for expense page -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const amountInput = document.getElementById('amount');
    const categorySelect = document.getElementById('category_id');
    const descriptionInput = document.getElementById('description');
    const budgetWarning = document.getElementById('budgetWarning');
    const warningText = document.getElementById('warningText');
    const form = document.getElementById('expenseForm');
    
    // Budget data from PHP (converted to JavaScript)
    const budgets = <?php echo json_encode($budgets); ?>;
    const categories = <?php echo json_encode($categories); ?>;
    
    // Auto-format amount input
    amountInput.addEventListener('blur', function() {
        if (this.value) {
            this.value = parseFloat(this.value).toFixed(2);
            checkBudget();
        }
    });
    
    // Check budget when category or amount changes
    categorySelect.addEventListener('change', checkBudget);
    amountInput.addEventListener('input', checkBudget);
    
    // Form validation
    form.addEventListener('submit', function(event) {
        const amount = parseFloat(amountInput.value);
        if (amount <= 0) {
            alert('Please enter a valid amount greater than 0.');
            event.preventDefault();
            amountInput.focus();
            return;
        }
        
        // Optional: Confirm if expense exceeds budget
        const selectedCategory = categorySelect.options[categorySelect.selectedIndex];
        const categoryName = selectedCategory.text.replace(/<[^>]*>/g, '').trim();
        
        for (const budget of budgets) {
            if (budget.category_name === categoryName) {
                const newTotal = parseFloat(budget.spent_amount) + amount;
                if (newTotal > parseFloat(budget.budget_amount)) {
                    const confirmMessage = `This expense will exceed your budget for "${categoryName}".\n\n` +
                                         `Budget: $${parseFloat(budget.budget_amount).toFixed(2)}\n` +
                                         `Already spent: $${parseFloat(budget.spent_amount).toFixed(2)}\n` +
                                         `This expense: $${amount.toFixed(2)}\n` +
                                         `New total: $${newTotal.toFixed(2)}\n\n` +
                                         `Do you want to proceed?`;
                    
                    if (!confirm(confirmMessage)) {
                        event.preventDefault();
                        return;
                    }
                }
                break;
            }
        }
    });
    
    // Quick fill buttons (example)
    function createQuickFillButtons() {
        const container = document.createElement('div');
        container.className = 'btn-group btn-group-sm mt-2';
        
        const amounts = [5, 10, 20, 50, 100];
        amounts.forEach(amount => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-secondary';
            btn.textContent = `$${amount}`;
            btn.onclick = () => {
                amountInput.value = amount.toFixed(2);
                checkBudget();
            };
            container.appendChild(btn);
        });
        
        amountInput.parentNode.appendChild(container);
    }
    
    // Create quick fill buttons
    createQuickFillButtons();
    
    // Check budget function
    function checkBudget() {
        const amount = parseFloat(amountInput.value) || 0;
        const selectedCategory = categorySelect.options[categorySelect.selectedIndex];
        
        if (selectedCategory.value && amount > 0) {
            const categoryName = selectedCategory.text.replace(/<[^>]*>/g, '').trim();
            
            for (const budget of budgets) {
                if (budget.category_name === categoryName) {
                    const newTotal = parseFloat(budget.spent_amount) + amount;
                    const remaining = parseFloat(budget.budget_amount) - newTotal;
                    
                    if (newTotal > parseFloat(budget.budget_amount)) {
                        budgetWarning.classList.remove('d-none');
                        warningText.innerHTML = `
                            <strong>Budget Exceeded!</strong> This expense will go over your monthly budget for "${categoryName}". 
                            Remaining budget after this expense: <strong>$${remaining.toFixed(2)}</strong>
                        `;
                        budgetWarning.className = 'alert alert-danger';
                    } else if (newTotal > parseFloat(budget.budget_amount) * 0.8) {
                        budgetWarning.classList.remove('d-none');
                        warningText.innerHTML = `
                            <strong>Budget Warning:</strong> You're approaching your monthly budget for "${categoryName}". 
                            Remaining budget after this expense: <strong>$${remaining.toFixed(2)}</strong>
                        `;
                        budgetWarning.className = 'alert alert-warning';
                    } else {
                        budgetWarning.classList.add('d-none');
                    }
                    return;
                }
            }
        }
        
        budgetWarning.classList.add('d-none');
    }
    
    // Show category description
    window.showCategoryDescription = function() {
        const selectedCategory = categorySelect.options[categorySelect.selectedIndex];
        const description = selectedCategory.getAttribute('data-description');
        const descriptionDiv = document.getElementById('categoryDescription');
        
        if (description) {
            descriptionDiv.textContent = description;
        } else {
            descriptionDiv.textContent = 'Select a category to see its description';
        }
    };
    
    // Initialize category description
    showCategoryDescription();
    
    // Set default date to today if not set
    const dateInput = document.getElementById('transaction_date');
    if (!dateInput.value) {
        dateInput.value = new Date().toISOString().split('T')[0];
    }
    
    // Set max date to today (optional)
    // dateInput.max = new Date().toISOString().split('T')[0];
});
</script>