<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

/**
 * Writes the complete configuration file of a framework section into
 * config/, every setting at its default with what it does, so the
 * settings can be read and changed where the application keeps its own.
 */
final readonly class PublishConfig implements Command
{
    private const STUBS = __DIR__ . '/../Config/stubs';

    public function __construct(
        private App $app,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Write a section's complete configuration file into config/

            Arguments:
              section   app, server, database, cache, queue, ...; more than one may be given. Without one, lists them.

            Options:
              --force   Replace a file that is already there
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $sections = self::sections();
        $asked = $input->arguments();
        if ($asked === []) {
            $output->line('Sections: ' . implode(', ', $sections));
            $output->line('Write one with php cua config:publish server');
            return 0;
        }
        $unknown = array_values(array_diff($asked, $sections));
        if ($unknown !== []) {
            $output->error(
                'Unknown section ' . implode(', ', $unknown) . '. The sections are ' . implode(', ', $sections) . '.',
            );
            return 1;
        }
        $directory = $this->app->path('config');
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $output->error("The folder {$directory} cannot be created. Check its permissions.");
            return 1;
        }
        $status = 0;
        foreach (array_unique($asked) as $section) {
            $file = "{$directory}/{$section}.php";
            if (is_file($file) && !$input->flag('force')) {
                $output->error(
                    "{$file} already exists. Keep it, or replace it, losing what it sets, with php cua config:publish {$section} --force.",
                );
                $status = 1;
                continue;
            }
            if (!@copy(self::STUBS . "/{$section}.php", $file)) {
                $output->error("Could not write {$file}. Check the folder's permissions.");
                $status = 1;
                continue;
            }
            $output->line("Created {$file}");
        }
        return $status;
    }

    /**
     * @return list<string>
     */
    public static function sections(): array
    {
        $sections = array_map(
            static fn(string $file): string => basename($file, '.php'),
            glob(self::STUBS . '/*.php') ?: [],
        );
        sort($sections);
        return $sections;
    }
}
