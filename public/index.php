<?php
declare(strict_types=1);

const MAX_REQUEST_BYTES = 12000;
const MAX_OUTPUT_FILES = 100;
const MAX_FILE_BYTES = 120000;
const MAX_TOTAL_BYTES = 3000000;

function respond(int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        'Dockerfile','composer.json','artisan','bootstrap/app.php','public/index.php','public/.htaccess',
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
        if (!preg_match('/\\.(php|blade\\.php|css|js|json|md|ya?ml|xml|txt|htaccess)$/i', $path)
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
    foreach (['FROM php:', 'pdo_mysql', 'pdo_pgsql', 'composer install', 'EXPOSE 10000', 'artisan migrate'] as $dockerCheck) {
        if (stripos($dockerfile, $dockerCheck) === false) {
            throw new RuntimeException('Render Dockerfile is missing required runtime configuration: '.$dockerCheck);
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
    $pr = github_api('POST', '/repos/'.$owner.'/'.$repoName.'/pulls', $token, [
        'title'=>'Review generated Laravel e-commerce store',
        'head'=>$branch,'base'=>$default,
        'body'=>"Nira generated this customer-specific Laravel e-commerce project. Review the source and CI results before merging or deploying.\n\nNo generated code was executed by the builder.",
    ]);
    return ['repository_url'=>$repo['html_url'] ?? ('https://github.com/'.$owner.'/'.$repoName),
        'pull_request_url'=>$pr['html_url'] ?? null,'branch'=>$branch,
        'download_url'=>'https://github.com/'.$owner.'/'.$repoName.'/archive/refs/heads/'.$branch.'.zip'];
}

function ui(): never {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html><html lang="bn"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nira AI Website Builder</title>
<style>
:root{color-scheme:dark;font-family:system-ui}body{margin:0;background:#0b1020;color:#eef2ff;display:grid;place-items:center;min-height:100vh;padding:20px}
main{width:min(760px,100%);background:#151d33;border:1px solid #293654;border-radius:22px;padding:28px;box-sizing:border-box}
h1{margin:0 0 8px;font-size:clamp(26px,5vw,38px)}p{color:#b9c5dc;line-height:1.6}
textarea,input{width:100%;box-sizing:border-box;background:#0b1328;color:#fff;border:1px solid #344464;border-radius:12px;padding:14px;font:inherit;margin:8px 0 16px}
textarea{min-height:160px;resize:vertical}button{background:#6d7cff;color:white;border:0;border-radius:12px;padding:13px 20px;font-weight:700;font-size:16px;cursor:pointer}
button:disabled{opacity:.5}#status{white-space:pre-wrap;margin-top:18px;color:#cbd5e1}.small{font-size:13px;color:#92a2bf}
</style><main><h1>Nira AI Website Builder</h1><p>আপনার ওয়েবসাইটের ধরন, পেজ, ডিজাইন ও ফিচার লিখুন। Nira AI একটি প্রজেক্ট ZIP তৈরি করবে।</p>
<label>ওয়েবসাইটের নির্দেশনা</label><textarea id="prompt" placeholder="যেমন: আধুনিক e-commerce website, product catalog, cart, admin dashboard, Bangla/English..."></textarea>
<label>প্রজেক্টের নাম</label><input id="name" value="my-store" maxlength="40">\n<label>Access token (যদি সার্ভারে সেট করা থাকে)</label><input id="token" type="password" autocomplete="off" placeholder="NIRA_BUILDER_TOKEN">
<button id="go">Generate website ZIP</button><div id="status"></div><p class="small">API key ও access token সার্ভারের Environment Variables-এ রাখতে হবে; এখানে লিখবেন না। Generated code আলাদা ZIP হিসেবে দেওয়া হয়, সার্ভারে স্বয়ংক্রিয়ভাবে execute করা হয় না।</p></main>
<script>
const b=document.getElementById('go'),s=document.getElementById('status');
b.onclick=async()=>{b.disabled=true;s.textContent='প্রজেক্ট তৈরি হচ্ছে...';try{
const r=await fetch('/api/generate',{method:'POST',headers:{'Content-Type':'application/json',...(document.getElementById('token').value?{'X-Nira-Token':document.getElementById('token').value}:{})},body:JSON.stringify({prompt:document.getElementById('prompt').value,name:document.getElementById('name').value})});
const j=await r.json();if(!r.ok)throw Error(j.error||'Generation failed');
s.innerHTML='';const a=document.createElement('a');a.href=j.download;a.textContent='Download '+j.name+'.zip';a.style='display:inline-block;margin-top:12px;color:#aab7ff;font-weight:bold';s.append('ওয়েবসাইট ZIP প্রস্তুত: ',a);
if(j.github?.repository_url){const p=document.createElement('p');const repo=document.createElement('a');repo.href=j.github.repository_url;repo.target='_blank';repo.rel='noopener';repo.textContent='Open private GitHub repository';p.append(repo);if(j.github.pull_request_url){p.append(' · ');const pr=document.createElement('a');pr.href=j.github.pull_request_url;pr.target='_blank';pr.rel='noopener';pr.textContent='Review pull request';p.append(pr)}s.append(p)}
if(j.github_error){const p=document.createElement('p');p.textContent='GitHub publishing issue: '+j.github_error;s.append(p)}else if(j.github_notice){const p=document.createElement('p');p.textContent=j.github_notice;s.append(p)}
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
    foreach ($generationBatches as $batch) {
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
    }
    $result = ['files'=>[]];
    foreach ($allGeneratedFiles as $generatedPath=>$generatedContent) {
        $result['files'][] = ['path'=>$generatedPath,'content'=>$generatedContent];
    }
    if (!is_array($result) || !isset($result['files']) || !is_array($result['files'])) respond(502, ['error'=>'AI returned an invalid project format.']);
    if (count($result['files']) < 1 || count($result['files']) >= MAX_OUTPUT_FILES) respond(502, ['error'=>'Generated project file count is outside the allowed limit after deployment files are added.']);
    // Always provide a deterministic Render-compatible runtime file; never rely on Gemini
    // remembering infrastructure boilerplate in a large source-generation response.
    $hasDockerfile = false;
    foreach ($result['files'] as $generatedFile) {
        if (is_array($generatedFile) && ($generatedFile['path'] ?? '') === 'Dockerfile') { $hasDockerfile = true; break; }
    }
    if (!$hasDockerfile) {
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
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache database \
    && chown -R www-data:www-data storage bootstrap/cache database
ENV APP_ENV=production APP_DEBUG=false DB_CONNECTION=mysql
EXPOSE 10000
CMD ["sh", "-c", "php artisan migrate --force && apache2-foreground"]
DOCKER;
        $result['files'][] = ['path'=>'Dockerfile','content'=>$dockerContent];
    }
    try { $validatedFiles = validate_laravel_project($result['files']); }
    catch (Throwable $e) { respond(422, ['error'=>'Generated output did not pass Laravel validation: '.$e->getMessage()]); }
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
    respond(200, $response);
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
