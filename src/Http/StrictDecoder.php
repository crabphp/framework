<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Decoding;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Reads a query string, a form and a JSON body the strict way a
 * Decoding asks for: nothing ambiguous is resolved in silence and
 * nothing is cut short. See Contracts\Http\Decoding for what is refused
 * and with which status.
 *
 * A field's name is a first part, then parts in brackets: "title",
 * "address[city]", "tags[]", "lines[0][quantity]". "[]" appends to a
 * list of plain values; a number is a place in a list, and the places
 * given must be 0, 1, 2 and so on with none left out; anything else is a
 * name in an object.
 */
final class StrictDecoder
{
    private const int LEAF = 0;
    private const int APPENDED = 1;
    private const int INDEXED = 2;
    private const int NAMED = 3;

    /**
     * A query string or a urlencoded body as nested fields.
     *
     * @param string $what What is being read, to say so: "query string", "form".
     * @return array<string, mixed>
     */
    public static function urlencoded(string $encoded, Decoding $decoding, string $what): array
    {
        if ($encoded === '') {
            return [];
        }
        if (substr_count($encoded, '&') >= $decoding->maxNodes) {
            throw new HttpException(413, "The {$what} has too many fields.");
        }
        $pairs = [];
        foreach (explode('&', $encoded) as $piece) {
            if ($piece === '') {
                continue;
            }
            $parts = explode('=', $piece, 2);
            $name = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            if (!mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($value, 'UTF-8')) {
                throw new HttpException(400, "The {$what} is not valid UTF-8.");
            }
            $pairs[] = [$name, $value];
        }
        return self::fields($pairs, $decoding, $what);
    }

    /**
     * Named values as nested fields, each name read by the grammar above.
     *
     * @param list<array{string, string|UploadedFileInterface}> $pairs Each field's name as it was written, and its value.
     * @return array<string, mixed>
     */
    public static function fields(array $pairs, Decoding $decoding, string $what): array
    {
        $root = [self::NAMED, []];
        $nodes = 0;
        foreach ($pairs as [$name, $value]) {
            if (strlen($name) > $decoding->maxNameBytes) {
                throw new HttpException(413, "A field name in the {$what} is too long.");
            }
            $path = self::path($name, $what);
            if (count($path) > $decoding->maxDepth) {
                throw new HttpException(413, "The field {$name} in the {$what} nests too deeply.");
            }
            self::put($root, $path, 0, $value, $name, $decoding, $nodes, $what);
        }
        return self::nested($root, '', $what);
    }

