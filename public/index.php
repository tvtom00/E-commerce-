<?php
declare(strict_types=1);

const MAX_REQUEST_BYTES = 12000;
const MAX_OUTPUT_FILES = 100;
const MAX_FILE_BYTES = 120000;
const MAX_TOTAL_BYTES = 3000000;
$GLOBALS['NIRA_SSE_MODE'] = false;

function progress_event(string $event, array $data): void {
    if (empty($GLOBALS['NIRA_SSE_MODE'])) return;
    echo 'event: '.$event."\n".'data: '.json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";
    if (function_exists('ob_flush')) @ob_flush();
    flush();
}

function respond(int $status, array $data): never {
    http_response_code($status);
    if (!empty($GLOBALS['NIRA_SSE_MODE'])) {
        progress_event('error', ['status'=>$status] + $data);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}
function envv(string $key, string $default = ''): string {
    $v = getenv($key);
    return $v === false ? $default : trim($v);
}
function clean_name(string $name): string {
    $name = trim(str_replace('\\', '/', $name));
    if ($name === '' || str_starts_with($name, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $name)) {
        throw new RuntimeException('Invalid generated file path.');
    }
    if (!preg_match('/^[a-zA-Z0-9._\/-]+$/', $name)) throw new RuntimeException('Unsupported file path.');
    return $name;
}

function validate_laravel_project(array $rawFiles): array {
    $required = [
        'Dockerfile','docker/entrypoint.sh','composer.json','artisan','bootstrap/app.php','public/index.php','public/.htaccess',
        'routes/web.php','routes/auth.php','README.md','.env.example',
        '.github/workflows/laravel-validate.yml',
        'config/auth.php','config/database.php','config/services.php',
        'resources/views/layouts/app.blade.php','resources/views/store/index.blade.php',
        'resources/views/store/products/show.blade.php','resources/views/cart/index.blade.php',
        'resources/views/checkout/index.blade.php','resources/views/admin/dashboard.blade.php',
        'resources/views/admin/products/index.blade.php','resources/views/admin/products/create.blade.php',
        'resources/views/admin/orders/index.blade.php','resources/views/admin/categories/index.blade.php',
        'resources/views/admin/inventory/index.blade.php','resources/views/admin/subadmins/index.blade.php',
        'resources/views/admin/customers/index.blade.php','resources/views/admin/settings/index.blade.php',
        'resources/views/admin/settings/payments.blade.php','resources/views/components/product-chat.blade.php',
        'resources/css/app.css','resources/js/app.js',
        'app/Models/Product.php','app/Models/Category.php','app/Models/Order.php',
        'app/Models/Setting.php','app/Models/Role.php',
        'app/Http/Controllers/StoreController.php','app/Http/Controllers/CartController.php',
        'app/Http/Controllers/CheckoutController.php',
        'app/Http/Controllers/Admin/ProductController.php',
        'app/Http/Controllers/Admin/OrderController.php',
        'app/Http/Controllers/Admin/SettingsController.php',
        'app/Http/Controllers/Admin/CategoryController.php',
        'app/Http/Controllers/Admin/InventoryController.php',
        'app/Http/Controllers/Admin/SubAdminController.php',
        'app/Http/Controllers/Auth/GoogleController.php',
        'app/Http/Controllers/Chat/ProductChatController.php',
        'app/Http/Controllers/PaymentWebhookController.php',
        'app/Http/Middleware/EnsureRole.php','app/Policies/OrderPolicy.php',
        'app/Http/Requests/StoreProductRequest.php',
        'app/Services/Payments/PaymentGateway.php',
        'app/Services/Payments/ConfiguredGateway.php',
        'app/Services/TelegramNotifier.php','app/Services/ProductAssistant.php',
        'tests/Feature/StorefrontTest.php','tests/Feature/AdminAuthorizationTest.php',
        'tests/Feature/ProductAssistantTest.php','tests/Feature/PaymentWebhookTest.php',
        'tests/Feature/GoogleOAuthTest.php','tests/Feature/TelegramSettingsTest.php',
        'tests/Feature/SubAdminAuthorizationTest.php','tests/Feature/CheckoutTest.php',
        'tests/Feature/PaymentGatewayTest.php','tests/Feature/CategoryInventoryTest.php',
    ];
    if (count($rawFiles) < count($required) || count($rawFiles) > MAX_OUTPUT_FILES) {
        throw new RuntimeException('Laravel E-commerce project is incomplete or exceeds the file limit.');
    }
    $files = [];
    $totalBytes = 0;
    foreach ($rawFiles as $file) {
        if (!is_array($file) || !isset($file['path'], $file['content']) || !is_string($file['path']) || !is_string($file['content'])) {
            throw new RuntimeException('Every generated file must contain a text path and content.');
        }
        $path = clean_name($file['path']);
        $parts = explode('/', $path);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)
            || in_array('vendor', $parts, true) || in_array('node_modules', $parts, true)
            || in_array('.git', $parts, true) || in_array('storage', $parts, true)) {
            throw new RuntimeException('Generated project contains an unsafe path.');
        }
        $base = strtolower((string)end($parts));
        if (($base === '.env' || str_starts_with($base, '.env.')) && $base !== '.env.example') {
            throw new RuntimeException('Real environment files and credentials are not allowed in the ZIP.');
        }
        if (isset($files[$path])) throw new RuntimeException('Generated project contains a duplicate path.');
        $fileBytes = strlen($file['content']);
        if ($fileBytes > MAX_FILE_BYTES) throw new RuntimeException('A generated file exceeded the 120 KB size limit.');
        $totalBytes += $fileBytes;
        if ($totalBytes > MAX_TOTAL_BYTES) throw new RuntimeException('Generated project exceeded the 3 MB total source limit.');
        if (!preg_match('/\\.(php|blade\\.php|css|js|json|md|ya?ml|xml|txt|htaccess|sh)$/i', $path)
            && !in_array($base, ['artisan','dockerfile','.gitignore','.env.example'], true)) {
            throw new RuntimeException('Generated project contains an unsupported or binary file.');
        }
        if (preg_match('/(?:AIza[0-9A-Za-z_-]{30,}|gh[pousr]_[A-Za-z0-9_]{30,}|GOCSPX-[A-Za-z0-9_-]{20,}|[0-9]{8,}:[A-Za-z0-9_-]{25,}|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----)/', $file['content'])) {
            throw new RuntimeException('A possible secret was found in generated source. Use environment variables instead.');
        }
        $files[$path] = $file['content'];
    }
    foreach ($required as $path) {
        if (!array_key_exists($path, $files)) throw new RuntimeException('Laravel E-commerce output is missing required file: '.$path);
    }
    $dockerfile = $files['Dockerfile'];
    foreach (['FROM php:', 'pdo_mysql', 'pdo_pgsql', 'composer install', 'EXPOSE 10000', 'docker/entrypoint.sh', 'nira-entrypoint'] as $dockerCheck) {
        if (stripos($dockerfile, $dockerCheck) === false) {
            throw new RuntimeException('Render Dockerfile is missing required runtime configuration: '.$dockerCheck);
        }
    }
    $entrypoint = $files['docker/entrypoint.sh'];
    foreach (['artisan migrate --force','apache2-foreground','DB_HOST','DB_URL'] as $runtimeNeedle) {
        if (stripos($entrypoint, $runtimeNeedle) === false) {
            throw new RuntimeException('Generated deployment entrypoint is missing safe startup behavior: '.$runtimeNeedle);
        }
    }
    $manifest = json_decode($files['composer.json'], true);
    if (!is_array($manifest) || !isset($manifest['require']['laravel/framework'], $manifest['require']['php'])) {
        throw new RuntimeException('composer.json must require laravel/framework and declare a supported PHP version.');
    }
    if (!str_contains(strtolower($files['config/database.php']), 'mysql')
        || !preg_match('/(?m)^DB_CONNECTION=mysql\\s*$/', $files['.env.example'])) {
        throw new RuntimeException('Production configuration must use MySQL; SQLite-only projects are not accepted.');
    }
    $workflow = $files['.github/workflows/laravel-validate.yml'];
    foreach (['composer validate','composer install','php -l','php artisan test'] as $check) {
        if (stripos($workflow, $check) === false) throw new RuntimeException('Laravel CI workflow is missing: '.$check);
    }
    $productMigration = '';
    $migrationNames = ['create_products','create_categories','create_orders','create_order_items','create_settings','create_roles'];
    foreach ($files as $path => $source) {
        if (str_starts_with($path, 'database/migrations/')) {
            foreach ($migrationNames as $migration) {
                if (str_contains($path, $migration)) $migrationNames = array_values(array_diff($migrationNames, [$migration]));
            }
            if (str_starts_with($path, 'database/migrations/') && str_contains($path, 'create_products')) $productMigration = $source;
        }
    }
    if ($migrationNames) throw new RuntimeException('Laravel project is missing required MySQL migrations.');
    $requiredMigrationGroups = ['create_carts','create_cart_items','create_inventory_movements','create_payment_transactions','create_audit_events'];
    foreach ($requiredMigrationGroups as $group) {
        $found = false;
        foreach (array_keys($files) as $candidate) {
            if (str_starts_with($candidate, 'database/migrations/') && str_contains($candidate, $group)) { $found = true; break; }
        }
        if (!$found) throw new RuntimeException('Laravel project is missing persistent data migration: '.$group);
    }
    $databaseConfig = $files['config/database.php'];
    if (!str_contains(strtolower($databaseConfig), 'pgsql')
        || !str_contains($databaseConfig, 'DB_URL')
        || !str_contains($databaseConfig, 'DATABASE_URL')
        || !preg_match('/[\x27"]url[\x27"]\\s*=>\\s*env\\([\x27"]DB_URL[\x27"]/', $databaseConfig)) {
        throw new RuntimeException('Database config must support MySQL, PostgreSQL and Laravel DB_URL.');
    }
    $envExample = $files['.env.example'];
    if (!preg_match('/(?m)^DB_CONNECTION=mysql\\s*$/', $envExample)) {
        throw new RuntimeException('The cPanel-ready .env.example must default to MySQL.');
    }
    $adminSettings = $files['resources/views/admin/settings/index.blade.php'];
    $settingsController = $files['app/Http/Controllers/Admin/SettingsController.php'];
    foreach (['telegram','google','payment','theme','shipping','tax'] as $section) {
        if (stripos($adminSettings, $section) === false || stripos($settingsController, $section) === false) {
            throw new RuntimeException('Owner Admin Panel is missing a configurable settings section: '.$section);
        }
    }
    foreach (['Not configured','Connection failed','Configured'] as $status) {
        if (stripos($settingsController, $status) === false && stripos($adminSettings, $status) === false) {
            throw new RuntimeException('Integration settings must expose truthful configuration status: '.$status);
        }
    }
    $telegram = $files['app/Services/TelegramNotifier.php'];
    foreach (['api.telegram.org','sendMessage','chat_id'] as $needle) {
        if (stripos($telegram, $needle) === false) throw new RuntimeException('Telegram notifier is missing required behavior.');
    }
    $google = $files['app/Http/Controllers/Auth/GoogleController.php'];
    foreach (['state','hash_equals','Socialite'] as $needle) {
        if (stripos($google, $needle) === false) throw new RuntimeException('Google OAuth must validate state and safely handle missing credentials.');
    }
    $paymentSettings = $files['resources/views/admin/settings/payments.blade.php'];
    foreach (['sandbox','live','endpoint','api_key','api_secret','merchant'] as $field) {
        if (stripos($paymentSettings, $field) === false) throw new RuntimeException('Payment Admin Panel is missing configurable field: '.$field);
    }
    $requiredFeatureTests = [
        'tests/Feature/StorefrontTest.php','tests/Feature/AdminAuthorizationTest.php',
        'tests/Feature/ProductAssistantTest.php','tests/Feature/PaymentWebhookTest.php',
        'tests/Feature/GoogleOAuthTest.php','tests/Feature/TelegramSettingsTest.php',
        'tests/Feature/SubAdminAuthorizationTest.php','tests/Feature/CheckoutTest.php',
        'tests/Feature/PaymentGatewayTest.php','tests/Feature/CategoryInventoryTest.php',
    ];
    foreach ($requiredFeatureTests as $testPath) {
        if (!isset($files[$testPath]) || strlen($files[$testPath]) < 180 || !preg_match('/assert|expect/i', $files[$testPath])) {
            throw new RuntimeException('A required feature test is missing assertions: '.$testPath);
        }
    }
    if (!preg_match('/\$table\s*->\s*json\s*\(\s*["\x27]attributes["\x27]/', $productMigration)) {
        throw new RuntimeException('Products must store structured AI attributes in a JSON database column.');
    }
    $critical = [
        'app/Services/Payments/PaymentGateway.php',
        'app/Services/Payments/ConfiguredGateway.php',
        'app/Services/TelegramNotifier.php',
        'app/Services/ProductAssistant.php',
        'app/Http/Controllers/Admin/ProductController.php',
        'app/Http/Controllers/Admin/OrderController.php',
        'app/Http/Controllers/Admin/SettingsController.php',
        'app/Http/Controllers/Auth/GoogleController.php',
        'app/Http/Controllers/Chat/ProductChatController.php',
        'app/Http/Controllers/PaymentWebhookController.php',
        'app/Http/Middleware/EnsureRole.php',
        'app/Policies/OrderPolicy.php',
    ];
    foreach ($critical as $path) {
        if (strlen(trim($files[$path])) < 120 || preg_match('/(?i)(TODO|placeholder|not implemented|stub only)/', $files[$path])) {
            throw new RuntimeException('A required Laravel module is only a placeholder: '.$path);
        }
    }
    $checks = [
        'app/Services/Payments/PaymentGateway.php' => ['createPayment','verifyWebhook'],
        'app/Services/Payments/ConfiguredGateway.php' => ['endpoint','secret','sandbox','verifyWebhook'],
        'app/Services/TelegramNotifier.php' => ['api.telegram.org','sendMessage','chat_id'],
        'app/Services/ProductAssistant.php' => ['generateContent','json_decode','attributes'],
        'app/Http/Controllers/PaymentWebhookController.php' => ['verifyWebhook','amount','idempot'],
        'app/Http/Middleware/EnsureRole.php' => ['role','abort'],
        'app/Policies/OrderPolicy.php' => ['sub-admin','order'],
    ];
    foreach ($checks as $path => $needles) {
        foreach ($needles as $needle) {
            if (stripos($files[$path], $needle) === false) throw new RuntimeException('A Laravel module is missing required behavior: '.$path);
        }
    }
    $all = implode("\\n", array_values($files));
    if (!str_contains($all, 'Crypt::encryptString') || !str_contains($all, 'Crypt::decryptString')) {
        throw new RuntimeException('Payment and integration secrets must be encrypted at rest.');
    }
    return $files;
}



