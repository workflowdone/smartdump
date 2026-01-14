                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="drop_database">
                                <label class="form-check-label text-danger">
                                    <strong>DROP & RECREATE DATABASE</strong> (Deletes all data!)
                                </label>
                            </div>
                        </div><?php
/**
 * SmartDump - Modern SQL Import Tool
 * A clean, modern, step-by-step SQL import solution
 * 
 * @version 1.0.0
 * @license MIT
 * 
 * SECURITY: DELETE THIS FILE AFTER USE!
 */

// Handle AJAX requests FIRST - before anything else
if (isset($_GET['action'])) {
    // Kill all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    ob_start();
    
    $action = $_GET['action'] ?? '';
    $allowedActions = [
        'upload', 'list_files', 'delete_file', 'test_connection',
        'detect_charset', 'detect_prefix', 'import', 'get_logs',
        'backup_database', 'download_backup', 'list_backups'
    ];
    
    if (!in_array($action, $allowedActions)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
    
    ob_end_clean();
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Include only necessary functions
    define('AJAX_REQUEST', true);
}

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable display, we'll log instead
ini_set('log_errors', 1);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

// Configuration
define('UPLOAD_DIR', __DIR__ . '/smartdump_uploads');
define('BACKUP_DIR', __DIR__ . '/smartdump_backups');
define('LOG_DIR', __DIR__ . '/smartdump_logs');
define('MAX_UPLOAD_SIZE', 500 * 1024 * 1024);
define('VERSION', '1.0.0');
define('ENABLE_IP_WHITELIST', false);
define('ALLOWED_IPS', ['127.0.0.1', '::1']);
define('PAYPAL_EMAIL', 'your@paypal.com'); // CHANGE THIS!

// Security: IP Whitelist
if (!defined('AJAX_REQUEST') && ENABLE_IP_WHITELIST) {
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($clientIP, ALLOWED_IPS)) {
        die('Access denied. Your IP is not whitelisted.');
    }
}

// Create directories with .htaccess protection
$htaccessContent = "Order deny,allow\nDeny from all\n";
foreach ([UPLOAD_DIR, BACKUP_DIR, LOG_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $htaccessFile = $dir . '/.htaccess';
    if (!file_exists($htaccessFile)) {
        file_put_contents($htaccessFile, $htaccessContent);
    }
}

// NOW handle AJAX after setup
if (defined('AJAX_REQUEST')) {
    switch ($_GET['action']) {
        case 'upload': handleFileUpload(); break;
        case 'list_files': listFiles(); break;
        case 'delete_file': deleteFile(); break;
        case 'test_connection': testConnection(); break;
        case 'detect_charset': detectCharset(); break;
        case 'detect_prefix': detectPrefix(); break;
        case 'import': executeImport(); break;
        case 'get_logs': getLogs(); break;
        case 'backup_database': backupDatabase(); break;
        case 'download_backup': downloadBackup(); break;
        case 'list_backups': listBackups(); break;
    }
    exit;
}

// Helper Functions
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function logMessage($sessionId, $message, $type = 'info') {
    $logFile = LOG_DIR . '/' . $sessionId . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$type}] {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

function handleFileUpload() {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'message' => 'Invalid request method']);
        }
        
        if (!isset($_FILES['sql_file'])) {
            jsonResponse(['success' => false, 'message' => 'No file uploaded']);
        }
        
        $file = $_FILES['sql_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
            ];
            $errorMsg = $errorMessages[$file['error']] ?? 'Unknown upload error: ' . $file['error'];
            jsonResponse(['success' => false, 'message' => $errorMsg]);
        }
        
        // Check if upload directory is writable
        if (!is_writable(UPLOAD_DIR)) {
            jsonResponse(['success' => false, 'message' => 'Upload directory is not writable. Set permissions to 755 or 777.']);
        }
        
        $allowedExtensions = ['sql', 'gz'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowedExtensions)) {
            jsonResponse(['success' => false, 'message' => 'Only .sql and .gz files allowed']);
        }
        
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $file['name']);
        $filepath = UPLOAD_DIR . '/' . $filename;
        
        // Handle duplicate filenames - add timestamp
        if (file_exists($filepath)) {
            $fileInfo = pathinfo($filename);
            $baseName = $fileInfo['filename'];
            $extension = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
            $filename = $baseName . '_' . time() . $extension;
            $filepath = UPLOAD_DIR . '/' . $filename;
        }
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            jsonResponse(['success' => false, 'message' => 'Failed to save file. Check directory permissions.']);
        }
        
        // Set proper permissions
        chmod($filepath, 0644);
        
        jsonResponse([
            'success' => true,
            'message' => 'File uploaded successfully',
            'filename' => $filename,
            'size' => formatBytes(filesize($filepath)),
            'bytes' => filesize($filepath)
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Upload error: ' . $e->getMessage()]);
    }
}

function listFiles() {
    try {
        $files = [];
        
        if (!is_dir(UPLOAD_DIR)) {
            jsonResponse(['success' => true, 'files' => []]);
        }
        
        if (!is_readable(UPLOAD_DIR)) {
            jsonResponse(['success' => false, 'message' => 'Upload directory is not readable']);
        }
        
        $items = @scandir(UPLOAD_DIR);
        
        if ($items === false) {
            jsonResponse(['success' => false, 'message' => 'Cannot read upload directory']);
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
            
            $filepath = UPLOAD_DIR . '/' . $item;
            if (is_file($filepath)) {
                $files[] = [
                    'name' => $item,
                    'size' => formatBytes(filesize($filepath)),
                    'bytes' => filesize($filepath),
                    'date' => date('Y-m-d H:i:s', filemtime($filepath))
                ];
            }
        }
        
        jsonResponse(['success' => true, 'files' => $files]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function deleteFile() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Invalid request']);
    }
    
    $filename = basename($_POST['filename'] ?? '');
    
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
        jsonResponse(['success' => false, 'message' => 'Invalid filename']);
    }
    
    $filepath = UPLOAD_DIR . '/' . $filename;
    $realPath = realpath($filepath);
    $uploadDirReal = realpath(UPLOAD_DIR);
    
    if (!$realPath || strpos($realPath, $uploadDirReal) !== 0) {
        jsonResponse(['success' => false, 'message' => 'Security: Invalid path']);
    }
    
    if (file_exists($filepath) && unlink($filepath)) {
        jsonResponse(['success' => true, 'message' => 'File deleted']);
    } else {
        jsonResponse(['success' => false, 'message' => 'Failed to delete file']);
    }
}

