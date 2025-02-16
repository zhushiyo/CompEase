<?php
declare(strict_types=1);

// 错误处理
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// CORS 和响应头设置
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 配置
final class Config {
    private static $settings = null;
    private static $initialized = false;
    private static $defaultSettings = [
        'db_file' => __DIR__ . '/../database/complaints.db',
        'upload_path' => __DIR__ . '/../storage/uploads/',
        'jwt_secret' => 'YourVeryLongAndSecureRandomString123!@#',
        'admin_username' => 'admin',
        'admin_password' => 'admin123',
        'websocket_enabled' => 'false',
        'websocket_port' => '9502'
    ];
    
    public static function get(string $key, $default = null) {
        if (!self::$initialized) {
            self::loadSettings();
        }
        return self::$settings[$key] ?? self::$defaultSettings[$key] ?? $default;
    }
    
    private static function loadSettings() {
        if (self::$initialized) return;
        
        self::$settings = [];
        self::$initialized = true;
        
        $db = Database::getInstance();
        
        // 使用简单查询，避免复杂的预处理语句
        $result = Database::query('SELECT key, value FROM settings');
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            self::$settings[$row['key']] = $row['value'];
        }
    }
}

// 数据库类
final class Database {
    private static ?SQLite3 $instance = null;
    private static $initialized = false;
    private static $DB_PATH = __DIR__ . '/../database/complaints.db';
    private static $DB_DIR = __DIR__ . '/../database';
    private static $STORAGE_DIR = __DIR__ . '/../storage/uploads';
    private static $lockFile = null;
    private static $lockHandle = null;

    private static function acquireLock() {
        // 确保数据库目录存在
        if (!file_exists(self::$DB_DIR)) {
            if (!mkdir(self::$DB_DIR, 0777, true)) {
                throw new Exception('无法创建数据库目录');
            }
        }

        self::$lockFile = self::$DB_DIR . '/db.lock';
        touch(self::$lockFile); // 确保锁文件存在
        chmod(self::$lockFile, 0666); // 设置适当的权限

        self::$lockHandle = fopen(self::$lockFile, 'c');
        if (!self::$lockHandle) {
            throw new Exception('无法创建锁文件');
        }

        if (!flock(self::$lockHandle, LOCK_EX)) {
            throw new Exception('无法获取数据库锁');
        }
    }

    public static function getInstance(): SQLite3 {
        if (self::$instance === null) {
            self::acquireLock();

            if (!file_exists(self::$STORAGE_DIR)) {
                if (!mkdir(self::$STORAGE_DIR, 0777, true)) {
                    throw new Exception('无法创建上传目录');
                }
                chmod(self::$STORAGE_DIR, 0777);
            }

            // 使用默认路径，避免循环依赖
            self::$instance = new SQLite3(self::$DB_PATH);
            chmod(self::$DB_PATH, 0666);
            self::$instance->enableExceptions(true);
            
            // 优化数据库性能和并发访问
            self::$instance->exec('PRAGMA journal_mode = WAL');
            self::$instance->exec('PRAGMA synchronous = NORMAL');
            self::$instance->exec('PRAGMA cache_size = -4000');
            self::$instance->exec('PRAGMA temp_store = MEMORY');
            self::$instance->exec('PRAGMA busy_timeout = 5000');
            self::$instance->exec('PRAGMA locking_mode = NORMAL');
            self::$instance->exec('PRAGMA page_size = 4096');
            self::$instance->exec('PRAGMA foreign_keys = ON');
            
            // 只初始化一次
            if (!self::$initialized) {
                self::initTable();
                self::$initialized = true;
            }
        }
        return self::$instance;
    }

    public static function query($sql) {
        return self::$instance->query($sql);
    }

    public static function prepare($sql) {
        return self::$instance->prepare($sql);
    }

    public static function exec($sql) {
        return self::$instance->exec($sql);
    }

