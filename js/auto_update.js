// Auto-update configuration
const CONFIG = {
    updateInterval: 30000, // 30 seconds
    apiEndpoints: {
        stats: 'api/dashboard_stats.php',
        sales: 'get_sales_data.php',
        products: 'get_top_products.php',
        notifications: 'api/notifications.php'
    },
    selectors: {
        salesChart: '#salesChart',
        productsChart: '#topProductsChart',
        notificationBadge: '#notificationBadge',
        notificationCount: '#notificationCount',
        notificationList: '#notificationList',
        stats: {
            sales: '#totalSales',
            orders: '#totalOrders',
            stock: '#totalStock',
            users: '#activeUsers'
        }
    }
};

// Global variables
let charts = {
    sales: null,
    products: null
};
let updateInterval;

// Format currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0
    }).format(amount);
}

// Format date
function formatDate(dateString) {
    const options = { 
        day: '2-digit', 
        month: 'short', 
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    return new Date(dateString).toLocaleDateString('id-ID', options);
}

// Update statistics
async function updateStats() {
    try {
        const response = await fetch(CONFIG.apiEndpoints.stats);
        const data = await response.json();
        
        if (data.success) {
            // Update sales
            const salesElement = document.querySelector(CONFIG.selectors.stats.sales);
            if (salesElement) {
                salesElement.textContent = formatCurrency(data.data.total_sales);
            }
            
            // Update orders
            const ordersElement = document.querySelector(CONFIG.selectors.stats.orders);
            if (ordersElement) {
                ordersElement.textContent = data.data.total_orders.toLocaleString('id-ID');
            }
            
            // Update stock
            const stockElement = document.querySelector(CONFIG.selectors.stats.stock);
            if (stockElement) {
                stockElement.innerHTML = data.data.low_stock_count > 0 
                    ? `<span class="text-red-600">${data.data.low_stock_count} Stok Rendah</span>` 
                    : '<span class="text-green-600">Aman</span>';
            }
            
            // Update active users
            const usersElement = document.querySelector(CONFIG.selectors.stats.users);
            if (usersElement) {
                usersElement.textContent = data.data.active_users.toLocaleString('id-ID');
            }
        }
    } catch (error) {
        console.error('Error updating stats:', error);
    }
}

// Update charts
async function updateCharts() {
    try {
        // Update sales chart
        if (charts.sales) {
            const response = await fetch(CONFIG.apiEndpoints.sales);
            const data = await response.json();
            
            charts.sales.data.labels = data.labels;
            charts.sales.data.datasets[0].data = data.values;
            charts.sales.update();
        }
        
        // Update products chart
        if (charts.products) {
            const response = await fetch(CONFIG.apiEndpoints.products);
            const data = await response.json();
            
            charts.products.data.labels = data.labels;
            charts.products.data.datasets[0].data = data.values;
            charts.products.update();
        }
    } catch (error) {
        console.error('Error updating charts:', error);
    }
}

// Update notifications
async function updateNotifications() {
    try {
        const response = await fetch(CONFIG.apiEndpoints.notifications);
        const data = await response.json();
        
        if (data.data) {
            const unreadCount = data.data.filter(n => !n.is_read).length;
            updateNotificationBadge(unreadCount);
            
            // Only update the dropdown if it's open
            const notificationList = document.querySelector(CONFIG.selectors.notificationList);
            if (notificationList && !notificationList.classList.contains('hidden')) {
                renderNotifications(data.data);
            }
        }
    } catch (error) {
        console.error('Error updating notifications:', error);
    }
}

// Update notification badge
function updateNotificationBadge(count) {
    const badge = document.querySelector(CONFIG.selectors.notificationBadge);
    const countElement = document.querySelector(CONFIG.selectors.notificationCount);
    
    if (count > 0) {
        if (badge) badge.classList.remove('hidden');
        if (countElement) countElement.textContent = count;
        
        // Play notification sound if this is a new notification
        if (count > parseInt(countElement?.textContent || '0')) {
            playNotificationSound();
        }
    } else {
        if (badge) badge.classList.add('hidden');
    }
}

// Render notifications list
function renderNotifications(notifications) {
    const notificationList = document.querySelector(CONFIG.selectors.notificationList);
    if (!notificationList) return;

    if (notifications.length > 0) {
        notificationList.innerHTML = notifications.map(notification => `
            <a href="#" class="flex items-center px-4 py-3 border-b hover:bg-gray-50 ${!notification.is_read ? 'bg-blue-50' : ''}" 
               data-id="${notification.id}">
                <div class="flex-shrink-0">
                    <span class="h-2 w-2 rounded-full ${getNotificationColor(notification.type)}"></span>
                </div>
                <div class="ml-3 w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900">${notification.title}</p>
                    <p class="text-sm text-gray-500 truncate">${notification.message}</p>
                    <p class="mt-1 text-xs text-gray-400">${formatDate(notification.created_at)}</p>
                </div>
            </a>
        `).join('');

        // Add click handlers
        notificationList.querySelectorAll('a').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const notificationId = this.dataset.id;
                markAsRead(notificationId);
                this.classList.remove('bg-blue-50');
                // You can add navigation to the relevant page here
            });
        });
    } else {
        notificationList.innerHTML = `
            <div class="px-4 py-8 text-center text-gray-500">
                <p>Tidak ada notifikasi</p>
            </div>
        `;
    }
}

