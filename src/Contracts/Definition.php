<?php

declare(strict_types=1);

namespace Crab\Contracts;

/**
 * What a package declares beside the application's routes, such as a
 * registry of pages, said to the application so that it is checked and
 * described with everything else. A plugin adds one to Definitions in
 * register().
 *
 * finish() runs once, in the parent, after every plugin, the
 * application's setup and its route files, and before anything serves or
 * works. It resolves what the declarations name in one another, adds the
 * routes they stand for and refuses what cannot be honoured. It is the end
 * of defining, not a boot step: it opens nothing, makes no service and
 * reads no request.
 */
interface Definition
{
    /**
     * A short name for what is declared here, for `describe`: "psx".
     */
    public function name(): string;

    /**
     * Resolves and checks the declarations, throwing a LogicException
     * that names the declaration and says what to do instead.
     */
    public function finish(): void;

    /**
     * The classes the container must make for unattended code, each with
     * where it is named, for App::unmade(): "Page posts.edit
     * (resources/pages/Posts/Edit.psx)".
     *
     * @return array<class-string, string>
     */
    public function entries(): array;

    /**
     * What is declared, one thing to a line, for `describe`.
     *
     * @return list<string>
     */
    public function describe(): array;
}
