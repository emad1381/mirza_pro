<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$action = $_GET['action'] ?? '';

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

function checkSystemRequirements()
{
    $requirements = [];
    $phpVersion = phpversion();
    $requirements['php_version'] = [
        'status' => version_compare($phpVersion, '8.2.0', '>='),
        'message' => "نسخه PHP: $phpVersion (نیاز: 8.2+)"
    ];

    $extensions = ['mysqli', 'pdo', 'pdo_mysql', 'mbstring', 'zip', 'gd', 'json', 'curl', 'soap'];
    foreach ($extensions as $ext) {
        $requirements["ext_$ext"] = [
            'status' => extension_loaded($ext),
            'message' => "افزونه $ext"
        ];
    }

    $baseDir = dirname(__DIR__);
    $requirements['writable'] = [
        'status' => is_writable($baseDir),
        'message' => "دسترسی نوشتن در پوشه"
    ];

    $requirements['apache'] = [
        'status' => function_exists('apache_get_version') || isset($_SERVER['SERVER_SOFTWARE']),
        'message' => "وب سرور فعال"
    ];

    sendResponse(true, 'Requirements checked', ['requirements' => $requirements]);
}

function testDatabaseConnection()
{
    $input = json_decode(file_get_contents('php://input'), true);
    $dbName = $input['dbName'] ?? '';
    $dbUser = $input['dbUser'] ?? '';
    $dbPassword = $input['dbPassword'] ?? '';

    if (empty($dbName) || empty($dbUser) || empty($dbPassword)) {
        sendResponse(false, 'تمام فیلدها الزامی هستند');
        return;
    }

    try {
        $conn = new mysqli('localhost', $dbUser, $dbPassword, $dbName);
        if ($conn->connect_error) {
            sendResponse(false, "خطا در اتصال به دیتابیس: " . $conn->connect_error);
            return;
        }

        $result = $conn->query("SELECT 1");
        if (!$result) {
            sendResponse(false, "دیتابیس متصل شد اما قادر به اجرای کوئری نیست");
            return;
        }

        $conn->set_charset("utf8mb4");
        $conn->close();
        sendResponse(true, 'اتصال موفقیت‌آمیز! دیتابیس آماده استفاده است');
    } catch (Exception $e) {
        sendResponse(false, 'خطا: ' . $e->getMessage());
    }
}

function performInstallation()
{
    $input = json_decode(file_get_contents('php://input'), true);
    $database = $input['database'] ?? [];
    $bot = $input['bot'] ?? [];

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
        // Auto-detect bot path from installer URL
        $scriptPath = $_SERVER['PHP_SELF'] ?? '';
        $botPath = dirname(dirname($scriptPath));
        if ($botPath === '/' || $botPath === '\\') {
            $botPath = '';
        }

        // Create config.php
        $configPath = dirname(__DIR__) . '/config.php';
        $configContent = "<?php\n";
        $configContent .= "\$dbname = '$dbName';\n";
        $configContent .= "\$usernamedb = '$dbUser';\n";
        $configContent .= "\$passworddb = '$dbPassword';\n";
        $configContent .= "\$connect = mysqli_connect('localhost', \$usernamedb, \$passworddb, \$dbname);\n";
        $configContent .= "if (\$connect->connect_error) { die('error' . \$connect->connect_error); }\n";
        $configContent .= "mysqli_set_charset(\$connect, 'utf8mb4');\n";
        $configContent .= "\$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];\n";
        $configContent .= "\$dsn = 'mysql:host=localhost;dbname=' . \$dbname . ';charset=utf8mb4';\n";
        $configContent .= "try { \$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options); } catch (PDOException \$e) { error_log('Database connection failed: ' . \$e->getMessage()); }\n";
        $configContent .= "\$APIKEY = '$botToken';\n";
        $configContent .= "\$adminnumber = '$adminChatId';\n";
        $configContent .= "\$domainhosts = '$domain$botPath';\n";
        $configContent .= "\$usernamebot = '$botUsername';\n";
        $configContent .= "\$new_marzban = true;\n";
        $configContent .= "?>";

        if (!file_put_contents($configPath, $configContent)) {
            sendResponse(false, 'خطا در ایجاد فایل config.php');
            return;
        }

        // Establish database connection
        global $connect, $pdo, $dbname, $usernamedb, $passworddb, $APIKEY, $adminnumber, $domainhosts, $usernamebot, $new_marzban;

        $connect = mysqli_connect("localhost", $dbUser, $dbPassword, $dbName);
        if ($connect->connect_error) {
            sendResponse(false, "خطا در اتصال به دیتابیس: " . $connect->connect_error);
            return;
        }
        mysqli_set_charset($connect, "utf8mb4");

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        $dsn = "mysql:host=localhost;dbname=$dbName;charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPassword, $options);
        } catch (PDOException $e) {
            sendResponse(false, "خطا در اتصال PDO: " . $e->getMessage());
            return;
        }

        $dbname = $dbName;
        $usernamedb = $dbUser;
        $passworddb = $dbPassword;
        $APIKEY = $botToken;
        $adminnumber = $adminChatId;
        $domainhosts = "$domain$botPath";
        $usernamebot = $botUsername;
        $new_marzban = true;

        // Execute table.php
        $tablePath = dirname(__DIR__) . '/table.php';
        if (file_exists($tablePath)) {
            ob_start();
            include $tablePath;
            ob_end_clean();
        } else {
            sendResponse(false, 'فایل table.php یافت نشد');
            return;
        }

        // Create installation marker
        file_put_contents(dirname(__DIR__) . '/.installed', date('Y-m-d H:i:s'));

        // Prepare webhook URL for manual setup
        $webhookUrl = "https://$domain$botPath/index.php";
        $webhookSetupUrl = "https://api.telegram.org/bot$botToken/setWebhook?url=" . urlencode($webhookUrl);

        sendResponse(true, 'نصب با موفقیت انجام شد!', [
            'credentials' => [
                'domain' => $domain,
                'dbName' => $dbName,
                'dbUser' => $dbUser,
                'dbPassword' => $dbPassword,
                'botUsername' => $botUsername
            ],
            'webhookUrl' => $webhookSetupUrl
        ]);

    } catch (Exception $e) {
        sendResponse(false, 'خطا در نصب: ' . $e->getMessage());
    }
}

function cleanupInstaller()
{
    try {
        $installerDir = __DIR__;
        $files = glob($installerDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($installerDir);
        sendResponse(true, 'نصاب با موفقیت حذف شد');
    } catch (Exception $e) {
        sendResponse(false, 'خطا در حذف نصاب: ' . $e->getMessage());
    }
}

function sendResponse($success, $message, $data = [])
{
    $response = array_merge([
        'success' => $success,
        'message' => $message
    ], $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>