// Mark notification as read
async function markAsRead(notificationId) {
    try {
        await fetch(CONFIG.apiEndpoints.notifications, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ notification_id: parseInt(notificationId) })
        });
        
        // Update badge
        const countElement = document.querySelector(CONFIG.selectors.notificationCount);
        if (countElement) {
            const newCount = Math.max(0, parseInt(countElement.textContent) - 1);
            countElement.textContent = newCount;
            if (newCount === 0) {
                document.querySelector(CONFIG.selectors.notificationBadge)?.classList.add('hidden');
            }
        }
    } catch (error) {
        console.error('Error marking notification as read:', error);
    }
}

// Play notification sound
function playNotificationSound() {
    const audio = new Audio('notification.mp3');
    audio.volume = 0.3;
    audio.play().catch(e => console.log('Audio play failed:', e));
}

// Get notification color based on type
function getNotificationColor(type) {
    const colors = {
        'info': 'bg-blue-500',
        'success': 'bg-green-500',
        'warning': 'bg-yellow-500',
        'danger': 'bg-red-500'
    };
    return colors[type] || 'bg-gray-300';
}

// Initialize auto-update
function initAutoUpdate() {
    // Initial update
    updateAll();
    
    // Set up interval for auto-updates
    updateInterval = setInterval(updateAll, CONFIG.updateInterval);
    
    // Also update when the tab becomes visible again
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            updateAll();
        }
    });
}

// Update all dashboard components
async function updateAll() {
    // Only update if the dashboard is visible
    if (document.visibilityState === 'visible') {
        await Promise.all([
            updateStats(),
            updateCharts(),
            updateNotifications()
        ]);
    }
}

// Initialize when the DOM is fully loaded
document.addEventListener('DOMContentLoaded', () => {
    // Store chart instances
    const salesCtx = document.querySelector(CONFIG.selectors.salesChart)?.getContext('2d');
    const productsCtx = document.querySelector(CONFIG.selectors.productsChart)?.getContext('2d');
    
    // Initialize charts if they exist
    if (salesCtx) {
        charts.sales = new Chart(salesCtx, {
            type: 'line',
            data: { labels: [], datasets: [{
                label: 'Total Penjualan',
                data: [],
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                borderColor: 'rgba(79, 70, 229, 1)',
                borderWidth: 2,
                tension: 0.3,
                fill: true
            }]},
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => 'Rp ' + context.raw.toLocaleString('id-ID')
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => 'Rp ' + value.toLocaleString('id-ID')
                        }
                    }
                }
            }
        });
    }
    
    if (productsCtx) {
        charts.products = new Chart(productsCtx, {
            type: 'bar',
            data: { labels: [], datasets: [{
                label: 'Terjual',
                data: [],
                backgroundColor: 'rgba(16, 185, 129, 0.7)',
                borderColor: 'rgba(16, 185, 129, 1)',
                borderWidth: 1
            }]},
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true } }
            }
        });
    }
    
    // Initialize auto-update
    initAutoUpdate();
    
    // Clean up on page unload
    window.addEventListener('beforeunload', () => {
        if (updateInterval) clearInterval(updateInterval);
    });
});
