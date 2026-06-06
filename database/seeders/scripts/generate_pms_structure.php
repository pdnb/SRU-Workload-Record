<?php

/**
 * Parse PMS HTML export into a PHP data array for WorkloadStructureSeeder.
 *
 * Usage: php database/seeders/scripts/generate_pms_structure.php
 */

use App\Services\PmsWorkloadHtmlParser;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$baseDir = dirname(__DIR__);
$sourcePath = $baseDir.'/sources/pms.html';
$outputPath = $baseDir.'/data/pms_computer_officer_2569.php';

if (! file_exists($sourcePath)) {
    fwrite(STDERR, "Source file not found: {$sourcePath}\n");
    exit(1);
}

$html = file_get_contents($sourcePath);
$parser = new PmsWorkloadHtmlParser;
$structure = $parser->parse($html);
$stats = $parser->stats($structure);

$groupCount = $stats['groups'];
$mainCount = $stats['mains'];
$workloadCount = $stats['workloads'];

$export = var_export($structure, true);
$content = "<?php\n\n// Generated from database/seeders/sources/pms.html\n// Groups: {$groupCount}, Mains: {$mainCount}, Workloads: {$workloadCount}\n\nreturn {$export};\n";

file_put_contents($outputPath, $content);

echo "Generated: {$outputPath}\n";
echo "Groups: {$groupCount}, Mains: {$mainCount}, Workloads: {$workloadCount}\n";

if ($groupCount !== 10 || $mainCount !== 27 || $workloadCount !== 151) {
    fwrite(STDERR, "WARNING: Expected 10/27/151 but got {$groupCount}/{$mainCount}/{$workloadCount}\n");
    exit(1);
}
