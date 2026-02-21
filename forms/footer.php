        </main>
    </div>
    
    <script>
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
            const sidebar = document.querySelector('.sidebar');
            
            if (mobileMenuBtn && sidebar) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                });
            }
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 768) {
                    const isClickInsideSidebar = sidebar.contains(event.target);
                    const isClickOnMenuBtn = mobileMenuBtn.contains(event.target);
                    
                    if (!isClickInsideSidebar && !isClickOnMenuBtn && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                    }
                }
            });
            
            // Chart initialization
            const bookingCtx = document.getElementById('bookingChart');
            if (bookingCtx) {
                const bookingChart = new Chart(bookingCtx, {
                    type: 'line',
                    data: {
                        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                        datasets: [{
                            label: 'Bookings',
                            data: [65, 59, 80, 81, 56, 55, 40, 45, 60, 75, 80, 85],
                            borderColor: '#0066FF',
                            backgroundColor: 'rgba(0, 102, 255, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(226, 232, 240, 0.5)'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
            
            // Vaccine Distribution Chart
            const vaccineCtx = document.getElementById('vaccineChart');
            if (vaccineCtx) {
                const vaccineChart = new Chart(vaccineCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['BCG', 'Hepatitis B', 'Polio', 'DPT', 'Measles', 'Others'],
                        datasets: [{
                            data: [15, 20, 25, 18, 12, 10],
                            backgroundColor: [
                                '#0066FF',
                                '#00D4AA',
                                '#FF6B35',
                                '#10B981',
                                '#F59E0B',
                                '#64748B'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>