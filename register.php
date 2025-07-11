<?php
session_start();
require 'includes/db_connect.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$success = $error = null;
$stmt = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid CSRF token.";
    } else {
        // Sanitize inputs
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        $role = trim($_POST['role']);
        $location = trim($_POST['location']);
        $phone = trim($_POST['phone']);
        $experience = trim($_POST['experience']);

        // Basic validation
        if (empty($name) || empty($email) || empty($password) || empty($role)) {
            $error = "Name, email, password, and role are required.";
        } elseif (!in_array($role, ['customer', 'worker', 'agent'])) {
            $error = "Invalid role selected.";
        } else {
            // Check if email already exists
            $sql = "SELECT id FROM users WHERE email = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $error = "Email already registered. Please use a different email.";
            } else {
                // Insert user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO users (name, email, password, role, location, phone, experience) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssss", $name, $email, $hashed_password, $role, $location, $phone, $experience);

                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Registration successful! Please log in.";
                    header("Location: login.php");
                    exit;
                } else {
                    $error = "Error registering user: " . $conn->error;
                }
            }
        }
    }
}

// Generate CSRF token for next request
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EventAI - Register</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="container">
    <h1>Register for EventAI</h1>

    <?php if ($error): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php elseif (isset($_SESSION['success_message'])): ?>
        <p class="success"><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></p>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="text" name="name" placeholder="Name" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <select name="role" required>
            <option value="">Select Role</option>
            <option value="customer">Customer</option>
            <option value="worker">Worker</option>
            <option value="agent">Agent</option>
        </select>
        <input type="text" name="location" placeholder="Location (e.g., Chennai)">
        <input type="text" name="phone" placeholder="Phone (e.g., +919876543210)">
        <input type="text" name="experience" placeholder="Experience (e.g., catering, decoration)">
        <button type="submit">Register</button>
    </form>
    <p>Already have an account? <a href="login.php">Log in</a></p>
</div>
</body>
</html>

<?php
// Close statement if initialized
if ($stmt !== null) {
    $stmt->close();
}
$conn->close();
?>
