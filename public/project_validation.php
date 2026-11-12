<?php
declare(strict_types=1);

/** Reject a DB transaction closure that reads the outer request without capturing it. */
function nira_validate_transaction_closure_captures(string $path,string $source): void {
    $pattern='/DB::transaction\s*\(\s*function\s*\(([^)]*)\)\s*(?:use\s*\(([^)]*)\))?\s*\{(.*?)\}\s*\)/s';
    if(!preg_match_all($pattern,$source,$matches,PREG_SET_ORDER)) return;
    $requestVariable=chr(36).'request';
    foreach($matches as $match) {
        if(str_contains($match[3]??'',$requestVariable)
            && !str_contains($match[1]??'',$requestVariable)
            && !str_contains($match[2]??'',$requestVariable)) {
            throw new RuntimeException('Transaction closure in '.$path.' uses uncaptured variable '.$requestVariable.'. Add it to use(...) or pass it as a parameter.');
        }
    }
}

/** Infer Laravel's conventional table name for a model class. */
function nira_infer_model_table_name(string $className): string {
    $snake=preg_replace_callback('/[A-Z]/',static fn($m)=>'_'.strtolower($m[0]),$className);
    $snake=ltrim((string)$snake,'_');
    if(preg_match('/[^aeiou]y$/',$snake)) return substr($snake,0,-1).'ies';
    if(preg_match('/(s|x|z|ch|sh)$/',$snake)) return $snake.'es';
    return $snake.'s';
}

/** Compare model fillable/cast fields with migration columns. */
function nira_validate_model_schema_columns(array $files): void {
    $tables=[];
    $columnMethods=['char','string','tinyText','text','mediumText','longText','integer','tinyInteger','smallInteger','mediumInteger','bigInteger','unsignedInteger','unsignedTinyInteger','unsignedSmallInteger','unsignedMediumInteger','unsignedBigInteger','float','double','decimal','unsignedDecimal','boolean','date','dateTime','dateTimeTz','time','timeTz','timestamp','timestampTz','year','json','jsonb','binary','uuid','ulid','enum','set','foreignId','foreignUuid','ipAddress','macAddress','geometry','point','lineString','polygon','multiPoint','multiLineString','multiPolygon','geometryCollection'];
    foreach($files as $path=>$source) {
        if(!str_starts_with($path,'database/migrations/')||!str_ends_with(strtolower($path),'.php')) continue;
        if(!preg_match_all('/Schema::(?:create|table)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*\{(.*?)\}\s*\)\s*;/s',$source,$blocks,PREG_SET_ORDER)) continue;
        foreach($blocks as $block) {
            $tableName=$block[1]; $tables[$tableName]=$tables[$tableName]??[];
            if(!preg_match_all('/\$table\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(\s*(?:[\'"]([^\'"]+)[\'"])?/',$block[2],$columns,PREG_SET_ORDER)) continue;
            foreach($columns as $column) {
                $method=$column[1]; $columnName=$column[2]??'';
                if(in_array($method,['id','bigIncrements','increments'],true)) $tables[$tableName]['id']=true;
                elseif(in_array($method,['timestamps','timestampsTz'],true)) { $tables[$tableName]['created_at']=true; $tables[$tableName]['updated_at']=true; }
                elseif(in_array($method,['softDeletes','softDeletesTz'],true)) $tables[$tableName]['deleted_at']=true;
                elseif($method==='rememberToken') $tables[$tableName]['remember_token']=true;
                elseif(in_array($method,['morphs','nullableMorphs','uuidMorphs','nullableUuidMorphs','ulidMorphs','nullableUlidMorphs'],true)&&$columnName!=='') {
                    $tables[$tableName][$columnName.'_id']=true; $tables[$tableName][$columnName.'_type']=true;
                } elseif(in_array($method,$columnMethods,true)&&$columnName!=='') $tables[$tableName][$columnName]=true;
            }
        }
    }
    foreach($files as $path=>$source) {
        if(!preg_match('#^app/Models/([A-Za-z_][A-Za-z0-9_]*)\.php$#',$path,$model)) continue;
        $className=$model[1]; $tableName=nira_infer_model_table_name($className);
        if(preg_match('/\$table\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/',$source,$explicitTable)) $tableName=$explicitTable[1];
        if(!isset($tables[$tableName])) throw new RuntimeException('Model '.$className.' has no matching migration table: '.$tableName.'.');
        $modelFields=[];
        if(preg_match('/\$fillable\s*=\s*\[(.*?)\]\s*;/s',$source,$fillableMatch)
            && preg_match_all('/[\'"]([^\'"]+)[\'"]/', $fillableMatch[1], $fieldMatches)) {
            foreach($fieldMatches[1] as $field) $modelFields[$field]=true;
        }
        if(preg_match('/\$casts\s*=\s*\[(.*?)\]\s*;/s',$source,$castsMatch)
            && preg_match_all('/[\'"]([^\'"]+)[\'"]/', $castsMatch[1], $castMatches)) {
            foreach($castMatches[1] as $field) $modelFields[$field]=true;
        }
        foreach(array_keys($modelFields) as $field) {
            if(!isset($tables[$tableName][$field])) {
                throw new RuntimeException('Model '.$className.' references missing database column '.$tableName.'.'.$field.'. Add it in an up migration or correct the model field.');
            }
        }
    }
}

