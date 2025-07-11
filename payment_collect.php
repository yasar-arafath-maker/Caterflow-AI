<?php
session_start();
require 'includes/db_connect.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure single session_start
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'customer') {
    error_log("Payment error: Unauthorized access - user_id: " . ($_SESSION['user_id'] ?? 'none') . ", role: " . ($_SESSION['role'] ?? 'none'));
    header("Location: index.php");
    exit;
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$function_id = isset($_GET['function_id']) ? intval($_GET['function_id']) : null;
$payment_type = isset($_GET['payment_type']) ? trim($_GET['payment_type']) : null;

if (!$function_id || !$payment_type || !in_array($payment_type, ['advance', 'full', 'balance'])) {
    $error = "நிகழ்வு ஐடி அல்லது கட்டண வகை தவறானது.";
    error_log("Payment error: Invalid parameters - function_id: $function_id, payment_type: $payment_type");
    header("Location: dashboard.php?error=" . urlencode($error));
    exit;
}

// Validate function
$sql = "SELECT * FROM functions WHERE id = ? AND customer_id = ? AND status IN ('pending', 'advance_paid')";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $error = "தரவுத்தள பிழை: " . $conn->error;
    error_log("Payment error: Database query failed - " . $conn->error);
    header("Location: dashboard.php?error=" . urlencode($error));
    exit;
}
$stmt->bind_param("ii", $function_id, $_SESSION['user_id']);
$stmt->execute();
$function = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$function) {
    $error = "நிகழ்வு கிடைக்கவில்லை அல்லது நீங்கள் அங்கீகரிக்கப்படவில்லை.";
    error_log("Payment error: Function not found or unauthorized - function_id: $function_id, user_id: {$_SESSION['user_id']}");
    header("Location: dashboard.php?error=" . urlencode($error));
    exit;
}

// Calculate expected payment
$num_workers = $function['num_workers'] ?: 0;
$worker_base_total = ($num_workers > 0) ? (($num_workers - 1) * 350) + 400 : 0;
$sql = "SELECT COUNT(*) as agent_assignments FROM assignments WHERE function_id = ? AND worker_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $function_id, $function['assigned_to_id']);
$stmt->execute();
$is_agent_as_worker = $stmt->get_result()->fetch_assoc()['agent_assignments'] > 0;
$stmt->close();
$agent_commission = $is_agent_as_worker ? 0 : 50;
$platform_fee = $num_workers * 100;
$total_customer = $worker_base_total + $agent_commission + $platform_fee;
$expected_amount = ($payment_type === 'advance') ? $total_customer * 0.5 : $total_customer;
if ($payment_type === 'balance') {
    $expected_amount = $total_customer - ($function['payment_amount'] ?: 0);
}

