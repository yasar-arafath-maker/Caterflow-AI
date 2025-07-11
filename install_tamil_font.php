<?php
$fontPath = 'includes/tcpdf/fonts/NotoSerifTamil-Regular.ttf';
if (file_exists($fontPath)) {
    echo "✅ Font file exists!";
} else {
    echo "❌ Font file not found at: " . $fontPath;
}
