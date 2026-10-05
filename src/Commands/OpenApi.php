<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Routing\Router;
use Crab\Contracts\Validation\Validator;
use Crab\Routing\OpenApi as Document;

final readonly class OpenApi implements Command
{
    public function __construct(private Router $router, private Validator $validator, private Config $config)
    {
    }

    public function description(): string
    {
        return <<<'TEXT'
            Write the OpenAPI description of the routes

            Options:
              --output=openapi.json   Write to a file instead of printing

            The title and version come from "app.name" and "app.version".
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $openApi = new Document($this->router, $this->validator);
        $document = $openApi->document(
            (string) $this->config->get('app.name', 'API'),
            (string) $this->config->get('app.version', '1.0.0'),
        );
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $file = $input->option('output');
        if (!is_string($file)) {
            $output->line($json);
            return 0;
        }
        if (@file_put_contents($file, $json . "\n") === false) {
            $output->error("Could not write {$file}.");
            return 1;
        }
        $output->line("Wrote {$file}.");
        return 0;
    }
}