    private static function initTable(): void {
        try {
            // 开启事务前先设置超时时间
            self::$instance->exec('PRAGMA busy_timeout = 5000');
            
            // 开启事务
            self::$instance->exec('BEGIN IMMEDIATE TRANSACTION');
            
            // 系统配置表
            self::$instance->exec('CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )');

            // 分批插入默认配置
            $defaultSettings = [
                ['upload_path', '../storage/uploads/'],
                ['db_file', '../database/complaints.db'],
                ['jwt_secret', 'YourVeryLongAndSecureRandomString123!@#'],
                ['admin_username', 'admin'],
                ['admin_password', 'admin123'],
                ['max_file_size', '20971520'],
                ['allowed_extensions', 'jpg,jpeg,png,pdf,doc,docx'],
                ['min_description_length', '30'],
                ['complaint_id_prefix', 'TS'],
                ['websocket_enabled', 'false'],
                ['websocket_port', '9502'],
                ['system_name', '投诉建议系统'],
                ['system_description', '欢迎使用投诉建议系统'],
                ['enable_attachments', 'true'],
                ['enable_replies', 'true'],
                ['enable_categories', 'true'],
                ['enable_businesses', 'true'],
                ['enable_contact_info', 'true'],
                ['contact_info_required', 'true'],
                ['max_title_length', '100'],
                ['max_description_length', '2000'],
                ['max_attachments', '5'],
                ['notification_email', ''],
                ['notification_enabled', 'false']
            ];
            
            $stmt = self::$instance->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
            foreach ($defaultSettings as $setting) {
                $stmt->bindValue(1, $setting[0]);
                $stmt->bindValue(2, $setting[1]);
                $stmt->execute();
            }

            // 业务分类表
            self::$instance->exec('CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                sort_order INTEGER DEFAULT 0,
                is_enabled BOOLEAN DEFAULT 1
            )');

            // 业务表
            self::$instance->exec('CREATE TABLE IF NOT EXISTS businesses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER,
                name TEXT NOT NULL,
                description TEXT,
                fields_config TEXT,
                sort_order INTEGER DEFAULT 0,
                is_enabled BOOLEAN DEFAULT 1,
                contact_info TEXT,
                FOREIGN KEY(category_id) REFERENCES categories(id)
            )');

            // 投诉表
            self::$instance->exec('CREATE TABLE IF NOT EXISTS complaints (
                id TEXT PRIMARY KEY,
                business_id INTEGER,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                contact TEXT,
                custom_fields TEXT,
                status TEXT DEFAULT "pending",
                priority TEXT DEFAULT "normal",
                attachments TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME,
                FOREIGN KEY(business_id) REFERENCES businesses(id)
            )');

            // 回复表
            self::$instance->exec('CREATE TABLE IF NOT EXISTS replies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                complaint_id TEXT NOT NULL,
                content TEXT NOT NULL,
                is_private BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by TEXT,
                attachments TEXT,
                FOREIGN KEY(complaint_id) REFERENCES complaints(id)
            )');

            // 状态配置表
            self::$instance->exec('CREATE TABLE IF NOT EXISTS status_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                color TEXT,
                sort_order INTEGER DEFAULT 0,
                is_enabled BOOLEAN DEFAULT 1
            )');

            // 初始化默认状态
            self::$instance->exec("INSERT OR IGNORE INTO status_config (name, description, color) VALUES 
                ('pending', '待处理', 'warning'),
                ('processing', '处理中', 'info'),
                ('resolved', '已解决', 'success'),
                ('rejected', '已驳回', 'danger')
            ");

            // 检查并添加列
            self::checkAndAddColumns();

            // 提交事务
            self::$instance->exec('COMMIT');
            
        } catch (Exception $e) {
            // 回滚事务
            try {
                self::$instance->exec('ROLLBACK');
            } catch (Exception $rollbackError) {
                error_log("Rollback failed: " . $rollbackError->getMessage());
            }
            throw $e;
        }
    }

    // 新增方法：检查并添加列
    private static function checkAndAddColumns(): void {
        try {
            // 检查 businesses 表中是否已存在 fields_config 列
            $result = self::$instance->query("PRAGMA table_info(businesses)");
            $hasFieldsConfig = false;
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['name'] === 'fields_config') {
                    $hasFieldsConfig = true;
                    break;
                }
            }
            
            // 如果列不存在，则添加
            if (!$hasFieldsConfig) {
                try {
                    self::$instance->exec('BEGIN IMMEDIATE TRANSACTION');
                    self::$instance->exec('ALTER TABLE businesses ADD COLUMN fields_config TEXT');
                    self::$instance->exec('COMMIT');
                } catch (Exception $e) {
                    self::$instance->exec('ROLLBACK');
                    error_log("Error adding fields_config column: " . $e->getMessage());
                }
            }

            // 检查 complaints 表中是否已存在 custom_fields 列
            $result = self::$instance->query("PRAGMA table_info(complaints)");
            $hasCustomFields = false;
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['name'] === 'custom_fields') {
                    $hasCustomFields = true;
                    break;
                }
            }
            
            // 如果列不存在，则添加
            if (!$hasCustomFields) {
                try {
                    self::$instance->exec('BEGIN IMMEDIATE TRANSACTION');
                    self::$instance->exec('ALTER TABLE complaints ADD COLUMN custom_fields TEXT');
                    self::$instance->exec('COMMIT');
                } catch (Exception $e) {
                    self::$instance->exec('ROLLBACK');
                    error_log("Error adding custom_fields column: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("Error checking and adding columns: " . $e->getMessage());
        }
    }

    public static function close(): void {
        if (self::$instance !== null) {
            try {
                // 等待所有事务完成
                self::$instance->exec('PRAGMA busy_timeout = 5000');
                
                // 尝试检查点
                try {
                    self::$instance->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                } catch (Exception $e) {
                    error_log("Checkpoint failed: " . $e->getMessage());
                }

                // 确保所有锁都被释放
                try {
                    self::$instance->exec('COMMIT');
                } catch (Exception $e) {
                    // 忽略可能的"no transaction is active"错误
                    error_log("Commit failed: " . $e->getMessage());
                }

                try {
                    self::$instance->exec('ROLLBACK');
                } catch (Exception $e) {
                    // 忽略可能的"no transaction is active"错误
                    error_log("Rollback failed: " . $e->getMessage());
                }

                // 关闭数据库连接
                try {
                    self::$instance->close();
                } catch (Exception $e) {
                    error_log("Close failed: " . $e->getMessage());
                }

                self::$instance = null;
                
                // 释放文件锁
                self::releaseLock();
            } catch (Exception $e) {
                error_log("Database close error: " . $e->getMessage());
                // 确保即使出错也释放文件锁
                self::releaseLock();
            }
        }
    }

    private static function releaseLock(): void {
        try {
            if (self::$lockHandle) {
                flock(self::$lockHandle, LOCK_UN);
                fclose(self::$lockHandle);
                self::$lockHandle = null;
            }
        } catch (Exception $e) {
            error_log("Lock release error: " . $e->getMessage());
        }
    }

    public static function lastInsertId(): int {
        return self::$instance->lastInsertRowID();
    }
}