$success = $error = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Debug CSRF token
    error_log("CSRF Debug: POST[csrf_token]=" . ($_POST['csrf_token'] ?? 'none') . ", SESSION[csrf_token]=" . ($_SESSION['csrf_token'] ?? 'none'));

    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "தவறான CSRF டோக்கன். பக்கத்தை புதுப்பித்து மீண்டும் முயற்சிக்கவும்.";
        error_log("Payment error: CSRF token mismatch - received: " . ($_POST['csrf_token'] ?? 'none') . ", expected: " . ($_SESSION['csrf_token'] ?? 'none'));
    } elseif (!isset($_POST['payment_method']) || !in_array($_POST['payment_method'], ['gpay', 'phonepe'])) {
        $error = "கட்டண முறையைத் தேர்ந்தெடுக்கவும் (GPay அல்லது PhonePe).";
        error_log("Payment error: Invalid payment method - " . ($_POST['payment_method'] ?? 'none'));
    } elseif (!isset($_POST['payment_amount']) || !is_numeric($_POST['payment_amount']) || $_POST['payment_amount'] <= 0) {
        $error = "சரியான கட்டண தொகையை உள்ளிடவும்.";
        error_log("Payment error: Invalid payment amount - " . ($_POST['payment_amount'] ?? 'none'));
    } else {
        $payment_amount = floatval($_POST['payment_amount']);
        $payment_method = $_POST['payment_method'];
        if (abs($payment_amount - $expected_amount) > 0.01) {
            $error = "கட்டண தொகை (₹$payment_amount) எதிர்பார்க்கப்பட்ட தொகையுடன் (₹$expected_amount) பொருந்தவில்லை.";
            error_log("Payment error: Amount mismatch - received: $payment_amount, expected: $expected_amount");
        } else {
            $_SESSION['last_payment_method'] = $payment_method;
            $conn->begin_transaction();
            try {
                // Insert payment
                $sql = "INSERT INTO payments (function_id, amount, status, created_at) VALUES (?, ?, ?, NOW())";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed for payment insertion: " . $conn->error);
                }
                $payment_status = ($payment_type === 'advance') ? 'advance_paid' : 'completed';
                $stmt->bind_param("ids", $function_id, $payment_amount, $payment_status);
                $stmt->execute();
                $stmt->close();

                // Worker assignment (only for advance or full, not balance)
                if ($payment_type !== 'balance') {
                    $worker_limit = $num_workers;
                    $sql = "SELECT u.id, u.name, u.experience, u.location, u.phone, 
                            AVG(r.rating) as avg_rating, 
                            COUNT(r.id) as review_count 
                            FROM users u 
                            LEFT JOIN reviews r ON u.id = r.worker_id 
                            AND r.created_at > DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                            WHERE u.role = 'worker' 
                            AND u.id NOT IN (
                                SELECT worker_id FROM assignments 
                                WHERE function_id IN (SELECT id FROM functions WHERE date = ?)
                            )
                            GROUP BY u.id";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("s", $function['date']);
                    $stmt->execute();
                    $workers = $stmt->get_result();
                    $stmt->close();

                    $worker_scores = [];
                    while ($worker = $workers->fetch_assoc()) {
                        $score = 30; // Availability
                        if (stripos($worker['experience'], $function['type']) !== false) {
                            $score += 30;
                        } elseif ($worker['experience']) {
                            $score += 15;
                        }
                        $score += $worker['avg_rating'] ? $worker['avg_rating'] * 6 : 0;
                        if ($worker['location'] == $function['location']) {
                            $score += 10;
                        } elseif (stripos($worker['location'], $function['location']) !== false) {
                            $score += 5;
                        }
                        $worker_scores[] = array_merge($worker, ['score' => $score]);
                    }
                    usort($worker_scores, fn($a, $b) => $b['score'] <=> $a['score']);

                    $assigned_workers = array_slice($worker_scores, 0, $worker_limit);
                    $worker_payments = [];
                    $is_agent_as_worker = count($assigned_workers) < $worker_limit;

                    foreach ($assigned_workers as $index => $worker) {
                        $role = ($index == 0) ? 'in-charge' : 'normal';
                        $sql = "INSERT INTO assignments (function_id, worker_id, role, status) VALUES (?, ?, ?, 'pending')";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("iis", $function_id, $worker['id'], $role);
                        $stmt->execute();
                        $stmt->close();

                        // Notify worker
                        $message = "நிகழ்வு #{$function_id} ({$function['type']}) {$function['date']} அன்று {$role} ஆக ஒதுக்கப்பட்டது.";
                        $sql = "INSERT INTO notifications (user_id, message, read_status, created_at) VALUES (?, ?, 'unread', NOW())";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("is", $worker['id'], $message);
                        $stmt->execute();
                        $stmt->close();

                        // Worker payment
                        $worker_payment = ($role === 'in-charge') ? 400 : 350;
                        $worker_payments[$worker['id']] = $worker_payment;

                        // Log worker payment
                        $sql = "INSERT INTO payment_splits (function_id, user_id, amount, type, created_at) VALUES (?, ?, ?, 'worker', NOW())";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("iid", $function_id, $worker['id'], $worker_payment);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // If agent is worker, assign remaining slots
                    if ($is_agent_as_worker) {
                        $remaining_slots = $worker_limit - count($assigned_workers);
                        for ($i = 0; $i < $remaining_slots; $i++) {
                            $role = (count($assigned_workers) == 0 && $i == 0) ? 'in-charge' : 'normal';
                            $worker_payment = ($role === 'in-charge') ? 400 : 350;
                            $sql = "INSERT INTO assignments (function_id, worker_id, role, status) VALUES (?, ?, ?, 'pending')";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("iis", $function_id, $function['assigned_to_id'], $role);
                            $stmt->execute();
                            $stmt->close();

                            // Log agent-as-worker payment
                            $sql = "INSERT INTO payment_splits (function_id, user_id, amount, type, created_at) VALUES (?, ?, ?, 'worker', NOW())";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("iid", $function_id, $function['assigned_to_id'], $worker_payment);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }

                    // Log agent commission (only if not worker)
                    if (!$is_agent_as_worker) {
                        $sql = "INSERT INTO payment_splits (function_id, user_id, amount, type, created_at) VALUES (?, ?, ?, 'agent', NOW())";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("iid", $function_id, $function['assigned_to_id'], $agent_commission);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // Log platform fee
                    $sql = "INSERT INTO payment_splits (function_id, user_id, amount, type, created_at) VALUES (?, ?, ?, 'platform', NOW())";
                    $stmt = $conn->prepare($sql);
                    $platform_id = 0;
                    $stmt->bind_param("iid", $function_id, $platform_id, $platform_fee);
                    $stmt->execute();
                    $stmt->close();
                }

                // Update function status and payment_amount
                $new_payment_amount = ($payment_type === 'balance') ? ($function['payment_amount'] ?: 0) + $payment_amount : $payment_amount;
                $sql = "UPDATE functions SET status = ?, payment_type = ?, payment_amount = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed for function update: " . $conn->error);
                }
                $function_status = ($payment_type === 'advance') ? 'advance_paid' : 'fully_paid';
                $stmt->bind_param("ssdi", $function_status, $payment_type, $new_payment_amount, $function_id);
                $stmt->execute();
                $stmt->close();

                // Notify customer
                $message = "நிகழ்வு #{$function_id} க்கான கட்டணம் ({$payment_type}, {$payment_method}): ₹" . number_format($payment_amount, 2) . ". பணியாளர்கள்: ₹$worker_base_total, முகவர்: ₹$agent_commission, தளம்: ₹$platform_fee.";
                $sql = "INSERT INTO notifications (user_id, message, read_status, created_at) VALUES (?, ?, 'unread', NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("is", $_SESSION['user_id'], $message);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $success = "கட்டணம் வெற்றிகரமாக $payment_method மூலம் செயலாக்கப்பட்டது!";
                header("Location: payment_split.php?function_id=$function_id&success=" . urlencode($success));
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = "கட்டணம் செயலாக்குவதில் பிழை: " . $e->getMessage();
                error_log("Payment error: " . $e->getMessage());
            }
        }
    }
}

