<?php
session_start();
require 'includes/db_connect.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'agent') {
    error_log("Function completion error: Unauthorized access - user_id: " . ($_SESSION['user_id'] ?? 'none') . ", role: " . ($_SESSION['role'] ?? 'none'));
    header("Location: index.php");
    exit;
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$function_id = isset($_GET['function_id']) ? intval($_GET['function_id']) : null;
$error = $success = null;

if (!$function_id) {
    $error = "நிகழ்வு ஐடி குறிப்பிடப்படவில்லை.";
    error_log("Function completion error: No function_id provided");
    header("Location: dashboard.php?error=" . urlencode($error));
    exit;
}

// Validate function
$sql = "SELECT f.*, u.name as customer_name 
        FROM functions f 
        JOIN users u ON f.customer_id = u.id 
        WHERE f.id = ? AND f.assigned_to_id = ? AND f.status = 'fully_paid'";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $error = "தரவுத்தள பிழை: " . $conn->error;
    error_log("Function completion error: Query preparation failed - " . $conn->error);
    header("Location: dashboard.php?error=" . urlencode($error));
    exit;
}
$stmt->bind_param("ii", $function_id, $_SESSION['user_id']);
$stmt->execute();
$function = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$function) {
    $error = "நிகழ்வு கிடைக்கவில்லை, முழுமையாக செலுத்தப்படவில்லை, அல்லது நீங்கள் அங்கீகரிக்கப்படவில்லை.";
    error_log("Function completion error: Function not found, not fully paid, or unauthorized - function_id: $function_id, user_id: {$_SESSION['user_id']}");
    header("Location: dashboard.php?error=" . urlencode($error));
    exit;
}

// Check if function date has passed
$current_date = date('Y-m-d');
if ($function['date'] > $current_date) {
    $error = "நிகழ்வு இன்னும் நடக்கவில்லை (தேதி: {$function['date']}).";
    error_log("Function completion error: Function date not passed - function_id: $function_id, date: {$function['date']}");
    header("Location: dashboard.php?error=" . urlencode($error));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "தவறான CSRF டோக்கன். பக்கத்தை புதுப்பித்து மீண்டும் முயற்சிக்கவும்.";
        error_log("Function completion error: CSRF token mismatch - received: " . ($_POST['csrf_token'] ?? 'none') . ", expected: " . ($_SESSION['csrf_token'] ?? 'none'));
    } else {
        $conn->begin_transaction();
        try {
            // Check if all assignments are accepted
            $sql = "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted 
                    FROM assignments WHERE function_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $function_id);
            $stmt->execute();
            $assignment_counts = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($assignment_counts['total'] == 0 || $assignment_counts['accepted'] < $function['num_workers']) {
                throw new Exception("எல்லா பணியாளர்களும் ஒப்புக்கொள்ளவில்லை. மொத்தம்: {$assignment_counts['total']}, ஒப்புக்கொண்டவை: {$assignment_counts['accepted']}.");
            }

            // Check if payment_splits already exist
            $sql = "SELECT COUNT(*) as split_count FROM payment_splits WHERE function_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $function_id);
            $stmt->execute();
            $split_count = $stmt->get_result()->fetch_assoc()['split_count'];
            $stmt->close();

            // Calculate payment breakdown
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
            $total_payment = $worker_base_total + $agent_commission + $platform_fee;

            // Log payment_splits if not already done
            if ($split_count == 0) {
                // Fetch assigned workers
                $sql = "SELECT worker_id, role FROM assignments WHERE function_id = ? AND status = 'accepted'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $function_id);
                $stmt->execute();
                $workers = $stmt->get_result();
                $stmt->close();

                while ($worker = $workers->fetch_assoc()) {
                    $worker_payment = ($worker['role'] === 'in-charge') ? 400 : 350;
                    $sql = "INSERT INTO payment_splits (function_id, user_id, amount, type, created_at) VALUES (?, ?, ?, 'worker', NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("iid", $function_id, $worker['worker_id'], $worker_payment);
                    $stmt->execute();
                    $stmt->close();

                    // Notify worker
                    $message = "நிகழ்வு #{$function_id} ({$function['type']}) முடிந்தது. உங்கள் கட்டணம்: ₹{$worker_payment}.";
                    $sql = "INSERT INTO notifications (user_id, message, read_status, created_at) VALUES (?, ?, 'unread', NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("is", $worker['worker_id'], $message);
                    $stmt->execute();
                    $stmt->close();
                }

                // Log agent commission (if not worker)
                if (!$is_agent_as_worker) {
                    $sql = "INSERT INTO payment_splits (function_id, user_id, amount, type, created_at) VALUES (?, ?, ?, 'agent', NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("iid", $function_id, $function['assigned_to_id'], $agent_commission);
                    $stmt->execute();
                    $stmt->close();

                    // Notify agent
                    $message = "நிகழ்வு #{$function_id} ({$function['type']}) முடிந்தது. உங்கள் கமிஷன்: ₹{$agent_commission}.";
                    $sql = "INSERT INTO notifications (user_id, message, read_status, created_at) VALUES (?, ?, 'unread', NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("is", $function['assigned_to_id'], $message);
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

            // Update function status
            $sql = "UPDATE functions SET status = 'completed' WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $function_id);
            $stmt->execute();
            $stmt->close();

            // Notify customer
            $message = "நிகழ்வு #{$function_id} ({$function['type']}) முடிந்தது. மொத்த கட்டணம்: ₹{$total_payment}.";
            $sql = "INSERT INTO notifications (user_id, message, read_status, created_at) VALUES (?, ?, 'unread', NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $function['customer_id'], $message);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $success = "நிகழ்வு வெற்றிகரமாக முடிந்தது மற்றும் கட்டணங்கள் செயலாக்கப்பட்டன!";
            error_log("Function completion success: function_id: $function_id");
            header("Location: dashboard.php?success=" . urlencode($success));
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = "நிகழ்வு முடிப்பதில் பிழை: " . $e->getMessage();
            error_log("Function completion error: " . $e->getMessage());
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
    <title>EventAI - நிகழ்வு முடித்தல்</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h1>நிகழ்வு #<?php echo htmlspecialchars($function_id); ?> முடித்தல்</h1>
        <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
        <?php if (isset($success)) echo "<p class='success'>" . htmlspecialchars($success) . "</p>"; ?>
        <?php if (isset($function)): ?>
            <p><strong>வாடிக்கையாளர்:</strong> <?php echo htmlspecialchars($function['customer_name']); ?></p>
            <p><strong>நிகழ்வு வகை:</strong> <?php echo htmlspecialchars($function['type']); ?></p>
            <p><strong>இடம்:</strong> <?php echo htmlspecialchars($function['location']); ?></p>
            <p><strong>தேதி:</strong> <?php echo htmlspecialchars($function['date']); ?></p>
            <p><strong>பணியாளர்களின் எண்ணிக்கை:</strong> <?php echo $function['num_workers']; ?></p>
            <p><strong>கட்டண நிலை:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $function['status']))); ?></p>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <button type="submit">நிகழ்வு முடிந்ததை உறுதிப்படுத்து</button>
            </form>
        <?php endif; ?>
        <p><a href="dashboard.php">டாஷ்போர்டுக்கு திரும்பு</a></p>
    </div>
</body>
</html>
<?php $conn->close(); ?>