<?php
/**
 * Mirza Pro Bot Installer API
 * Backend handler for installation process
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Get action from query string
$action = $_GET['action'] ?? '';

// Handle different actions
switch ($action) {
    case 'check_requirements':
        checkSystemRequirements();
        break;
    
    case 'test_database':
        testDatabaseConnection();
        break;
    
    case 'install':
        performInstallation();
        break;
    
    case 'cleanup':
        cleanupInstaller();
        break;
    
    default:
        sendResponse(false, 'Invalid action');
}

/**
 * Check system requirements
 */
function checkSystemRequirements() {
    $requirements = [];
    
    // Check PHP version
    $phpVersion = phpversion();
    $requirements['php_version'] = [
        'status' => version_compare($phpVersion, '8.2.0', '>='),
        'message' => "نسخه PHP: $phpVersion (نیاز: 8.2+)"
    ];
    
    // Check required extensions
    $extensions = ['mysqli', 'pdo', 'pdo_mysql', 'mbstring', 'zip', 'gd', 'json', 'curl', 'soap'];
    foreach ($extensions as $ext) {
        $requirements["ext_$ext"] = [
            'status' => extension_loaded($ext),
            'message' => "افزونه $ext"
        ];
    }
    
    // Check if directory is writable
    $baseDir = dirname(__DIR__);
    $requirements['writable'] = [
        'status' => is_writable($baseDir),
        'message' => "دسترسی نوشتن در پوشه"
    ];
    
    // Check if Apache is running
    $requirements['apache'] = [
        'status' => function_exists('apache_get_version') || isset($_SERVER['SERVER_SOFTWARE']),
        'message' => "وب سرور فعال"
    ];
    
    sendResponse(true, 'Requirements checked', ['requirements' => $requirements]);
}

/**
 * Test database connection and create database
 */
function testDatabaseConnection() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $dbRootUser = $input['dbRootUser'] ?? '';
    $dbRootPassword = $input['dbRootPassword'] ?? '';
    $dbName = $input['dbName'] ?? '';
    $dbUser = $input['dbUser'] ?? '';
    $dbPassword = $input['dbPassword'] ?? '';
    
    // Validate inputs
    if (empty($dbRootUser) || empty($dbRootPassword) || empty($dbName) || empty($dbUser) || empty($dbPassword)) {
        sendResponse(false, 'تمام فیلدها الزامی هستند');
        return;
    }
    
    try {
        // Test root connection
        $conn = new mysqli('localhost', $dbRootUser, $dbRootPassword);
        
        if ($conn->connect_error) {
            sendResponse(false, "خطا در اتصال: " . $conn->connect_error);
            return;
        }
        
        // Create database
        $dbName = $conn->real_escape_string($dbName);
        $sql = "CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        
        if (!$conn->query($sql)) {
            sendResponse(false, "خطا در ایجاد دیتابیس: " . $conn->error);
            return;
        }
        
        // Create user with privileges
        $dbUser = $conn->real_escape_string($dbUser);
        $dbPassword = $conn->real_escape_string($dbPassword);
        
        // Drop user if exists
        $conn->query("DROP USER IF EXISTS '$dbUser'@'localhost'");
        $conn->query("DROP USER IF EXISTS '$dbUser'@'%'");
        
        // Create new user
        $sql = "CREATE USER '$dbUser'@'localhost' IDENTIFIED BY '$dbPassword'";
        if (!$conn->query($sql)) {
            sendResponse(false, "خطا در ایجاد کاربر: " . $conn->error);
            return;
        }
        
        // Grant privileges
        $sql = "GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'localhost'";
        if (!$conn->query($sql)) {
            sendResponse(false, "خطا در تنظیم دسترسی‌ها: " . $conn->error);
            return;
        }
        
        $conn->query("FLUSH PRIVILEGES");
        $conn->close();
        
        sendResponse(true, 'اتصال موفقیت‌آمیز و دیتابیس آماده است');
        
    } catch (Exception $e) {
        sendResponse(false, 'خطا: ' . $e->getMessage());
    }
}

/**
 * Perform complete installation
 */
