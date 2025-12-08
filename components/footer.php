// components/footer.php
<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> CV. Panca Indra Kemasan. All rights reserved.</p>
</footer>

<!-- Common Scripts -->
<script>
    // Toggle Sidebar
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('active');
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.querySelector('.menu-toggle');
        
        if (window.innerWidth <= 992 && 
            !sidebar.contains(e.target) && 
            e.target !== menuToggle && 
            !menuToggle.contains(e.target)) {
            sidebar.classList.remove('active');
        }
    });

    // Make table responsive
    document.addEventListener('DOMContentLoaded', function() {
        const tables = document.querySelectorAll('table:not(.no-wrap)');
        tables.forEach(table => {
            const wrapper = document.createElement('div');
            wrapper.className = 'table-responsive';
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        });
    });
</script>
</body>
</html>