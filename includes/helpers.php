<?php
/**
 * Shared helper functions (single source of truth).
 *
 * Previously these were copy-pasted across 17+ files; they now live here and
 * are loaded with:
 *   require_once __DIR__ . '/../includes/helpers.php';
 */

/**
 * Parse a School Year selector value ("2024-2025" or a single "2024")
 * into [start_date, end_date] covering July 1 -> June 30.
 * Returns [null, null] when the input is empty or not recognised.
 */
function parse_school_year_range($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return [null, null];
    if (preg_match('/^(20\d{2})\s*-\s*(20\d{2})$/', $raw, $m)) {
        $startY = (int)$m[1];
        $endY = (int)$m[2];
        if ($endY === $startY + 1) {
            return [sprintf('%04d-07-01', $startY), sprintf('%04d-06-30', $endY)];
        }
    } elseif (preg_match('/^(19|20)\d{2}$/', $raw)) {
        $startY = (int)$raw;
        return [sprintf('%04d-07-01', $startY), sprintf('%04d-06-30', $startY + 1)];
    }
    return [null, null];
}

/**
 * Escape + trim a request value for (legacy) interpolated SQL.
 */
function safe($conn, $v)
{
    return $conn->real_escape_string(trim($v));
}

/**
 * Compact pagination window: at most 5 page numbers, sliding so the
 * current page stays near the middle (clamped at the edges).
 */
function pagination_window($current, $total)
{
    $current = max(1, (int)$current);
    $total = max(1, (int)$total);
    if ($total <= 5) {
        return range(1, $total);
    }
    $start = max(1, min($current - 2, $total - 4));
    return range($start, $start + 4);
}