// Regenerate CSRF token for next form load
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EventAI - கட்டணம் அனுப்பு</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h1>நிகழ்வு #<?php echo htmlspecialchars($function_id); ?> க்கு கட்டணம் அனுப்பு</h1>
        <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
        <?php if (isset($success)) echo "<p class='success'>" . htmlspecialchars($success) . "</p>"; ?>
        <p><strong>கட்டண வகை:</strong> <?php echo htmlspecialchars(ucfirst($payment_type == 'advance' ? 'முன்பணம்' : ($payment_type == 'full' ? 'முழு தொகை' : 'மீதித் தொகை'))); ?></p>
        <p><strong>எதிர்பார்க்கப்பட்ட தொகை (₹):</strong> <?php echo htmlspecialchars(number_format($expected_amount, 2)); ?></p>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="amount-input">
                <label><strong>தொகையை உள்ளிடவும் (₹):</strong>
                    <input type="number" name="payment_amount" step="0.01" min="0" required placeholder="தொகையை உள்ளிடவும்" value="<?php echo htmlspecialchars($expected_amount); ?>">
                </label>
            </div>
            <p><strong>கட்டண முறையைத் தேர்ந்தெடு:</strong></p>
            <div class="payment-methods">
                <label><input type="radio" name="payment_method" value="gpay" required> GPay</label>
                <label><input type="radio" name="payment_method" value="phonepe" required> PhonePe</label>
            </div>
            <button type="submit">கட்டணத்தை உறுதிப்படுத்து</button>
        </form>
        <p><a href="dashboard.php">டாஷ்போர்டுக்கு திரும்பு</a></p>
    </div>
</body>
</html>
<?php $conn->close(); ?>