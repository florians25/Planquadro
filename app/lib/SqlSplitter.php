<?php
/**
 * Zerlegt ein SQL-Skript in einzelne Statements, wie es der mysql-Client tut.
 * Beachtet Zeichenketten ('…', "…", `…`) inkl. Escapes, Kommentare
 * (-- …, # …, /* … *\/) und DELIMITER-Wechsel (für Prozeduren in
 * Alt-Migrationen).
 */
final class SqlSplitter
{
    /** @return string[] */
    public static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        $delim = ';';
        $atLineStart = true;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`') { $buf .= $n; $i++; continue; }
                if ($c === $quote) {
                    if ($n === $quote) { $buf .= $n; $i++; continue; } // '' als Escape
                    $quote = null;
                }
                continue;
            }
            if ($atLineStart && trim($buf) === '' && preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/Ai', $sql, $m, 0, $i)) {
                $delim = $m[1];
                $i += strlen($m[0]) - 1;
                $buf = '';
                continue;
            }
            $atLineStart = ($c === "\n");
            if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $buf .= $c; continue; }
            if (($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) || $c === '#') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol;
                $buf .= "\n";
                $atLineStart = true;
                continue;
            }
            if ($c === '/' && $n === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $comment = substr($sql, $i, $end === false ? $len - $i : $end + 2 - $i);
                /* MySQL-Versionskommentare (/*!40101 …*\/) sind ausführbar */
                if (isset($comment[2]) && $comment[2] === '!') $buf .= $comment;
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if (substr_compare($sql, $delim, $i, strlen($delim)) === 0) {
                if (trim($buf) !== '') $out[] = trim($buf);
                $buf = '';
                $i += strlen($delim) - 1;
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') $out[] = trim($buf);
        return $out;
    }
}