    /**
     * A JSON body as its one object, with each object in it a stdClass
     * and each list an array, so that {} is not [] and {"0": 7} is not
     * [7]. Repeated member names are refused at every depth, before PHP
     * would keep the last.
     */
    public static function json(string $body, Decoding $decoding): \stdClass
    {
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new HttpException(400, 'The JSON body is not valid UTF-8.');
        }
        if (!str_starts_with(ltrim($body, " \t\r\n"), '{')) {
            throw new HttpException(400, 'The JSON body must be one object.');
        }
        try {
            $value = Request::decodeJson($body, objects: true);
        } catch (\LengthException $error) {
            throw new HttpException(413, $error->getMessage(), previous: $error);
        } catch (\JsonException $error) {
            if ($error->getCode() === JSON_ERROR_DEPTH) {
                throw new HttpException(413, 'The JSON body nests too deeply.', previous: $error);
            }
            throw new HttpException(400, 'The JSON body is malformed.', previous: $error);
        }
        self::repeated($body);
        $nodes = 0;
        self::bounded($value, 1, $decoding, $nodes);
        return $value;
    }

    /**
     * What json() read as PHP reads JSON for most code: every object the
     * array of its members.
     *
     * @return array<mixed>
     */
    public static function plain(\stdClass|array $value): array
    {
        $plain = $value instanceof \stdClass ? get_object_vars($value) : $value;
        foreach ($plain as $key => $item) {
            if (!is_array($item) && !$item instanceof \stdClass) {
                continue;
            }
            $plain[$key] = self::plain($item);
        }
        return $plain;
    }

    /**
     * The parts of a field's name: "lines[0][quantity]" is lines, 0 and
     * quantity; "tags[]" is tags and the empty part that appends.
     *
     * @return list<string>
     */
    private static function path(string $name, string $what): array
    {
        if (preg_match('/^([^\[\].\s]+)((?:\[[^\[\]]*\])*)$/D', $name, $match) !== 1) {
            throw new HttpException(
                400,
                "The {$what} has a field named \"{$name}\", which is not a name such as title, address[city], tags[] or lines[0][quantity].",
            );
        }
        $path = [$match[1]];
        if ($match[2] !== '') {
            foreach (explode('][', substr($match[2], 1, -1)) as $part) {
                if ($part !== '' && ctype_digit($part) && $part !== '0' && $part[0] === '0') {
                    throw new HttpException(
                        400,
                        "The {$what} has a field named \"{$name}\", whose place {$part} is written with a leading zero.",
                    );
                }
                if (preg_match('/[.\s]/', $part) === 1) {
                    throw new HttpException(
                        400,
                        "The {$what} has a field named \"{$name}\", and a name may hold no dot or space.",
                    );
                }
                $path[] = $part;
            }
        }
        return $path;
    }

    /**
     * @param array{int, mixed} $node A kind, and a leaf's value or the children by key.
     * @param list<string> $path
     */
    private static function put(
        array &$node,
        array $path,
        int $at,
        string|UploadedFileInterface $value,
        string $name,
        Decoding $decoding,
        int &$nodes,
        string $what,
    ): void {
        $part = $path[$at];
        $last = $at === (count($path) - 1);
        $kind = match (true) {
            $at === 0 => self::NAMED,
            $part === '' => self::APPENDED,
            ctype_digit($part) => self::INDEXED,
            default => self::NAMED,
        };
        if ($node[0] !== $kind && $node[1] !== []) {
            throw new HttpException(
                400,
                "The {$what} gives the field {$name} in a way that does not agree with another field of the same name: a list is written with [] throughout or with places throughout, and an object with names.",
            );
        }
        $node[0] = $kind;
        if ($kind === self::APPENDED) {
            if (!$last) {
                throw new HttpException(
                    400,
                    "The {$what} has a field named \"{$name}\": [] appends a plain value, so nothing may follow it. Give each item its place, as in lines[0][quantity].",
                );
            }
            $part = (string) count($node[1]);
        }
        if (!isset($node[1][$part])) {
            if (++$nodes > $decoding->maxNodes) {
                throw new HttpException(413, "The {$what} has too many fields.");
            }
            if (count($node[1]) >= $decoding->maxItems) {
                throw new HttpException(413, "The {$what} has too many fields under one name.");
            }
            $node[1][$part] = $last ? [self::LEAF, $value] : [self::NAMED, []];
            if ($last) {
                return;
            }
        } elseif ($last || $node[1][$part][0] === self::LEAF) {
            throw new HttpException(
                400,
                "The {$what} gives the field {$name} more than once. Send each field once, or a list as {$path[0]}[].",
            );
        }
        self::put($node[1][$part], $path, $at + 1, $value, $name, $decoding, $nodes, $what);
    }

    /**
     * @param array{int, mixed} $node
     */
    private static function nested(array $node, string $name, string $what): mixed
    {
        [$kind, $children] = $node;
        if ($kind === self::LEAF) {
            return $children;
        }
        if ($kind === self::INDEXED) {
            ksort($children, SORT_NUMERIC);
            if (array_keys($children) !== range(0, count($children) - 1)) {
                throw new HttpException(
                    400,
                    "The {$what} gives the list {$name} with a place left out: its places are 0, 1, 2 and so on.",
                );
            }
        }
        $plain = [];
        foreach ($children as $key => $child) {
            $plain[$key] = self::nested($child, $name === '' ? (string) $key : "{$name}[{$key}]", $what);
        }
        return $kind === self::NAMED ? $plain : array_values($plain);
    }

    /**
     * Refuses JSON text, already known to be well formed, in which an
     * object names a member twice.
     */
    private static function repeated(string $json): void
    {
        // The names seen in each open object, innermost last; null for an open array.
        $open = [];
        $level = -1;
        $key = false;
        $length = strlen($json);
        for ($offset = 0; $offset < $length; ++$offset) {
            $offset += strcspn($json, '"{}[],', $offset);
            if ($offset >= $length) {
                break;
            }
            switch ($json[$offset]) {
                case '{':
                    $open[++$level] = [];
                    $key = true;
                    break;
                case '[':
                    $open[++$level] = null;
                    $key = false;
                    break;
                case '}':
                case ']':
                    unset($open[$level--]);
                    $key = false;
                    break;
                case ',':
                    $key = $level >= 0 && $open[$level] !== null;
                    break;
                case '"':
                    $start = $offset;
                    for (++$offset; $offset < $length; $offset += 2) {
                        $offset += strcspn($json, '"\\', $offset);
                        if ($json[$offset] === '"') {
                            break;
                        }
                    }
                    if ($key) {
                        // Decoded, so that "a" and "\u0061" are one name.
                        $name = (string) json_decode(substr($json, $start, $offset - $start + 1));
                        if (isset($open[$level][$name])) {
                            throw new HttpException(
                                400,
                                'The JSON body names a member twice in one object. Send each member once.',
                            );
                        }
                        $open[$level][$name] = true;
                        $key = false;
                    }
                    break;
            }
        }
    }

    private static function bounded(mixed $value, int $depth, Decoding $decoding, int &$nodes): void
    {
        if (++$nodes > $decoding->maxNodes) {
            throw new HttpException(413, 'The JSON body has too many values.');
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return;
        }
        if ($depth > $decoding->maxDepth) {
            throw new HttpException(413, 'The JSON body nests too deeply.');
        }
        if (count($value) > $decoding->maxItems) {
            throw new HttpException(413, 'The JSON body has too many items in one list or object.');
        }
        foreach ($value as $item) {
            self::bounded($item, $depth + 1, $decoding, $nodes);
        }
    }
}
