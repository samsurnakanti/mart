<?php
$order = order_for_invoice((int)($_GET['id'] ?? 0));
echo render_invoice_document($order);
exit;