function testConnection() {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'message' => 'Invalid request method']);
        }
        
        $host = filter_var($_POST['db_host'] ?? '', FILTER_SANITIZE_STRING);
        $user = filter_var($_POST['db_user'] ?? '', FILTER_SANITIZE_STRING);
        $pass = $_POST['db_pass'] ?? '';
        $name = filter_var($_POST['db_name'] ?? '', FILTER_SANITIZE_STRING);
        
        // Validate inputs
        if (empty($host) || empty($user) || empty($name)) {
            jsonResponse(['success' => false, 'message' => 'Please fill in all required fields']);
        }
        
        // Suppress connection errors and handle manually
        $mysqli = @new mysqli($host, $user, $pass, $name);
        
        if ($mysqli->connect_error) {
            jsonResponse(['success' => false, 'message' => 'Connection failed: ' . htmlspecialchars($mysqli->connect_error)]);
        }
        
        $version = $mysqli->get_server_info();
        $mysqli->close();
        
        jsonResponse([
            'success' => true,
            'message' => 'Connected! MySQL ' . htmlspecialchars($version),
            'version' => htmlspecialchars($version)
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Error: ' . htmlspecialchars($e->getMessage())]);
    }
}

function detectCharset() {
    $filename = basename($_POST['filename'] ?? '');
    
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
        jsonResponse(['success' => false, 'message' => 'Invalid filename']);
    }
    
    $filepath = UPLOAD_DIR . '/' . $filename;
    
    if (!file_exists($filepath)) {
        jsonResponse(['success' => false, 'message' => 'File not found']);
    }
    
    $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $isGzip = ($extension === 'gz');
    
    $file = $isGzip ? gzopen($filepath, 'r') : fopen($filepath, 'r');
    
    if (!$file) {
        jsonResponse(['success' => false, 'message' => 'Cannot open file']);
    }
    
    $charset = 'utf8mb4';
    $collation = 'utf8mb4_unicode_ci';
    $linesChecked = 0;
    
    while (!feof($file) && $linesChecked < 100) {
        $line = $isGzip ? gzgets($file, 8192) : fgets($file, 8192);
        if ($line === false) break;
        
        $linesChecked++;
        
        if (preg_match('/SET NAMES\s+([a-z0-9_]+)/i', $line, $matches)) {
            $charset = $matches[1];
        }
        
        if (preg_match('/CHARSET[=\s]+([a-z0-9_]+)/i', $line, $matches)) {
            $charset = $matches[1];
        }
        
        if (preg_match('/COLLATE[=\s]+([a-z0-9_]+)/i', $line, $matches)) {
            $collation = $matches[1];
        }
    }
    
    if ($isGzip) {
        gzclose($file);
    } else {
        fclose($file);
    }
    
    jsonResponse([
        'success' => true,
        'charset' => $charset,
        'collation' => $collation
    ]);
}

function detectPrefix() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Invalid request']);
    }
    
    $host = filter_var($_POST['db_host'] ?? '', FILTER_SANITIZE_STRING);
    $user = filter_var($_POST['db_user'] ?? '', FILTER_SANITIZE_STRING);
    $pass = $_POST['db_pass'] ?? '';
    $name = filter_var($_POST['db_name'] ?? '', FILTER_SANITIZE_STRING);
    
    try {
        $mysqli = new mysqli($host, $user, $pass, $name);
        
        if ($mysqli->connect_error) {
            throw new Exception($mysqli->connect_error);
        }
        
        $result = $mysqli->query("SHOW TABLES");
        $tables = [];
        
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
        
        $mysqli->close();
        
        $prefix = '';
        if (count($tables) > 0) {
            $prefixes = [];
            foreach ($tables as $table) {
                if (preg_match('/^([a-z0-9_]+)_/', $table, $matches)) {
                    $prefixes[] = $matches[1] . '_';
                }
            }
            
            if (count($prefixes) > 0) {
                $prefixCount = array_count_values($prefixes);
                arsort($prefixCount);
                $prefix = key($prefixCount);
            }
        }
        
        jsonResponse([
            'success' => true,
            'prefix' => $prefix,
            'tableCount' => count($tables)
        ]);
        
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => htmlspecialchars($e->getMessage())]);
    }
}

