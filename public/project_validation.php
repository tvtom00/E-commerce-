<?php
declare(strict_types=1);


/** Catch the common closure-scope bug where DB::transaction uses an outer variable without capturing it. */
function nira_validate_transaction_closure_captures(string $path,array $tokens): void {
    $count=count($tokens);
    $superglobals=['this','_SERVER','_GET','_POST','_FILES','_COOKIE','_SESSION','_REQUEST','_ENV','GLOBALS','argc','argv'];
    $assignmentIds=array_filter([
        defined('T_PLUS_EQUAL')?T_PLUS_EQUAL:null, defined('T_MINUS_EQUAL')?T_MINUS_EQUAL:null,
        defined('T_MUL_EQUAL')?T_MUL_EQUAL:null, defined('T_DIV_EQUAL')?T_DIV_EQUAL:null,
        defined('T_CONCAT_EQUAL')?T_CONCAT_EQUAL:null, defined('T_MOD_EQUAL')?T_MOD_EQUAL:null,
        defined('T_AND_EQUAL')?T_AND_EQUAL:null, defined('T_OR_EQUAL')?T_OR_EQUAL:null,
        defined('T_XOR_EQUAL')?T_XOR_EQUAL:null, defined('T_SL_EQUAL')?T_SL_EQUAL:null,
        defined('T_SR_EQUAL')?T_SR_EQUAL:null, defined('T_COALESCE_EQUAL')?T_COALESCE_EQUAL:null,
    ],static fn($value)=>$value!==null);
    for($i=0;$i<$count;$i++) {
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION) continue;
        $prefix='';
        for($k=max(0,$i-18);$k<$i;$k++) $prefix.=is_array($tokens[$k])?$tokens[$k][1]:$tokens[$k];
        if(!preg_match('/transaction\s*\(\s*(?:static\s*)?$/i',$prefix)) continue;
        $cursor=$i+1;
        while($cursor<$count&&is_array($tokens[$cursor])&&in_array($tokens[$cursor][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $cursor++;
        if(isset($tokens[$cursor])&&$tokens[$cursor]==='&') $cursor++;
        if(isset($tokens[$cursor])&&is_array($tokens[$cursor])&&$tokens[$cursor][0]===T_STRING) continue;
        if(!isset($tokens[$cursor])||$tokens[$cursor]!=='(') continue;
        $depth=0; $parameterTokens=[]; $closed=false;
        for(;$cursor<$count;$cursor++) {
            $token=$tokens[$cursor]; if($token==='(') $depth++;
            if($depth>0) $parameterTokens[]=$token;
            if($token===')'&&--$depth===0) { $closed=true; $cursor++; break; }
        }
        if(!$closed) continue;
        $allowed=[];
        foreach($parameterTokens as $token) if(is_array($token)&&$token[0]===T_VARIABLE) $allowed[$token[1]]=true;
        while($cursor<$count&&is_array($tokens[$cursor])&&in_array($tokens[$cursor][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $cursor++;
        if(isset($tokens[$cursor])&&is_array($tokens[$cursor])&&$tokens[$cursor][0]===T_USE) {
            $cursor++;
            while($cursor<$count&&is_array($tokens[$cursor])&&in_array($tokens[$cursor][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $cursor++;
            if(($tokens[$cursor]??null)==='(') {
                $useDepth=0;
                for(;$cursor<$count;$cursor++) {
                    $token=$tokens[$cursor]; if($token==='(') $useDepth++;
                    if($useDepth>0&&is_array($token)&&$token[0]===T_VARIABLE) $allowed[$token[1]]=true;
                    if($token===')'&&--$useDepth===0) { $cursor++; break; }
                }
            }
        }
        while($cursor<$count&&$tokens[$cursor]!=='{') $cursor++;
        if($cursor>=$count) continue;
        $bodyStart=$cursor; $bodyDepth=0; $bodyTokens=[];
        for(;$cursor<$count;$cursor++) {
            $token=$tokens[$cursor];
            if($token==='{') $bodyDepth++;
            if($bodyDepth>0) $bodyTokens[]=$token;
            if($token==='}'&&--$bodyDepth===0) break;
        }
        $bodyCount=count($bodyTokens);
        for($j=0;$j<$bodyCount;$j++) {
            $token=$bodyTokens[$j];
            if(!is_array($token)||$token[0]!==T_VARIABLE) continue;
            $name=$token[1];
            if(isset($allowed[$name])||in_array(ltrim($name,'
function nira_validate_project_source_contracts(array $files): void {
    $symbols=[]; $methodsByPath=[];
    foreach($files as $path=>$source) {
        $isPhp=$path==='artisan'||(str_ends_with(strtolower($path),'.php')&&!str_ends_with(strtolower($path),'.blade.php'));
        if(!$isPhp) continue;
        try { $tokens=token_get_all($source,TOKEN_PARSE); }
        catch(ParseError $error) { throw new RuntimeException('PHP syntax error in '.$path.' at line '.$error->getLine().': '.$error->getMessage()); }
        nira_validate_transaction_closure_captures($path,$tokens);
        $namespace='';
        if(preg_match('/(?m)^\s*namespace\s+([^;{]+)\s*[;{]/',$source,$nm)) $namespace=trim($nm[1]," \t\n\r\\");
        $methods=[]; $count=count($tokens);
        for($i=0;$i<$count;$i++) {
            $token=$tokens[$i]; if(!is_array($token)) continue;
            if(in_array($token[0],[T_CLASS,T_INTERFACE,T_TRAIT,T_ENUM],true)) {
                $previous=$i-1;
                while($previous>=0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $previous--;
                if($previous>=0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0],[T_DOUBLE_COLON,T_NEW],true)) continue;
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
        if(preg_match_all('/@(?:extends|include|includeIf|each|component)\s*\(\s*[\'"]([^\'"]+)[\'"]/',$source,$m)) $viewNames=array_merge($viewNames,$m[1]);
        if(preg_match_all('/@include(?:When|Unless)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]/',$source,$m)) $viewNames=array_merge($viewNames,$m[1]);
        foreach(array_unique($viewNames) as $viewName) {
            if($viewName===''||str_contains($viewName,'::')||str_contains($viewName,'*')) continue;
            $viewPath='resources/views/'.str_replace('.','/',$viewName).'.blade.php';
            if(!array_key_exists($viewPath,$files)) throw new RuntimeException('Referenced Blade view is missing: '.$viewPath.' (referenced by '.$sourcePath.').');
        }
    }
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
),$superglobals,true)) continue;
            $previous=$j-1;
            while($previous>=0&&is_array($bodyTokens[$previous])&&in_array($bodyTokens[$previous][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $previous--;
            $next=$j+1;
            while($next<$bodyCount&&is_array($bodyTokens[$next])&&in_array($bodyTokens[$next][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $next++;
            $isLocal=false;
            if(($bodyTokens[$next]??null)==='='|| (isset($bodyTokens[$next])&&is_array($bodyTokens[$next])&&in_array($bodyTokens[$next][0],$assignmentIds,true))) $isLocal=true;
            if($previous>=0&&is_array($bodyTokens[$previous])&&in_array($bodyTokens[$previous][0],[T_AS,T_GLOBAL,T_STATIC,T_INC,T_DEC],true)) $isLocal=true;
            if(($bodyTokens[$next]??null)==='++'||($bodyTokens[$next]??null)==='--') $isLocal=true;
            if($isLocal) $allowed[$name]=true;
        }
        foreach($bodyTokens as $token) {
            if(!is_array($token)||$token[0]!==T_VARIABLE) continue;
            $name=$token[1];
            if(isset($allowed[$name])||in_array(ltrim($name,'
function nira_validate_project_source_contracts(array $files): void {
    $symbols=[]; $methodsByPath=[];
    foreach($files as $path=>$source) {
        $isPhp=$path==='artisan'||(str_ends_with(strtolower($path),'.php')&&!str_ends_with(strtolower($path),'.blade.php'));
        if(!$isPhp) continue;
        try { $tokens=token_get_all($source,TOKEN_PARSE); }
        catch(ParseError $error) { throw new RuntimeException('PHP syntax error in '.$path.' at line '.$error->getLine().': '.$error->getMessage()); }
        $namespace='';
        if(preg_match('/(?m)^\s*namespace\s+([^;{]+)\s*[;{]/',$source,$nm)) $namespace=trim($nm[1]," \t\n\r\\");
        $methods=[]; $count=count($tokens);
        for($i=0;$i<$count;$i++) {
            $token=$tokens[$i]; if(!is_array($token)) continue;
            if(in_array($token[0],[T_CLASS,T_INTERFACE,T_TRAIT,T_ENUM],true)) {
                $previous=$i-1;
                while($previous>=0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $previous--;
                if($previous>=0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0],[T_DOUBLE_COLON,T_NEW],true)) continue;
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
        if(preg_match_all('/@(?:extends|include|includeIf|each|component)\s*\(\s*[\'"]([^\'"]+)[\'"]/',$source,$m)) $viewNames=array_merge($viewNames,$m[1]);
        if(preg_match_all('/@include(?:When|Unless)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]/',$source,$m)) $viewNames=array_merge($viewNames,$m[1]);
        foreach(array_unique($viewNames) as $viewName) {
            if($viewName===''||str_contains($viewName,'::')||str_contains($viewName,'*')) continue;
            $viewPath='resources/views/'.str_replace('.','/',$viewName).'.blade.php';
            if(!array_key_exists($viewPath,$files)) throw new RuntimeException('Referenced Blade view is missing: '.$viewPath.' (referenced by '.$sourcePath.').');
        }
    }
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
),$superglobals,true)) continue;
            throw new RuntimeException('Transaction closure in '.$path.' uses uncaptured variable '.$name.'. Add it to the closure use(...) list or pass it as a parameter.');
        }
    }
}

/** Static project checks; generated application code is parsed but never executed. */
function nira_validate_project_source_contracts(array $files): void {
    $symbols=[]; $methodsByPath=[];
    foreach($files as $path=>$source) {
        $isPhp=$path==='artisan'||(str_ends_with(strtolower($path),'.php')&&!str_ends_with(strtolower($path),'.blade.php'));
        if(!$isPhp) continue;
        try { $tokens=token_get_all($source,TOKEN_PARSE); }
        catch(ParseError $error) { throw new RuntimeException('PHP syntax error in '.$path.' at line '.$error->getLine().': '.$error->getMessage()); }
        $namespace='';
        if(preg_match('/(?m)^\s*namespace\s+([^;{]+)\s*[;{]/',$source,$nm)) $namespace=trim($nm[1]," \t\n\r\\");
        $methods=[]; $count=count($tokens);
        for($i=0;$i<$count;$i++) {
            $token=$tokens[$i]; if(!is_array($token)) continue;
            if(in_array($token[0],[T_CLASS,T_INTERFACE,T_TRAIT,T_ENUM],true)) {
                $previous=$i-1;
                while($previous>=0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $previous--;
                if($previous>=0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0],[T_DOUBLE_COLON,T_NEW],true)) continue;
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
        if(preg_match_all('/@(?:extends|include|includeIf|each|component)\s*\(\s*[\'"]([^\'"]+)[\'"]/',$source,$m)) $viewNames=array_merge($viewNames,$m[1]);
        if(preg_match_all('/@include(?:When|Unless)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]/',$source,$m)) $viewNames=array_merge($viewNames,$m[1]);
        foreach(array_unique($viewNames) as $viewName) {
            if($viewName===''||str_contains($viewName,'::')||str_contains($viewName,'*')) continue;
            $viewPath='resources/views/'.str_replace('.','/',$viewName).'.blade.php';
            if(!array_key_exists($viewPath,$files)) throw new RuntimeException('Referenced Blade view is missing: '.$viewPath.' (referenced by '.$sourcePath.').');
        }
    }
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
