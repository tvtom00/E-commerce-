<?php
declare(strict_types=1);

require __DIR__.'/../public/project_validation.php';

function fixture_project(): array {
    return [
        'app/Http/Controllers/Controller.php' => "<?php\nnamespace App\\Http\\Controllers;\nabstract class Controller {}",
        'app/Http/Controllers/StoreController.php' => "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Http\\Controllers\\Controller;\nclass StoreController extends Controller { public function index() { return view('store.index'); } }",
        'routes/web.php' => "<?php\nuse App\\Http\\Controllers\\StoreController;\nRoute::get('/', [StoreController::class, 'index']);",
        'resources/views/store/index.blade.php' => '@extends("layouts.app")' . "\n" . '@includeIf("partials.optional")',
        'resources/views/layouts/app.blade.php' => '<html><body>ok</body></html>',
    ];
}

function expect_project_failure(array $files, string $expected): void {
    try { nira_validate_project_source_contracts($files); }
    catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), $expected)) throw new RuntimeException('Expected '.$expected.'; got '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected validation failure containing '.$expected.'.');
}

$valid=fixture_project();
nira_validate_project_source_contracts($valid);

$schemaFixture=$valid;
$schemaFixture['database/migrations/2025_01_01_000000_create_users_table.php']="<?php\nuse Illuminate\\Support\\Facades\\Schema;\nSchema::create('users', function (\$table) { \$table->id(); \$table->string('name'); \$table->string('email'); \$table->timestamps(); });";
$schemaFixture['app/Models/User.php']="<?php\nnamespace App\\Models;\nclass User { protected \$fillable = ['name', 'email']; }";
nira_validate_project_source_contracts($schemaFixture);
$schemaBroken=$schemaFixture;
$schemaBroken['app/Models/User.php']="<?php\nnamespace App\\Models;\nclass User { protected \$fillable = ['name', 'email', 'is_active']; }";
expect_project_failure($schemaBroken,'missing database column users.is_active');

$broken=$valid;
$broken['app/Policies/OrderPolicy.php']="<?php\nnamespace App\\Policies;\nclass OrderPolicy { public function broken( { }";
expect_project_failure($broken,'PHP syntax error');

$broken=$valid;
$broken['routes/web.php']="<?php\nuse App\\Http\\Controllers\\MissingController;\nRoute::get('/', [MissingController::class, 'index']);";
expect_project_failure($broken,'Unresolved App import');

$broken=$valid;
$broken['routes/web.php']="<?php\nuse App\\Http\\Controllers\\StoreController;\nRoute::get('/', [StoreController::class, 'missing']);";
expect_project_failure($broken,'Route method missing()');

$broken=$valid;
$broken['app/Http/Controllers/StoreController.php']="<?php\nnamespace App\\Http\\Controllers;\nuse App\\Http\\Controllers\\Controller;\nclass StoreController extends Controller { public function index() { return DB::transaction(function () { return \$request->user(); }); } }";
expect_project_failure($broken,'uncaptured variable $request');

$broken=$valid;
$broken['app/Http/Controllers/StoreController.php']="<?php\nnamespace App\\Http\\Controllers;\nuse App\\Http\\Controllers\\Controller;\nclass StoreController extends Controller { public function index() { return view('store.missing'); } }";
expect_project_failure($broken,'Referenced Blade view is missing');

$broken=$valid;
$broken['app/Http/Controllers/StoreController.php']="<?php\nnamespace App\\Http\\Controllers;\nclass WrongName {}";
expect_project_failure($broken,'PSR-4 class path mismatch');


$uiValid = $valid;
$uiValid['resources/views/store/form.blade.php'] = '<form method="POST" action="/contact">@csrf<input name="message"><button type="submit">Send</button></form>';
nira_validate_project_source_contracts($uiValid);

$uiBroken = $valid;
$uiBroken['resources/views/store/form.blade.php'] = '<form method="POST" action="/contact"><input name="message"><button>Send</button></form>';
expect_project_failure($uiBroken, 'missing CSRF protection');

$uiBroken = $valid;
$uiBroken['resources/views/store/form.blade.php'] = '<a href="#">Shop now</a>';
expect_project_failure($uiBroken, 'non-functional placeholder link');

$pathBroken = $valid;
$pathBroken['resources/views/Store/index.blade.php'] = '<p>duplicate path</p>';
expect_project_failure($pathBroken, 'case-insensitive duplicate paths');

$testQuality = $valid;
$testQuality['tests/Feature/StorefrontTest.php'] = "<?php class StorefrontTest { public function test_storefront_loads() { \$this->assertTrue(true); } }";
expect_project_failure($testQuality, 'too shallow');

$testQuality['tests/Feature/StorefrontTest.php'] = "<?php class StorefrontTest { public function test_storefront_loads() { \$this->assertTrue(true); \$this->assertSame(200, 200); } }";
nira_validate_project_source_contracts($testQuality);

if(!class_exists(ZipArchive::class)) throw new RuntimeException('ZIP extension is required for this test.');
$temp=sys_get_temp_dir().'/nira-zip-check-'.bin2hex(random_bytes(5));
if(!mkdir($temp,0700,true)) throw new RuntimeException('Could not create test directory.');
$zipPath=$temp.'/project.zip'; $zip=new ZipArchive();
if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Could not create test ZIP.');
foreach($valid as $path=>$content) $zip->addFromString($path,$content);
$zip->close();
$hash=verify_generated_project_zip($zipPath,$valid);
if(!preg_match('/^[a-f0-9]{64}$/',$hash)) throw new RuntimeException('ZIP checksum was not generated.');
$wrong=$valid; $wrong['resources/views/store/index.blade.php']='different';
try { verify_generated_project_zip($zipPath,$wrong); throw new RuntimeException('ZIP content mismatch was not rejected.'); }
catch(RuntimeException $error) { if(!str_contains($error->getMessage(),'content verification failed')) throw $error; }
@unlink($zipPath); @rmdir($temp);
echo "Project validation tests passed.\n";