function executeImport() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Invalid request']);
    }
    
    $config = $_POST;
    
    $host = filter_var($config['db_host'] ?? '', FILTER_SANITIZE_STRING);
    $user = filter_var($config['db_user'] ?? '', FILTER_SANITIZE_STRING);
    $pass = $config['db_pass'] ?? '';
    $name = filter_var($config['db_name'] ?? '', FILTER_SANITIZE_STRING);
    $charset = filter_var($config['db_charset'] ?? '', FILTER_SANITIZE_STRING);
    $collation = filter_var($config['db_collation'] ?? '', FILTER_SANITIZE_STRING);
    
    $allowedCharsets = ['utf8', 'utf8mb4', 'latin1', 'cp1251'];
    if (!empty($charset) && !in_array($charset, $allowedCharsets)) {
        jsonResponse(['success' => false, 'message' => 'Invalid charset']);
    }
    
    $sessionId = $config['session_id'] ?? uniqid('import_');
    $offset = (int)($config['offset'] ?? 0);
    $totalQueries = (int)($config['totalQueries'] ?? 0);
    $filename = basename($config['filename'] ?? '');
    
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
        jsonResponse(['success' => false, 'message' => 'Invalid filename']);
    }
    
    $filepath = UPLOAD_DIR . '/' . $filename;
    $realPath = realpath($filepath);
    $uploadDirReal = realpath(UPLOAD_DIR);
    
    if (!$realPath || strpos($realPath, $uploadDirReal) !== 0) {
        jsonResponse(['success' => false, 'message' => 'Security: Invalid path']);
    }
    
    if (!file_exists($filepath)) {
        jsonResponse(['success' => false, 'message' => 'File not found']);
    }
    
    try {
        $mysqli = new mysqli($host, $user, $pass, $name);
        
        if ($mysqli->connect_error) {
            throw new Exception($mysqli->connect_error);
        }
        
        if (!empty($charset)) {
            $mysqli->set_charset($charset);
        }
        
        if (!empty($collation)) {
            $stmt = $mysqli->prepare("SET collation_connection = ?");
            $stmt->bind_param("s", $collation);
            $stmt->execute();
            $stmt->close();
        }
        
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
        $mysqli->query("SET UNIQUE_CHECKS = 0");
        $mysqli->query("SET AUTOCOMMIT = 0");
        
        if ($offset === 0 && !empty($config['drop_database']) && $config['drop_database'] === 'true') {
            $dbNameEscaped = '`' . str_replace('`', '``', $name) . '`';
            $mysqli->query("DROP DATABASE IF EXISTS {$dbNameEscaped}");
            $mysqli->query("CREATE DATABASE {$dbNameEscaped}");
            $mysqli->select_db($name);
            logMessage($sessionId, "Database dropped and recreated", 'warning');
        }
        
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $isGzip = ($extension === 'gz');
        
        $file = $isGzip ? gzopen($filepath, 'r') : fopen($filepath, 'r');
        
        if (!$file) {
            throw new Exception('Failed to open file');
        }
        
        if ($isGzip) {
            gzseek($file, $offset);
        } else {
            fseek($file, $offset);
        }
        
        $maxQueries = (int)($config['max_queries'] ?? 300);
        $maxTime = (int)($config['max_time'] ?? 30);
        $startTime = time();
        
        $query = '';
        $queriesExecuted = 0;
        $queriesFailed = 0;
        $delimiter = ';';
        $queryBuffer = [];
        
        $oldPrefix = $config['old_prefix'] ?? '';
        $newPrefix = $config['new_prefix'] ?? '';
        $replacePrefix = !empty($oldPrefix) && !empty($newPrefix);
        
        if ($offset === 0) {
            logMessage($sessionId, "Starting import: " . basename($filepath));
            logMessage($sessionId, "File size: " . formatBytes(filesize($filepath)));
            if ($replacePrefix) {
                logMessage($sessionId, "Replacing prefix: {$oldPrefix} → {$newPrefix}");
            }
        }
        
        while (!feof($file) && $queriesExecuted < $maxQueries && (time() - $startTime) < $maxTime) {
            $line = $isGzip ? gzgets($file, 8192) : fgets($file, 8192);
            if ($line === false) break;
            
            $trimmedLine = trim($line);
            
            if (empty($trimmedLine) || 
                substr($trimmedLine, 0, 2) === '--' || 
                substr($trimmedLine, 0, 1) === '#') {
                continue;
            }
            
            if (stripos($trimmedLine, 'DELIMITER') === 0) {
                $parts = preg_split('/\s+/', $trimmedLine);
                if (isset($parts[1])) {
                    $delimiter = trim($parts[1]);
                }
                continue;
            }
            
            $query .= ' ' . $line;
            
            if (substr(rtrim($trimmedLine), -strlen($delimiter)) === $delimiter) {
                $query = trim($query);
                $query = substr($query, 0, -strlen($delimiter));
                
                if ($replacePrefix && !empty($query)) {
                    $query = preg_replace(
                        '/\b' . preg_quote($oldPrefix, '/') . '(\w+)\b/',
                        $newPrefix . '$1',
                        $query
                    );
                }
                
                if (!empty($query)) {
                    $queryBuffer[] = $query;
                    
                    if (count($queryBuffer) >= 10) {
                        foreach ($queryBuffer as $q) {
                            if (!$mysqli->query($q)) {
                                $queriesFailed++;
                            } else {
                                $queriesExecuted++;
                            }
                        }
                        $mysqli->commit();
                        $queryBuffer = [];
                    }
                    
                    $totalQueries++;
                }
                
                $query = '';
            }
        }
        
        if (!empty($queryBuffer)) {
            foreach ($queryBuffer as $q) {
                if (!$mysqli->query($q)) {
                    $queriesFailed++;
                } else {
                    $queriesExecuted++;
                }
            }
            $mysqli->commit();
        }
        
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
        $mysqli->query("SET UNIQUE_CHECKS = 1");
        $mysqli->query("SET AUTOCOMMIT = 1");
        
        $newOffset = $isGzip ? gztell($file) : ftell($file);
        $fileSize = filesize($filepath);
        $isComplete = feof($file);
        
        if ($isGzip) {
            gzclose($file);
        } else {
            fclose($file);
        }
        
        $mysqli->close();
        
        $progress = $isGzip ? 0 : round(($newOffset / $fileSize) * 100, 2);
        
        if ($queriesExecuted > 0) {
            logMessage($sessionId, "Batch: {$queriesExecuted} queries, {$queriesFailed} failed");
        }
        
        if ($isComplete) {
            logMessage($sessionId, "Import completed! Total: {$totalQueries}", 'success');
            
            // Post-import verification
            try {
                $result = $mysqli->query("SHOW TABLES");
                $tableCount = $result->num_rows;
                logMessage($sessionId, "Verification: {$tableCount} tables found", 'success');
                
                // Check if WordPress (most common use case)
                $wpCheck = $mysqli->query("SHOW TABLES LIKE '%posts'");
                if ($wpCheck && $wpCheck->num_rows > 0) {
                    $postCount = $mysqli->query("SELECT COUNT(*) as cnt FROM (SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$name}' AND TABLE_NAME LIKE '%posts') as t");
                    if ($postCount) {
                        logMessage($sessionId, "WordPress database detected - looks healthy!", 'success');
                    }
                }
            } catch (Exception $e) {
                // Ignore verification errors
            }
            
            if (!empty($config['notification_email']) && filter_var($config['notification_email'], FILTER_VALIDATE_EMAIL)) {
                $subject = 'SmartDump: Import Completed';
                $message = "<p><strong>Import completed successfully!</strong></p>
                    <ul>
                        <li>Database: " . htmlspecialchars($name) . "</li>
                        <li>Total Queries: {$totalQueries}</li>
                        <li>Successful: {$queriesExecuted}</li>
                        <li>Failed: {$queriesFailed}</li>
                        <li>Success Rate: " . round(($queriesExecuted / $totalQueries) * 100, 2) . "%</li>
                    </ul>
                    <p><strong>Recommendation:</strong> Test your website/application to ensure everything works correctly.</p>";
                mail($config['notification_email'], $subject, $message, "Content-Type: text/html");
            }
        }
        
        jsonResponse([
            'success' => true,
            'complete' => $isComplete,
            'offset' => $newOffset,
            'totalQueries' => $totalQueries,
            'queriesExecuted' => $queriesExecuted,
            'queriesFailed' => $queriesFailed,
            'progress' => $progress,
            'fileSize' => formatBytes($fileSize),
            'processed' => formatBytes($newOffset),
            'sessionId' => $sessionId
        ]);
        
    } catch (Exception $e) {
        logMessage($sessionId ?? 'error', "Error: " . $e->getMessage(), 'error');
        jsonResponse(['success' => false, 'message' => htmlspecialchars($e->getMessage())]);
    }
}