function performInstallation() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $database = $input['database'] ?? [];
    $bot = $input['bot'] ?? [];
    
    // Validate inputs
    if (empty($database) || empty($bot)) {
        sendResponse(false, 'اطلاعات ناقص است');
        return;
    }
    
    $dbName = $database['dbName'];
    $dbUser = $database['dbUser'];
    $dbPassword = $database['dbPassword'];
    $botToken = $bot['botToken'];
    $adminChatId = $bot['adminChatId'];
    $domain = $bot['domain'];
    $botUsername = $bot['botUsername'];
    
    try {
        // Step 1: Create config.php
        $configPath = dirname(__DIR__) . '/config.php';
        $configContent = <<<PHP
<?php
\$dbname = '$dbName';
\$usernamedb = '$dbUser';
\$passworddb = '$dbPassword';
\$connect = mysqli_connect("localhost", \$usernamedb, \$passworddb, \$dbname);
if (\$connect->connect_error) { die("error" . \$connect->connect_error); }
mysqli_set_charset(\$connect, "utf8mb4");
\$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
\$dsn = "mysql:host=localhost;dbname=\$dbname;charset=utf8mb4";
try { \$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options); } catch (\PDOException \$e) { error_log("Database connection failed: " . \$e->getMessage()); }
\$APIKEY = '$botToken';
\$adminnumber = '$adminChatId';
\$domainhosts = '$domain/mirzaprobotconfig';
\$usernamebot = '$botUsername';

\$new_marzban = true;
?>
PHP;
        
        if (!file_put_contents($configPath, $configContent)) {
            sendResponse(false, 'خطا در ایجاد فایل config.php');
            return;
        }
        
        // Step 2: Execute table.php to create all database tables
        $tablePath = dirname(__DIR__) . '/table.php';
        if (file_exists($tablePath)) {
            // Suppress output from table.php
            ob_start();
            include $tablePath;
            ob_end_clean();
        } else {
            sendResponse(false, 'فایل table.php یافت نشد');
            return;
        }
        
        // Step 3: Set webhook
        $webhookUrl = "https://$domain/mirzaprobotconfig/index.php";
        $secretToken = bin2hex(random_bytes(16));
        
        $telegramApiUrl = "https://api.telegram.org/bot$botToken/setWebhook";
        $postData = [
            'url' => $webhookUrl,
            'secret_token' => $secretToken
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $telegramApiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            sendResponse(false, 'خطا در ثبت Webhook - لطفا توکن و دامنه را بررسی کنید');
            return;
        }
        
        // Step 4: Send success message to admin
        $message = "✅ ربات میرزا پرو با موفقیت نصب شد!\n\n";
        $message .= "🔗 دامنه: https://$domain/mirzaprobotconfig\n";
        $message .= "📊 دیتابیس: $dbName\n\n";
        $message .= "برای شروع، دستور /start را ارسال کنید.";
        
        $telegramSendUrl = "https://api.telegram.org/bot$botToken/sendMessage";
        $postData = [
            'chat_id' => $adminChatId,
            'text' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $telegramSendUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
        
        // Step 5: Create installation marker
        file_put_contents(dirname(__DIR__) . '/.installed', date('Y-m-d H:i:s'));
        
        sendResponse(true, 'نصب با موفقیت انجام شد', [
            'credentials' => [
                'domain' => $domain,
                'dbName' => $dbName,
                'dbUser' => $dbUser,
                'dbPassword' => $dbPassword,
                'botUsername' => $botUsername
            ]
        ]);
        
    } catch (Exception $e) {
        sendResponse(false, 'خطا در نصب: ' . $e->getMessage());
    }
}

/**
 * Cleanup installer files
 */
function cleanupInstaller() {
    try {
        // Delete installer directory
        $installerDir = __DIR__;
        
        // Delete files in installer directory
        $files = glob($installerDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        
        // Try to delete directory
        @rmdir($installerDir);
        
        sendResponse(true, 'نصاب با موفقیت حذف شد');
    } catch (Exception $e) {
        sendResponse(false, 'خطا در حذف نصاب: ' . $e->getMessage());
    }
}

/**
 * Send JSON response
 */
function sendResponse($success, $message, $data = []) {
    $response = array_merge([
        'success' => $success,
        'message' => $message
    ], $data);
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>