// JWT 处理类
final class JWT {
    public static function generate(string $username): string {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode([
            'username' => $username,
            'exp' => time() + 3600
        ]));
        $signature = base64_encode(hash_hmac('sha256', "$header.$payload", Config::get('jwt_secret'), true));
        
        return "$header.$payload.$signature";
    }

    public static function verify(?string $token): bool {
        if (empty($token)) return false;
        
        $parts = explode('.', $token);
        if (count($parts) !== 3) return false;
        
        [$header, $payload, $signature] = $parts;
        $valid_signature = base64_encode(hash_hmac('sha256', "$header.$payload", Config::get('jwt_secret'), true));
        
        if ($signature !== $valid_signature) return false;
        
        $payload_data = json_decode(base64_decode($payload), true);
        return ($payload_data['exp'] ?? 0) > time();
    }
}

// 文件处理类
final class FileHandler {
    public static function handleUpload(array $files): array {
        if (empty($files['attachments'])) {
            return [];
        }

        // 确保上传目录存在
        $upload_path = Config::get('upload_path');
        if (!is_dir($upload_path)) {
            mkdir($upload_path, 0777, true);
        }

        $attachments = [];
        foreach ($files as $key => $file) {
            if (!is_array($file['tmp_name'])) continue;
            
            foreach ($file['tmp_name'] as $index => $tmp_name) {
                if (empty($tmp_name)) continue;
                
                $filename = $file['name'][$index];
                $filesize = $file['size'][$index];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                // 验证文件
                $allowed_extensions = explode(',', Config::get('allowed_extensions'));
                if (!in_array($ext, $allowed_extensions)) {
                    continue;
                }
                if ($filesize > Config::get('max_file_size')) {
                    continue;
                }

                $new_filename = uniqid() . '.' . $ext;
                if (move_uploaded_file($tmp_name, $upload_path . $new_filename)) {
                    $attachments[] = $new_filename;
                }
            }
        }
        return $attachments;
    }