function getLogs() {
    $sessionId = basename($_GET['session_id'] ?? '');
    
    if (!preg_match('/^import_[0-9a-f]+$/', $sessionId)) {
        jsonResponse(['success' => false, 'message' => 'Invalid session ID']);
    }
    
    $logFile = LOG_DIR . '/' . $sessionId . '.log';
    
    if (!file_exists($logFile)) {
        jsonResponse(['success' => true, 'logs' => []]);
    }
    
    $logs = file($logFile, FILE_IGNORE_NEW_LINES);
    jsonResponse(['success' => true, 'logs' => $logs]);
}

function backupDatabase() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Invalid request']);
    }
    
    $host = filter_var($_POST['db_host'] ?? '', FILTER_SANITIZE_STRING);
    $user = filter_var($_POST['db_user'] ?? '', FILTER_SANITIZE_STRING);
    $pass = $_POST['db_pass'] ?? '';
    $name = filter_var($_POST['db_name'] ?? '', FILTER_SANITIZE_STRING);
    
    try {
        $mysqli = new mysqli($host, $user, $pass, $name);
        
        if ($mysqli->connect_error) {
            throw new Exception($mysqli->connect_error);
        }
        
        $backupFile = BACKUP_DIR . '/' . $name . '_' . date('Y-m-d_H-i-s') . '.sql';
        
        $tables = [];
        $result = $mysqli->query("SHOW TABLES");
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
        
        $output = "-- SmartDump Database Backup\n";
        $output .= "-- Database: {$name}\n";
        $output .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        
        foreach ($tables as $table) {
            $output .= "DROP TABLE IF EXISTS `{$table}`;\n";
            
            $result = $mysqli->query("SHOW CREATE TABLE `{$table}`");
            $row = $result->fetch_array();
            $output .= $row[1] . ";\n\n";
            
            $result = $mysqli->query("SELECT * FROM `{$table}`");
            
            if ($result->num_rows > 0) {
                $output .= "INSERT INTO `{$table}` VALUES\n";
                $counter = 0;
                
                while ($row = $result->fetch_array(MYSQLI_NUM)) {
                    $counter++;
                    $output .= "(";
                    
                    foreach ($row as $key => $value) {
                        if ($value === null) {
                            $output .= "NULL";
                        } else {
                            $output .= "'" . $mysqli->real_escape_string($value) . "'";
                        }
                        
                        if ($key < count($row) - 1) {
                            $output .= ",";
                        }
                    }
                    
                    $output .= ")";
                    $output .= ($counter < $result->num_rows) ? ",\n" : ";\n\n";
                }
            }
        }
        
        $output .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        
        file_put_contents($backupFile, $output);
        $mysqli->close();
        
        jsonResponse([
            'success' => true,
            'message' => 'Backup created successfully',
            'filename' => basename($backupFile),
            'size' => formatBytes(filesize($backupFile))
        ]);
        
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => htmlspecialchars($e->getMessage())]);
    }
}

function downloadBackup() {
    $filename = basename($_GET['filename'] ?? '');
    
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.sql$/', $filename)) {
        die('Invalid filename');
    }
    
    $filepath = BACKUP_DIR . '/' . $filename;
    $realPath = realpath($filepath);
    $backupDirReal = realpath(BACKUP_DIR);
    
    if (!$realPath || strpos($realPath, $backupDirReal) !== 0 || !file_exists($filepath)) {
        die('File not found');
    }
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('X-Content-Type-Options: nosniff');
    
    readfile($filepath);
    exit;
}

