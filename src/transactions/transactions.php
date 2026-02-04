<?php
// This is a simplified version to show income transactions
require_once '../../config/database.php';
require_once '../../includes/header.php';

$conn = getDBConnection();
$user_id = getCurrentUserId();

// Filter by type (income/expense)
$type = $_GET['type'] ?? 'all';

// Build query based on filter
if ($type === 'income') {
    $where_clause = "AND t.transaction_type = 'income'";
    $page_title = "Income Transactions";
} elseif ($type === 'expense') {
    $where_clause = "AND t.transaction_type = 'expense'";
    $page_title = "Expense Transactions";
} else {
    $where_clause = "";
    $page_title = "All Transactions";
}

// Get transactions
$query = "SELECT t.*, c.category_name, c.category_type 
          FROM transactions t 
          JOIN transaction_categories c ON t.category_id = c.id 
          WHERE t.user_id = ? $where_clause 
          ORDER BY t.transaction_date DESC, t.created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Display transactions in a table
?>
<div class="card">
    <div class="card-header">
        <h4><?php echo $page_title; ?></h4>
    </div>
    <div class="card-body">
        <table class="table table-striped">
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
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($row['transaction_date'])); ?></td>
                    <td><?php echo htmlspecialchars($row['description']); ?></td>
                    <td><?php echo htmlspecialchars($row['category_name']); ?></td>
                    <td>
                        <span class="badge <?php echo $row['category_type'] === 'income' ? 'bg-success' : 'bg-danger'; ?>">
                            <?php echo ucfirst($row['category_type']); ?>
                        </span>
                    </td>
                    <td class="<?php echo $row['category_type'] === 'income' ? 'text-success' : 'text-danger'; ?>">
                        <?php echo ($row['category_type'] === 'income' ? '+' : '-'); ?>
                        $<?php echo number_format($row['amount'], 2); ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$stmt->close();
$conn->close();
require_once '../../includes/footer.php';
?>