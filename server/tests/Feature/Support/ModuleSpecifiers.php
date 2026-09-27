<?php

namespace Tests\Feature\Support;

/**
 * **Every module specifier a browser module's source names** — the one reader behind every import walk
 * in this suite (`DrivesAShippedClientModule::relativeImports()`, and the lobby's import-graph bound in
 * `Tests\Feature\Floor\TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT FAILS CLOSED (card#7343 r2-1). A walk that reads only the specifiers it knows how to follow
 * reports clean over every one it cannot, so an import spelled another way — an absolute `/js/…` path,
 * a URL, a backtick — walked around the bound it was holding. So this reads EVERY specifier after
 * `from`, after a bare `import` and inside `import(`, in single quotes, double quotes or backticks, and
 * says of each whether a walk over the shipped tree can follow it: a string literal (a backtick one
 * with no `${`) starting `./` or `../`. Anything else — an absolute path, a URL, a bare name, a template
 * with `${`, or an `import(` whose argument is not one literal — is returned as UNFOLLOWABLE with the
 * reason, for the caller to treat as a defect and never to skip.
 *
 * ⛔ COMMENTS ARE NOT READ. This tree's comments name modules in prose all the time (*from
 * `lobby-screen.js`*), so a reader that counted backticks inside comments would red on documentation.
 * The source is lexed first — strings, template literals (with their `${…}` nesting), regular-expression
 * literals and both comment forms — and the comments are blanked before anything is matched. A string
 * literal is kept whole, so text inside one that merely looks like an import is read as one: the
 * direction that errs is a false red, never a false clean.
 *
 * ⚠ IT IS A LEXER'S READING, NOT A PARSER'S. It does not know `import` from an identifier named
 * `import` in a position no JavaScript allows, and it decides a `/` is a regular expression by the
 * token before it, which is the standard heuristic and is wrong only for code no module here contains.
 */
final class ModuleSpecifiers
{
    /**
     * @return list<array{form: string, specifier: ?string, followable: bool, why: ?string}>
     */
    public static function of(string $source): array
    {
        $literal = '(?:\'((?:\\\\.|[^\'\\\\\n])*)\'|"((?:\\\\.|[^"\\\\\n])*)"|`((?:\\\\.|[^`\\\\])*)`)';
        preg_match_all('/(?<![\w$.])(from|import)\s*(\(\s*)?(?:'.$literal.'(\s*[,)])?)?/', self::withoutComments($source), $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $found = [];

        foreach ($matches as $m) {
            $dynamic = $m[2] !== null;
            // Groups 3–5 are the three literal forms — single, double, backtick — and at most one is set.
            [$specifier, $template] = $m[3] !== null ? [$m[3], false] : ($m[4] !== null ? [$m[4], false] : [$m[5], $m[5] !== null]);

            if ($m[1] === 'from' && ($dynamic || $specifier === null)) {
                // `from` as an identifier (`const from = …`, `{ from, to }`) — no specifier follows it.
                continue;
            }

            if ($m[1] === 'import' && ! $dynamic && $specifier === null) {
                // `import { … } from` and `import x from` (the `from` carries the specifier), or `import.meta`.
                continue;
            }

            $form = $dynamic ? 'import(…)' : ($m[1] === 'from' ? 'from' : 'bare import');

            if ($dynamic && ($specifier === null || ($m[6] ?? null) === null)) {
                $found[] = ['form' => $form, 'specifier' => null, 'followable' => false,
                    'why' => 'an import( whose argument is not one literal names no file a walk can read'];

                continue;
            }

            $found[] = ['form' => $form, 'specifier' => $specifier] + self::judge($specifier, $template);
        }

        return $found;
    }

    /** @return array{followable: bool, why: ?string} */
    private static function judge(string $specifier, bool $template): array
    {
        $why = match (true) {
            $template && str_contains($specifier, '${') => 'a template literal with a ${…} names no one file',
            preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $specifier) === 1 => 'a URL is not a file in the shipped tree',
            str_starts_with($specifier, '/') => 'an absolute path is served, not a file this walk resolves',
            ! str_starts_with($specifier, './') && ! str_starts_with($specifier, '../') => 'a bare name resolves through no import map this tree has',
            default => null,
        };

        return ['followable' => $why === null, 'why' => $why];
    }

