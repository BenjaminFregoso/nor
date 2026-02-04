    </div> <!-- Close container -->

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Set minimum date to prevent future dates (optional)
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('transaction_date');
            const today = new Date().toISOString().split('T')[0];
            
            // Set max date to today (optional, remove if you want to allow future dates)
            // dateInput.max = today;
            
            // Auto-format amount input
            const amountInput = document.getElementById('amount');
            amountInput.addEventListener('blur', function() {
                if (this.value) {
                    this.value = parseFloat(this.value).toFixed(2);
                }
            });
            
            // Form validation
            const form = document.querySelector('form');
            form.addEventListener('submit', function(event) {
                const amount = parseFloat(amountInput.value);
                if (amount <= 0) {
                    alert('Please enter a valid amount greater than 0.');
                    event.preventDefault();
                    amountInput.focus();
                }
            });
            
            // Quick fill buttons for common amounts (optional)
            function quickFillAmount(amount) {
                amountInput.value = amount.toFixed(2);
                amountInput.focus();
            }
            
            // Add quick buttons if needed
            // Example: Add this HTML somewhere and uncomment function
            // <button type="button" onclick="quickFillAmount(100)">$100</button>
        });
    </script>
</body>
</html>