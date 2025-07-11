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

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    error_log("Dashboard error: Unauthorized access - user_id: " . ($_SESSION['user_id'] ?? 'none') . ", role: " . ($_SESSION['role'] ?? 'none'));
    header("Location: index.php");
    exit;
}

$success = isset($_GET['success']) ? htmlspecialchars($_GET['success']) : null;
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : null;

// Fetch functions based on user role
$functions = [];
if ($_SESSION['role'] == 'customer') {
    $sql = "SELECT f.*, u.name as agent_name 
            FROM functions f 
            LEFT JOIN users u ON f.assigned_to_id = u.id 
            WHERE f.customer_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            // Calculate total_amount
            $num_workers = isset($row['num_workers']) ? $row['num_workers'] : 0; // Fallback to 0 if null
            $worker_base_total = ($num_workers > 0) ? (($num_workers - 1) * 350) + 400 : 0;
            $sql_assign = "SELECT COUNT(*) as agent_assignments FROM assignments WHERE function_id = ? AND worker_id = ?";
            $stmt_assign = $conn->prepare($sql_assign);
            $stmt_assign->bind_param("ii", $row['id'], $row['assigned_to_id']);
            $stmt_assign->execute();
            $is_agent_as_worker = $stmt_assign->get_result()->fetch_assoc()['agent_assignments'] > 0;
            $agent_commission = $is_agent_as_worker ? 0 : 50;
            $platform_fee = $num_workers * 100;
            $row['total_amount'] = $worker_base_total + $agent_commission + $platform_fee;
            $functions[] = $row;
            $stmt_assign->close();
        }
        $stmt->close();
    } else {
        $error = "Database error: " . $conn->error;
        error_log("Dashboard error: Query preparation failed - " . $conn->error);
    }
} elseif ($_SESSION['role'] == 'agent') {
    $sql = "SELECT f.*, u.name as customer_name 
            FROM functions f 
            JOIN users u ON f.customer_id = u.id 
            WHERE f.assigned_to_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $num_workers = isset($row['num_workers']) ? $row['num_workers'] : 0;
            $worker_base_total = ($num_workers > 0) ? (($num_workers - 1) * 350) + 400 : 0;
            $sql_assign = "SELECT COUNT(*) as agent_assignments FROM assignments WHERE function_id = ? AND worker_id = ?";
            $stmt_assign = $conn->prepare($sql_assign);
            $stmt_assign->bind_param("ii", $row['id'], $row['assigned_to_id']);
            $stmt_assign->execute();
            $is_agent_as_worker = $stmt_assign->get_result()->fetch_assoc()['agent_assignments'] > 0;
            $agent_commission = $is_agent_as_worker ? 0 : 50;
            $platform_fee = $num_workers * 100;
            $row['total_amount'] = $worker_base_total + $agent_commission + $platform_fee;
            $functions[] = $row;
            $stmt_assign->close();
        }
        $stmt->close();
    } else {
        $error = "Database error: " . $conn->error;
        error_log("Dashboard error: Query preparation failed - " . $conn->error);
    }
} elseif ($_SESSION['role'] == 'worker') {
    $sql = "SELECT f.*, u.name as customer_name 
            FROM functions f 
            JOIN assignments a ON f.id = a.function_id 
            JOIN users u ON f.customer_id = u.id 
            WHERE a.worker_id = ? AND a.status = 'pending'";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $num_workers = isset($row['num_workers']) ? $row['num_workers'] : 0;
            $worker_base_total = ($num_workers > 0) ? (($num_workers - 1) * 350) + 400 : 0;
            $sql_assign = "SELECT COUNT(*) as agent_assignments FROM assignments WHERE function_id = ? AND worker_id = ?";
            $stmt_assign = $conn->prepare($sql_assign);
            $stmt_assign->bind_param("ii", $row['id'], $row['assigned_to_id']);
            $stmt_assign->execute();
            $is_agent_as_worker = $stmt_assign->get_result()->fetch_assoc()['agent_assignments'] > 0;
            $agent_commission = $is_agent_as_worker ? 0 : 50;
            $platform_fee = $num_workers * 100;
            $row['total_amount'] = $worker_base_total + $agent_commission + $platform_fee;
            $functions[] = $row;
            $stmt_assign->close();
        }
        $stmt->close();
    } else {
        $error = "Database error: " . $conn->error;
        error_log("Dashboard error: Query preparation failed - " . $conn->error);
    }
}
?>

<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EventAI - டாஷ்போர்டு</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <div class="container">
        <h1>EventAI: <?php echo $_SESSION['role'] == 'customer' ? 'எனது நிகழ்வுகள்' : ($_SESSION['role'] == 'agent' ? 'எனது ஒதுக்கீடுகள்' : 'எனது வேலைகள்'); ?></h1>
        <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
        <?php if (isset($success)) echo "<p class='success'>" . htmlspecialchars($success) . "</p>"; ?>
        
        <?php if (empty($functions)): ?>
            <p>நிகழ்வுகள் எதுவும் இல்லை.</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>நிகழ்வு ஐடி</th>
                    <th>வகை</th>
                    <th>இடம்</th>
                    <th>தேதி</th>
                    <th>நிலை</th>
                    <th>மொத்த தொகை (₹)</th>
                    <th>நடவடிக்கைகள்</th>
                </tr>
                <?php foreach ($functions as $function): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($function['id']); ?></td>
                        <td><?php echo htmlspecialchars($function['type']); ?></td>
                        <td><?php echo htmlspecialchars($function['location']); ?></td>
                        <td><?php echo htmlspecialchars($function['date']); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $function['status'])); ?></td>
                        <td><?php echo number_format($function['total_amount'] ?: 0, 2); ?></td>
                        <td class="actions">
                            <?php if ($_SESSION['role'] == 'customer'): ?>
                                <?php if ($function['status'] == 'pending'): ?>
                                    <a href="payment_collect.php?function_id=<?php echo $function['id']; ?>&payment_type=advance">கட்டணம் முன்பணம்</a>
                                    <a href="payment_collect.php?function_id=<?php echo $function['id']; ?>&payment_type=full">முழு தொகை செலுத்து</a>
                                <?php elseif ($function['status'] == 'advance_paid'): ?>
                                    <a href="payment_collect.php?function_id=<?php echo $function['id']; ?>&payment_type=balance">முழு தொகை செலுத்து</a>
                                <?php endif; ?>
                                <a href="payment_split.php?function_id=<?php echo $function['id']; ?>">கட்டண பிரிவு</a>
                            <?php elseif ($_SESSION['role'] == 'agent'): ?>
                                <a href="assign_workers.php?function_id=<?php echo $function['id']; ?>">பணியாளர்களை ஒதுக்கு</a>
                                <a href="payment_split.php?function_id=<?php echo $function['id']; ?>">கட்டண பிரிவு</a>
                            <?php elseif ($_SESSION['role'] == 'worker'): ?>
                                <a href="worker_action.php?function_id=<?php echo $function['id']; ?>&action=accept">ஏற்க</a>
                                <a href="worker_action.php?function_id=<?php echo $function['id']; ?>&action=decline">நிராகரி</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <?php if ($_SESSION['role'] == 'customer'): ?>
            <p><a href="post_function.php">புதிய நிகழ்வு சேர்</a></p>
        <?php endif; ?>
        <p><a href="logout.php">வெளியேறு</a></p>
    </div>
</body>
</html>
<?php $conn->close(); ?>