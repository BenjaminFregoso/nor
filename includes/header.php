<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal Finance Manager - Add Income</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <style>
        .finance-card {
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: none;
            transition: transform 0.3s;
        }
        .finance-card:hover {
            transform: translateY(-5px);
        }
        .income-color {
            border-left: 5px solid #28a745;
        }
        .expense-color {
            border-left: 5px solid #dc3545;
        }
        .btn-income {
            background-color: #28a745;
            color: white;
        }
        .btn-income:hover {
            background-color: #218838;
            color: white;
        }
        .category-icon {
            font-size: 1.2rem;
            margin-right: 8px;
        }
        .amount-display {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-wallet me-2"></i>Finance Manager
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="add_income.php">
                            <i class="fas fa-plus-circle"></i> Add Income
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="add_expense.php">
                            <i class="fas fa-minus-circle"></i> Add Expense
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="transactions.php">
                            <i class="fas fa-list"></i> Transactions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">