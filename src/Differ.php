<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator;

/**
 * Minimal dependency-free line differ (LCS) producing unified-style hunks.
 * Good enough for audit output; not a general-purpose diff tool.
 */
final class Differ
{
    /**
     * @return string unified-style diff body ('' when identical),
     *                capped at $maxLines output lines
     */
    public function diff(string $expected, string $actual, int $maxLines = 80): string
    {
        if ($expected === $actual) {
            return '';
        }
        $a = explode("\n", $expected);
        $b = explode("\n", $actual);

        // For very large files fall back to a summary rather than an O(n*m) LCS.
        if (count($a) * count($b) > 4_000_000) {
            return sprintf("(files differ; too large for inline diff: %d vs %d lines)\n", count($a), count($b));
        }

        $ops = $this->lcsOps($a, $b);
        $out = [];
        $context = 2;
        $n = count($ops);
        // Mark which op indexes to print: changes + N context lines around them
        $print = array_fill(0, $n, false);
        foreach ($ops as $i => [$op]) {
            if ($op !== ' ') {
                for ($j = max(0, $i - $context); $j <= min($n - 1, $i + $context); $j++) {
                    $print[$j] = true;
                }
            }
        }
        $lastPrinted = -2;
        foreach ($ops as $i => [$op, $line]) {
            if (!$print[$i]) {
                continue;
            }
            if ($i > $lastPrinted + 1) {
                $out[] = '@@';
            }
            $lastPrinted = $i;
            $out[] = $op . $line;
            if (count($out) >= $maxLines) {
                $out[] = sprintf('... (diff truncated at %d lines)', $maxLines);
                break;
            }
        }
        return implode("\n", $out) . "\n";
    }

    /**
     * Classic DP LCS producing an op list: [' ', line] keep, ['-', line] only
     * in expected, ['+', line] only in actual.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{string, string}>
     */
    private function lcsOps(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);
        // dp[i][j] = LCS length of a[i:] vs b[j:]
        $dp = array_fill(0, $m + 1, null);
        for ($i = $m; $i >= 0; $i--) {
            $row = array_fill(0, $n + 1, 0);
            $next = $dp[$i + 1] ?? array_fill(0, $n + 1, 0);
            for ($j = $n - 1; $j >= 0; $j--) {
                $row[$j] = ($i < $m && $a[$i] === $b[$j])
                    ? $next[$j + 1] + 1
                    : max($next[$j] ?? 0, $row[$j + 1]);
            }
            $dp[$i] = $row;
        }
        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $m && $j < $n) {
            if ($a[$i] === $b[$j]) {
                $ops[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif (($dp[$i + 1][$j] ?? 0) >= ($dp[$i][$j + 1] ?? 0)) {
                $ops[] = ['-', $a[$i]];
                $i++;
            } else {
                $ops[] = ['+', $b[$j]];
                $j++;
            }
        }
        for (; $i < $m; $i++) {
            $ops[] = ['-', $a[$i]];
        }
        for (; $j < $n; $j++) {
            $ops[] = ['+', $b[$j]];
        }
        return $ops;
    }
}
