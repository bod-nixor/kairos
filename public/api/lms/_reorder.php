<?php
declare(strict_types=1);

/**
 * @return list<int>
 */
function lms_reorder_positive_ids(array $input, string $field): array
{
    $rawIds = $input[$field] ?? null;
    if (!is_array($rawIds) || $rawIds === []) {
        throw new InvalidArgumentException($field . ' must be a non-empty array');
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        if (is_int($rawId)) {
            $id = $rawId;
        } elseif (is_string($rawId) && preg_match('/^[1-9][0-9]*$/D', $rawId) === 1) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new InvalidArgumentException($field . ' contains an out-of-range ID');
            }
        } else {
            throw new InvalidArgumentException($field . ' must contain positive integers');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException($field . ' must contain positive integers');
        }
        $ids[] = $id;
    }

    if (count(array_unique($ids, SORT_NUMERIC)) !== count($ids)) {
        throw new InvalidArgumentException($field . ' must not contain duplicates');
    }

    return $ids;
}

/**
 * @param list<int> $left
 * @param list<int> $right
 */
function lms_reorder_same_id_set(array $left, array $right): bool
{
    if (count($left) !== count($right)) {
        return false;
    }

    sort($left, SORT_NUMERIC);
    sort($right, SORT_NUMERIC);
    return $left === $right;
}

/**
 * @param list<int> $currentPositions
 * @return list<int>
 */
function lms_reorder_temporary_positions(array $currentPositions, int $count): array
{
    if ($count <= 0) {
        throw new InvalidArgumentException('Reorder count must be positive');
    }

    $maxPosition = $currentPositions === [] ? 0 : max($currentPositions);
    $unsignedIntMax = 4294967295;
    if ($maxPosition < 0 || $maxPosition > ($unsignedIntMax - $count - 1)) {
        throw new OverflowException('No safe temporary position range remains');
    }

    $firstTemporaryPosition = $maxPosition + 1;
    return range($firstTemporaryPosition, $firstTemporaryPosition + $count - 1);
}
