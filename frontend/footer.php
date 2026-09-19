            </div> <!-- End view-panel -->
            <footer class="app-footer">
                &copy; 2026 SmartOLT v1.0.0 by Erlangga Alfian
            </footer>
        </main> <!-- End main-content -->
    </div> <!-- End app-container -->

    <script>
        // Inisialisasi ikon Lucide secara global
        lucide.createIcons();

        // Topnav dropdown toggle
        document.querySelectorAll('.topnav-dropdown-toggle').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const parent = button.parentElement;
                // Close others
                document.querySelectorAll('.topnav-dropdown.open').forEach(d => {
                    if (d !== parent) d.classList.remove('open');
                });
                parent.classList.toggle('open');
            });
        });
        // Close dropdown on outside click
        document.addEventListener('click', () => {
            document.querySelectorAll('.topnav-dropdown.open').forEach(d => d.classList.remove('open'));
        });

        // Tema Toggle (Gelap/Terang)
        const themeToggle = document.getElementById('theme-toggle');
        const themeKey = 'smartolt_theme';

        // Sinkronkan body dari html (sudah diterapkan oleh anti-FOUC di <head>)
        if (document.documentElement.classList.contains('light-mode')) {
            document.body.classList.add('light-mode');
            themeToggle.innerHTML = '<i data-lucide="moon"></i>';
            lucide.createIcons();
        }

        themeToggle.addEventListener('click', () => {
            document.documentElement.classList.toggle('light-mode');
            document.body.classList.toggle('light-mode');
            const isLight = document.body.classList.contains('light-mode');
            localStorage.setItem(themeKey, isLight ? 'light' : 'dark');
            themeToggle.innerHTML = isLight ? '<i data-lucide="moon"></i>' : '<i data-lucide="sun"></i>';
            lucide.createIcons();
        });
    </script>
</body>
</html>