function s3_configured(): bool {
    return envv('NIRA_S3_ENDPOINT') !== '' && envv('NIRA_S3_BUCKET') !== ''
        && envv('NIRA_S3_ACCESS_KEY_ID') !== '' && envv('NIRA_S3_SECRET_ACCESS_KEY') !== '';
}
function s3_object_request(string $method, string $key, string $body = ''): string {
    $endpoint = rtrim(envv('NIRA_S3_ENDPOINT'), '/');
    $bucket = envv('NIRA_S3_BUCKET');
    $access = envv('NIRA_S3_ACCESS_KEY_ID');
    $secret = envv('NIRA_S3_SECRET_ACCESS_KEY');
    $region = envv('NIRA_S3_REGION', 'auto');
    if ($endpoint === '' || $bucket === '' || $access === '' || $secret === '') {
        throw new RuntimeException('S3-compatible storage is not configured.');
    }
    $parts = parse_url($endpoint);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('NIRA_S3_ENDPOINT must be a complete HTTPS endpoint URL.');
    }
    if (strtolower((string)$parts['scheme']) !== 'https') {
        throw new RuntimeException('External object storage endpoint must use HTTPS.');
    }
    $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    $prefix = rtrim((string)($parts['path'] ?? ''), '/');
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    $uri = $prefix.'/'.rawurlencode($bucket).'/'.$encodedKey;
    $url = $parts['scheme'].'://'.$host.$uri;
    $now = gmdate('Ymd\THis\Z');
    $date = substr($now, 0, 8);
    $payloadHash = hash('sha256', $body);
    $canonicalHeaders = "host:".$host."\n"."x-amz-content-sha256:".$payloadHash."\n"."x-amz-date:".$now."\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = $method."\n".$uri."\n\n".$canonicalHeaders."\n".$signedHeaders."\n".$payloadHash;
    $scope = $date.'/'.$region.'/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n".$now."\n".$scope."\n".hash('sha256', $canonicalRequest);
    $kDate = hash_hmac('sha256', $date, 'AWS4'.$secret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $authorization = 'Authorization: AWS4-HMAC-SHA256 Credential='.$access.'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.$signature;
    $headers = [
        'Host: '.$host, 'x-amz-date: '.$now, 'x-amz-content-sha256: '.$payloadHash,
        $authorization, 'Content-Type: application/zip',
    ];
    $ch = curl_init($url);
    $options = [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>90];
    if ($method === 'PUT') $options[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        throw new RuntimeException('Object storage '.$method.' failed (HTTP '.$status.'): '.($error ?: substr((string)$response, 0, 300)));
    }
    return $response;
}

