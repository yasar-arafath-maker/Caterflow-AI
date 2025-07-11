<?php
session_start();
require 'includes/db_connect.php';
require_once 'includes/tcpdf/tcpdf.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$name = $_SESSION['name'] ?? 'User';
$role = $_SESSION['role'];
$error = null;

if (!isset($_GET['function_id'])) {
    $error = "நிகழ்வு குறிப்பிடப்படவில்லை.";
} else {
    $function_id = intval($_GET['function_id']);
    $sql = "SELECT f.*, u.name as agent_name 
            FROM functions f 
            JOIN users u ON f.assigned_to_id = u.id 
            WHERE f.id = ?";
    if ($role == 'customer') {
        $sql .= " AND f.customer_id = ?";
    } elseif ($role == 'agent') {
        $sql .= " AND f.assigned_to_id = ?";
    } else {
        $sql .= " AND EXISTS (SELECT 1 FROM assignments a WHERE a.function_id = f.id AND a.worker_id = ?)";
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $error = "வினவல் தோல்வி: " . $conn->error;
    } else {
        $stmt->bind_param("ii", $function_id, $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 0) {
            $error = "தவறான நிகழ்வு.";
        } else {
            $function = $result->fetch_assoc();
            $num_workers = $function['num_workers'];
            $payment_type = $function['payment_type'];
            $payment_amount = $function['payment_amount'];

            $worker_base_total = (($num_workers - 1) * 350) + 400;
            $platform_fee = $num_workers * 100;

            $sql = "SELECT COUNT(*) as agent_assignments FROM assignments WHERE function_id = ? AND worker_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $function_id, $function['assigned_to_id']);
            $stmt->execute();
            $is_agent_as_worker = $stmt->get_result()->fetch_assoc()['agent_assignments'] > 0;
            $agent_commission = $is_agent_as_worker ? 0 : 50;
            $total_payment = $worker_base_total + $agent_commission + $platform_fee;
            $balance_due = ($payment_type === 'advance') ? $total_payment - $payment_amount : 0;

            $sql = "SELECT u.name, a.role 
                    FROM assignments a 
                    JOIN users u ON a.worker_id = u.id 
                    WHERE a.function_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $function_id);
            $stmt->execute();
            $worker_result = $stmt->get_result();
            $worker_list = [];
            while ($worker = $worker_result->fetch_assoc()) {
                $worker_list[] = $worker['name'] . " (" . ($worker['role'] == 'in-charge' ? 'பொறுப்பாளர்' : 'பணியாளர்') . ")";
            }
            $worker_names = empty($worker_list) ? 'எவரும் இல்லை' : implode(', ', $worker_list);
        }
    }
}

if (isset($_GET['download_pdf']) && $_GET['download_pdf'] == '1' && isset($function)) {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('EventAI');
    $pdf->SetTitle('நிகழ்வு #' . $function_id . ' கட்டண பிரிவு');
    $pdf->SetSubject('Payment Split');

    // Tamil font registration (must run addfont or load existing)
    $fontPath = __DIR__ . '/includes/tcpdf/fonts/NotoSerifTamil-Regular.ttf';
    $fontname = TCPDF_FONTS::addTTFfont($fontPath, 'TrueTypeUnicode', '', 96);

    $pdf->SetFont($fontname, '', 12);
    $pdf->setHeaderFont([$fontname, '', 10]);
    $pdf->setFooterFont([$fontname, '', 8]);

    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    $pdf->AddPage();

    $html = '
    <h1 style="text-align: center;">நிகழ்வு #' . htmlspecialchars($function_id) . ' க்கான கட்டண பிரிவு</h1>
    <h2>கட்டண விவரங்கள்</h2>
    <p><strong>வாடிக்கையாளர்:</strong> ' . htmlspecialchars($name) . '</p>
    <p><strong>நிகழ்வு வகை:</strong> ' . htmlspecialchars($function['type']) . '</p>
    <p><strong>இடம்:</strong> ' . htmlspecialchars($function['location']) . '</p>
    <p><strong>தேதி:</strong> ' . htmlspecialchars($function['date']) . '</p>
    <p><strong>பணியாளர்களின் எண்ணிக்கை:</strong> ' . $num_workers . '</p>
    <p><strong>கட்டண வகை:</strong> ' . ($payment_type == 'advance' ? 'முன்பணம்' : ($payment_type == 'full' ? 'முழு தொகை' : 'மீதித் தொகை')) . '</p>
    <p><strong>செலுத்தப்பட்ட தொகை:</strong> ₹' . number_format($payment_amount, 2) . '</p>
    <p><strong>மீதமுள்ள தொகை:</strong> ₹' . number_format($balance_due, 2) . '</p>
    <p><strong>நிலை:</strong> ' . htmlspecialchars($function['status']) . '</p>';
    if ($is_agent_as_worker) {
        $html .= '<p><strong>குறிப்பு:</strong> முகவர் (' . htmlspecialchars($function['agent_name']) . ') பணியாளர்களில் ஒருவராக உள்ளார்.</p>';
    }
    $html .= '
    <p><strong>பணியாளர் பட்டியல்:</strong> ' . htmlspecialchars($worker_names) . '</p>
    <h2>கட்டண பிரிவு</h2>
    <table border="1" cellpadding="4">
        <tr style="background-color: #f2f2f2;">
            <th>கூறு</th>
            <th>தொகை (₹)</th>
        </tr>
        <tr>
            <td>பணியாளர்கள் (' . ($num_workers - 1) . ' × ₹350 + 1 × ₹400)</td>
            <td>' . number_format($worker_base_total, 2) . '</td>
        </tr>
        <tr>
            <td>முகவர் கமிஷன்</td>
            <td>' . number_format($agent_commission, 2) . '</td>
        </tr>
        <tr>
            <td>தளக் கட்டணம் (' . $num_workers . ' × ₹100)</td>
            <td>' . number_format($platform_fee, 2) . '</td>
        </tr>
        <tr style="font-weight: bold;">
            <td>மொத்தம்</td>
            <td>' . number_format($total_payment, 2) . '</td>
        </tr>
    </table>';

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('payment_split_function_' . $function_id . '.pdf', 'D');
    exit;
}
?>

