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
        'composer.json','artisan','bootstrap/app.php','public/index.php','public/.htaccess',
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
    $branch = 'nira/generated-store';
    github_api('POST', '/repos/'.$owner.'/'.$repoName.'/git/refs', $token, [
        'ref'=>'refs/heads/'.$branch,'sha'=>$commit['sha'],
    ]);
    $pr = github_api('POST', '/repos/'.$owner.'/'.$repoName.'/pulls', $token, [
        'title'=>'Review generated Laravel e-commerce store',
        'head'=>$branch,'base'=>$default,
        'body'=>"Nira generated this customer-specific Laravel e-commerce project. Review the source and CI results before merging or deploying.\n\nNo generated code was executed by the builder.",
    ]);
    return ['repository_url'=>$repo['html_url'] ?? ('https://github.com/'.$owner.'/'.$repoName),
        'pull_request_url'=>$pr['html_url'] ?? null,'branch'=>$branch];
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
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); exit; }
    respond(200, ['ok'=>true,'service'=>'nira-website-builder']);
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
    $payload = ['contents'=>[['parts'=>[['text'=>$system."\nUSER REQUEST:\n".$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json','temperature'=>0.3]];
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($apiKey));
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>180]);
    $body = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($body === false) respond(502, ['error'=>'AI request failed: '.$err]);
    $answer = json_decode($body, true);
    if ($code < 200 || $code >= 300) respond(502, ['error'=>'AI provider error','detail'=>$answer['error']['message'] ?? 'Unknown provider error']);
    $text = $answer['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $result = json_decode($text, true);
    if (!is_array($result) || !isset($result['files']) || !is_array($result['files'])) respond(502, ['error'=>'AI returned an invalid project format.']);
    if (count($result['files']) < 1 || count($result['files']) > MAX_OUTPUT_FILES) respond(502, ['error'=>'Generated project file count is outside the allowed limit.']);
    try { $validatedFiles = validate_laravel_project($result['files']); }
    catch (Throwable $e) { respond(422, ['error'=>'Generated output did not pass Laravel validation: '.$e->getMessage()]); }
    if (!class_exists(ZipArchive::class)) respond(500, ['error'=>'ZIP extension is not available.']);
    $id = bin2hex(random_bytes(12)); $storageRoot = envv('NIRA_STORAGE_PATH', __DIR__.'/../storage/generated');
    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) respond(500, ['error'=>'Could not initialize generated-project storage.']);
    $base = rtrim($storageRoot, '/').'/'.$id;
    if (!mkdir($base, 0750, true)) respond(500, ['error'=>'Could not create project workspace.']);
    $zipPath = $base.'/'.($name ?: 'website').'.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) respond(500, ['error'=>'Could not create ZIP.']);
    try {
        foreach ($validatedFiles as $rel => $source) {
            $zip->addFromString($rel, $source);
        }
        $zip->close();
    } catch (Throwable $e) { $zip->close(); respond(422, ['error'=>$e->getMessage()]); }
        $response = ['ok'=>true,'name'=>$name ?: 'website','download'=>'/download.php?id='.$id,'files'=>count($validatedFiles)];
    $githubToken = envv('NIRA_GITHUB_TOKEN');
    if ($githubToken !== '') {
        try { $response['github'] = publish_private_github_project($name ?: 'website', $validatedFiles, $githubToken); }
        catch (Throwable $e) { $response['github_error'] = $e->getMessage(); }
    } else {
        $response['github_notice'] = 'Private GitHub publishing is not configured; ZIP output is available.';
    }
    respond(200, $response);
}
if ($path === '/download.php') {
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) { http_response_code(404); exit; }
    $storageRoot = rtrim(envv('NIRA_STORAGE_PATH', __DIR__.'/../storage/generated'), '/');
    $dir = $storageRoot.'/'.$id;
    $files = glob($dir.'/*.zip') ?: [];
    if (!$files || !is_file($files[0])) { http_response_code(404); exit; }
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.basename($files[0]).'"');
    header('Content-Length: '.filesize($files[0])); readfile($files[0]); exit;
}
respond(404, ['error'=>'Not found']);