/** Validate generated PHP, App imports, route actions, and static view references. */
function nira_validate_project_source_contracts(array $files): void {
    $symbols=[]; $methodsByPath=[];
    foreach($files as $path=>$source) {
        $isPhp=$path==='artisan'||(str_ends_with(strtolower($path),'.php')&&!str_ends_with(strtolower($path),'.blade.php'));
        if(!$isPhp) continue;
        try { $tokens=token_get_all($source,TOKEN_PARSE); }
        catch(ParseError $error) { throw new RuntimeException('PHP syntax error in '.$path.' at line '.$error->getLine().': '.$error->getMessage()); }
        nira_validate_transaction_closure_captures($path,$source);
        $namespace='';
        if(preg_match('/(?m)^\s*namespace\s+([^;{]+)\s*[;{]/',$source,$nm)) $namespace=trim($nm[1]," \t\n\r\\");
        $methods=[]; $count=count($tokens);
        for($i=0;$i<$count;$i++) {
            $token=$tokens[$i]; if(!is_array($token)) continue;
            if(in_array($token[0],[T_CLASS,T_INTERFACE,T_TRAIT,T_ENUM],true)) {
                $previous=$i-1;
                while($previous>=0&&is_array($tokens[$previous])&&in_array($tokens[$previous][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $previous--;
                if($previous>=0&&is_array($tokens[$previous])&&in_array($tokens[$previous][0],[T_DOUBLE_COLON,T_NEW],true)) continue;
                for($j=$i+1;$j<$count;$j++) {
                    if(is_array($tokens[$j])&&$tokens[$j][0]===T_STRING) {
                        $fqcn=($namespace!==''?$namespace.'\\':'').$tokens[$j][1];
                        if(str_starts_with(strtolower($fqcn),'app\\')) {
                            $key=strtolower($fqcn);
                            if(isset($symbols[$key])&&$symbols[$key]!==$path) throw new RuntimeException('Duplicate PHP class declaration '.$fqcn.'.');
                            $symbols[$key]=$path;
                            $expected='app/'.str_replace('\\','/',substr($fqcn,4)).'.php';
                            if($expected!==$path) throw new RuntimeException('PSR-4 class path mismatch: '.$fqcn.' must be in '.$expected.', found in '.$path.'.');
                        }
                        break;
                    }
                    if(is_string($tokens[$j])&&$tokens[$j]==='{') break;
                }
            }
            if($token[0]===T_FUNCTION) {
                for($j=$i+1;$j<$count;$j++) {
                    if(is_array($tokens[$j])&&$tokens[$j][0]===T_STRING) { $methods[strtolower($tokens[$j][1])]=true; break; }
                    if(is_string($tokens[$j])&&in_array($tokens[$j],['(',';','{'],true)) break;
                }
            }
        }
        $methodsByPath[$path]=$methods;
    }
    foreach($files as $path=>$source) {
        if(!str_ends_with(strtolower($path),'.php')) continue;
        $imports=[];
        if(preg_match_all('/(?m)^\s*use\s+(App\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/',$source,$matches,PREG_SET_ORDER)) {
            foreach($matches as $match) {
                $fqcn=trim($match[1],'\\'); $alias=$match[2]??'';
                $short=$alias!==''?$alias:substr($fqcn,strrpos($fqcn,'\\')+1);
                $imports[strtolower($short)]=$fqcn;
                if(!isset($symbols[strtolower($fqcn)])) throw new RuntimeException('Unresolved App import in '.$path.': '.$fqcn.'.');
            }
        }
        if(preg_match_all('/(?m)^\s*use\s+(App\\\\[A-Za-z0-9_\\\\]*)\\\\\{([^}]+)\}\s*;/',$source,$groups,PREG_SET_ORDER)) {
            foreach($groups as $group) foreach(explode(',',$group[2]) as $part) {
                $bits=preg_split('/\s+as\s+/i',trim($part)); $fqcn=rtrim($group[1],'\\').'\\'.trim($bits[0]," \t\n\r\\");
                $alias=$bits[1]??''; $short=$alias!==''?trim($alias):substr($fqcn,strrpos($fqcn,'\\')+1);
                $imports[strtolower($short)]=$fqcn;
                if(!isset($symbols[strtolower($fqcn)])) throw new RuntimeException('Unresolved grouped App import in '.$path.': '.$fqcn.'.');
            }
        }
        if(str_starts_with($path,'routes/')) {
            if(preg_match_all('/\[\s*([A-Za-z_][A-Za-z0-9_]*)::class\s*,\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\]/s',$source,$actions,PREG_SET_ORDER)) {
                foreach($actions as $action) {
                    $alias=strtolower($action[1]); if(!isset($imports[$alias])) continue;
                    $fqcn=$imports[$alias]; $classPath=$symbols[strtolower($fqcn)]??null;
                    if($classPath===null) throw new RuntimeException('Route controller is missing: '.$fqcn.'.');
                    if(!isset($methodsByPath[$classPath][strtolower($action[2])])) throw new RuntimeException('Route method '.$action[2].'() does not exist in '.$fqcn.' ('.$classPath.').');
                }
            }
            if(preg_match_all('/Route::(apiResource|resource)\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*([A-Za-z_][A-Za-z0-9_]*)::class/s',$source,$resources,PREG_SET_ORDER)) {
                foreach($resources as $resource) {
                    $alias=strtolower($resource[2]); if(!isset($imports[$alias])) continue;
                    $fqcn=$imports[$alias]; $classPath=$symbols[strtolower($fqcn)]??null;
                    if($classPath===null) throw new RuntimeException('Resource controller is missing: '.$fqcn.'.');
                    $requiredMethods=$resource[1]==='apiResource'?['index','store','show','update','destroy']:['index','create','store','show','edit','update','destroy'];
                    foreach($requiredMethods as $method) if(!isset($methodsByPath[$classPath][$method])) throw new RuntimeException('Resource controller '.$fqcn.' is missing '.$method.'() required by '.$path.'.');
                }
            }
        }
    }
    foreach($files as $sourcePath=>$source) {
        $viewNames=[];
        if(preg_match_all('/\bview\s*\(\s*[\'"]([^\'"]+)[\'"]/',$source,$m)) $viewNames=array_merge($viewNames,$m[1]);
        if(preg_match_all('/@(?:extends|include|each|component)\s*\(\s*[\'"]([^\'"]+)[\'"]/',$source,$m)) $viewNames=array_merge($viewNames,$m[1]);
        if(preg_match_all('/@include(?:When|Unless)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]/',$source,$m)) $viewNames=array_merge($viewNames,$m[1]);
        foreach(array_unique($viewNames) as $viewName) {
            if($viewName===''||str_contains($viewName,'::')||str_contains($viewName,'*')) continue;
            $viewPath='resources/views/'.str_replace('.','/',$viewName).'.blade.php';
            if(!array_key_exists($viewPath,$files)) throw new RuntimeException('Referenced Blade view is missing: '.$viewPath.' (referenced by '.$sourcePath.').');
        }
    }
    nira_validate_model_schema_columns($files);
    nira_validate_unique_project_paths($files);
    nira_validate_ui_interaction_contracts($files);
    nira_validate_feature_test_quality($files);
}

/** Re-open the archive and compare every stored entry with the validated source map. */
function verify_generated_project_zip(string $zipPath,array $files): string {
    if(!is_file($zipPath)||filesize($zipPath)===0) throw new RuntimeException('Generated ZIP is missing or empty.');
    $zip=new ZipArchive(); $opened=$zip->open($zipPath,ZipArchive::CHECKCONS);
    if($opened!==true) throw new RuntimeException('Generated ZIP failed archive integrity verification (code '.$opened.').');
    try {
        if($zip->numFiles!==count($files)) throw new RuntimeException('Generated ZIP entry count mismatch: expected '.count($files).', found '.$zip->numFiles.'.');
        foreach($files as $path=>$expectedContent) {
            $actualContent=$zip->getFromName($path);
            if(!is_string($actualContent)) throw new RuntimeException('Generated ZIP is missing required entry: '.$path.'.');
            if(!hash_equals(hash('sha256',$expectedContent),hash('sha256',$actualContent))) throw new RuntimeException('Generated ZIP content verification failed for: '.$path.'.');
        }
    } finally { $zip->close(); }
    $hash=hash_file('sha256',$zipPath);
    if(!is_string($hash)||strlen($hash)!==64) throw new RuntimeException('Could not calculate final ZIP checksum.');
    return $hash;
}


/** Reject path aliases that collide on common case-insensitive filesystems. */
function nira_validate_unique_project_paths(array $files): void {
    $seen = [];
    foreach (array_keys($files) as $path) {
        if (!is_string($path) || $path === '' || str_contains($path, chr(92)) || str_starts_with($path, '/')) {
            throw new RuntimeException('Generated project contains an invalid normalized file path.');
        }
        $parts = explode('/', $path);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new RuntimeException('Generated project contains an ambiguous file path: '.$path.'.');
        }
        $key = strtolower(implode('/', $parts));
        if (isset($seen[$key]) && $seen[$key] !== $path) {
            throw new RuntimeException('Generated project contains case-insensitive duplicate paths: '.$seen[$key].' and '.$path.'.');
        }
        $seen[$key] = $path;
    }
}

/** Reject unsafe or oversized files before a generation batch is persisted as a checkpoint. */
function nira_validate_checkpoint_file_map(array $files): void {
    if (count($files) > 2000) throw new RuntimeException('Checkpoint exceeds the 2000-file safety limit.');
    $totalBytes = 0;
    foreach ($files as $path => $content) {
        if (!is_string($path) || !is_string($content) || $path === '' || str_contains($path, chr(92)) || str_starts_with($path, '/')) {
            throw new RuntimeException('Checkpoint contains an invalid file path or non-text file content.');
        }
        $parts = explode('/', $path);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)
            || in_array('vendor', $parts, true) || in_array('node_modules', $parts, true)
            || in_array('.git', $parts, true) || in_array('storage', $parts, true)) {
            throw new RuntimeException('Checkpoint contains an unsafe file path: '.$path.'.');
        }
        $base = strtolower((string)end($parts));
        if (($base === '.env' || str_starts_with($base, '.env.')) && $base !== '.env.example') {
            throw new RuntimeException('Checkpoint contains a real environment file: '.$path.'.');
        }
        if (!preg_match('/[.](php|css|js|json|md|ya?ml|xml|txt|htaccess|sh)$/i', $path)
            && !in_array($base, ['artisan','dockerfile','.gitignore','.env.example'], true)) {
            throw new RuntimeException('Checkpoint contains an unsupported or binary file: '.$path.'.');
        }
        if (strlen($content) > 524288) throw new RuntimeException('Checkpoint file exceeds the 512 KB limit: '.$path.'.');
        $totalBytes += strlen($content);
        if ($totalBytes > 50000000) throw new RuntimeException('Checkpoint exceeds the 50 MB total source limit.');
        if (preg_match('/(?:AIza[0-9A-Za-z_-]{30,}|gh[pousr]_[A-Za-z0-9_]{30,}|GOCSPX-[A-Za-z0-9_-]{20,}|[0-9]{8,}:[A-Za-z0-9_-]{25,}|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----)/', $content)) {
            throw new RuntimeException('Checkpoint contains a possible secret in '.$path.'.');
        }
    }
    nira_validate_unique_project_paths($files);
}

