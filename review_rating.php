<?php
session_start();
require 'includes/db_connect.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Twilio credentials (optional, comment out if not using SMS)
// $twilio_sid = 'your_twilio_account_sid';
// $twilio_token = 'your_twilio_auth_token';
// $twilio_from = 'your_twilio_phone_number';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['organizer', 'agent'])) {
    header("Location: index.php");
    exit;
}

$function_id = isset($_GET['function_id']) ? intval($_GET['function_id']) : null;
if (!$function_id) {
    $error = "No function ID provided.";
    header("Location: dashboard.php");
    exit;
}

$sql = "SELECT * FROM functions WHERE id = ? AND assigned_to_id = ? AND assigned_to_role = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iis", $function_id, $_SESSION['user_id'], $_SESSION['role']);
$stmt->execute();
$function = $stmt->get_result()->fetch_assoc();

if (!$function) {
    $error = "Function not found or you are not authorized.";
    header("Location: dashboard.php");
    exit;
}

$sql = "SELECT a.*, u.name, u.phone 
        FROM assignments a 
        JOIN users u ON a.worker_id = u.id 
        LEFT JOIN reviews r ON a.function_id = r.function_id AND a.worker_id = r.worker_id 
        WHERE a.function_id = ? AND r.id IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $function_id);
$stmt->execute();
$workers = $stmt->get_result();
$worker_count = $workers->num_rows;

$success = $error = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid CSRF token.";
    } else {
        $worker_id = intval($_POST['worker_id']);
        $rating = intval($_POST['rating']);
        $feedback = trim($_POST['feedback']);
        
        if ($rating < 1 || $rating > 5) {
            $error = "Rating must be 1–5.";
        } elseif (empty($feedback)) {
            $error = "Feedback is required.";
        } else {
            $conn->begin_transaction();
            try {
                $sql = "INSERT INTO reviews (function_id, worker_id, rating, feedback) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiis", $function_id, $worker_id, $rating, $feedback);
                $stmt->execute();

                // Check if all workers are reviewed
                $sql = "SELECT a.worker_id FROM assignments a LEFT JOIN reviews r ON a.function_id = r.function_id AND a.worker_id = r.worker_id WHERE a.function_id = ? AND r.id IS NULL";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $function_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows == 0) {
                    $sql = "UPDATE functions SET status = 'completed' WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $function_id);
                    $stmt->execute();

                    // Notify customer
                    $message = "All reviews completed for function #{$function_id}.";
                    $sql = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("is", $function['customer_id'], $message);
                    $stmt->execute();

                    // Optional SMS to customer
                    if (!empty($twilio_sid) && !empty($twilio_token) && !empty($twilio_from)) {
                        $sql = "SELECT phone, name FROM users WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $function['customer_id']);
                        $stmt->execute();
                        $customer = $stmt->get_result()->fetch_assoc();

                        if ($customer['phone']) {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, "https://api.twilio.com/2010-04-01/Accounts/$twilio_sid/Messages.json");
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_USERPWD, "$twilio_sid:$twilio_token");
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                                'To' => $customer['phone'],
                                'From' => $twilio_from,
                                'Body' => "EventAI: $message Login: http://localhost/eventai"
                            ]));
                            $response = curl_exec($ch);
                            if (curl_errno($ch)) {
                                error_log("SMS failed: " . curl_error($ch));
                            }
                            curl_close($ch);
                        }
                    }
                }

                $conn->commit();
                $success = "Review submitted!";
                header("Location: review_rating.php?function_id=$function_id");
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error: " . $e->getMessage();
                error_log("Review error: " . $e->getMessage());
            }
        }
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EventAI - Review Workers</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h1>Review Workers for Function #<?php echo htmlspecialchars($function_id); ?></h1>
        <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
        <?php if (isset($success)) echo "<p class='success'>" . htmlspecialchars($success) . "</p>"; ?>
        <?php if ($worker_count == 0): ?>
            <p class="error">No workers assigned or all reviewed.</p>
            <p><a href="assign_workers.php?function_id=<?php echo htmlspecialchars($function_id); ?>">Adjust Workers</a></p>
        <?php else: ?>
            <p>Found <?php echo $worker_count; ?> worker(s) to review.</p>
            <?php while ($worker = $workers->fetch_assoc()): ?>
                <h2>Review for <?php echo htmlspecialchars($worker['name']); ?> (Role: <?php echo htmlspecialchars($worker['role']); ?>)</h2>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="worker_id" value="<?php echo $worker['worker_id']; ?>">
                    <select name="rating" required>
                        <option value="">Select Rating</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                    <textarea name="feedback" placeholder="Feedback" required></textarea>
                    <button type="submit">Submit Review</button>
                </form>
            <?php endwhile; ?>
        <?php endif; ?>
        <p><a href="dashboard.php">Back to Dashboard</a></p>
    </div>
</body>
</html>
<?php $stmt->close(); ?>