function listBackups() {
    $backups = [];
    
    if (is_dir(BACKUP_DIR)) {
        $items = scandir(BACKUP_DIR);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
            
            $filepath = BACKUP_DIR . '/' . $item;
            if (is_file($filepath)) {
                $backups[] = [
                    'name' => $item,
                    'size' => formatBytes(filesize($filepath)),
                    'date' => date('Y-m-d H:i:s', filemtime($filepath))
                ];
            }
        }
    }
    
    jsonResponse(['success' => true, 'backups' => $backups]);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartDump v<?php echo VERSION; ?> - Modern SQL Import Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        
        .wizard-container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin: 30px 0;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 60px;
            right: 60px;
            height: 2px;
            background: #dee2e6;
            z-index: 0;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .step.active .step-circle {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            transform: scale(1.2);
        }
        
        .step.completed .step-circle {
            background: #28a745;
            color: white;
        }
        
        .step-label {
            font-size: 14px;
            color: #6c757d;
        }
        
        .step.active .step-label {
            color: var(--primary);
            font-weight: bold;
        }
        
        .step-content {
            display: none;
            animation: fadeIn 0.3s;
        }
        
        .step-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .file-item {
            padding: 15px;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }
        
        .file-item:hover {
            border-color: var(--primary);
            background: #f8f9fa;
            transform: translateX(3px);
        }
        
        .file-item.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(102,126,234,0.15), rgba(118,75,162,0.15));
            box-shadow: 0 2px 8px rgba(102,126,234,0.3);
        }
        
        .btn-wizard {
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .btn-primary-gradient {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            color: white;
        }
        
        .btn-primary-gradient:hover {
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            color: white;
        }
        
        #logViewer {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }
        
        #logViewer.active {
            display: block;
        }
        
        .log-entry {
            padding: 3px 0;
        }
        
        .log-entry.error { color: #f48771; }
        .log-entry.success { color: #89d185; }
        .log-entry.warning { color: #e5c07b; }
        .log-entry.info { color: #61afef; }
        
        .progress {
            height: 30px;
            border-radius: 15px;
        }
        
        .upload-zone {
            border: 3px dashed #dee2e6;
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .upload-zone:hover {
            border-color: var(--primary);
            background: rgba(102,126,234,0.05);
        }
        
        .upload-zone.dragover {
            border-color: var(--primary);
            background: rgba(102,126,234,0.1);
        }
        
        #uploadProgress {
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px solid var(--primary);
        }
        
        #uploadProgress .progress {
            height: 25px;
            border-radius: 10px;
        }
        
        #uploadProgress .progress-bar {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }
    </style>
</head>
<body>
    <div class="wizard-container">
        <div class="card mb-4">
            <div class="card-header">
                <h1 class="mb-0"><i class="bi bi-lightning-charge-fill"></i> SmartDump</h1>
                <p class="mb-0">Modern Step-by-Step SQL Import Tool v<?php echo VERSION; ?></p>
            </div>
        </div>

        <div class="alert alert-danger alert-dismissible" style="border-radius: 15px;">
            <strong><i class="bi bi-shield-exclamation"></i> Security:</strong>
            DELETE THIS FILE AFTER USE!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>

        <div class="card">
            <div class="card-body p-4">
                <div class="step-indicator">
                    <div class="step active" data-step="1">
                        <div class="step-circle">1</div>
                        <div class="step-label">Upload</div>
                    </div>
                    <div class="step" data-step="2">
                        <div class="step-circle">2</div>
                        <div class="step-label">Database</div>
                    </div>
                    <div class="step" data-step="3">
                        <div class="step-circle">3</div>
                        <div class="step-label">Settings</div>
                    </div>
                    <div class="step" data-step="4">
                        <div class="step-circle">4</div>
                        <div class="step-label">Import</div>
                    </div>
                </div>

                <div class="step-content active" id="step1">
                    <h4 class="mb-4"><i class="bi bi-file-earmark-arrow-up"></i> Step 1: Select SQL File</h4>
                    
                    <ul class="nav nav-pills mb-3" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-bs-toggle="pill" href="#uploadTab">Browser Upload</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="pill" href="#ftpTab">FTP Upload</a>
                        </li>
                    </ul>
                    
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="uploadTab">
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle"></i> 
                                <strong>Upload Tips:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>For files over 100MB, use the <strong>FTP Upload</strong> tab</li>
                                    <li>Progress bar will show upload status</li>
                                    <li>Supported formats: .sql, .gz</li>
                                </ul>
                            </div>
                            
                            <div class="upload-zone mb-3" id="uploadZone">
                                <i class="bi bi-cloud-upload" style="font-size: 48px; color: var(--primary);"></i>
                                <h5>Drop SQL file here or click to browse</h5>
                                <p class="text-muted mb-0">Supports .sql and .gz files</p>
                                <input type="file" id="fileInput" accept=".sql,.gz" style="display: none;">
                            </div>
                            
                            <div id="uploadedFiles"></div>
                        </div>
                        
                        <div class="tab-pane fade" id="ftpTab">
                            <div class="alert alert-info">
                                <strong><i class="bi bi-info-circle"></i> For Large Files:</strong>
                                <ol class="mb-0 mt-2">
                                    <li>Upload your SQL file via FTP to: <code><?php echo basename(UPLOAD_DIR); ?>/</code></li>
                                    <li>Click "Refresh Files" below</li>
                                    <li>Select your file and proceed</li>
                                </ol>
                            </div>
                            
                            <button class="btn btn-primary w-100" onclick="loadFiles()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh Files
                            </button>
                            
                            <div id="ftpFiles" class="mt-3"></div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button class="btn btn-primary-gradient btn-wizard" onclick="goToStep(2)" id="step1Next" disabled>
                            Next: Database Settings <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <div class="step-content" id="step2">
                    <h4 class="mb-4"><i class="bi bi-database"></i> Step 2: Database Connection</h4>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Host</label>
                            <input type="text" class="form-control" id="db_host" value="localhost">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Database Name</label>
                            <input type="text" class="form-control" id="db_name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="db_user">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" id="db_pass">
                        </div>
                    </div>
                    
                    <button class="btn btn-info w-100 mb-3" onclick="testConnection()">
                        <i class="bi bi-plug"></i> Test Connection
                    </button>
                    
                    <div id="connectionStatus"></div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button class="btn btn-secondary btn-wizard" onclick="goToStep(1)">
                            <i class="bi bi-arrow-left"></i> Back
                        </button>
                        <button class="btn btn-primary-gradient btn-wizard" onclick="goToStep(3)" id="step2Next" disabled>
                            Next: Settings <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <div class="step-content" id="step3">
                    <h4 class="mb-4"><i class="bi bi-sliders"></i> Step 3: Import Settings</h4>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Charset</label>
                            <div class="input-group">
                                <select class="form-select" id="db_charset">
                                    <option value="utf8mb4">utf8mb4</option>
                                    <option value="utf8">utf8</option>
                                    <option value="latin1">latin1</option>
                                </select>
                                <button class="btn btn-outline-secondary" onclick="autoDetectCharset()" title="Auto-detect">
                                    <i class="bi bi-magic"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collation</label>
                            <select class="form-select" id="db_collation">
                                <option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci</option>
                                <option value="utf8mb4_general_ci">utf8mb4_general_ci</option>
                                <option value="utf8_general_ci">utf8_general_ci</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Max Queries/Step</label>
                            <input type="number" class="form-control" id="max_queries" value="300">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Max Time (sec)</label>
                            <input type="number" class="form-control" id="max_time" value="30">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Delay (ms)</label>
                            <input type="number" class="form-control" id="delay" value="500">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Old Prefix (optional)</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="old_prefix" placeholder="wp_">
                                <button class="btn btn-outline-secondary" onclick="autoDetectPrefix()" title="Auto-detect">
                                    <i class="bi bi-magic"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">New Prefix (optional)</label>
                            <input type="text" class="form-control" id="new_prefix" placeholder="new_">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Email Notification (optional)</label>
                            <input type="email" class="form-control" id="notification_email" placeholder="your@email.com">
                        </div>
                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="continue_on_error" checked>
                                <label class="form-check-label">
                                    <strong>Continue on errors</strong> (Recommended for WordPress/WooCommerce)
                                </label>
                                <br><small class="text-muted">Skip failed queries and continue importing</small>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="drop_database">
                                <label class="form-check-label text-danger">
                                    <strong>DROP & RECREATE DATABASE</strong> (Deletes all data!)
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button class="btn btn-secondary btn-wizard" onclick="goToStep(2)">
                            <i class="bi bi-arrow-left"></i> Back
                        </button>
                        <button class="btn btn-primary-gradient btn-wizard" onclick="goToStep(4)">
                            Start Import <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <div class="step-content" id="step4">
                    <h4 class="mb-4"><i class="bi bi-hourglass-split"></i> Step 4: Importing...</h4>
                    
                    <div class="progress mb-3">
                        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                             style="width: 0%">0%</div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-4">
                            <div class="text-center p-3" style="background: #f8f9fa; border-radius: 10px;">
                                <small class="text-muted">Queries</small>
                                <h4 id="totalQueries" class="mb-0">0</h4>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="text-center p-3" style="background: #f8f9fa; border-radius: 10px;">
                                <small class="text-muted">Failed</small>
                                <h4 id="failedQueries" class="mb-0 text-danger">0</h4>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="text-center p-3" style="background: #f8f9fa; border-radius: 10px;">
                                <small class="text-muted">Processed</small>
                                <h5 id="dataProcessed" class="mb-0">0</h5>
                            </div>
                        </div>
                    </div>
                    
                    <div id="logViewer"></div>
                    
                    <div class="text-center mt-4" id="importControls">
                        <button class="btn btn-danger btn-wizard" onclick="stopImport()">
                            <i class="bi bi-stop-circle"></i> Stop
                        </button>
                    </div>
                    
                    <div class="text-center mt-4" id="importComplete" style="display: none;">
                        <div class="alert alert-success">
                            <h5><i class="bi bi-check-circle"></i> Import Complete!</h5>
                        </div>
                        <button class="btn btn-success btn-wizard me-2" onclick="backupDatabase()">
                            <i class="bi bi-download"></i> Backup DB
                        </button>
                        <button class="btn btn-secondary btn-wizard" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise"></i> Start Over
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-body text-center">
                <p class="mb-2"><i class="bi bi-heart-fill text-danger"></i> Support SmartDump Development:</p>
                <a href="https://www.paypal.com/paypalme/workflowdone" target="_blank" class="btn btn-primary" style="background: #0070ba; border: none;">
                    <i class="bi bi-paypal"></i> Donate via PayPal
                </a>
                <p class="text-muted mt-3 mb-0 small">
                    Made with ❤️ by <a href="https://workflowdone.com" target="_blank">WorkflowDone.com</a>
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentStep = 1;
        let selectedFile = null;
        let importRunning = false;
        let currentOffset = 0;
        let currentTotalQueries = 0;
        let currentSessionId = '';
        let logUpdateInterval = null;

        function goToStep(step) {
            document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.step').forEach(el => {
                el.classList.remove('active');
                if (parseInt(el.dataset.step) < step) {
                    el.classList.add('completed');
                }
            });
            
            document.getElementById('step' + step).classList.add('active');
            document.querySelector(`[data-step="${step}"]`).classList.add('active');
            currentStep = step;
            
            if (step === 4 && !importRunning) {
                setTimeout(startImport, 500);
            }
        }

        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('fileInput');

        uploadZone.addEventListener('click', () => fileInput.click());

        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });

        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('dragover');
        });

        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                handleFileSelect(e.dataTransfer.files[0]);
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length) {
                handleFileSelect(e.target.files[0]);
            }
        });

        function handleFileSelect(file) {
            const formData = new FormData();
            formData.append('sql_file', file);
            
            // Show upload progress
            const progressHtml = `
                <div id="uploadProgress" class="mt-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Uploading: ${file.name}</span>
                        <span id="uploadPercent">0%</span>
                    </div>
                    <div class="progress">
                        <div id="uploadBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                             style="width: 0%"></div>
                    </div>
                    <small class="text-muted">Size: ${formatBytes(file.size)}</small>
                </div>
            `;
            document.getElementById('uploadZone').insertAdjacentHTML('afterend', progressHtml);
            
            // Create XHR for progress tracking
            const xhr = new XMLHttpRequest();
            
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    document.getElementById('uploadBar').style.width = percent + '%';
                    document.getElementById('uploadPercent').textContent = percent + '%';
                }
            });
            
            xhr.addEventListener('load', () => {
                // Remove progress bar
                const progressEl = document.getElementById('uploadProgress');
                if (progressEl) {
                    progressEl.remove();
                }
                
                if (xhr.status === 413) {
                    showStatus('File too large! Use FTP Upload tab instead.', 'danger');
                    return;
                }
                
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        showStatus('Upload complete: ' + data.filename, 'success');
                        selectedFile = data.filename;
                        document.getElementById('step1Next').disabled = false;
                        // Refresh file list
                        loadFiles();
                    } else {
                        showStatus(data.message, 'danger');
                    }
                } catch (e) {
                    console.error('Server response:', xhr.responseText);
                    showStatus('Upload failed. Check console for details.', 'danger');
                }
            });
            
            xhr.addEventListener('error', () => {
                const progressEl = document.getElementById('uploadProgress');
                if (progressEl) {
                    progressEl.remove();
                }
                showStatus('Network error during upload', 'danger');
            });
            
            xhr.addEventListener('abort', () => {
                const progressEl = document.getElementById('uploadProgress');
                if (progressEl) {
                    progressEl.remove();
                }
                showStatus('Upload cancelled', 'warning');
            });
            
            xhr.open('POST', '?action=upload');
            xhr.send(formData);
        }
        
        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        function loadFiles() {
            fetch('?action=list_files')
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON from list_files');
                    console.error('Response:', text);
                    throw new Error('Server returned invalid response. Check console.');
                }
            })
            .then(data => {
                if (!data.success) {
                    console.error('List files failed:', data.message);
                    throw new Error(data.message);
                }
                
                const uploadContainer = document.getElementById('uploadedFiles');
                const ftpContainer = document.getElementById('ftpFiles');
                
                if (!data.files || data.files.length === 0) {
                    uploadContainer.innerHTML = '<p class="text-muted text-center mt-3">No files uploaded yet</p>';
                    if (ftpContainer) {
                        ftpContainer.innerHTML = '<p class="text-muted text-center mt-3">No files found. Upload via FTP first.</p>';
                    }
                    return;
                }
                
                // Auto-select first file if none selected
                if (!selectedFile && data.files.length > 0) {
                    selectedFile = data.files[0].name;
                    document.getElementById('step1Next').disabled = false;
                }
                
                let html = '<h6 class="mt-4 mb-3"><i class="bi bi-files"></i> Available Files:</h6>';
                data.files.forEach(file => {
                    const isSelected = file.name === selectedFile;
                    html += `
                        <div class="file-item ${isSelected ? 'selected' : ''}" onclick="selectFile('${file.name}')">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="flex-grow-1">
                                    <i class="bi bi-file-earmark-text text-primary me-2"></i>
                                    <strong>${file.name}</strong>
                                    ${isSelected ? '<span class="badge bg-primary ms-2">Selected</span>' : ''}
                                    <br><small class="text-muted">${file.size} | ${file.date}</small>
                                </div>
                                <button class="btn btn-sm btn-danger" onclick="deleteFile('${file.name}', event)" title="Delete file">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
                
                uploadContainer.innerHTML = html;
                if (ftpContainer) {
                    ftpContainer.innerHTML = html;
                }
            })
            .catch(e => {
                console.error('Load files error:', e);
                const uploadContainer = document.getElementById('uploadedFiles');
                uploadContainer.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Error loading files. Check browser console (F12) for details.</div>';
            });
        }

        function selectFile(filename) {
            selectedFile = filename;
            document.getElementById('step1Next').disabled = false;
            loadFiles();
        }

        function deleteFile(filename, event) {
            event.stopPropagation();
            if (!confirm('Delete?')) return;
            
            fetch('?action=delete_file', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({filename})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (selectedFile === filename) {
                        selectedFile = null;
                        document.getElementById('step1Next').disabled = true;
                    }
                    loadFiles();
                }
            });
        }

        function testConnection() {
            const config = {
                db_host: document.getElementById('db_host').value,
                db_name: document.getElementById('db_name').value,
                db_user: document.getElementById('db_user').value,
                db_pass: document.getElementById('db_pass').value
            };
            
            const status = document.getElementById('connectionStatus');
            status.innerHTML = '<div class="alert alert-info">Testing connection...</div>';
            
            fetch('?action=test_connection', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams(config)
            })
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Server response:', text);
                    throw new Error('Invalid server response. Check console for details.');
                }
            })
            .then(data => {
                if (data.success) {
                    status.innerHTML = `<div class="alert alert-success"><i class="bi bi-check-circle"></i> ${data.message}</div>`;
                    document.getElementById('step2Next').disabled = false;
                } else {
                    status.innerHTML = `<div class="alert alert-danger"><i class="bi bi-x-circle"></i> ${data.message}</div>`;
                    document.getElementById('step2Next').disabled = true;
                }
            })
            .catch(e => {
                console.error('Test connection error:', e);
                status.innerHTML = `<div class="alert alert-danger"><i class="bi bi-x-circle"></i> ${e.message}</div>`;
                document.getElementById('step2Next').disabled = true;
            });
        }

        function autoDetectCharset() {
            if (!selectedFile) {
                showStatus('Please select a file first', 'warning');
                return;
            }
            
            showStatus('Detecting charset...', 'info');
            
            fetch('?action=detect_charset', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({filename: selectedFile})
            })
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Server response:', text);
                    throw new Error('Invalid response from server');
                }
            })
            .then(data => {
                if (data.success) {
                    document.getElementById('db_charset').value = data.charset;
                    document.getElementById('db_collation').value = data.collation;
                    showStatus(`Detected: ${data.charset} / ${data.collation}`, 'success');
                } else {
                    showStatus(data.message, 'danger');
                }
            })
            .catch(e => {
                console.error('Detect charset error:', e);
                showStatus(e.message, 'danger');
            });
        }

        function autoDetectPrefix() {
            const config = {
                db_host: document.getElementById('db_host').value,
                db_name: document.getElementById('db_name').value,
                db_user: document.getElementById('db_user').value,
                db_pass: document.getElementById('db_pass').value
            };
            
            if (!config.db_host || !config.db_name || !config.db_user) {
                showStatus('Please fill in database credentials first', 'warning');
                return;
            }
            
            showStatus('Detecting prefix...', 'info');
            
            fetch('?action=detect_prefix', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams(config)
            })
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Server response:', text);
                    throw new Error('Invalid response from server');
                }
            })
            .then(data => {
                if (data.success && data.prefix) {
                    document.getElementById('old_prefix').value = data.prefix;
                    showStatus(`Found prefix: ${data.prefix} (${data.tableCount} tables)`, 'success');
                } else if (data.success) {
                    showStatus('No common prefix found in database', 'info');
                } else {
                    showStatus(data.message, 'danger');
                }
            })
            .catch(e => {
                console.error('Detect prefix error:', e);
                showStatus(e.message, 'danger');
            });
        }

        function startImport() {
            if (importRunning) return;
            
            importRunning = true;
            currentOffset = 0;
            currentTotalQueries = 0;
            currentSessionId = 'import_' + Date.now();
            
            document.getElementById('logViewer').classList.add('active');
            document.getElementById('logViewer').innerHTML = '';
            
            logUpdateInterval = setInterval(updateLogs, 2000);
            
            executeImportStep();
        }

        function executeImportStep() {
            if (!importRunning) return;
            
            const config = {
                filename: selectedFile,
                db_host: document.getElementById('db_host').value,
                db_name: document.getElementById('db_name').value,
                db_user: document.getElementById('db_user').value,
                db_pass: document.getElementById('db_pass').value,
                db_charset: document.getElementById('db_charset').value,
                db_collation: document.getElementById('db_collation').value,
                max_queries: document.getElementById('max_queries').value,
                max_time: document.getElementById('max_time').value,
                old_prefix: document.getElementById('old_prefix').value,
                new_prefix: document.getElementById('new_prefix').value,
                drop_database: document.getElementById('drop_database').checked,
                error_mode: document.getElementById('error_mode').value,
                notification_email: document.getElementById('notification_email').value,
                offset: currentOffset,
                totalQueries: currentTotalQueries,
                session_id: currentSessionId
            };
            
            fetch('?action=import', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams(config)
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    addLog(data.message, 'error');
                    stopImport();
                    return;
                }
                
                currentOffset = data.offset;
                currentTotalQueries = data.totalQueries;
                
                document.getElementById('totalQueries').textContent = data.totalQueries;
                document.getElementById('failedQueries').textContent = data.queriesFailed || 0;
                document.getElementById('dataProcessed').textContent = data.processed;
                
                const progressBar = document.getElementById('progressBar');
                progressBar.style.width = data.progress + '%';
                progressBar.textContent = data.progress + '%';
                
                if (data.complete) {
                    addLog('Import complete!', 'success');
                    completeImport();
                } else {
                    const delay = parseInt(document.getElementById('delay').value) || 500;
                    setTimeout(executeImportStep, delay);
                }
            })
            .catch(e => {
                addLog('Error: ' + e.message, 'error');
                stopImport();
            });
        }

        function stopImport() {
            importRunning = false;
            if (logUpdateInterval) {
                clearInterval(logUpdateInterval);
            }
            addLog('Stopped', 'warning');
        }

        function completeImport() {
            importRunning = false;
            if (logUpdateInterval) {
                clearInterval(logUpdateInterval);
            }
            document.getElementById('importControls').style.display = 'none';
            document.getElementById('importComplete').style.display = 'block';
        }

        function updateLogs() {
            if (!currentSessionId) return;
            
            fetch(`?action=get_logs&session_id=${currentSessionId}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.logs) {
                    const logViewer = document.getElementById('logViewer');
                    const existingLogs = logViewer.querySelectorAll('.log-entry').length;
                    
                    if (data.logs.length > existingLogs) {
                        data.logs.slice(existingLogs).forEach(log => {
                            const match = log.match(/\[(.*?)\] \[(.*?)\] (.*)/);
                            if (match) {
                                const type = match[2].toLowerCase();
                                const logEntry = document.createElement('div');
                                logEntry.className = `log-entry ${type}`;
                                logEntry.textContent = log;
                                logViewer.appendChild(logEntry);
                            }
                        });
                        logViewer.scrollTop = logViewer.scrollHeight;
                    }
                }
            });
        }

        function addLog(message, type = 'info') {
            const logViewer = document.getElementById('logViewer');
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = document.createElement('div');
            logEntry.className = `log-entry ${type}`;
            logEntry.textContent = `[${timestamp}] [${type.toUpperCase()}] ${message}`;
            logViewer.appendChild(logEntry);
            logViewer.scrollTop = logViewer.scrollHeight;
        }

        function backupDatabase() {
            const config = {
                db_host: document.getElementById('db_host').value,
                db_name: document.getElementById('db_name').value,
                db_user: document.getElementById('db_user').value,
                db_pass: document.getElementById('db_pass').value
            };
            
            showStatus('Creating backup...', 'info');
            
            fetch('?action=backup_database', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams(config)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showStatus('Backup created!', 'success');
                    if (confirm('Download?')) {
                        window.location.href = `?action=download_backup&filename=${data.filename}`;
                    }
                } else {
                    showStatus(data.message, 'danger');
                }
            });
        }

        function showStatus(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; border-radius: 10px;';
            alertDiv.innerHTML = message;
            document.body.appendChild(alertDiv);
            setTimeout(() => alertDiv.remove(), 3000);
        }

        loadFiles();
    </script>
</body>
</html>