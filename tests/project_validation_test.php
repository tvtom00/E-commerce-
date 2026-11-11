<?php
declare(strict_types=1);

require __DIR__.'/../public/project_validation.php';

function fixture_project(): array {
    return [
        'app/Http/Controllers/Controller.php' => '<?php namespace App\\Http\\Controllers; abstract class Controller {}',
        'app/Http/Controllers/StoreController.php' => '<?php namespace App\\Http\\Controllers; use App\\Http\\Controllers\\Controller; class StoreController extends Controller { public function index() { return view("store.index"); } }',
        'routes/web.php' => '<?php use App\\Http\\Controllers\\StoreController; Route::get("/", [StoreController::class, "index"]);',
        'resources/views/store/index.blade.php' => '@extends("layouts.app")',
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

$broken=$valid;
$broken['app/Policies/OrderPolicy.php']='<?php namespace App\\Policies; class OrderPolicy { public function broken( { }';
expect_project_failure($broken,'PHP syntax error');

$broken=$valid;
$broken['routes/web.php']='<?php use App\\Http\\Controllers\\MissingController; Route::get("/", [MissingController::class, "index"]);';
expect_project_failure($broken,'Unresolved App import');

$broken=$valid;
$broken['routes/web.php']='<?php use App\\Http\\Controllers\\StoreController; Route::get("/", [StoreController::class, "missing"]);';
expect_project_failure($broken,'Route method missing()');

$broken=$valid;
$broken['app/Http/Controllers/StoreController.php']='<?php namespace App\\Http\\Controllers; use App\\Http\\Controllers\\Controller; class StoreController extends Controller { public function index() { return view("store.missing"); } }';
expect_project_failure($broken,'Referenced Blade view is missing');

$broken=$valid;
$broken['app/Http/Controllers/StoreController.php']='<?php namespace App\\Http\\Controllers; class WrongName {}';
expect_project_failure($broken,'PSR-4 class path mismatch');

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
