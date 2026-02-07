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


// Get income categories for dropdown
$categories = [];
$category_query = "SELECT id, category_name FROM transaction_categories 
                   WHERE category_type = 'income' 
                   ORDER BY category_name";
$stmt = $conn->prepare($category_query);
//$stmt->bind_param("i", $user_id);
$user_id= 1;
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}
$stmt->close();

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
$wallets = getUserWalletsList($conn, $user_id);

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
    
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
    
    // If no errors, insert into database
    if (empty($errors)) {
        $insert_query = "INSERT INTO transactions 
                         (user_id, category_id, transaction_type, amount, description, transaction_date) 
                         VALUES (?, ?, 'income', ?, ?, ?)";
        
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("iidss", $user_id, $category_id, $amount, $description, $transaction_date);
        
        if ($stmt->execute()) {
            $success_message = "Income successfully added!";
            
            // Clear form data
            $form_data = [
                'amount' => '',
                'description' => '',
                'transaction_date' => date('Y-m-d'),
                'category_id' => ''
            ];
        } else {
            $error_message = "Error adding income: " . $stmt->error;
        }
        $stmt->close();
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

// Get recent income transactions
$recent_income = [];
$recent_query = "SELECT t.amount, t.description, t.transaction_date, c.category_name 
                 FROM transactions t 
                 JOIN transaction_categories c ON t.category_id = c.id 
                 WHERE t.user_id = ? AND t.transaction_type = 'income' 
                 ORDER BY t.transaction_date DESC, t.created_at DESC 
                 LIMIT 5";
$stmt = $conn->prepare($recent_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_result = $stmt->get_result();
while ($row = $recent_result->fetch_assoc()) {
    $recent_income[] = $row;
}
$stmt->close();

// Get current month's total income
$monthly_income_query = "SELECT COALESCE(SUM(amount), 0) as total_income 
                         FROM transactions 
                         WHERE user_id = ? 
                         AND transaction_type = 'income' 
                         AND MONTH(transaction_date) = MONTH(CURDATE()) 
                         AND YEAR(transaction_date) = YEAR(CURDATE())";
$stmt = $conn->prepare($monthly_income_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$monthly_result = $stmt->get_result();
$monthly_income = $monthly_result->fetch_assoc()['total_income'];
$stmt->close();

$conn->close();
?>

<div class="row">
    <!-- Left Column: Add Income Form -->
    <div class="col-md-8">
        <div class="card finance-card income-color mb-4">
            <div class="card-header bg-success text-white">
                <h4 class="mb-0">
                    <i class="fas fa-plus-circle me-2"></i>Add New Income
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
                
                <!-- Income Form -->
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <div class="row">
                        <!-- Wallet -->
                        <div class="mb-3">
                            <label for="wallet_id" class="form-label required-field">Wallet</label>
                            <select class="form-select" id="wallet_id" name="wallet_id" required>
                                <option value="">-- Select Wallet --</option>
                                <?php foreach ($wallets as $wallet): ?>
                                    <option value="<?php echo $wallet['id']; ?>" 
                                        data-balance="<?php echo $wallet['balance']; ?>"
                                        data-color="<?php echo $wallet['color_code']; ?>">
                                        <i class="<?php echo $wallet['icon_class']; ?> me-2"></i>
                                        <?php echo htmlspecialchars($wallet['wallet_name']); ?> 
                                        (<?php echo $wallet['type_name']; ?>)
                                        - $<?php echo number_format($wallet['balance'], 2); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

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
                            <div class="form-text">Enter the income amount</div>
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
                    
                    <!-- Category -->
                    <div class="mb-3">
                        <label for="category_id" class="form-label required-field">Category</label>
                        <select class="form-select" id="category_id" name="category_id" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" 
                                    <?php echo ($form_data['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Description -->
                    <div class="mb-3">
                        <label for="description" class="form-label required-field">Description</label>
                        <textarea class="form-control" 
                                  id="description" 
                                  name="description" 
                                  rows="3" 
                                  required 
                                  placeholder="Enter description (e.g., Salary from Company XYZ, Freelance project, etc.)"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="reset" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-redo me-1"></i>Clear Form
                        </button>
                        <button type="submit" class="btn btn-income">
                            <i class="fas fa-save me-1"></i>Add Income
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="card finance-card">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">
                            <i class="fas fa-calendar-alt me-1"></i>This Month's Income
                        </h6>
                        <h3 class="card-title text-success amount-display">
                            $<?php echo number_format($monthly_income, 2); ?>
                        </h3>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card finance-card">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">
                            <i class="fas fa-layer-group me-1"></i>Categories Available
                        </h6>
                        <h3 class="card-title text-primary amount-display">
                            <?php echo count($categories); ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Recent Income & Quick Actions -->
    <div class="col-md-4">
        <!-- Recent Income Transactions -->
        <div class="card finance-card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-history me-2"></i>Recent Income
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($recent_income)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-inbox fa-2x mb-2"></i>
                        <p>No recent income transactions</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_income as $income): ?>
                            <div class="list-group-item border-0 px-0 py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($income['description']); ?></h6>
                                        <small class="text-muted">
                                            <i class="fas fa-tag me-1"></i>
                                            <?php echo htmlspecialchars($income['category_name']); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-success rounded-pill">
                                            $<?php echo number_format($income['amount'], 2); ?>
                                        </span>
                                        <div class="text-muted small">
                                            <?php echo date('M d', strtotime($income['transaction_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 text-center">
                        <a href="transactions.php?type=income" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye me-1"></i>View All Income
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card finance-card">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="add_expense.php" class="btn btn-outline-danger">
                        <i class="fas fa-minus-circle me-1"></i>Add Expense
                    </a>
                    <a href="transactions.php" class="btn btn-outline-primary">
                        <i class="fas fa-list me-1"></i>View All Transactions
                    </a>
                    <a href="reports.php" class="btn btn-outline-success">
                        <i class="fas fa-chart-pie me-1"></i>View Reports
                    </a>
                    <a href="index.php" class="btn btn-outline-info">
                        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Tips Section -->
        <div class="card finance-card mt-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="fas fa-lightbulb me-2"></i>Income Tips
                </h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="mb-2">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        <small>Record all income sources for accurate tracking</small>
                    </li>
                    <li class="mb-2">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        <small>Use descriptive names for easy searching</small>
                    </li>
                    <li class="mb-2">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        <small>Record income as soon as it's received</small>
                    </li>
                    <li>
                        <i class="fas fa-check-circle text-success me-2"></i>
                        <small>Regularly review income patterns</small>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>