</main>

<?php require_once __DIR__ . '/modal.php'; ?>

<script>
window.allTxns = <?= json_encode($txns_for_js ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/js/dashboard.js"></script>
</body>
</html>