    /**
     * The source with every comment blanked — each character of it a space, its newlines kept — and
     * every string, template and regular-expression literal left as it was.
     */
    public static function withoutComments(string $source): string
    {
        $i = 0;

        return self::lex($source, $i, false);
    }

    /**
     * Lex from `$i` to the end — or, inside a template's `${`, to its closing `}` (consumed and returned).
     */
    private static function lex(string $s, int &$i, bool $inTemplateExpression): string
    {
        $n = strlen($s);
        $out = '';
        $depth = 0;
        // The last significant token: decides whether a `/` opens a regular expression or divides.
        $prev = '';
        $word = '';

        while ($i < $n) {
            $c = $s[$i];
            $d = $s[$i + 1] ?? '';

            if ($c === '/' && $d === '/') {
                $end = strpos($s, "\n", $i);
                $end = $end === false ? $n : $end;
                $out .= str_repeat(' ', $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '/' && $d === '*') {
                $end = strpos($s, '*/', $i + 2);
                $end = $end === false ? $n : $end + 2;
                $out .= (string) preg_replace('/[^\n]/', ' ', substr($s, $i, $end - $i));
                $i = $end;

                continue;
            }

            if ($c === '\'' || $c === '"') {
                $start = $i++;

                while ($i < $n && $s[$i] !== $c && $s[$i] !== "\n") {
                    $i += $s[$i] === '\\' ? 2 : 1;
                }

                $i++;
                $out .= substr($s, $start, $i - $start);
                $prev = $c;
                $word = '';

                continue;
            }

            if ($c === '`') {
                $out .= '`';
                $i++;

                while ($i < $n && $s[$i] !== '`') {
                    if ($s[$i] === '\\') {
                        $out .= substr($s, $i, 2);
                        $i += 2;
                    } elseif ($s[$i] === '$' && ($s[$i + 1] ?? '') === '{') {
                        $i += 2;
                        $out .= '${'.self::lex($s, $i, true);
                    } else {
                        $out .= $s[$i++];
                    }
                }

                $out .= '`';
                $i++;
                $prev = '`';
                $word = '';

                continue;
            }

            if ($c === '/' && self::regexMayStart($prev, $word)) {
                $start = $i++;
                $class = false;

                while ($i < $n && $s[$i] !== "\n" && ($class || $s[$i] !== '/')) {
                    if ($s[$i] === '\\') {
                        $i++;
                    } elseif ($s[$i] === '[') {
                        $class = true;
                    } elseif ($s[$i] === ']') {
                        $class = false;
                    }

                    $i++;
                }

                $i++;
                $out .= substr($s, $start, $i - $start);
                $prev = '/';
                $word = '';

                continue;
            }

            if ($inTemplateExpression && $c === '{') {
                $depth++;
            } elseif ($inTemplateExpression && $c === '}') {
                if ($depth === 0) {
                    $i++;

                    return $out.'}';
                }

                $depth--;
            }

            $out .= $c;
            $i++;

            if (ctype_alnum($c) || $c === '_' || $c === '$') {
                $word = ($prev === 'w' ? $word : '').$c;
                $prev = 'w';
            } elseif (! ctype_space($c)) {
                $prev = $c;
                $word = '';
            }
        }

        return $out;
    }

    /** Whether a `/` after this token opens a regular expression rather than dividing. */
    private static function regexMayStart(string $prev, string $word): bool
    {
        if ($prev === 'w') {
            return in_array($word, ['return', 'typeof', 'case', 'do', 'else', 'in', 'of', 'new', 'delete', 'void', 'throw', 'yield', 'await'], true);
        }

        return $prev === '' || str_contains('(,=:[!&|?{};+-*%<>~^', $prev);
    }
}