/** A checkpoint can resume only when its exact saved prompt hash matches. */
function nira_checkpoint_matches_request(array $checkpoint, string $promptHash): bool {
    $savedHash = $checkpoint['prompt_hash'] ?? null;
    return is_string($savedHash) && $savedHash !== '' && hash_equals($promptHash, $savedHash);
}

/**
 * Catch common UI defects that syntax and route checks cannot see:
 * unsafe placeholder links/actions and state-changing forms without CSRF/method spoofing.
 */
function nira_validate_ui_interaction_contracts(array $files): void {
    foreach ($files as $path => $source) {
        if (!str_starts_with($path, 'resources/views/') || !str_ends_with(strtolower($path), '.blade.php')) continue;
        $clean = preg_replace('/\\{\\{--.*?--\\}\\}/s', '', $source) ?? $source;
        $clean = preg_replace('/<!--.*?-->/s', '', $clean) ?? $clean;
        if (preg_match('/\\b(?:href|action|formaction)\\s*=\\s*(\\x22|\\x27)\\s*(?:#\\s*\\1|javascript\\s*:)/i', $clean)) {
            throw new RuntimeException('Blade view contains a non-functional placeholder link or form action in '.$path.'. Replace it with a real route or a button with an implemented action.');
        }
        if (preg_match('/\\bon(?:click|submit)\\s*=\\s*(\\x22|\\x27)\\s*\\1/i', $clean)) {
            throw new RuntimeException('Blade view contains an empty event handler in '.$path.'.');
        }
        if (!preg_match_all('/<form\\b([^>]*)>(.*?)<\\/form\\s*>/is', $clean, $forms, PREG_SET_ORDER)) continue;
        foreach ($forms as $form) {
            $attributes = $form[1] ?? '';
            $body = $form[2] ?? '';
            if (preg_match('/\\bmethod\\s*=\\s*(\\x22|\\x27)(post|put|patch|delete)\\1/i', $attributes)) {
                if (!preg_match('/@csrf|csrf_field\\s*\\(|name\\s*=\\s*(\\x22|\\x27)_token\\1/i', $body)) {
                    throw new RuntimeException('State-changing form is missing CSRF protection in '.$path.'.');
                }
                if (preg_match('/\\bmethod\\s*=\\s*(\\x22|\\x27)(put|patch|delete)\\1/i', $attributes)
                    && !preg_match('/@method\\s*\\(|method_field\\s*\\(|name\\s*=\\s*(\\x22|\\x27)_method\\1/i', $body)) {
                    throw new RuntimeException('State-changing form is missing Laravel method spoofing in '.$path.'.');
                }
            }
        }
    }
}

/** Ensure generated feature tests contain executable test cases and multiple assertions. */
function nira_validate_feature_test_quality(array $files): void {
    foreach ($files as $path => $source) {
        if (!preg_match('#^tests/Feature/.+\\.php$#i', $path)) continue;
        $withoutComments = preg_replace('~/\\*.*?\\*/|(?m)//[^\\r\\n]*|(?m)^\\s*#.*$~s', '', $source) ?? $source;
        $hasTest = preg_match('/\\bfunction\\s+(?:test[A-Za-z0-9_]*|[A-Za-z_][A-Za-z0-9_]*Test[A-Za-z0-9_]*)\\s*\\(|\\b(?:it|test)\\s*\\(/i', $withoutComments) === 1;
        preg_match_all('/(?:->|::)assert[A-Za-z0-9_]*\\s*\\(|\\bassert[A-Z][A-Za-z0-9_]*\\s*\\(/', $withoutComments, $assertions);
        if (!$hasTest || count($assertions[0] ?? []) < 2) {
            throw new RuntimeException('Feature test is too shallow or has no executable test case: '.$path.'. Add a real test case with at least two meaningful assertions.');
        }
    }
}
