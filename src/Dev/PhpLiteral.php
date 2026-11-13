<?php

namespace Snorky22\ApiForge\Dev;

/**
 * Reads PHP data written by hand ("return [...];") without running any code: only the tokens of a literal
 * are accepted (arrays in short syntax, strings, nowdocs, numbers, true/false/null, a sign, "." between
 * strings) plus comments, which are dropped. A function call, a variable, an interpolated string or a second
 * statement is refused with its line, then nothing is evaluated.
 */
final class PhpLiteral
{
    private const TOKENS = [
        \T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_CONSTANT_ENCAPSED_STRING, \T_START_HEREDOC,
        \T_ENCAPSED_AND_WHITESPACE, \T_END_HEREDOC, \T_LNUMBER, \T_DNUMBER, \T_DOUBLE_ARROW, \T_STRING,
        \T_NAME_FULLY_QUALIFIED, \T_RETURN,
    ];

    /**
     * @param string $php a literal, optionally "<?php return ...;" (what a file holds)
     *
     * @throws \InvalidArgumentException when the code is not a literal
     */
    public static function evaluate(string $php): mixed
    {
        $expression = '';
        $started = false;
        $ended = false;
        $returned = false;
        $line = 1;
        // The two significant tokens before this one (text, whether it ends a value, line): to say a missing "=>" or ","
        $prev = $prevprev = null;
        foreach (token_get_all(str_starts_with(ltrim($php), '<?php') ? $php : '<?php '.$php) as $token) {
            [$id, $text] = \is_array($token) ? [$token[0], $token[1]] : [null, $token];
            $line = \is_array($token) ? $token[2] : $line;
            $inert = \in_array($id, [\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true);

            if (':' === $text && null === $id) {
                throw new \InvalidArgumentException(\sprintf('line %d: a PHP array uses "=>" between a key and its value (\'key\' => value), not ":" (that is JSON).', $line));
            }
            $startsValue = '[' === $text || \in_array($id, [\T_CONSTANT_ENCAPSED_STRING, \T_LNUMBER, \T_DNUMBER, \T_STRING, \T_START_HEREDOC], true);
            if (!$inert && $startsValue && null !== $prev && $prev['endsValue']) {
                if ('=>' === ($prevprev['text'] ?? null) || ']' === $prev['text']) {
                    throw new \InvalidArgumentException(\sprintf('line %d: a "," is missing after the value on line %d (each element ends with ",").', $line, $prev['line']));
                }
                throw new \InvalidArgumentException(\sprintf('line %d: a "=>" is missing between %s and "%s" (\'key\' => value).', $line, $prev['text'], trim($text)));
            }
            if (!$inert && \T_OPEN_TAG !== $id) {
                $prevprev = $prev;
                $prev = ['text' => trim($text), 'endsValue' => ']' === $text || \in_array($id, [\T_CONSTANT_ENCAPSED_STRING, \T_LNUMBER, \T_DNUMBER, \T_STRING, \T_END_HEREDOC], true), 'line' => $line];
            }

            if (';' === $text && null === $id) {
                if ($ended || !$started) {
                    throw new \InvalidArgumentException(\sprintf('line %d: unexpected ";".', $line));
                }
                $ended = true;
                continue;
            }
            if (!$inert && $ended) {
                throw new \InvalidArgumentException(\sprintf('line %d: nothing may follow the array, found "%s".', $line, trim($text)));
            }
            if (null === $id ? !str_contains('[],-.', $text) : !\in_array($id, self::TOKENS, true)) {
                throw new \InvalidArgumentException(\sprintf('line %d: only literal data is accepted (arrays, strings, numbers, true, false, null), found %s.', $line, '' !== trim($text) ? '"'.trim($text).'"' : (null !== $id ? token_name($id) : 'an unexpected token')));
            }
            if (\T_RETURN === $id) {
                if ($started || $returned) {
                    throw new \InvalidArgumentException(\sprintf('line %d: unexpected "return".', $line));
                }
                $returned = true;
                continue;
            }
            if (!\in_array($id, [\T_OPEN_TAG, \T_COMMENT, \T_DOC_COMMENT], true)) {
                $started = $started || \T_WHITESPACE !== $id;
                $expression .= $text;
            }
        }
        if ('' === trim($expression)) {
            throw new \InvalidArgumentException('no data found.');
        }

        try {
            return eval('return '.$expression.';');
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('not valid PHP data: '.$e->getMessage().($e instanceof \ParseError ? ' (line '.$e->getLine().')' : ''), 0, $e);
        }
    }
}