function github_api(string $method, string $path, string $token, ?array $payload = null): array {
    $ch = curl_init('https://api.github.com'.$path);
    $headers = ['Accept: application/vnd.github+json','Authorization: Bearer '.$token,'X-GitHub-Api-Version: 2022-11-28','User-Agent: Nira-Website-Builder'];
    $opts = [CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>35];
    if ($payload !== null) { $headers[] = 'Content-Type: application/json'; $opts[CURLOPT_HTTPHEADER] = $headers; $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES); }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        throw new RuntimeException('GitHub project publishing failed (HTTP '.$status.').');
    }
    return $data;
}
function github_token_is_valid(): bool {
    $token = envv('NIRA_GITHUB_TOKEN');
    if ($token === '') return false;
    $ch = curl_init('https://api.github.com/user');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer '.$token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Nira-Website-Builder',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $status !== 200) return false;
    $data = json_decode($body, true);
    return is_array($data) && isset($data['login']);
}
function publish_private_github_project(string $projectName, array $files, string $token): array {
    $baseName = strtolower(trim(preg_replace('/[^a-zA-Z0-9-]+/', '-', $projectName), '-'));
    if ($baseName === '') $baseName = 'nira-store';
    $repoName = substr($baseName, 0, 42).'-'.bin2hex(random_bytes(3));
    $repo = github_api('POST', '/user/repos', $token, [
        'name'=>$repoName,'private'=>true,'auto_init'=>true,
        'description'=>'Customer-specific Laravel e-commerce project generated by Nira AI',
    ]);
    $owner = (string)($repo['owner']['login'] ?? '');
    $default = (string)($repo['default_branch'] ?? 'main');
    if ($owner === '') throw new RuntimeException('GitHub did not return the repository owner.');
    $ref = github_api('GET', '/repos/'.$owner.'/'.$repoName.'/git/ref/heads/'.rawurlencode($default), $token);
    $baseSha = (string)($ref['object']['sha'] ?? '');
    if ($baseSha === '') throw new RuntimeException('GitHub repository base branch was not available.');
    $baseCommit = github_api('GET', '/repos/'.$owner.'/'.$repoName.'/git/commits/'.$baseSha, $token);
    $treeItems = [];
    foreach ($files as $path => $content) {
        $blob = github_api('POST', '/repos/'.$owner.'/'.$repoName.'/git/blobs', $token, [
            'content'=>$content,'encoding'=>'utf-8',
        ]);
        $treeItems[] = ['path'=>$path,'mode'=>'100644','type'=>'blob','sha'=>$blob['sha']];
    }
    $tree = github_api('POST', '/repos/'.$owner.'/'.$repoName.'/git/trees', $token, [
        'base_tree'=>$baseCommit['tree']['sha'],'tree'=>$treeItems,
    ]);
    $commit = github_api('POST', '/repos/'.$owner.'/'.$repoName.'/git/commits', $token, [
        'message'=>'Generate customer Laravel e-commerce project',
        'tree'=>$tree['sha'],'parents'=>[$baseSha],
    ]);
    $branch = 'nira-generated';
    github_api('POST', '/repos/'.$owner.'/'.$repoName.'/git/refs', $token, [
        'ref'=>'refs/heads/'.$branch,'sha'=>$commit['sha'],
    ]);
    return ['repository_url'=>$repo['html_url'] ?? ('https://github.com/'.$owner.'/'.$repoName),
        'owner'=>$owner,'repo'=>$repoName,'branch'=>$branch,'approval_required'=>true,
        'download_url'=>'https://github.com/'.$owner.'/'.$repoName.'/archive/refs/heads/'.$branch.'.zip'];
}

function build_static_preview(array $files, string $viewPath): string {
    $layout = (string)($files['resources/views/layouts/app.blade.php'] ?? '');
    $view = (string)($files[$viewPath] ?? '');
    if ($view === '') return '<!doctype html><html><body><h1>Preview unavailable</h1><p>Requested view was not generated.</p></body></html>';
    if (preg_match('/@extends\\s*\\(["\\x27]([^"\\x27]+)["\\x27]\\)/', $view, $m)) {
        $view = preg_replace('/@extends\\s*\\(["\\x27][^"\\x27]+["\\x27]\\)\\s*/', '', $view, 1);
    }
    if ($layout !== '') {
        if (preg_match('/@section\\s*\\(["\\x27]content["\\x27]\\)(.*?)@endsection/s', $view, $m)) {
            $content = $m[1];
            $layout = preg_replace('/@yield\\s*\\(["\\x27]content["\\x27](?:\\s*,\\s*[^)]*)?\\)/', $content, $layout, 1);
        } else {
            $layout .= $view;
        }
        $html = $layout;
    } else {
        $html = $view;
    }
    $css = (string)($files['resources/css/app.css'] ?? '');
    $html = preg_replace('/<script\\b[^>]*>.*?<\\/script\\s*>/is', '', $html);
    $html = preg_replace('/<(iframe|object|embed|base)\\b[^>]*>.*?<\\/\\1\\s*>/is', '', $html);
    $html = preg_replace('/<(iframe|object|embed|base)\\b[^>]*\\/?\\s*>/is', '', $html);
    $html = preg_replace('/<form\\b[^>]*>/i', '<div class="preview-form">', $html);
    $html = preg_replace('/<\\/form\\s*>/i', '</div>', $html);
    $html = preg_replace('/\\s+on[a-z]+\\s*=\\s*("[^"]*"|\\x27[^\\x27]*\\x27|[^\\s>]+)/i', '', $html);
    $html = preg_replace('/\\s+(href|src)\\s*=\\s*(["\\x27])\\s*javascript:[^"\\x27]*\\2/i', '', $html);
    $html = preg_replace('/@csrf|@method\\s*\\([^)]*\\)|@vite\\s*\\([^)]*\\)/', '', $html);
    $html = preg_replace('/\\{\\{\\s*(.*?)\\s*\\}\\}/s', '<span class="preview-dynamic">[dynamic content]</span>', $html);
    $html = preg_replace('/\\{!!\\s*(.*?)\\s*!!\\}/s', '<span class="preview-dynamic">[dynamic content]</span>', $html);
    $html = preg_replace('/@[a-zA-Z_]+(?:\\s*\\([^)]*\\))?/', '', $html);
    $style = '<style>body{margin:0;font-family:system-ui,sans-serif}.preview-dynamic{color:#64748b;background:#e2e8f0;border-radius:4px;padding:1px 4px}.preview-form{pointer-events:none}'.$css.'</style>';
    if (preg_match('/<head\\b[^>]*>/i', $html)) $html = preg_replace('/<head\\b[^>]*>/i', '$0'.$style, $html, 1);
    else $html = '<!doctype html><html><head><meta charset="utf-8">'.$style.'</head><body>'.$html.'</body></html>';
    return substr($html, 0, 60000);
}

