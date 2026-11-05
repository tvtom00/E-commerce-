<?php
declare(strict_types=1);

const MAX_REQUEST_BYTES = 12000;
const MAX_OUTPUT_FILES = 80;
const MAX_FILE_BYTES = 200000;

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
}catch(e){s.textContent='সমস্যা: '+e.message}finally{b.disabled=false}};
</script></html>
HTML;
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/health') respond(200, ['ok'=>true,'service'=>'nira-website-builder']);
if ($path === '/' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') ui();
if ($path === '/api/generate' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $expected = envv('NIRA_BUILDER_TOKEN');
    if ($expected !== '') {
        $given = $_SERVER['HTTP_X_NIRA_TOKEN'] ?? '';
        if (!hash_equals($expected, $given)) respond(401, ['error'=>'Unauthorized']);
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
    $system = "You are a senior web engineer. Return ONLY valid JSON with shape {\"files\": [{\"path\":\"relative/path\",\"content\":\"complete file content\"}]}. Generate a complete responsive, accessible website project based on the request. Include README.md and all necessary source/assets as text files. Do not include binaries, secrets, API keys, deployment credentials, or parent-directory paths. Keep it self-contained and production-conscious. Never claim payments are live without merchant credentials. Limit to 80 files and 200KB per file.";
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
    if (!class_exists(ZipArchive::class)) respond(500, ['error'=>'ZIP extension is not available.']);
    $id = bin2hex(random_bytes(12)); $base = __DIR__.'/../storage/generated/'.$id;
    if (!mkdir($base, 0750, true)) respond(500, ['error'=>'Could not create project workspace.']);
    $zipPath = $base.'/'.($name ?: 'website').'.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) respond(500, ['error'=>'Could not create ZIP.']);
    try {
        foreach ($result['files'] as $file) {
            if (!is_array($file) || !isset($file['path'],$file['content']) || !is_string($file['content'])) continue;
            $rel = clean_name((string)$file['path']);
            if (strlen($file['content']) > MAX_FILE_BYTES) throw new RuntimeException('A generated file exceeded the size limit.');
            $zip->addFromString($rel, $file['content']);
        }
        $zip->close();
    } catch (Throwable $e) { $zip->close(); respond(422, ['error'=>$e->getMessage()]); }
    respond(200, ['ok'=>true,'name'=>$name ?: 'website','download'=>'/download.php?id='.$id]);
}
if ($path === '/download.php') {
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) { http_response_code(404); exit; }
    $dir = __DIR__.'/../storage/generated/'.$id;
    $files = glob($dir.'/*.zip') ?: [];
    if (!$files || !is_file($files[0])) { http_response_code(404); exit; }
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.basename($files[0]).'"');
    header('Content-Length: '.filesize($files[0])); readfile($files[0]); exit;
}
respond(404, ['error'=>'Not found']);
