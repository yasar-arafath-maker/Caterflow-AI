<?php
session_start();
include 'includes/db_connect.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email    = $_POST['email'];
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['role']      = $user['role'];

        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Invalid login credentials!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - EventAI</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <h2>Login</h2>
    <form method="POST">
        <label>Email:</label><br>
        <input type="email" name="email" required><br><br>

        <label>Password:</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit">Login</button>
    </form>
    <p style="color: red;"><?php echo $error; ?></p>
    <p>Don't have an account? <a href="register.php">Register here</a>.</p>
</body>
</html>