function ui(): never {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html><html lang="bn"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nira AI Website Builder</title>
<style>
:root{color-scheme:dark;font-family:system-ui}body{margin:0;background:#0b1020;color:#eef2ff;display:grid;place-items:center;min-height:100vh;padding:20px}
main{width:min(1000px,100%);background:#151d33;border:1px solid #293654;border-radius:22px;padding:28px;box-sizing:border-box}
h1{margin:0 0 8px;font-size:clamp(26px,5vw,38px)}p{color:#b9c5dc;line-height:1.6}
textarea,input{width:100%;box-sizing:border-box;background:#0b1328;color:#fff;border:1px solid #344464;border-radius:12px;padding:14px;font:inherit;margin:8px 0 16px}
textarea{min-height:160px;resize:vertical}button{background:#6d7cff;color:white;border:0;border-radius:12px;padding:13px 20px;font-weight:700;font-size:16px;cursor:pointer}
button:disabled{opacity:.5}#status{white-space:pre-wrap;margin-top:18px;color:#cbd5e1}.small{font-size:13px;color:#92a2bf}
#preview{display:none;margin-top:24px;border-top:1px solid #344464;padding-top:20px}#preview iframe{width:100%;height:620px;background:white;border:1px solid #344464;border-radius:12px}
.preview-tabs{display:flex;gap:8px;margin:12px 0}.preview-tabs button{font-size:14px;padding:9px 14px}
</style><main><h1>Nira AI Website Builder</h1><p>আপনার ওয়েবসাইটের ধরন, পেজ, ডিজাইন ও ফিচার লিখুন। Nira AI একটি প্রজেক্ট ZIP তৈরি করবে।</p>
<label>ওয়েবসাইটের নির্দেশনা</label><textarea id="prompt" placeholder="যেমন: আধুনিক e-commerce website, product catalog, cart, admin dashboard, Bangla/English..."></textarea>
<label>প্রজেক্টের নাম</label><input id="name" value="my-store" maxlength="40">\n<label>Access token (যদি সার্ভারে সেট করা থাকে)</label><input id="token" type="password" autocomplete="off" placeholder="NIRA_BUILDER_TOKEN">
<button id="go">Generate website ZIP</button><div id="status"></div><section id="preview"><h2>Static preview (safe mode)</h2><p class="small">এটি generated Blade/CSS থেকে তৈরি নিরাপদ static preview; Laravel/PHP বা generated JavaScript এখানে execute হয় না।</p><div class="preview-tabs"><button id="storeTab" type="button">Storefront</button><button id="adminTab" type="button">Admin Panel</button></div><iframe id="previewFrame" title="Website preview" sandbox=""></iframe></section><p class="small">API key ও access token সার্ভারের Environment Variables-এ রাখতে হবে; এখানে লিখবেন না। Generated code আলাদা ZIP হিসেবে দেওয়া হয়, সার্ভারে স্বয়ংক্রিয়ভাবে execute করা হয় না।</p></main>
<script>
const b=document.getElementById('go'),s=document.getElementById('status');
b.onclick=async()=>{b.disabled=true;s.textContent='প্রজেক্ট তৈরি শুরু হচ্ছে...';try{
const r=await fetch('/api/generate?stream=1',{method:'POST',headers:{'Content-Type':'application/json',...(document.getElementById('token').value?{'X-Nira-Token':document.getElementById('token').value}:{})},body:JSON.stringify({prompt:document.getElementById('prompt').value,name:document.getElementById('name').value})});
if(!r.ok && !r.body)throw Error('Generation failed');
const reader=r.body.getReader(),decoder=new TextDecoder();let buffer='',completed=null;
while(true){const {value,done}=await reader.read();if(done)break;buffer+=decoder.decode(value,{stream:true});const blocks=buffer.split(/\n\n/);buffer=blocks.pop()||'';
for(const block of blocks){const ev=(block.match(/^event: (.+)$/m)||[])[1],data=(block.match(/^data: (.+)$/m)||[])[1];if(!data)continue;const j=JSON.parse(data);
if(ev==='progress'){s.textContent=j.message+'\nঅগ্রগতি: '+(j.percent||0)+'% · ফাইল: '+(j.files_created||0); }
if(ev==='error')throw Error(j.error||'Generation failed');
if(ev==='complete')completed=j;
}}
if(!completed)throw Error('Builder connection ended before completion.');
s.textContent='তৈরি হয়েছে: '+completed.files+'টি ফাইল। '+(completed.github?'Private GitHub draft প্রস্তুত।':'');
if(completed.github?.repository_url){const p=document.createElement('p'),repo=document.createElement('a');repo.href=completed.github.repository_url;repo.target='_blank';repo.rel='noopener';repo.textContent='Private draft repository দেখুন';p.append(repo);s.append(p);
const approve=document.createElement('button');approve.textContent='আমি অনুমোদন দিচ্ছি — Review PR তৈরি করো';approve.style.marginTop='12px';approve.onclick=async()=>{approve.disabled=true;try{const ar=await fetch('/api/approve',{method:'POST',headers:{'Content-Type':'application/json','X-Nira-Token':document.getElementById('token').value},body:JSON.stringify({owner:completed.github.owner,repo:completed.github.repo,branch:completed.github.branch})});const aj=await ar.json();if(!ar.ok)throw Error(aj.error||'Approval failed');const link=document.createElement('a');link.href=aj.pull_request_url;link.target='_blank';link.rel='noopener';link.textContent='Approved review pull request খুলুন';s.append(link);}catch(e){s.append(document.createTextNode(' অনুমোদন ব্যর্থ: '+e.message));approve.disabled=false;}};s.append(approve);
}
if(completed.preview?.storefront_html && completed.preview?.admin_html){
const section=document.getElementById('preview'),frame=document.getElementById('previewFrame');
const store=document.getElementById('storeTab'),admin=document.getElementById('adminTab');
const show=(which)=>{frame.srcdoc=which==='admin'?completed.preview.admin_html:completed.preview.storefront_html;store.disabled=which==='store';admin.disabled=which==='admin';};
store.onclick=()=>show('store');admin.onclick=()=>show('admin');section.style.display='block';show('store');
}
if(completed.download){const a=document.createElement('a');a.href=completed.download;a.textContent=' ZIP ডাউনলোড';a.style.display='inline-block';a.style.marginTop='12px';s.append(a)}
}catch(e){s.textContent='সমস্যা: '+e.message}finally{b.disabled=false}};
</script></html>
HTML;
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/health') {
    $storageRoot = envv('NIRA_STORAGE_PATH', __DIR__.'/../storage/generated');
    $storageReady = s3_configured() || (is_dir($storageRoot) ? is_writable($storageRoot) : (is_writable(dirname($storageRoot)) || is_writable(dirname(__DIR__))));
    $persistentPath = s3_configured() || str_starts_with($storageRoot, '/var/data/') || str_starts_with($storageRoot, '/mnt/data/') || envv('NIRA_STORAGE_PERSISTENT') === 'true';
    $health = [
        'ok'=>true,
        'service'=>'nira-website-builder',
        'gemini_configured'=>envv('GEMINI_API_KEY', envv('GOOGLE_API_KEY')) !== '',
        'github_publishing_configured'=>envv('NIRA_GITHUB_TOKEN') !== '',
        'github_token_valid'=>github_token_is_valid(),
        'storage_writable'=>$storageReady,
        'persistent_storage_configured'=>$persistentPath,
    ];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); exit; }
    respond(200, $health);
}
if ($path === '/' && in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') { http_response_code(200); header('Content-Type: text/html; charset=utf-8'); exit; }
    ui();
}
if ($path === '/api/generate' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $expected = envv('NIRA_BUILDER_TOKEN');
    if ($expected === '') respond(503, ['error'=>'Builder API is disabled until NIRA_BUILDER_TOKEN is configured.']);
    $given = $_SERVER['HTTP_X_NIRA_TOKEN'] ?? '';
    if (!hash_equals($expected, $given)) respond(401, ['error'=>'Unauthorized']);
    $GLOBALS['NIRA_SSE_MODE'] = (($_GET['stream'] ?? '') === '1');
    if (!empty($GLOBALS['NIRA_SSE_MODE'])) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        progress_event('progress', ['stage'=>'request_received','percent'=>2,'message'=>'অনুরোধ যাচাই করা হচ্ছে']);
    }
    $raw = file_get_contents('php://input', false, null, 0, MAX_REQUEST_BYTES + 1);
    if (strlen($raw) > MAX_REQUEST_BYTES) respond(413, ['error'=>'Request too large.']);
    $input = json_decode($raw ?: '', true);
    $prompt = trim((string)($input['prompt'] ?? ''));
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($input['name'] ?? 'website'));
    if (strlen($prompt) < 20) respond(422, ['error'=>'কমপক্ষে ২০ অক্ষরের বিস্তারিত নির্দেশনা দিন।']);
    if (strlen($prompt) > 9000) respond(422, ['error'=>'নির্দেশনা খুব বড়।']);
    $apiKey = envv('GEMINI_API_KEY', envv('GOOGLE_API_KEY'));
    if ($apiKey === '') respond(503, ['error'=>'Server setup incomplete: GEMINI_API_KEY environment variable is missing.']);
    $model = envv('GEMINI_MODEL', 'gemini-2.5-flash');
    $system = "You are Nira's complete Laravel E-commerce application generator. Return ONLY valid JSON: {\"files\":[{\"path\":\"relative/path\",\"content\":\"complete file text\"}]}. MANDATORY: generate a complete, installable Laravel Framework application, never Core PHP, plain PHP, WordPress, Node-only, or a static mockup. Require laravel/framework and a supported PHP version in composer.json. Generate up to 100 files, each <=120KB and total source <=3MB. Every required feature must be implemented end-to-end across routes, validation, authorization, controllers, services, database migrations, Blade views, and feature tests; a view or placeholder class alone does not count. Include the complete Laravel app: artisan, bootstrap/app.php, public/index.php and .htaccess, routes/web.php and auth.php, MySQL config and .env.example, models, controllers, middleware, policies, requests, migrations, seeders, Blade storefront/admin views, CSS/JS, feature tests, README, and .github/workflows/laravel-validate.yml. Required modules: responsive customer-specific storefront, search, product detail, cart, checkout, customer accounts/order history, owner admin, product/category/inventory CRUD, orders/customers, sub-admin restricted to order management with server-side authorization, JSON product attributes, Gemini product description JSON generation and live product chat, Telegram notifications/settings, Google OAuth configuration, provider-neutral configurable payment adapter and secure webhook. Derive store name, niche, catalog, currency, colors, layout, language and brand from the user's brief; do not hardcode a demo shop. Use MySQL for production and include cPanel installation instructions. Include migrations for users, products, categories, product images, carts/cart items (or secure session cart), orders, order items, inventory movements, settings, roles/permissions, payment transactions, and audit events; include seeders that create the first owner securely from environment variables or a one-time setup flow. Store money as integer minor units or fixed-precision decimals, use database transactions and row locks for stock/order changes, prevent overselling, validate shipping/tax totals server-side, and never trust client-submitted prices. Implement CSRF protection, authentication throttling, authorization policies, form requests, output escaping, upload MIME/size validation, and secure session/cookie defaults. The owner admin must be able to configure store name/logo/colors/theme/layout/currency/language/contact details, catalog, stock, shipping, and taxes. All buyer-specific integrations must be configured from dedicated owner-only Admin Panel screens without routine source-code or .env edits. Telegram settings must include bot token, chat ID, per-event notification toggles, enable/disable, test-message action and explicit Not configured/Configured/Connection failed status; missing credentials keep it disabled and no fake success is allowed. Google OAuth settings must include Client ID, Client Secret, Redirect/Callback URL, enable/disable and setup status; missing credentials keep OAuth disabled while ordinary login continues. Payment settings must allow each provider to be independently configured with display name, enabled/disabled, sandbox/live mode, API endpoint, merchant/account ID, API key, API secret, signing/webhook secret, currency, return URL and webhook URL. Encrypt secrets at rest, mask them, preserve saved values when blank secret fields are submitted, and never expose them to browser JS, HTML responses, logs or audit metadata. Show actionable validation and connection-test results. Missing or undocumented credentials leave integrations inactive/pending, never simulate successful connections or payments. The sub-admin must only view/update permitted order fields; enforce this on every route and policy. Encrypt integration/payment credentials at rest, mask secrets in admin UI, never expose secrets to browser JS or logs. Payment adapters must verify webhook signatures server-side, amount/currency/order, idempotency and atomic updates. For unconfigured providers, expose a clear disabled/pending status and safe manual/offline payment option rather than pretending online payment works. Never invent a provider API or claim a real payment integration without official docs and sandbox credentials; keep provider setup disabled/pending until configured. Never include real credentials, binaries, vendor, node_modules, .git, .env, or generated secrets. Include meaningful feature tests for storefront, admin/subadmin authorization, products/JSON chat, Telegram settings, Google OAuth, checkout, and payment webhooks. CI must run composer validate, composer install, php -l and php artisan test. Do not execute generated PHP on this builder server. Return complete files only, no markdown fences.";
    // Generate the Laravel application in three bounded modules. This avoids
    // truncating a complete application at Gemini's single-response token limit.
    $generationBatches = [
        [
            'name'=>'framework_storefront',
            'paths'=>[
                'composer.json','artisan','bootstrap/app.php','public/index.php','public/.htaccess',
                'routes/web.php','routes/auth.php','config/auth.php','config/database.php','config/services.php',
                '.env.example','README.md','.github/workflows/laravel-validate.yml',
                'database/migrations/*','database/seeders/*',
                'app/Models/*','app/Http/Requests/*','app/Http/Middleware/EnsureRole.php',
                'app/Policies/OrderPolicy.php',
                'app/Http/Controllers/StoreController.php','app/Http/Controllers/CartController.php',
                'app/Http/Controllers/CheckoutController.php',
                'resources/views/layouts/*','resources/views/store/*','resources/views/cart/*',
                'resources/views/checkout/*'
            ],
            'scope'=>'Build the Laravel framework skeleton, all database migrations and seeders, models, auth/routing/configuration, storefront, customer account basics, cart and secure checkout.'
        ],
        [
            'name'=>'owner_admin',
            'paths'=>[
                'app/Http/Controllers/Admin/*.php','app/Http/Controllers/Auth/GoogleController.php',
                'resources/views/admin/*.blade.php','resources/views/admin/products/*.blade.php',
                'resources/views/admin/orders/*.blade.php','resources/views/admin/categories/*.blade.php',
                'resources/views/admin/inventory/*.blade.php','resources/views/admin/subadmins/*.blade.php',
                'resources/views/admin/customers/*.blade.php','resources/views/admin/settings/*.blade.php'
            ],
            'scope'=>'Build the complete owner admin and least-privilege sub-admin modules, authorization, catalog, orders, customers, inventory, branding/theme, shipping/tax, Google OAuth settings and their views. Integrate with the exact models, migrations, routes and service contracts implied by the application brief.'
        ],
        [
            'name'=>'integrations_tests_assets',
            'paths'=>[
                'app/Services/*.php','app/Services/Payments/*.php',
                'app/Http/Controllers/Chat/ProductChatController.php',
                'app/Http/Controllers/PaymentWebhookController.php',
                'resources/views/components/*','resources/css/app.css','resources/js/app.js',
                'tests/Feature/*'
            ],
            'scope'=>'Build Telegram, Gemini product assistant, secure provider-neutral payment adapters/webhooks, product chat UI, assets, and comprehensive feature tests for every required workflow. Ensure all service/controller/view contracts match the files requested in earlier batches.'
        ]
    ];
    $allGeneratedFiles = [];
    $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($apiKey);
    $batchTotal = count($generationBatches);
    foreach ($generationBatches as $batchIndex => $batch) {
        progress_event('progress', ['stage'=>$batch['name'],'batch'=>$batchIndex+1,'total_batches'=>$batchTotal,'percent'=>5+(int)(($batchIndex/$batchTotal)*70),'files_created'=>count($allGeneratedFiles),'message'=>'ব্যাচ '.$batchIndex+1 .'/'.$batchTotal.' তৈরি হচ্ছে']);
        $batchText = $system."\\n\\nGENERATION BATCH: ".$batch['name'].
            "\\nSCOPE: ".$batch['scope'].
            "\\nONLY CREATE FILES MATCHING THESE PATHS/PATTERNS (glob syntax):\\n- ".
            implode("\\n- ", $batch['paths']).
            "\\nReturn JSON only in the required {files:[{path,content}]} schema. ".
            "Do not repeat files from another batch, do not create files outside this batch, ".
            "and do not omit any concrete required file in this batch. Use consistent Laravel 10/PHP 8.2 APIs, ".
            "route names, model relationships, database columns and service method signatures across batches. ".
            "No placeholders, TODOs, fake integration success, real credentials or binary files.\\nUSER REQUEST:\\n".$prompt;
        $payload = [
            'contents'=>[['parts'=>[['text'=>$batchText]]]],
            'generationConfig'=>['responseMimeType'=>'application/json','temperature'=>0.2,'maxOutputTokens'=>22000]
        ];
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>15,
            CURLOPT_TIMEOUT=>150
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) respond(502, ['error'=>'AI request failed during '.$batch['name'].' generation: '.$err]);
        $answer = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $providerMessage = $answer['error']['message'] ?? 'Unknown provider error';
            respond(502, ['error'=>'AI provider error during '.$batch['name'].' generation','detail'=>$providerMessage]);
        }
        $candidate = $answer['candidates'][0] ?? null;
        if (!is_array($candidate)) respond(502, ['error'=>'AI returned no candidate for generation batch '.$batch['name'].'.']);
        $finishReason = (string)($candidate['finishReason'] ?? '');
        if ($finishReason === 'MAX_TOKENS') {
            respond(502, ['error'=>'AI output limit reached in '.$batch['name'].' batch. Reduce the requested scope and retry; no partial ZIP was published.']);
        }
        $parts = $candidate['content']['parts'] ?? [];
        $batchTextOutput = '';
        foreach ($parts as $part) {
            if (isset($part['text']) && is_string($part['text'])) $batchTextOutput .= $part['text'];
        }
        $batchResult = json_decode($batchTextOutput, true);
        if (!is_array($batchResult) || !isset($batchResult['files']) || !is_array($batchResult['files'])) {
            respond(502, ['error'=>'AI returned invalid JSON for '.$batch['name'].' batch. No partial project was published.']);
        }
        foreach ($batchResult['files'] as $generatedFile) {
            if (!is_array($generatedFile) || !isset($generatedFile['path'],$generatedFile['content'])
                || !is_string($generatedFile['path']) || !is_string($generatedFile['content'])) {
                respond(502, ['error'=>'AI returned a malformed file in '.$batch['name'].' batch.']);
            }
            $generatedPath = clean_name($generatedFile['path']);
            if (isset($allGeneratedFiles[$generatedPath])) {
                respond(502, ['error'=>'AI generated a duplicate path across batches: '.$generatedPath]);
            }
            $allGeneratedFiles[$generatedPath] = $generatedFile['content'];
            if (count($allGeneratedFiles) > MAX_OUTPUT_FILES) {
                respond(422, ['error'=>'Generated project exceeds the 100-file limit.']);
            }
        }
        if (count($batchResult['files']) < 1) {
            respond(502, ['error'=>'AI returned an empty '.$batch['name'].' batch.']);
        }
        progress_event('progress', ['stage'=>$batch['name'].'_complete','batch'=>$batchIndex+1,'total_batches'=>$batchTotal,'percent'=>5+(int)((($batchIndex+1)/$batchTotal)*70),'files_created'=>count($allGeneratedFiles),'message'=>$batch['name'].' সম্পন্ন']);
    }
    $result = ['files'=>[]];
    foreach ($allGeneratedFiles as $generatedPath=>$generatedContent) {
        $result['files'][] = ['path'=>$generatedPath,'content'=>$generatedContent];
    }
    if (!is_array($result) || !isset($result['files']) || !is_array($result['files'])) respond(502, ['error'=>'AI returned an invalid project format.']);
    if (count($result['files']) < 1 || count($result['files']) >= MAX_OUTPUT_FILES) respond(502, ['error'=>'Generated project file count is outside the allowed limit after deployment files are added.']);
    // Replace model-authored deployment plumbing with a deterministic runtime.
    // Missing buyer database credentials must not prevent Apache from starting.
    $dockerContent = <<<'DOCKER'
FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql mbstring bcmath exif gd zip intl opcache \
    && a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -ri 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf \
    && sed -ri '/<Directory/,/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/sites-available/000-default.conf \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/nira-entrypoint
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache database \
    && chmod +x /usr/local/bin/nira-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache database
ENV APP_ENV=production APP_DEBUG=false DB_CONNECTION=mysql
EXPOSE 10000
CMD ["/usr/local/bin/nira-entrypoint"]
DOCKER;
    $entrypointContent = <<<'SH'
#!/bin/sh
set -eu
cd /var/www/html
if [ ! -f artisan ]; then
    echo "Laravel artisan file is missing; refusing to start an incomplete project." >&2
    exit 1
fi
if [ -n "${DB_URL:-}" ] || [ -n "${DATABASE_URL:-}" ] || [ -n "${DB_HOST:-}" ]; then
    echo "Database configuration detected; applying Laravel migrations."
    php artisan migrate --force
else
    echo "Database credentials are not configured yet; skipping migrations and starting the app."
fi
exec apache2-foreground
SH;
    $runtimeFiles = [];
    foreach ($result['files'] as $generatedFile) {
        if (!is_array($generatedFile) || !isset($generatedFile['path'])) continue;
        if (in_array($generatedFile['path'], ['Dockerfile','docker/entrypoint.sh'], true)) continue;
        $runtimeFiles[] = $generatedFile;
    }
    $runtimeFiles[] = ['path'=>'Dockerfile','content'=>$dockerContent];
    $runtimeFiles[] = ['path'=>'docker/entrypoint.sh','content'=>$entrypointContent];
    $result['files'] = $runtimeFiles;
    progress_event('progress', ['stage'=>'validation','percent'=>80,'files_created'=>count($result['files']),'message'=>'প্রজেক্টের ফাইল ও নিরাপত্তা যাচাই হচ্ছে']);
    try { $validatedFiles = validate_laravel_project($result['files']); }
    catch (Throwable $e) { respond(422, ['error'=>'Generated output did not pass Laravel validation: '.$e->getMessage()]); }
    progress_event('progress', ['stage'=>'validation_complete','percent'=>88,'files_created'=>count($validatedFiles),'message'=>'যাচাই সম্পন্ন']);
    if (!class_exists(ZipArchive::class)) respond(500, ['error'=>'ZIP extension is not available.']);
    $id = bin2hex(random_bytes(12)); $storageRoot = envv('NIRA_STORAGE_PATH', __DIR__.'/../storage/generated');
    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) respond(500, ['error'=>'Could not initialize generated-project storage.']);
    $base = rtrim($storageRoot, '/').'/'.$id;
    if (!mkdir($base, 0750, true)) respond(500, ['error'=>'Could not create project workspace.']);
    $zipPath = $base.'/project.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) respond(500, ['error'=>'Could not create ZIP.']);
    try {
        foreach ($validatedFiles as $rel => $source) {
            $zip->addFromString($rel, $source);
        }
        $zip->close();
    } catch (Throwable $e) { $zip->close(); respond(422, ['error'=>$e->getMessage()]); }
        $downloadKey = 'generated/'.$id.'/project.zip';
    if (s3_configured()) {
        try {
            $zipBytes = file_get_contents($zipPath);
            if (!is_string($zipBytes)) throw new RuntimeException('Could not read completed ZIP for upload.');
            s3_object_request('PUT', $downloadKey, $zipBytes);
            @unlink($zipPath); @rmdir($base);
        } catch (Throwable $e) { respond(502, ['error'=>'ZIP created but persistent object-storage upload failed: '.$e->getMessage()]); }
    }
    progress_event('progress', ['stage'=>'archive','percent'=>92,'files_created'=>count($validatedFiles),'message'=>'ZIP ও প্রজেক্ট আর্কাইভ প্রস্তুত হচ্ছে']);
    $response = ['ok'=>true,'name'=>$name ?: 'website','download'=>'/download.php?id='.$id,'files'=>count($validatedFiles),'storage'=>s3_configured() ? 'external_object_storage' : 'local_ephemeral'];
    $githubToken = envv('NIRA_GITHUB_TOKEN');
    if ($githubToken !== '') {
        try {
            $response['github'] = publish_private_github_project($name ?: 'website', $validatedFiles, $githubToken);
            // The private GitHub repository is the durable, no-extra-storage archive.
            // Its branch ZIP contains the complete generated source and survives Render restarts.
            $response['download'] = $response['github']['download_url'];
            $response['storage'] = 'private_github_repository';
            @unlink($zipPath);
            @rmdir($base);
        } catch (Throwable $e) {
            $response['github_error'] = $e->getMessage();
            $response['github_notice'] = 'GitHub archive could not be confirmed; the local ZIP is temporary and may disappear after a restart or redeploy.';
        }
    } else {
        $response['github_notice'] = 'NIRA_GITHUB_TOKEN is not configured. ZIP storage is temporary on Render Free; configure GitHub publishing for durable free storage.';
    }
    $response['approval_required'] = true;
    $response['preview_type'] = 'static_sanitized';
    $response['preview'] = [
        'storefront_html'=>build_static_preview($validatedFiles, 'resources/views/store/index.blade.php'),
        'admin_html'=>build_static_preview($validatedFiles, 'resources/views/admin/dashboard.blade.php'),
    ];
    if (!empty($GLOBALS['NIRA_SSE_MODE'])) {
        progress_event('complete', $response + ['percent'=>100,'message'=>'প্রজেক্ট তৈরি হয়েছে। GitHub PR তৈরির আগে আপনার অনুমতি প্রয়োজন।']);
        exit;
    }
    respond(200, $response);
}
if ($path === '/api/revise' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $expected = envv('NIRA_BUILDER_TOKEN');
    $given = $_SERVER['HTTP_X_NIRA_TOKEN'] ?? '';
    if ($expected === '' || !hash_equals($expected, $given)) respond(401, ['error'=>'Unauthorized']);
    $raw = file_get_contents('php://input', false, null, 0, 10000);
    $input = is_string($raw) ? json_decode($raw, true) : null;
    $owner = is_array($input) ? (string)($input['owner'] ?? '') : '';
    $repoName = is_array($input) ? (string)($input['repo'] ?? '') : '';
    $branch = is_array($input) ? (string)($input['branch'] ?? '') : '';
    $instruction = trim((string)($input['instruction'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9-]{1,39}$/', $owner) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $repoName)
        || $branch !== 'nira-generated' || strlen($instruction) < 5 || strlen($instruction) > 2500) {
        respond(422, ['error'=>'Provide a valid private draft repository, nira-generated branch and a 5–2500 character design instruction.']);
    }
    $token = envv('NIRA_GITHUB_TOKEN');
    $apiKey = envv('GEMINI_API_KEY', envv('GOOGLE_API_KEY'));
    if ($token === '' || $apiKey === '') respond(503, ['error'=>'GitHub and Gemini server configuration is required for revisions.']);
    try {
        $repoData = github_api('GET', '/repos/'.$owner.'/'.$repoName, $token);
        if (empty($repoData['private'])) respond(403, ['error'=>'Only private Nira draft repositories can be revised.']);
        $ref = github_api('GET', '/repos/'.$owner.'/'.$repoName.'/git/ref/heads/'.rawurlencode($branch), $token);
        $baseSha = (string)($ref['object']['sha'] ?? '');
        if ($baseSha === '') respond(404, ['error'=>'Draft branch was not found.']);
        $commitData = github_api('GET', '/repos/'.$owner.'/'.$repoName.'/git/commits/'.$baseSha, $token);
        $treeData = github_api('GET', '/repos/'.$owner.'/'.$repoName.'/git/trees/'.$commitData['tree']['sha'].'?recursive=1', $token);
        $sourceFiles = [];
        $allowed = static function(string $p): bool {
            return (str_starts_with($p, 'resources/views/') && str_ends_with($p, '.blade.php'))
                || in_array($p, ['resources/css/app.css','resources/js/app.js'], true);
        };
        foreach (($treeData['tree'] ?? []) as $item) {
            $p = (string)($item['path'] ?? '');
            if (($item['type'] ?? '') !== 'blob' || !$allowed($p)) continue;
            if (count($sourceFiles) >= 35) break;
            $blob = github_api('GET', '/repos/'.$owner.'/'.$repoName.'/git/blobs/'.$item['sha'], $token);
            if (($blob['encoding'] ?? '') !== 'base64' || !isset($blob['content'])) continue;
            $content = base64_decode((string)$blob['content'], true);
            if (!is_string($content) || strlen($content) > 90000) continue;
            $sourceFiles[$p] = $content;
            if (array_sum(array_map('strlen', $sourceFiles)) > 850000) respond(413, ['error'=>'Preview-edit source set exceeds the safe 850 KB context limit.']);
        }
        if (!isset($sourceFiles['resources/views/store/index.blade.php']) || !isset($sourceFiles['resources/views/admin/dashboard.blade.php'])) {
            respond(422, ['error'=>'Draft is missing the Storefront or Admin dashboard view needed for preview.']);
        }
        progress_event('progress', ['stage'=>'revision_read','percent'=>10,'message'=>'Draft source পড়া হয়েছে','files_created'=>count($sourceFiles)]);
        $model = envv('GEMINI_MODEL', 'gemini-2.5-flash');
        $prompt = "You are revising an existing Laravel e-commerce UI. Apply the user's requested visual/content change consistently. Return ONLY JSON: {\\"files\\":[{\\"path\\":\\"existing relative path\\",\\"content\\":\\"complete updated file content\\"}]}. You may edit ONLY the supplied existing Blade views, resources/css/app.css, and resources/js/app.js. Never add files, change routes/controllers/database/auth/payment logic, add external scripts, invent secrets, or remove unrelated features. Return only files that actually need changes. Keep responsive accessibility and Laravel Blade syntax intact. USER INSTRUCTION:\\n".$instruction."\\nEXISTING FILES JSON:\\n".json_encode($sourceFiles, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($apiKey));
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode([
            'contents'=>[['parts'=>[['text'=>$prompt]]]],
            'generationConfig'=>['responseMimeType'=>'application/json','temperature'=>0.2,'maxOutputTokens'=>12000]
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>150]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if (!is_string($body) || $code < 200 || $code >= 300) throw new RuntimeException('Gemini revision failed (HTTP '.$code.'). '.($err ?: ''));
        $answer = json_decode($body, true);
        $text = (string)($answer['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $changes = json_decode($text, true);
        if (!is_array($changes) || !isset($changes['files']) || !is_array($changes['files']) || count($changes['files']) < 1) {
            throw new RuntimeException('Gemini returned no valid changed files.');
        }
        $treeItems = [];
        $changedPaths = [];
        foreach ($changes['files'] as $file) {
            if (!is_array($file) || !isset($file['path'],$file['content']) || !is_string($file['path']) || !is_string($file['content'])) {
                throw new RuntimeException('Malformed revision file.');
            }
            $p = clean_name($file['path']);
            if (!$allowed($p) || !array_key_exists($p, $sourceFiles)) throw new RuntimeException('Revision attempted to modify a disallowed or unknown path: '.$p);
            if (strlen($file['content']) > 90000 || preg_match('/(?:AIza[0-9A-Za-z_-]{30,}|gh[pousr]_[A-Za-z0-9_]{30,}|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----)/', $file['content'])) {
                throw new RuntimeException('Revision contains an oversized file or possible secret.');
            }
            $blob = github_api('POST', '/repos/'.$owner.'/'.$repoName.'/git/blobs', $token, ['content'=>$file['content'],'encoding'=>'utf-8']);
            $treeItems[] = ['path'=>$p,'mode'=>'100644','type'=>'blob','sha'=>$blob['sha']];
            $sourceFiles[$p] = $file['content'];
            $changedPaths[] = $p;
        }
        $tree = github_api('POST', '/repos/'.$owner.'/'.$repoName.'/git/trees', $token, ['base_tree'=>$commitData['tree']['sha'],'tree'=>$treeItems]);
        $newCommit = github_api('POST', '/repos/'.$owner.'/'.$repoName.'/git/commits', $token, ['message'=>'Apply approved Nira UI revision','tree'=>$tree['sha'],'parents'=>[$baseSha]]);
        github_api('PATCH', '/repos/'.$owner.'/'.$repoName.'/git/refs/heads/'.rawurlencode($branch), $token, ['sha'=>$newCommit['sha'],'force'=>false]);
        respond(200, ['ok'=>true,'changed_files'=>$changedPaths,'commit'=>$newCommit['sha'],
            'repository_url'=>$repoData['html_url'] ?? ('https://github.com/'.$owner.'/'.$repoName),
            'preview'=>[
                'storefront_html'=>build_static_preview($sourceFiles,'resources/views/store/index.blade.php'),
                'admin_html'=>build_static_preview($sourceFiles,'resources/views/admin/dashboard.blade.php')
            ],
            'note'=>'Revision committed to the private draft branch only. No merge or deployment was performed.']);
    } catch (Throwable $e) {
        respond(502, ['error'=>'Could not revise the private draft: '.$e->getMessage()]);
    }
}
if ($path === '/api/approve' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $expected = envv('NIRA_BUILDER_TOKEN');
    $given = $_SERVER['HTTP_X_NIRA_TOKEN'] ?? '';
    if ($expected === '' || !hash_equals($expected, $given)) respond(401, ['error'=>'Unauthorized']);
    $raw = file_get_contents('php://input', false, null, 0, 4000);
    $input = is_string($raw) ? json_decode($raw, true) : null;
    $owner = is_array($input) ? (string)($input['owner'] ?? '') : '';
    $repoName = is_array($input) ? (string)($input['repo'] ?? '') : '';
    $branch = is_array($input) ? (string)($input['branch'] ?? 'nira-generated') : 'nira-generated';
    if (!preg_match('/^[A-Za-z0-9-]{1,39}$/', $owner) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $repoName) || $branch !== 'nira-generated') respond(422, ['error'=>'Invalid draft project reference.']);
    try {
        $token = envv('NIRA_GITHUB_TOKEN');
        if ($token === '') respond(503, ['error'=>'GitHub approval is not configured.']);
        $repoData = github_api('GET', '/repos/'.$owner.'/'.$repoName, $token);
        if (empty($repoData['private'])) respond(403, ['error'=>'Only private draft repositories can be approved.']);
        $ref = github_api('GET', '/repos/'.$owner.'/'.$repoName.'/git/ref/heads/'.rawurlencode($branch), $token);
        $default = (string)($repoData['default_branch'] ?? 'main');
        $existing = github_api('GET', '/repos/'.$owner.'/'.$repoName.'/pulls?state=open&head='.rawurlencode($owner.':'.$branch).'&base='.rawurlencode($default), $token);
        if (isset($existing[0]['html_url'])) {
            respond(200, ['ok'=>true,'pull_request_url'=>$existing[0]['html_url'],'already_created'=>true]);
        }
        $pr = github_api('POST', '/repos/'.$owner.'/'.$repoName.'/pulls', $token, [
            'title'=>'Review generated Laravel e-commerce store','head'=>$branch,'base'=>$default,
            'body'=>"The user approved creating this review pull request. Review source and CI before merging or deploying. Generated code was not executed by the builder.",
        ]);
        respond(200, ['ok'=>true,'pull_request_url'=>$pr['html_url'] ?? null]);
    } catch (Throwable $e) { respond(502, ['error'=>'Could not create the approved review pull request: '.$e->getMessage()]); }
}
if ($path === '/download.php') {
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) { http_response_code(404); exit; }
    if (s3_configured()) {
        try {
            $bytes = s3_object_request('GET', 'generated/'.$id.'/project.zip');
            header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="website.zip"');
            header('Content-Length: '.strlen($bytes)); echo $bytes; exit;
        } catch (Throwable $e) { http_response_code(404); exit; }
    }
    $storageRoot = rtrim(envv('NIRA_STORAGE_PATH', __DIR__.'/../storage/generated'), '/');
    $dir = $storageRoot.'/'.$id;
    $file = $dir.'/project.zip';
    if (!is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="website.zip"');
    header('Content-Length: '.filesize($file)); readfile($file); exit;
}
respond(404, ['error'=>'Not found']);
