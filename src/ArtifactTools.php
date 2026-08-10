<?php

declare(strict_types=1);

namespace Ecotech\Chat;

/**
 * Local (non-CRM) tools for charts and printable PDF reports.
 */
final class ArtifactTools
{
    /**
     * @param array<string, mixed> $params
     * @return array{ok: bool, chart?: array<string, mixed>, error?: string}
     */
    public static function createChart(array $params): array
    {
        $type = strtolower((string) ($params['type'] ?? ''));
        if (!in_array($type, ['bar', 'pie', 'line'], true)) {
            return ['ok' => false, 'error' => 'type must be bar, pie, or line'];
        }

        $labels = $params['labels'] ?? null;
        $datasets = $params['datasets'] ?? null;
        if (!is_array($labels) || $labels === []) {
            return ['ok' => false, 'error' => 'labels array is required'];
        }
        if (!is_array($datasets) || $datasets === []) {
            return ['ok' => false, 'error' => 'datasets array is required'];
        }

        $normalizedDatasets = [];
        foreach ($datasets as $ds) {
            if (!is_array($ds) || !isset($ds['data']) || !is_array($ds['data'])) {
                continue;
            }
            $normalizedDatasets[] = [
                'label' => (string) ($ds['label'] ?? ''),
                'data' => array_map(static fn ($v) => is_numeric($v) ? 0 + $v : 0, $ds['data']),
            ];
        }

        if ($normalizedDatasets === []) {
            return ['ok' => false, 'error' => 'datasets must include numeric data arrays'];
        }

        return [
            'ok' => true,
            'chart' => [
                'id' => 'chart_' . bin2hex(random_bytes(4)),
                'type' => $type,
                'title' => (string) ($params['title'] ?? ''),
                'labels' => array_map('strval', $labels),
                'datasets' => $normalizedDatasets,
            ],
            'message' => 'Chart created. It will render in the chat UI.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{ok: bool, report?: array<string, mixed>, error?: string}
     */
    public static function createReport(array $params): array
    {
        $title = trim((string) ($params['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'error' => 'title is required'];
        }

        $sections = $params['sections'] ?? null;
        if (!is_array($sections) || $sections === []) {
            return ['ok' => false, 'error' => 'sections array is required'];
        }

        $normalized = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $type = strtolower((string) ($section['type'] ?? 'text'));
            if (!in_array($type, ['text', 'table', 'metrics', 'list'], true)) {
                $type = 'text';
            }
            $normalized[] = [
                'heading' => (string) ($section['heading'] ?? ''),
                'type' => $type,
                'content' => $section['content'] ?? '',
                'columns' => is_array($section['columns'] ?? null) ? $section['columns'] : null,
                'rows' => is_array($section['rows'] ?? null) ? $section['rows'] : null,
                'items' => is_array($section['items'] ?? null) ? $section['items'] : null,
            ];
        }

        if ($normalized === []) {
            return ['ok' => false, 'error' => 'No valid sections provided'];
        }

        return [
            'ok' => true,
            'report' => [
                'id' => 'report_' . bin2hex(random_bytes(4)),
                'title' => $title,
                'subtitle' => (string) ($params['subtitle'] ?? ''),
                'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto')))->format('Y-m-d H:i:s T'),
                'sections' => $normalized,
            ],
            'message' => 'Report ready. The UI will open it in a new tab for print/download.',
            'row_counts' => array_map(
                static function (array $section): array {
                    return [
                        'heading' => $section['heading'],
                        'type' => $section['type'],
                        'row_count' => is_array($section['rows'] ?? null) ? count($section['rows']) : null,
                    ];
                },
                $normalized,
            ),
        ];
    }
}
