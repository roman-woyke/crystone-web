</main>

<footer class="site-footer">
    <span class="footer-label">Switch to:</span>
    <?php
        $page = basename($_SERVER['SCRIPT_NAME']);
    ?>
    <a href="/basti/<?= $page ?>">Basti</a>
    <a href="/ben/<?= $page ?>">Ben</a>
</footer>

</body>
</html>