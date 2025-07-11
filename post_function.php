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
    error_log("Post Function error: Unauthorized access - user_id: " . ($_SESSION['user_id'] ?? 'none') . ", role: " . ($_SESSION['role'] ?? 'none'));
    header("Location: index.php");
    exit;
}

$errors = [];
$success = '';
$agents = [];

// Fetch agents
$sql = "SELECT id, name, location FROM users WHERE role = 'agent'";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $agents[] = $row;
    }
} else {
    $errors[] = "முகவர்களை ஏற்றுவதில் பிழை: " . $conn->error;
    error_log("Post Function error: Fetch agents failed - " . $conn->error);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "தவறான CSRF டோக்கன்";
        error_log("Post Function error: Invalid CSRF token");
    } else {
        // Sanitize inputs
        $type = filter_input(INPUT_POST, 'type', FILTER_SANITIZE_STRING);
        $location = filter_input(INPUT_POST, 'location', FILTER_SANITIZE_STRING);
        $date = filter_input(INPUT_POST, 'date', FILTER_SANITIZE_STRING);
        $time = filter_input(INPUT_POST, 'time', FILTER_SANITIZE_STRING);
        $num_workers = filter_input(INPUT_POST, 'num_workers', FILTER_VALIDATE_INT);
        $assigned_to_id = filter_input(INPUT_POST, 'assigned_to_id', FILTER_VALIDATE_INT);

        // Validate inputs
        if (!$type || !$location || !$date || !$time || !$num_workers || $num_workers < 1) {
            $errors[] = "அனைத்து புலங்களும் தேவை மற்றும் பணியாளர்களின் எண்ணிக்கை 1 அல்லது அதற்கு மேல் இருக்க வேண்டும்";
        } elseif (!$assigned_to_id || !in_array($assigned_to_id, array_column($agents, 'id'))) {
            $errors[] = "சரியான முகவரைத் தேர்ந்தெடுக்கவும்";
        } else {
            // Insert function
            $sql = "INSERT INTO functions (customer_id, assigned_to_id, type, location, date, time, num_workers, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("iissssi", $_SESSION['user_id'], $assigned_to_id, $type, $location, $date, $time, $num_workers);
                if ($stmt->execute()) {
                    $function_id = $conn->insert_id;
                    $success = "நிகழ்வு வெற்றிகரமாக உருவாக்கப்பட்டது";

                    // Send notification to agent
                    $message = "புதிய நிகழ்வு (ஐடி: $function_id, வகை: $type) உங்களுக்கு ஒதுக்கப்பட்டது";
                    $sql_notify = "INSERT INTO notifications (user_id, message, read_status, created_at) VALUES (?, ?, 'unread', NOW())";
                    $stmt_notify = $conn->prepare($sql_notify);
                    $stmt_notify->bind_param("is", $assigned_to_id, $message);
                    $stmt_notify->execute();
                    $stmt_notify->close();

                    // Redirect to dashboard
                    header("Location: dashboard.php?success=" . urlencode($success));
                    exit;
                } else {
                    $errors[] = "நிகழ்வு உருவாக்குவதில் பிழை: " . $stmt->error;
                    error_log("Post Function error: Insert failed - " . $stmt->error);
                }
                $stmt->close();
            } else {
                $errors[] = "தரவுத்தள பிழை: " . $conn->error;
                error_log("Post Function error: Prepare failed - " . $conn->error);
            }
        }
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EventAI - புதிய நிகழ்வு சேர்</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h1>புதிய நிகழ்வு சேர்</h1>
        <?php if ($errors): ?>
            <?php foreach ($errors as $error): ?>
                <p class="error"><?php echo htmlspecialchars($error); ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>
        
        <form method="POST" class="form-container">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="form-group">
                <label for="type">நிகழ்வு வகை</label>
                <input type="text" id="type" name="type" value="<?php echo isset($_POST['type']) ? htmlspecialchars($_POST['type']) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="location">இடம்</label>
                <input type="text" id="location" name="location" value="<?php echo isset($_POST['location']) ? htmlspecialchars($_POST['location']) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="date">தேதி</label>
                <input type="date" id="date" name="date" value="<?php echo isset($_POST['date']) ? htmlspecialchars($_POST['date']) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="time">நேரம்</label>
                <input type="time" id="time" name="time" value="<?php echo isset($_POST['time']) ? htmlspecialchars($_POST['time']) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="num_workers">பணியாளர்களின் எண்ணிக்கை</label>
                <input type="number" id="num_workers" name="num_workers" min="1" value="<?php echo isset($_POST['num_workers']) ? htmlspecialchars($_POST['num_workers']) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="assigned_to_id">முகவரைத் தேர்ந்தெடு</label>
                <select id="assigned_to_id" name="assigned_to_id" required>
                    <option value="">-- முகவரைத் தேர்ந்தெடு --</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo htmlspecialchars($agent['id']); ?>" <?php echo isset($_POST['assigned_to_id']) && $_POST['assigned_to_id'] == $agent['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($agent['name'] . ' (' . $agent['location'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">நிகழ்வு உருவாக்கு</button>
        </form>
        <p><a href="dashboard.php">டாஷ்போர்டுக்கு திரும்பு</a></p>
    </div>
</body>
</html>
<?php $conn->close(); ?>