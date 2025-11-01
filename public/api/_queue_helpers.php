<?php
declare(strict_types=1);

/**
 * Fetch queue snapshot information for the provided queue id.
 *
 * @return array{queue_id:int,count:int,position:?int,participants:array<int,array{user_id:int,name:string}>,avg_handle_minutes:?float,eta_minutes:int,basis_factor:int,avg_used:float}
 */
function get_queue_snapshot(PDO $pdo, int $queueId, ?int $currentUserId = null): array
{
    $queueStmt = $pdo->prepare('SELECT queue_id, avg_handle_minutes FROM queues_info WHERE queue_id = :qid');
    $queueStmt->execute([':qid' => $queueId]);
    $queueRow = $queueStmt->fetch(PDO::FETCH_ASSOC);
    if (!$queueRow) {
        throw new RuntimeException('Queue not found');
    }

    $entryStmt = $pdo->prepare(
        'SELECT qe.user_id, qe.timestamp, u.name
         FROM queue_entries qe
         LEFT JOIN users u ON u.user_id = qe.user_id
         WHERE qe.queue_id = :qid
         ORDER BY qe.timestamp ASC, qe.user_id ASC'
    );
    $entryStmt->execute([':qid' => $queueId]);
    $entries = $entryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $participants = [];
    $position = null;
    foreach ($entries as $idx => $entry) {
        $uid = isset($entry['user_id']) ? (int)$entry['user_id'] : null;
        if ($uid === null) {
            continue;
        }
        $participants[] = [
            'user_id' => $uid,
            'name'    => isset($entry['name']) ? (string)$entry['name'] : ''
        ];
        if ($currentUserId !== null && $uid === $currentUserId && $position === null) {
            $position = $idx + 1;
        }
    }

    $count = count($participants);

    $rawAvg = isset($queueRow['avg_handle_minutes']) ? (float)$queueRow['avg_handle_minutes'] : 0.0;
    if (!is_finite($rawAvg) || $rawAvg <= 0) {
        $rawAvg = 0.0;
    }

    $avgUsed = $rawAvg > 0 ? $rawAvg : 7.0;
    $factor = $position ?? $count;
    if ($factor < 0) {
        $factor = 0;
    }

    $eta = $factor > 0 ? (int)ceil($avgUsed * $factor) : 0;

    return [
        'queue_id'           => (int)$queueRow['queue_id'],
        'count'              => $count,
        'position'           => $position,
        'participants'       => $participants,
        'avg_handle_minutes' => $rawAvg > 0 ? $rawAvg : null,
        'eta_minutes'        => $eta,
        'basis_factor'       => $factor,
        'avg_used'           => $avgUsed,
    ];
}
