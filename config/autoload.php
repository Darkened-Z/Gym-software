<?php
/**
 * Simple Autoloader
 */

spl_autoload_register(function ($class) {
    $baseDir = __DIR__ . '/../';
    $classFile = str_replace('\\', '/', $class) . '.php';
    
    // Try app directory first
    $file = $baseDir . 'app/' . $classFile;
    if (file_exists($file)) {
        require_once $file;
        return;
    }
    
    // Try root directory
    $file = $baseDir . $classFile;
    if (file_exists($file)) {
        require_once $file;
        return;
    }
});

