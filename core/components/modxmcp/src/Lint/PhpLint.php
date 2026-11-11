<?php
namespace ModxMCP\Lint;

/** Syntax check for PHP source without executing it. */
final class PhpLint {
    /**
     * @param string $code   Source as stored: snippets and plugins usually have no opening tag.
     * @return array|null    null when the code parses, else ['message' => …, 'line' => int]
     *                       with the line counted in the given source.
     */
    public static function check($code) {
        $code = (string) $code;
        // Element code is stored without an opening tag. The tag is added on the same line,
        // so reported line numbers match the stored source.
        $source = preg_match('/^\s*<\?(?:php)?/i', $code) ? $code : '<?php ' . $code;
        try {
            token_get_all($source, TOKEN_PARSE);
            return null;
        } catch (\ParseError $e) {
            return array('message' => $e->getMessage(), 'line' => max(1, $e->getLine()));
        }
    }
}
