# How a docs page is written

The model is the Laravel documentation: every page answers "what is this, when do I want it, how do I use it", in that order, with code for each step. The reader is building an application and has not read the framework's source.

## Contents

- [Shape of a page](#shape-of-a-page)
- [Prose](#prose)
- [Code samples](#code-samples)
- [Settings, commands and limits](#settings-commands-and-limits)
- [A page to copy](#a-page-to-copy)

## Shape of a page

1. `# Title`, one or two words: the topic, as it is named in the menu.
2. A table of contents: a list of links to every `##` section, with `###` sections nested one level. Anchors are the heading in lower case, spaces as dashes, punctuation dropped.
3. `## Introduction`, always first. Two short paragraphs at most: what the thing is, and **when you would reach for it**, with a concrete case ("sending the welcome email after a user signs up"). If the component is opt-in, the provider line goes here or in the next section.
4. `## Configuration` or `## Installation` when there is any: the provider to register, commands to run once, the settings.
5. Task sections, in the order a newcomer needs them: the common case first, variations after, rarely used things last. Name a section after what the reader wants to do ("Delaying a job"), not after a class ("The Queue contract").
6. A closing section for testing the thing, when it has a fake or a memory store, linking to `testing.md`.

Keep a page to one topic. When a section grows past a screen and stands on its own, it is a page.

## Prose

- Address the reader as "you". Present tense, active voice, short sentences.
- Open each section with one or two sentences that say what the code below does and why you would want it. Then the code. Then only what the code does not show: a rule, a limit, a consequence.
- Say when to use a thing and when not to. "Use `later()` for a reminder; for work at a fixed time of day use the [schedule](scheduling.md)."
- Explain a term the first time it appears, in a clause, not a glossary.
- State limits plainly and near the feature: what is not supported, what blocks the worker, what runs twice. A reader who finds a limit in production stops trusting the page.
- Crab differs from Laravel in ways that matter (long-running workers, no static access to the request, services in constructors). Where a Laravel habit would lead the reader wrong, say so in one sentence.
- No marketing words (powerful, elegant, simply, just, easy). No "we". No history of how it was built.
- Use callouts sparingly, for what the reader must not miss:

  ```md
  > [!NOTE]
  > A fact that saves a wrong guess.

  > [!WARNING]
  > A consequence that costs data or an outage.
  ```

## Code samples

- Fence with three backticks and a language: `php`, `sh`, `json`, `text`.
- A sample must work as written against the current code. Check every method name, parameter name and default against the contract before writing it.
- Show where the code lives when that is not obvious, as a first-line comment: `// app/Jobs/SendWelcomeEmail.php`, `// routes/web.php`, `// config/queue.php`.
- Include the `use` lines for Crab's own classes the first time a sample on the page needs them; leave out `<?php` and `declare(strict_types=1)` except in a whole-file sample.
- Write samples the way the framework asks code to be written: `final readonly class`, typed parameters and returns, services in the constructor, one statement per line.
- Use one running example across a page (a `users` table, a welcome email), so that later samples build on earlier ones.
- The application's command line is `php cua <command>`.
- Trailing comments explain a line; align them when several lines have one.

## Settings, commands and limits

- Write a setting as `section.key`, in backticks, every time: `queue.tries`. The check finds settings by that form.
- List a component's settings in one table: setting, default, what it does. Put it in the Configuration section.
- Show a command where it is first needed, and list a component's commands in a table when it has more than two.
- End a component page's Introduction or Configuration with what must also be registered (`DatabaseProvider` for the database stores).

## A page to copy

````md
# Cache

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Choosing a store](#choosing-a-store)
- [Storing and reading](#storing-and-reading)

## Introduction

The cache keeps the result of slow work so that the next request does not repeat it: a list of exchange rates fetched from another API, a count that takes a second to compute. ...

## Configuration

Register the provider in `app.php`:

```php
// app.php
->withProviders([
    CacheProvider::class,
])
```

| Setting | Default | |
| --- | --- | --- |
| `cache.store` | `file` | Where entries are kept: `file`, `database` or `array` |

### Choosing a store

...

## Storing and reading

Ask for the cache in a constructor, then ...

```php
use Crab\Contracts\Cache\Cache;

final readonly class ShowRates
{
    public function __construct(private Cache $cache)
    {
    }
}
```
````
