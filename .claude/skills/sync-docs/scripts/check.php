<?php

declare(strict_types=1);

/**
 * Checks docs/ against the code. Run from the framework's root:
 *
 *     php .claude/skills/sync-docs/scripts/check.php
 *
 * Prints each problem and exits with 1 when there is any.
 */

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\Provider;

$root = getcwd();
if (!is_file($root . '/composer.json') || !is_dir($root . '/src') || !is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run this from the framework's root, the folder with composer.json, after `composer install`.\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

$docs = $root . '/docs';
$index = 'documentation.md';
$problems = [];

/**
 * A heading's anchor, as the preview page and GitHub both make it.
 */
function anchor(string $heading): string
{
    $text = strtolower(trim((string) preg_replace('/`|\*|\[([^\]]*)\]\([^)]*\)/', '$1', $heading)));
    $text = (string) preg_replace('/[^a-z0-9 _-]/', '', $text);
    return str_replace(' ', '-', $text);
}

/**
 * A page without its code blocks, so that what is inside one is not read as prose.
 */
function prose(string $markdown): string
{
    return (string) preg_replace('/^(`{3,}|~{3,}).*?^\1\s*$/ms', '', $markdown);
}

// ---- Pages ---------------------------------------------------------------

$pages = [];
foreach (glob($docs . '/*.md') ?: [] as $file) {
    $pages[basename($file)] = (string) file_get_contents($file);
}
if (!isset($pages[$index])) {
    fwrite(STDERR, "docs/{$index} is missing; it lists the pages.\n");
    exit(1);
}
preg_match_all('/\]\(([a-z0-9-]+\.md)\)/', $pages[$index], $listed);
foreach (array_diff(array_keys($pages), $listed[1], [$index]) as $unlisted) {
    $problems[] = "docs/{$unlisted} is not listed in docs/{$index}.";
}

$anchors = [];
foreach ($pages as $name => $markdown) {
    preg_match_all('/^#{1,4} +(.+)$/m', prose($markdown), $headings);
    $anchors[$name] = array_map(anchor(...), $headings[1]);
    if ($name !== $index && !str_starts_with($markdown, '# ')) {
        $problems[] = "docs/{$name} does not start with its title, a line beginning \"# \".";
    }
    if ($name !== $index && !in_array('introduction', $anchors[$name], true)) {
        $problems[] = "docs/{$name} has no \"## Introduction\", which says what the page's subject is and when to use it.";
    }
}

// ---- Links ---------------------------------------------------------------

foreach ($pages as $name => $markdown) {
    preg_match_all('/\]\(([^)\s]+)\)/', prose($markdown), $links);
    foreach (array_unique($links[1]) as $link) {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $link) === 1) {
            continue;
        }
        [$page, $fragment] = explode('#', $link, 2) + [1 => ''];
        $page = $page === '' ? $name : $page;
        if (!isset($pages[$page])) {
            if (!is_file($docs . '/' . $page)) {
                $problems[] = "docs/{$name} links to {$link}, and there is no docs/{$page}.";
            }
            continue;
        }
        if ($fragment !== '' && !in_array($fragment, $anchors[$page], true)) {
            $problems[] = "docs/{$name} links to {$link}, and docs/{$page} has no heading with that anchor.";
        }
    }
}

// ---- Names ---------------------------------------------------------------

foreach ($pages as $name => $markdown) {
    // A name starts with a capital after the backslash, which "Crab\n" in a string does not.
    preg_match_all('/\bCrab\\\\[A-Z][A-Za-z_\\\\]*[A-Za-z_]/', $markdown, $names);
    foreach (array_unique($names[0]) as $class) {
        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class) && !is_dir($root . '/src/' . str_replace('\\', '/', substr($class, 5)))) {
            $problems[] = "docs/{$name} names {$class}, and there is no such class, interface or namespace.";
        }
    }
}

// ---- What the code offers ------------------------------------------------

$app = new App(new Config());
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!str_ends_with($file->getFilename(), 'Provider.php') || str_contains($file->getPathname(), '/Contracts/')) {
        continue;
    }
    $class = 'Crab\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root . '/src/'), -4));
    if (class_exists($class) && is_subclass_of($class, Provider::class)) {
        $app->register($class);
    }
}

$everything = implode("\n", $pages);

// Settings declared with defaults(), and those the glue reads with a default of its own.
$settings = [];
foreach ($app->config->all() as $section => $values) {
    foreach (is_array($values) ? array_keys($values) : [] as $key) {
        $settings[] = "{$section}.{$key}";
    }
}
foreach (['App.php', 'Server.php', 'Commands/Serve.php'] as $glue) {
    preg_match_all('/config->get\(\'([a-z_]+\.[a-z_]+)\'/', (string) file_get_contents($root . '/src/' . $glue), $read);
    array_push($settings, ...$read[1]);
}
foreach (array_unique($settings) as $setting) {
    if (!str_contains($everything, $setting)) {
        $problems[] = "The setting {$setting} is not documented; write it as `{$setting}` on its component's page.";
    }
}

// Commands.
$property = new ReflectionProperty($app->console, 'commands');
$commands = array_keys($property->getValue($app->console));
foreach ($commands as $command) {
    if (preg_match('/\b' . preg_quote($command, '/') . '\b/', $everything) !== 1) {
        $problems[] = "The command {$command} is not documented.";
    }
}
preg_match_all('/Command::add\(\s*\'([a-z0-9:_-]+)\'/', $everything, $declared);
preg_match_all('/->command\(\s*\'([a-z0-9:_-]+)\'/', $everything, $scheduled);
$known = [...$commands, ...$declared[1], ...$scheduled[1], 'list', 'help'];
foreach ($pages as $name => $markdown) {
    preg_match_all('/php cua ([a-z][a-z0-9:_-]*)/', $markdown, $run);
    foreach (array_unique($run[1]) as $command) {
        if (!in_array($command, $known, true)) {
            $problems[] = "docs/{$name} runs `php cua {$command}`, and no such command is registered or declared on a page.";
        }
    }
}

// ---- Report --------------------------------------------------------------

if ($problems === []) {
    echo 'The docs are in step with the code: ' . (count($pages) - 1) . ' pages, ' . count($commands) . ' commands, ' . count(array_unique($settings)) . " settings.\n";
    exit(0);
}
foreach ($problems as $problem) {
    echo "- {$problem}\n";
}
echo "\n" . count($problems) . (count($problems) === 1 ? " problem.\n" : " problems.\n");
exit(1);
