<?php
declare(strict_types=1);

/** Developer-owned code templates. Each source is a complete Task Club code tree. */
function tcTemplateCatalog(): array
{
    return [
        'standard' => [
            'name' => 'Standard Task Club',
            'description' => 'The existing Task Club with its current tasks, participants and rewards features.',
            'directory' => 'mini apps/Task Club',
        ],
        'iran-map' => [
            'name' => 'Iran Map',
            'description' => 'Task Club with an Iran map side slide, the event logo and a back button.',
            'directory' => 'mini apps/taskclub-templates/iran-map',
        ],
    ];
}

function tcTemplateResolve(string $id, ?string $projectRoot = null): array
{
    $catalog = tcTemplateCatalog();
    if (!isset($catalog[$id])) {
        throw new InvalidArgumentException('The selected Task Club template is unavailable.');
    }
    $template = $catalog[$id];
    $root = realpath($projectRoot ?? dirname(__DIR__, 2));
    $source = $root === false ? false : realpath($root . '/' . $template['directory']);
    if ($source === false || !str_starts_with(str_replace('\\', '/', $source), str_replace('\\', '/', $root) . '/')
        || !is_file($source . '/TCM.php') || !is_file($source . '/TC Panel.php')) {
        throw new RuntimeException('The selected Task Club template code is missing or incomplete.');
    }
    return ['id' => $id, 'source' => $source] + $template;
}

function tcTemplateSelection(string $type, string $id): array
{
    if (!in_array($type, ['standard', 'custom'], true)) {
        throw new InvalidArgumentException('Select a valid Task Club type.');
    }
    $template = tcTemplateResolve($type === 'standard' ? 'standard' : $id);
    return ['type' => $type, 'templateId' => $template['id']];
}

function tcTemplateReadSelection(PDO $pdo, string $code): array
{
    $selection = tcInstanceReadData($pdo, $code, 'code_template', null);
    if ($selection === null) return ['type' => 'standard', 'templateId' => 'standard'];
    if (!is_array($selection) || !in_array($selection['type'] ?? '', ['standard', 'custom'], true)
        || !is_string($selection['templateId'] ?? null) || $selection['templateId'] === '') {
        throw new RuntimeException('Invalid saved Task Club template selection.');
    }
    return $selection;
}
