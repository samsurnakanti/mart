<?php
declare(strict_types=1);

require __DIR__ . '/includes/functions.php';

try {
    $pdo = db();
} catch (Throwable $ex) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>VMCmarts Setup</title>
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
        <main class="page-wrap">
            <section class="panel">
                <h1 class="section-title">VMCmarts database setup needed</h1><br>
                <p>MySQL rejected the current database login. Update <b>DB_USER</b> and <b>DB_PASS</b> in <code>config/database.php</code> to match the username/password you use in phpMyAdmin.</p>
                <br>
                <p class="small"><?= e($ex->getMessage()) ?></p>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action === 'signup') {
            signup($_POST);
            redirect_to('');
        }

        if ($action === 'login') {
            login($_POST['login'] ?? '', $_POST['password'] ?? '');
            $user = current_user();
            redirect_to($user && in_array($user['role'], ['admin', 'super_admin'], true) ? 'admin' : '');
        }

        if ($action === 'add_to_cart' || $action === 'buy_now') {
            add_to_cart((int)($_POST['product_id'] ?? 0), max(1, (int)($_POST['qty'] ?? 1)));
            flash('ok', $action === 'buy_now' ? 'Product ready for checkout.' : 'Added to cart.');
            redirect_to($action === 'buy_now' ? 'checkout' : ($_POST['back'] ?? ''));
        }

        if ($action === 'update_cart') {
            update_cart($_POST['qty'] ?? []);
            flash('ok', 'Cart updated.');
            redirect_to('cart');
        }

        if ($action === 'place_order') {
            $orderId = place_order($_POST);
            flash('ok', "Order #$orderId placed. Admin will review and confirm it.");
            redirect_to('profile');
        }

        if ($action === 'save_product') {
            require_admin();
            save_product($_POST, $_FILES);
            flash('ok', 'Product saved successfully.');
            redirect_to('admin');
        }

        if ($action === 'save_category') {
            require_admin();
            save_category($_POST, $_FILES);
            flash('ok', 'Category saved successfully.');
            redirect_to('admin&module=categories');
        }

        if ($action === 'save_slider') {
            require_admin();
            save_slider($_POST, $_FILES);
            flash('ok', 'Slider saved successfully.');
            redirect_to('admin&module=sliders');
        }

        if ($action === 'update_inventory') {
            require_admin();
            update_inventory($_POST['stock'] ?? []);
            flash('ok', 'Inventory updated.');
            redirect_to('admin&module=inventory');
        }

        if ($action === 'save_user') {
            require_super_admin();
            save_user($_POST);
            flash('ok', 'User saved successfully.');
            redirect_to('super_admin');
        }

        if ($action === 'complete_order') {
            require_admin();
            complete_order((int)($_POST['order_id'] ?? 0), (int)($_POST['points_to_allot'] ?? 0));
            flash('ok', 'Order completed.');
            redirect_to('admin&module=orders');
        }

        if ($action === 'update_order_status') {
            require_admin();
            update_order_status((int)($_POST['order_id'] ?? 0), $_POST['status'] ?? 'Placed');
            flash('ok', 'Order status updated.');
            redirect_to('admin&module=orders');
        }

        if ($action === 'update_profile') {
            update_profile($_POST);
            flash('ok', 'Profile updated successfully.');
            redirect_to('profile&tab=edit');
        }

        if ($action === 'change_own_password') {
            change_own_password($_POST);
            flash('ok', 'Password updated successfully.');
            $user = current_user();
            redirect_to($user && $user['role'] === 'super_admin' ? 'super_admin&module=system' : 'admin&module=settings');
        }
    }

    if ($action === 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    }

    if ($action === 'remove_cart') {
        remove_from_cart((int)($_GET['id'] ?? 0));
        flash('ok', 'Removed from cart.');
        redirect_to('cart');
    }

    if ($action === 'delete_product') {
        require_admin();
        set_product_active((int)($_GET['id'] ?? 0), 0);
        flash('ok', 'Product disabled.');
        redirect_to('admin');
    }

    if ($action === 'delete_category') {
        require_admin();
        set_category_active((int)($_GET['id'] ?? 0), 0);
        flash('ok', 'Category disabled.');
        redirect_to('admin&module=categories');
    }

    if ($action === 'delete_slider') {
        require_admin();
        set_slider_active((int)($_GET['id'] ?? 0), 0);
        flash('ok', 'Slider disabled.');
        redirect_to('admin&module=sliders');
    }

    if ($action === 'delete_product_image') {
        require_admin();
        delete_product_image((int)($_GET['id'] ?? 0));
        flash('ok', 'Product image removed.');
        redirect_to('admin&module=products&edit=' . (int)($_GET['product_id'] ?? 0));
    }
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $ex->getMessage());
    redirect_to($_POST['back'] ?? ($_GET['page'] ?? ''));
}

$page = $_GET['page'] ?? 'home';
$allowed = ['home', 'products', 'product', 'login', 'signup', 'cart', 'checkout', 'profile', 'admin', 'super_admin'];
if (!in_array($page, $allowed, true)) {
    $page = 'home';
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/pages/' . $page . '.php';
include __DIR__ . '/includes/footer.php';