<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <title>EventAI - கட்டண பிரிவு</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h1>நிகழ்வு #<?php echo htmlspecialchars($function_id); ?> க்கான கட்டண பிரிவு</h1>
        <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
        <?php if (isset($function)): ?>
            <h2>கட்டண விவரங்கள்</h2>
            <p><strong>வாடிக்கையாளர்:</strong> <?php echo htmlspecialchars($name); ?></p>
            <p><strong>நிகழ்வு வகை:</strong> <?php echo htmlspecialchars($function['type']); ?></p>
            <p><strong>இடம்:</strong> <?php echo htmlspecialchars($function['location']); ?></p>
            <p><strong>தேதி:</strong> <?php echo htmlspecialchars($function['date']); ?></p>
            <p><strong>பணியாளர்களின் எண்ணிக்கை:</strong> <?php echo $num_workers; ?></p>
            <p><strong>கட்டண வகை:</strong> <?php echo ($payment_type == 'advance' ? 'முன்பணம்' : ($payment_type == 'full' ? 'முழு தொகை' : 'மீதித் தொகை')); ?></p>
            <p><strong>செலுத்தப்பட்ட தொகை:</strong> ₹<?php echo number_format($payment_amount, 2); ?></p>
            <p><strong>மீதமுள்ள தொகை:</strong> ₹<?php echo number_format($balance_due, 2); ?></p>
            <p><strong>நிலை:</strong> <?php echo htmlspecialchars($function['status']); ?></p>
            <?php if ($is_agent_as_worker): ?>
                <p><strong>குறிப்பு:</strong> முகவர் (<?php echo htmlspecialchars($function['agent_name']); ?>) பணியாளராக உள்ளார்.</p>
            <?php endif; ?>
            <p><strong>பணியாளர் பட்டியல்:</strong> <?php echo htmlspecialchars($worker_names); ?></p>
            <h2>கட்டண பிரிவு</h2>
            <table>
                <tr><th>கூறு</th><th>தொகை (₹)</th></tr>
                <tr><td>பணியாளர்கள் (<?php echo ($num_workers - 1); ?> × ₹350 + 1 × ₹400)</td><td><?php echo number_format($worker_base_total, 2); ?></td></tr>
                <tr><td>முகவர் கமிஷன்</td><td><?php echo number_format($agent_commission, 2); ?></td></tr>
                <tr><td>தளக் கட்டணம் (<?php echo $num_workers; ?> × ₹100)</td><td><?php echo number_format($platform_fee, 2); ?></td></tr>
                <tr><th>மொத்தம்</th><th><?php echo number_format($total_payment, 2); ?></th></tr>
            </table>
            <p><a href="?function_id=<?php echo $function_id; ?>&download_pdf=1"><button>PDF பதிவிறக்கு</button></a></p>
        <?php endif; ?>
        <p><a href="dashboard.php">டாஷ்போர்டு</a></p>
    </div>
</body>
</html>
<?php if (isset($stmt)) $stmt->close(); ?>
<?php $conn->close(); ?>
