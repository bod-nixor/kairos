<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/api/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = db();

$sql = "
SELECT mi.module_item_id, mi.item_type, mi.entity_id
FROM lms_module_items mi
LEFT JOIN lms_lessons l ON mi.item_type='lesson' AND l.lesson_id=mi.entity_id AND l.deleted_at IS NULL
LEFT JOIN lms_resources r ON mi.item_type IN ('file','video','link') AND r.resource_id=mi.entity_id AND r.deleted_at IS NULL
LEFT JOIN lms_assignments a ON mi.item_type='assignment' AND a.assignment_id=mi.entity_id AND a.deleted_at IS NULL
LEFT JOIN lms_assessments q ON mi.item_type='quiz' AND q.assessment_id=mi.entity_id AND q.deleted_at IS NULL
WHERE (mi.item_type='lesson' AND l.lesson_id IS NULL)
   OR (mi.item_type IN ('file','video','link') AND r.resource_id IS NULL)
   OR (mi.item_type='assignment' AND a.assignment_id IS NULL)
   OR (mi.item_type='quiz' AND q.assessment_id IS NULL)
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "No orphaned module items found.\n";
    exit(0);
}

$deleteStmt = $pdo->prepare('DELETE FROM lms_module_items WHERE module_item_id = :id');
foreach ($rows as $row) {
    if (!$dryRun) {
        $deleteStmt->execute([':id' => (int)$row['module_item_id']]);
    }
    echo sprintf(
        "%s orphan module_item_id=%d type=%s entity_id=%d\n",
        $dryRun ? 'Would remove' : 'Removed',
        (int)$row['module_item_id'],
        (string)$row['item_type'],
        (int)$row['entity_id']
    );
}