    public static function deleteFiles(array $filenames): void {
        foreach ($filenames as $filename) {
            $filepath = Config::get('upload_path') . $filename;
            if (is_file($filepath)) {
                @unlink($filepath);
            }
        }
    }
}

// 响应处理
function sendResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

// 获取认证token
$headers = getallheaders();
$token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : '';

// 路由处理
try {
    // 初始化数据库
    if (!Database::getInstance()) {
        throw new Exception('数据库初始化失败');
    }

    // 获取请求路径和方法
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];

    // 生成投诉编号
    function generateComplaintId(): string {
        $timestamp = date('YmdHis');
        $random = str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
        return Config::get('complaint_id_prefix') . $timestamp . $random;
    }

    switch (true) {
        // 管理员登录
        case $method === 'POST' && $path === '/api/admin/login':
            $data = json_decode(file_get_contents('php://input'), true);
            if ($data['username'] === Config::get('admin_username') && $data['password'] === Config::get('admin_password')) {
                sendResponse(['token' => JWT::generate($data['username'])]);
            } else {
                sendResponse(['error' => '用户名或密码错误'], 401);
            }
            break;

        // 提交投诉
        case $method === 'POST' && $path === '/api/complaints':
            try {
                // 验证描述长度
                if (mb_strlen($_POST['description'] ?? '') < Config::get('min_description_length')) {
                    sendResponse(['error' => '详细描述不能少于' . Config::get('min_description_length') . '个字'], 400);
                    break;
                }

                $attachments = FileHandler::handleUpload($_FILES);
                $complaintId = generateComplaintId();
                
                $stmt = Database::prepare('INSERT INTO complaints (id, business_id, title, description, contact, custom_fields, attachments) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bindValue(1, $complaintId);
                $stmt->bindValue(2, $_POST['business_id'] ?? null);
                $stmt->bindValue(3, $_POST['title'] ?? '');
                $stmt->bindValue(4, $_POST['description'] ?? '');
                $stmt->bindValue(5, $_POST['contact'] ?? '');
                $stmt->bindValue(6, $_POST['custom_fields'] ?? '{}'); // 保存自定义字段的值
                $stmt->bindValue(7, json_encode($attachments));
                
                $stmt->execute();
                sendResponse([
                    'message' => '投诉提交成功',
                    'complaintId' => $complaintId
                ]);
            } catch (Exception $e) {
                throw $e;
            }
            break;

        // 获取投诉列表
        case $method === 'GET' && $path === '/api/admin/complaints':
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            
            // 添加分页和限制字段
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = ($page - 1) * $limit;
            
            $result = Database::query("
                SELECT c.*, b.name as business_name
                FROM complaints c
                LEFT JOIN businesses b ON c.business_id = b.id
                ORDER BY c.created_at DESC
                LIMIT $limit OFFSET $offset
            ");
            $complaints = [];
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                // 解码附件
                $row['attachments'] = json_decode($row['attachments'] ?: '[]', true);
                $complaints[] = $row;
            }
            
            sendResponse($complaints);
            break;

        // 更新投诉
        case $method === 'PUT' && preg_match('/^\/api\/admin\/complaints\/([A-Za-z0-9]+)$/', $path, $matches):
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            
            $id = $matches[1];
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = Database::prepare('UPDATE complaints SET status = ? WHERE id = ?');
            $stmt->bindValue(1, $data['status']);
            $stmt->bindValue(2, $id);
            $stmt->execute();
            
            sendResponse(['message' => '更新成功']);
            break;

        // 删除投诉
        case $method === 'DELETE' && preg_match('/^\/api\/admin\/complaints\/([A-Za-z0-9]+)$/', $path, $matches):
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            
            $id = $matches[1];
            $stmt = Database::prepare('SELECT attachments FROM complaints WHERE id = ?');
            $stmt->bindValue(1, $id);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                FileHandler::deleteFiles(json_decode($row['attachments'], true));
                
                $stmt = Database::prepare('DELETE FROM complaints WHERE id = ?');
                $stmt->bindValue(1, $id);
                $stmt->execute();
                
                sendResponse(['message' => '删除成功']);
            } else {
                sendResponse(['error' => '投诉不存在'], 404);
            }
            break;

        // 获取单个投诉详情（管理员）
        case $method === 'GET' && preg_match('/^\/api\/complaints\/([A-Za-z0-9]+)$/', $path, $matches):
            $id = $matches[1];
            // 如果没有前缀，添加前缀
            if (strpos($id, Config::get('complaint_id_prefix')) !== 0) {
                $id = Config::get('complaint_id_prefix') . $id;
            }

            // 修改 SQL 查询，加入业务和自定义字段信息
            $stmt = Database::prepare('
                SELECT c.*, 
                       b.name as business_name, 
                       b.fields_config,
                       cat.name as category_name,
                       (SELECT json_group_array(
                           json_object(
                               \'id\', r.id,
                               \'content\', r.content,
                               \'created_at\', r.created_at,
                               \'is_private\', r.is_private,
                               \'created_by\', r.created_by
                           )
                       )
                       FROM replies r 
                       WHERE r.complaint_id = c.id) as replies
                FROM complaints c
                LEFT JOIN businesses b ON c.business_id = b.id
                LEFT JOIN categories cat ON b.category_id = cat.id
                WHERE c.id = ?
            ');
            $stmt->bindValue(1, $id);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $row['replies'] = json_decode($row['replies'] ?: '[]', true);
                $row['custom_fields'] = json_decode($row['custom_fields'] ?: '{}', true);
                $row['fields_config'] = json_decode($row['fields_config'] ?: '[]', true);
                $row['attachments'] = json_decode($row['attachments'] ?: '[]', true);
                sendResponse($row);
            } else {
                sendResponse(['error' => '投诉不存在'], 404);
            }
            break;

        // 获取系统配置
        case $method === 'GET' && $path === '/api/admin/settings':
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $result = Database::query('SELECT * FROM settings');
            $settings = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $settings[$row['key']] = $row['value'];
            }
            sendResponse($settings);
            break;

        // 更新系统配置
        case $method === 'PUT' && $path === '/api/admin/settings':
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            foreach ($data as $key => $value) {
                // 确保布尔值被正确存储
                if ($value === 'true' || $value === 'false') {
                    $value = $value === 'true' ? 'true' : 'false';
                }
                $stmt = Database::prepare('UPDATE settings SET value = ? WHERE key = ?');
                $stmt->bindValue(1, $value);
                $stmt->bindValue(2, $key);
                $stmt->execute();
            }
            sendResponse(['message' => '配置更新成功']);
            break;

        // 业务分类管理
        case $method === 'GET' && $path === '/api/admin/categories':
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $result = Database::query('SELECT * FROM categories');
            $categories = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $categories[] = $row;
            }
            sendResponse($categories);
            break;

        // 添加回复
        case $method === 'POST' && preg_match('/^\/api\/admin\/complaints\/([A-Za-z0-9]+)\/replies$/', $path, $matches):
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            
            $complaint_id = $matches[1];
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = Database::prepare('INSERT INTO replies (complaint_id, content) VALUES (?, ?)');
            $stmt->bindValue(1, $complaint_id);
            $stmt->bindValue(2, $data['content']);
            $stmt->execute();
            
            sendResponse(['message' => '回复成功']);
            break;

        // 获取业务列表
        case $method === 'GET' && $path === '/api/businesses':
            // 这个接口不需要验证，因为前端提交投诉时需要获取业务列表
            $result = Database::query('SELECT * FROM businesses WHERE is_enabled = 1 ORDER BY category_id, sort_order, id');
            $businesses = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $businesses[] = $row;
            }
            sendResponse($businesses);
            break;

        // 获取业务列表
        case $method === 'GET' && $path === '/api/admin/businesses':
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $result = Database::query('SELECT * FROM businesses ORDER BY sort_order, id');
            $businesses = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $businesses[] = $row;
            }
            sendResponse($businesses);
            break;

        // 添加业务
        case $method === 'POST' && $path === '/api/admin/businesses':
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            
            // 验证数据
            if (empty($data['name'])) {
                sendResponse(['error' => '业务名称不能为空'], 400);
                break;
            }
            
            if (empty($data['category_id'])) {
                sendResponse(['error' => '请选择所属分类'], 400);
                break;
            }
            
            $stmt = Database::prepare('INSERT INTO businesses (category_id, name, description, fields_config, is_enabled) VALUES (?, ?, ?, ?, ?)');
            $stmt->bindValue(1, $data['category_id']);
            $stmt->bindValue(2, $data['name']);
            $stmt->bindValue(3, $data['description'] ?? '');
            $stmt->bindValue(4, json_encode($data['fields_config'] ?? []));
            $stmt->bindValue(5, $data['is_enabled'] ? 1 : 0);
            $stmt->execute();
            
            sendResponse(['message' => '添加成功', 'id' => Database::lastInsertId()]);
            break;

        // 更新业务
        case $method === 'PUT' && preg_match('/^\/api\/admin\/businesses\/(\d+)$/', $path, $matches):
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $id = $matches[1];
            $data = json_decode(file_get_contents('php://input'), true);
            
            // 验证数据
            if (empty($data['name'])) {
                sendResponse(['error' => '业务名称不能为空'], 400);
                break;
            }
            
            if (empty($data['category_id'])) {
                sendResponse(['error' => '请选择所属分类'], 400);
                break;
            }
            
            $stmt = Database::prepare('UPDATE businesses SET category_id = ?, name = ?, description = ?, fields_config = ?, is_enabled = ? WHERE id = ?');
            $stmt->bindValue(1, $data['category_id']);
            $stmt->bindValue(2, $data['name']);
            $stmt->bindValue(3, $data['description'] ?? '');
            $stmt->bindValue(4, json_encode($data['fields_config'] ?? [])); // 保存自定义字段配置
            $stmt->bindValue(5, $data['is_enabled'] ? 1 : 0);
            $stmt->bindValue(6, $id);
            $stmt->execute();
            
            sendResponse(['message' => '更新成功']);
            break;

        // 删除业务
        case $method === 'DELETE' && preg_match('/^\/api\/admin\/businesses\/(\d+)$/', $path, $matches):
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $id = $matches[1];
            $stmt = Database::prepare('DELETE FROM businesses WHERE id = ?');
            $stmt->bindValue(1, $id);
            $stmt->execute();
            sendResponse(['message' => '删除成功']);
            break;

        // 获取分类列表
        case $method === 'GET' && $path === '/api/admin/categories':
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $result = Database::query('SELECT * FROM categories ORDER BY sort_order, id');
            $categories = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $categories[] = $row;
            }
            sendResponse($categories);
            break;

        // 添加分类
        case $method === 'POST' && $path === '/api/admin/categories':
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            
            // 验证数据
            if (empty($data['name'])) {
                sendResponse(['error' => '分类名称不能为空'], 400);
                break;
            }
            
            $stmt = Database::prepare('INSERT INTO categories (name, description, is_enabled, sort_order) VALUES (?, ?, ?, ?)');
            $stmt->bindValue(1, $data['name']);
            $stmt->bindValue(2, $data['description'] ?? '');
            $stmt->bindValue(3, $data['is_enabled'] ? 1 : 0);
            $stmt->bindValue(4, $data['sort_order'] ?? 0);
            $stmt->execute();
            
            sendResponse(['message' => '添加成功', 'id' => Database::lastInsertId()]);
            break;

        // 更新分类
        case $method === 'PUT' && preg_match('/^\/api\/admin\/categories\/(\d+)$/', $path, $matches):
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $id = $matches[1];
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = Database::prepare('UPDATE categories SET name = ?, description = ?, is_enabled = ? WHERE id = ?');
            $stmt->bindValue(1, $data['name']);
            $stmt->bindValue(2, $data['description'] ?? '');
            $stmt->bindValue(3, $data['is_enabled'] ? 1 : 0);
            $stmt->bindValue(4, $id);
            $stmt->execute();
            sendResponse(['message' => '更新成功']);
            break;

        // 删除分类
        case $method === 'DELETE' && preg_match('/^\/api\/admin\/categories\/(\d+)$/', $path, $matches):
            if (!JWT::verify($token)) {
                sendResponse(['error' => '未授权'], 401);
                break;
            }
            $id = $matches[1];
            // 检查是否有关联的业务
            $stmt = Database::prepare('SELECT COUNT(*) as count FROM businesses WHERE category_id = ?');
            $stmt->bindValue(1, $id);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            if ($row['count'] > 0) {
                sendResponse(['error' => '该分类下还有业务，无法删除'], 400);
                break;
            }
            $stmt = Database::prepare('DELETE FROM categories WHERE id = ?');
            $stmt->bindValue(1, $id);
            $stmt->execute();
            sendResponse(['message' => '删除成功']);
            break;

        // 获取分类列表
        case $method === 'GET' && $path === '/api/categories':
            // 这个接口不需要验证，因为前端提交投诉时也需要获取分类列表
            $result = Database::query('SELECT * FROM categories WHERE is_enabled = 1 ORDER BY sort_order, id');
            $categories = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $categories[] = $row;
            }
            sendResponse($categories);
            break;

        // 添加普通用户回复接口
        case $method === 'POST' && preg_match('/^\/api\/complaints\/([A-Za-z0-9]+)\/replies$/', $path, $matches):
            $complaint_id = $matches[1];
            $data = json_decode(file_get_contents('php://input'), true);
            
            // 验证数据
            if (empty($data['content'])) {
                sendResponse(['error' => '回复内容不能为空'], 400);
                break;
            }
            
            $stmt = Database::prepare('INSERT INTO replies (complaint_id, content, is_private, created_by) VALUES (?, ?, ?, ?)');
            $stmt->bindValue(1, $complaint_id);
            $stmt->bindValue(2, $data['content']);
            $stmt->bindValue(3, 0); // 普通用户的回复永远是公开的
            $stmt->bindValue(4, '用户'); // 标记为用户回复
            $stmt->execute();
            
            sendResponse(['message' => '回复成功']);
            break;

        default:
            sendResponse(['error' => '接口不存在'], 404);
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => '服务器错误',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} finally {
    Database::close();
} 